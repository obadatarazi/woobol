<?php
/**
 * Admin Menu.
 *
 * Registers the "Bol Sync" admin area with four pages:
 *   1. Dashboard  — sync stats, connection status, manual sync buttons
 *   2. Settings   — credentials, smart-sync, rate limit, batch size, debug, log retention
 *   3. Logs       — DB log viewer with level filter and Clear button
 *   4. License    — license key entry and validation status
 *
 * All AJAX handlers are registered here.
 *
 * @package WooBolSync\Admin
 */

namespace WooBolSync\Admin;

defined( 'ABSPATH' ) || exit;

use WooBolSync\Includes\Ajax_Runtime;
use WooBolSync\Includes\Hook_Loader;
use WooBolSync\Includes\License_Manager;
use WooBolSync\Includes\Logger;
use WooBolSync\Includes\Mapping_Config;
use WooBolSync\Includes\Sync_Scheduler;
use WooBolSync\Models\Category_Map;
use WooBolSync\Models\Product_Mapping;
use WooBolSync\Services\Bol_API_Service;
use WooBolSync\Services\Order_Sync_Service;
use WooBolSync\Services\Product_Sync_Service;
use WooBolSync\Services\Subscription_Sync_Service;

/**
 * Class Admin_Menu
 */
class Admin_Menu {

    /** @var Bol_API_Service */
    protected Bol_API_Service $api;

    private const SETTINGS_GROUP    = 'wbs_settings_group';
    private const SETTINGS_FIELDMAP = 'wbs_fieldmap_group';
    private const SLUG_DASH         = 'wbs-dashboard';
    private const SLUG_SETT         = 'wbs-settings';
    private const SLUG_LOGS         = 'wbs-logs';
    private const SLUG_LIC          = 'wbs-license';
    private const SLUG_CAT          = 'wbs-categories';
    private const SLUG_FIELD        = 'wbs-field-map';
    private const SLUG_BOL_PRODUCTS = 'wbs-bol-products';
    private const SLUG_BOL_ORDERS   = 'wbs-bol-orders';

    /**
     * Options that trigger recalculation of WP-Cron single events.
     *
     * @var string[]
     */
    private const SCHEDULE_OPTION_KEYS = [
        Mapping_Config::OPTION_PRODUCT_SYNC_MODE,
        Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED,
        Mapping_Config::OPTION_ALLOW_NEW_OFFERS,
        Mapping_Config::OPTION_SYNC_OFFER_PRICE,
        Mapping_Config::OPTION_SYNC_OFFER_STOCK,
        Mapping_Config::OPTION_SYNC_CONTENT_NAME,
        Mapping_Config::OPTION_SYNC_CONTENT_DESCRIPTION,
        Mapping_Config::OPTION_SYNC_CONTENT_IMAGES,
        Mapping_Config::OPTION_PRODUCT_SYNC_TIME,
        Mapping_Config::OPTION_PRODUCT_SYNC_WEEKDAY,
        Mapping_Config::OPTION_PRODUCT_SYNC_MONTHDAY,
        Mapping_Config::OPTION_ORDER_SYNC_MODE,
        Mapping_Config::OPTION_ORDER_SYNC_TIME,
        Mapping_Config::OPTION_ORDER_SYNC_WEEKDAY,
        Mapping_Config::OPTION_ORDER_SYNC_MONTHDAY,
    ];

    /**
     * Constructor.
     *
     * @param Bol_API_Service $api
     */
    public function __construct( Bol_API_Service $api ) {
        $this->api = $api;
        add_action( 'admin_post_wbs_save_category_map', [ $this, 'save_category_map' ] );
        add_action( 'update_option_wbs_client_id', [ $this, 'on_credentials_option_changed' ], 10, 3 );
        add_action( 'update_option_wbs_client_secret', [ $this, 'on_credentials_option_changed' ], 10, 3 );
        add_action( 'updated_option', [ $this, 'maybe_reschedule_sync_crons' ], 10, 3 );
    }

    /**
     * Re-queue product/order single events when schedule options change.
     *
     * @param mixed $old_value Previous option value.
     * @param mixed $value     New option value.
     */
    public function maybe_reschedule_sync_crons( string $option, $old_value, $value ): void {
        if ( ! in_array( $option, self::SCHEDULE_OPTION_KEYS, true ) ) {
            return;
        }
        Sync_Scheduler::apply_product_schedule();
        Sync_Scheduler::apply_order_schedule();
    }

    /**
     * Clear cached EO and re-fetch when API credentials change.
     *
     * @param mixed  $old_value Previous option value.
     * @param mixed  $value     New option value.
     * @param string $option    Option name.
     */
    public function on_credentials_option_changed( $old_value, $value, string $option ): void {
        if ( (string) $old_value === (string) $value ) {
            return;
        }
        delete_transient( 'wbs_bol_access_token' );
        Mapping_Config::clear_economic_operator_options();
        Mapping_Config::clear_connection_status();
        if ( $this->api->has_credentials() ) {
            $this->api->fetch_and_store_economic_operator();
        }
    }

    // ── Menu registration ─────────────────────────────────────────────────────

