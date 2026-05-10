<?php
/**
 * Rows in {prefix}wbs_order_mapping.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Models;

defined( 'ABSPATH' ) || exit;

class Order_Mapping {

    /**
     * @return array<string, mixed>|null
     */
    public static function get_by_bol_order_id( string $bol_order_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_ORDER_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE bol_order_id = %s LIMIT 1", $bol_order_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_by_wc_order_id( int $wc_order_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_ORDER_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d LIMIT 1", $wc_order_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public static function save_mapping( int $wc_order_id, string $bol_order_id, string $status ): void {
        global $wpdb;

        $table = $wpdb->prefix . WBS_ORDER_MAP;
        $row   = self::get_by_wc_order_id( $wc_order_id );

        if ( $row ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->update(
                $table,
                [
                    'bol_order_id' => $bol_order_id,
                    'status'       => $status,
                    'updated_at'   => current_time( 'mysql', true ),
                ],
                [ 'wc_order_id' => $wc_order_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->insert(
            $table,
            [
                'wc_order_id'  => $wc_order_id,
                'bol_order_id' => $bol_order_id,
                'status'       => $status,
                'updated_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
    }

    public static function update_status_by_wc_order_id( int $wc_order_id, string $status ): void {
        global $wpdb;

        $table = $wpdb->prefix . WBS_ORDER_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->update(
            $table,
            [
                'status'     => $status,
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'wc_order_id' => $wc_order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_failed( int $limit = 50 ): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_ORDER_MAP;
        $limit = max( 1, min( 500, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status IN ('failed','error') ORDER BY updated_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @return array{total:int, shipped:int, failed:int, pending:int}
     */
    public static function get_stats(): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_ORDER_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $linked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE bol_order_id <> ''" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('failed','error')" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $shipped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'shipped'" );

        $processing = 0;
        if ( function_exists( 'wc_orders_count' ) ) {
            $processing = (int) wc_orders_count( 'processing' );
        }

        return [
            'total'   => $linked,
            'shipped' => $shipped,
            'failed'  => $failed,
            'pending' => $processing,
        ];
    }
}
