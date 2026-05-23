<?php
/**
 * Plugin activation: database tables, defaults, cron schedules.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on register_activation_hook.
 */
final class Activator {

    /**
     * Create/update custom tables and schedule crons.
     *
     * @return void
     */
    public static function activate(): void {
        global $wpdb;

        self::create_tables();
        self::set_defaults();
        self::schedule_crons();

        self::clear_plugin_transient_cache();
        update_option( 'wbs_db_version', WBS_VERSION );
    }

    /**
     * Run dbDelta when the plugin version bumps (existing installs).
     */
    public static function maybe_upgrade(): void {
        $db_ver = (string) get_option( 'wbs_db_version', '' );
        if ( $db_ver === '' || version_compare( $db_ver, WBS_VERSION, '<' ) ) {
            self::clear_plugin_transient_cache();
        }
        if (
            version_compare( $db_ver, WBS_VERSION, '>=' )
            && self::table_has_column( WBS_CATEGORY_MAP, 'template' )
            && self::table_exists( WBS_STAGING_PRODUCT_DRAFT )
            && self::table_exists( WBS_STAGING_VARIATION_DRAFT )
            && self::table_exists( WBS_STAGING_BATCHES )
            && self::table_exists( WBS_STAGING_JOB )
            && self::table_exists( WBS_STAGING_JOB_ITEM )
            && self::table_exists( WBS_STAGING_AUDIT )
            && self::table_has_column( WBS_STAGING_PRODUCT_DRAFT, 'sync_content' )
            && self::table_has_column( WBS_STAGING_PRODUCT_DRAFT, 'bol_offer_snapshot_json' )
        ) {
            return;
        }
        self::create_tables();
        self::set_defaults();
        if ( class_exists( 'WooCommerce' ) ) {
            Sync_Scheduler::apply_product_schedule();
            Sync_Scheduler::apply_order_schedule();
        }
        update_option( 'wbs_db_version', WBS_VERSION );
    }

    /**
     * Drop all wbs_* transients (API token cache, license cache, debounce keys, etc.).
     *
     * @return void
     */
    public static function clear_plugin_transient_cache(): void {
        global $wpdb;

        delete_transient( 'wbs_bol_access_token' );
        delete_transient( 'wbs_license_valid' );
        delete_transient( 'wbs_signature_keys' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name; pattern is fixed.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wbs_%' OR option_name LIKE '_transient_timeout_wbs_%'" );

        if ( is_multisite() ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_wbs_%' OR meta_key LIKE '_site_transient_timeout_wbs_%'" );
        }
    }

    /**
     * @return void
     */
    private static function create_tables(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix;

        $logs = "CREATE TABLE {$prefix}wbs_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) NOT NULL DEFAULT 'info',
			context varchar(64) NOT NULL DEFAULT 'general',
			message text NOT NULL,
			data longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY level (level),
			KEY context (context),
			KEY created_at (created_at)
		) $charset_collate;";

        $product_map = "CREATE TABLE {$prefix}wbs_product_mapping (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			bol_offer_id varchar(128) NOT NULL DEFAULT '',
			bol_ean varchar(32) NOT NULL DEFAULT '',
			sync_hash varchar(64) NOT NULL DEFAULT '',
			meta longtext NULL,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY wc_product_id (wc_product_id),
			KEY bol_offer_id (bol_offer_id),
			KEY bol_ean (bol_ean)
		) $charset_collate;";

