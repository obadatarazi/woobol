<?php
/**
 * Database-backed logging for sync and API activity.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Logger
 */
class Logger {

    private static bool $debug_mode_cached = false;
    private static bool $debug_mode_loaded = false;

    /**
     * Reload debug flag from options (e.g. after settings save).
     */
    public static function refresh_debug_mode(): void {
        self::$debug_mode_loaded = false;
    }

    private static function is_debug_mode(): bool {
        if ( ! self::$debug_mode_loaded ) {
            self::$debug_mode_cached = (bool) get_option( 'wbs_debug_mode', false );
            self::$debug_mode_loaded  = true;
        }
        return self::$debug_mode_cached;
    }

    /**
     * @return string
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . WBS_LOG_TABLE;
    }

    /**
     * @param string               $level   error|warning|info|debug
     * @param string               $message Log message.
     * @param array<string, mixed> $data    Optional structured data (stored as JSON).
     * @param string               $context Context slug.
     */
    public static function log( string $level, string $message, array $data = [], string $context = 'general' ): void {
        if ( 'debug' === $level && ! self::is_debug_mode() ) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            self::table(),
            [
                'level'      => sanitize_key( $level ),
                'context'    => sanitize_key( $context ),
                'message'    => $message,
                'data'       => $data ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
                'created_at' => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public static function error( string $message, array $data = [], string $context = 'general' ): void {
        self::log( 'error', $message, $data, $context );
    }

    public static function warning( string $message, array $data = [], string $context = 'general' ): void {
        self::log( 'warning', $message, $data, $context );
    }

    public static function info( string $message, array $data = [], string $context = 'general' ): void {
        self::log( 'info', $message, $data, $context );
    }

    public static function debug( string $message, array $data = [], string $context = 'general' ): void {
        self::log( 'debug', $message, $data, $context );
    }

    /**
     * @param int    $limit   Max rows.
     * @param string $level   Filter by level or '' for all.
     * @param string $context Filter by context or '' for all.
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent( int $limit, string $level = '', string $context = '' ): array {
        global $wpdb;

        $table = self::table();
        $where = [];
        $args  = [];

        if ( $level !== '' ) {
            $where[] = 'level = %s';
            $args[]  = $level;
        }
        if ( $context !== '' ) {
            $where[] = 'context = %s';
            $args[]  = $context;
        }

        $sql = "SELECT id, level, context, message, data, created_at FROM {$table}";
        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $args[] = max( 1, min( 500, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders match args.
        $prepared = $wpdb->prepare( $sql, $args );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $prepared, ARRAY_A ) ?: [];
    }

    /**
     * @param string $level Optional level filter.
     */
    public static function count( string $level = '' ): int {
        global $wpdb;

        $table = self::table();
        if ( $level === '' ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE level = %s", $level ) );
    }

    /**
     * Delete every log row.
     */
    public static function clear_all(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( 'DELETE FROM ' . self::table() );
    }

    /**
     * Cron: remove rows older than retention option.
     */
    public static function purge_old(): void {
        global $wpdb;

        $days = (int) get_option( 'wbs_log_retention_days', 30 );
        $days = max( 1, min( 365, $days ) );

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff ) );
    }
}