    /**
     * Register top-level menu and sub-pages.
     *
     * @return void
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'Bol Sync', 'woo-bol-sync' ),
            __( 'Bol Sync', 'woo-bol-sync' ),
            'manage_woocommerce',
            self::SLUG_DASH,
            [ $this, 'render_dashboard' ],
            'dashicons-store',
            56
        );

        add_submenu_page( self::SLUG_DASH, __( 'Dashboard', 'woo-bol-sync' ),   __( 'Dashboard', 'woo-bol-sync' ), 'manage_woocommerce', self::SLUG_DASH, [ $this, 'render_dashboard' ] );
        add_submenu_page( self::SLUG_DASH, __( 'Settings', 'woo-bol-sync' ),    __( 'Settings', 'woo-bol-sync' ),  'manage_woocommerce', self::SLUG_SETT, [ $this, 'render_settings' ] );
        add_submenu_page( self::SLUG_DASH, __( 'Category mapping', 'woo-bol-sync' ), __( 'Category mapping', 'woo-bol-sync' ), 'manage_woocommerce', self::SLUG_CAT, [ $this, 'render_category_mapping' ] );
        add_submenu_page( self::SLUG_DASH, __( 'Field mapping', 'woo-bol-sync' ), __( 'Field mapping', 'woo-bol-sync' ), 'manage_woocommerce', self::SLUG_FIELD, [ $this, 'render_field_mapping' ] );
        add_submenu_page( self::SLUG_DASH, __( 'bol Products', 'woo-bol-sync' ), __( 'bol Products', 'woo-bol-sync' ), 'manage_woocommerce', self::SLUG_BOL_PRODUCTS, [ $this, 'render_bol_products' ] );
        add_submenu_page( self::SLUG_DASH, __( 'bol Orders', 'woo-bol-sync' ), __( 'bol Orders', 'woo-bol-sync' ), 'manage_woocommerce', self::SLUG_BOL_ORDERS, [ $this, 'render_bol_orders' ] );
        add_submenu_page( self::SLUG_DASH, __( 'Logs', 'woo-bol-sync' ),        __( 'Logs', 'woo-bol-sync' ),      'manage_woocommerce', self::SLUG_LOGS, [ $this, 'render_logs' ] );
        add_submenu_page( self::SLUG_DASH, __( 'License', 'woo-bol-sync' ),     __( 'License', 'woo-bol-sync' ),   'manage_woocommerce', self::SLUG_LIC,  [ $this, 'render_license' ] );
    }

    // ── Settings API ──────────────────────────────────────────────────────────

    /**
     * Register all settings fields and sections.
     *
     * @return void
     */
    public function register_settings(): void {

        // ── API Credentials ───────────────────────────────────────────────────
        register_setting( self::SETTINGS_GROUP, 'wbs_client_id',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::SETTINGS_GROUP, 'wbs_client_secret', [ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_secret' ] ] );

        // ── Sync behaviour ────────────────────────────────────────────────────
        register_setting( self::SETTINGS_GROUP, 'wbs_smart_sync',      [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
        register_setting( self::SETTINGS_GROUP, 'wbs_sync_batch_size', [ 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_batch_size' ] ] );
        register_setting( self::SETTINGS_GROUP, 'wbs_rate_limit_delay',[ 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_rate_delay' ] ] );
        register_setting( self::SETTINGS_GROUP, 'wbs_api_retry_count', [ 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_retry_count' ] ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_OFFER_MEDIA_TYPE, [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_offer_media_type' ],
            'default'           => 'application/vnd.retailer.v10+json',
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_AUTO_RECOVER_STALE_OFFERS, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_WEBHOOK_ENABLED, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_WEBHOOK_SHARED_SECRET, [ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_secret' ] ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_MARGIN_TYPE, [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_margin_type' ],
            'default'           => 'none',
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_MARGIN_VALUE, [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_margin_value' ],
            'default'           => '0',
        ] );

        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_PRODUCT_SYNC_MODE, [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_product_sync_mode' ],
            'default'           => Mapping_Config::SYNC_MODE_DAILY,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_ALLOW_NEW_OFFERS, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_SYNC_OFFER_PRICE, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_SYNC_OFFER_STOCK, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_SYNC_CONTENT_NAME, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_SYNC_CONTENT_DESCRIPTION, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_SYNC_CONTENT_IMAGES, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_PRODUCT_SYNC_TIME, [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_product_sync_time' ],
            'default'           => '02:00',
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_PRODUCT_SYNC_WEEKDAY, [
            'type'              => 'integer',
            'sanitize_callback' => [ $this, 'sanitize_weekday' ],
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_PRODUCT_SYNC_MONTHDAY, [
            'type'              => 'integer',
            'sanitize_callback' => [ $this, 'sanitize_monthday' ],
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_ORDER_SYNC_MODE, [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_order_sync_mode' ],
            'default'           => Mapping_Config::SYNC_MODE_DAILY,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_ORDER_SYNC_TIME, [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_order_sync_time' ],
            'default'           => '02:15',
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_ORDER_SYNC_WEEKDAY, [
            'type'              => 'integer',
            'sanitize_callback' => [ $this, 'sanitize_weekday' ],
            'default'           => 1,
        ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_ORDER_SYNC_MONTHDAY, [
            'type'              => 'integer',
            'sanitize_callback' => [ $this, 'sanitize_monthday' ],
            'default'           => 1,
        ] );

        // ── Developer ─────────────────────────────────────────────────────────
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_STAGING_ENABLED,     [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
        register_setting( self::SETTINGS_GROUP, Mapping_Config::OPTION_STAGING_AUTO_INGEST, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );

        register_setting( self::SETTINGS_GROUP, 'wbs_debug_mode',         [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
        register_setting( self::SETTINGS_GROUP, 'wbs_log_retention_days', [ 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_retention' ] ] );

        // ── Sections ──────────────────────────────────────────────────────────
        add_settings_section( 'wbs_sec_credentials', __( 'API Credentials', 'woo-bol-sync' ),   [ $this, 'sec_credentials_desc' ], self::SLUG_SETT );
        add_settings_section( 'wbs_sec_sync',        __( 'Sync Rules', 'woo-bol-sync' ),        [ $this, 'sec_sync_desc' ],        self::SLUG_SETT );
        add_settings_section( 'wbs_sec_product_sched', __( 'Product batch sync schedule', 'woo-bol-sync' ), [ $this, 'sec_product_sched_desc' ], self::SLUG_SETT );
        add_settings_section( 'wbs_sec_order_sched', __( 'Order import schedule', 'woo-bol-sync' ), [ $this, 'sec_order_sched_desc' ], self::SLUG_SETT );
        add_settings_section( 'wbs_sec_webhooks',    __( 'Webhooks', 'woo-bol-sync' ),          [ $this, 'sec_webhooks_desc' ],    self::SLUG_SETT );
        add_settings_section( 'wbs_sec_dev',         __( 'Advanced & Logs', 'woo-bol-sync' ),   [ $this, 'sec_dev_desc' ],         self::SLUG_SETT );

        // ── Fields: credentials ───────────────────────────────────────────────
        add_settings_field( 'wbs_f_client_id',     __( 'Client ID', 'woo-bol-sync' ),     [ $this, 'field_client_id' ],     self::SLUG_SETT, 'wbs_sec_credentials', [ 'label_for' => 'wbs_client_id' ] );
        add_settings_field( 'wbs_f_client_secret', __( 'Client Secret', 'woo-bol-sync' ), [ $this, 'field_client_secret' ], self::SLUG_SETT, 'wbs_sec_credentials', [ 'label_for' => 'wbs_client_secret' ] );

        // ── Fields: sync behaviour ────────────────────────────────────────────
        add_settings_field( 'wbs_f_smart_sync',      __( 'Smart Sync', 'woo-bol-sync' ),         [ $this, 'field_smart_sync' ],      self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => 'wbs_smart_sync' ] );
        add_settings_field( 'wbs_f_batch_size',      __( 'Batch Size', 'woo-bol-sync' ),          [ $this, 'field_batch_size' ],      self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => 'wbs_sync_batch_size' ] );
        add_settings_field( 'wbs_f_rate_limit_delay',__( 'Rate Limit Delay (ms)', 'woo-bol-sync' ),[ $this, 'field_rate_limit' ],    self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => 'wbs_rate_limit_delay' ] );
        add_settings_field( 'wbs_f_retry_count', __( 'API retries', 'woo-bol-sync' ), [ $this, 'field_retry_count' ], self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => 'wbs_api_retry_count' ] );
        add_settings_field( 'wbs_f_offer_media_type', __( 'Offer API media type', 'woo-bol-sync' ), [ $this, 'field_offer_media_type' ], self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => Mapping_Config::OPTION_OFFER_MEDIA_TYPE ] );
        add_settings_field( 'wbs_f_auto_recover_stale_offers', __( 'Stale offer recovery', 'woo-bol-sync' ), [ $this, 'field_auto_recover_stale_offers' ], self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => Mapping_Config::OPTION_AUTO_RECOVER_STALE_OFFERS ] );
        add_settings_field( 'wbs_f_webhook_enabled', __( 'Webhook automation', 'woo-bol-sync' ), [ $this, 'field_webhook_enabled' ], self::SLUG_SETT, 'wbs_sec_webhooks', [ 'label_for' => Mapping_Config::OPTION_WEBHOOK_ENABLED ] );
        add_settings_field( 'wbs_f_webhook_signing_required', __( 'Webhook authentication', 'woo-bol-sync' ), [ $this, 'field_webhook_signing_required' ], self::SLUG_SETT, 'wbs_sec_webhooks', [ 'label_for' => Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED ] );
        add_settings_field( 'wbs_f_webhook_shared_secret', __( 'Webhook shared secret', 'woo-bol-sync' ), [ $this, 'field_webhook_shared_secret' ], self::SLUG_SETT, 'wbs_sec_webhooks', [ 'label_for' => Mapping_Config::OPTION_WEBHOOK_SHARED_SECRET ] );
        add_settings_field( 'wbs_f_margin_type', __( 'bol.com price margin', 'woo-bol-sync' ), [ $this, 'field_margin_type' ], self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => 'wbs_price_margin_type' ] );
        add_settings_field( 'wbs_f_margin_val', __( 'Margin value', 'woo-bol-sync' ), [ $this, 'field_margin_value' ], self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => 'wbs_price_margin_value' ] );

        add_settings_field( 'wbs_f_product_sync_mode', __( 'Product batch mode', 'woo-bol-sync' ), [ $this, 'field_product_sync_mode' ], self::SLUG_SETT, 'wbs_sec_product_sched', [ 'label_for' => Mapping_Config::OPTION_PRODUCT_SYNC_MODE ] );
        add_settings_field( 'wbs_f_product_sync_enabled', __( 'Enable product sync', 'woo-bol-sync' ), [ $this, 'field_product_sync_enabled' ], self::SLUG_SETT, 'wbs_sec_product_sched', [ 'label_for' => Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED ] );
        add_settings_field( 'wbs_f_allow_new_offers', __( 'Create new offers on bol.com', 'woo-bol-sync' ), [ $this, 'field_allow_new_offers' ], self::SLUG_SETT, 'wbs_sec_product_sched', [ 'label_for' => Mapping_Config::OPTION_ALLOW_NEW_OFFERS ] );
        add_settings_field( 'wbs_f_sync_product_fields', __( 'Fields to sync to bol.com', 'woo-bol-sync' ), [ $this, 'field_sync_product_fields' ], self::SLUG_SETT, 'wbs_sec_product_sched' );
        add_settings_field( 'wbs_f_product_sync_time', __( 'Run at (site time)', 'woo-bol-sync' ), [ $this, 'field_product_sync_time' ], self::SLUG_SETT, 'wbs_sec_product_sched', [ 'label_for' => Mapping_Config::OPTION_PRODUCT_SYNC_TIME ] );
        add_settings_field( 'wbs_f_product_sync_weekday', __( 'Day of week', 'woo-bol-sync' ), [ $this, 'field_product_sync_weekday' ], self::SLUG_SETT, 'wbs_sec_product_sched', [ 'label_for' => Mapping_Config::OPTION_PRODUCT_SYNC_WEEKDAY ] );
        add_settings_field( 'wbs_f_product_sync_monthday', __( 'Day of month', 'woo-bol-sync' ), [ $this, 'field_product_sync_monthday' ], self::SLUG_SETT, 'wbs_sec_product_sched', [ 'label_for' => Mapping_Config::OPTION_PRODUCT_SYNC_MONTHDAY ] );

        add_settings_field( 'wbs_f_order_sync_mode', __( 'Order import mode', 'woo-bol-sync' ), [ $this, 'field_order_sync_mode' ], self::SLUG_SETT, 'wbs_sec_order_sched', [ 'label_for' => Mapping_Config::OPTION_ORDER_SYNC_MODE ] );
        add_settings_field( 'wbs_f_order_sync_time', __( 'Run at (site time)', 'woo-bol-sync' ), [ $this, 'field_order_sync_time' ], self::SLUG_SETT, 'wbs_sec_order_sched', [ 'label_for' => Mapping_Config::OPTION_ORDER_SYNC_TIME ] );
        add_settings_field( 'wbs_f_order_sync_weekday', __( 'Day of week', 'woo-bol-sync' ), [ $this, 'field_order_sync_weekday' ], self::SLUG_SETT, 'wbs_sec_order_sched', [ 'label_for' => Mapping_Config::OPTION_ORDER_SYNC_WEEKDAY ] );
        add_settings_field( 'wbs_f_order_sync_monthday', __( 'Day of month', 'woo-bol-sync' ), [ $this, 'field_order_sync_monthday' ], self::SLUG_SETT, 'wbs_sec_order_sched', [ 'label_for' => Mapping_Config::OPTION_ORDER_SYNC_MONTHDAY ] );

        // ── Fields: developer ─────────────────────────────────────────────────
        add_settings_field( 'wbs_f_staging_mode',        __( 'Staging & Review mode', 'woo-bol-sync' ),        [ $this, 'field_staging_mode' ],        self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => Mapping_Config::OPTION_STAGING_ENABLED ] );
        add_settings_field( 'wbs_f_staging_auto_ingest', __( 'Staging auto-ingest on cron', 'woo-bol-sync' ), [ $this, 'field_staging_auto_ingest' ], self::SLUG_SETT, 'wbs_sec_sync', [ 'label_for' => Mapping_Config::OPTION_STAGING_AUTO_INGEST ] );

        add_settings_field( 'wbs_f_debug_mode',    __( 'Debug Mode', 'woo-bol-sync' ),         [ $this, 'field_debug_mode' ],    self::SLUG_SETT, 'wbs_sec_dev', [ 'label_for' => 'wbs_debug_mode' ] );
        add_settings_field( 'wbs_f_log_retention', __( 'Log Retention (days)', 'woo-bol-sync' ),[ $this, 'field_log_retention' ], self::SLUG_SETT, 'wbs_sec_dev', [ 'label_for' => 'wbs_log_retention_days' ] );

        // Refresh debug-mode cache when settings are saved.
        add_action( 'update_option_wbs_debug_mode', static function () {
            Logger::refresh_debug_mode();
        } );

        // ── Field mapping & bol listing defaults ─────────────────────────────
        register_setting(
            self::SETTINGS_FIELDMAP,
            Mapping_Config::OPTION_FIELD_MAP,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_field_map_option' ],
                'default'           => Mapping_Config::default_field_map(),
            ]
        );
        register_setting(
            self::SETTINGS_FIELDMAP,
            Mapping_Config::OPTION_DELIVERY,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => Mapping_Config::DEFAULT_V10_DELIVERY_CODE,
            ]
        );
        register_setting(
            self::SETTINGS_FIELDMAP,
            Mapping_Config::OPTION_FULFILMENT,
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_fulfilment_method' ],
                'default'           => 'FBR',
            ]
        );
        register_setting(
            self::SETTINGS_FIELDMAP,
            Mapping_Config::OPTION_SYNC_PUBLISH,
            [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 1,
            ]
        );
        register_setting(
            self::SETTINGS_FIELDMAP,
            Mapping_Config::OPTION_EXCLUDE_CATS,
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_exclude_categories' ],
                'default'           => '',
            ]
        );
        register_setting(
            self::SETTINGS_FIELDMAP,
            Mapping_Config::OPTION_DEFAULT_BRAND,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        add_settings_section( 'wbs_sec_listing', __( 'Listing defaults', 'woo-bol-sync' ), [ $this, 'sec_listing_desc' ], self::SLUG_FIELD );
        add_settings_field( 'wbs_f_fulfilment', __( 'Default fulfilment method', 'woo-bol-sync' ), [ $this, 'field_default_fulfilment' ], self::SLUG_FIELD, 'wbs_sec_listing', [ 'label_for' => 'wbs_default_fulfilment_method' ] );
        add_settings_field( 'wbs_f_delivery', __( 'Default delivery code', 'woo-bol-sync' ), [ $this, 'field_default_delivery' ], self::SLUG_FIELD, 'wbs_sec_listing', [ 'label_for' => 'wbs_default_delivery_code' ] );
        add_settings_field( 'wbs_f_default_brand', __( 'Default brand', 'woo-bol-sync' ), [ $this, 'field_default_brand' ], self::SLUG_FIELD, 'wbs_sec_listing', [ 'label_for' => Mapping_Config::OPTION_DEFAULT_BRAND ] );
        add_settings_field( 'wbs_f_sync_pub', __( 'Product scope', 'woo-bol-sync' ), [ $this, 'field_sync_publish_only' ], self::SLUG_FIELD, 'wbs_sec_listing', [ 'label_for' => 'wbs_sync_only_published' ] );
        add_settings_field( 'wbs_f_exclude', __( 'Exclude WC categories', 'woo-bol-sync' ), [ $this, 'field_exclude_categories' ], self::SLUG_FIELD, 'wbs_sec_listing' );
        add_settings_section( 'wbs_sec_fields', __( 'WooCommerce → bol.com fields', 'woo-bol-sync' ), [ $this, 'sec_fields_desc' ], self::SLUG_FIELD );
        add_settings_field( 'wbs_f_fieldmap', __( 'Field sources', 'woo-bol-sync' ), [ $this, 'field_map_sources' ], self::SLUG_FIELD, 'wbs_sec_fields' );
    }

    // ── Asset enqueueing ──────────────────────────────────────────────────────

    /**
     * Enqueue CSS + JS only on our own admin pages.
     *
     * @param string $hook Current admin page hook.
     *
     * @return void
     */
    public function enqueue_assets( string $hook ): void {
        $our_pages = [
            'toplevel_page_' . self::SLUG_DASH,
            'bol-sync_page_' . self::SLUG_SETT,
            'bol-sync_page_' . self::SLUG_CAT,
            'bol-sync_page_' . self::SLUG_FIELD,
            'bol-sync_page_' . self::SLUG_BOL_PRODUCTS,
            'bol-sync_page_' . self::SLUG_BOL_ORDERS,
            'bol-sync_page_' . self::SLUG_LOGS,
            'bol-sync_page_' . self::SLUG_LIC,
        ];

        if ( ! in_array( $hook, $our_pages, true ) ) {
            return;
        }

        wp_enqueue_style( 'wbs-admin', WBS_PLUGIN_URL . 'assets/css/admin.css', [], WBS_VERSION );

        wp_enqueue_script( 'wbs-admin', WBS_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], WBS_VERSION, true );

        wp_localize_script( 'wbs-admin', 'wbsAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wbs_ajax_nonce' ),
            'i18n'    => [
                'syncing'       => __( 'Syncing…', 'woo-bol-sync' ),
                'testing'       => __( 'Testing…', 'woo-bol-sync' ),
                'validating'    => __( 'Validating…', 'woo-bol-sync' ),
                'clearing'      => __( 'Clearing…', 'woo-bol-sync' ),
                'fetchingEo'    => __( 'Fetching…', 'woo-bol-sync' ),
                'fetchEo'       => __( 'Fetch economic operator', 'woo-bol-sync' ),
                'syncProducts'  => __( 'Sync Products', 'woo-bol-sync' ),
                'syncSelectedProduct' => __( 'Sync selected product', 'woo-bol-sync' ),
                'syncOrders'    => __( 'Sync Orders', 'woo-bol-sync' ),
                'testConn'      => __( 'Test Connection', 'woo-bol-sync' ),
                'healthCheck'   => __( 'Run health check', 'woo-bol-sync' ),
                'retryProducts' => __( 'Retry failed products', 'woo-bol-sync' ),
                'forceUpdate'   => __( 'Force updating…', 'woo-bol-sync' ),
                'retryOrders'   => __( 'Retry failed orders', 'woo-bol-sync' ),
                'ensureWebhook' => __( 'Ensure webhook', 'woo-bol-sync' ),
                'validateLic'   => __( 'Validate License', 'woo-bol-sync' ),
                'clearLogs'     => __( 'Clear All Logs', 'woo-bol-sync' ),
                'error'         => __( 'An error occurred. Please try again.', 'woo-bol-sync' ),
                'confirmClear'  => __( 'Are you sure you want to delete all log entries? This cannot be undone.', 'woo-bol-sync' ),
                'confirmForceUpdate' => __( 'Force update all existing bol-linked products now? This bypasses Smart Sync and may take time.', 'woo-bol-sync' ),
                'selectProductFirst' => __( 'Select a product or variation first.', 'woo-bol-sync' ),
                'searchProducts' => __( 'Search products…', 'woo-bol-sync' ),
                'searchingProducts' => __( 'Searching products…', 'woo-bol-sync' ),
                'searchProductsHint' => __( 'Search by product name, SKU, EAN, or WooCommerce ID.', 'woo-bol-sync' ),
                'searchProductsMin' => __( 'Type at least 2 characters to search.', 'woo-bol-sync' ),
                'searchProductsEmpty' => __( 'No matching products or variations found.', 'woo-bol-sync' ),
                'clearSelectedProduct' => __( 'Clear selected product', 'woo-bol-sync' ),
                /* translators: %s: UTC datetime */
                'lastFetched'   => __( 'Last fetched: %s', 'woo-bol-sync' ),
                'catalogLookup'   => __( 'Look up', 'woo-bol-sync' ),
                'catalogLookupLoading' => __( 'Looking up…', 'woo-bol-sync' ),
                'catalogLookupNeedEan' => __( 'Enter an EAN or GTIN (8–14 digits).', 'woo-bol-sync' ),
                'catalogLookupNeedCreds' => __( 'Save API credentials in Settings first.', 'woo-bol-sync' ),
                'chunkRecommendLoading' => __( 'Fetching recommendations…', 'woo-bol-sync' ),
                'chunkRecommendNeedName' => __( 'Enter a product name.', 'woo-bol-sync' ),
                'chunkRecommendGpcLabel' => __( 'Catalog GPC chunkId (if present):', 'woo-bol-sync' ),
                'catalog404Hint'       => __( 'This EAN is not in bol.com’s catalog yet. That is normal for new or exclusive products. Use “Chunk recommendations” below with your product title, or get classification details from bol partner support.', 'woo-bol-sync' ),
                'chunkSummaryProduct'  => __( 'Product queried:', 'woo-bol-sync' ),
                /* translators: 1: chunkId string 2: confidence percent */
                'chunkSummaryTop'      => __( 'Top match — chunkId: %1$s · confidence ~%2$s%%', 'woo-bol-sync' ),
                'chunkSummaryOthers'   => __( 'Lower confidence:', 'woo-bol-sync' ),
                'chunkSummaryNoNames'  => __( 'bol does not return chunk titles in this API. Look up the chunkId in the bol data model.', 'woo-bol-sync' ),
                'chunkDatamodelLink'   => __( 'Open bol data model', 'woo-bol-sync' ),
                'chunkDatamodelUrl'    => 'https://developers.bol.com/en/datamodel/',
                'collapse'             => __( 'Collapse', 'woo-bol-sync' ),
                'minimize'             => __( 'Minimize', 'woo-bol-sync' ),
                'fetchingProducts'     => __( 'Fetching bol product…', 'woo-bol-sync' ),
                'fetchingOrders'       => __( 'Fetching bol orders…', 'woo-bol-sync' ),
                'fetchingOrderDetail'  => __( 'Fetching order detail…', 'woo-bol-sync' ),
                'deletingOffer'        => __( 'Deleting offer…', 'woo-bol-sync' ),
                'deleteOfferNeedId'    => __( 'Enter a bol.com offer ID.', 'woo-bol-sync' ),
                'confirmDeleteOffer'   => __( 'Permanently delete this offer on bol.com? This cannot be undone. WooCommerce products are not deleted; local offer mapping will be cleared if it matched this ID.', 'woo-bol-sync' ),
                /* translators: %d: number of offers */
                'confirmBulkDeleteOffer' => __( 'Delete %d selected offers on bol.com? This cannot be undone.', 'woo-bol-sync' ),
                'deleteOfferRow'       => __( 'Delete', 'woo-bol-sync' ),
                'deleteSelectedOffers' => __( 'Delete selected on bol.com', 'woo-bol-sync' ),
                'deletingOffersBulk'   => __( 'Deleting offers…', 'woo-bol-sync' ),
                'selectOffersFirst'    => __( 'Select at least one offer.', 'woo-bol-sync' ),
                /* translators: %d: number of offers successfully deleted */
                'offersDeletedCount'   => __( '%d offer(s) removed on bol.com.', 'woo-bol-sync' ),
                'bulkDeleteHadErrors'  => __( 'Some deletions failed:', 'woo-bol-sync' ),
                'bolOfferForSale'      => __( 'For sale', 'woo-bol-sync' ),
                'bolOfferNotForSale'   => __( 'Not for sale', 'woo-bol-sync' ),
                'bolOfferOnHold'       => __( 'On hold', 'woo-bol-sync' ),
            ],
        ] );
    }

    // ── AJAX hooks ────────────────────────────────────────────────────────────

    /**
     * Register AJAX handlers via the loader.
     *
     * @param Hook_Loader $loader
     *
     * @return void
     */
    public function register_ajax_hooks( Hook_Loader $loader ): void {
        $loader->add_action( 'wp_ajax_wbs_sync_products',   $this, 'ajax_sync_products' );
        $loader->add_action( 'wp_ajax_wbs_sync_selected_product', $this, 'ajax_sync_selected_product' );
        $loader->add_action( 'wp_ajax_wbs_search_sync_products', $this, 'ajax_search_sync_products' );
        $loader->add_action( 'wp_ajax_wbs_sync_orders',     $this, 'ajax_sync_orders' );
        $loader->add_action( 'wp_ajax_wbs_test_connection', $this, 'ajax_test_connection' );
        $loader->add_action( 'wp_ajax_wbs_run_health_check', $this, 'ajax_run_health_check' );
        $loader->add_action( 'wp_ajax_wbs_retry_failed_products', $this, 'ajax_retry_failed_products' );
        $loader->add_action( 'wp_ajax_wbs_force_update_existing_products', $this, 'ajax_force_update_existing_products' );
        $loader->add_action( 'wp_ajax_wbs_retry_failed_orders', $this, 'ajax_retry_failed_orders' );
        $loader->add_action( 'wp_ajax_wbs_ensure_subscription', $this, 'ajax_ensure_subscription' );
        $loader->add_action( 'wp_ajax_wbs_dashboard_stats', $this, 'ajax_dashboard_stats' );
        $loader->add_action( 'wp_ajax_wbs_refresh_economic_operator', $this, 'ajax_refresh_economic_operator' );
        $loader->add_action( 'wp_ajax_wbs_save_economic_operator', $this, 'ajax_save_economic_operator' );
        $loader->add_action( 'wp_ajax_wbs_delete_economic_operator', $this, 'ajax_delete_economic_operator' );
        $loader->add_action( 'wp_ajax_wbs_clear_logs',      $this, 'ajax_clear_logs' );
        $loader->add_action( 'wp_ajax_wbs_validate_license',$this, 'ajax_validate_license' );
        $loader->add_action( 'wp_ajax_wbs_catalog_lookup_ean', $this, 'ajax_catalog_lookup_ean' );
        $loader->add_action( 'wp_ajax_wbs_chunk_recommendations', $this, 'ajax_chunk_recommendations' );
        $loader->add_action( 'wp_ajax_wbs_bol_products_fetch', $this, 'ajax_bol_products_fetch' );
        $loader->add_action( 'wp_ajax_wbs_bol_offer_delete', $this, 'ajax_bol_offer_delete' );
        $loader->add_action( 'wp_ajax_wbs_bol_orders_fetch', $this, 'ajax_bol_orders_fetch' );
        $loader->add_action( 'wp_ajax_wbs_bol_order_detail_fetch', $this, 'ajax_bol_order_detail_fetch' );
    }

    /**
     * AJAX: fetch seller offers, optionally filtered by EAN.
     */
    public function ajax_bol_products_fetch(): void {
        $this->verify_ajax();
        if ( ! $this->api->has_credentials() ) {
            wp_send_json_error( [ 'message' => __( 'Save API credentials in Settings first.', 'woo-bol-sync' ) ] );
        }

        $ean_raw = isset( $_POST['ean'] ) ? (string) wp_unslash( $_POST['ean'] ) : '';
        $ean     = preg_replace( '/\D/', '', $ean_raw ) ?? '';
        if ( $ean !== '' && ( strlen( $ean ) < 8 || strlen( $ean ) > 14 ) ) {
            wp_send_json_error( [ 'message' => __( 'Enter a valid EAN or GTIN (8–14 digits), or leave it empty.', 'woo-bol-sync' ) ] );
        }

        $page = isset( $_POST['page'] ) ? max( 1, (int) wp_unslash( $_POST['page'] ) ) : 1;
        $size = isset( $_POST['size'] ) ? max( 1, min( 50, (int) wp_unslash( $_POST['size'] ) ) ) : 50;

        $catalog = null;
        if ( $ean !== '' ) {
            $catalog = $this->api->get_catalog_product( $ean );
        }

        $offers_path = '/offers?page=' . $page . '&size=' . $size;
        if ( $ean !== '' ) {
            $offers_path .= '&ean=' . rawurlencode( $ean );
        }

        $offers = $this->api->request_with_headers(
            $offers_path,
            'GET'
        );
        $unpublished_offers = $this->api->request_with_headers(
            $offers_path . '&for-sale=false',
            'GET'
        );

        $catalog_data = [];
        $offers_data  = [];

        if ( is_array( $catalog ) && ! is_wp_error( $catalog ) ) {
            $catalog_data = [
                'code'    => (int) ( $catalog['code'] ?? 0 ),
                'summary' => (string) ( $catalog['summary'] ?? '' ),
                'body'    => is_array( $catalog['body'] ?? null ) ? $catalog['body'] : [],
            ];
        }
        if ( ! is_wp_error( $offers ) ) {
            $offers_data = [
                'code'    => (int) ( $offers['code'] ?? 0 ),
                'summary' => (string) ( $offers['summary'] ?? '' ),
                'body'    => is_array( $offers['body'] ?? null ) ? $offers['body'] : [],
            ];
        }
        if ( ! is_wp_error( $unpublished_offers ) && is_array( $unpublished_offers['body'] ?? null ) ) {
            $published_body   = is_array( $offers_data['body'] ?? null ) ? $offers_data['body'] : [];
            $unpublished_body = $unpublished_offers['body'];
            $published_items  = [];
            $unpublished_items = [];

            foreach ( [ 'offers', 'results', 'items' ] as $list_key ) {
                if ( is_array( $published_body[ $list_key ] ?? null ) ) {
                    $published_items = $published_body[ $list_key ];
                    break;
                }
            }
            foreach ( [ 'offers', 'results', 'items' ] as $list_key ) {
                if ( is_array( $unpublished_body[ $list_key ] ?? null ) ) {
                    $unpublished_items = $unpublished_body[ $list_key ];
                    break;
                }
            }

            if ( $published_items !== [] || $unpublished_items !== [] ) {
                foreach ( $published_items as $idx => $pub_item ) {
                    if ( is_array( $pub_item ) ) {
                        $published_items[ $idx ]['_wbs_for_sale'] = true;
                    }
                }
                foreach ( $unpublished_items as $idx => $unpub_item ) {
                    if ( is_array( $unpub_item ) ) {
                        $unpublished_items[ $idx ]['_wbs_for_sale'] = false;
                    }
                }
                $merged  = [];
                $seen    = [];
                $all_set = array_merge( $published_items, $unpublished_items );
                foreach ( $all_set as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $offer_id = (string) ( $item['offerId'] ?? $item['id'] ?? '' );
                    if ( $offer_id !== '' && isset( $seen[ $offer_id ] ) ) {
                        continue;
                    }
                    if ( $offer_id !== '' ) {
                        $seen[ $offer_id ] = true;
                    }
                    $merged[] = $item;
                }
                $offers_data['body']['offers'] = $merged;
            }
        }

        if ( is_wp_error( $catalog ) && is_wp_error( $offers ) ) {
            wp_send_json_error( [ 'message' => $catalog->get_error_message() ] );
        }
        if ( is_wp_error( $offers ) ) {
            wp_send_json_error( [ 'message' => $offers->get_error_message() ] );
        }

        $offer_items  = $this->extract_offer_items_from_offers_data( $offers_data );
        $has_products = $offer_items !== [];
        $offer_rows   = $has_products ? $this->normalize_offers_for_admin_table( $offer_items ) : [];

        wp_send_json_success(
            [
                'message' => ! $has_products
                    ? __( 'No products found for this seller account yet.', 'woo-bol-sync' )
                    : ( $ean !== ''
                        ? sprintf( __( 'Fetched seller products for EAN %s (including unpublished offers when available).', 'woo-bol-sync' ), $ean )
                        : __( 'Fetched seller products for the current bol account (including unpublished offers when available).', 'woo-bol-sync' ) ),
                'ean'            => $ean,
                'page'           => $page,
                'size'           => $size,
                'has_products'   => $has_products,
                'catalog'        => $catalog_data,
                'offers'         => $offers_data,
                'offer_rows'     => $offer_rows,
            ]
        );
    }

    /**
     * AJAX: delete one seller offer on bol.com by offer ID.
     */
    public function ajax_bol_offer_delete(): void {
        $this->verify_ajax();
        if ( ! $this->api->has_credentials() ) {
            wp_send_json_error( [ 'message' => __( 'Save API credentials in Settings first.', 'woo-bol-sync' ) ] );
        }

        $offer_id = isset( $_POST['offer_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['offer_id'] ) ) : '';
        $offer_id = trim( $offer_id );
        if ( $offer_id === '' || strlen( $offer_id ) > 128 || ! preg_match( '/^[a-zA-Z0-9._-]+$/', $offer_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Enter a valid offer ID (as shown in the seller offers API).', 'woo-bol-sync' ) ] );
        }

        $resp = $this->api->delete_offer( $offer_id );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
        }

        $code = (int) ( $resp['code'] ?? 0 );
        $gone = ( $code === 404 );
        if ( ( $code < 200 || $code >= 300 ) && ! $gone ) {
            wp_send_json_error( [ 'message' => (string) ( $resp['summary'] ?? __( 'bol.com rejected the request.', 'woo-bol-sync' ) ) ] );
        }

        $unlinked = Product_Mapping::unlink_by_bol_offer_id( $offer_id );

        Logger::info(
            'bol.com offer deleted via admin.',
            [
                'offer_id'               => $offer_id,
                'http_code'              => $code,
                'unlinked_wc_product_id' => $unlinked,
            ],
            'api'
        );

        if ( $gone ) {
            $message = $unlinked > 0
                /* translators: %d: WooCommerce product ID */
                ? sprintf( __( 'That offer was already gone on bol.com. Cleared local mapping for product #%d.', 'woo-bol-sync' ), $unlinked )
                : __( 'That offer was already gone on bol.com.', 'woo-bol-sync' );
        } elseif ( $unlinked > 0 ) {
            /* translators: %d: WooCommerce product ID */
            $message = sprintf( __( 'Offer deleted on bol.com. Cleared local mapping for product #%d.', 'woo-bol-sync' ), $unlinked );
        } else {
            $message = __( 'Offer deleted on bol.com.', 'woo-bol-sync' );
        }

        wp_send_json_success(
            [
                'message'                => $message,
                'unlinked_wc_product_id' => $unlinked,
            ]
        );
    }

    /**
     * AJAX: fetch bol orders list.
     */
    public function ajax_bol_orders_fetch(): void {
        $this->verify_ajax();
        if ( ! $this->api->has_credentials() ) {
            wp_send_json_error( [ 'message' => __( 'Save API credentials in Settings first.', 'woo-bol-sync' ) ] );
        }

        $status = isset( $_POST['status'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['status'] ) ) : 'ALL';
        $status = strtoupper( $status );
        if ( ! in_array( $status, [ 'ALL', 'OPEN', 'SHIPPED', 'CANCELLED' ], true ) ) {
            $status = 'ALL';
        }
        $fulfilment = isset( $_POST['fulfilment_method'] ) ? strtoupper( sanitize_text_field( (string) wp_unslash( $_POST['fulfilment_method'] ) ) ) : 'ALL';
        if ( ! in_array( $fulfilment, [ 'ALL', 'FBR', 'FBB' ], true ) ) {
            $fulfilment = 'ALL';
        }
        $latest_change_date = isset( $_POST['latest_change_date'] ) ? trim( (string) wp_unslash( $_POST['latest_change_date'] ) ) : '';
        if ( $latest_change_date !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $latest_change_date ) ) {
            wp_send_json_error( [ 'message' => __( 'Latest change date must be empty or in YYYY-MM-DD format.', 'woo-bol-sync' ) ] );
        }
        // bol.com: combine latest-change-date with status=ALL for open + handled on that day (up to ~3 months back).
        if ( $latest_change_date !== '' ) {
            $status = 'ALL';
        }
        $page = isset( $_POST['page'] ) ? max( 1, (int) wp_unslash( $_POST['page'] ) ) : 1;
        $size = isset( $_POST['size'] ) ? max( 1, min( 50, (int) wp_unslash( $_POST['size'] ) ) ) : 50;

        $query = [
            'fulfilment-method' => $fulfilment,
            'status'            => $status,
            'page'              => $page,
            'size'              => $size,
        ];
        if ( $latest_change_date !== '' ) {
            $query['latest-change-date'] = $latest_change_date;
        }
        $path = '/orders?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

        $resp = $this->api->request_with_headers( $path, 'GET' );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
        }

        $body   = is_array( $resp['body'] ?? null ) ? $resp['body'] : [];
        $orders = $body['orders'] ?? $body['results'] ?? [];
        if ( ! is_array( $orders ) ) {
            $orders = [];
        }

        $message = sprintf( __( 'Fetched %d bol orders.', 'woo-bol-sync' ), count( $orders ) );
        $hint    = '';
        if ( $orders === [] && $latest_change_date === '' ) {
            $hint = __( 'Tip: the bol.com list API shows open orders plus shipments/cancellations from about the last 48 hours only. Use “Latest change date” (day the order was placed or last updated) to load older rows, set Logistics to ALL, and confirm your seller-fulfilled orders use FBR.', 'woo-bol-sync' );
        }

        wp_send_json_success(
            [
                'message'            => $message,
                'hint'               => $hint,
                'code'               => (int) ( $resp['code'] ?? 0 ),
                'summary'            => (string) ( $resp['summary'] ?? '' ),
                'status'             => $status,
                'fulfilment_method'  => $fulfilment,
                'latest_change_date' => $latest_change_date,
                'page'               => $page,
                'size'               => $size,
                'orders'             => array_values( $orders ),
                'raw_body'           => $body,
            ]
        );
    }

    /**
     * AJAX: fetch one bol order detail.
     */
    public function ajax_bol_order_detail_fetch(): void {
        $this->verify_ajax();
        if ( ! $this->api->has_credentials() ) {
            wp_send_json_error( [ 'message' => __( 'Save API credentials in Settings first.', 'woo-bol-sync' ) ] );
        }

        $order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['order_id'] ) ) : '';
        $order_id = trim( $order_id );
        if ( $order_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Enter a valid bol order ID.', 'woo-bol-sync' ) ] );
        }

        $resp = $this->api->get_order( $order_id );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
        }

        wp_send_json_success(
            [
                'message'  => sprintf( __( 'Fetched order detail for %s.', 'woo-bol-sync' ), $order_id ),
                'order_id' => $order_id,
                'code'     => (int) ( $resp['code'] ?? 0 ),
                'summary'  => (string) ( $resp['summary'] ?? '' ),
                'body'     => is_array( $resp['body'] ?? null ) ? $resp['body'] : [],
            ]
        );
    }

    /**
     * AJAX: trigger full product sync.
     *
     * @return void
     */
    public function ajax_sync_products(): void {
        $this->verify_ajax();
        Ajax_Runtime::prepare_long_request();
        if ( ! Mapping_Config::product_sync_enabled() ) {
            wp_send_json_error(
                [ 'message' => __( 'Product sync is disabled in settings. Enable it first to run manual sync.', 'woo-bol-sync' ) ],
                400
            );
        }
        $service = new \WooBolSync\Services\Product_Sync_Service( $this->api );
        $result  = $service->sync_all();
        wp_send_json_success( [
            'message' => sprintf(
                __( 'Product sync complete — Synced: %1$d | Created: %2$d | Updated: %3$d | Pending async: %4$d | Failed: %5$d | Skipped: %6$d | Invalid: %7$d', 'woo-bol-sync' ),
                $result['synced'],
                $result['created'] ?? 0,
                $result['updated'] ?? 0,
                $result['pending_async'] ?? 0,
                $result['failed'],
                $result['skipped'],
                $result['invalid'] ?? 0
            ),
            'result' => $result,
            'stats'  => self::dashboard_stats_payload(),
        ] );
    }

    /**
     * AJAX: trigger direct sync for one selected product or variation.
     *
     * @return void
     */
    public function ajax_sync_selected_product(): void {
        $this->verify_ajax();
        Ajax_Runtime::prepare_long_request();
        if ( ! Mapping_Config::product_sync_enabled() ) {
            wp_send_json_error(
                [ 'message' => __( 'Product sync is disabled in settings. Enable it first to run manual sync.', 'woo-bol-sync' ) ],
                400
            );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        if ( $product_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Select a valid WooCommerce product or variation.', 'woo-bol-sync' ) ], 400 );
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
        if ( ! $product instanceof \WC_Product ) {
            wp_send_json_error( [ 'message' => __( 'Selected WooCommerce product was not found.', 'woo-bol-sync' ) ], 404 );
        }

        $service = new Product_Sync_Service( $this->api );
        $result  = $service->sync_product_by_id( $product_id, true, false );

        $label   = $product->get_name() !== '' ? $product->get_name() : '#' . $product_id;
        $message = match ( $result['status'] ) {
            'synced' => sprintf( __( 'Selected product synced: %1$s (%2$s).', 'woo-bol-sync' ), $label, $result['operation'] !== '' ? $result['operation'] : __( 'synced', 'woo-bol-sync' ) ),
            'skipped' => sprintf( __( 'Selected product was skipped: %s.', 'woo-bol-sync' ), $label ),
            'invalid' => sprintf( __( 'Selected product is invalid for sync: %s.', 'woo-bol-sync' ), $label ),
            default => sprintf( __( 'Selected product sync failed: %s.', 'woo-bol-sync' ), $label ),
        };

        if ( $result['status'] === 'failed' || $result['status'] === 'invalid' ) {
            wp_send_json_error(
                [
                    'message' => $message,
                    'result'  => $result,
                    'stats'   => self::dashboard_stats_payload(),
                ]
            );
        }

        wp_send_json_success( [
            'message' => $message,
            'result'  => [
                'synced'        => $result['status'] === 'synced' ? 1 : 0,
                'created'       => $result['operation'] === 'created' ? 1 : 0,
                'updated'       => $result['operation'] === 'updated' ? 1 : 0,
                'pending_async' => $result['status'] === 'pending_async' ? 1 : 0,
                'failed'        => 0,
                'skipped'       => $result['status'] === 'skipped' ? 1 : 0,
                'invalid'       => 0,
            ],
            'stats'   => self::dashboard_stats_payload(),
        ] );
    }

    /**
     * AJAX: search WooCommerce products/variations for direct sync.
     *
     * @return void
     */
    public function ajax_search_sync_products(): void {
        $this->verify_ajax();

        $term = isset( $_POST['term'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['term'] ) ) : '';
        $term = trim( $term );
        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( [ 'items' => [] ] );
        }

        $limit      = 20;
        $statuses   = Mapping_Config::sync_only_published() ? [ 'publish' ] : [ 'publish', 'draft', 'private' ];
        $normalized = strtolower( $term );
        $digits     = preg_replace( '/\D/', '', $term ) ?? '';

        $candidates = [];

        $search_query = new \WP_Query(
            [
                'post_type'              => [ 'product', 'product_variation' ],
                'post_status'            => $statuses,
                'posts_per_page'         => $limit,
                's'                      => $term,
                'fields'                 => 'ids',
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );
        if ( is_array( $search_query->posts ?? null ) ) {
            $candidates = array_merge( $candidates, array_map( 'absint', $search_query->posts ) );
        }

        $sku_query = new \WP_Query(
            [
                'post_type'              => [ 'product', 'product_variation' ],
                'post_status'            => $statuses,
                'posts_per_page'         => $limit,
                'fields'                 => 'ids',
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => [
                    [
                        'key'     => '_sku',
                        'value'   => $term,
                        'compare' => 'LIKE',
                    ],
                ],
            ]
        );
        if ( is_array( $sku_query->posts ?? null ) ) {
            $candidates = array_merge( $candidates, array_map( 'absint', $sku_query->posts ) );
        }

        if ( $digits !== '' ) {
            $id_query = new \WP_Query(
                [
                    'post_type'              => [ 'product', 'product_variation' ],
                    'post_status'            => $statuses,
                    'posts_per_page'         => $limit,
                    'post__in'               => [ (int) $digits ],
                    'fields'                 => 'ids',
                    'orderby'                => 'post__in',
                    'no_found_rows'          => true,
                    'ignore_sticky_posts'    => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]
            );
            if ( is_array( $id_query->posts ?? null ) ) {
                $candidates = array_merge( $candidates, array_map( 'absint', $id_query->posts ) );
            }
        }

        $items = [];
        $seen  = [];
        foreach ( array_values( array_unique( array_filter( $candidates ) ) ) as $product_id ) {
            $product = wc_get_product( (int) $product_id );
            if ( ! $product instanceof \WC_Product ) {
                continue;
            }

            $label = $this->direct_sync_product_label( $product );
            $haystack = strtolower(
                $label . ' ' .
                (string) $product->get_sku() . ' ' .
                Mapping_Config::get_ean( $product ) . ' ' .
                (string) $product->get_id()
            );
            if ( ! str_contains( $haystack, $normalized ) && $digits !== '' && ! str_contains( $haystack, $digits ) ) {
                continue;
            }

            $key = (int) $product->get_id();
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            $items[] = [
                'id'    => $key,
                'label' => $label,
                'sku'   => (string) $product->get_sku(),
                'ean'   => Mapping_Config::get_ean( $product ),
                'type'  => $product->is_type( 'variation' ) ? 'variation' : 'product',
            ];

            if ( count( $items ) >= $limit ) {
                break;
            }
        }

        wp_send_json_success( [ 'items' => $items ] );
    }

    private function direct_sync_product_label( \WC_Product $product ): string {
        $product_id = $product->get_id();
        $name       = $product->get_name();
        $sku        = (string) $product->get_sku();
        $ean        = Mapping_Config::get_ean( $product );
        $type       = $product->is_type( 'variation' ) ? __( 'Variation', 'woo-bol-sync' ) : __( 'Product', 'woo-bol-sync' );

        $parts   = [];
        $parts[] = $name !== '' ? $name : __( 'Untitled product', 'woo-bol-sync' );
        $parts[] = '#' . $product_id;
        if ( $sku !== '' ) {
            $parts[] = 'SKU: ' . $sku;
        }
        if ( $ean !== '' ) {
            $parts[] = 'EAN: ' . $ean;
        }
        $parts[] = '[' . $type . ']';

        return implode( ' · ', $parts );
    }

    /**
     * AJAX: trigger full order sync.
     *
     * @return void
     */
    public function ajax_sync_orders(): void {
        $this->verify_ajax();
        Ajax_Runtime::prepare_long_request();
        $service = new \WooBolSync\Services\Order_Sync_Service( $this->api );
        $result  = $service->sync_orders();
        wp_send_json_success( [
            'message' => sprintf(
                __( 'Order sync complete — Created: %1$d | Skipped: %2$d | Failed: %3$d | Returns: %4$d', 'woo-bol-sync' ),
                $result['created'], $result['skipped'], $result['failed'], $result['returns'] ?? 0
            ),
            'result' => $result,
        ] );
    }

    /**
     * AJAX: GET catalog product by EAN (read-only) for category mapping discovery.
     */
    public function ajax_catalog_lookup_ean(): void {
        $this->verify_ajax();
        if ( ! $this->api->has_credentials() ) {
            wp_send_json_error( [ 'message' => __( 'Save API credentials in Settings first.', 'woo-bol-sync' ) ] );
        }

        $ean_raw = isset( $_POST['ean'] ) ? (string) wp_unslash( $_POST['ean'] ) : '';
        $ean     = preg_replace( '/\D/', '', $ean_raw ) ?? '';
        if ( strlen( $ean ) < 8 || strlen( $ean ) > 14 ) {
            wp_send_json_error( [ 'message' => __( 'Enter a valid EAN or GTIN (8–14 digits).', 'woo-bol-sync' ) ] );
        }

        $resp = $this->api->get_catalog_product( $ean );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
        }

        $code = (int) ( $resp['code'] ?? 0 );
        $body = isset( $resp['body'] ) && is_array( $resp['body'] ) ? $resp['body'] : [];
        $summary = isset( $resp['summary'] ) ? (string) $resp['summary'] : '';

        $gpc_chunk_id = '';
        if ( isset( $body['gpc'] ) && is_array( $body['gpc'] ) ) {
            $cid = $body['gpc']['chunkId'] ?? $body['gpc']['chunk_id'] ?? '';
            if ( is_string( $cid ) && $cid !== '' ) {
                $gpc_chunk_id = $cid;
            } elseif ( is_scalar( $cid ) ) {
                $gpc_chunk_id = (string) $cid;
            }
        }

        if ( $code === 404 ) {
            $summary = __( 'EAN not found in bol catalog.', 'woo-bol-sync' );
        } elseif ( $code >= 200 && $code < 300 ) {
            $published_raw = $body['published'] ?? null;
            $published     = null;
            if ( is_bool( $published_raw ) ) {
                $published = $published_raw;
            }

            $published_label = 'unknown';
            if ( $published === true ) {
                $published_label = 'yes';
            } elseif ( $published === false ) {
                $published_label = 'no';
            }

            $summary = sprintf(
                /* translators: 1: yes/no/unknown, 2: optional chunk id suffix */
                __( 'Catalog product found (published: %1$s%2$s)', 'woo-bol-sync' ),
                $published_label,
                $gpc_chunk_id !== '' ? ', chunkId: ' . $gpc_chunk_id : ''
            );
        }

        $json = wp_json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            $json = '{}';
        }
        if ( strlen( $json ) > 48000 ) {
            $json = substr( $json, 0, 48000 ) . "\n…";
        }

        $hint = '';
        if ( $code === 404 ) {
            $hint = __( 'This EAN is not in bol.com’s catalog yet. That is normal for new or exclusive products. Use “Chunk recommendations” below with your product title, or get classification details from bol partner support.', 'woo-bol-sync' );
        }

        wp_send_json_success(
            [
                'http_code'        => $code,
                'summary'          => $summary,
                'body_json'        => $json,
                'gpc_chunk_id'     => $gpc_chunk_id,
                'catalog_not_found'=> $code === 404,
                'hint'             => $hint,
            ]
        );
    }

    /**
     * AJAX: POST chunk recommendations (Name + optional Description) for classification hints.
     */
    public function ajax_chunk_recommendations(): void {
        $this->verify_ajax();
        if ( ! $this->api->has_credentials() ) {
            wp_send_json_error( [ 'message' => __( 'Save API credentials in Settings first.', 'woo-bol-sync' ) ] );
        }

        $name = isset( $_POST['product_name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['product_name'] ) ) : '';
        $name = trim( $name );
        if ( $name === '' ) {
            wp_send_json_error( [ 'message' => __( 'Enter a product name.', 'woo-bol-sync' ) ] );
        }

        $desc = isset( $_POST['product_description'] ) ? wp_strip_all_tags( (string) wp_unslash( $_POST['product_description'] ) ) : '';
        $desc = trim( preg_replace( '/\s+/', ' ', $desc ) ?? '' );

        $attrs = [
            [
                'id'     => 'Name',
                'values' => [
                    [ 'value' => mb_substr( $name, 0, 500 ) ],
                ],
            ],
        ];
        if ( $desc !== '' ) {
            $attrs[] = [
                'id'     => 'Description',
                'values' => [
                    [ 'value' => mb_substr( $desc, 0, 2000 ) ],
                ],
            ];
        }

        $payload = [
            'productContents' => [
                [
                    'attributes' => $attrs,
                ],
            ],
        ];

        $resp = $this->api->get_chunk_recommendations( $payload );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
        }

        $code = (int) ( $resp['code'] ?? 0 );
        $body = isset( $resp['body'] ) && is_array( $resp['body'] ) ? $resp['body'] : [];
        $summary = isset( $resp['summary'] ) ? (string) $resp['summary'] : '';

        $json = wp_json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            $json = '{}';
        }
        if ( strlen( $json ) > 24000 ) {
            $json = substr( $json, 0, 24000 ) . "\n…";
        }

        $predictions_parsed = self::parse_chunk_recommendation_predictions( $body );

        wp_send_json_success(
            [
                'http_code'          => $code,
                'summary'            => $summary,
                'body_json'          => $json,
                'product_name_sent'  => $name,
                'predictions_parsed' => $predictions_parsed,
            ]
        );
    }

    /**
     * Extract chunkId + probability from chunk-recommendations response body.
     *
     * @param array<string, mixed> $body
     * @return array<int, array{chunkId:string, probability:float, percent:string}>
     */
    private static function parse_chunk_recommendation_predictions( array $body ): array {
        $recs = $body['recommendations'] ?? null;
        if ( ! is_array( $recs ) || $recs === [] ) {
            return [];
        }
        $first = $recs[0] ?? null;
        if ( ! is_array( $first ) ) {
            return [];
        }
        $preds = $first['predictions'] ?? null;
        if ( ! is_array( $preds ) ) {
            return [];
        }
        $out = [];
        foreach ( $preds as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }
            $cid = isset( $p['chunkId'] ) ? (string) $p['chunkId'] : '';
            if ( $cid === '' ) {
                continue;
            }
            $prob = isset( $p['probability'] ) && is_numeric( $p['probability'] ) ? (float) $p['probability'] : 0.0;
            $out[] = [
                'chunkId'     => $cid,
                'probability' => $prob,
                'percent'     => (string) round( $prob * 100, 4 ),
            ];
        }

        return $out;
    }

