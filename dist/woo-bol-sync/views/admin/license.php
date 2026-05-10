<?php
/**
 * License admin page view.
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;

use WooBolSync\Includes\License_Manager;

$key    = License_Manager::get_key();
$status = (string) get_option( License_Manager::STATUS_OPTION, 'inactive' );

$status_labels = [
    'active'   => __( 'Active', 'woo-bol-sync' ),
    'inactive' => __( 'Inactive', 'woo-bol-sync' ),
    'invalid'  => __( 'Invalid', 'woo-bol-sync' ),
    'error'    => __( 'Server Error', 'woo-bol-sync' ),
];

$status_colors = [
    'active'   => '#00a32a',
    'inactive' => '#8c8f94',
    'invalid'  => '#d63638',
    'error'    => '#dba617',
];

$status_label = $status_labels[ $status ] ?? ucfirst( $status );
$status_color = $status_colors[ $status ] ?? '#8c8f94';
?>
<div class="wrap wbs-wrap">

    <h1 class="wbs-page-title">
        <span class="dashicons dashicons-admin-network"></span>
        <?php esc_html_e( 'Bol.com Sync Pro — License', 'woo-bol-sync' ); ?>
    </h1>

    <div class="wbs-settings-layout">

        <!-- ── License form ───────────────────────────────────────────────── -->
        <div class="wbs-settings-main">

            <section class="wbs-section" data-section="license-key" id="wbs-section-license-key">
                <header class="wbs-section__header">
                    <span class="wbs-section__icon dashicons dashicons-admin-network" aria-hidden="true"></span>
                    <h2 class="wbs-section__title"><?php esc_html_e( 'License Key', 'woo-bol-sync' ); ?></h2>
                    <button
                        type="button"
                        class="wbs-section__toggle"
                        aria-expanded="true"
                        aria-controls="wbs-section-body-license-key"
                    >
                        <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                        <span class="wbs-section__toggle-label"><?php esc_html_e( 'Collapse', 'woo-bol-sync' ); ?></span>
                    </button>
                </header>

                <div class="wbs-section__body" id="wbs-section-body-license-key">

                    <div class="wbs-license-status-row">
                        <span class="wbs-status-dot" style="background:<?php echo esc_attr( $status_color ); ?>;"></span>
                        <strong style="color:<?php echo esc_attr( $status_color ); ?>;"><?php echo esc_html( $status_label ); ?></strong>
                        <span class="wbs-license-status-site">
                            <?php
                            printf(
                                /* translators: site URL */
                                esc_html__( 'Registered to: %s', 'woo-bol-sync' ),
                                '<code>' . esc_html( home_url() ) . '</code>'
                            );
                            ?>
                        </span>
                    </div>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="wbs-license-key-input"><?php esc_html_e( 'License Key', 'woo-bol-sync' ); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="text"
                                        id="wbs-license-key-input"
                                        value="<?php echo esc_attr( $key ); ?>"
                                        class="regular-text"
                                        placeholder="XXXX-XXXX-XXXX-XXXX"
                                        autocomplete="off"
                                        spellcheck="false"
                                    />
                                    <p class="description">
                                        <?php esc_html_e( 'Enter the license key you received after purchase. Validation runs against the licensing server, so make sure your shop can reach the internet.', 'woo-bol-sync' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="wbs-license-actions">
                        <button
                            type="button"
                            id="wbs-btn-validate-license"
                            class="button button-primary"
                        >
                            <span class="dashicons dashicons-update wbs-spin" style="display:none;"></span>
                            <?php esc_html_e( 'Validate License', 'woo-bol-sync' ); ?>
                        </button>

                        <?php if ( 'active' === $status ) : ?>
                            <button
                                type="button"
                                id="wbs-btn-deactivate-license"
                                class="button button-secondary wbs-btn-danger"
                                style="margin-left:8px;"
                            >
                                <?php esc_html_e( 'Deactivate', 'woo-bol-sync' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <div id="wbs-license-message" class="wbs-inline-message" style="display:none; margin-top:12px;"></div>

                </div>
            </section>

        </div><!-- /.wbs-settings-main -->

        <!-- ── Sidebar ────────────────────────────────────────────────────── -->
        <div class="wbs-settings-sidebar">

            <div class="wbs-card wbs-card--sidebar">
                <h3><?php esc_html_e( 'What\'s included', 'woo-bol-sync' ); ?></h3>
                <ul>
                    <li>✅ <?php esc_html_e( 'Product sync (WC → bol.com)', 'woo-bol-sync' ); ?></li>
                    <li>✅ <?php esc_html_e( 'Order import (bol.com → WC)', 'woo-bol-sync' ); ?></li>
                    <li>✅ <?php esc_html_e( 'Real-time stock sync', 'woo-bol-sync' ); ?></li>
                    <li>✅ <?php esc_html_e( 'Order status → bol.com', 'woo-bol-sync' ); ?></li>
                    <li>✅ <?php esc_html_e( 'Smart sync (hash-based)', 'woo-bol-sync' ); ?></li>
                    <li>✅ <?php esc_html_e( 'DB-backed logging', 'woo-bol-sync' ); ?></li>
                    <li>✅ <?php esc_html_e( 'Rate limit handling', 'woo-bol-sync' ); ?></li>
                    <li>✅ <?php esc_html_e( '1 year of updates & support', 'woo-bol-sync' ); ?></li>
                </ul>
            </div>

            <div class="wbs-card wbs-card--sidebar">
                <h3><?php esc_html_e( 'Need help?', 'woo-bol-sync' ); ?></h3>
                <p style="font-size:12px; color:#646970;">
                    <?php esc_html_e( 'Lost your license key or need support?', 'woo-bol-sync' ); ?>
                </p>
                <a href="https://yoursite.com/support" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="width:100%; text-align:center; margin-top:4px;">
                    <?php esc_html_e( 'Contact Support ↗', 'woo-bol-sync' ); ?>
                </a>
                <a href="https://yoursite.com/my-account" target="_blank" rel="noopener noreferrer" class="button" style="width:100%; text-align:center; margin-top:6px;">
                    <?php esc_html_e( 'My Account ↗', 'woo-bol-sync' ); ?>
                </a>
            </div>

            <div class="wbs-card wbs-card--sidebar">
                <h3><?php esc_html_e( 'Next step', 'woo-bol-sync' ); ?></h3>
                <p style="font-size:12px; color:#646970;">
                    <?php esc_html_e( 'After activation, return to the dashboard and run the health check to confirm the plugin is ready for live syncing.', 'woo-bol-sync' ); ?>
                </p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbs-dashboard' ) ); ?>" class="button button-secondary" style="width:100%; text-align:center; margin-top:4px;">
                    <?php esc_html_e( 'Go to dashboard', 'woo-bol-sync' ); ?>
                </a>
            </div>

        </div><!-- /.wbs-settings-sidebar -->

    </div><!-- /.wbs-settings-layout -->

</div><!-- /.wrap.wbs-wrap -->
