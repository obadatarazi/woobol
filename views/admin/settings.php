<?php
/**
 * Settings admin page (Settings API).
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;

use WooBolSync\Includes\Mapping_Config;

$eo_id   = Mapping_Config::get_economic_operator_id();
$eo_name = Mapping_Config::get_economic_operator_name();
$eo_st   = Mapping_Config::get_economic_operator_status();
$eo_sync = Mapping_Config::get_economic_operator_last_sync();
$subscription_id = Mapping_Config::get_subscription_id();

if ( $eo_id !== '' ) {
    $eo_status_line = $eo_name !== ''
        ? sprintf(
            /* translators: 1: operator name 2: VALID/INVALID/PENDING */
            __( 'Economic operator: connected — %1$s (%2$s)', 'woo-bol-sync' ),
            $eo_name,
            $eo_st !== '' ? $eo_st : __( 'unknown status', 'woo-bol-sync' )
        )
        : sprintf(
            /* translators: %s: status */
            __( 'Economic operator: connected (%s)', 'woo-bol-sync' ),
            $eo_st !== '' ? $eo_st : __( 'unknown status', 'woo-bol-sync' )
        );
    $eo_connected = true;
} else {
    $eo_status_line = __( 'No economic operator found', 'woo-bol-sync' );
    $eo_connected   = false;
}