    /**
     * AJAX: test API connection.
     *
     * @return void
     */
    public function ajax_test_connection(): void {
        $this->verify_ajax();
        if ( $this->api->test_connection() ) {
            $eo_part = '';
            $fr      = $this->api->fetch_and_store_economic_operator();
            if ( $fr === true ) {
                $eo_part = ' ' . __( 'Economic operator loaded from bol.com.', 'woo-bol-sync' );
            } elseif ( is_wp_error( $fr ) ) {
                $eo_part = ' ' . $fr->get_error_message();
            }

            $message = __( 'Successfully connected to bol.com.', 'woo-bol-sync' ) . $eo_part;
            Mapping_Config::save_connection_status( 'success', $message );
            wp_send_json_success(
                [
                    'message'            => $message,
                    'economic_operator'  => self::economic_operator_ajax_payload(),
                ]
            );
        }

        $detail = $this->api->get_last_error();
        $msg    = $detail !== ''
            ? sprintf(
                /* translators: %s: technical detail from bol.com or WordPress */
                __( 'Connection failed. %s', 'woo-bol-sync' ),
                $detail
            )
            : __( 'Connection failed. Check your Client ID and Client Secret.', 'woo-bol-sync' );

        Mapping_Config::save_connection_status( 'error', $msg );
        wp_send_json_error( [ 'message' => $msg ] );
    }

