<?php
/**
 * Rows in {prefix}wbs_product_mapping.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Models;

defined( 'ABSPATH' ) || exit;

class Product_Mapping {

    /**
     * @return array<string, mixed>|null
     */
    public static function get_row( int $wc_product_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_product_id = %d", $wc_product_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public static function get_sync_hash( int $wc_product_id ): string {
        $row = self::get_row( $wc_product_id );
        return is_array( $row ) ? (string) ( $row['sync_hash'] ?? '' ) : '';
    }

    public static function get_offer_id( int $wc_product_id ): string {
        $row = self::get_row( $wc_product_id );
        return is_array( $row ) ? (string) ( $row['bol_offer_id'] ?? '' ) : '';
    }

    /**
     * @return int[]
     */
    public static function get_mapped_product_ids( int $limit = 0 ): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        $sql   = "SELECT wc_product_id FROM {$table} WHERE bol_offer_id <> '' ORDER BY updated_at DESC";

        if ( $limit > 0 ) {
            $limit = max( 1, min( 5000, $limit ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_col( $wpdb->prepare( $sql . ' LIMIT %d', $limit ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_col( $sql );
        }

        return array_values( array_filter( array_map( 'intval', is_array( $rows ) ? $rows : [] ) ) );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_by_offer_id( string $offer_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE bol_offer_id = %s LIMIT 1", $offer_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Clear bol_offer_id for the WooCommerce row linked to this offer (after delete on bol.com).
     *
     * @return int WooCommerce product ID if a row was updated, 0 if none matched.
     */
    public static function unlink_by_bol_offer_id( string $offer_id ): int {
        $offer_id = trim( $offer_id );
        if ( $offer_id === '' ) {
            return 0;
        }

        $row = self::get_by_offer_id( $offer_id );
        if ( ! is_array( $row ) ) {
            return 0;
        }

        $wc_id = (int) ( $row['wc_product_id'] ?? 0 );
        if ( $wc_id < 1 ) {
            return 0;
        }

        $meta = [];
        if ( ! empty( $row['meta'] ) && is_string( $row['meta'] ) ) {
            $decoded = json_decode( $row['meta'], true );
            $meta    = is_array( $decoded ) ? $decoded : [];
        }
        $meta['offer_deleted_on_bol_at'] = current_time( 'mysql', true );

        self::save_row( $wc_id, '', (string) ( $row['bol_ean'] ?? '' ), '', $meta );

        return $wc_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_by_ean( string $ean ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE bol_ean = %s LIMIT 1", $ean ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @param array<string, mixed> $meta_decoded
     */
    public static function save_row( int $wc_product_id, string $bol_offer_id, string $bol_ean, string $sync_hash, array $meta_decoded ): void {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        $meta  = wp_json_encode( $meta_decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $row   = self::get_row( $wc_product_id );

        if ( $row ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->update(
                $table,
                [
                    'bol_offer_id' => $bol_offer_id,
                    'bol_ean'      => $bol_ean,
                    'sync_hash'    => $sync_hash,
                    'meta'         => $meta,
                    'updated_at'   => current_time( 'mysql', true ),
                ],
                [ 'wc_product_id' => $wc_product_id ],
                [ '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->insert(
            $table,
            [
                'wc_product_id' => $wc_product_id,
                'bol_offer_id'  => $bol_offer_id,
                'bol_ean'       => $bol_ean,
                'sync_hash'     => $sync_hash,
                'meta'          => $meta,
                'updated_at'    => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_failed_rows( int $limit = 100 ): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        $limit = max( 1, min( 500, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE meta IS NOT NULL AND meta LIKE %s ORDER BY updated_at DESC LIMIT %d",
                '%"failed":true%',
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_retryable_rows( int $limit = 100 ): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        $limit = max( 1, min( 500, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE meta IS NOT NULL
                   AND (meta LIKE %s OR meta LIKE %s)
                 ORDER BY updated_at DESC
                 LIMIT %d",
                '%"failed":true%',
                '%"pending_async":true%',
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Recently detected EAN change events that triggered mapping reset/recreate.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_ean_change_rows( int $limit = 5 ): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        $limit = max( 1, min( 50, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE meta IS NOT NULL
                   AND meta LIKE %s
                 ORDER BY updated_at DESC
                 LIMIT %d",
                '%"ean_changed":true%',
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * WooCommerce product IDs that need a new bol offer after an EAN change (mapping reset cleared offer id).
     * These are easy to miss because batch sync only touches a slice of the catalog per run.
     *
     * @return int[]
     */
    public static function get_wc_product_ids_pending_offer_after_ean_change( int $limit = 25 ): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        $limit = max( 1, min( 100, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $col = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT wc_product_id FROM {$table}
                 WHERE bol_offer_id = %s
                   AND bol_ean <> %s
                   AND meta IS NOT NULL
                   AND meta LIKE %s
                 ORDER BY updated_at DESC
                 LIMIT %d",
                '',
                '',
                '%"ean_changed":true%',
                $limit
            )
        );

        if ( ! is_array( $col ) || $col === [] ) {
            return [];
        }

        return array_values( array_filter( array_map( 'intval', $col ) ) );
    }

    /**
     * @return array{synced:int, failed:int, pending:int}
     */
    public static function get_stats(): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_PRODUCT_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $synced = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE bol_offer_id <> ''" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE meta IS NOT NULL AND meta LIKE '%\"failed\":true%'" );

        $published = 0;
        if ( post_type_exists( 'product' ) ) {
            $counts    = wp_count_posts( 'product' );
            $published = isset( $counts->publish ) ? (int) $counts->publish : 0;
        }

        return [
            'synced'  => $synced,
            'failed'  => $failed,
            'pending' => max( 0, $published - $synced ),
        ];
    }
}