        $order_map = "CREATE TABLE {$prefix}wbs_order_mapping (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			bol_order_id varchar(128) NOT NULL DEFAULT '',
			status varchar(64) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY wc_order_id (wc_order_id),
			KEY bol_order_id (bol_order_id)
		) $charset_collate;";

        $category_map = "CREATE TABLE {$prefix}wbs_category_mapping (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
			bol_category_id varchar(128) NOT NULL DEFAULT '',
			template longtext NULL,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY wc_term_id (wc_term_id),
			KEY bol_category_id (bol_category_id)
		) $charset_collate;";

        $staging_batches = "CREATE TABLE {$prefix}wbs_sync_batches (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(191) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'open',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			counts longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

        $staging_product_draft = "CREATE TABLE {$prefix}wbs_product_sync_draft (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
			wc_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_type varchar(32) NOT NULL DEFAULT 'simple',
			sku varchar(100) NOT NULL DEFAULT '',
			ean varchar(32) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			short_description longtext NULL,
			description longtext NULL,
			regular_price decimal(18,4) NULL,
			sale_price decimal(18,4) NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			stock_quantity int(11) NULL,
			stock_status varchar(32) NOT NULL DEFAULT '',
			manage_stock tinyint(1) NOT NULL DEFAULT 0,
			main_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			main_image_url text NULL,
			gallery_json longtext NULL,
			categories_json longtext NULL,
			tags_json longtext NULL,
			mapped_payload_json longtext NULL,
			admin_overrides_json longtext NULL,
			final_payload_json longtext NULL,
			sync_price tinyint(1) NOT NULL DEFAULT 1,
			sync_stock tinyint(1) NOT NULL DEFAULT 1,
			sync_content tinyint(1) NOT NULL DEFAULT 0,
			sync_images tinyint(1) NOT NULL DEFAULT 0,
			bol_offer_snapshot_json longtext NULL,
			bol_content_snapshot_json longtext NULL,
			validation_status varchar(32) NOT NULL DEFAULT 'pending',
			validation_errors_json longtext NULL,
			validation_warnings_json longtext NULL,
			review_status varchar(32) NOT NULL DEFAULT 'pending',
			approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			approved_at datetime NULL,
			sync_status varchar(32) NOT NULL DEFAULT 'not_synced',
			last_sync_error text NULL,
			last_synced_at datetime NULL,
			version int(11) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY wc_product_id (wc_product_id),
			KEY batch_id (batch_id),
			KEY validation_status (validation_status),
			KEY review_status (review_status),
			KEY sync_status (sync_status),
			KEY ean (ean)
		) $charset_collate;";

        $staging_variation_draft = "CREATE TABLE {$prefix}wbs_variation_sync_draft (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_draft_id bigint(20) unsigned NOT NULL DEFAULT 0,
			wc_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			wc_parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku varchar(100) NOT NULL DEFAULT '',
			ean varchar(32) NOT NULL DEFAULT '',
			attributes_json longtext NULL,
			attributes_signature varchar(191) NOT NULL DEFAULT '',
			regular_price decimal(18,4) NULL,
			sale_price decimal(18,4) NULL,
			stock_quantity int(11) NULL,
			stock_status varchar(32) NOT NULL DEFAULT '',
			manage_stock tinyint(1) NOT NULL DEFAULT 0,
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			image_url text NULL,
			description longtext NULL,
			mapped_payload_json longtext NULL,
			admin_overrides_json longtext NULL,
			final_payload_json longtext NULL,
			validation_status varchar(32) NOT NULL DEFAULT 'pending',
			validation_errors_json longtext NULL,
			validation_warnings_json longtext NULL,
			sync_status varchar(32) NOT NULL DEFAULT 'not_synced',
			last_sync_error text NULL,
			last_synced_at datetime NULL,
			version int(11) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY wc_variation_id (wc_variation_id),
			KEY product_draft_id (product_draft_id),
			KEY wc_parent_id (wc_parent_id),
			KEY attributes_signature (attributes_signature),
			KEY validation_status (validation_status),
			KEY sync_status (sync_status)
		) $charset_collate;";

        $staging_job = "CREATE TABLE {$prefix}wbs_sync_job (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'queued',
			triggered_by bigint(20) unsigned NOT NULL DEFAULT 0,
			counts longtext NULL,
			message text NULL,
			started_at datetime NULL,
			ended_at datetime NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

        $staging_job_item = "CREATE TABLE {$prefix}wbs_sync_job_item (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			draft_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entity_type varchar(32) NOT NULL DEFAULT 'product',
			status varchar(32) NOT NULL DEFAULT 'pending',
			request_payload longtext NULL,
			response_payload longtext NULL,
			error_message text NULL,
			started_at datetime NULL,
			ended_at datetime NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY draft_id (draft_id),
			KEY entity_type (entity_type),
			KEY status (status)
		) $charset_collate;";

        $staging_audit = "CREATE TABLE {$prefix}wbs_sync_audit (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_type varchar(32) NOT NULL DEFAULT 'product_draft',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field_name varchar(100) NOT NULL DEFAULT '',
			old_value longtext NULL,
			new_value longtext NULL,
			changed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			changed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			note varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY entity (entity_type, entity_id),
			KEY changed_at (changed_at)
		) $charset_collate;";

        dbDelta( $logs );
        dbDelta( $product_map );
        dbDelta( $order_map );
        dbDelta( $category_map );
        dbDelta( $staging_batches );
        dbDelta( $staging_product_draft );
        dbDelta( $staging_variation_draft );
        dbDelta( $staging_job );
        dbDelta( $staging_job_item );
        dbDelta( $staging_audit );
    }

    /**
     * @return void
     */
    private static function set_defaults(): void {
        if ( false === get_option( 'wbs_smart_sync', false ) ) {
            add_option( 'wbs_smart_sync', 1 );
        }
        if ( false === get_option( 'wbs_sync_batch_size', false ) ) {
            add_option( 'wbs_sync_batch_size', 25 );
        }
        if ( false === get_option( 'wbs_rate_limit_delay', false ) ) {
            add_option( 'wbs_rate_limit_delay', 500 );
        }
        if ( false === get_option( 'wbs_api_retry_count', false ) ) {
            add_option( 'wbs_api_retry_count', 2 );
        }
        if ( false === get_option( Mapping_Config::OPTION_OFFER_MEDIA_TYPE, false ) ) {
            add_option( Mapping_Config::OPTION_OFFER_MEDIA_TYPE, 'application/vnd.retailer.v10+json' );
        }
        if ( false === get_option( 'wbs_debug_mode', false ) ) {
            add_option( 'wbs_debug_mode', 0 );
        }
        if ( false === get_option( 'wbs_log_retention_days', false ) ) {
            add_option( 'wbs_log_retention_days', 30 );
        }
        if ( false === get_option( Mapping_Config::OPTION_FIELD_MAP, false ) ) {
            add_option( Mapping_Config::OPTION_FIELD_MAP, Mapping_Config::default_field_map() );
        }
        if ( false === get_option( Mapping_Config::OPTION_SYNC_PUBLISH, false ) ) {
            add_option( Mapping_Config::OPTION_SYNC_PUBLISH, 1 );
        }
        if ( false === get_option( Mapping_Config::OPTION_DELIVERY, false ) ) {
            add_option( Mapping_Config::OPTION_DELIVERY, Mapping_Config::DEFAULT_V10_DELIVERY_CODE );
        }
        if ( false === get_option( Mapping_Config::OPTION_FULFILMENT, false ) ) {
            add_option( Mapping_Config::OPTION_FULFILMENT, 'FBR' );
        }
        if ( false === get_option( Mapping_Config::OPTION_MARGIN_TYPE, false ) ) {
            add_option( Mapping_Config::OPTION_MARGIN_TYPE, 'none' );
        }
        if ( false === get_option( Mapping_Config::OPTION_MARGIN_VALUE, false ) ) {
            add_option( Mapping_Config::OPTION_MARGIN_VALUE, '0' );
        }
        if ( false === get_option( Mapping_Config::OPTION_WEBHOOK_ENABLED, false ) ) {
            add_option( Mapping_Config::OPTION_WEBHOOK_ENABLED, 1 );
        }
        if ( false === get_option( Mapping_Config::OPTION_WEBHOOK_SUBSCRIPTION_ID, false ) ) {
            add_option( Mapping_Config::OPTION_WEBHOOK_SUBSCRIPTION_ID, '' );
        }
        if ( false === get_option( Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED, false ) ) {
            add_option( Mapping_Config::OPTION_WEBHOOK_SIGNING_REQUIRED, 1 );
        }
        if ( false === get_option( Mapping_Config::OPTION_WEBHOOK_SHARED_SECRET, false ) ) {
            add_option( Mapping_Config::OPTION_WEBHOOK_SHARED_SECRET, '' );
        }
        if ( false === get_option( Mapping_Config::OPTION_DEFAULT_BRAND, false ) ) {
            add_option( Mapping_Config::OPTION_DEFAULT_BRAND, '' );
        }
        if ( false === get_option( Mapping_Config::OPTION_LAST_ORDER_SYNC, false ) ) {
            add_option( Mapping_Config::OPTION_LAST_ORDER_SYNC, '' );
        }
        if ( false === get_option( Mapping_Config::OPTION_CONNECTION_STATUS, false ) ) {
            add_option( Mapping_Config::OPTION_CONNECTION_STATUS, 'unknown' );
        }
        if ( false === get_option( Mapping_Config::OPTION_CONNECTION_MESSAGE, false ) ) {
            add_option( Mapping_Config::OPTION_CONNECTION_MESSAGE, '' );
        }
        if ( false === get_option( Mapping_Config::OPTION_CONNECTION_LAST_TESTED, false ) ) {
            add_option( Mapping_Config::OPTION_CONNECTION_LAST_TESTED, '' );
        }
        if ( false === get_option( Mapping_Config::OPTION_PRODUCT_SYNC_MODE, false ) ) {
            add_option( Mapping_Config::OPTION_PRODUCT_SYNC_MODE, Mapping_Config::SYNC_MODE_DAILY );
        }
        if ( false === get_option( Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED, false ) ) {
            add_option( Mapping_Config::OPTION_PRODUCT_SYNC_ENABLED, 1 );
        }
        if ( false === get_option( Mapping_Config::OPTION_ALLOW_NEW_OFFERS, false ) ) {
            add_option( Mapping_Config::OPTION_ALLOW_NEW_OFFERS, 1 );
        }
        if ( false === get_option( Mapping_Config::OPTION_SYNC_PRODUCT_CONTENT, false ) ) {
            add_option( Mapping_Config::OPTION_SYNC_PRODUCT_CONTENT, 1 );
        }
        if ( false === get_option( Mapping_Config::OPTION_PRODUCT_SYNC_TIME, false ) ) {
            add_option( Mapping_Config::OPTION_PRODUCT_SYNC_TIME, '02:00' );
        }
        if ( false === get_option( Mapping_Config::OPTION_PRODUCT_SYNC_WEEKDAY, false ) ) {
            add_option( Mapping_Config::OPTION_PRODUCT_SYNC_WEEKDAY, 1 );
        }
        if ( false === get_option( Mapping_Config::OPTION_PRODUCT_SYNC_MONTHDAY, false ) ) {
            add_option( Mapping_Config::OPTION_PRODUCT_SYNC_MONTHDAY, 1 );
        }
        if ( false === get_option( Mapping_Config::OPTION_ORDER_SYNC_INTERVAL, false ) ) {
            add_option(
                Mapping_Config::OPTION_ORDER_SYNC_INTERVAL,
                Mapping_Config::DEFAULT_ORDER_SYNC_INTERVAL_MINUTES
            );
        }
        if ( false === get_option( Mapping_Config::OPTION_STAGING_ENABLED, false ) ) {
            add_option( Mapping_Config::OPTION_STAGING_ENABLED, 0 );
        }
        if ( false === get_option( Mapping_Config::OPTION_STAGING_AUTO_INGEST, false ) ) {
            add_option( Mapping_Config::OPTION_STAGING_AUTO_INGEST, 0 );
        }
    }

    /**
     * Schedule plugin cron events when WooCommerce is available.
     *
     * Activation may run while WooCommerce is still inactive (single-plugin
     * activations on stores being set up). In that case we install only the
     * recurring housekeeping events; sync schedules are applied later by
     * {@see Plugin::bootstrap()} once WooCommerce is loaded.
     */
    private static function schedule_crons(): void {
        if ( class_exists( 'WooCommerce' ) ) {
            Sync_Scheduler::apply_product_schedule();
            Sync_Scheduler::apply_order_schedule();
            update_option( Sync_Scheduler::OPTION_MIGRATED, '1' );
        }
        if ( ! wp_next_scheduled( 'wbs_cron_ensure_subscription' ) ) {
            wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'twicedaily', 'wbs_cron_ensure_subscription' );
        }
        if ( ! wp_next_scheduled( 'wbs_cron_purge_logs' ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'wbs_cron_purge_logs' );
        }
    }

    private static function table_has_column( string $table_suffix, string $column ): bool {
        global $wpdb;
        $table = $wpdb->prefix . $table_suffix;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                $column
            )
        );
        return is_string( $exists ) && $exists !== '';
    }

    private static function table_exists( string $table_suffix ): bool {
        global $wpdb;
        $table = $wpdb->prefix . $table_suffix;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );
        return is_string( $found ) && $found !== '';
    }
}
