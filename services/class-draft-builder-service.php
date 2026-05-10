<?php
/**
 * Builds product_sync_draft and variation_sync_draft rows from WooCommerce data.
 *
 * Staging (review-before-sync) ingestion. The staged draft holds the mapped
 * payload, admin overrides, and the final payload used by the sync worker.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Services;

use WooBolSync\Includes\Logger;
use WooBolSync\Includes\Mapping_Config;
use WooBolSync\Models\Product_Draft;
use WooBolSync\Models\Sync_Audit;
use WooBolSync\Models\Sync_Batch;
use WooBolSync\Models\Variation_Draft;

defined( 'ABSPATH' ) || exit;

class Draft_Builder_Service {

    private Draft_Validator_Service $validator;

    public function __construct( ?Draft_Validator_Service $validator = null ) {
        $this->validator = $validator ?? new Draft_Validator_Service();
    }

    /**
     * Create a batch and ingest products into drafts.
     *
     * @param int[] $product_ids Optional list of WC product ids. When empty, uses settings-defined scope.
     * @return array{batch_id:int, products:int, variations:int, errors:int, skipped_excluded:int}
     */
    public function ingest( array $product_ids = [], string $label = '' ): array {
        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $label === '' ) {
            $label = sprintf( 'Staging run %s', current_time( 'mysql', true ) );
        }

        if ( $product_ids === [] ) {
            $product_ids = $this->collect_default_product_ids();
        }

        $batch_id = Sync_Batch::create( $label, $user_id );
        $product_count   = 0;
        $variation_count = 0;
        $errors          = 0;
        $skipped_excluded = 0;

        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( (int) $pid );
            if ( ! $product instanceof \WC_Product ) {
                ++$errors;
                continue;
            }
            if ( ! $this->should_stage_product( $product ) ) {
                ++$skipped_excluded;
                Logger::info(
                    'Skipping staging ingest for excluded-category product.',
                    [
                        'product_id' => (int) $pid,
                    ],
                    'staging'
                );
                continue;
            }
            try {
                $result = $this->ingest_product( $product, $batch_id );
                ++$product_count;
                $variation_count += $result['variations'];
            } catch ( \Throwable $e ) {
                ++$errors;
                Logger::error(
                    'Failed to ingest product into staging drafts.',
                    [
                        'product_id' => (int) $pid,
                        'exception'  => $e->getMessage(),
                    ],
                    'staging'
                );
            }
        }

        Sync_Batch::update_counts(
            $batch_id,
            [
                'products'   => $product_count,
                'variations' => $variation_count,
                'errors'     => $errors,
                'skipped_excluded' => $skipped_excluded,
            ]
        );

        Logger::info(
            'Staging ingest complete.',
            [
                'batch_id'   => $batch_id,
                'products'   => $product_count,
                'variations' => $variation_count,
                'errors'     => $errors,
                'skipped_excluded' => $skipped_excluded,
            ],
            'staging'
        );

        return [
            'batch_id'   => $batch_id,
            'products'   => $product_count,
            'variations' => $variation_count,
            'errors'     => $errors,
            'skipped_excluded' => $skipped_excluded,
        ];
    }

    /**
     * Ingest a single WooCommerce product (with variations for variable products).
     *
     * @return array{product_draft_id:int, variations:int}
     */
    public function ingest_product( \WC_Product $product, int $batch_id = 0 ): array {
        $wc_product_id = $product->get_id();
        $product_type  = $product->get_type();
        $ean           = Mapping_Config::get_ean( $product );
        $base_price    = Mapping_Config::get_base_price_for_bol( $product );

        $mapped_payload = $this->build_product_mapped_payload( $product, $ean, $base_price );
        $bol_snapshot   = $this->fetch_bol_snapshots( $ean );

        $data = [
            'batch_id'            => $batch_id,
            'wc_product_id'       => $wc_product_id,
            'product_type'        => $product_type,
            'sku'                 => (string) $product->get_sku(),
            'ean'                 => $ean,
            'name'                => Mapping_Config::get_listing_title( $product ),
            'short_description'   => (string) $product->get_short_description(),
            'description'         => (string) $product->get_description(),
            'regular_price'       => $this->numeric_or_null( $product->get_regular_price() ),
            'sale_price'          => $this->numeric_or_null( $product->get_sale_price() ),
            'currency'            => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
            'stock_quantity'      => $product->get_stock_quantity() === null ? null : (int) $product->get_stock_quantity(),
            'stock_status'        => (string) $product->get_stock_status(),
            'manage_stock'        => $product->get_manage_stock() ? 1 : 0,
            'main_image_id'       => (int) $product->get_image_id(),
            'main_image_url'      => $this->resolve_image_url( (int) $product->get_image_id() ),
            'gallery_json'        => $this->encode_json( $this->collect_gallery( $product ) ),
            'categories_json'     => $this->encode_json( $this->collect_terms( $wc_product_id, 'product_cat' ) ),
            'tags_json'           => $this->encode_json( $this->collect_terms( $wc_product_id, 'product_tag' ) ),
            'mapped_payload_json' => $this->encode_json( $mapped_payload ),
            'admin_overrides_json' => null,
            'final_payload_json'  => $this->encode_json( $mapped_payload ),
            'sync_price'          => 1,
            'sync_stock'          => 1,
            'sync_content'        => 0,
            'sync_images'         => 0,
            'bol_offer_snapshot_json'   => $this->encode_json( $bol_snapshot['offer'] ),
            'bol_content_snapshot_json' => $this->encode_json( $bol_snapshot['content'] ),
            'validation_status'   => Product_Draft::VALIDATION_PENDING,
            'review_status'       => Product_Draft::REVIEW_PENDING,
            'sync_status'         => Product_Draft::SYNC_NOT_SYNCED,
        ];

        $existing_draft = Product_Draft::get_by_wc_product( $wc_product_id );

        $draft_id = Product_Draft::upsert_by_wc_product( $wc_product_id, $data );

        if ( $existing_draft ) {
            Sync_Audit::log_change(
                Sync_Audit::ENTITY_PRODUCT_DRAFT,
                $draft_id,
                'ingest_refresh',
                '',
                'mapped payload regenerated',
                0,
                'Ingest refresh'
            );
        } else {
            Sync_Audit::log_change(
                Sync_Audit::ENTITY_PRODUCT_DRAFT,
                $draft_id,
                'create',
                '',
                'draft created',
                0,
                'Initial ingest'
            );
        }

        $variations = 0;
        if ( $product instanceof \WC_Product_Variable ) {
            $variations = $this->ingest_variations( $product, $draft_id );
        }

        $this->validator->revalidate_product_draft( $draft_id );

        return [
            'product_draft_id' => $draft_id,
            'variations'       => $variations,
        ];
    }

    private function ingest_variations( \WC_Product_Variable $product, int $product_draft_id ): int {
        $count = 0;
        foreach ( $product->get_children() as $child_id ) {
            $variation = wc_get_product( (int) $child_id );
            if ( ! $variation instanceof \WC_Product_Variation ) {
                continue;
            }
            $this->ingest_variation( $variation, $product_draft_id, $product );
            ++$count;
        }
        return $count;
    }

    private function ingest_variation( \WC_Product_Variation $variation, int $product_draft_id, \WC_Product_Variable $parent_product ): int {
        $attributes = $this->normalize_attributes( $variation->get_attributes() );
        $signature  = $this->attributes_signature( $attributes );
        
        // EAN: Use variation's own EAN first, fall back to parent only if missing
        $ean = Mapping_Config::get_ean( $variation );
        if ( $ean === '' ) {
            $ean = Mapping_Config::get_ean( $parent_product );
        }
        
        // Price: Use variation's own price first, fall back to parent only if zero/missing
        $base_price = Mapping_Config::get_base_price_for_bol( $variation );
        if ( $base_price <= 0 ) {
            $base_price = Mapping_Config::get_base_price_for_bol( $parent_product );
        }
        
        // Content: ALWAYS use parent's title and descriptions for variation listings
        $parent_name              = Mapping_Config::get_listing_title( $parent_product );
        $parent_short_description = (string) $parent_product->get_short_description();
        $parent_description       = (string) $parent_product->get_description();
        $parent_main_image_url    = $this->resolve_image_url( (int) $parent_product->get_image_id() );
        $parent_gallery           = $this->collect_gallery( $parent_product );
        $parent_categories        = $this->collect_terms( (int) $parent_product->get_id(), 'product_cat' );
        $parent_tags              = $this->collect_terms( (int) $parent_product->get_id(), 'product_tag' );

        $mapped_payload = $this->build_variation_mapped_payload(
            $variation,
            $ean,
            $base_price,
            $attributes,
            $parent_name,
            $parent_short_description,
            $parent_description,
            $parent_main_image_url,
            $parent_gallery,
            $parent_categories,
            $parent_tags
        );

        $variation_image_id  = (int) $variation->get_image_id();
        $variation_image_url = $this->resolve_image_url( $variation_image_id );
        
        // Use variation image if available, otherwise fall back to parent image
        if ( $variation_image_url === '' ) {
            $variation_image_url = $parent_main_image_url;
            $variation_image_id  = (int) $parent_product->get_image_id();
        }

        // Get stock quantity - handle both managed and unmanaged stock
        $stock_qty = $variation->get_stock_quantity();
        if ( $stock_qty === null || $stock_qty === '' ) {
            // For unmanaged stock, use a default value based on status
            $stock_status = $variation->get_stock_status();
            $stock_qty = ( $stock_status === 'instock' || $stock_status === 'onbackorder' ) ? 1 : 0;
        }

        $data = [
            'product_draft_id'     => $product_draft_id,
            'wc_parent_id'         => (int) $variation->get_parent_id(),
            'sku'                  => (string) $variation->get_sku(),
            'ean'                  => $ean,
            'attributes_json'      => $this->encode_json( $attributes ),
            'attributes_signature' => $signature,
            'regular_price'        => $this->numeric_or_null( $variation->get_regular_price() ),
            'sale_price'           => $this->numeric_or_null( $variation->get_sale_price() ),
            'stock_quantity'       => (int) $stock_qty,
            'stock_status'         => (string) $variation->get_stock_status(),
            'manage_stock'         => $variation->get_manage_stock() ? 1 : 0,
            'image_id'             => $variation_image_id,
            'image_url'            => $variation_image_url,
            'description'          => $parent_description,
            'mapped_payload_json'  => $this->encode_json( $mapped_payload ),
            'final_payload_json'   => $this->encode_json( $mapped_payload ),
            'validation_status'    => Product_Draft::VALIDATION_PENDING,
            'sync_status'          => Product_Draft::SYNC_NOT_SYNCED,
        ];

        $existing = Variation_Draft::get_by_wc_variation( (int) $variation->get_id() );
        $id = Variation_Draft::upsert_by_wc_variation( (int) $variation->get_id(), $data );

        if ( ! $existing ) {
            Sync_Audit::log_change(
                Sync_Audit::ENTITY_VARIATION_DRAFT,
                $id,
                'create',
                '',
                'variation draft created',
                0,
                'Initial ingest'
            );
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_product_mapped_payload( \WC_Product $product, string $ean, float $base_price ): array {
        return [
            'ean'                => $ean,
            'name'               => Mapping_Config::get_listing_title( $product ),
            'description'        => Mapping_Config::get_listing_description( $product ),
            'short_description'  => (string) $product->get_short_description(),
            'regular_price'      => (float) $product->get_regular_price(),
            'sale_price'         => $this->numeric_or_null( $product->get_sale_price() ),
            'price_for_bol'      => Mapping_Config::apply_bol_price_margin( $base_price ),
            'currency'           => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
            'stock_quantity'     => $product->get_stock_quantity(),
            'stock_status'       => $product->get_stock_status(),
            'manage_stock'       => $product->get_manage_stock(),
            'main_image_url'     => $this->resolve_image_url( (int) $product->get_image_id() ),
            'gallery'            => $this->collect_gallery( $product ),
            'categories'         => $this->collect_terms( $product->get_id(), 'product_cat' ),
            'tags'               => $this->collect_terms( $product->get_id(), 'product_tag' ),
            'fulfilment_method'  => Mapping_Config::get_default_fulfilment_method(),
            'delivery_code'      => Mapping_Config::get_default_delivery_code(),
        ];
    }

    /**
     * Build payload for a variation - treated as individual product on bol.com.
     * 
     * Data sources:
     * - FROM PARENT: Title, descriptions, gallery, categories (content/SEO)
     * - FROM VARIATION: EAN, price, stock, SKU, image (product-specific data)
     * 
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    private function build_variation_mapped_payload(
        \WC_Product_Variation $variation,
        string $ean,
        float $base_price,
        array $attributes,
        string $parent_name,
        string $parent_short_description,
        string $parent_description,
        string $parent_main_image_url,
        array $parent_gallery,
        array $parent_categories,
        array $parent_tags
    ): array {
        // Build variation title: Parent name + attribute values
        // Example: "T-Shirt - Red / Large"
        $variation_name = $parent_name;
        if ( $attributes !== [] ) {
            $variation_name .= ' - ' . implode( ' / ', array_values( $attributes ) );
        }

        // Image: Use variation's image if set, otherwise use parent's image
        $variation_image_url = $this->resolve_image_url( (int) $variation->get_image_id() );
        if ( $variation_image_url === '' ) {
            $variation_image_url = $parent_main_image_url;
        }

        // Stock: Always from variation (handle both managed and unmanaged stock)
        $stock_qty = $variation->get_stock_quantity();
        if ( $stock_qty === null || $stock_qty === '' ) {
            $stock_status = $variation->get_stock_status();
            $stock_qty = ( $stock_status === 'instock' || $stock_status === 'onbackorder' ) ? 1 : 0;
        }

        return [
            // Product identifiers - FROM VARIATION
            'ean'                => $ean,                                    // Variation's own EAN
            'sku'                => (string) $variation->get_sku(),         // Variation's own SKU
            
            // Title - FROM PARENT + attributes
            'name'               => $variation_name,                        // Parent name + attributes
            'attributes'         => $attributes,                            // Color, Size, etc.
            
            // Pricing - FROM VARIATION
            'regular_price'      => (float) $variation->get_regular_price(), // Variation's price
            'sale_price'         => $this->numeric_or_null( $variation->get_sale_price() ),
            'price_for_bol'      => Mapping_Config::apply_bol_price_margin( $base_price ),
            
            // Stock - FROM VARIATION
            'stock_quantity'     => (int) $stock_qty,                       // Variation's stock
            'stock_status'       => $variation->get_stock_status(),
            'manage_stock'       => $variation->get_manage_stock(),
            
            // Images - FROM VARIATION (with parent fallback)
            'image_url'          => $variation_image_url,                   // Variation or parent image
            'main_image_url'     => $variation_image_url,
            'gallery'            => $parent_gallery,                        // Parent gallery
            
            // Content/SEO - ALWAYS FROM PARENT
            'short_description'  => $parent_short_description,              // Parent description
            'description'        => $parent_description,                    // Parent description
            'categories'         => $parent_categories,                     // Parent categories
            'tags'               => $parent_tags,                           // Parent tags
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collect_gallery( \WC_Product $product ): array {
        $out = [];
        $ids = $product->get_gallery_image_ids();
        if ( ! is_array( $ids ) ) {
            return $out;
        }
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) {
                continue;
            }
            $url = $this->resolve_image_url( $id );
            if ( $url === '' ) {
                continue;
            }
            $out[] = [
                'id'  => $id,
                'url' => $url,
                'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{id:int, name:string, slug:string}>
     */
    private function collect_terms( int $post_id, string $taxonomy ): array {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        $out = [];
        foreach ( $terms as $term ) {
            $out[] = [
                'id'   => (int) $term->term_id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function normalize_attributes( array $raw ): array {
        $out = [];
        foreach ( $raw as $key => $value ) {
            $clean_key   = sanitize_key( (string) $key );
            $clean_value = is_scalar( $value ) ? trim( (string) $value ) : '';
            if ( $clean_key === '' || $clean_value === '' ) {
                continue;
            }
            $out[ $clean_key ] = $clean_value;
        }
        ksort( $out );
        return $out;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function attributes_signature( array $attributes ): string {
        if ( $attributes === [] ) {
            return '';
        }
        $parts = [];
        foreach ( $attributes as $k => $v ) {
            $parts[] = $k . '=' . strtolower( $v );
        }
        return mb_substr( implode( '|', $parts ), 0, 191 );
    }

    private function resolve_image_url( int $attachment_id ): string {
        if ( $attachment_id <= 0 ) {
            return '';
        }
        $url = wp_get_attachment_url( $attachment_id );
        return is_string( $url ) ? $url : '';
    }

    /**
     * @return array{offer:array<string,mixed>,content:array<string,mixed>}
     */
    private function fetch_bol_snapshots( string $ean ): array {
        $snapshots = [
            'offer'   => [],
            'content' => [],
        ];
        if ( $ean === '' ) {
            return $snapshots;
        }

        $content = ( new Bol_API_Service() )->get_catalog_product( $ean );
        if ( ! is_wp_error( $content ) && isset( $content['code'] ) && (int) $content['code'] >= 200 && (int) $content['code'] < 300 && is_array( $content['body'] ?? null ) ) {
            $snapshots['content'] = $content['body'];
        }

        $offer = ( new Bol_API_Service() )->request_with_headers(
            '/offers?ean=' . rawurlencode( $ean ) . '&page=1&size=1',
            'GET'
        );
        if ( ! is_wp_error( $offer ) && isset( $offer['code'] ) && (int) $offer['code'] >= 200 && (int) $offer['code'] < 300 && is_array( $offer['body'] ?? null ) ) {
            $snapshots['offer'] = $offer['body'];
        }

        return $snapshots;
    }

    /**
     * @return int[]
     */
    private function collect_default_product_ids(): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }
        $statuses = Mapping_Config::sync_only_published() ? [ 'publish' ] : [ 'publish', 'draft', 'private' ];
        $args = [
            'status' => $statuses,
            'limit'  => max( 1, (int) get_option( 'wbs_sync_batch_size', 25 ) ),
            'return' => 'ids',
            'type'   => [ 'simple', 'variable' ],
        ];
        $excluded_categories = Mapping_Config::get_excluded_category_ids();
        if ( $excluded_categories !== [] ) {
            $args['category_exclude'] = $excluded_categories;
        }
        $ids = wc_get_products( $args );
        return array_values( array_map( 'intval', is_array( $ids ) ? $ids : [] ) );
    }

    private function should_stage_product( \WC_Product $product ): bool {
        return ! Mapping_Config::product_in_excluded_category( $product );
    }

    /**
     * @param mixed $value
     */
    private function numeric_or_null( $value ): ?string {
        if ( $value === null || $value === '' ) {
            return null;
        }
        if ( ! is_numeric( $value ) ) {
            return null;
        }
        return (string) $value;
    }

    /**
     * @param mixed $data
     */
    private function encode_json( $data ): string {
        $encoded = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? $encoded : 'null';
    }
}
