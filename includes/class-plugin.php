<?php
/**
 * Main plugin bootstrap: hooks, cron, admin.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

use WooBolSync\Admin\Admin_Menu;
use WooBolSync\Admin\Staging_Admin;
use WooBolSync\Includes\Activator;
use WooBolSync\Includes\Mapping_Config;
use WooBolSync\Includes\Sync_Hooks;
use WooBolSync\Includes\Webhook_Controller;
use WooBolSync\Services\Bol_API_Service;
use WooBolSync\Services\Draft_Builder_Service;
use WooBolSync\Services\Order_Sync_Service;
use WooBolSync\Services\Product_Sync_Service;
use WooBolSync\Services\Staging_Sync_Service;
use WooBolSync\Services\Subscription_Sync_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton.
 */
final class Plugin {

    private static ?self $instance = null;

    private bool $bootstrapped = false;

    private Bol_API_Service $api;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api = new Bol_API_Service();
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ], 0 );
        add_action( 'plugins_loaded', [ $this, 'bootstrap' ], 20 );
    }

    /**
     * Load translations from the bundled /languages directory.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain( 'woo-bol-sync', false, dirname( WBS_PLUGIN_BASE ) . '/languages' );
    }

    /**
     * @return Bol_API_Service
     */
    public function api(): Bol_API_Service {
        return $this->api;
    }

    /**
     * Wire WordPress hooks after WooCommerce (and other plugins) are available.
     */
    public function bootstrap(): void {
        if ( $this->bootstrapped ) {
            return;
        }
        $this->bootstrapped = true;

        Activator::maybe_upgrade();
        Mapping_Config::maybe_migrate_sync_granular_options();

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_woocommerce_required' ] );
            return;
        }

        Sync_Scheduler::register_hooks();
        Sync_Scheduler::bootstrap_migration();

        Sync_Hooks::register( $this->api );
        add_action( 'rest_api_init', [ new Webhook_Controller( $this->api ), 'register_routes' ] );

        $admin  = new Admin_Menu( $this->api );
        $loader = new Hook_Loader();

        $loader->add_action( 'admin_menu', $admin, 'register_menu' );
        $loader->add_action( 'admin_init', $admin, 'register_settings' );
        $loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_assets' );
        $admin->register_ajax_hooks( $loader );

        $staging_admin = new Staging_Admin( $this->api );
        $staging_admin->register( $loader );

        $loader->run();

        add_action( 'wbs_cron_sync_products', [ $this, 'cron_sync_products' ] );
        add_action( 'wbs_cron_sync_orders', [ $this, 'cron_sync_orders' ] );
        add_action( 'wbs_cron_ensure_subscription', [ $this, 'cron_ensure_subscription' ] );
        add_action( 'wbs_cron_purge_logs', [ Logger::class, 'purge_old' ] );
    }

    /**
     * @return void
     */
    public function notice_woocommerce_required(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__( 'WooBolSync requires WooCommerce to be installed and active.', 'woo-bol-sync' )
        );
    }

    /**
     * @return void
     */
    public function cron_sync_products(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        if ( ! Mapping_Config::product_sync_enabled() ) {
            return;
        }
        try {
            if ( Mapping_Config::staging_mode_enabled() ) {
                if ( Mapping_Config::staging_auto_ingest_enabled() ) {
                    ( new Draft_Builder_Service() )->ingest();
                }
                ( new Staging_Sync_Service( $this->api ) )->sync_approved(
                    max( 1, (int) get_option( 'wbs_sync_batch_size', 25 ) )
                );
            } else {
                $service = new Product_Sync_Service( $this->api );
                $service->sync_all();
            }
        } finally {
            Sync_Scheduler::chain_next_product_run();
        }
    }

    /**
     * @return void
     */
    public function cron_sync_orders(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        if ( ! Sync_Scheduler::can_run_order_sync() ) {
            return;
        }
        $service = new Order_Sync_Service( $this->api );
        $service->sync_orders();
    }

    /**
     * @return void
     */
    public function cron_ensure_subscription(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        $service = new Subscription_Sync_Service( $this->api );
        $service->ensure_process_status_subscription();
    }
}
