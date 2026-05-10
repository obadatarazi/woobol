<?php
/**
 * Plugin deactivation: clear scheduled crons only (data retained).
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on register_deactivation_hook.
 */
final class Deactivator {

    /**
     * @return void
     */
    public static function deactivate(): void {
        foreach ( [ 'wbs_cron_sync_products', 'wbs_cron_sync_orders', 'wbs_cron_ensure_subscription', 'wbs_cron_purge_logs' ] as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
    }
}
