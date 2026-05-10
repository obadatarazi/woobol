<?php
/**
 * Rows in {prefix}wbs_sync_job and {prefix}wbs_sync_job_item.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Models;

defined( 'ABSPATH' ) || exit;

class Sync_Job {

    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    public const ITEM_PENDING = 'pending';
    public const ITEM_RUNNING = 'running';
    public const ITEM_SUCCESS = 'success';
    public const ITEM_FAILED  = 'failed';
    public const ITEM_SKIPPED = 'skipped';

    private static function job_table(): string {
        global $wpdb;
        return $wpdb->prefix . WBS_STAGING_JOB;
    }

    private static function item_table(): string {
        global $wpdb;
        return $wpdb->prefix . WBS_STAGING_JOB_ITEM;
    }

    public static function create( int $batch_id, int $triggered_by ): int {
        global $wpdb;
        $wpdb->insert(
            self::job_table(),
            [
                'batch_id'     => $batch_id,
                'status'       => self::STATUS_QUEUED,
                'triggered_by' => $triggered_by,
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%d', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function mark_started( int $job_id ): void {
        global $wpdb;
        $wpdb->update(
            self::job_table(),
            [
                'status'     => self::STATUS_RUNNING,
                'started_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $job_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * @param array<string, mixed> $counts
     */
    public static function mark_finished( int $job_id, string $status, array $counts, string $message = '' ): void {
        global $wpdb;
        $wpdb->update(
            self::job_table(),
            [
                'status'   => sanitize_key( $status ),
                'counts'   => wp_json_encode( $counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'message'  => mb_substr( $message, 0, 65535 ),
                'ended_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $job_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get( int $job_id ): ?array {
        global $wpdb;
        $table = self::job_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recent( int $limit = 20 ): array {
        global $wpdb;
        $table = self::job_table();
        $limit = max( 1, min( 200, $limit ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    public static function add_item( int $job_id, int $draft_id, string $entity_type ): int {
        global $wpdb;
        $wpdb->insert(
            self::item_table(),
            [
                'job_id'      => $job_id,
                'draft_id'    => $draft_id,
                'entity_type' => sanitize_key( $entity_type ),
                'status'      => self::ITEM_PENDING,
            ],
            [ '%d', '%d', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     */
    public static function record_item_result( int $item_id, string $status, array $request, array $response, string $error = '' ): void {
        global $wpdb;
        $wpdb->update(
            self::item_table(),
            [
                'status'           => sanitize_key( $status ),
                'request_payload'  => wp_json_encode( $request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'response_payload' => wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'error_message'    => mb_substr( $error, 0, 65535 ),
                'ended_at'         => current_time( 'mysql', true ),
            ],
            [ 'id' => $item_id ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function mark_item_started( int $item_id ): void {
        global $wpdb;
        $wpdb->update(
            self::item_table(),
            [
                'status'     => self::ITEM_RUNNING,
                'started_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $item_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Mark any items still "running" as failed (PHP timeout, fatal error, or killed request).
     *
     * @return int Rows updated.
     */
    public static function finalize_stale_running_items( int $job_id, string $reason = '' ): int {
        global $wpdb;
        if ( $reason === '' ) {
            $reason = __( 'Item was still running when the job finished (request timeout, PHP error, or process stopped). Re-run sync if needed.', 'woo-bol-sync' );
        }
        $table = self::item_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE job_id = %d AND status = %s",
                $job_id,
                self::ITEM_RUNNING
            )
        );
        if ( ! is_array( $ids ) || $ids === [] ) {
            return 0;
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $values       = array_merge(
            [ self::ITEM_FAILED, mb_substr( $reason, 0, 65535 ), current_time( 'mysql', true ) ],
            array_map( 'intval', $ids )
        );
        $query = "UPDATE {$table} SET status = %s, error_message = %s, ended_at = %s WHERE id IN ({$placeholders})";
        // $wpdb->prepare() takes variadic args, not a single array — passing an array caused fatals and 500 on admin-ajax.
        $sql = call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $query ], $values ) );
        if ( is_string( $sql ) && $sql !== '' ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $sql );
        }

        return (int) count( $ids );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function items_for_job( int $job_id ): array {
        global $wpdb;
        $table = self::item_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id ASC", $job_id ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }
}
