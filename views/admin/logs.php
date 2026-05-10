<?php
/**
 * Logs admin page view.
 *
 * Displays DB log entries from {prefix}wbs_logs with level and context filters.
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;

use WooBolSync\Includes\Logger;

// ── Read filter inputs (no user-supplied SQL – all go through Logger::get_recent) ─
$filter_level   = isset( $_GET['wbs_level'] )   ? sanitize_key( $_GET['wbs_level'] )   : '';
$filter_context = isset( $_GET['wbs_context'] ) ? sanitize_key( $_GET['wbs_context'] ) : '';
$per_page       = 100;

$rows        = Logger::get_recent( $per_page, $filter_level, $filter_context );
$total_count = Logger::count();
$error_count = Logger::count( 'error' );
$warning_count = Logger::count( 'warning' );
$info_count = Logger::count( 'info' );

$levels = [
    ''        => __( 'All Levels', 'woo-bol-sync' ),
    'error'   => __( 'Error', 'woo-bol-sync' ),
    'warning' => __( 'Warning', 'woo-bol-sync' ),
    'info'    => __( 'Info', 'woo-bol-sync' ),
    'debug'   => __( 'Debug', 'woo-bol-sync' ),
];

$contexts = [
    ''             => __( 'All Contexts', 'woo-bol-sync' ),
    'api'          => 'api',
    'products'     => 'products',
    'orders'       => 'orders',
    'product_sync' => 'product_sync',
    'order_sync'   => 'order_sync',
    'stock_sync'   => 'stock_sync',
    'status_sync'  => 'status_sync',
    'automation'   => 'automation',
    'license'      => 'license',
    'cron'         => 'cron',
    'general'      => 'general',
];

$level_classes = [
    'error'   => 'wbs-log-level--error',
    'warning' => 'wbs-log-level--warning',
    'info'    => 'wbs-log-level--info',
    'debug'   => 'wbs-log-level--debug',
];
?>
<div class="wrap wbs-wrap">

    <h1 class="wbs-page-title">
        <span class="dashicons dashicons-list-view"></span>
        <?php esc_html_e( 'Bol.com Sync — Logs', 'woo-bol-sync' ); ?>
        <?php if ( $error_count > 0 ) : ?>
            <span class="wbs-count-badge wbs-count-badge--error">
                <?php
                printf(
                    /* translators: %s: number of error log entries */
                    esc_html( _n( '%s error', '%s errors', $error_count, 'woo-bol-sync' ) ),
                    esc_html( number_format_i18n( $error_count ) )
                );
                ?>
            </span>
        <?php endif; ?>
    </h1>

    <section class="wbs-section" data-section="logs-guide" id="wbs-section-logs-guide">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-info" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'How to use logs', 'woo-bol-sync' ); ?></h2>
            <button
                type="button"
                class="wbs-section__toggle"
                aria-expanded="true"
                aria-controls="wbs-section-body-logs-guide"
            >
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__body" id="wbs-section-body-logs-guide">
            <div class="wbs-checklist">
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">1</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Start with Error level to find blocking issues', 'woo-bol-sync' ); ?></strong></div>
                </div>
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">2</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Use the context filter to narrow down product, order, API, or automation problems', 'woo-bol-sync' ); ?></strong></div>
                </div>
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">3</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Expand data only when you need technical details', 'woo-bol-sync' ); ?></strong></div>
                </div>
            </div>
        </div>
    </section>

    <div class="wbs-stats-row wbs-stats-row--compact">
        <div class="wbs-stat-card">
            <div class="wbs-stat-card__body">
                <span class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $total_count ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Total Log Entries', 'woo-bol-sync' ); ?></span>
            </div>
        </div>
        <div class="wbs-stat-card">
            <div class="wbs-stat-card__body">
                <span class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $error_count ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Errors', 'woo-bol-sync' ); ?></span>
            </div>
        </div>
        <div class="wbs-stat-card">
            <div class="wbs-stat-card__body">
                <span class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $warning_count ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Warnings', 'woo-bol-sync' ); ?></span>
            </div>
        </div>
        <div class="wbs-stat-card">
            <div class="wbs-stat-card__body">
                <span class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $info_count ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Info Entries', 'woo-bol-sync' ); ?></span>
            </div>
        </div>
    </div>

    <!-- ── Toolbar ────────────────────────────────────────────────────────── -->
    <div class="wbs-logs-toolbar">

        <!-- Filters -->
        <form method="get" action="" class="wbs-logs-filters">
            <input type="hidden" name="page" value="wbs-logs" />

            <select name="wbs_level" onchange="this.form.submit()">
                <?php foreach ( $levels as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_level, $val ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="wbs_context" onchange="this.form.submit()">
                <?php foreach ( $contexts as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_context, $val ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ( $filter_level || $filter_context ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-logs' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Reset', 'woo-bol-sync' ); ?></a>
            <?php endif; ?>
        </form>

        <!-- Stats + clear -->
        <div class="wbs-logs-actions">
            <span class="wbs-logs-count">
                <?php
                printf(
                    /* translators: 1: shown rows 2: total rows */
                    esc_html__( 'Showing %1$d of %2$d entries', 'woo-bol-sync' ),
                    count( $rows ),
                    $total_count
                );
                ?>
            </span>
            <button type="button" id="wbs-btn-clear-logs" class="button button-secondary wbs-btn-danger">
                <span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
                <?php esc_html_e( 'Clear All Logs', 'woo-bol-sync' ); ?>
            </button>
        </div>

    </div><!-- /.wbs-logs-toolbar -->

    <div id="wbs-logs-message" class="wbs-inline-message" style="display:none; margin-bottom:12px;"></div>

    <?php if ( empty( $rows ) ) : ?>
        <section class="wbs-section" data-section="logs-empty">
            <header class="wbs-section__header">
                <span class="wbs-section__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <h2 class="wbs-section__title"><?php esc_html_e( 'No log entries found', 'woo-bol-sync' ); ?></h2>
            </header>
            <div class="wbs-section__body">
                <p><?php esc_html_e( 'Either nothing has been logged yet, or your current filters do not match any entry.', 'woo-bol-sync' ); ?></p>
                <?php if ( $filter_level || $filter_context ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-logs' ) ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'Clear filters', 'woo-bol-sync' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </section>
    <?php else : ?>
        <div class="wbs-table-wrap">
            <table class="wp-list-table widefat fixed striped wbs-log-table">
                <thead>
                    <tr>
                        <th style="width:140px;"><?php esc_html_e( 'Date / Time', 'woo-bol-sync' ); ?></th>
                        <th style="width:70px;"><?php esc_html_e( 'Level', 'woo-bol-sync' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Context', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'woo-bol-sync' ); ?></th>
                        <th style="width:34px;"></th>
                    </tr>
                </thead>
                <tbody id="wbs-log-tbody">
                    <?php foreach ( $rows as $row ) :
                        $level   = $row['level']   ?? 'info';
                        $ctx     = $row['context']  ?? '—';
                        $msg     = $row['message']  ?? '';
                        $data    = $row['data']     ?? '';
                        $ts      = $row['created_at'] ?? '';
                        $has_data    = ! empty( trim( $data, '{}[] ' ) );
                        $row_id      = 'wbs-log-' . (int) $row['id'];
                        $level_cls   = $level_classes[ $level ] ?? 'wbs-log-level--info';
                        $data_parsed = $has_data ? json_decode( (string) $data, true ) : null;
                        $edit_url    = is_array( $data_parsed ) && ! empty( $data_parsed['edit_url'] ) && is_string( $data_parsed['edit_url'] )
                            ? $data_parsed['edit_url']
                            : '';
                        $product_name = is_array( $data_parsed ) && ! empty( $data_parsed['product_name'] ) && is_string( $data_parsed['product_name'] )
                            ? $data_parsed['product_name']
                            : '';
                        $exact_error = is_array( $data_parsed ) && ! empty( $data_parsed['message'] ) && is_string( $data_parsed['message'] )
                            ? $data_parsed['message']
                            : '';
                        $catalog_hint = is_array( $data_parsed ) && ! empty( $data_parsed['catalog_hint'] ) && is_string( $data_parsed['catalog_hint'] )
                            ? $data_parsed['catalog_hint']
                            : '';
                        $offer_id = is_array( $data_parsed ) && ! empty( $data_parsed['offer_id'] ) && is_string( $data_parsed['offer_id'] )
                            ? $data_parsed['offer_id']
                            : '';
                        $process_status_id = is_array( $data_parsed ) && ! empty( $data_parsed['process_status_id'] ) && is_string( $data_parsed['process_status_id'] )
                            ? $data_parsed['process_status_id']
                            : '';
                        $wc_order_id = is_array( $data_parsed ) && ! empty( $data_parsed['wc_order_id'] )
                            ? (string) $data_parsed['wc_order_id']
                            : '';
                        $bol_order_id = is_array( $data_parsed ) && ! empty( $data_parsed['bol_order_id'] ) && is_string( $data_parsed['bol_order_id'] )
                            ? $data_parsed['bol_order_id']
                            : '';
                        $unmapped_count = is_array( $data_parsed ) && isset( $data_parsed['unmapped_count'] )
                            ? (int) $data_parsed['unmapped_count']
                            : 0;
                    ?>
                        <tr>
                            <td class="wbs-log-ts">
                                <?php
                                if ( $ts && '0000-00-00 00:00:00' !== $ts ) {
                                    echo esc_html(
                                        get_date_from_gmt( $ts, 'd M Y' ) . '<br>'
                                        . get_date_from_gmt( $ts, 'H:i:s' )
                                    );
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="wbs-log-level <?php echo esc_attr( $level_cls ); ?>">
                                    <?php echo esc_html( strtoupper( $level ) ); ?>
                                </span>
                            </td>
                            <td><code class="wbs-log-ctx"><?php echo esc_html( $ctx ); ?></code></td>
                            <td class="wbs-log-msg">
                                <?php echo esc_html( $msg ); ?>
                                <?php if ( $product_name !== '' ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#50575e;">
                                        <strong><?php esc_html_e( 'Product:', 'woo-bol-sync' ); ?></strong>
                                        <?php echo esc_html( $product_name ); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $exact_error !== '' && $exact_error !== $msg ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#b32d2e;">
                                        <strong><?php esc_html_e( 'Exact error:', 'woo-bol-sync' ); ?></strong>
                                        <?php echo esc_html( $exact_error ); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $catalog_hint !== '' ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#6a4b00;">
                                        <strong><?php esc_html_e( 'Next action:', 'woo-bol-sync' ); ?></strong>
                                        <?php echo esc_html( $catalog_hint ); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $offer_id !== '' ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#50575e;">
                                        <strong><?php esc_html_e( 'Offer ID:', 'woo-bol-sync' ); ?></strong>
                                        <code><?php echo esc_html( $offer_id ); ?></code>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $wc_order_id !== '' ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#50575e;">
                                        <strong><?php esc_html_e( 'Woo order:', 'woo-bol-sync' ); ?></strong>
                                        <code>#<?php echo esc_html( $wc_order_id ); ?></code>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $bol_order_id !== '' ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#50575e;">
                                        <strong><?php esc_html_e( 'bol order:', 'woo-bol-sync' ); ?></strong>
                                        <code><?php echo esc_html( $bol_order_id ); ?></code>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $unmapped_count > 0 ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#b32d2e;">
                                        <strong><?php esc_html_e( 'Unmapped items:', 'woo-bol-sync' ); ?></strong>
                                        <?php echo esc_html( (string) $unmapped_count ); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $process_status_id !== '' ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#50575e;">
                                        <strong><?php esc_html_e( 'Process status ID:', 'woo-bol-sync' ); ?></strong>
                                        <code><?php echo esc_html( $process_status_id ); ?></code>
                                    </div>
                                <?php endif; ?>
                                <?php
                                if ( $edit_url !== '' ) {
                                    echo ' ';
                                    printf(
                                        '<a class="wbs-log-edit-product" href="%s">%s</a>',
                                        esc_url( $edit_url ),
                                        esc_html__( 'Edit product', 'woo-bol-sync' )
                                    );
                                }
                                ?>
                                <?php if ( $has_data ) : ?>
                                    <a href="#" class="wbs-toggle-data" data-target="<?php echo esc_attr( $row_id ); ?>"
                                       style="font-size:11px; margin-left:6px;" aria-expanded="false">
                                        [<?php esc_html_e( 'data', 'woo-bol-sync' ); ?> ▾]
                                    </a>
                                    <pre id="<?php echo esc_attr( $row_id ); ?>" class="wbs-log-data" style="display:none;"><?php echo esc_html( $data ); ?></pre>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center; color:#c3c4c7; font-size:12px;"><?php echo esc_html( '#' . (int) $row['id'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ( $total_count > $per_page ) : ?>
            <p class="wbs-logs-overflow">
                <?php
                printf(
                    /* translators: number */
                    esc_html__( 'Showing the %d most recent entries. Older entries are purged automatically.', 'woo-bol-sync' ),
                    $per_page
                );
                ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

</div><!-- /.wrap.wbs-wrap -->
