<?php
/**
 * Category map, field map, and bol.com listing defaults from options.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

use WooBolSync\Models\Category_Map;

/**
 * Mapping_Config
 */
final class Mapping_Config {

    public const OPTION_FIELD_MAP   = 'wbs_field_map';
    public const OPTION_EO_ID        = 'wbs_economic_operator_id';
    public const OPTION_EO_NAME      = 'wbs_economic_operator_name';
    public const OPTION_EO_STATUS    = 'wbs_economic_operator_status';
    public const OPTION_EO_LAST_SYNC = 'wbs_economic_operator_last_sync';
    public const OPTION_DELIVERY     = 'wbs_default_delivery_code';
    /** Default v10 FBR deliveryCode (1–2 day window; confirm against bol.com ReDoc for your account). */
    public const DEFAULT_V10_DELIVERY_CODE = '1-2d';
    public const OPTION_FULFILMENT   = 'wbs_default_fulfilment_method';
    public const OPTION_SYNC_PUBLISH = 'wbs_sync_only_published';
    public const OPTION_EXCLUDE_CATS = 'wbs_exclude_category_ids';

    public const OPTION_MARGIN_TYPE  = 'wbs_price_margin_type';
    public const OPTION_MARGIN_VALUE = 'wbs_price_margin_value';
    public const OPTION_OFFER_MEDIA_TYPE = 'wbs_offer_media_type';
    public const OPTION_AUTO_RECOVER_STALE_OFFERS = 'wbs_auto_recover_stale_offers';
    public const OPTION_WEBHOOK_ENABLED = 'wbs_webhook_enabled';
    public const OPTION_WEBHOOK_SUBSCRIPTION_ID = 'wbs_webhook_subscription_id';
    public const OPTION_WEBHOOK_SHARED_SECRET = 'wbs_webhook_shared_secret';
    public const OPTION_WEBHOOK_SIGNING_REQUIRED = 'wbs_webhook_signing_required';
    public const OPTION_WEBHOOK_SIGNATURE_KEYS = 'wbs_webhook_signature_keys';
    public const OPTION_WEBHOOK_SIGNATURE_KEYS_FETCHED = 'wbs_webhook_signature_keys_fetched_at';
    public const OPTION_DEFAULT_BRAND = 'wbs_default_brand';
    public const OPTION_LAST_ORDER_SYNC = 'wbs_last_order_sync_at';
    public const OPTION_CONNECTION_STATUS = 'wbs_connection_status';
    public const OPTION_CONNECTION_MESSAGE = 'wbs_connection_message';
    public const OPTION_CONNECTION_LAST_TESTED = 'wbs_connection_last_tested_at';

    public const OPTION_STAGING_ENABLED       = 'wbs_staging_mode_enabled';
    public const OPTION_STAGING_AUTO_INGEST   = 'wbs_staging_auto_ingest';
    public const OPTION_STAGING_SYNC_ENABLED  = 'wbs_staging_sync_enabled';

    public const OPTION_PRODUCT_SYNC_MODE     = 'wbs_product_sync_mode';
    public const OPTION_PRODUCT_SYNC_ENABLED  = 'wbs_product_sync_enabled';
    public const OPTION_ALLOW_NEW_OFFERS      = 'wbs_allow_new_offers';
    /**
     * Legacy single toggle; migrated once to granular options. Kept for uninstall / old DB rows.
     */
    public const OPTION_SYNC_PRODUCT_CONTENT  = 'wbs_sync_product_content_to_bol';
    public const OPTION_SYNC_OFFER_PRICE      = 'wbs_sync_offer_price_to_bol';
    public const OPTION_SYNC_OFFER_STOCK      = 'wbs_sync_offer_stock_to_bol';
    public const OPTION_SYNC_CONTENT_NAME     = 'wbs_sync_content_name_to_bol';
    public const OPTION_SYNC_CONTENT_DESCRIPTION = 'wbs_sync_content_description_to_bol';
    public const OPTION_SYNC_CONTENT_IMAGES   = 'wbs_sync_content_images_to_bol';
    /**
     * Stored override for content API "Product Group" when missing. Empty in DB means use
     * {@see DEFAULT_CONTENT_FALLBACK_PRODUCT_GROUP} (coffee beans label for bol.nl catalog).
     */
    public const OPTION_CONTENT_FALLBACK_PRODUCT_GROUP = 'wbs_content_fallback_product_group';
    /** Default bol product group when none is configured (Dutch catalog: coffee beans). */
    public const DEFAULT_CONTENT_FALLBACK_PRODUCT_GROUP = 'Koffiebonen';
    public const OPTION_PRODUCT_SYNC_TIME     = 'wbs_product_sync_time';
    public const OPTION_PRODUCT_SYNC_WEEKDAY  = 'wbs_product_sync_weekday';
    public const OPTION_PRODUCT_SYNC_MONTHDAY = 'wbs_product_sync_monthday';