    /**
     * AJAX: run merchant-facing health diagnostics.
     */
    public function ajax_run_health_check(): void {
        $this->verify_ajax();
        $report = $this->api->run_health_check();
        wp_send_json_success(
            [
                'message' => $report['ok']
                    ? __( 'Health check passed.', 'woo-bol-sync' )
                    : __( 'Health check found issues that need attention.', 'woo-bol-sync' ),
                'report'  => $report,
            ]
        );
    }

    public function ajax_retry_failed_products(): void {
        $this->verify_ajax();
        Ajax_Runtime::prepare_long_request();
        if ( ! Mapping_Config::product_sync_enabled() ) {
            wp_send_json_error(
                [ 'message' => __( 'Product sync is disabled in settings. Enable it first to retry failed products.', 'woo-bol-sync' ) ],
                400
            );
        }
        $service = new Product_Sync_Service( $this->api );
        $result  = $service->retry_failed_products();
        wp_send_json_success(
            [
                'message' => sprintf(
                    __( 'Retried products — Synced: %1$d | Created: %2$d | Updated: %3$d | Pending async: %4$d | Failed: %5$d | Skipped: %6$d | Invalid: %7$d', 'woo-bol-sync' ),
                    $result['synced'],
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['pending_async'] ?? 0,
                    $result['failed'],
                    $result['skipped'],
                    $result['invalid'] ?? 0
                ),
                'result'  => $result,
                'stats'   => self::dashboard_stats_payload(),
            ]
        );
    }

