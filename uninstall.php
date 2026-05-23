<?php
/**
 * Uninstall WooCommerce Bol.com Sync.
 *
 * Executed by WordPress when the user clicks "Delete" on the plugins screen.
 * Removes ALL plugin data: tables, options, transients, and scheduled events.
 *
 * Idempotent: safe to invoke repeatedly.
 *
 * @package WooBolSync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── Drop tables ───────────────────────────────────────────────────────────────

$tables = [
    // Core mapping & logging.
    $wpdb->prefix . 'wbs_product_mapping',
    $wpdb->prefix . 'wbs_order_mapping',
    $wpdb->prefix . 'wbs_category_mapping',
    $wpdb->prefix . 'wbs_logs',
    // Staging / review pipeline.
    $wpdb->prefix . 'wbs_sync_batches',
    $wpdb->prefix . 'wbs_product_sync_draft',
    $wpdb->prefix . 'wbs_variation_sync_draft',
    $wpdb->prefix . 'wbs_sync_job',
    $wpdb->prefix . 'wbs_sync_job_item',
    $wpdb->prefix . 'wbs_sync_audit',
    // Legacy table from v1.
    $wpdb->prefix . 'wbs_offer_map',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// ── Delete options ────────────────────────────────────────────────────────────

$options = [
    // Credentials & licensing.
    'wbs_client_id',
    'wbs_client_secret',
    'wbs_license_key',
    'wbs_license_status',
    // Sync behaviour.
    'wbs_debug_mode',
    'wbs_rate_limit_delay',
    'wbs_log_retention_days',
    'wbs_smart_sync',
    'wbs_sync_batch_size',
    'wbs_api_retry_count',
    'wbs_offer_media_type',
    'wbs_auto_recover_stale_offers',
    'wbs_sync_catalog_offset',
    'wbs_product_sync_query_offset',
    // Schema & migrations.
    'wbs_db_version',
    'wbs_sync_scheduler_v2',
    'wbs_sync_scheduler_v3',
    // Mapping & listing defaults.
    'wbs_field_map',
    'wbs_default_delivery_code',
    'wbs_default_fulfilment_method',
    'wbs_default_brand',
    'wbs_sync_only_published',
    'wbs_exclude_category_ids',
    'wbs_price_margin_type',
    'wbs_price_margin_value',
    // Economic operator.
    'wbs_economic_operator_id',
    'wbs_economic_operator_name',
    'wbs_economic_operator_status',
    'wbs_economic_operator_last_sync',
    // Schedules.
    'wbs_product_sync_mode',
    'wbs_product_sync_enabled',
    'wbs_allow_new_offers',
    'wbs_sync_product_content_to_bol',
    'wbs_sync_offer_price_to_bol',
    'wbs_sync_offer_stock_to_bol',
    'wbs_sync_content_name_to_bol',
    'wbs_sync_content_description_to_bol',
    'wbs_sync_content_images_to_bol',
    'wbs_sync_granular_migrated_v2',
    'wbs_product_sync_time',
    'wbs_product_sync_weekday',
    'wbs_product_sync_monthday',
    'wbs_order_sync_mode',
    'wbs_order_sync_time',
    'wbs_order_sync_weekday',
    'wbs_order_sync_monthday',
    'wbs_order_sync_interval_minutes',
    'wbs_order_sync_last_run',
    'wbs_last_order_sync_at',
    // Webhook.
    'wbs_webhook_enabled',
    'wbs_webhook_subscription_id',
    'wbs_webhook_signing_required',
    'wbs_webhook_shared_secret',
    'wbs_webhook_signature_keys',
    'wbs_webhook_signature_keys_fetched_at',
    // Connection diagnostics.
    'wbs_connection_status',
    'wbs_connection_message',
    'wbs_connection_last_tested_at',
    // Staging.
    'wbs_staging_mode_enabled',
    'wbs_staging_auto_ingest',
    'wbs_staging_sync_enabled',
];

foreach ( $options as $option ) {
    delete_option( $option );
    delete_site_option( $option );
}

// ── Delete transients ─────────────────────────────────────────────────────────

delete_transient( 'wbs_bol_access_token' );
delete_transient( 'wbs_license_valid' );
delete_transient( 'wbs_signature_keys' );

// Catch-all: any remaining wbs_* transients (debounce keys, dedup keys, rate-limit windows).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wbs_%' OR option_name LIKE '_transient_timeout_wbs_%'" );

if ( is_multisite() ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_wbs_%' OR meta_key LIKE '_site_transient_timeout_wbs_%'" );
}

// ── Unschedule cron events ────────────────────────────────────────────────────

foreach (
    [
        'wbs_cron_sync_products',
        'wbs_cron_sync_orders',
        'wbs_cron_ensure_subscription',
        'wbs_cron_purge_logs',
    ] as $hook
) {
    wp_clear_scheduled_hook( $hook );
}
