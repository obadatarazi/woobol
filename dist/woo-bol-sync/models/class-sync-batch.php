<?php
/**
 * Rows in {prefix}wbs_sync_batches.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Models;

defined( 'ABSPATH' ) || exit;

class Sync_Batch {

    public const STATUS_OPEN    = 'open';
    public const STATUS_CLOSED  = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . WBS_STAGING_BATCHES;
    }

    /**
     * @param array<string, mixed> $counts
     */
    public static function create( string $label, int $created_by, array $counts = [] ): int {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $wpdb->insert(
            self::table(),
            [
                'label'      => mb_substr( $label, 0, 191 ),
                'status'     => self::STATUS_OPEN,
                'created_by' => $created_by,
                'counts'     => wp_json_encode( $counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $counts
     */
    public static function update_counts( int $batch_id, array $counts ): void {
        global $wpdb;
        $wpdb->update(
            self::table(),
            [
                'counts'     => wp_json_encode( $counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $batch_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function set_status( int $batch_id, string $status ): void {
        global $wpdb;
        $wpdb->update(
            self::table(),
            [
                'status'     => sanitize_key( $status ),
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $batch_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get( int $batch_id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $batch_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recent( int $limit = 20 ): array {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, min( 200, $limit ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public static function get_latest_open(): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT 1", self::STATUS_OPEN ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }
}