?>
<div class="wrap wbs-wrap">

    <h1 class="wbs-page-title">
        <span class="dashicons dashicons-admin-settings"></span>
        <?php esc_html_e( 'Bol.com Sync — Settings', 'woo-bol-sync' ); ?>
    </h1>

    <section class="wbs-section" data-section="setup-guide" id="wbs-section-setup-guide">
        <header class="wbs-section__header">
            <span class="wbs-section__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
            <h2 class="wbs-section__title"><?php esc_html_e( 'Recommended setup order', 'woo-bol-sync' ); ?></h2>
            <button
                type="button"
                class="wbs-section__toggle"
                aria-expanded="true"
                aria-controls="wbs-section-body-setup-guide"
            >
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
            </button>
        </header>
        <div class="wbs-section__body" id="wbs-section-body-setup-guide">
            <div class="wbs-checklist">
                <div class="wbs-checklist__item is-done">
                    <div class="wbs-checklist__status"><?php esc_html_e( '1', 'woo-bol-sync' ); ?></div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Enter Client ID and Client Secret', 'woo-bol-sync' ); ?></strong></div>
                </div>
                <div class="wbs-checklist__item <?php echo $eo_connected ? 'is-done' : 'is-todo'; ?>">
                    <div class="wbs-checklist__status"><?php esc_html_e( '2', 'woo-bol-sync' ); ?></div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Fetch or create the economic operator', 'woo-bol-sync' ); ?></strong></div>
                </div>
                <div class="wbs-checklist__item is-todo">
                    <div class="wbs-checklist__status"><?php esc_html_e( '3', 'woo-bol-sync' ); ?></div>
                    <div class="wbs-checklist__body"><strong><?php esc_html_e( 'Save settings, then continue with category and field mapping', 'woo-bol-sync' ); ?></strong></div>
                </div>
            </div>
        </div>
    </section>

    <div class="wbs-settings-layout">

        <div class="wbs-settings-main">
            <?php
            global $wp_settings_sections, $wp_settings_fields;
            $wbs_settings_page = 'wbs-settings';

            $wbs_section_map = [
                'connection'    => [
                    'label'    => __( 'Connection', 'woo-bol-sync' ),
                    'icon'     => 'admin-network',
                    'sections' => [ 'wbs_sec_credentials' ],
                ],
                'sync-rules'    => [
                    'label'    => __( 'Sync Rules', 'woo-bol-sync' ),
                    'icon'     => 'admin-generic',
                    'sections' => [ 'wbs_sec_sync' ],
                ],
                'product-sched' => [
                    'label'    => __( 'Product Sync Schedule', 'woo-bol-sync' ),
                    'icon'     => 'cart',
                    'sections' => [ 'wbs_sec_product_sched' ],
                ],
                'order-sched'   => [
                    'label'    => __( 'Order Import Schedule', 'woo-bol-sync' ),
                    'icon'     => 'list-view',
                    'sections' => [ 'wbs_sec_order_sched' ],
                ],
                'webhooks'      => [
                    'label'    => __( 'Webhooks', 'woo-bol-sync' ),
                    'icon'     => 'rss',
                    'sections' => [ 'wbs_sec_webhooks' ],
                ],
                'advanced'      => [
                    'label'    => __( 'Advanced & Logs', 'woo-bol-sync' ),
                    'icon'     => 'admin-tools',
                    'sections' => [ 'wbs_sec_dev' ],
                ],
            ];
            ?>

            <form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="wbs-settings-form">
                <?php settings_fields( 'wbs_settings_group' ); ?>

                <?php foreach ( $wbs_section_map as $section_key => $section_def ) : ?>
                    <section
                        class="wbs-section"
                        data-section="<?php echo esc_attr( $section_key ); ?>"
                        id="wbs-section-<?php echo esc_attr( $section_key ); ?>"
                    >
                        <header class="wbs-section__header">
                            <span class="wbs-section__icon dashicons dashicons-<?php echo esc_attr( $section_def['icon'] ); ?>" aria-hidden="true"></span>
                            <h2 class="wbs-section__title"><?php echo esc_html( $section_def['label'] ); ?></h2>
                            <button
                                type="button"
                                class="wbs-section__toggle"
                                aria-expanded="true"
                                aria-controls="wbs-section-body-<?php echo esc_attr( $section_key ); ?>"
                            >
                                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                                <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
                            </button>
                        </header>

                        <?php
                        foreach ( (array) $section_def['sections'] as $section_id ) {
                            if ( ! isset( $wp_settings_sections[ $wbs_settings_page ][ $section_id ] ) ) {
                                continue;
                            }
                            $section = $wp_settings_sections[ $wbs_settings_page ][ $section_id ];

                            if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
                                echo '<div class="wbs-section__intro">';
                                call_user_func( $section['callback'], $section );
                                echo '</div>';
                            }
                        }
                        ?>

                        <div
                            class="wbs-section__body"
                            id="wbs-section-body-<?php echo esc_attr( $section_key ); ?>"
                        >
                            <?php
                            foreach ( (array) $section_def['sections'] as $section_id ) {
                                if ( isset( $wp_settings_fields[ $wbs_settings_page ][ $section_id ] ) ) {
                                    echo '<table class="form-table" role="presentation">';
                                    do_settings_fields( $wbs_settings_page, $section_id );
                                    echo '</table>';
                                }
                            }
                            ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php submit_button( __( 'Save Settings', 'woo-bol-sync' ) ); ?>
            </form>
        </div>

        <div class="wbs-settings-sidebar">
            <div class="wbs-card wbs-card--sidebar" id="wbs-eo-panel">
                <h3><?php esc_html_e( 'Economic operator', 'woo-bol-sync' ); ?></h3>
                <p id="wbs-eo-status" class="wbs-eo-status <?php echo $eo_connected ? 'wbs-eo-status--ok' : 'wbs-eo-status--none'; ?>" style="font-size:13px; margin:0 0 10px;">
                    <?php echo esc_html( $eo_status_line ); ?>
                </p>
                <p id="wbs-eo-id-wrap" class="description" style="margin:0 0 10px; word-break:break-all;<?php echo $eo_id === '' ? ' display:none;' : ''; ?>">
                    <code id="wbs-eo-id-code"><?php echo $eo_id !== '' ? esc_html( $eo_id ) : ''; ?></code>
                </p>
                <p id="wbs-eo-sync-wrap" class="description" style="margin:0 0 10px;<?php echo $eo_sync === '' ? ' display:none;' : ''; ?>">
                    <span id="wbs-eo-sync-label">
                        <?php
                        if ( $eo_sync !== '' ) {
                            printf(
                                /* translators: %s: UTC datetime */
                                esc_html__( 'Last fetched: %s', 'woo-bol-sync' ),
                                esc_html( $eo_sync )
                            );
                        }
                        ?>
                    </span>
                </p>
                <button type="button" id="wbs-btn-fetch-economic-operator" class="button button-secondary" style="width:100%; text-align:center;">
                    <span class="wbs-spin dashicons dashicons-update" style="display:none; vertical-align:middle; margin-top:3px;"></span>
                    <?php esc_html_e( 'Fetch economic operator', 'woo-bol-sync' ); ?>
                </button>
                <p id="wbs-eo-message" class="wbs-inline-msg" style="display:none; margin-top:10px; font-size:12px;"></p>
                <p class="description" style="margin-top:12px; font-size:11px;">
                    <?php esc_html_e( 'Loaded from your bol.com account via the API. You can also create or update the current operator below.', 'woo-bol-sync' ); ?>
                </p>
            </div>

            <div class="wbs-card wbs-card--sidebar" id="wbs-eo-editor">
                <h3><?php esc_html_e( 'Economic operator details', 'woo-bol-sync' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Create or update the economic operator required for bol.com offers.', 'woo-bol-sync' ); ?></p>
                <div class="wbs-eo-grid">
                    <p><label><strong><?php esc_html_e( 'Name', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-name" class="regular-text" value="<?php echo esc_attr( $eo_form['name'] ?? '' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'Street', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-street" class="regular-text" value="<?php echo esc_attr( $eo_form['street'] ?? '' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'House number', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-houseNumber" class="regular-text" value="<?php echo esc_attr( $eo_form['houseNumber'] ?? '' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'Postal code', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-postalCode" class="regular-text" value="<?php echo esc_attr( $eo_form['postalCode'] ?? '' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'City', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-city" class="regular-text" value="<?php echo esc_attr( $eo_form['city'] ?? '' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'Country', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-country" class="regular-text" maxlength="2" value="<?php echo esc_attr( $eo_form['country'] ?? 'NL' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'Email', 'woo-bol-sync' ); ?></strong><br /><input type="email" id="wbs-eo-emailAddress" class="regular-text" value="<?php echo esc_attr( $eo_form['emailAddress'] ?? '' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'Phone number', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-phoneNumber" class="regular-text" value="<?php echo esc_attr( $eo_form['phoneNumber'] ?? '' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'Additional address info', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-additionalAddressInfo" class="regular-text" value="<?php echo esc_attr( $eo_form['additionalAddressInfo'] ?? '' ); ?>" /></label></p>
                    <p><label><strong><?php esc_html_e( 'External reference', 'woo-bol-sync' ); ?></strong><br /><input type="text" id="wbs-eo-externalReference" class="regular-text" value="<?php echo esc_attr( $eo_form['externalReference'] ?? '' ); ?>" /></label></p>
                </div>
                <p style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" id="wbs-btn-save-economic-operator" class="button button-secondary">
                        <span class="wbs-spin dashicons dashicons-update" style="display:none; vertical-align:middle; margin-top:3px;"></span>
                        <?php echo $eo_id !== '' ? esc_html__( 'Update economic operator', 'woo-bol-sync' ) : esc_html__( 'Create economic operator', 'woo-bol-sync' ); ?>
                    </button>
                    <?php if ( $eo_id !== '' ) : ?>
                        <button type="button" id="wbs-btn-delete-economic-operator" class="button button-link-delete">
                            <?php esc_html_e( 'Delete economic operator', 'woo-bol-sync' ); ?>
                        </button>
                    <?php endif; ?>
                </p>
                <p id="wbs-eo-editor-message" class="wbs-inline-msg" style="display:none; margin-top:10px; font-size:12px;"></p>
            </div>

            <div class="wbs-card wbs-card--sidebar">
                <h3><?php esc_html_e( 'Automation', 'woo-bol-sync' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Webhook callback URL used for bol.com PROCESS_STATUS push notifications.', 'woo-bol-sync' ); ?>
                </p>
                <p><code style="word-break:break-all;"><?php echo esc_html( Mapping_Config::get_webhook_url() ); ?></code></p>
                <p class="description">
                    <?php
                    if ( $subscription_id !== '' ) {
                        printf(
                            /* translators: %s: subscription id */
                            esc_html__( 'Current subscription ID: %s', 'woo-bol-sync' ),
                            esc_html( $subscription_id )
                        );
                    } else {
                        esc_html_e( 'No subscription stored yet. Use the dashboard tool or wait for cron to ensure it automatically.', 'woo-bol-sync' );
                    }
                    ?>
                </p>
            </div>

            <div class="wbs-card wbs-card--sidebar">
                <h3><?php esc_html_e( 'Documentation', 'woo-bol-sync' ); ?></h3>
                <p style="font-size:12px; color:#646970;">
                    <?php esc_html_e( 'Create API credentials in the bol.com seller portal under Settings → API.', 'woo-bol-sync' ); ?>
                </p>
                <a href="https://partner.bol.com/sdd/nl/login" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="width:100%; text-align:center;">
                    <?php esc_html_e( 'Seller portal ↗', 'woo-bol-sync' ); ?>
                </a>
                <p style="margin-top:12px; font-size:12px; color:#646970;">
                    <?php esc_html_e( 'Configure field mapping and optional category mapping:', 'woo-bol-sync' ); ?>
                </p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-categories' ) ); ?>" class="button" style="width:100%; text-align:center; margin-top:4px;">
                    <?php esc_html_e( 'Optional category mapping', 'woo-bol-sync' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-field-map' ) ); ?>" class="button" style="width:100%; text-align:center; margin-top:6px;">
                    <?php esc_html_e( 'Field mapping', 'woo-bol-sync' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-dashboard' ) ); ?>" class="button" style="width:100%; text-align:center; margin-top:6px;">
                    <?php esc_html_e( 'Back to dashboard', 'woo-bol-sync' ); ?>
                </a>
            </div>
        </div>

    </div>

</div>
