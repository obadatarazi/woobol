<?php
/**
 * Plugin Name:       WooBolSync
 * Plugin URI:        https://cupcoding.com/woo-bol-sync
 * Description:       Production-grade WooCommerce ↔ bol.com integration: product sync, order sync, stock and order-status sync, DB logging, signed webhooks, and rate-limit handling.
 * Version:           3.0.26
 * Author:            Obada Al-Tarazi
 * Author URI:        https://cupcoding.com
 * License:           Proprietary
 * Text Domain:       woo-bol-sync
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * WC requires at least: 8.0
 * WC tested up to:      9.4
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;

// Avoid fatal collisions when another installed copy of the plugin is already loaded.
if ( defined( 'WBS_PLUGIN_FILE' ) || function_exists( 'woo_bol_sync' ) || class_exists( \WooBolSync\Includes\Plugin::class, false ) ) {
    return;
}

// ── Constants ──────────────────────────────────────────────────────────────────

define( 'WBS_VERSION',      '3.0.26' );
define( 'WBS_MIN_PHP',      '8.2' );
define( 'WBS_MIN_WP',       '6.2' );
define( 'WBS_MIN_WC',       '8.0' );
define( 'WBS_PLUGIN_FILE',  __FILE__ );
define( 'WBS_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'WBS_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'WBS_PLUGIN_BASE',  plugin_basename( __FILE__ ) );

add_action(
    'before_woocommerce_init',
    static function (): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WBS_PLUGIN_FILE, true );
        }
    }
);

define( 'WBS_LOG_TABLE',    'wbs_logs' );
define( 'WBS_PRODUCT_MAP',  'wbs_product_mapping' );
define( 'WBS_ORDER_MAP',    'wbs_order_mapping' );
define( 'WBS_CATEGORY_MAP', 'wbs_category_mapping' );

// Staging (review-before-sync) tables.
define( 'WBS_STAGING_BATCHES',       'wbs_sync_batches' );
define( 'WBS_STAGING_PRODUCT_DRAFT', 'wbs_product_sync_draft' );
define( 'WBS_STAGING_VARIATION_DRAFT', 'wbs_variation_sync_draft' );
define( 'WBS_STAGING_JOB',           'wbs_sync_job' );
define( 'WBS_STAGING_JOB_ITEM',      'wbs_sync_job_item' );
define( 'WBS_STAGING_AUDIT',         'wbs_sync_audit' );

// ── PHP version guard ─────────────────────────────────────────────────────────

if ( version_compare( PHP_VERSION, WBS_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', static function () {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: 1: required PHP version  2: current PHP version */
                    __( 'WooBolSync requires PHP %1$s or higher. You are running PHP %2$s.', 'woo-bol-sync' ),
                    WBS_MIN_PHP,
                    PHP_VERSION
                )
            )
        );
    } );
    return;
}

// ── Autoloader ────────────────────────────────────────────────────────────────

spl_autoload_register( static function ( string $class_name ): void {
    $prefix = 'WooBolSync\\';
    $len    = strlen( $prefix );

    if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class_name, $len );
    $parts          = explode( '\\', $relative_class );
    $class_file     = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';

    $dir_map = [
        'Admin'    => WBS_PLUGIN_DIR . 'admin/',
        'Services' => WBS_PLUGIN_DIR . 'services/',
        'Models'   => WBS_PLUGIN_DIR . 'models/',
        'Includes' => WBS_PLUGIN_DIR . 'includes/',
    ];

    $base_dir = WBS_PLUGIN_DIR . 'includes/';

    if ( ! empty( $parts ) && isset( $dir_map[ $parts[0] ] ) ) {
        $base_dir = $dir_map[ $parts[0] ];
    }

    $file = $base_dir . $class_file;

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Load the core bootstrap classes explicitly as a fallback for environments
// where the autoloader is not yet resolving namespaced classes reliably.
$wbs_core_files = [
    WBS_PLUGIN_DIR . 'services/class-bol-api-service.php',
    WBS_PLUGIN_DIR . 'includes/class-plugin.php',
];

foreach ( $wbs_core_files as $wbs_core_file ) {
    if ( file_exists( $wbs_core_file ) ) {
        require_once $wbs_core_file;
    }
}

// ── Activation / Deactivation / Uninstall ────────────────────────────────────

register_activation_hook( WBS_PLUGIN_FILE,   [ 'WooBolSync\\Includes\\Activator',   'activate'   ] );
register_deactivation_hook( WBS_PLUGIN_FILE, [ 'WooBolSync\\Includes\\Deactivator', 'deactivate' ] );

// ── Bootstrap ─────────────────────────────────────────────────────────────────

/**
 * Returns the singleton plugin instance.
 *
 * @return WooBolSync\Includes\Plugin
 */
if ( ! function_exists( 'woo_bol_sync' ) ) {
    function woo_bol_sync(): \WooBolSync\Includes\Plugin {
        return \WooBolSync\Includes\Plugin::instance();
    }
}

if ( ! has_action( 'plugins_loaded', 'woo_bol_sync' ) ) {
    add_action( 'plugins_loaded', 'woo_bol_sync', 1 );
}
