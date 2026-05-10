<?php
/**
 * System health monitoring and diagnostics.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

use WooBolSync\Services\Bol_API_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Health_Monitor class.
 */
class Health_Monitor {

    /**
     * Run comprehensive health check.
     *
     * @return array{ok:bool, checks:array<int, array<string, mixed>>, summary:string, score:int}
     */
    public static function run_full_health_check(): array {
        $checks = [];
        $api    = new Bol_API_Service();

        $checks[] = self::check_credentials( $api );
        $checks[] = self::check_api_connection( $api );
        $checks[] = self::check_economic_operator();
        $checks[] = self::check_webhook_subscription();
        $checks[] = self::check_field_mapping();
        $checks[] = self::check_recent_sync();
        $checks[] = self::check_error_rate();
        $checks[] = self::check_database_tables();

        $passed = count( array_filter( $checks, static fn( array $item ): bool => ! empty( $item['ok'] ) ) );
        $total  = count( $checks );
        $score  = $total > 0 ? (int) round( ( $passed / $total ) * 100 ) : 0;
        $ok     = $passed === $total;

        return [
            'ok'      => $ok,
            'checks'  => $checks,
            'summary' => self::generate_summary( $passed, $total, $score ),
            'score'   => $score,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function check_credentials( Bol_API_Service $api ): array {
        $ok = $api->has_credentials();
        return [
            'label'    => __( 'API Credentials', 'woo-bol-sync' ),
            'ok'       => $ok,
            'message'  => $ok 
                ? __( 'Client ID and Client Secret are configured.', 'woo-bol-sync' )
                : __( 'Missing Client ID or Client Secret. Configure in Settings → Connection.', 'woo-bol-sync' ),
            'category' => 'critical',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function check_api_connection( Bol_API_Service $api ): array {
        if ( ! $api->has_credentials() ) {
            return [
                'label'    => __( 'API Connection', 'woo-bol-sync' ),
                'ok'       => false,
                'message'  => __( 'Cannot test connection without credentials.', 'woo-bol-sync' ),
                'category' => 'critical',
            ];
        }

        $connected = $api->test_connection();
        return [
            'label'    => __( 'API Connection', 'woo-bol-sync' ),
            'ok'       => $connected,
            'message'  => $connected 
                ? __( 'Successfully connected to bol.com API.', 'woo-bol-sync' )
                : ( $api->get_last_error() ?: __( 'Connection test failed.', 'woo-bol-sync' ) ),
            'category' => 'critical',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function check_economic_operator(): array {
        $eo_id     = Mapping_Config::get_economic_operator_id();
        $eo_status = Mapping_Config::get_economic_operator_status();
        $ok        = $eo_id !== '' && $eo_status === 'VALID';

        if ( $eo_id === '' ) {
            $message = __( 'No economic operator configured. Click "Fetch economic operator" in Settings.', 'woo-bol-sync' );
        } elseif ( $eo_status !== 'VALID' ) {
            $message = sprintf(
                /* translators: %s: economic operator status */
                __( 'Economic operator status is "%s". Should be VALID.', 'woo-bol-sync' ),
                $eo_status
            );
        } else {
            $message = __( 'Economic operator is configured and valid.', 'woo-bol-sync' );
        }

        return [
            'label'    => __( 'Economic Operator', 'woo-bol-sync' ),
            'ok'       => $ok,
            'message'  => $message,
            'category' => 'critical',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function check_webhook_subscription(): array {
        $enabled = Mapping_Config::webhook_enabled();
        $sub_id  = Mapping_Config::get_subscription_id();
        $ok      = $enabled && $sub_id !== '';

        if ( ! $enabled ) {
            $message = __( 'Webhook automation is disabled. Enable in Settings → Webhooks.', 'woo-bol-sync' );
        } elseif ( $sub_id === '' ) {
            $message = __( 'Webhook enabled but no subscription ID stored. Wait for cron or trigger manually.', 'woo-bol-sync' );
        } else {
            $message = __( 'Webhook automation is active and configured.', 'woo-bol-sync' );
        }

        return [
            'label'    => __( 'Webhook Subscription', 'woo-bol-sync' ),
            'ok'       => $ok,
            'message'  => $message,
            'category' => 'important',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function check_field_mapping(): array {
        $map           = Mapping_Config::get_field_map();
        $ean_source    = $map['ean_source'] ?? '';
        $title_source  = $map['title_source'] ?? '';
        $desc_source   = $map['description_source'] ?? '';
        $ok            = $ean_source !== '' && $title_source !== '' && $desc_source !== '';

        return [
            'label'    => __( 'Field Mapping', 'woo-bol-sync' ),
            'ok'       => $ok,
            'message'  => $ok 
                ? __( 'Field mapping is configured (EAN, Title, Description).', 'woo-bol-sync' )
                : __( 'Field mapping incomplete. Configure in Field Mapping page.', 'woo-bol-sync' ),
            'category' => 'important',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function check_recent_sync(): array {
        global $wpdb;
        $table = $wpdb->prefix . WBS_LOG_TABLE;

        if ( ! self::table_exists( $table ) ) {
            return [
                'label'    => __( 'Recent Sync Activity', 'woo-bol-sync' ),
                'ok'       => false,
                'message'  => __( 'Log table not found. Plugin may need reactivation.', 'woo-bol-sync' ),
                'category' => 'warning',
            ];
        }

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE category = %s AND level IN ('info', 'debug') AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            'sync'
        );
        $count = (int) $wpdb->get_var( $sql );
        $ok    = $count > 0;

        return [
            'label'    => __( 'Recent Sync Activity', 'woo-bol-sync' ),
            'ok'       => $ok,
            'message'  => $ok 
                ? sprintf(
                    /* translators: %d: number of sync activities */
                    __( '%d sync activities in the last 24 hours.', 'woo-bol-sync' ),
                    $count
                )
                : __( 'No sync activity in the last 24 hours. Check sync schedule or run manual sync.', 'woo-bol-sync' ),
            'category' => 'warning',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function check_error_rate(): array {
        global $wpdb;
        $table = $wpdb->prefix . WBS_LOG_TABLE;

        if ( ! self::table_exists( $table ) ) {
            return [
                'label'    => __( 'Error Rate', 'woo-bol-sync' ),
                'ok'       => true,
                'message'  => __( 'Log table not available for analysis.', 'woo-bol-sync' ),
                'category' => 'warning',
            ];
        }

        $sql_total = "SELECT COUNT(*) FROM {$table} WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $sql_errors = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE level = %s AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            'error'
        );

        $total_logs  = (int) $wpdb->get_var( $sql_total );
        $error_logs  = (int) $wpdb->get_var( $sql_errors );
        $error_rate  = $total_logs > 0 ? ( $error_logs / $total_logs ) * 100 : 0;
        $ok          = $error_rate < 10;

        return [
            'label'    => __( 'Error Rate', 'woo-bol-sync' ),
            'ok'       => $ok,
            'message'  => sprintf(
                /* translators: 1: number of errors, 2: total logs, 3: error percentage */
                __( '%1$d errors out of %2$d total logs (%.1f%%) in the last 24 hours.', 'woo-bol-sync' ),
                $error_logs,
                $total_logs,
                $error_rate
            ),
            'category' => 'warning',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function check_database_tables(): array {
        $required_tables = [
            WBS_LOG_TABLE,
            WBS_PRODUCT_MAP,
            WBS_ORDER_MAP,
            WBS_CATEGORY_MAP,
        ];

        $missing = [];
        foreach ( $required_tables as $table_name ) {
            global $wpdb;
            $table = $wpdb->prefix . $table_name;
            if ( ! self::table_exists( $table ) ) {
                $missing[] = $table;
            }
        }

        $ok = empty( $missing );

        return [
            'label'    => __( 'Database Tables', 'woo-bol-sync' ),
            'ok'       => $ok,
            'message'  => $ok 
                ? __( 'All required database tables exist.', 'woo-bol-sync' )
                : sprintf(
                    /* translators: %s: comma-separated list of missing tables */
                    __( 'Missing tables: %s. Try deactivating and reactivating the plugin.', 'woo-bol-sync' ),
                    implode( ', ', $missing )
                ),
            'category' => 'critical',
        ];
    }

    /**
     * Check if a database table exists.
     */
    private static function table_exists( string $table_name ): bool {
        global $wpdb;
        $sql = $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name );
        return $wpdb->get_var( $sql ) === $table_name;
    }

    /**
     * Generate health summary.
     */
    private static function generate_summary( int $passed, int $total, int $score ): string {
        if ( $score >= 90 ) {
            return __( 'System health is excellent. All systems operational.', 'woo-bol-sync' );
        }
        if ( $score >= 70 ) {
            return __( 'System health is good. Minor issues detected.', 'woo-bol-sync' );
        }
        if ( $score >= 50 ) {
            return __( 'System health needs attention. Several issues detected.', 'woo-bol-sync' );
        }
        return __( 'System health is poor. Critical issues require immediate attention.', 'woo-bol-sync' );
    }

    /**
     * Get system statistics.
     *
     * @return array<string, mixed>
     */
    public static function get_system_stats(): array {
        global $wpdb;

        $stats = [
            'total_products_mapped' => 0,
            'total_orders_mapped'   => 0,
            'logs_last_24h'         => 0,
            'errors_last_24h'       => 0,
            'last_sync_time'        => '',
            'plugin_version'        => WBS_VERSION,
            'php_version'           => PHP_VERSION,
            'wp_version'            => get_bloginfo( 'version' ),
            'wc_version'            => defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A',
        ];

        $product_table = $wpdb->prefix . WBS_PRODUCT_MAP;
        if ( self::table_exists( $product_table ) ) {
            $stats['total_products_mapped'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$product_table}" );
        }

        $order_table = $wpdb->prefix . WBS_ORDER_MAP;
        if ( self::table_exists( $order_table ) ) {
            $stats['total_orders_mapped'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$order_table}" );
        }

        $log_table = $wpdb->prefix . WBS_LOG_TABLE;
        if ( self::table_exists( $log_table ) ) {
            $stats['logs_last_24h'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$log_table} WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $stats['errors_last_24h'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$log_table} WHERE level = %s AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                    'error'
                )
            );
            
            $last_sync = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT created_at FROM {$log_table} WHERE category = %s ORDER BY created_at DESC LIMIT 1",
                    'sync'
                )
            );
            if ( $last_sync ) {
                $stats['last_sync_time'] = $last_sync;
            }
        }

        return $stats;
    }
}