    public const OPTION_ORDER_SYNC_MODE     = 'wbs_order_sync_mode';
    public const OPTION_ORDER_SYNC_TIME     = 'wbs_order_sync_time';
    public const OPTION_ORDER_SYNC_WEEKDAY  = 'wbs_order_sync_weekday';
    public const OPTION_ORDER_SYNC_MONTHDAY = 'wbs_order_sync_monthday';
    public const OPTION_ORDER_SYNC_INTERVAL = 'wbs_order_sync_interval_minutes';
    public const OPTION_ORDER_SYNC_LAST_RUN = 'wbs_order_sync_last_run';

    /** @var int[] */
    public const ORDER_SYNC_INTERVAL_CHOICES = [ 5, 10, 15, 20 ];

    public const DEFAULT_ORDER_SYNC_INTERVAL_MINUTES = 15;

    public const SYNC_MODE_DAILY     = 'daily';
    public const SYNC_MODE_WEEKLY    = 'weekly';
    public const SYNC_MODE_MONTHLY   = 'monthly';
    public const SYNC_MODE_WC_UPDATES = 'wc_updates';

    /**
     * @return array<string, string>
     */
    public static function get_field_map(): array {
        $raw = get_option( self::OPTION_FIELD_MAP, [] );
        if ( ! is_array( $raw ) ) {
            return self::default_field_map();
        }
        return array_merge( self::default_field_map(), array_intersect_key( array_map( 'strval', $raw ), self::default_field_map() ) );
    }

    /**
     * @return array<string, string>
     */
    public static function default_field_map(): array {
        return [
            'ean_source'         => 'sku',
            'title_source'       => 'product_name',
            'description_source' => 'short_description',
        ];
    }

    public static function get_economic_operator_id(): string {
        return trim( (string) get_option( self::OPTION_EO_ID, '' ) );
    }

    public static function get_economic_operator_name(): string {
        return trim( (string) get_option( self::OPTION_EO_NAME, '' ) );
    }

    public static function get_economic_operator_status(): string {
        return strtoupper( trim( (string) get_option( self::OPTION_EO_STATUS, '' ) ) );
    }

    public static function get_economic_operator_last_sync(): string {
        return trim( (string) get_option( self::OPTION_EO_LAST_SYNC, '' ) );
    }

    /**
     * Clear cached economic operator (e.g. empty API list or credential change).
     */
    public static function clear_economic_operator_options(): void {
        update_option( self::OPTION_EO_ID, '' );
        update_option( self::OPTION_EO_NAME, '' );
        update_option( self::OPTION_EO_STATUS, '' );
        update_option( self::OPTION_EO_LAST_SYNC, '' );
    }

    public static function get_default_delivery_code(): string {
        $fb = self::DEFAULT_V10_DELIVERY_CODE;
        $c  = trim( (string) get_option( self::OPTION_DELIVERY, $fb ) );
        $out = $c !== '' ? $c : $fb;
        /**
         * Override default v10 FBR deliveryCode (Offer API v10 only).
         *
         * @param string $out Resolved delivery code from settings or {@see DEFAULT_V10_DELIVERY_CODE}.
         */
        return (string) apply_filters( 'wbs_default_delivery_code', $out );
    }

    public static function get_default_fulfilment_method(): string {
        $method = strtoupper( trim( (string) get_option( self::OPTION_FULFILMENT, 'FBR' ) ) );
        return in_array( $method, [ 'FBR', 'FBB' ], true ) ? $method : 'FBR';
    }

    /**
     * Condition payload for NEW offers. v11 allows only category; v10 expects name + category.
     *
     * @return array<string, string>
     */
    public static function build_offer_condition_new_for_api(): array {
        if ( self::get_offer_media_type() === 'application/vnd.retailer.v11+json' ) {
            return [ 'category' => 'NEW' ];
        }
        return [
            'name'     => 'NEW',
            'category' => 'NEW',
        ];
    }

    /**
     * Fulfilment for Offer API create/update.
     * v10 FBR: deliveryCode. v11 FBR: schedule (default MY_DELIVERY_PROMISE; optional BOL_DELIVERY_PROMISE + deliveryPromise).
     *
     * @return array<string, mixed>
     */
    public static function build_offer_fulfilment_for_api(): array {
        $method = self::get_default_fulfilment_method();
        $out    = [ 'method' => $method ];
        if ( $method !== 'FBR' ) {
            return $out;
        }
        if ( self::get_offer_media_type() === 'application/vnd.retailer.v11+json' ) {
            /**
             * v11 FBR schedule: MY_DELIVERY_PROMISE (seller dashboard), SHIPPING_VIA_BOL, or BOL_DELIVERY_PROMISE (+ deliveryPromise).
             *
             * @param string $schedule One of MY_DELIVERY_PROMISE, SHIPPING_VIA_BOL, BOL_DELIVERY_PROMISE.
             */
            $schedule = (string) apply_filters( 'wbs_v11_fbr_schedule', 'MY_DELIVERY_PROMISE' );
            $allowed  = [ 'MY_DELIVERY_PROMISE', 'SHIPPING_VIA_BOL', 'BOL_DELIVERY_PROMISE' ];
            if ( ! in_array( $schedule, $allowed, true ) ) {
                $schedule = 'MY_DELIVERY_PROMISE';
            }
            $out['schedule'] = $schedule;
            if ( $schedule === 'BOL_DELIVERY_PROMISE' ) {
                $promise = [
                    'minimumDaysToCustomer' => 1,
                    'maximumDaysToCustomer' => 2,
                ];
                /**
                 * Used only when schedule is BOL_DELIVERY_PROMISE.
                 * Add ultimateOrderTime only for same/next-day promises (e.g. 0–1 days); bol omits it for longer windows.
                 *
                 * @param array{minimumDaysToCustomer:int,maximumDaysToCustomer:int,ultimateOrderTime?:string} $promise
                 */
                $filtered = apply_filters( 'wbs_v11_fbr_delivery_promise', $promise );
                if ( is_array( $filtered ) ) {
                    foreach ( [ 'minimumDaysToCustomer', 'maximumDaysToCustomer', 'ultimateOrderTime' ] as $key ) {
                        if ( array_key_exists( $key, $filtered ) ) {
                            $promise[ $key ] = $key === 'ultimateOrderTime'
                                ? (string) $filtered[ $key ]
                                : (int) $filtered[ $key ];
                        }
                    }
                }
                if ( isset( $promise['ultimateOrderTime'] ) && $promise['ultimateOrderTime'] === '' ) {
                    unset( $promise['ultimateOrderTime'] );
                }
                $out['deliveryPromise'] = $promise;
            }
            return $out;
        }
        $out['deliveryCode'] = self::get_default_delivery_code();
        return $out;
    }

