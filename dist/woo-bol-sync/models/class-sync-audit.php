<?php
/**
 * Rows in {prefix}wbs_sync_audit.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Models;

defined( 'ABSPATH' ) || exit;

class Sync_Audit {

    public const ENTITY_PRODUCT_DRAFT   = 'product_draft';
    public const ENTITY_VARIATION_DRAFT = 'variation_draft';
    public const ENTITY_JOB             = 'sync_job';

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . WBS_STAGING_AUDIT;
    }

    /**
     * @param mixed $old_value
     * @param mixed $new_value
     */
    public static function log_change(
        string $entity_type,
        int $entity_id,
        string $field_name,
        $old_value,
        $new_value,
        int $changed_by = 0,
        string $note = ''
    ): void {
        if ( $changed_by === 0 && function_exists( 'get_current_user_id' ) ) {
            $changed_by = (int) get_current_user_id();
        }

        global $wpdb;
        $wpdb->insert(
            self::table(),
            [
                'entity_type' => sanitize_key( $entity_type ),
                'entity_id'   => $entity_id,
                'field_name'  => mb_substr( $field_name, 0, 100 ),
                'old_value'   => self::stringify( $old_value ),
                'new_value'   => self::stringify( $new_value ),
                'changed_by'  => $changed_by,
                'changed_at'  => current_time( 'mysql', true ),
                'note'        => mb_substr( $note, 0, 255 ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    /**
     * Log the diff between two associative arrays.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param string[]             $fields Whitelist of field names to diff.
     */
    public static function log_diff(
        string $entity_type,
        int $entity_id,
        array $before,
        array $after,
        array $fields,
        int $changed_by = 0,
        string $note = ''
    ): void {
        foreach ( $fields as $field ) {
            $old = $before[ $field ] ?? null;
            $new = $after[ $field ] ?? null;
            if ( self::stringify( $old ) === self::stringify( $new ) ) {
                continue;
            }
            self::log_change( $entity_type, $entity_id, $field, $old, $new, $changed_by, $note );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recent_for_entity( string $entity_type, int $entity_id, int $limit = 50 ): array {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, min( 500, $limit ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d ORDER BY id DESC LIMIT %d",
                $entity_type,
                $entity_id,
                $limit
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param mixed $value
     */
    private static function stringify( $value ): string {
        if ( $value === null ) {
            return '';
        }
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }
        $encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? $encoded : '';
    }
}
