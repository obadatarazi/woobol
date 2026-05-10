<?php
/**
 * bol Products: fetch seller offers and manage listings.
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap wbs-wrap">
    <h1 class="wbs-page-title">
        <span class="dashicons dashicons-products"></span>
        <?php esc_html_e( 'bol Products', 'woo-bol-sync' ); ?>
    </h1>

    <section class="wbs-section" data-section="bol-products-fetch" id="wbs-section-bol-products-fetch">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-search" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'Fetch Seller Products', 'woo-bol-sync' ); ?></h2>
            <button type="button" class="wbs-section__toggle" aria-expanded="true" aria-controls="wbs-section-body-bol-products-fetch">
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__intro">
            <p><?php esc_html_e( 'Load offers from your connected bol seller account. Images show the WooCommerce product image when this EAN is mapped locally. Use the checkboxes to delete one or many offers on bol.com.', 'woo-bol-sync' ); ?></p>
        </div>
        <div class="wbs-section__body" id="wbs-section-body-bol-products-fetch">
            <div class="wbs-toolbar-inline">
                <input type="text" id="wbs-bol-products-ean" class="regular-text" placeholder="<?php esc_attr_e( 'EAN or GTIN (optional)', 'woo-bol-sync' ); ?>" />
                <button type="button" class="button button-primary" id="wbs-btn-bol-products-fetch">
                    <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                    <?php esc_html_e( 'Fetch', 'woo-bol-sync' ); ?>
                </button>
            </div>
            <div id="wbs-bol-products-message" class="wbs-inline-message" style="display:none;"></div>

            <h3><?php esc_html_e( 'Catalog Product (when EAN is provided)', 'woo-bol-sync' ); ?></h3>
            <pre id="wbs-bol-products-catalog" class="wbs-catalog-json" style="display:none;"></pre>

            <h3><?php esc_html_e( 'Seller Offers', 'woo-bol-sync' ); ?></h3>
            <div id="wbs-bol-offers-table-wrap" class="wbs-bol-offers-table-wrap" style="display:none;">
                <div class="wbs-toolbar-inline wbs-bol-offers-bulk">
                    <label class="wbs-bol-offers-select-all-label">
                        <input type="checkbox" id="wbs-bol-offers-select-all" />
                        <?php esc_html_e( 'Select all', 'woo-bol-sync' ); ?>
                    </label>
                    <button type="button" class="button button-secondary wbs-bol-offers-delete-selected" id="wbs-bol-offers-delete-selected" disabled>
                        <?php esc_html_e( 'Delete selected on bol.com', 'woo-bol-sync' ); ?>
                    </button>
                </div>
                <div class="wbs-bol-offers-table-scroll">
                    <table class="widefat striped wbs-bol-offers-table">
                        <thead>
                            <tr>
                                <th class="wbs-col-check" scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'woo-bol-sync' ); ?></span></th>
                                <th class="wbs-col-thumb" scope="col"><?php esc_html_e( 'Image', 'woo-bol-sync' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Product', 'woo-bol-sync' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'EAN', 'woo-bol-sync' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Stock', 'woo-bol-sync' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Price', 'woo-bol-sync' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'On bol', 'woo-bol-sync' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Fulfilment', 'woo-bol-sync' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Reference', 'woo-bol-sync' ); ?></th>
                                <th class="wbs-col-actions" scope="col"><?php esc_html_e( 'Actions', 'woo-bol-sync' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="wbs-bol-products-offers-rows"></tbody>
                    </table>
                </div>
            </div>

            <details id="wbs-bol-offers-raw-details" class="wbs-bol-offers-raw-details" style="display:none;">
                <summary><?php esc_html_e( 'Raw API response (debug)', 'woo-bol-sync' ); ?></summary>
                <pre id="wbs-bol-products-offers" class="wbs-catalog-json"></pre>
            </details>

            <div class="wbs-bol-offer-delete">
                <h3><?php esc_html_e( 'Delete by offer ID', 'woo-bol-sync' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Optional: delete a single offer when you already know its ID.', 'woo-bol-sync' ); ?></p>
                <div class="wbs-toolbar-inline wbs-toolbar-inline--danger">
                    <label class="screen-reader-text" for="wbs-bol-offer-delete-id"><?php esc_html_e( 'Offer ID', 'woo-bol-sync' ); ?></label>
                    <input type="text" id="wbs-bol-offer-delete-id" class="regular-text code" placeholder="<?php esc_attr_e( 'offerId', 'woo-bol-sync' ); ?>" autocomplete="off" />
                    <button type="button" class="button button-secondary" id="wbs-btn-bol-offer-delete">
                        <?php esc_html_e( 'Delete on bol.com', 'woo-bol-sync' ); ?>
                    </button>
                </div>
                <div id="wbs-bol-offer-delete-message" class="wbs-inline-message" style="display:none;"></div>
            </div>
        </div>
    </section>
</div>