    public static function sync_only_published(): bool {
        return (int) get_option( self::OPTION_SYNC_PUBLISH, 1 ) === 1;
    }

    /**
     * @return int[]
     */
    public static function get_excluded_category_ids(): array {
        $raw = get_option( self::OPTION_EXCLUDE_CATS, '' );
        if ( is_array( $raw ) ) {
            return array_map( 'absint', $raw );
        }
        $ids = array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) );
        return array_values( array_unique( $ids ) );
    }

    /**
     * Product ID used for product_cat terms (parent for variations).
     */
    public static function get_category_source_product_id( \WC_Product $product ): int {
        if ( $product->is_type( 'variation' ) ) {
            return (int) $product->get_parent_id();
        }
        return $product->get_id();
    }

    /**
     * Resolve bol.com category id for a WC product (first matching mapped ancestor).
     */
    public static function get_bol_category_id_for_product( \WC_Product $product ): string {
        $tid = Category_Map::resolve_mapped_wc_term_id_for_product( $product );
        if ( $tid <= 0 ) {
            return '';
        }
        $map = Category_Map::get_all();

        return isset( $map[ $tid ] ) ? trim( (string) $map[ $tid ] ) : '';
    }

    /**
     * @return bool
     */
    public static function product_in_excluded_category( \WC_Product $product ): bool {
        $excluded = self::get_excluded_category_ids();
        if ( $excluded === [] ) {
            return false;
        }
        $pid   = self::get_category_source_product_id( $product );
        $terms = get_the_terms( $pid, 'product_cat' );
        if ( ! is_array( $terms ) ) {
            return false;
        }
        foreach ( $terms as $term ) {
            if ( in_array( (int) $term->term_id, $excluded, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string 12- or 13-digit EAN/GTIN or empty (GTIN-14 with leading 0 is reduced to EAN-13)
     */
    public static function get_ean( \WC_Product $product ): string {
        $custom = apply_filters( 'wbs_product_ean', null, $product );
        if ( is_string( $custom ) && $custom !== '' ) {
            $ean = self::coerce_gtin_to_ean( self::normalize_ean_digits( $custom ) );
            if ( $ean !== '' ) {
                return $ean;
            }
        }

        $map     = self::get_field_map();
        $primary = $map['ean_source'] ?? 'sku';
        $chain   = self::ean_source_resolution_chain( $primary, $product );

        $targets = [ $product ];
        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent instanceof \WC_Product ) {
                $targets[] = $parent;
            }
        }

        foreach ( $targets as $target ) {
            foreach ( $chain as $src ) {
                $raw = self::resolve_source( $target, $src );
                $ean = self::coerce_gtin_to_ean( self::normalize_ean_digits( $raw ) );
                if ( $ean !== '' ) {
                    return $ean;
                }
            }
        }

        // Variable parents may keep GTIN/EAN only on child variations.
        if ( $product->is_type( 'variable' ) ) {
            $children = $product->get_children();
            if ( is_array( $children ) ) {
                foreach ( $children as $child_id ) {
                    $child = wc_get_product( (int) $child_id );
                    if ( ! $child instanceof \WC_Product ) {
                        continue;
                    }
                    foreach ( $chain as $src ) {
                        $raw = self::resolve_source( $child, $src );
                        $ean = self::coerce_gtin_to_ean( self::normalize_ean_digits( $raw ) );
                        if ( $ean !== '' ) {
                            return $ean;
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Strip non-digits (hyphens/spaces in formatted barcodes).
     */
    public static function normalize_ean_digits( string $raw ): string {
        return preg_replace( '/\D/', '', $raw ) ?? '';
    }

    /**
     * Normalize and validate an offer barcode for bol Offer API.
     *
     * Accepts 12/13-digit GTIN (or GTIN-14 with leading 0 -> EAN-13), then checks digit checksum.
     */
    public static function normalize_offer_ean( string $raw ): string {
        $digits = self::normalize_ean_digits( $raw );
        $ean    = self::coerce_gtin_to_ean( $digits );
        if ( $ean === '' ) {
            return '';
        }
        return self::has_valid_gtin_checksum( $ean ) ? $ean : '';
    }

    private static function is_valid_ean_length( string $digits ): bool {
        $len = strlen( $digits );
        return $len === 12 || $len === 13;
    }

    /**
     * Map stored barcodes to 12- or 13-digit GTIN/EAN for bol.com.
     *
     * WooCommerce often stores GTIN-14 (14 digits) with indicator digit 0 for a consumer unit;
     * the embedded EAN-13 is digits 2–14 (GS1).
     *
     * @param string $digits digits-only string
     * @return string 12- or 13-digit code or empty if unusable
     */
    private static function coerce_gtin_to_ean( string $digits ): string {
        if ( self::is_valid_ean_length( $digits ) ) {
            return $digits;
        }
        if ( strlen( $digits ) === 14 && $digits[0] === '0' ) {
            $candidate = substr( $digits, 1 );
            if ( self::is_valid_ean_length( $candidate ) ) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * GTIN checksum for 12/13 digits (mod-10).
     */
    private static function has_valid_gtin_checksum( string $digits ): bool {
        if ( ! self::is_valid_ean_length( $digits ) || ! ctype_digit( $digits ) ) {
            return false;
        }

        $sum       = 0;
        $length    = strlen( $digits );
        $use_three = true; // Rightmost digit before checksum uses weight 3.
        for ( $i = $length - 2; $i >= 0; $i-- ) {
            $digit = (int) $digits[ $i ];
            $sum  += $use_three ? $digit * 3 : $digit;
            $use_three = ! $use_three;
        }

        $check = ( 10 - ( $sum % 10 ) ) % 10;
        return $check === (int) $digits[ $length - 1 ];
    }

    /**
     * Try configured source first, then common GTIN locations so WC “GTIN / UPC / EAN” works even when field map still says SKU.
     *
     * @return string[]
     */
    private static function ean_source_resolution_chain( string $primary, \WC_Product $product ): array {
        $fallbacks = [
            'meta:_global_unique_id',
            'meta:_wbs_gtin',
            'meta:_alg_ean',
            'attribute:pa_ean',
            'attribute:pa_gtin',
            'sku',
        ];
        $ordered   = array_merge( [ $primary ], $fallbacks );
        $seen      = [];
        $chain     = [];
        foreach ( $ordered as $src ) {
            if ( ! isset( $seen[ $src ] ) ) {
                $seen[ $src ] = true;
                $chain[]      = $src;
            }
        }

        /**
         * @param string[]    $chain
         * @param string      $primary Configured `ean_source`.
         * @param \WC_Product $product Product or variation being synced.
         */
        return apply_filters( 'wbs_ean_fallback_sources', $chain, $primary, $product );
    }

    public static function get_listing_title( \WC_Product $product ): string {
        $map = self::get_field_map();
        $src = $map['title_source'] ?? 'product_name';
        $t   = self::resolve_source( $product, $src );
        if ( $t === '' ) {
            $t = $product->get_name();
        }
        $base_title = self::normalize_listing_text( $t );
        if ( $base_title !== '' ) {
            $structured_title = self::build_structured_listing_title( $product, $base_title );
            if ( $structured_title !== '' ) {
                return $structured_title;
            }
            return $base_title;
        }
        return 'Product ' . (string) $product->get_id();
    }

    private static function build_structured_listing_title( \WC_Product $product, string $base_title ): string {
        $base_title = self::normalize_listing_text( $base_title );
        if ( $base_title === '' ) {
            return '';
        }

        $parts = [];
        $brand = self::get_product_brand_for_title( $product );

        // bol titles are usually concise: Brand + Product type/name + key variant facts.
        if ( $brand !== '' && stripos( $base_title, $brand ) === false ) {
            $parts[] = $brand;
        }
        $parts[] = $base_title;

        $group = self::get_product_group_for_title( $product );
        if ( $group !== '' && stripos( $base_title, $group ) === false ) {
            $parts[] = $group;
        }

        foreach ( self::get_product_features_for_title( $product, $parts ) as $feature ) {
            $parts[] = $feature;
        }

        $parts = self::dedupe_title_parts( $parts );
        $title = self::normalize_listing_text( implode( ' ', $parts ) );

        if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) > 150 ) {
            $title = rtrim( mb_substr( $title, 0, 150, 'UTF-8' ), " \t\n\r\0\x0B-," );
        } elseif ( strlen( $title ) > 150 ) {
            $title = rtrim( substr( $title, 0, 150 ), " \t\n\r\0\x0B-," );
        }

        return $title;
    }

    private static function get_product_brand_for_title( \WC_Product $product ): string {
        $product_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
        $brand      = '';

        if ( $product_id > 0 ) {
            $brand = self::normalize_listing_text( self::canonical_brand_value( (string) get_post_meta( $product_id, '_wbs_brand', true ) ) );
        }

        if ( $brand === '' ) {
            $brand = self::normalize_listing_text( self::canonical_brand_value( self::get_default_brand( $product ) ) );
        }

        return $brand;
    }

    private static function get_product_group_for_title( \WC_Product $product ): string {
        $group_sources = [
            'attribute:pa_product_group',
            'attribute:product_group',
            'attribute:pa_productgroep',
            'attribute:pa_type',
            'attribute:pa_category',
        ];

        foreach ( $group_sources as $source ) {
            $value = self::normalize_listing_text( self::resolve_source( $product, $source ) );
            if ( $value !== '' ) {
                return $value;
            }
        }

        $pid   = self::get_category_source_product_id( $product );
        $terms = get_the_terms( $pid, 'product_cat' );
        if ( is_array( $terms ) && isset( $terms[0] ) && isset( $terms[0]->name ) ) {
            return self::normalize_listing_text( (string) $terms[0]->name );
        }

        return '';
    }

    /**
     * @param string[] $existing_parts
     * @return string[]
     */
    private static function get_product_features_for_title( \WC_Product $product, array $existing_parts ): array {
        $features = [];
        $target   = $product;

        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent instanceof \WC_Product ) {
                $target = $parent;
            }
        }

        $net_content = self::normalize_listing_text( (string) get_post_meta( $target->get_id(), '_wbs_net_content', true ) );
        if ( $net_content !== '' ) {
            $features[] = $net_content;
        }

        foreach ( [ 'pa_size', 'size', 'pa_color', 'color', 'pa_flavor', 'flavor' ] as $attr ) {
            $value = self::normalize_listing_text( (string) $product->get_attribute( $attr ) );
            if ( $value !== '' ) {
                $features[] = $value;
            }
        }

        if ( $product instanceof \WC_Product_Variation ) {
            foreach ( $product->get_attributes() as $value ) {
                $clean = self::normalize_listing_text( (string) $value );
                if ( $clean !== '' ) {
                    $features[] = $clean;
                }
            }
        }

        $features = self::dedupe_title_parts( array_merge( $existing_parts, $features ) );
        $features = array_slice( $features, count( $existing_parts ), 3 );

        return array_values( $features );
    }

    /**
     * @param string[] $parts
     * @return string[]
     */
    private static function dedupe_title_parts( array $parts ): array {
        $out  = [];
        $seen = [];

        foreach ( $parts as $part ) {
            $clean = self::normalize_listing_text( (string) $part );
            if ( $clean === '' ) {
                continue;
            }
            $key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $clean, 'UTF-8' ) : strtolower( $clean );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[]        = $clean;
        }

        return $out;
    }

    public static function get_listing_description( \WC_Product $product ): string {
        $map = self::get_field_map();
        $src = $map['description_source'] ?? 'short_description';
        $chain = match ( $src ) {
            'short_long' => [ 'short_description', 'description' ],
            'long_short' => [ 'description', 'short_description' ],
            default      => [ $src ],
        };

        foreach ( $chain as $candidate_source ) {
            $candidate = self::normalize_listing_text( self::resolve_source( $product, $candidate_source ) );
            if ( $candidate !== '' ) {
                return $candidate;
            }
        }

        return self::normalize_listing_text( $product->get_name() );
    }

    public static function normalize_listing_text( string $raw ): string {
        $raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
        $raw = preg_replace( '/<\/?span[^>]*>/i', ' - ', $raw ) ?? $raw;
        $raw = wp_strip_all_tags( $raw );
        $raw = wp_strip_all_tags( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) );
        $raw = preg_replace( '/\s+/', ' ', $raw ) ?? $raw;
        $raw = preg_replace( '/\s*-\s*-\s*/', ' - ', $raw ) ?? $raw;
        return trim( $raw, " \t\n\r\0\x0B-" );
    }

    /**
     * @param \WC_Product $product WC product.
     * @param string      $source  sku | product_name | short_description | description | meta:_key | attribute:taxonomy
     */
    public static function resolve_source( \WC_Product $product, string $source ): string {
        $source = trim( $source );
        if ( $source === 'sku' ) {
            return (string) $product->get_sku();
        }
        if ( $source === 'product_name' ) {
            return wp_strip_all_tags( (string) $product->get_name() );
        }
        if ( $source === 'short_description' ) {
            return wp_strip_all_tags( (string) $product->get_short_description() );
        }
        if ( $source === 'description' ) {
            return wp_strip_all_tags( (string) $product->get_description() );
        }
        if ( str_starts_with( $source, 'meta:' ) ) {
            $key = substr( $source, 5 );
            if ( $key === '_global_unique_id' && method_exists( $product, 'get_global_unique_id' ) ) {
                $g = (string) $product->get_global_unique_id();
                if ( $g !== '' ) {
                    return $g;
                }
            }
            $v = $product->get_meta( $key, true );
            return is_scalar( $v ) ? (string) $v : '';
        }
        if ( str_starts_with( $source, 'attribute:' ) ) {
            $tax = substr( $source, 10 );
            $v   = $product->get_attribute( $tax );
            return (string) $v;
        }

        if ( $product->is_type( 'variation' ) && in_array( $source, [ 'short_description', 'description' ], true ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent instanceof \WC_Product ) {
                return self::resolve_source( $parent, $source );
            }
        }

        return '';
    }

    /**
     * Base catalog price in WooCommerce (before bol.com margin).
     */
    public static function get_base_price_for_bol( \WC_Product $product ): float {
        $price = (float) $product->get_regular_price();
        if ( $price <= 0 ) {
            $price = (float) $product->get_price();
        }
        return $price;
    }

    /**
     * Price sent to bol.com after optional margin (percent or fixed).
     */
    public static function apply_bol_price_margin( float $base_price ): float {
        $base_price = max( 0, $base_price );
        $type       = (string) get_option( self::OPTION_MARGIN_TYPE, 'none' );
        $val        = (float) get_option( self::OPTION_MARGIN_VALUE, 0 );

        if ( 'percent' === $type && $val > 0 ) {
            $out = round( $base_price * ( 1 + ( $val / 100 ) ), 2 );
        } elseif ( 'fixed' === $type && $val !== 0.0 ) {
            $out = round( max( 0, $base_price + $val ), 2 );
        } else {
            $out = round( $base_price, 2 );
        }

        /**
         * Final unit price sent to bol.com after margin (store currency).
         *
         * @param float $out        Price after margin rules.
         * @param float $base_price WooCommerce base price before margin.
         */
        return (float) apply_filters( 'wbs_bol_listing_price', $out, $base_price );
    }

    public static function staging_mode_enabled(): bool {
        return (int) get_option( self::OPTION_STAGING_ENABLED, 0 ) === 1;
    }

    public static function staging_auto_ingest_enabled(): bool {
        return (int) get_option( self::OPTION_STAGING_AUTO_INGEST, 0 ) === 1;
    }

    public static function staging_sync_enabled(): bool {
        return (int) get_option( self::OPTION_STAGING_SYNC_ENABLED, 1 ) === 1;
    }

    public static function webhook_enabled(): bool {
        return (int) get_option( self::OPTION_WEBHOOK_ENABLED, 1 ) === 1;
    }

    public static function webhook_signing_required(): bool {
        return (int) get_option( self::OPTION_WEBHOOK_SIGNING_REQUIRED, 1 ) === 1;
    }

    public static function get_webhook_shared_secret(): string {
        return trim( (string) get_option( self::OPTION_WEBHOOK_SHARED_SECRET, '' ) );
    }

    /**
     * Cached bol.com signature keys (persistent option fed by Subscription_Sync_Service).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_webhook_signature_keys(): array {
        $raw = get_option( self::OPTION_WEBHOOK_SIGNATURE_KEYS, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $entry ) {
            if ( is_array( $entry ) ) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Brand value sent in `Brand` content attribute. Filterable so multi-brand
     * stores can override per product via `wbs_default_brand`.
     */
    public static function get_default_brand( ?\WC_Product $product = null ): string {
        $stored = trim( (string) get_option( self::OPTION_DEFAULT_BRAND, '' ) );
        /**
         * @param string           $stored  Configured default brand value (may be empty).
         * @param \WC_Product|null $product Optional product context.
         */
        $brand = (string) apply_filters( 'wbs_default_brand', $stored, $product );
        return self::canonical_brand_value( trim( $brand ) );
    }

    /**
     * Product record that should supply shared bol.com content metadata.
     * Variations usually inherit these fields from the variable parent.
     */
    public static function get_content_meta_source_product( \WC_Product $product ): \WC_Product {
        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent instanceof \WC_Product ) {
                return $parent;
            }
        }

        return $product;
    }

    /**
     * Brand value for bol.com content payloads, with variation -> parent fallback.
     */
    public static function get_content_brand( \WC_Product $product ): string {
        $meta_product = self::get_content_meta_source_product( $product );
        $brand        = self::canonical_brand_value( trim( (string) $meta_product->get_meta( '_wbs_brand', true ) ) );

        if ( $brand === '' ) {
            $brand = self::get_default_brand( $product );
        }

        return self::canonical_brand_value( trim( $brand ) );
    }

    /**
     * Canonicalize known brand spellings so bol.com always receives the exact brand form expected by the merchant.
     */
    public static function canonical_brand_value( string $brand ): string {
        $brand = trim( $brand );
        if ( $brand === '' ) {
            return '';
        }

        $folded = function_exists( 'remove_accents' ) ? remove_accents( $brand ) : $brand;
        $folded = function_exists( 'mb_strtolower' ) ? mb_strtolower( $folded, 'UTF-8' ) : strtolower( $folded );

        if ( $folded === 'caffebello' ) {
            return 'CAFFÈBELLO';
        }

        return $brand;
    }

    public static function get_offer_media_type(): string {
        $raw = trim( (string) get_option( self::OPTION_OFFER_MEDIA_TYPE, 'application/vnd.retailer.v10+json' ) );
        $media_type = $raw;
        if ( ! in_array( $media_type, [ 'application/vnd.retailer.v10+json', 'application/vnd.retailer.v11+json' ], true ) ) {
            $media_type = 'application/vnd.retailer.v10+json';
        }
        return $media_type;
    }

    public static function auto_recover_stale_offers_enabled(): bool {
        return (int) get_option( self::OPTION_AUTO_RECOVER_STALE_OFFERS, 0 ) === 1;
    }

    public static function get_product_sync_mode(): string {
        $m = sanitize_key( (string) get_option( self::OPTION_PRODUCT_SYNC_MODE, self::SYNC_MODE_DAILY ) );
        $allowed = [ self::SYNC_MODE_DAILY, self::SYNC_MODE_WEEKLY, self::SYNC_MODE_MONTHLY, self::SYNC_MODE_WC_UPDATES ];
        return in_array( $m, $allowed, true ) ? $m : self::SYNC_MODE_DAILY;
    }

    public static function product_sync_enabled(): bool {
        return (int) get_option( self::OPTION_PRODUCT_SYNC_ENABLED, 1 ) === 1;
    }

    public static function allow_new_offers(): bool {
        return (int) get_option( self::OPTION_ALLOW_NEW_OFFERS, 1 ) === 1;
    }

    /**
     * One-time migration from legacy OPTION_SYNC_PRODUCT_CONTENT to per-field toggles.
     */
    public static function maybe_migrate_sync_granular_options(): void {
        if ( get_option( 'wbs_sync_granular_migrated_v2', '' ) === '1' ) {
            return;
        }
        $legacy = (int) get_option( self::OPTION_SYNC_PRODUCT_CONTENT, 1 );
        update_option( self::OPTION_SYNC_OFFER_PRICE, 1 );
        update_option( self::OPTION_SYNC_OFFER_STOCK, 1 );
        update_option( self::OPTION_SYNC_CONTENT_NAME, $legacy );
        update_option( self::OPTION_SYNC_CONTENT_DESCRIPTION, $legacy );
        update_option( self::OPTION_SYNC_CONTENT_IMAGES, $legacy );
        update_option( 'wbs_sync_granular_migrated_v2', '1' );
    }

    public static function sync_offer_price_enabled(): bool {
        return (int) get_option( self::OPTION_SYNC_OFFER_PRICE, 1 ) === 1;
    }

    public static function sync_offer_stock_enabled(): bool {
        return (int) get_option( self::OPTION_SYNC_OFFER_STOCK, 1 ) === 1;
    }

    public static function sync_content_name_enabled(): bool {
        return (int) get_option( self::OPTION_SYNC_CONTENT_NAME, 1 ) === 1;
    }

    public static function sync_content_description_enabled(): bool {
        return (int) get_option( self::OPTION_SYNC_CONTENT_DESCRIPTION, 1 ) === 1;
    }

    public static function sync_content_images_enabled(): bool {
        return (int) get_option( self::OPTION_SYNC_CONTENT_IMAGES, 1 ) === 1;
    }

    /**
     * True when any catalog content field (name, description, images) should be pushed via the content API.
     */
    public static function sync_product_content_enabled(): bool {
        return self::sync_content_name_enabled()
            || self::sync_content_description_enabled()
            || self::sync_content_images_enabled();
    }

    public static function get_product_sync_time(): string {
        $t = trim( (string) get_option( self::OPTION_PRODUCT_SYNC_TIME, '02:00' ) );
        return preg_match( '/^\d{1,2}:\d{2}$/', $t ) === 1 ? $t : '02:00';
    }

    public static function get_product_sync_weekday(): int {
        $w = (int) get_option( self::OPTION_PRODUCT_SYNC_WEEKDAY, 1 );
        return min( 6, max( 0, $w ) );
    }

    public static function get_product_sync_monthday(): int {
        $d = (int) get_option( self::OPTION_PRODUCT_SYNC_MONTHDAY, 1 );
        return min( 28, max( 1, $d ) );
    }

    public static function get_order_sync_interval_minutes(): int {
        $m = (int) get_option( self::OPTION_ORDER_SYNC_INTERVAL, self::DEFAULT_ORDER_SYNC_INTERVAL_MINUTES );
        return in_array( $m, self::ORDER_SYNC_INTERVAL_CHOICES, true )
            ? $m
            : self::DEFAULT_ORDER_SYNC_INTERVAL_MINUTES;
    }

    public static function get_order_sync_interval_seconds(): int {
        return self::get_order_sync_interval_minutes() * MINUTE_IN_SECONDS;
    }

    public static function get_order_sync_last_run(): int {
        return max( 0, (int) get_option( self::OPTION_ORDER_SYNC_LAST_RUN, 0 ) );
    }

    public static function set_order_sync_last_run( ?int $timestamp = null ): void {
        update_option( self::OPTION_ORDER_SYNC_LAST_RUN, $timestamp ?? time(), false );
    }

    /**
     * @param int $minutes
     */
    public static function sanitize_order_sync_interval_minutes( $minutes ): int {
        $minutes = (int) $minutes;
        return in_array( $minutes, self::ORDER_SYNC_INTERVAL_CHOICES, true )
            ? $minutes
            : self::DEFAULT_ORDER_SYNC_INTERVAL_MINUTES;
    }

    public static function get_subscription_id(): string {
        return trim( (string) get_option( self::OPTION_WEBHOOK_SUBSCRIPTION_ID, '' ) );
    }

    public static function get_last_order_sync_at(): string {
        return trim( (string) get_option( self::OPTION_LAST_ORDER_SYNC, '' ) );
    }

    public static function get_webhook_url(): string {
        return rest_url( 'woobol/v1/webhook' );
    }

    public static function get_connection_status(): string {
        $status = sanitize_key( (string) get_option( self::OPTION_CONNECTION_STATUS, 'unknown' ) );
        return in_array( $status, [ 'success', 'error', 'warning', 'unknown' ], true ) ? $status : 'unknown';
    }

    public static function get_connection_message(): string {
        return trim( (string) get_option( self::OPTION_CONNECTION_MESSAGE, '' ) );
    }

    public static function get_connection_last_tested(): string {
        return trim( (string) get_option( self::OPTION_CONNECTION_LAST_TESTED, '' ) );
    }

    public static function save_connection_status( string $status, string $message ): void {
        update_option( self::OPTION_CONNECTION_STATUS, sanitize_key( $status ) );
        update_option( self::OPTION_CONNECTION_MESSAGE, sanitize_text_field( $message ) );
        update_option( self::OPTION_CONNECTION_LAST_TESTED, current_time( 'mysql', true ) );
    }

    public static function clear_connection_status(): void {
        update_option( self::OPTION_CONNECTION_STATUS, 'unknown' );
        update_option( self::OPTION_CONNECTION_MESSAGE, '' );
        update_option( self::OPTION_CONNECTION_LAST_TESTED, '' );
    }

    /**
     * bol.com content attribute "Product Group" when missing from category templates / meta.
     * Default matches coffee beans (Dutch) for typical `language` => nl payloads; confirm against bol's data model.
     */
    public static function get_content_fallback_product_group(): string {
        $saved = trim(
            (string) get_option(
                self::OPTION_CONTENT_FALLBACK_PRODUCT_GROUP,
                self::DEFAULT_CONTENT_FALLBACK_PRODUCT_GROUP
            )
        );

        return $saved !== '' ? $saved : self::DEFAULT_CONTENT_FALLBACK_PRODUCT_GROUP;
    }

    /**
     * Append Product Group for content API when absent or empty (fixes "product group is missing" offline offers).
     *
     * @param array<int, array<string, mixed>> $attributes
     * @param \WC_Product|null                  $product
     * @return array<int, array<string, mixed>>
     */
    public static function ensure_product_group_fallback_on_attributes( array $attributes, $product = null ): array {
        if ( self::attributes_include_nonempty_product_group( $attributes ) ) {
            return $attributes;
        }

        $fallback = self::get_content_fallback_product_group();

        /**
         * Override the fallback Product Group before content upload. Trimmed empty string falls back to
         * {@see DEFAULT_CONTENT_FALLBACK_PRODUCT_GROUP}.
         *
         * @param string $fallback Current resolved value (never empty before filter; see {@see get_content_fallback_product_group}).
         * @param \WC_Product|null $product
         * @param array<int, array<string, mixed>> $attributes
         */
        $fallback = (string) apply_filters( 'wbs_content_product_group_fallback_value', $fallback, $product, $attributes );
        $fallback = trim( $fallback );
        if ( $fallback === '' ) {
            $fallback = self::DEFAULT_CONTENT_FALLBACK_PRODUCT_GROUP;
        }

        $attributes[] = [
            'id'     => 'Product Group',
            'values' => [
                [ 'value' => $fallback ],
            ],
        ];

        return $attributes;
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     */
    private static function attributes_include_nonempty_product_group( array $attributes ): bool {
        foreach ( $attributes as $attr ) {
            if ( ! is_array( $attr ) ) {
                continue;
            }
            $id = isset( $attr['id'] ) ? trim( (string) $attr['id'] ) : '';
            if ( strcasecmp( $id, 'Product Group' ) !== 0 ) {
                continue;
            }
            $values = $attr['values'] ?? null;
            if ( ! is_array( $values ) ) {
                continue;
            }
            foreach ( $values as $v ) {
                if ( ! is_array( $v ) || ! isset( $v['value'] ) ) {
                    continue;
                }
                if ( trim( (string) $v['value'] ) !== '' ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Hash payload for smart sync skip.
     */
    public static function compute_sync_hash( \WC_Product $product ): string {
        $image_bits = [ (string) $product->get_image_id() ];
        $gallery    = $product->get_gallery_image_ids();
        if ( is_array( $gallery ) && $gallery !== [] ) {
            $image_bits[] = implode( ',', array_map( 'strval', $gallery ) );
        }
        $parts = [
            $product->get_type(),
            (string) $product->get_parent_id(),
            self::get_ean( $product ),
            (string) $product->get_regular_price(),
            (string) $product->get_sale_price(),
            (string) $product->get_stock_quantity(),
            self::get_listing_title( $product ),
            self::get_listing_description( $product ),
            self::get_bol_category_id_for_product( $product ),
            self::get_content_fallback_product_group(),
            (string) (int) self::sync_offer_price_enabled(),
            (string) (int) self::sync_offer_stock_enabled(),
            (string) (int) self::sync_content_name_enabled(),
            (string) (int) self::sync_content_description_enabled(),
            (string) (int) self::sync_content_images_enabled(),
            (string) get_option( self::OPTION_MARGIN_TYPE, 'none' ),
            (string) get_option( self::OPTION_MARGIN_VALUE, '0' ),
            self::get_default_fulfilment_method(),
            self::get_default_delivery_code(),
            implode( '|', $image_bits ),
        ];
        return hash( 'sha256', implode( '|', $parts ) );
    }
}
