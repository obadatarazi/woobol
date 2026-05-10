<?php
/**
 * Field mapping and listing defaults (Settings API).
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="wrap wbs-wrap">

    <h1 class="wbs-page-title">
        <span class="dashicons dashicons-admin-links"></span>
        <?php esc_html_e( 'Bol.com — Field mapping', 'woo-bol-sync' ); ?>
    </h1>

    <section class="wbs-section" data-section="fieldmap-guide" id="wbs-section-fieldmap-guide">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-info" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'Before you save', 'woo-bol-sync' ); ?></h2>
            <button
                type="button"
                class="wbs-section__toggle"
                aria-expanded="true"
                aria-controls="wbs-section-body-fieldmap-guide"
            >
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__body" id="wbs-section-body-fieldmap-guide">
            <div class="wbs-checklist">
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">1</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'EAN / GTIN should point to a field that contains a valid 12 or 13 digit code', 'woo-bol-sync' ); ?></strong></div>
                </div>
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">2</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Choose listing title and description sources that are complete and merchant-friendly', 'woo-bol-sync' ); ?></strong></div>
                </div>
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status">3</div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Default delivery code and publish scope affect all synced products', 'woo-bol-sync' ); ?></strong></div>
                </div>
            </div>
        </div>
    </section>

    <div class="wbs-settings-layout">
        <div class="wbs-settings-main">
            <form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
                <?php
                settings_fields( 'wbs_fieldmap_group' );

                global $wp_settings_sections, $wp_settings_fields;
                $fieldmap_page = 'wbs-field-map';
                $fieldmap_sections = $wp_settings_sections[ $fieldmap_page ] ?? [];

                foreach ( $fieldmap_sections as $section_id => $section ) :
                    ?>
                    <section
                        class="wbs-section"
                        data-section="<?php echo esc_attr( $section_id ); ?>"
                        id="wbs-section-<?php echo esc_attr( $section_id ); ?>"
                    >
                        <header class="wbs-section__header">
                            <span class="wbs-section__icon dashicons dashicons-admin-links" aria-hidden="true"></span>
                            <h2 class="wbs-section__title"><?php echo esc_html( $section['title'] ?? '' ); ?></h2>
                            <button
                                type="button"
                                class="wbs-section__toggle"
                                aria-expanded="true"
                                aria-controls="wbs-section-body-<?php echo esc_attr( $section_id ); ?>"
                            >
                                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
                            </button>
                        </header>
                        <?php if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) : ?>
                            <div class="wbs-section__intro">
                                <?php call_user_func( $section['callback'], $section ); ?>
                            </div>
                        <?php endif; ?>
                        <div class="wbs-section__body" id="wbs-section-body-<?php echo esc_attr( $section_id ); ?>">
                            <?php if ( isset( $wp_settings_fields[ $fieldmap_page ][ $section_id ] ) ) : ?>
                                <table class="form-table" role="presentation">
                                    <?php do_settings_fields( $fieldmap_page, $section_id ); ?>
                                </table>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <p>
                    <?php submit_button( __( 'Save mapping', 'woo-bol-sync' ), 'primary', 'submit', false ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-dashboard' ) ); ?>" class="button"><?php esc_html_e( 'Back to dashboard', 'woo-bol-sync' ); ?></a>
                </p>
            </form>
        </div>
        <div class="wbs-settings-sidebar">
            <div class="wbs-card wbs-card--sidebar">
                <h3><?php esc_html_e( 'Recommended defaults', 'woo-bol-sync' ); ?></h3>
                <ul>
                    <li><?php esc_html_e( 'EAN / GTIN: use a product field that always contains a barcode', 'woo-bol-sync' ); ?></li>
                    <li><?php esc_html_e( 'Listing title: use Product name unless you maintain a custom marketplace title', 'woo-bol-sync' ); ?></li>
                    <li><?php esc_html_e( 'Description: short description is usually the safest choice for clean listings', 'woo-bol-sync' ); ?></li>
                </ul>
            </div>
            <div class="wbs-card wbs-card--sidebar">
                <h3><?php esc_html_e( 'Next step', 'woo-bol-sync' ); ?></h3>
                <p style="font-size:12px; color:#646970;">
                    <?php esc_html_e( 'After saving field mapping, return to the dashboard and run a product sync or health check.', 'woo-bol-sync' ); ?>
                </p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-dashboard' ) ); ?>" class="button button-secondary" style="width:100%; text-align:center;">
                    <?php esc_html_e( 'Go to dashboard', 'woo-bol-sync' ); ?>
                </a>
            </div>
        </div>
    </div>

</div>
