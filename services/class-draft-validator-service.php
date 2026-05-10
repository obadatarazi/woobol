<?php
/**
 * Validates product_sync_draft and variation_sync_draft rows.
 *
 * Catches EAN gaps, variation identity collisions, image mapping issues
 * and recomputes validation_status + final_payload_json so the sync worker
 * can trust the stored payload.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Services;

use WooBolSync\Models\Product_Draft;
use WooBolSync\Models\Variation_Draft;

defined( 'ABSPATH' ) || exit;

class Draft_Validator_Service {

    public const REASON_MISSING_EAN          = 'missing_ean';
    public const REASON_INVALID_EAN          = 'invalid_ean';
    public const REASON_MISSING_NAME         = 'missing_name';
    public const REASON_MISSING_PRICE        = 'missing_price';
    public const REASON_MISSING_MAIN_IMAGE   = 'missing_main_image';
    public const REASON_BROKEN_IMAGE_URL     = 'broken_image_url';
    public const REASON_VARIATION_MISSING_SKU = 'variation_missing_sku';
    public const REASON_VARIATION_NO_ATTRIBUTES = 'variation_no_attributes';
    public const REASON_VARIATION_DUPLICATE_SIGNATURE = 'variation_duplicate_signature';
    public const REASON_VARIATION_MISSING_IMAGE = 'variation_missing_image';
    public const REASON_IMAGE_REUSED_ACROSS_PARENTS = 'image_reused_across_parents';
    public const REASON_ORPHAN_VARIATION = 'orphan_variation';

    /**
     * @return array{status:string, errors:string[], warnings:string[]}
     */
    public function revalidate_product_draft( int $draft_id ): array {
        $draft = Product_Draft::get( $draft_id );
        if ( ! $draft ) {
            return [
                'status'   => Product_Draft::VALIDATION_BLOCKED,
                'errors'   => [ 'draft_missing' ],
                'warnings' => [],
            ];
        }

        $errors   = [];
        $warnings = [];

        $effective = $this->effective_product_values( $draft );

        if ( $effective['name'] === '' ) {
            $errors[] = self::REASON_MISSING_NAME;
        }

        if ( $effective['ean'] === '' ) {
            $errors[] = self::REASON_MISSING_EAN;
        } elseif ( ! $this->valid_ean( $effective['ean'] ) ) {
            $errors[] = self::REASON_INVALID_EAN;
        }

        $price = $this->coerce_float( $effective['regular_price'] );
        if ( $price <= 0 ) {
            $errors[] = self::REASON_MISSING_PRICE;
        }

        $main_image_url = (string) $effective['main_image_url'];
        if ( $main_image_url === '' ) {
            $warnings[] = self::REASON_MISSING_MAIN_IMAGE;
        } elseif ( ! $this->looks_like_valid_url( $main_image_url ) ) {
            $errors[] = self::REASON_BROKEN_IMAGE_URL;
        }

        $final_payload = $this->compose_final_product_payload( $draft, $effective );

        $variations = Variation_Draft::get_for_product_draft( $draft_id );
        $variation_summary = $this->validate_variation_set( $draft, $variations );

        $errors   = array_values( array_unique( array_merge( $errors, $variation_summary['errors'] ) ) );
        $warnings = array_values( array_unique( array_merge( $warnings, $variation_summary['warnings'] ) ) );

        $status = $this->compute_status( $errors, $warnings );

        Product_Draft::update(
            $draft_id,
            [
                'validation_status'         => $status,
                'validation_errors_json'    => $this->encode_json( $errors ),
                'validation_warnings_json'  => $this->encode_json( $warnings ),
                'final_payload_json'        => $this->encode_json( $final_payload ),
                'ean'                       => (string) $effective['ean'],
                'name'                      => mb_substr( (string) $effective['name'], 0, 255 ),
            ]
        );

        return [
            'status'   => $status,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $variations
     * @return array{errors:string[], warnings:string[]}
     */
    private function validate_variation_set( array $product_draft, array $variations ): array {
        if ( $variations === [] ) {
            return [ 'errors' => [], 'warnings' => [] ];
        }

        $parent_id  = (int) $product_draft['wc_product_id'];
        $signatures = [];
        $errors     = [];
        $warnings   = [];

        foreach ( $variations as $variation ) {
            $vid = (int) $variation['id'];
            $result = $this->validate_variation_row( $variation, $product_draft );
            if ( ! empty( $result['errors'] ) ) {
                $errors = array_merge( $errors, $result['errors'] );
            }
            if ( ! empty( $result['warnings'] ) ) {
                $warnings = array_merge( $warnings, $result['warnings'] );
            }

            $sig = (string) ( $variation['attributes_signature'] ?? '' );
            if ( $sig !== '' ) {
                $signatures[ $sig ] = ( $signatures[ $sig ] ?? 0 ) + 1;
            }
        }

        foreach ( $signatures as $sig => $count ) {
            if ( $count > 1 ) {
                $errors[] = self::REASON_VARIATION_DUPLICATE_SIGNATURE;
                break;
            }
        }

        unset( $parent_id );

        return [
            'errors'   => array_values( array_unique( $errors ) ),
            'warnings' => array_values( array_unique( $warnings ) ),
        ];
    }

    /**
     * @param array<string, mixed> $variation
     * @param array<string, mixed> $product_draft
     * @return array{status:string, errors:string[], warnings:string[]}
     */
    public function validate_variation_row( array $variation, array $product_draft ): array {
        $errors   = [];
        $warnings = [];

        $effective = $this->effective_variation_values( $variation );

        // Variation must have EAN (SKU is optional)
        if ( $effective['ean'] === '' ) {
            $errors[] = self::REASON_MISSING_EAN;
        }
        
        // SKU is helpful but not required for variations
        if ( $effective['sku'] === '' ) {
            $warnings[] = self::REASON_VARIATION_MISSING_SKU;
        }

        $attrs = $effective['attributes'];
        if ( ! is_array( $attrs ) || $attrs === [] ) {
            $errors[] = self::REASON_VARIATION_NO_ATTRIBUTES;
        }

        $price = $this->coerce_float( $effective['regular_price'] );
        if ( $price <= 0 ) {
            $errors[] = self::REASON_MISSING_PRICE;
        }

        $image_url = (string) $effective['image_url'];
        if ( $image_url === '' ) {
            // Allow variations to use parent image as fallback - only warn, don't block
            $warnings[] = self::REASON_VARIATION_MISSING_IMAGE;
        } elseif ( ! $this->looks_like_valid_url( $image_url ) ) {
            $errors[] = self::REASON_BROKEN_IMAGE_URL;
        } else {
            $parents_sharing = Variation_Draft::count_image_url_usages_across_parents( $image_url );
            if ( $parents_sharing > 1 ) {
                $warnings[] = self::REASON_IMAGE_REUSED_ACROSS_PARENTS;
            }
        }

        $parent_draft_id  = (int) $variation['product_draft_id'];
        if ( $parent_draft_id <= 0 ) {
            $errors[] = self::REASON_ORPHAN_VARIATION;
        }

        $final_payload = $this->compose_final_variation_payload( $variation, $effective );
        $status        = $this->compute_status( $errors, $warnings );

        Variation_Draft::update(
            (int) $variation['id'],
            [
                'validation_status'        => $status,
                'validation_errors_json'   => $this->encode_json( $errors ),
                'validation_warnings_json' => $this->encode_json( $warnings ),
                'final_payload_json'       => $this->encode_json( $final_payload ),
            ]
        );

        return [
            'status'   => $status,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Merge mapped payload + admin overrides to get what's actually effective.
     *
     * @return array<string, mixed>
     */
    private function effective_product_values( array $draft ): array {
        $mapped    = $this->decode_json( (string) ( $draft['mapped_payload_json'] ?? '' ) );
        $overrides = $this->decode_json( (string) ( $draft['admin_overrides_json'] ?? '' ) );

        return [
            'name'           => $overrides['name']           ?? ( $draft['name']          ?? ( $mapped['name'] ?? '' ) ),
            'ean'            => $overrides['ean']            ?? ( $draft['ean']           ?? ( $mapped['ean'] ?? '' ) ),
            'short_description' => $overrides['short_description'] ?? ( $draft['short_description'] ?? ( $mapped['short_description'] ?? '' ) ),
            'description'    => $overrides['description']    ?? ( $draft['description']   ?? ( $mapped['description'] ?? '' ) ),
            'regular_price'  => $overrides['regular_price']  ?? ( $draft['regular_price'] ?? ( $mapped['regular_price'] ?? null ) ),
            'sale_price'     => $overrides['sale_price']     ?? ( $draft['sale_price']    ?? ( $mapped['sale_price'] ?? null ) ),
            'currency'       => $overrides['currency']       ?? ( $draft['currency']      ?? ( $mapped['currency'] ?? '' ) ),
            'stock_quantity' => $overrides['stock_quantity'] ?? ( $draft['stock_quantity'] ?? ( $mapped['stock_quantity'] ?? null ) ),
            'stock_status'   => $overrides['stock_status']   ?? ( $draft['stock_status']  ?? ( $mapped['stock_status'] ?? '' ) ),
            'manage_stock'   => $overrides['manage_stock']   ?? ( $draft['manage_stock']  ?? ( $mapped['manage_stock'] ?? 0 ) ),
            'main_image_url' => $overrides['main_image_url'] ?? ( $draft['main_image_url'] ?? ( $mapped['main_image_url'] ?? '' ) ),
            'gallery'        => $overrides['gallery']        ?? $this->decode_json( (string) ( $draft['gallery_json'] ?? '' ) ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function effective_variation_values( array $variation ): array {
        $mapped    = $this->decode_json( (string) ( $variation['mapped_payload_json'] ?? '' ) );
        $overrides = $this->decode_json( (string) ( $variation['admin_overrides_json'] ?? '' ) );

        return [
            'sku'           => $overrides['sku']           ?? ( $variation['sku']           ?? ( $mapped['sku'] ?? '' ) ),
            'ean'           => $overrides['ean']           ?? ( $variation['ean']           ?? ( $mapped['ean'] ?? '' ) ),
            'attributes'    => $overrides['attributes']    ?? $this->decode_json( (string) ( $variation['attributes_json'] ?? '' ) ),
            'regular_price' => $overrides['regular_price'] ?? ( $variation['regular_price'] ?? ( $mapped['regular_price'] ?? null ) ),
            'sale_price'    => $overrides['sale_price']    ?? ( $variation['sale_price']    ?? ( $mapped['sale_price'] ?? null ) ),
            'stock_quantity' => $overrides['stock_quantity'] ?? ( $variation['stock_quantity'] ?? ( $mapped['stock_quantity'] ?? null ) ),
            'stock_status'  => $overrides['stock_status']  ?? ( $variation['stock_status']  ?? ( $mapped['stock_status'] ?? '' ) ),
            'image_url'     => $overrides['image_url']     ?? ( $variation['image_url']     ?? ( $mapped['image_url'] ?? '' ) ),
            'description'   => $overrides['description']   ?? ( $variation['description']   ?? ( $mapped['description'] ?? '' ) ),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $effective
     * @return array<string, mixed>
     */
    private function compose_final_product_payload( array $draft, array $effective ): array {
        $mapped = $this->decode_json( (string) ( $draft['mapped_payload_json'] ?? '' ) );
        $final  = is_array( $mapped ) ? $mapped : [];
        foreach ( $effective as $key => $value ) {
            $final[ $key ] = $value;
        }
        return $final;
    }

    /**
     * @param array<string, mixed> $variation
     * @param array<string, mixed> $effective
     * @return array<string, mixed>
     */
    private function compose_final_variation_payload( array $variation, array $effective ): array {
        $mapped = $this->decode_json( (string) ( $variation['mapped_payload_json'] ?? '' ) );
        $final  = is_array( $mapped ) ? $mapped : [];
        foreach ( $effective as $key => $value ) {
            $final[ $key ] = $value;
        }
        return $final;
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function compute_status( array $errors, array $warnings ): string {
        if ( $errors !== [] ) {
            return Product_Draft::VALIDATION_BLOCKED;
        }
        if ( $warnings !== [] ) {
            return Product_Draft::VALIDATION_WARNING;
        }
        return Product_Draft::VALIDATION_READY;
    }

    private function valid_ean( string $ean ): bool {
        $digits = preg_replace( '/\D/', '', $ean ) ?? '';
        $len    = strlen( $digits );
        return $len === 8 || $len === 12 || $len === 13 || $len === 14;
    }

    private function looks_like_valid_url( string $url ): bool {
        if ( filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            return false;
        }
        return str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' );
    }

    /**
     * @param mixed $value
     */
    private function coerce_float( $value ): float {
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }
        return 0.0;
    }

    /**
     * @param mixed $data
     */
    private function encode_json( $data ): string {
        $encoded = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? $encoded : 'null';
    }

    /**
     * @return array<mixed>|array<string, mixed>
     */
    private function decode_json( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