    public function ajax_force_update_existing_products(): void {
        $this->verify_ajax();
        Ajax_Runtime::prepare_long_request();
        if ( ! Mapping_Config::product_sync_enabled() ) {
            wp_send_json_error(
                [ 'message' => __( 'Product sync is disabled in settings. Enable it first to force-update products.', 'woo-bol-sync' ) ],
                400
            );
        }
        $service = new Product_Sync_Service( $this->api );
        $result  = $service->force_update_existing_products();
        wp_send_json_success(
            [
                'message' => sprintf(
                    __( 'Force update complete — Synced: %1$d | Updated: %2$d | Pending async: %3$d | Failed: %4$d | Skipped: %5$d | Invalid: %6$d', 'woo-bol-sync' ),
                    $result['synced'],
                    $result['updated'] ?? 0,
                    $result['pending_async'] ?? 0,
                    $result['failed'],
                    $result['skipped'],
                    $result['invalid'] ?? 0
                ),
                'result' => $result,
                'stats'  => self::dashboard_stats_payload(),
            ]
        );
    }

    public function ajax_dashboard_stats(): void {
        $this->verify_ajax();
        wp_send_json_success(
            [
                'stats' => self::dashboard_stats_payload(),
            ]
        );
    }

    public function ajax_retry_failed_orders(): void {
        $this->verify_ajax();
        Ajax_Runtime::prepare_long_request();
        $service = new Order_Sync_Service( $this->api );
        $result  = $service->sync_orders();
        wp_send_json_success(
            [
                'message' => sprintf(
                    __( 'Retried orders — Created: %1$d | Skipped: %2$d | Failed: %3$d | Returns: %4$d', 'woo-bol-sync' ),
                    $result['created'],
                    $result['skipped'],
                    $result['failed'],
                    $result['returns'] ?? 0
                ),
                'result' => $result,
            ]
        );
    }

    public function ajax_ensure_subscription(): void {
        $this->verify_ajax();
        $service = new Subscription_Sync_Service( $this->api );
        $result  = $service->ensure_process_status_subscription();
        if ( ! empty( $result['ok'] ) ) {
            $test_result = $service->test_configured_subscription();
            $keys_result = $service->refresh_signature_keys();

            $result['test']           = $test_result;
            $result['signature_keys'] = $keys_result;

            $message_parts = [ (string) $result['message'] ];
            $message_parts[] = ! empty( $test_result['ok'] )
                ? __( 'Test push was scheduled.', 'woo-bol-sync' )
                : sprintf(
                    /* translators: %s: API or validation error text */
                    __( 'Test push failed: %s', 'woo-bol-sync' ),
                    (string) ( $test_result['message'] ?? __( 'Unknown error.', 'woo-bol-sync' ) )
                );
            $message_parts[] = ! empty( $keys_result['ok'] )
                ? sprintf(
                    /* translators: %d: number of signature keys */
                    __( 'Signature keys refreshed (%d keys).', 'woo-bol-sync' ),
                    (int) ( $keys_result['count'] ?? 0 )
                )
                : sprintf(
                    /* translators: %s: API or validation error text */
                    __( 'Signature keys refresh failed: %s', 'woo-bol-sync' ),
                    (string) ( $keys_result['message'] ?? __( 'Unknown error.', 'woo-bol-sync' ) )
                );

            wp_send_json_success(
                [
                    'message' => implode( ' ', array_filter( $message_parts ) ),
                    'result'  => $result,
                ]
            );
        }
        wp_send_json_error( [ 'message' => $result['message'], 'result' => $result ] );
    }

    /**
     * AJAX: re-fetch economic operator UUID from bol.com.
     */
    public function ajax_refresh_economic_operator(): void {
        $this->verify_ajax();
        $fr = $this->api->fetch_and_store_economic_operator();
        if ( $fr === true ) {
            wp_send_json_success(
                [
                    'message'           => __( 'Economic operator saved from bol.com.', 'woo-bol-sync' ),
                    'economic_operator' => self::economic_operator_ajax_payload(),
                ]
            );
        }

        $msg = is_wp_error( $fr ) ? $fr->get_error_message() : __( 'Could not fetch economic operator.', 'woo-bol-sync' );
        wp_send_json_error(
            [
                'message'           => $msg,
                'economic_operator' => self::economic_operator_ajax_payload(),
            ]
        );
    }

    /**
     * AJAX: create or update the economic operator using the documented API.
     */
    public function ajax_save_economic_operator(): void {
        $this->verify_ajax();

        $name                  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $street                = sanitize_text_field( wp_unslash( $_POST['street'] ?? '' ) );
        $house_number          = sanitize_text_field( wp_unslash( $_POST['houseNumber'] ?? '' ) );
        $postal_code           = sanitize_text_field( wp_unslash( $_POST['postalCode'] ?? '' ) );
        $city                  = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
        $country               = strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
        $email                 = sanitize_email( wp_unslash( $_POST['emailAddress'] ?? '' ) );
        $phone                 = sanitize_text_field( wp_unslash( $_POST['phoneNumber'] ?? '' ) );
        $additional_info       = sanitize_text_field( wp_unslash( $_POST['additionalAddressInfo'] ?? '' ) );
        $external_reference    = sanitize_text_field( wp_unslash( $_POST['externalReference'] ?? '' ) );

        if ( '' === $name || '' === $street || '' === $house_number || '' === $postal_code || '' === $city || '' === $country || '' === $email || '' === $phone ) {
            wp_send_json_error( [ 'message' => __( 'Please fill all required economic operator fields.', 'woo-bol-sync' ) ], 400 );
        }

        $payload = [
            'name'               => $name,
            'address'            => [
                'street'                => $street,
                'houseNumber'           => $house_number,
                'postalCode'            => $postal_code,
                'city'                  => $city,
                'country'               => $country,
            ],
            'contactInformation' => [
                'emailAddress'          => $email,
                'phoneNumber'           => $phone,
            ],
        ];

        if ( '' !== $additional_info ) {
            $payload['address']['additionalAddressInfo'] = $additional_info;
        }
        if ( '' !== $external_reference ) {
            $payload['externalReference'] = $external_reference;
        }

        $current_id = Mapping_Config::get_economic_operator_id();
        $result = $current_id !== ''
            ? $this->api->update_economic_operator( $current_id, $payload )
            : $this->api->create_economic_operator( $payload );

        if ( is_wp_error( $result ) || $result['code'] < 200 || $result['code'] >= 300 ) {
            wp_send_json_error(
                [
                    'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Could not save economic operator.', 'woo-bol-sync' ),
                ]
            );
        }

        $refresh = $this->api->fetch_and_store_economic_operator();
        if ( is_wp_error( $refresh ) ) {
            wp_send_json_error( [ 'message' => $refresh->get_error_message() ] );
        }

