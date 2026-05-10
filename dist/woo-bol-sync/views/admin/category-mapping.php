<?php
/**
 * WooCommerce product_cat ↔ bol.com category id, template attributes, and discovery tools.
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;

use WooBolSync\Models\Category_Map;

$updated = isset( $_GET['updated'] ) && (string) $_GET['updated'] === '1';
$terms   = get_terms(
    [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
    ]
);
$map = Category_Map::get_all();
$total_terms  = is_array( $terms ) ? count( $terms ) : 0;
$mapped_terms = count( array_filter( $map, static fn( $value ): bool => is_string( $value ) && trim( $value ) !== '' ) );
?>
<div class="wrap wbs-wrap">

    <h1 class="wbs-page-title">
        <span class="dashicons dashicons-category"></span>
        <?php esc_html_e( 'Bol.com — Category mapping', 'woo-bol-sync' ); ?>
    </h1>

    <?php if ( $updated ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Category map saved.', 'woo-bol-sync' ); ?></p></div>
    <?php endif; ?>

    <section class="wbs-section" data-section="catmap-guide" id="wbs-section-catmap-guide">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-info" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'Why map categories', 'woo-bol-sync' ); ?></h2>
            <button
                type="button"
                class="wbs-section__toggle"
                aria-expanded="true"
                aria-controls="wbs-section-body-catmap-guide"
            >
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__intro">
            <p style="margin:0 0 12px;">
                <?php esc_html_e( 'bol.com often needs correct classification (product group / attributes) for a complete storefront listing—price, stock, and images can look wrong until catalog data is complete. Map each WooCommerce category you sell on bol to bol\'s category id and required attributes from bol\'s documentation or support.', 'woo-bol-sync' ); ?>
            </p>
        </div>
        <div class="wbs-section__body" id="wbs-section-body-catmap-guide">
            <div class="wbs-checklist">
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">1</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Offers can still be created without mapping, but missing classification may block a full customer-facing listing.', 'woo-bol-sync' ); ?></strong></div>
                </div>
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">2</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Template lines add bol attribute ids (id=value) merged into product content upload for products in that category.', 'woo-bol-sync' ); ?></strong></div>
                </div>
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">3</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Products in a child category inherit the mapped row from a parent category when the child row has no bol id (same rule for template attributes).', 'woo-bol-sync' ); ?></strong></div>
                </div>
            </div>
        </div>
    </section>

    <section class="wbs-section" id="wbs-catalog-lookup-card" data-section="catalog-lookup">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-search" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'Catalog lookup by EAN', 'woo-bol-sync' ); ?></h2>
            <button
                type="button"
                class="wbs-section__toggle"
                aria-expanded="true"
                aria-controls="wbs-section-body-catalog-lookup"
            >
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__intro">
            <p style="margin-top:0;">
                <?php esc_html_e( 'Calls bol.com GET /retailer/content/catalog-products/{ean} (read-only). If bol returns 404, the EAN is not in their catalog yet—use Chunk recommendations instead. When you get 200, use the JSON for classification hints. Requires API credentials in Settings.', 'woo-bol-sync' ); ?>
            </p>
        </div>
        <div class="wbs-section__body" id="wbs-section-body-catalog-lookup">
            <p style="margin-bottom:8px;">
                <label for="wbs-catalog-lookup-ean" class="screen-reader-text"><?php esc_html_e( 'EAN', 'woo-bol-sync' ); ?></label>
                <input type="text" id="wbs-catalog-lookup-ean" class="regular-text" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="<?php esc_attr_e( 'EAN / GTIN', 'woo-bol-sync' ); ?>" />
                <button type="button" id="wbs-btn-catalog-lookup" class="button"><?php esc_html_e( 'Look up', 'woo-bol-sync' ); ?></button>
            </p>
            <div id="wbs-catalog-lookup-message" class="wbs-inline-message" style="display:none;"></div>
            <p id="wbs-catalog-gpc-hint" class="description" style="display:none; margin-top:8px;"></p>
            <pre id="wbs-catalog-lookup-output" class="wbs-catalog-json" style="display:none;" aria-live="polite"></pre>
        </div>
    </section>

    <section class="wbs-section" id="wbs-chunk-recommend-card" data-section="chunk-recommend">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-lightbulb" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'Chunk recommendations', 'woo-bol-sync' ); ?></h2>
            <button
                type="button"
                class="wbs-section__toggle"
                aria-expanded="true"
                aria-controls="wbs-section-body-chunk-recommend"
            >
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__intro">
            <p style="margin-top:0;">
                <?php esc_html_e( 'Calls bol.com POST /retailer/content/chunk-recommendations with product name and optional description. The API returns chunkId and probability only (no chunk title)—use the summary below and bol\'s data model to find the human-readable group name.', 'woo-bol-sync' ); ?>
            </p>
        </div>
        <div class="wbs-section__body" id="wbs-section-body-chunk-recommend">
            <p>
                <label for="wbs-chunk-product-name"><strong><?php esc_html_e( 'Product name', 'woo-bol-sync' ); ?></strong></label><br />
                <input type="text" id="wbs-chunk-product-name" class="regular-text" style="max-width:100%;" placeholder="<?php esc_attr_e( 'e.g. Espresso coffee beans 1 kg', 'woo-bol-sync' ); ?>" />
            </p>
            <p>
                <label for="wbs-chunk-product-description"><?php esc_html_e( 'Description', 'woo-bol-sync' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'woo-bol-sync' ); ?></span></label><br />
                <textarea id="wbs-chunk-product-description" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Short plain text; helps bol suggest chunks.', 'woo-bol-sync' ); ?>"></textarea>
            </p>
            <p style="margin-bottom:0;">
                <button type="button" id="wbs-btn-chunk-recommendations" class="button"><?php esc_html_e( 'Get chunk recommendations', 'woo-bol-sync' ); ?></button>
            </p>
            <div id="wbs-chunk-recommend-message" class="wbs-inline-message" style="display:none;"></div>
            <div id="wbs-chunk-summary" class="wbs-chunk-summary" style="display:none;" aria-live="polite"></div>
            <pre id="wbs-chunk-recommend-output" class="wbs-catalog-json" style="display:none;" aria-live="polite"></pre>
        </div>
    </section>

    <div class="wbs-stats-row wbs-stats-row--compact" style="max-width:920px;">
        <div class="wbs-stat-card">
            <div class="wbs-stat-card__body">
                <span class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( $mapped_terms ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Mapped Categories', 'woo-bol-sync' ); ?></span>
            </div>
        </div>
        <div class="wbs-stat-card">
            <div class="wbs-stat-card__body">
                <span class="wbs-stat-card__number"><?php echo esc_html( number_format_i18n( max( 0, $total_terms - $mapped_terms ) ) ); ?></span>
                <span class="wbs-stat-card__label"><?php esc_html_e( 'Unmapped Categories', 'woo-bol-sync' ); ?></span>
            </div>
        </div>
    </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'wbs_save_category_map' ); ?>
        <input type="hidden" name="action" value="wbs_save_category_map" />

        <section class="wbs-section" data-section="catmap-table" id="wbs-section-catmap-table">
            <header class="wbs-section__header">
                <span class="wbs-section__icon dashicons dashicons-category" aria-hidden="true"></span>
                <h2 class="wbs-section__title"><?php esc_html_e( 'Category mapping', 'woo-bol-sync' ); ?></h2>
                <button
                    type="button"
                    class="wbs-section__toggle"
                    aria-expanded="true"
                    aria-controls="wbs-section-body-catmap-table"
                >
                    <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                    <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
                </button>
            </header>
            <div class="wbs-section__body" id="wbs-section-body-catmap-table">
                <div class="wbs-toolbar-inline">
                    <input type="search" id="wbs-category-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search WooCommerce category...', 'woo-bol-sync' ); ?>" />
                    <label class="wbs-inline-checkbox">
                        <input type="checkbox" id="wbs-category-show-unmapped" />
                        <?php esc_html_e( 'Show only unmapped', 'woo-bol-sync' ); ?>
                    </label>
                </div>
        <table class="widefat striped wbs-admin-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'WooCommerce category', 'woo-bol-sync' ); ?></th>
                    <th><?php esc_html_e( 'bol.com category ID', 'woo-bol-sync' ); ?></th>
                    <th><?php esc_html_e( 'bol attribute template', 'woo-bol-sync' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ( is_wp_error( $terms ) || empty( $terms ) ) :
                    ?>
                    <tr><td colspan="3"><?php esc_html_e( 'No categories found.', 'woo-bol-sync' ); ?></td></tr>
                    <?php
                else :
                    foreach ( $terms as $term ) :
                        $tid   = (int) $term->term_id;
                        $val   = $map[ $tid ] ?? '';
                        $depth = count( get_ancestors( $tid, 'product_cat', 'taxonomy' ) );
                        $pad   = str_repeat( '— ', $depth );
                        $template_lines = [];
                        foreach ( Category_Map::get_template_attributes( $tid ) as $template_row ) {
                            $template_lines[] = $template_row['id'] . '=' . $template_row['value'];
                        }
                        $template_text = implode( "\n", $template_lines );
                        ?>
                        <tr class="wbs-category-row" data-category-name="<?php echo esc_attr( strtolower( $term->name ) ); ?>" data-mapped="<?php echo trim( (string) $val ) !== '' ? '1' : '0'; ?>">
                            <td>
                                <?php echo esc_html( $pad . $term->name ); ?>
                                <code>(ID <?php echo esc_html( (string) $tid ); ?>)</code>
                                <?php if ( $val !== '' ) : ?>
                                    <span class="wbs-col-badge wbs-col-badge--synced"><?php esc_html_e( 'Mapped', 'woo-bol-sync' ); ?></span>
                                <?php else : ?>
                                    <span class="wbs-col-badge wbs-col-badge--pending"><?php esc_html_e( 'Needs mapping', 'woo-bol-sync' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="wbs_bol_cat[<?php echo esc_attr( (string) $tid ); ?>]" value="<?php echo esc_attr( $val ); ?>" placeholder="<?php esc_attr_e( 'bol category id', 'woo-bol-sync' ); ?>" />
                            </td>
                            <td>
                                <textarea class="large-text code" rows="3" name="wbs_bol_template[<?php echo esc_attr( (string) $tid ); ?>]" placeholder="<?php esc_attr_e( 'Example: Brand=Lavazza', 'woo-bol-sync' ); ?>"><?php echo esc_textarea( $template_text ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'One attribute per line using id=value. Example: Weight=1000 g', 'woo-bol-sync' ); ?></p>
                            </td>
                        </tr>
                        <?php
                    endforeach;
                endif;
                ?>
            </tbody>
        </table>
            </div>
        </section>

        <p style="margin-top:16px;">
            <?php submit_button( __( 'Save category map', 'woo-bol-sync' ), 'primary', 'submit', false ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-field-map' ) ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Next: field mapping', 'woo-bol-sync' ); ?></a>
        </p>
    </form>

</div>
