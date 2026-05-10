<?php
/**
 * bol Orders read-only page.
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap wbs-wrap">
    <h1 class="wbs-page-title">
        <span class="dashicons dashicons-cart"></span>
        <?php esc_html_e( 'bol Orders (Read-only)', 'woo-bol-sync' ); ?>
    </h1>

    <section class="wbs-section" data-section="bol-orders-list" id="wbs-section-bol-orders-list">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-list-view" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'Fetch Orders', 'woo-bol-sync' ); ?></h2>
            <button type="button" class="wbs-section__toggle" aria-expanded="true" aria-controls="wbs-section-body-bol-orders-list">
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__body" id="wbs-section-body-bol-orders-list">
            <div class="wbs-toolbar-inline">
                <label for="wbs-bol-orders-fulfilment"><?php esc_html_e( 'Logistics', 'woo-bol-sync' ); ?></label>
                <select id="wbs-bol-orders-fulfilment" title="<?php esc_attr_e( 'FBR = you ship; FBB = bol logistics; ALL = both.', 'woo-bol-sync' ); ?>">
                    <option value="ALL"><?php esc_html_e( 'ALL (FBR + FBB)', 'woo-bol-sync' ); ?></option>
                    <option value="FBR"><?php esc_html_e( 'FBR (ship yourself)', 'woo-bol-sync' ); ?></option>
                    <option value="FBB"><?php esc_html_e( 'FBB (via bol)', 'woo-bol-sync' ); ?></option>
                </select>
                <label for="wbs-bol-orders-status"><?php esc_html_e( 'Status', 'woo-bol-sync' ); ?></label>
                <select id="wbs-bol-orders-status">
                    <option value="ALL"><?php esc_html_e( 'ALL', 'woo-bol-sync' ); ?></option>
                    <option value="OPEN"><?php esc_html_e( 'OPEN', 'woo-bol-sync' ); ?></option>
                    <option value="SHIPPED"><?php esc_html_e( 'SHIPPED', 'woo-bol-sync' ); ?></option>
                    <option value="CANCELLED"><?php esc_html_e( 'CANCELLED', 'woo-bol-sync' ); ?></option>
                </select>
                <label for="wbs-bol-orders-latest-date"><?php esc_html_e( 'Latest change date', 'woo-bol-sync' ); ?></label>
                <input type="date" id="wbs-bol-orders-latest-date" class="wbs-input-date" />
                <label for="wbs-bol-orders-page"><?php esc_html_e( 'Page', 'woo-bol-sync' ); ?></label>
                <input type="number" id="wbs-bol-orders-page" min="1" value="1" class="small-text" />
                <label for="wbs-bol-orders-size"><?php esc_html_e( 'Size', 'woo-bol-sync' ); ?></label>
                <input type="number" id="wbs-bol-orders-size" min="1" max="50" value="50" class="small-text" />
                <button type="button" class="button button-primary" id="wbs-btn-bol-orders-fetch">
                    <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                    <?php esc_html_e( 'Fetch Orders', 'woo-bol-sync' ); ?>
                </button>
            </div>
            <p class="description" style="margin-top:8px;">
                <?php esc_html_e( 'If the list is empty but bol.com shows orders: choose ALL under Logistics, try SHIPPED or ALL under Status, or set Latest change date to the order day (bol’s list API only includes very recent handled orders unless you filter by date).', 'woo-bol-sync' ); ?>
            </p>
            <div id="wbs-bol-orders-message" class="wbs-inline-message" style="display:none;"></div>

            <div class="wbs-table-wrap" id="wbs-bol-orders-table-wrap" style="display:none;">
                <table class="wp-list-table widefat striped wbs-admin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Order ID', 'woo-bol-sync' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'woo-bol-sync' ); ?></th>
                            <th><?php esc_html_e( 'Order Date', 'woo-bol-sync' ); ?></th>
                            <th><?php esc_html_e( 'Country', 'woo-bol-sync' ); ?></th>
                            <th><?php esc_html_e( 'Items', 'woo-bol-sync' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'woo-bol-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wbs-bol-orders-rows"></tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="wbs-section" data-section="bol-orders-detail" id="wbs-section-bol-orders-detail">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-media-text" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'Order Detail', 'woo-bol-sync' ); ?></h2>
            <button type="button" class="wbs-section__toggle" aria-expanded="true" aria-controls="wbs-section-body-bol-orders-detail">
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__body" id="wbs-section-body-bol-orders-detail">
            <div class="wbs-toolbar-inline">
                <input type="text" id="wbs-bol-order-id" class="regular-text" placeholder="<?php esc_attr_e( 'Order ID', 'woo-bol-sync' ); ?>" />
                <button type="button" class="button" id="wbs-btn-bol-order-detail-fetch">
                    <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                    <?php esc_html_e( 'Fetch Order Detail', 'woo-bol-sync' ); ?>
                </button>
            </div>
            <div id="wbs-bol-order-detail-message" class="wbs-inline-message" style="display:none;"></div>
            <pre id="wbs-bol-order-detail-json" class="wbs-catalog-json" style="display:none;"></pre>
        </div>
    </section>
</div>
