<?php
/**
 * Rows in {prefix}wbs_product_sync_draft.
 *
 * Editable pre-sync product snapshot. Admin can modify fields here without
 * affecting Woo until the draft is approved and synced.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Models;

defined( 'ABSPATH' ) || exit;

class Product_Draft {

    public const VALIDATION_PENDING = 'pending';
    public const VALIDATION_READY   = 'ready';
    public const VALIDATION_WARNING = 'warning';
    public const VALIDATION_BLOCKED = 'blocked';

    public const REVIEW_PENDING  = 'pending';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    public const SYNC_NOT_SYNCED = 'not_synced';
    public const SYNC_QUEUED     = 'queued';
    public const SYNC_SYNCING    = 'syncing';
    public const SYNC_SYNCED     = 'synced';
    public const SYNC_FAILED     = 'failed';

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . WBS_STAGING_PRODUCT_DRAFT;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_by_wc_product( int $wc_product_id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_product_id = %d", $wc_product_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Insert or update a draft keyed by wc_product_id.
     *
     * @param array<string, mixed> $data
     * @return int Draft id.
     */
    public static function upsert_by_wc_product( int $wc_product_id, array $data ): int {
        global $wpdb;
        $existing = self::get_by_wc_product( $wc_product_id );
        $now      = current_time( 'mysql', true );

        $data['wc_product_id'] = $wc_product_id;
        $data['updated_at']    = $now;

        [ $values, $formats ] = self::normalize_columns( $data );

        if ( $existing ) {
            $wpdb->update( self::table(), $values, [ 'id' => (int) $existing['id'] ], $formats, [ '%d' ] );
            return (int) $existing['id'];
        }

        $values['created_at'] = $now;
        $formats[]            = '%s';
        $wpdb->insert( self::table(), $values, $formats );
        return (int) $wpdb->insert_id;
    }

    /**
     * Partial update with version bump.
     *
     * @param array<string, mixed> $data
     */
    public static function update( int $id, array $data ): void {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql', true );
        [ $values, $formats ] = self::normalize_columns( $data );
        $wpdb->update( self::table(), $values, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    public static function bump_version( int $id ): void {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET version = version + 1, updated_at = %s WHERE id = %d", current_time( 'mysql', true ), $id ) );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function search( array $filters = [], int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = self::table();

        $where  = [];
        $params = [];

        if ( ! empty( $filters['batch_id'] ) ) {
            $where[]  = 'batch_id = %d';
            $params[] = (int) $filters['batch_id'];
        }
        if ( ! empty( $filters['validation_status'] ) ) {
            $where[]  = 'validation_status = %s';
            $params[] = (string) $filters['validation_status'];
        }
        if ( ! empty( $filters['review_status'] ) ) {
            $where[]  = 'review_status = %s';
            $params[] = (string) $filters['review_status'];
        }
        if ( ! empty( $filters['sync_status'] ) ) {
            $where[]  = 'sync_status = %s';
            $params[] = (string) $filters['sync_status'];
        }
        if ( ! empty( $filters['search'] ) ) {
            $needle   = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
            $where[]  = '(name LIKE %s OR sku LIKE %s OR ean LIKE %s)';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        $sql = "SELECT * FROM {$table}";
        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql     .= ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';
        $params[] = max( 1, min( 500, $limit ) );
        $params[] = max( 0, $offset );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    public static function count_by_status(): array {
        global $wpdb;
        $table = self::table();

        $result = [
            'total'     => 0,
            'pending'   => 0,
            'ready'     => 0,
            'warning'   => 0,
            'blocked'   => 0,
            'approved'  => 0,
            'rejected'  => 0,
            'synced'    => 0,
            'failed'    => 0,
        ];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        foreach ( [ 'pending', 'ready', 'warning', 'blocked' ] as $status ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result[ $status ] = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE validation_status = %s", $status )
            );
        }

        foreach ( [ 'approved', 'rejected' ] as $status ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result[ $status ] = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE review_status = %s", $status )
            );
        }

        foreach ( [ 'synced', 'failed' ] as $status ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result[ $status ] = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sync_status = %s", $status )
            );
        }

        return $result;
    }

    /**
     * @return int[] Draft ids approved + validation=ready and not synced/failed.
     */
    public static function get_syncable_ids( int $limit = 100 ): array {
        global $wpdb;
        $table           = self::table();
        $variation_table = $wpdb->prefix . WBS_STAGING_VARIATION_DRAFT;
        $limit           = max( 1, min( 500, $limit ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE review_status = %s
                   AND (
                        validation_status IN (%s, %s)
                        OR (
                            product_type = %s
                            AND validation_status = %s
                            AND EXISTS (
                                SELECT 1 FROM {$variation_table} v
                                WHERE v.product_draft_id = {$table}.id
                                  AND v.validation_status IN (%s, %s)
                                  AND v.sync_status IN (%s, %s, %s)
                            )
                        )
                   )
                   AND sync_status IN (%s, %s, %s)
                 ORDER BY id ASC LIMIT %d",
                self::REVIEW_APPROVED,
                self::VALIDATION_READY,
                self::VALIDATION_WARNING,
                'variable',
                self::VALIDATION_BLOCKED,
                self::VALIDATION_READY,
                self::VALIDATION_WARNING,
                self::SYNC_NOT_SYNCED,
                self::SYNC_QUEUED,
                self::SYNC_FAILED,
                self::SYNC_NOT_SYNCED,
                self::SYNC_QUEUED,
                self::SYNC_FAILED,
                $limit
            )
        );
        return array_values( array_map( 'intval', is_array( $ids ) ? $ids : [] ) );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    private static function normalize_columns( array $data ): array {
        $column_formats = [
            'batch_id'                  => '%d',
            'wc_product_id'             => '%d',
            'product_type'              => '%s',
            'sku'                       => '%s',
            'ean'                       => '%s',
            'name'                      => '%s',
            'short_description'         => '%s',
            'description'               => '%s',
            'regular_price'             => '%s',
            'sale_price'                => '%s',
            'currency'                  => '%s',
            'stock_quantity'            => '%d',
            'stock_status'              => '%s',
            'manage_stock'              => '%d',
            'main_image_id'             => '%d',
            'main_image_url'            => '%s',
            'gallery_json'              => '%s',
            'categories_json'           => '%s',
            'tags_json'                 => '%s',
            'mapped_payload_json'       => '%s',
            'admin_overrides_json'      => '%s',
            'final_payload_json'        => '%s',
            'sync_price'                => '%d',
            'sync_stock'                => '%d',
            'sync_content'              => '%d',
            'sync_images'               => '%d',
            'bol_offer_snapshot_json'   => '%s',
            'bol_content_snapshot_json' => '%s',
            'validation_status'         => '%s',
            'validation_errors_json'    => '%s',
            'validation_warnings_json'  => '%s',
            'review_status'             => '%s',
            'approved_by'               => '%d',
            'approved_at'               => '%s',
            'sync_status'               => '%s',
            'last_sync_error'           => '%s',
            'last_synced_at'            => '%s',
            'version'                   => '%d',
            'created_at'                => '%s',
            'updated_at'                => '%s',
        ];

        $values  = [];
        $formats = [];
        foreach ( $data as $col => $val ) {
            if ( ! isset( $column_formats[ $col ] ) ) {
                continue;
            }
            if ( $val === null ) {
                $values[ $col ]  = null;
                $formats[]       = $column_formats[ $col ];
                continue;
            }
            $values[ $col ] = $val;
            $formats[]      = $column_formats[ $col ];
        }
        return [ $values, $formats ];
    }
}