        wp_send_json_success(
            [
                'message'           => $current_id !== '' ? __( 'Economic operator updated.', 'woo-bol-sync' ) : __( 'Economic operator created.', 'woo-bol-sync' ),
                'economic_operator' => self::economic_operator_ajax_payload(),
            ]
        );
    }

    /**
     * AJAX: delete the currently selected economic operator.
     */
    public function ajax_delete_economic_operator(): void {
        $this->verify_ajax();
        $current_id = Mapping_Config::get_economic_operator_id();
        if ( $current_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'No economic operator is currently stored.', 'woo-bol-sync' ) ], 400 );
        }

        $result = $this->api->delete_economic_operator( $current_id );
        if ( is_wp_error( $result ) || $result['code'] < 200 || $result['code'] >= 300 ) {
            wp_send_json_error(
                [
                    'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Could not delete economic operator.', 'woo-bol-sync' ),
                ]
            );
        }

        Mapping_Config::clear_economic_operator_options();
        wp_send_json_success(
            [
                'message'           => __( 'Economic operator deleted.', 'woo-bol-sync' ),
                'economic_operator' => self::economic_operator_ajax_payload(),
            ]
        );
    }

    /**
     * @return array{connected:bool, status_line:string, detail:string, operator_status:string}
     */
    private static function economic_operator_ajax_payload(): array {
        $id   = Mapping_Config::get_economic_operator_id();
        $name = Mapping_Config::get_economic_operator_name();
        $st   = Mapping_Config::get_economic_operator_status();

        if ( $id !== '' ) {
            $line = $name !== ''
                ? sprintf(
                    /* translators: 1: operator display name 2: bol.com status (e.g. VALID) */
                    __( 'Economic operator: connected — %1$s (%2$s)', 'woo-bol-sync' ),
                    $name,
                    $st !== '' ? $st : __( 'unknown status', 'woo-bol-sync' )
                )
                : sprintf(
                    /* translators: %s: bol.com status */
                    __( 'Economic operator: connected (%s)', 'woo-bol-sync' ),
                    $st !== '' ? $st : __( 'unknown status', 'woo-bol-sync' )
                );

            return [
                'connected'       => true,
                'status_line'     => $line,
                'operator_id'     => $id,
                'detail'          => $name !== '' ? $name : $id,
                'operator_status' => $st,
                'last_sync'       => Mapping_Config::get_economic_operator_last_sync(),
            ];
        }

        return [
            'connected'       => false,
            'status_line'     => __( 'No economic operator found', 'woo-bol-sync' ),
            'operator_id'     => '',
            'detail'          => '',
            'operator_status' => '',
            'last_sync'       => '',
        ];
    }

    /**
     * AJAX: clear all DB log entries.
     *
     * @return void
     */
    public function ajax_clear_logs(): void {
        $this->verify_ajax();
        Logger::clear_all();
        Logger::info( 'Logs cleared by admin user.', [], 'general' );
        wp_send_json_success( [ 'message' => __( 'All log entries have been deleted.', 'woo-bol-sync' ) ] );
    }

    /**
     * AJAX: validate license key.
     *
     * @return void
     */
    public function ajax_validate_license(): void {
        $this->verify_ajax();
        $deactivate = isset( $_POST['deactivate'] ) && (string) wp_unslash( $_POST['deactivate'] ) === '1';
        $key        = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
        $result     = License_Manager::save_and_validate( $key, $deactivate );
        if ( $result['valid'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    // ── Page renderers ────────────────────────────────────────────────────────

    /** @return void */
    public function render_dashboard(): void {
        $this->require_capability();
        $view = WBS_PLUGIN_DIR . 'views/admin/dashboard.php';
        if ( file_exists( $view ) ) {
            $api = $this->api;
            include $view;
        }
    }

    /** @return void */
    public function render_settings(): void {
        $this->require_capability();
        $view = WBS_PLUGIN_DIR . 'views/admin/settings.php';
        if ( file_exists( $view ) ) {
            $eo_form = $this->load_economic_operator_form_data();
            include $view;
        }
    }

    /**
     * @return array<string, string>
     */
    private function load_economic_operator_form_data(): array {
        $defaults = [
            'name'                  => '',
            'street'                => '',
            'houseNumber'           => '',
            'postalCode'            => '',
            'city'                  => '',
            'country'               => 'NL',
            'emailAddress'          => '',
            'phoneNumber'           => '',
            'additionalAddressInfo' => '',
            'externalReference'     => '',
        ];

        $id = Mapping_Config::get_economic_operator_id();
        if ( $id === '' || ! $this->api->has_credentials() ) {
            return $defaults;
        }

        $result = $this->api->get_economic_operator( $id );
        if ( is_wp_error( $result ) || $result['code'] < 200 || $result['code'] >= 300 || ! is_array( $result['body'] ) ) {
            return $defaults;
        }

        $body = $result['body'];
        if ( isset( $body['economicOperator'] ) && is_array( $body['economicOperator'] ) ) {
            $body = $body['economicOperator'];
        }

        if ( isset( $body['name'] ) && is_scalar( $body['name'] ) ) {
            $defaults['name'] = (string) $body['name'];
        }
        if ( isset( $body['externalReference'] ) && is_scalar( $body['externalReference'] ) ) {
            $defaults['externalReference'] = (string) $body['externalReference'];
        }
        if ( isset( $body['address'] ) && is_array( $body['address'] ) ) {
            foreach ( [ 'street', 'houseNumber', 'postalCode', 'city', 'country', 'additionalAddressInfo' ] as $key ) {
                if ( isset( $body['address'][ $key ] ) && is_scalar( $body['address'][ $key ] ) ) {
                    $defaults[ $key ] = (string) $body['address'][ $key ];
                }
            }
        }
        if ( isset( $body['contactInformation'] ) && is_array( $body['contactInformation'] ) ) {
            foreach ( [ 'emailAddress', 'phoneNumber' ] as $key ) {
                if ( isset( $body['contactInformation'][ $key ] ) && is_scalar( $body['contactInformation'][ $key ] ) ) {
                    $defaults[ $key ] = (string) $body['contactInformation'][ $key ];
                }
            }
        }

        return $defaults;
    }

    /** @return void */
    public function render_logs(): void {
        $this->require_capability();
        $view = WBS_PLUGIN_DIR . 'views/admin/logs.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    /** @return void */
    public function render_license(): void {
        $this->require_capability();
        $view = WBS_PLUGIN_DIR . 'views/admin/license.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    /** @return void */
    public function render_category_mapping(): void {
        $this->require_capability();
        $view = WBS_PLUGIN_DIR . 'views/admin/category-mapping.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    /** @return void */
    public function render_field_mapping(): void {
        $this->require_capability();
        $view = WBS_PLUGIN_DIR . 'views/admin/field-mapping.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    /** @return void */
    public function render_bol_products(): void {
        $this->require_capability();
        $view = WBS_PLUGIN_DIR . 'views/admin/bol-products.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    /** @return void */
    public function render_bol_orders(): void {
        $this->require_capability();
        $view = WBS_PLUGIN_DIR . 'views/admin/bol-orders.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    /**
     * Save WC ↔ bol category rows (admin_post).
     *
     * @return void
     */
    public function save_category_map(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'woo-bol-sync' ) );
        }
        check_admin_referer( 'wbs_save_category_map' );
        $rows = isset( $_POST['wbs_bol_cat'] ) && is_array( $_POST['wbs_bol_cat'] )
            ? wp_unslash( $_POST['wbs_bol_cat'] )
            : [];
        $templates = isset( $_POST['wbs_bol_template'] ) && is_array( $_POST['wbs_bol_template'] )
            ? wp_unslash( $_POST['wbs_bol_template'] )
            : [];
        foreach ( $rows as $tid => $bol ) {
            $template_raw = isset( $templates[ $tid ] ) ? (string) $templates[ $tid ] : '';
            Category_Map::upsert_with_template(
                absint( $tid ),
                sanitize_text_field( (string) $bol ),
                self::parse_template_lines( $template_raw )
            );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_CAT . '&updated=1' ) );
        exit;
    }

    // ── Section descriptions ──────────────────────────────────────────────────

    /** @return void */
    public function sec_listing_desc(): void {
        echo '<p>' . esc_html__( 'Choose the fulfilment method for new offers. For Offer API v10 FBR, set a valid delivery code (default 1-2d). For v11 FBR, WooBol defaults to MY_DELIVERY_PROMISE (configure your promise in the bol seller dashboard). Economic operator is loaded from Settings.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function sec_fields_desc(): void {
        echo '<p>' . esc_html__( 'Map WooCommerce data to bol.com listing fields. For EAN/GTIN you can use SKU, a product meta key (meta:_your_key), or an attribute (attribute:pa_ean).', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function sec_credentials_desc(): void {
        echo '<p>' . wp_kses(
            sprintf(
                __( 'Enter the API Client ID and Client Secret you generated in the <a href="%s" target="_blank" rel="noopener noreferrer">bol.com seller portal</a> under <em>Settings → API</em>. The plugin uses these credentials to securely connect your WooCommerce store with your bol.com seller account so it can read products, write offers, and import orders on your behalf.', 'woo-bol-sync' ),
                'https://partner.bol.com/sdd/nl/login'
            ),
            [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ], 'em' => [] ]
        ) . '</p>';
    }

    /** @return void */
    public function sec_dev_desc(): void {
        echo '<p>' . esc_html__( 'Advanced options for diagnostics and housekeeping. Enable debug mode while troubleshooting to capture full bol.com API request and response payloads, and set how long log entries are kept before they are automatically purged. We recommend turning debug mode off again once everything is running smoothly.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function sec_sync_desc(): void {
        echo '<p>' . esc_html__( 'Decide how WooCommerce products are matched and pushed to bol.com. These rules cover both manual sync runs from the dashboard and the scheduled batch jobs below — they fine-tune how often the API is called, how many products are processed at once, and what happens to your bol.com price.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function sec_webhooks_desc(): void {
        echo '<p>' . esc_html__( 'Webhooks let bol.com push real-time updates to your shop the moment something changes — for example when an offer finishes processing or a new order is placed. Enable webhook automation so the plugin keeps the subscription registered with bol.com on its own, and choose how strictly the inbound callback URL is authenticated.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function sec_product_sched_desc(): void {
        echo '<p>' . esc_html__( 'Controls how often the plugin runs a batch product sync (catalog slice via batch size). WooCommerce still syncs individual products immediately when you change stock, price, or product data (hooks). Choose “On WooCommerce product updates only” to disable this batch job entirely.', 'woo-bol-sync' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'If products already exist on bol.com, use this checklist:', 'woo-bol-sync' ) . '</strong></p>';
        echo '<ol style="margin-left:20px;list-style:decimal;">';
        echo '<li>' . esc_html__( 'Enable product sync = Yes.', 'woo-bol-sync' ) . '</li>';
        echo '<li>' . esc_html__( 'Create new offers on bol.com = No (prevents adding new products from WooCommerce).', 'woo-bol-sync' ) . '</li>';
        echo '<li>' . esc_html__( 'Under “Fields to sync”, turn off product title, description, and/or images if you edit those on bol.com and want to keep them.', 'woo-bol-sync' ) . '</li>';
        echo '<li>' . esc_html__( 'Keep EAN/SKU mapping correct so existing offers can be matched and updated.', 'woo-bol-sync' ) . '</li>';
        echo '</ol>';
    }

    /** @return void */
    public function sec_order_sched_desc(): void {
        echo '<p>' . esc_html__( 'Controls how often bol.com orders are imported in bulk. A bol.com webhook POST to your callback URL can still trigger an immediate import when webhook automation is enabled.', 'woo-bol-sync' ) . '</p>';
    }

    // ── Field renderers ───────────────────────────────────────────────────────

    /** @return void */
    public function field_client_id(): void {
        $val = esc_attr( (string) get_option( 'wbs_client_id', '' ) );
        echo '<input type="text" id="wbs_client_id" name="wbs_client_id" value="' . $val . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">' . esc_html__( 'Your bol.com API Client ID.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_client_secret(): void {
        $val = esc_attr( (string) get_option( 'wbs_client_secret', '' ) );
        echo '<input type="password" id="wbs_client_secret" name="wbs_client_secret" value="' . $val . '" class="regular-text" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Your bol.com API Client Secret. Stored securely.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_smart_sync(): void {
        $checked = checked( 1, (int) get_option( 'wbs_smart_sync', 1 ), false );
        echo '<label for="wbs_smart_sync"><input type="checkbox" id="wbs_smart_sync" name="wbs_smart_sync" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Only sync products whose price, stock, or EAN has changed since the last sync', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Recommended. The plugin remembers a hash of the last data sent for each product and skips anything that has not changed, so your scheduled runs stay lightweight and avoid hitting bol.com rate limits. Disable only if you suspect the cache is out of date and want to force a full resync on the next run.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_batch_size(): void {
        $val = (int) get_option( 'wbs_sync_batch_size', 25 );
        echo '<input type="number" id="wbs_sync_batch_size" name="wbs_sync_batch_size" value="' . $val . '" min="1" max="100" class="small-text" />';
        $offset = (int) get_option( Product_Sync_Service::OPTION_SYNC_CATALOG_OFFSET, 0 );
        echo '<p class="description">' . esc_html__( 'Number of products processed per scheduled run (1–100). Each run advances through the catalog in product-ID order using a saved cursor; once the end is reached the cursor wraps back to the start. Increase this to cover your full catalog faster. Changing this resets the cursor.', 'woo-bol-sync' ) . '</p>';
        echo '<p class="description">' . esc_html(
            sprintf(
                /* translators: %d: current catalog cursor (zero-based offset) */
                __( 'Current catalog cursor: %d', 'woo-bol-sync' ),
                $offset
            )
        ) . '</p>';
    }

    /** @return void */
    public function field_rate_limit(): void {
        $val = (int) get_option( 'wbs_rate_limit_delay', 500 );
        echo '<input type="number" id="wbs_rate_limit_delay" name="wbs_rate_limit_delay" value="' . $val . '" min="0" max="5000" step="50" class="small-text" /> ms';
        echo '<p class="description">' . esc_html__( 'Minimum delay between consecutive bol.com API calls. 500 ms is recommended.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_retry_count(): void {
        $val = (int) get_option( 'wbs_api_retry_count', 2 );
        echo '<input type="number" id="wbs_api_retry_count" name="wbs_api_retry_count" value="' . $val . '" min="0" max="4" class="small-text" />';
        echo '<p class="description">' . esc_html__( 'How many times to retry transient bol.com API failures such as rate limits or temporary server errors.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_offer_media_type(): void {
        $cur = Mapping_Config::get_offer_media_type();
        $opts = [
            'application/vnd.retailer.v10+json' => __( 'v10 (default)', 'woo-bol-sync' ),
            'application/vnd.retailer.v11+json' => __( 'v11', 'woo-bol-sync' ),
        ];
        echo '<select id="' . esc_attr( Mapping_Config::OPTION_OFFER_MEDIA_TYPE ) . '" name="' . esc_attr( Mapping_Config::OPTION_OFFER_MEDIA_TYPE ) . '">';
        foreach ( $opts as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $cur, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Picks which version of the bol.com Offer API is used for creating offers and pushing stock and price updates. v10 is the default for broad compatibility; use v11 if your account is fully on the synchronous Offer API. For v11 FBR, new offers use MY_DELIVERY_PROMISE by default (seller dashboard); override with filter wbs_v11_fbr_schedule. Other API groups (orders, returns, processes) keep their own version regardless of this setting.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_auto_recover_stale_offers(): void {
        echo '<input type="hidden" name="' . esc_attr( Mapping_Config::OPTION_AUTO_RECOVER_STALE_OFFERS ) . '" value="0" />';
        $checked = checked( 1, (int) get_option( Mapping_Config::OPTION_AUTO_RECOVER_STALE_OFFERS, 0 ), false );
        echo '<label for="' . esc_attr( Mapping_Config::OPTION_AUTO_RECOVER_STALE_OFFERS ) . '"><input type="checkbox" id="' . esc_attr( Mapping_Config::OPTION_AUTO_RECOVER_STALE_OFFERS ) . '" name="' . esc_attr( Mapping_Config::OPTION_AUTO_RECOVER_STALE_OFFERS ) . '" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Automatically reset stale mapped offer IDs after forbidden offer updates (HTTP 403) so the next sync can recreate the offer', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Off by default. If your stored bol.com offer ID points to an offer that no longer belongs to this seller account (typical after migrating accounts or rotating credentials), bol.com replies with HTTP 403. Enabling this lets the plugin clear the stale mapping so the next sync run recreates the offer cleanly. A built-in 24-hour cooldown stops the same offer being reset over and over by accident.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_staging_mode(): void {
        echo '<input type="hidden" name="' . esc_attr( Mapping_Config::OPTION_STAGING_ENABLED ) . '" value="0" />';
        $checked = checked( 1, (int) get_option( Mapping_Config::OPTION_STAGING_ENABLED, 0 ), false );
        echo '<label for="' . esc_attr( Mapping_Config::OPTION_STAGING_ENABLED ) . '"><input type="checkbox" id="' . esc_attr( Mapping_Config::OPTION_STAGING_ENABLED ) . '" name="' . esc_attr( Mapping_Config::OPTION_STAGING_ENABLED ) . '" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Sync only approved staging drafts (review-before-sync)', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Adds a safety net between WooCommerce and bol.com: when enabled, scheduled syncs only push products you have explicitly approved on the Staging & Review screen. This is the safest option while you are auditing catalog data — it prevents bad images, wrong variations, or incomplete attributes from leaking onto your bol.com listings. Leave off if you trust your WooCommerce data and want every change to go through immediately.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_staging_auto_ingest(): void {
        echo '<input type="hidden" name="' . esc_attr( Mapping_Config::OPTION_STAGING_AUTO_INGEST ) . '" value="0" />';
        $checked = checked( 1, (int) get_option( Mapping_Config::OPTION_STAGING_AUTO_INGEST, 0 ), false );
        echo '<label for="' . esc_attr( Mapping_Config::OPTION_STAGING_AUTO_INGEST ) . '"><input type="checkbox" id="' . esc_attr( Mapping_Config::OPTION_STAGING_AUTO_INGEST ) . '" name="' . esc_attr( Mapping_Config::OPTION_STAGING_AUTO_INGEST ) . '" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Auto-ingest WooCommerce products into staging drafts before every scheduled sync run', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Only takes effect when Staging & Review mode is enabled. Products that are already in staging keep their admin overrides.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_webhook_enabled(): void {
        echo '<input type="hidden" name="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_ENABLED ) . '" value="0" />';
        $checked = checked( 1, (int) get_option( Mapping_Config::OPTION_WEBHOOK_ENABLED, 1 ), false );
        echo '<label for="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_ENABLED ) . '"><input type="checkbox" id="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_ENABLED ) . '" name="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_ENABLED ) . '" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Automatically maintain a bol.com PROCESS_STATUS webhook subscription', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'When enabled, the plugin keeps a webhook subscription registered in your bol.com seller account on its own. bol.com then pings the URL below in real time as soon as offers finish processing or new orders are placed, so your shop stays in sync without waiting for the next scheduled run. The current callback URL is:', 'woo-bol-sync' ) . '</p>';
        echo '<p class="description"><code>' . esc_html( Mapping_Config::get_webhook_url() ) . '</code></p>';
    }

    /** @return void */
    public function field_webhook_signing_required(): void {
        echo '<input type="hidden" name="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED ) . '" value="0" />';
        $checked = checked( 1, (int) get_option( Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED, 1 ), false );
        echo '<label for="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED ) . '"><input type="checkbox" id="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED ) . '" name="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED ) . '" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Reject unsigned webhook requests (recommended for production)', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Strongly recommended once your store is live. With this on, every inbound POST to the callback URL must either carry a valid bol.com RSA signature or include the shared secret you set below — anything else is rejected. You can switch this off temporarily while testing the connection from your laptop, but turn it back on as soon as you go to production so the endpoint cannot be abused.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_webhook_shared_secret(): void {
        $val = esc_attr( (string) get_option( Mapping_Config::OPTION_WEBHOOK_SHARED_SECRET, '' ) );
        echo '<input type="password" id="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_SHARED_SECRET ) . '" name="' . esc_attr( Mapping_Config::OPTION_WEBHOOK_SHARED_SECRET ) . '" value="' . $val . '" class="regular-text" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Optional fallback authentication when bol.com signing is not available (for example while testing from a tool like Postman). Any sender must then include this exact value either as an X-WBS-Webhook-Token header or as a ?token= query argument when calling your callback URL. Leave blank to rely on bol.com\'s RSA signature only.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_margin_type(): void {
        $cur = (string) get_option( Mapping_Config::OPTION_MARGIN_TYPE, 'none' );
        $opts = [
            'none'    => __( 'None (use WooCommerce price)', 'woo-bol-sync' ),
            'percent' => __( 'Percentage on top of WooCommerce price', 'woo-bol-sync' ),
            'fixed'   => __( 'Fixed amount on top of WooCommerce price', 'woo-bol-sync' ),
        ];
        echo '<select id="wbs_price_margin_type" name="wbs_price_margin_type">';
        foreach ( $opts as $v => $label ) {
            echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Applied only to the price sent to bol.com; your shop checkout prices are unchanged.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_margin_value(): void {
        $val = esc_attr( (string) get_option( Mapping_Config::OPTION_MARGIN_VALUE, '0' ) );
        echo '<input type="text" inputmode="decimal" id="wbs_price_margin_value" name="wbs_price_margin_value" value="' . $val . '" class="small-text" /> ';
        echo '<span class="description">' . esc_html__( 'For percent: e.g. 15 = +15%. For fixed: e.g. 2.50 = +€2.50 (store currency).', 'woo-bol-sync' ) . '</span>';
    }

    /** @return void */
    public function field_product_sync_mode(): void {
        $cur = Mapping_Config::get_product_sync_mode();
        $opts = [
            Mapping_Config::SYNC_MODE_DAILY     => __( 'Daily', 'woo-bol-sync' ),
            Mapping_Config::SYNC_MODE_WEEKLY    => __( 'Weekly', 'woo-bol-sync' ),
            Mapping_Config::SYNC_MODE_MONTHLY   => __( 'Monthly', 'woo-bol-sync' ),
            Mapping_Config::SYNC_MODE_WC_UPDATES => __( 'On WooCommerce product updates only (no batch cron)', 'woo-bol-sync' ),
        ];
        echo '<select id="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_MODE ) . '" name="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_MODE ) . '">';
        foreach ( $opts as $v => $label ) {
            echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    /** @return void */
    public function field_product_sync_enabled(): void {
        echo '<input type="hidden" name="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED ) . '" value="0" />';
        $checked = checked( 1, (int) get_option( Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED, 1 ), false );
        echo '<label for="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED ) . '"><input type="checkbox" id="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED ) . '" name="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED ) . '" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Yes — enable product sync (cron + manual + WooCommerce update triggers)', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Set to No to stop all product sync operations while keeping order sync and other plugin features active.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_allow_new_offers(): void {
        echo '<input type="hidden" name="' . esc_attr( Mapping_Config::OPTION_ALLOW_NEW_OFFERS ) . '" value="0" />';
        $checked = checked( 1, (int) get_option( Mapping_Config::OPTION_ALLOW_NEW_OFFERS, 1 ), false );
        echo '<label for="' . esc_attr( Mapping_Config::OPTION_ALLOW_NEW_OFFERS ) . '"><input type="checkbox" id="' . esc_attr( Mapping_Config::OPTION_ALLOW_NEW_OFFERS ) . '" name="' . esc_attr( Mapping_Config::OPTION_ALLOW_NEW_OFFERS ) . '" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Yes — allow creating new bol.com offers for WooCommerce products that are not mapped yet', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Controls whether the plugin is allowed to publish brand-new offers on bol.com. With this off, only WooCommerce products that are already linked to an existing bol.com offer get updated (stock, price, and optionally content); unmapped products are quietly skipped. This is the recommended setting if you joined bol.com before installing the plugin and want WooCommerce to manage your existing catalog without accidentally adding extra listings.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_sync_product_fields(): void {
        $rows = [
            [
                'option' => Mapping_Config::OPTION_SYNC_OFFER_PRICE,
                'id'     => 'wbs_sync_offer_price',
                'label'  => __( 'Price', 'woo-bol-sync' ),
                'hint'   => __( 'Updates bol.com offer price from WooCommerce (after margin rules). New offers always include an initial price.', 'woo-bol-sync' ),
            ],
            [
                'option' => Mapping_Config::OPTION_SYNC_OFFER_STOCK,
                'id'     => 'wbs_sync_offer_stock',
                'label'  => __( 'Stock', 'woo-bol-sync' ),
                'hint'   => __( 'Updates bol.com stock from WooCommerce. New offers always include initial stock.', 'woo-bol-sync' ),
            ],
            [
                'option' => Mapping_Config::OPTION_SYNC_CONTENT_NAME,
                'id'     => 'wbs_sync_content_name',
                'label'  => __( 'Product title (offer & content)', 'woo-bol-sync' ),
                'hint'   => __( 'Pushes the listing title to bol.com. Turn off to keep titles you edit in the bol partner portal.', 'woo-bol-sync' ),
            ],
            [
                'option' => Mapping_Config::OPTION_SYNC_CONTENT_DESCRIPTION,
                'id'     => 'wbs_sync_content_description',
                'label'  => __( 'Description', 'woo-bol-sync' ),
                'hint'   => __( 'Pushes WooCommerce description text to bol.com catalog content.', 'woo-bol-sync' ),
            ],
            [
                'option' => Mapping_Config::OPTION_SYNC_CONTENT_IMAGES,
                'id'     => 'wbs_sync_content_images',
                'label'  => __( 'Images', 'woo-bol-sync' ),
                'hint'   => __( 'Pushes main and gallery images to bol.com. Turn off to preserve bol-only photos.', 'woo-bol-sync' ),
            ],
        ];
        echo '<fieldset class="wbs-sync-field-toggles"><legend class="screen-reader-text">' . esc_html__( 'Fields to sync to bol.com', 'woo-bol-sync' ) . '</legend>';
        foreach ( $rows as $row ) {
            $opt = $row['option'];
            echo '<input type="hidden" name="' . esc_attr( $opt ) . '" value="0" />';
            $checked = checked( 1, (int) get_option( $opt, 1 ), false );
            echo '<p class="wbs-sync-field-row"><label for="' . esc_attr( $row['id'] ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $row['id'] ) . '" name="' . esc_attr( $opt ) . '" value="1" ' . $checked . ' /> ';
            echo '<strong>' . esc_html( $row['label'] ) . '</strong></label><br /><span class="description">' . esc_html( $row['hint'] ) . '</span></p>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__( 'Offer updates: only checked fields are sent on each sync. Creating a new offer still sends price, stock, and title once (bol.com requires them). Catalog content uploads follow the title, description, and image toggles.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_product_sync_time(): void {
        $val = esc_attr( Mapping_Config::get_product_sync_time() );
        echo '<input type="time" step="60" id="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_TIME ) . '" name="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_TIME ) . '" value="' . $val . '" class="wbs-time-input" />';
        echo '<p class="description">' . esc_html__( 'Used for daily, weekly, and monthly batch runs (site timezone).', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_product_sync_weekday(): void {
        $cur = Mapping_Config::get_product_sync_weekday();
        echo '<select id="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_WEEKDAY ) . '" name="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_WEEKDAY ) . '">';
        foreach ( self::weekday_choices() as $dow => $label ) {
            echo '<option value="' . (int) $dow . '"' . selected( $cur, (int) $dow, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    /** @return void */
    public function field_product_sync_monthday(): void {
        $val = Mapping_Config::get_product_sync_monthday();
        echo '<input type="number" min="1" max="28" class="small-text" id="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_MONTHDAY ) . '" name="' . esc_attr( Mapping_Config::OPTION_PRODUCT_SYNC_MONTHDAY ) . '" value="' . (int) $val . '" />';
        echo '<p class="description">' . esc_html__( '1–28 (capped so every month is valid).', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_order_sync_mode(): void {
        $cur = Mapping_Config::get_order_sync_mode();
        $opts = [
            Mapping_Config::SYNC_MODE_DAILY   => __( 'Daily', 'woo-bol-sync' ),
            Mapping_Config::SYNC_MODE_WEEKLY  => __( 'Weekly', 'woo-bol-sync' ),
            Mapping_Config::SYNC_MODE_MONTHLY => __( 'Monthly', 'woo-bol-sync' ),
        ];
        echo '<select id="' . esc_attr( Mapping_Config::OPTION_ORDER_SYNC_MODE ) . '" name="' . esc_attr( Mapping_Config::OPTION_ORDER_SYNC_MODE ) . '">';
        foreach ( $opts as $v => $label ) {
            echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    /** @return void */
    public function field_order_sync_time(): void {
        $val = esc_attr( Mapping_Config::get_order_sync_time() );
        echo '<input type="time" step="60" id="' . esc_attr( Mapping_Config::OPTION_ORDER_SYNC_TIME ) . '" name="' . esc_attr( Mapping_Config::OPTION_ORDER_SYNC_TIME ) . '" value="' . $val . '" class="wbs-time-input" />';
        echo '<p class="description">' . esc_html__( 'Used for daily, weekly, and monthly runs (site timezone).', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_order_sync_weekday(): void {
        $cur = Mapping_Config::get_order_sync_weekday();
        echo '<select id="' . esc_attr( Mapping_Config::OPTION_ORDER_SYNC_WEEKDAY ) . '" name="' . esc_attr( Mapping_Config::OPTION_ORDER_SYNC_WEEKDAY ) . '">';
        foreach ( self::weekday_choices() as $dow => $label ) {
            echo '<option value="' . (int) $dow . '"' . selected( $cur, (int) $dow, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    /** @return void */
    public function field_order_sync_monthday(): void {
        $val = Mapping_Config::get_order_sync_monthday();
        echo '<input type="number" min="1" max="28" class="small-text" id="' . esc_attr( Mapping_Config::OPTION_ORDER_SYNC_MONTHDAY ) . '" name="' . esc_attr( Mapping_Config::OPTION_ORDER_SYNC_MONTHDAY ) . '" value="' . (int) $val . '" />';
        echo '<p class="description">' . esc_html__( '1–28 (capped so every month is valid).', 'woo-bol-sync' ) . '</p>';
    }

    /**
     * @return array<int, string> 0 = Sunday … 6 = Saturday (PHP date('w')).
     */
    private static function weekday_choices(): array {
        return [
            0 => __( 'Sunday', 'woo-bol-sync' ),
            1 => __( 'Monday', 'woo-bol-sync' ),
            2 => __( 'Tuesday', 'woo-bol-sync' ),
            3 => __( 'Wednesday', 'woo-bol-sync' ),
            4 => __( 'Thursday', 'woo-bol-sync' ),
            5 => __( 'Friday', 'woo-bol-sync' ),
            6 => __( 'Saturday', 'woo-bol-sync' ),
        ];
    }

    /** @return void */
    public function field_debug_mode(): void {
        $checked = checked( 1, (int) get_option( 'wbs_debug_mode', 0 ), false );
        echo '<label for="wbs_debug_mode"><input type="checkbox" id="wbs_debug_mode" name="wbs_debug_mode" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Log all API requests and responses to the database', 'woo-bol-sync' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Useful while you are setting things up or chasing a problem: every request the plugin sends to bol.com (and the response it gets back) is captured under "Bol Sync → Logs" together with the URL, headers, and payload. It writes a lot of data, so we recommend turning it back off in production once everything is running smoothly to keep your database lean.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_log_retention(): void {
        $val = (int) get_option( 'wbs_log_retention_days', 30 );
        echo '<input type="number" id="wbs_log_retention_days" name="wbs_log_retention_days" value="' . $val . '" min="1" max="365" class="small-text" /> ';
        echo esc_html__( 'days', 'woo-bol-sync' );
        echo '<p class="description">' . esc_html__( 'How long log entries are kept before the daily cleanup cron deletes them. 30 days is plenty for most shops; lower it (e.g. 7) on busy stores to save database space, or raise it (e.g. 90) when you need a longer audit trail. Counts every level — info, warning, and error — equally.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_default_fulfilment(): void {
        $cur = Mapping_Config::get_default_fulfilment_method();
        echo '<select id="wbs_default_fulfilment_method" name="' . esc_attr( Mapping_Config::OPTION_FULFILMENT ) . '">';
        echo '<option value="FBR"' . selected( $cur, 'FBR', false ) . '>' . esc_html__( 'FBR (fulfilled by retailer)', 'woo-bol-sync' ) . '</option>';
        echo '<option value="FBB"' . selected( $cur, 'FBB', false ) . '>' . esc_html__( 'FBB (fulfilled by bol)', 'woo-bol-sync' ) . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'New offers are usually FBR unless bol fulfils stock for you.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_default_delivery(): void {
        $val = esc_attr( Mapping_Config::get_default_delivery_code() );
        echo '<input type="text" id="wbs_default_delivery_code" name="wbs_default_delivery_code" value="' . $val . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Offer API v10 FBR only (v11 uses your dashboard delivery promise when using MY_DELIVERY_PROMISE). Default matches a 1–2 day window; confirm the code in bol.com API docs. Example: 1-2d.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_default_brand(): void {
        $val = esc_attr( (string) get_option( Mapping_Config::OPTION_DEFAULT_BRAND, '' ) );
        echo '<input type="text" id="' . esc_attr( Mapping_Config::OPTION_DEFAULT_BRAND ) . '" name="' . esc_attr( Mapping_Config::OPTION_DEFAULT_BRAND ) . '" value="' . $val . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Sent in the bol.com Brand attribute. Leave empty to omit. Filterable via wbs_default_brand for per-product overrides.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_sync_publish_only(): void {
        echo '<input type="hidden" name="wbs_sync_only_published" value="0" />';
        $checked = checked( 1, (int) get_option( Mapping_Config::OPTION_SYNC_PUBLISH, 1 ), false );
        echo '<label for="wbs_sync_only_published"><input type="checkbox" id="wbs_sync_only_published" name="wbs_sync_only_published" value="1" ' . $checked . ' /> ';
        echo esc_html__( 'Only sync products in Published status', 'woo-bol-sync' ) . '</label>';
    }

    /** @return void */
    public function field_exclude_categories(): void {
        $val = implode( ',', Mapping_Config::get_excluded_category_ids() );
        echo '<input type="text" class="large-text" id="wbs_exclude_category_ids" name="wbs_exclude_category_ids" value="' . esc_attr( $val ) . '" placeholder="12,34" />';
        echo '<p class="description">' . esc_html__( 'Comma-separated WooCommerce product category term IDs to exclude from bol.com sync.', 'woo-bol-sync' ) . '</p>';
    }

    /** @return void */
    public function field_map_sources(): void {
        $map      = Mapping_Config::get_field_map();
        $choices  = self::field_source_choices();
        $keys     = [ 'ean_source' => __( 'EAN / GTIN', 'woo-bol-sync' ), 'title_source' => __( 'Listing title', 'woo-bol-sync' ), 'description_source' => __( 'Description (title helper)', 'woo-bol-sync' ) ];
        foreach ( $keys as $key => $label ) {
            $current = $map[ $key ] ?? '';
            $active_choices = $choices;
            if ( $key === 'description_source' ) {
                $active_choices = self::description_source_choices();
            }
            echo '<p><label for="wbs_fm_' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br />';
            echo '<select id="wbs_fm_' . esc_attr( $key ) . '" name="wbs_field_map[' . esc_attr( $key ) . ']">';
            foreach ( $active_choices as $val => $text ) {
                echo '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $text ) . '</option>';
            }
            echo '</select></p>';
        }
    }

    /**
     * @return array<string, string>
     */
    private static function field_source_choices(): array {
        return [
            'sku'                => __( 'Product SKU', 'woo-bol-sync' ),
            'product_name'       => __( 'Product name', 'woo-bol-sync' ),
            'short_description'  => __( 'Short description', 'woo-bol-sync' ),
            'description'        => __( 'Long description', 'woo-bol-sync' ),
            'short_long'         => __( 'Short description then long description', 'woo-bol-sync' ),
            'long_short'         => __( 'Long description then short description', 'woo-bol-sync' ),
            'meta:_alg_ean'      => __( 'Meta: _alg_ean (common EAN plugin)', 'woo-bol-sync' ),
            'meta:_global_unique_id' => __( 'Meta: _global_unique_id (WooCommerce GTIN / UPC / EAN)', 'woo-bol-sync' ),
            'meta:_wbs_gtin'     => __( 'Meta: _wbs_gtin', 'woo-bol-sync' ),
            'attribute:pa_ean'   => __( 'Attribute: pa_ean', 'woo-bol-sync' ),
            'attribute:pa_gtin'  => __( 'Attribute: pa_gtin', 'woo-bol-sync' ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function description_source_choices(): array {
        return [
            'short_description' => __( 'Short description', 'woo-bol-sync' ),
            'description'       => __( 'Long description', 'woo-bol-sync' ),
            'short_long'        => __( 'Short description then long description', 'woo-bol-sync' ),
            'long_short'        => __( 'Long description then short description', 'woo-bol-sync' ),
        ];
    }

    // ── Sanitization ──────────────────────────────────────────────────────────

    /** @param mixed $v @return string */
    public function sanitize_secret( $v ): string {
        return sanitize_text_field( trim( (string) $v ) );
    }

    /** @param mixed $v @return string */
    public function sanitize_fulfilment_method( $v ): string {
        $v = strtoupper( sanitize_text_field( (string) $v ) );
        return in_array( $v, [ 'FBR', 'FBB' ], true ) ? $v : 'FBR';
    }

    /** @param mixed $v @return int */
    public function sanitize_batch_size( $v ): int {
        $old = (int) get_option( 'wbs_sync_batch_size', 25 );
        $new = min( 100, max( 1, (int) $v ) );
        if ( $old !== $new ) {
            delete_option( Product_Sync_Service::OPTION_SYNC_CATALOG_OFFSET );
        }
        return $new;
    }

    /** @param mixed $v @return int */
    public function sanitize_rate_delay( $v ): int {
        return min( 5000, max( 0, (int) $v ) );
    }

    /** @param mixed $v @return int */
    public function sanitize_retry_count( $v ): int {
        return min( 4, max( 0, (int) $v ) );
    }

    /** @param mixed $v @return string */
    public function sanitize_offer_media_type( $v ): string {
        $v = trim( (string) $v );
        return in_array( $v, [ 'application/vnd.retailer.v10+json', 'application/vnd.retailer.v11+json' ], true )
            ? $v
            : 'application/vnd.retailer.v10+json';
    }

    /** @param mixed $v @return string */
    public function sanitize_product_sync_mode( $v ): string {
        $v = sanitize_key( (string) $v );
        $allowed = [
            Mapping_Config::SYNC_MODE_DAILY,
            Mapping_Config::SYNC_MODE_WEEKLY,
            Mapping_Config::SYNC_MODE_MONTHLY,
            Mapping_Config::SYNC_MODE_WC_UPDATES,
        ];
        return in_array( $v, $allowed, true ) ? $v : Mapping_Config::SYNC_MODE_DAILY;
    }

    /** @param mixed $v @return string */
    public function sanitize_order_sync_mode( $v ): string {
        $v = sanitize_key( (string) $v );
        $allowed = [
            Mapping_Config::SYNC_MODE_DAILY,
            Mapping_Config::SYNC_MODE_WEEKLY,
            Mapping_Config::SYNC_MODE_MONTHLY,
        ];
        return in_array( $v, $allowed, true ) ? $v : Mapping_Config::SYNC_MODE_DAILY;
    }

    /** @param mixed $v @return string */
    public function sanitize_product_sync_time( $v ): string {
        return $this->sanitize_sync_time_hm( $v, '02:00' );
    }

    /** @param mixed $v @return string */
    public function sanitize_order_sync_time( $v ): string {
        return $this->sanitize_sync_time_hm( $v, '02:15' );
    }

    /**
     * @param mixed  $v
     * @param string $fallback
     */
    private function sanitize_sync_time_hm( $v, string $fallback ): string {
        $v = trim( (string) $v );
        if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m ) === 1 ) {
            $h = min( 23, max( 0, (int) $m[1] ) );
            $i = min( 59, max( 0, (int) $m[2] ) );
            return sprintf( '%02d:%02d', $h, $i );
        }
        return $fallback;
    }

    /** @param mixed $v @return int */
    public function sanitize_weekday( $v ): int {
        $w = (int) $v;
        return min( 6, max( 0, $w ) );
    }

    /** @param mixed $v @return int */
    public function sanitize_monthday( $v ): int {
        $d = (int) $v;
        return min( 28, max( 1, $d ) );
    }

    /** @param mixed $v @return string */
    public function sanitize_margin_type( $v ): string {
        $v = sanitize_key( (string) $v );
        return in_array( $v, [ 'none', 'percent', 'fixed' ], true ) ? $v : 'none';
    }

    /**
     * @param mixed $v @return string
     */
    public function sanitize_margin_value( $v ): string {
        $v = str_replace( ',', '.', trim( (string) $v ) );
        if ( ! is_numeric( $v ) ) {
            return '0';
        }
        $f = (float) $v;
        return (string) round( $f, 4 );
    }

    /** @param mixed $v @return int */
    public function sanitize_retention( $v ): int {
        return min( 365, max( 1, (int) $v ) );
    }

    /**
     * @param mixed $v Raw option value.
     * @return array<string, string>
     */
    public function sanitize_field_map_option( $v ): array {
        $defaults = Mapping_Config::default_field_map();
        if ( ! is_array( $v ) ) {
            return $defaults;
        }
        $out = $defaults;
        foreach ( array_keys( $defaults ) as $key ) {
            if ( isset( $v[ $key ] ) && is_string( $v[ $key ] ) ) {
                $allowed = array_keys( self::field_source_choices() );
                $val     = sanitize_text_field( $v[ $key ] );
                if ( str_starts_with( $val, 'meta:' ) || str_starts_with( $val, 'attribute:' ) || in_array( $val, $allowed, true ) ) {
                    $out[ $key ] = $val;
                }
            }
        }
        return $out;
    }

    /**
     * @param mixed $v Posted value.
     */
    public function sanitize_exclude_categories( $v ): string {
        $v = sanitize_text_field( (string) $v );
        $ids = array_filter( array_map( 'absint', explode( ',', $v ) ) );
        return implode( ',', array_values( array_unique( $ids ) ) );
    }

    /**
     * @return array<int, array{id:string, value:string}>
     */
    private static function parse_template_lines( string $raw ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
        $out   = [];
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' || str_starts_with( $line, '#' ) ) {
                continue;
            }
            if ( ! str_contains( $line, '=' ) ) {
                continue;
            }
            [ $id, $value ] = array_map( 'trim', explode( '=', $line, 2 ) );
            $id    = sanitize_text_field( $id );
            $value = sanitize_text_field( $value );
            if ( $id === '' || $value === '' ) {
                continue;
            }
            $out[] = [
                'id'    => $id,
                'value' => $value,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function dashboard_stats_payload(): array {
        return [
            'products' => \WooBolSync\Models\Product_Mapping::get_stats(),
            'orders'   => \WooBolSync\Models\Order_Mapping::get_stats(),
        ];
    }

    /**
     * Pull offer objects from bol list response (body or JSON-encoded summary).
     *
     * @param array<string, mixed> $offers_data
     * @return array<int, array<string, mixed>>
     */
    private function extract_offer_items_from_offers_data( array $offers_data ): array {
        $body = is_array( $offers_data['body'] ?? null ) ? $offers_data['body'] : [];
        foreach ( [ 'offers', 'results', 'items' ] as $list_key ) {
            if ( is_array( $body[ $list_key ] ?? null ) && $body[ $list_key ] !== [] ) {
                return array_values( array_filter( $body[ $list_key ], 'is_array' ) );
            }
        }

        $summary = trim( (string) ( $offers_data['summary'] ?? '' ) );
        if ( $summary !== '' ) {
            $decoded = json_decode( $summary, true );
            if ( is_array( $decoded ) ) {
                foreach ( [ 'offers', 'results', 'items' ] as $list_key ) {
                    if ( is_array( $decoded[ $list_key ] ?? null ) && $decoded[ $list_key ] !== [] ) {
                        return array_values( array_filter( $decoded[ $list_key ], 'is_array' ) );
                    }
                }
                if ( array_is_list( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
                    $first = $decoded[0];
                    if ( isset( $first['offerId'] ) || isset( $first['id'] ) ) {
                        return array_values( array_filter( $decoded, 'is_array' ) );
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, string>>
     */
    private function normalize_offers_for_admin_table( array $items ): array {
        $rows = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $offer_id = (string) ( $item['offerId'] ?? $item['id'] ?? '' );
            if ( $offer_id === '' ) {
                continue;
            }
            $ean   = preg_replace( '/\D/', '', (string) ( $item['ean'] ?? '' ) ) ?? '';
            $title = (string) ( $item['unknownProductTitle'] ?? $item['storeProductTitle'] ?? $item['title'] ?? '' );
            if ( $title === '' ) {
                $title = $ean !== '' ? sprintf( /* translators: %s: EAN */ __( 'EAN %s', 'woo-bol-sync' ), $ean ) : __( '(no title)', 'woo-bol-sync' );
            }

            $stock_raw = $item['stock'] ?? null;
            $stock     = '';
            if ( is_array( $stock_raw ) && isset( $stock_raw['amount'] ) && is_scalar( $stock_raw['amount'] ) ) {
                $stock = (string) (int) $stock_raw['amount'];
            } elseif ( is_scalar( $stock_raw ) ) {
                $stock = (string) (int) $stock_raw;
            }

            $price_val = null;
            $bp        = $item['pricing']['bundlePrices'] ?? null;
            if ( is_array( $bp ) && isset( $bp[0]['unitPrice'] ) && is_scalar( $bp[0]['unitPrice'] ) ) {
                $price_val = max( 0.0, (float) str_replace( ',', '.', (string) $bp[0]['unitPrice'] ) );
            }
            $price_label = '';
            if ( $price_val !== null ) {
                $price_label = function_exists( 'wc_price' )
                    ? wp_strip_all_tags( wc_price( $price_val ) )
                    : number_format_i18n( $price_val, 2 );
            }

            $condition   = '';
            $cond_raw    = $item['condition'] ?? null;
            if ( is_array( $cond_raw ) && isset( $cond_raw['name'] ) ) {
                $condition = (string) $cond_raw['name'];
            } elseif ( is_string( $cond_raw ) ) {
                $condition = $cond_raw;
            }

            $fulfil = '';
            if ( is_array( $item['fulfilment'] ?? null ) && isset( $item['fulfilment']['method'] ) ) {
                $fulfil = (string) $item['fulfilment']['method'];
            }

            $reference = (string) ( $item['reference'] ?? $item['retailerOfferId'] ?? '' );

            $image_url = (string) ( $item['imageUrl'] ?? $item['image']['url'] ?? '' );
            if ( $image_url === '' && $ean !== '' ) {
                $image_url = $this->wc_thumbnail_url_for_mapped_ean( $ean );
            }

            $on_hold = isset( $item['onHoldByRetailer'] ) && (bool) $item['onHoldByRetailer'];

            $for_sale = null;
            if ( array_key_exists( '_wbs_for_sale', $item ) ) {
                $for_sale = (bool) $item['_wbs_for_sale'];
            } elseif ( isset( $item['forSale'] ) && is_bool( $item['forSale'] ) ) {
                $for_sale = $item['forSale'];
            } else {
                $for_sale = true;
            }

            $status_parts = [];
            if ( $for_sale ) {
                $status_parts[] = __( 'For sale', 'woo-bol-sync' );
                $sale_badge_kind = 'for_sale';
            } else {
                $status_parts[] = __( 'Not for sale', 'woo-bol-sync' );
                $sale_badge_kind = 'not_for_sale';
            }
            if ( $on_hold ) {
                $status_parts[] = __( 'On hold', 'woo-bol-sync' );
            }
            $status_display = implode( ' · ', $status_parts );

            $rows[] = [
                'offer_id'       => $offer_id,
                'ean'            => $ean,
                'title'          => $title,
                'stock'          => $stock,
                'price'          => $price_label,
                'condition'      => $condition,
                'fulfilment'     => $fulfil,
                'reference'      => $reference,
                'image_url'      => $image_url,
                'status_display' => $status_display,
                'sale_badge'     => $sale_badge_kind,
                'on_hold'        => $on_hold ? '1' : '0',
            ];
        }

        return $rows;
    }

    private function wc_thumbnail_url_for_mapped_ean( string $ean ): string {
        if ( $ean === '' || ! function_exists( 'wc_get_product' ) ) {
            return '';
        }
        $row = Product_Mapping::get_by_ean( $ean );
        if ( ! is_array( $row ) ) {
            return '';
        }
        $pid = (int) ( $row['wc_product_id'] ?? 0 );
        if ( $pid < 1 ) {
            return '';
        }
        $product = wc_get_product( $pid );
        if ( ! $product ) {
            return '';
        }
        $image_id = (int) $product->get_image_id();
        if ( $image_id < 1 ) {
            return '';
        }
        $url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
        return is_string( $url ) ? $url : '';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Verify AJAX nonce + capability, then die on failure.
     *
     * @return void
     */
    private function verify_ajax(): void {
        check_ajax_referer( 'wbs_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-bol-sync' ) ], 403 );
        }
    }

    /**
     * Abort if the current user cannot manage WooCommerce.
     *
     * @return void
     */
    private function require_capability(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-bol-sync' ) );
        }
    }
}
