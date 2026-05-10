<?php
/**
 * Dashboard admin page view.
 *
 * Variables:
 *   @var \WooBolSync\Services\Bol_API_Service $api
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;

use WooBolSync\Includes\License_Manager;
use WooBolSync\Includes\Mapping_Config;
use WooBolSync\Models\Order_Mapping;
use WooBolSync\Models\Product_Mapping;

$has_credentials = $api->has_credentials();
$product_stats   = Product_Mapping::get_stats();
$order_stats     = Order_Mapping::get_stats();
$latest_failed_product = Product_Mapping::get_failed_rows( 1 );
$latest_failed_product = $latest_failed_product[0] ?? null;
$recent_ean_recreates = Product_Mapping::get_recent_ean_change_rows( 3 );
$license_active  = License_Manager::is_active();
$next_products   = wp_next_scheduled( 'wbs_cron_sync_products' );
$next_orders     = wp_next_scheduled( 'wbs_cron_sync_orders' );
$debug_on        = (bool) get_option( 'wbs_debug_mode', false );
$smart_sync_on   = (bool) get_option( 'wbs_smart_sync', true );
$subscription_id = Mapping_Config::get_subscription_id();
$connection_status = Mapping_Config::get_connection_status();
$connection_message = Mapping_Config::get_connection_message();
$connection_last_tested = Mapping_Config::get_connection_last_tested();
$eo_ready        = Mapping_Config::get_economic_operator_id() !== '';
$cats_mapped     = function_exists( 'get_terms' ) ? get_terms(
    [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'meta_query' => [],
    ]
) : [];
$setup_steps = [
    [
        'label'   => __( 'Enter bol.com API credentials', 'woo-bol-sync' ),
        'done'    => $has_credentials,
        'url'     => admin_url( 'admin.php?page=wbs-settings' ),
        'action'  => __( 'Open settings', 'woo-bol-sync' ),
    ],
    [
        'label'   => __( 'Fetch or create an economic operator', 'woo-bol-sync' ),
        'done'    => $eo_ready,
        'url'     => admin_url( 'admin.php?page=wbs-settings' ),
        'action'  => __( 'Manage operator', 'woo-bol-sync' ),
    ],
    [
        'label'   => __( 'Optional: map WooCommerce categories to bol.com categories', 'woo-bol-sync' ),
        'done'    => true,
        'url'     => admin_url( 'admin.php?page=wbs-categories' ),
        'action'  => __( 'Open optional mapping', 'woo-bol-sync' ),
    ],
    [
        'label'   => __( 'Review field mapping and defaults', 'woo-bol-sync' ),
        'done'    => true,
        'url'     => admin_url( 'admin.php?page=wbs-field-map' ),
        'action'  => __( 'Open field mapping', 'woo-bol-sync' ),
    ],
];
$setup_done = count( array_filter( $setup_steps, static fn( $step ) => ! empty( $step['done'] ) ) );
$setup_total = count( $setup_steps );

$fmt_next = static function ( $ts ) {
    if ( ! $ts ) {
        return __( 'Not scheduled', 'woo-bol-sync' );
    }
    return get_date_from_gmt(
        gmdate( 'Y-m-d H:i:s', $ts ),
        get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
    );
};

$product_next_label = $fmt_next( $next_products );
if ( Mapping_Config::get_product_sync_mode() === Mapping_Config::SYNC_MODE_WC_UPDATES ) {
    $product_next_label = __( 'Batch off — sync on product save/stock changes', 'woo-bol-sync' );
}
?>
<div class="wrap wbs-wrap">

    <h1 class="wbs-page-title">
        <span class="dashicons dashicons-store"></span>
        <?php esc_html_e( 'Bol.com Sync — Dashboard', 'woo-bol-sync' ); ?>
        <span class="wbs-version-badge">v<?php echo esc_html( WBS_VERSION ); ?></span>
    </h1>

    <?php if ( ! $license_active ) : ?>
    <div class="notice notice-warning wbs-notice-inline">
        <p>
            <?php esc_html_e( 'Your license is inactive. Some features may be limited.', 'woo-bol-sync' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-license' ) ); ?>">
                <?php esc_html_e( 'Activate license →', 'woo-bol-sync' ); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

    <?php if ( is_array( $recent_ean_recreates ) && $recent_ean_recreates !== [] ) : ?>
    <div class="notice notice-info wbs-notice-inline">
        <p><strong><?php esc_html_e( 'EAN changes detected and queued for offer recreation', 'woo-bol-sync' ); ?></strong></p>
        <?php foreach ( $recent_ean_recreates as $ean_event_row ) : ?>
            <?php $ean_event = json_decode( (string) ( $ean_event_row['meta'] ?? '' ), true ); ?>
            <?php if ( ! is_array( $ean_event ) ) { continue; } ?>
            <p>
                <?php
                $event_product_label = (string) ( $ean_event['product_name'] ?? '#' . (int) ( $ean_event_row['wc_product_id'] ?? 0 ) );
                $event_prev_ean     = (string) ( $ean_event['ean_previous'] ?? '' );
                $event_curr_ean     = (string) ( $ean_event['ean_current'] ?? '' );
                $event_at           = (string) ( $ean_event['ean_changed_at'] ?? ( $ean_event_row['updated_at'] ?? '' ) );

                printf(
                    /* translators: 1: product label, 2: old EAN, 3: new EAN, 4: datetime */
                    esc_html__( '%1$s — EAN changed from %2$s to %3$s. Offer mapping was reset and will be recreated on next sync. (%4$s)', 'woo-bol-sync' ),
                    esc_html( $event_product_label ),
                    esc_html( $event_prev_ean !== '' ? $event_prev_ean : '—' ),
                    esc_html( $event_curr_ean !== '' ? $event_curr_ean : '—' ),
                    esc_html( $event_at !== '' ? $event_at : __( 'time unknown', 'woo-bol-sync' ) )
                );
                ?>
            </p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="wbs-card wbs-card--guide">
        <div class="wbs-guide-header">
            <div>
                <h2 class="wbs-card__title" style="margin-bottom:8px;"><?php esc_html_e( 'Getting Started', 'woo-bol-sync' ); ?></h2>
                <p class="wbs-card__description" style="margin-bottom:0;">
                    <?php esc_html_e( 'Follow this setup checklist once. After that, the plugin can keep products, stock, and orders synchronized with minimal manual work.', 'woo-bol-sync' ); ?>
                </p>
            </div>
            <div class="wbs-guide-progress">
                <strong><?php echo esc_html( sprintf( '%d/%d', $setup_done, $setup_total ) ); ?></strong>
                <span><?php esc_html_e( 'steps complete', 'woo-bol-sync' ); ?></span>
            </div>
        </div>
        <div class="wbs-checklist">
            <?php foreach ( $setup_steps as $step ) : ?>
                <div class="wbs-checklist__item <?php echo ! empty( $step['done'] ) ? 'is-done' : 'is-todo'; ?>">
                    <div class="wbs-checklist__status"><?php echo ! empty( $step['done'] ) ? esc_html__( 'Done', 'woo-bol-sync' ) : esc_html__( 'Todo', 'woo-bol-sync' ); ?></div>
                    <div class="wbs-checklist__body">
                        <strong><?php echo esc_html( $step['label'] ); ?></strong>
                    </div>
                    <a class="button button-secondary" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['action'] ); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Stats row ──────────────────────────────────────────────────────── -->
    <div class="wbs-stats-row">

        <div class="wbs-stat-card wbs-stat-card--blue">
            <div class="wbs-stat-card__icon dashicons dashicons-products"></div>
            <div class="wbs-stat-card__body">
                <span id="wbs-stat-products-synced" class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $product_stats['synced'] ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Products Synced', 'woo-bol-sync' ); ?></span>
            </div>
        </div>

        <div class="wbs-stat-card wbs-stat-card--red">
            <div class="wbs-stat-card__icon dashicons dashicons-warning"></div>
            <div class="wbs-stat-card__body">
                <span id="wbs-stat-products-failed" class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $product_stats['failed'] ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Sync Failures', 'woo-bol-sync' ); ?></span>
            </div>
        </div>

        <div class="wbs-stat-card wbs-stat-card--green">
            <div class="wbs-stat-card__icon dashicons dashicons-cart"></div>
            <div class="wbs-stat-card__body">
                <span id="wbs-stat-orders-imported" class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $order_stats['total'] ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Orders Imported', 'woo-bol-sync' ); ?></span>
            </div>
        </div>

        <div class="wbs-stat-card wbs-stat-card--purple">
            <div class="wbs-stat-card__icon dashicons dashicons-airplane"></div>
            <div class="wbs-stat-card__body">
                <span id="wbs-stat-orders-shipped" class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $order_stats['shipped'] ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Orders Shipped', 'woo-bol-sync' ); ?></span>
            </div>
        </div>

    </div><!-- /.wbs-stats-row -->

    <div class="wbs-dashboard-grid">

        <!-- ── Connection Status ──────────────────────────────────────────── -->
        <div class="wbs-card">
            <h2 class="wbs-card__title"><?php esc_html_e( 'Connection Status', 'woo-bol-sync' ); ?></h2>

            <?php if ( ! $has_credentials ) : ?>
                <div class="wbs-status wbs-status--warning">
                    <span class="wbs-status__dot"></span>
                    <span class="wbs-status__label"><?php esc_html_e( 'No credentials configured', 'woo-bol-sync' ); ?></span>
                </div>

        <div class="wbs-card">
            <h2 class="wbs-card__title"><?php esc_html_e( 'Latest Product Diagnostic', 'woo-bol-sync' ); ?></h2>
            <?php if ( is_array( $latest_failed_product ) ) : ?>
                <?php $diag = json_decode( (string) ( $latest_failed_product['meta'] ?? '' ), true ); ?>
                <p><strong><?php echo esc_html( (string) ( $diag['product_name'] ?? '#' . (int) $latest_failed_product['wc_product_id'] ) ); ?></strong></p>
                <p class="wbs-card__description"><?php echo esc_html( (string) ( $diag['message'] ?? __( 'Unknown issue.', 'woo-bol-sync' ) ) ); ?></p>
                <?php if ( ! empty( $diag['catalog_hint'] ) ) : ?>
                    <p class="description"><?php echo esc_html( (string) $diag['catalog_hint'] ); ?></p>
                <?php endif; ?>
                <div class="wbs-card__actions">
                    <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-logs&wbs_level=error&wbs_context=products' ) ); ?>"><?php esc_html_e( 'Open logs', 'woo-bol-sync' ); ?></a>
                </div>
            <?php else : ?>
                <p class="wbs-card__description"><?php esc_html_e( 'No recent product diagnostics. When bol blocks a product, the exact reason and next action will appear here and in the logs.', 'woo-bol-sync' ); ?></p>
            <?php endif; ?>
        </div>
                <p class="wbs-card__description">
                    <?php
                    printf(
                        /* translators: link to settings */
                        wp_kses( __( 'Go to <a href="%s">Settings</a> to enter your bol.com API credentials.', 'woo-bol-sync' ), [ 'a' => [ 'href' => [] ] ] ),
                        esc_url( admin_url( 'admin.php?page=wbs-settings' ) )
                    );
                    ?>
                </p>
            <?php else : ?>
                <?php
                $status_class = match ( $connection_status ) {
                    'success' => 'wbs-status--success',
                    'error' => 'wbs-status--error',
                    'warning' => 'wbs-status--warning',
                    default => 'wbs-status--unknown',
                };
                $status_label = $connection_message !== ''
                    ? $connection_message
                    : __( 'Not tested yet — click below', 'woo-bol-sync' );
                ?>
                <div class="wbs-status <?php echo esc_attr( $status_class ); ?>" id="wbs-conn-status">
                    <span class="wbs-status__dot"></span>
                    <span class="wbs-status__label"><?php echo esc_html( $status_label ); ?></span>
                </div>
                <div class="wbs-card__actions">
                    <button type="button" id="wbs-btn-test-connection" class="button button-secondary">
                        <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                        <?php esc_html_e( 'Test Connection', 'woo-bol-sync' ); ?>
                    </button>
                </div>
                <div id="wbs-conn-message" class="wbs-inline-message" style="display:none;"></div>
                <?php if ( $connection_last_tested !== '' ) : ?>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: datetime */
                            esc_html__( 'Last tested: %s', 'woo-bol-sync' ),
                            esc_html( $connection_last_tested )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <hr class="wbs-card__divider" />

            <table class="wbs-info-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Client ID', 'woo-bol-sync' ); ?></th>
                        <td>
                            <?php
                            $cid = (string) get_option( 'wbs_client_id', '' );
                            echo ! empty( $cid )
                                ? esc_html( substr( $cid, 0, 6 ) . str_repeat( '•', 10 ) )
                                : '<em>' . esc_html__( 'Not set', 'woo-bol-sync' ) . '</em>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Smart Sync', 'woo-bol-sync' ); ?></th>
                        <td><?php echo $smart_sync_on
                            ? '<span class="wbs-badge wbs-badge--on">' . esc_html__( 'ON', 'woo-bol-sync' ) . '</span>'
                            : '<span class="wbs-badge wbs-badge--off">' . esc_html__( 'OFF', 'woo-bol-sync' ) . '</span>'; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Debug Mode', 'woo-bol-sync' ); ?></th>
                        <td><?php echo $debug_on
                            ? '<span class="wbs-badge wbs-badge--warn">' . esc_html__( 'ON', 'woo-bol-sync' ) . '</span>'
                            : '<span class="wbs-badge wbs-badge--off">' . esc_html__( 'OFF', 'woo-bol-sync' ) . '</span>'; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'License', 'woo-bol-sync' ); ?></th>
                        <td>
                            <?php if ( $license_active ) : ?>
                                <span class="wbs-badge wbs-badge--on"><?php esc_html_e( 'Active', 'woo-bol-sync' ); ?></span>
                            <?php else : ?>
                                <span class="wbs-badge wbs-badge--off"><?php esc_html_e( 'Inactive', 'woo-bol-sync' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Webhook', 'woo-bol-sync' ); ?></th>
                        <td>
                            <?php if ( Mapping_Config::webhook_enabled() ) : ?>
                                <span class="wbs-badge wbs-badge--on"><?php echo $subscription_id !== '' ? esc_html__( 'Enabled', 'woo-bol-sync' ) : esc_html__( 'Pending', 'woo-bol-sync' ); ?></span>
                            <?php else : ?>
                                <span class="wbs-badge wbs-badge--off"><?php esc_html_e( 'Disabled', 'woo-bol-sync' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Next product batch sync', 'woo-bol-sync' ); ?></th>
                        <td><small><?php echo esc_html( $product_next_label ); ?></small></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Next order import', 'woo-bol-sync' ); ?></th>
                        <td><small><?php echo esc_html( $fmt_next( $next_orders ) ); ?></small></td>
                    </tr>
                </tbody>
            </table>

            <?php if ( $has_credentials && ! $eo_ready ) : ?>
                <div class="wbs-inline-message is-error" style="display:block;">
                    <?php esc_html_e( 'Credentials are saved, but no economic operator is configured yet. Create or fetch one in Settings before syncing products.', 'woo-bol-sync' ); ?>
                </div>
            <?php endif; ?>
        </div><!-- /.wbs-card -->

        <!-- ── Manual Sync ────────────────────────────────────────────────── -->
        <div class="wbs-card">
            <h2 class="wbs-card__title"><?php esc_html_e( 'Manual Sync', 'woo-bol-sync' ); ?></h2>

            <p class="wbs-card__description">
                <?php esc_html_e( 'Trigger a sync run manually. Automatic syncs run in the background via WP-Cron.', 'woo-bol-sync' ); ?>
            </p>

            <!-- Products -->
            <div class="wbs-sync-block">
                <div class="wbs-sync-block__header">
                    <span class="dashicons dashicons-products"></span>
                    <strong><?php esc_html_e( 'Products → bol.com', 'woo-bol-sync' ); ?></strong>
                </div>
                <p class="wbs-sync-block__desc">
                    <?php esc_html_e( 'Validates SKU, EAN, price and stock, then creates or updates bol.com offers. Smart-sync skips unchanged products. Each run processes one batch (see batch size in Settings); products waiting for a new offer after an EAN change are synced first when possible.', 'woo-bol-sync' ); ?>
                </p>
                <div id="wbs-sync-products-meta" class="wbs-sync-block__meta">
                    <?php
                    printf(
                        /* translators: 1: synced 2: failed 3: pending */
                        esc_html__( 'Synced: %1$d &nbsp;|&nbsp; Failed: %2$d &nbsp;|&nbsp; Pending: %3$d', 'woo-bol-sync' ),
                        $product_stats['synced'],
                        $product_stats['failed'],
                        $product_stats['pending']
                    );
                    ?>
                </div>
                <button type="button" id="wbs-btn-sync-products" class="button button-primary wbs-sync-btn"
                    <?php disabled( ! $has_credentials ); ?>>
                    <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                    <?php esc_html_e( 'Sync Products', 'woo-bol-sync' ); ?>
                </button>
                <button type="button" id="wbs-btn-force-update-existing-products" class="button button-secondary wbs-sync-btn"
                    <?php disabled( ! $has_credentials ); ?> style="margin-left:8px;">
                    <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                    <?php esc_html_e( 'Force update existing products', 'woo-bol-sync' ); ?>
                </button>
                <div id="wbs-products-message" class="wbs-inline-message" style="display:none;"></div>
            </div>

            <hr class="wbs-card__divider" />

            <!-- Orders -->
            <div class="wbs-sync-block">
                <div class="wbs-sync-block__header">
                    <span class="dashicons dashicons-cart"></span>
                    <strong><?php esc_html_e( 'Orders ← bol.com', 'woo-bol-sync' ); ?></strong>
                </div>
                <p class="wbs-sync-block__desc">
                    <?php esc_html_e( 'Pulls changed FBR orders from bol.com, imports new ones, and annotates matched returns. Duplicate imports are prevented via the order-mapping table.', 'woo-bol-sync' ); ?>
                </p>
                <div class="wbs-sync-block__meta">
                    <span id="wbs-sync-orders-meta">
                    <?php
                    printf(
                        /* translators: 1: imported 2: shipped 3: failed */
                        esc_html__( 'Imported: %1$d &nbsp;|&nbsp; Shipped: %2$d &nbsp;|&nbsp; Failed: %3$d', 'woo-bol-sync' ),
                        $order_stats['total'],
                        $order_stats['shipped'],
                        $order_stats['failed']
                    );
                    ?>
                    </span>
                </div>
                <button type="button" id="wbs-btn-sync-orders" class="button button-primary wbs-sync-btn"
                    <?php disabled( ! $has_credentials ); ?>>
                    <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                    <?php esc_html_e( 'Sync Orders', 'woo-bol-sync' ); ?>
                </button>
                <div id="wbs-orders-message" class="wbs-inline-message" style="display:none;"></div>
            </div>

            <hr class="wbs-card__divider" />

            <div class="wbs-sync-block">
                <div class="wbs-sync-block__header">
                    <span class="dashicons dashicons-saved"></span>
                    <strong><?php esc_html_e( 'Operational tools', 'woo-bol-sync' ); ?></strong>
                </div>
                <p class="wbs-sync-block__desc">
                    <?php esc_html_e( 'Run a production health check, recover failed syncs, and make sure the webhook subscription is present.', 'woo-bol-sync' ); ?>
                </p>
                <div class="wbs-card__actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" id="wbs-btn-health-check" class="button button-secondary">
                        <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                        <?php esc_html_e( 'Run Health Check', 'woo-bol-sync' ); ?>
                    </button>
                    <button type="button" id="wbs-btn-retry-products" class="button button-secondary">
                        <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                        <?php esc_html_e( 'Retry Failed Products', 'woo-bol-sync' ); ?>
                    </button>
                    <button type="button" id="wbs-btn-retry-orders" class="button button-secondary">
                        <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                        <?php esc_html_e( 'Retry Orders / Returns', 'woo-bol-sync' ); ?>
                    </button>
                    <button type="button" id="wbs-btn-ensure-webhook" class="button button-secondary">
                        <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                        <?php esc_html_e( 'Ensure Webhook', 'woo-bol-sync' ); ?>
                    </button>
                </div>
                <div id="wbs-ops-message" class="wbs-inline-message" style="display:none; margin-top:12px;"></div>
            </div>

        </div><!-- /.wbs-card -->

    </div><!-- /.wbs-dashboard-grid -->

    <!-- ── Quick links ────────────────────────────────────────────────────── -->
    <div class="wbs-quick-links">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-settings' ) ); ?>" class="button"><?php esc_html_e( 'Settings', 'woo-bol-sync' ); ?></a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-categories' ) ); ?>" class="button"><?php esc_html_e( 'Category Mapping', 'woo-bol-sync' ); ?></a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-field-map' ) ); ?>" class="button"><?php esc_html_e( 'Field Mapping', 'woo-bol-sync' ); ?></a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-logs' ) ); ?>" class="button"><?php esc_html_e( 'View Logs', 'woo-bol-sync' ); ?></a>
        <a href="https://api.bol.com/retailer/public/Retailer-API/index.html" target="_blank" rel="noopener noreferrer" class="button"><?php esc_html_e( 'API Docs', 'woo-bol-sync' ); ?> ↗</a>
    </div>

</div><!-- /.wrap.wbs-wrap -->
