<?php
/**
 * Staging & Review admin page.
 *
 * Variables (provided by Staging_Admin::render):
 *   @var array<string, int>    $counts
 *   @var array<int, array>     $batches
 *   @var array<int, array>     $jobs
 *
 * @package WooBolSync
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap wbs-staging-wrap">
    <h1><?php esc_html_e( 'Staging & Review', 'woo-bol-sync' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Review mapped product and variation data before anything is sent to bol.com. Edit images, text, prices and stock, approve the records you trust, then sync only the approved rows.', 'woo-bol-sync' ); ?>
    </p>
    <p class="description">
        <?php
        printf(
            /* translators: 1: title source 2: description source 3: ean source */
            esc_html__( 'Current mapping sources — title: %1$s, description: %2$s, EAN: %3$s.', 'woo-bol-sync' ),
            esc_html( (string) ( $field_map['title_source'] ?? 'product_name' ) ),
            esc_html( (string) ( $field_map['description_source'] ?? 'short_description' ) ),
            esc_html( (string) ( $field_map['ean_source'] ?? 'sku' ) )
        );
        ?>
    </p>

    <div class="wbs-staging-toolbar">
        <button type="button" class="button button-primary" id="wbs-staging-ingest">
            <?php esc_html_e( 'Ingest / refresh drafts', 'woo-bol-sync' ); ?>
        </button>
        <button type="button" class="button" id="wbs-staging-sync-approved">
            <?php esc_html_e( 'Sync approved drafts', 'woo-bol-sync' ); ?>
        </button>
        <button type="button" class="button" id="wbs-staging-sync-selected" disabled>
            <?php esc_html_e( 'Sync selected drafts', 'woo-bol-sync' ); ?>
        </button>
        <span class="wbs-selection-summary" id="wbs-staging-selection-summary" aria-live="polite"></span>
        <span class="wbs-staging-status" id="wbs-staging-status" aria-live="polite"></span>
    </div>

    <div class="wbs-staging-counters">
        <div class="wbs-counter"><strong><?php echo esc_html( (string) ( $counts['total'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Total', 'woo-bol-sync' ); ?></span></div>
        <div class="wbs-counter is-ready"><strong data-count="ready"><?php echo esc_html( (string) ( $counts['ready'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Ready', 'woo-bol-sync' ); ?></span></div>
        <div class="wbs-counter is-warning"><strong data-count="warning"><?php echo esc_html( (string) ( $counts['warning'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Warnings', 'woo-bol-sync' ); ?></span></div>
        <div class="wbs-counter is-blocked"><strong data-count="blocked"><?php echo esc_html( (string) ( $counts['blocked'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Blocked', 'woo-bol-sync' ); ?></span></div>
        <div class="wbs-counter is-approved"><strong data-count="approved"><?php echo esc_html( (string) ( $counts['approved'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Approved', 'woo-bol-sync' ); ?></span></div>
        <div class="wbs-counter is-synced"><strong data-count="synced"><?php echo esc_html( (string) ( $counts['synced'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Synced', 'woo-bol-sync' ); ?></span></div>
        <div class="wbs-counter is-failed"><strong data-count="failed"><?php echo esc_html( (string) ( $counts['failed'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Failed', 'woo-bol-sync' ); ?></span></div>
    </div>

    <div class="wbs-staging-filters">
        <div class="wbs-staging-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Quick filters', 'woo-bol-sync' ); ?>">
            <button type="button" class="button button-secondary wbs-tab is-active" data-tab="all"><?php esc_html_e( 'All', 'woo-bol-sync' ); ?></button>
            <button type="button" class="button button-secondary wbs-tab" data-tab="ready"><?php esc_html_e( 'Ready For Sync', 'woo-bol-sync' ); ?></button>
            <button type="button" class="button button-secondary wbs-tab" data-tab="needs_review"><?php esc_html_e( 'Needs Review', 'woo-bol-sync' ); ?></button>
            <button type="button" class="button button-secondary wbs-tab" data-tab="approved"><?php esc_html_e( 'Approved', 'woo-bol-sync' ); ?></button>
            <button type="button" class="button button-secondary wbs-tab" data-tab="variation_missing_images"><?php esc_html_e( 'Variations Missing Image', 'woo-bol-sync' ); ?></button>
        </div>
        <label class="wbs-inline-checkbox">
            <input type="checkbox" id="wbs-staging-include-variations" value="1" checked />
            <?php esc_html_e( 'Show variation rows in table', 'woo-bol-sync' ); ?>
        </label>
        <label class="wbs-inline-checkbox">
            <input type="checkbox" id="wbs-staging-compact-mode" value="1" />
            <?php esc_html_e( 'Compact mode', 'woo-bol-sync' ); ?>
        </label>
        <input type="search" id="wbs-staging-search" placeholder="<?php esc_attr_e( 'Search by name, SKU, or EAN…', 'woo-bol-sync' ); ?>" />
        <select id="wbs-staging-filter-validation">
            <option value=""><?php esc_html_e( 'All validation', 'woo-bol-sync' ); ?></option>
            <option value="ready"><?php esc_html_e( 'Ready', 'woo-bol-sync' ); ?></option>
            <option value="warning"><?php esc_html_e( 'Warnings', 'woo-bol-sync' ); ?></option>
            <option value="blocked"><?php esc_html_e( 'Blocked', 'woo-bol-sync' ); ?></option>
            <option value="pending"><?php esc_html_e( 'Pending', 'woo-bol-sync' ); ?></option>
        </select>
        <select id="wbs-staging-filter-review">
            <option value=""><?php esc_html_e( 'All review states', 'woo-bol-sync' ); ?></option>
            <option value="pending"><?php esc_html_e( 'Pending review', 'woo-bol-sync' ); ?></option>
            <option value="approved"><?php esc_html_e( 'Approved', 'woo-bol-sync' ); ?></option>
            <option value="rejected"><?php esc_html_e( 'Rejected', 'woo-bol-sync' ); ?></option>
        </select>
        <select id="wbs-staging-filter-sync">
            <option value=""><?php esc_html_e( 'All sync states', 'woo-bol-sync' ); ?></option>
            <option value="not_synced"><?php esc_html_e( 'Not synced', 'woo-bol-sync' ); ?></option>
            <option value="synced"><?php esc_html_e( 'Synced', 'woo-bol-sync' ); ?></option>
            <option value="failed"><?php esc_html_e( 'Failed', 'woo-bol-sync' ); ?></option>
        </select>
        <button type="button" class="button" id="wbs-staging-refresh"><?php esc_html_e( 'Refresh', 'woo-bol-sync' ); ?></button>
    </div>

    <div class="wbs-staging-layout">
        <div class="wbs-staging-list">
            <table class="widefat striped" id="wbs-staging-table">
                <thead>
                    <tr>
                        <th style="width:34px;">
                            <input type="checkbox" id="wbs-staging-select-all" aria-label="<?php esc_attr_e( 'Select all rows', 'woo-bol-sync' ); ?>" />
                        </th>
                        <th><?php esc_html_e( 'Product', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'SKU / EAN', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Short Description', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Long Description', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Price', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Stock', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Variations', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Sync Fields', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Validation', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Review', 'woo-bol-sync' ); ?></th>
                        <th><?php esc_html_e( 'Sync', 'woo-bol-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody id="wbs-staging-rows">
                    <tr><td colspan="12"><?php esc_html_e( 'No drafts loaded yet. Click "Ingest / refresh drafts" to generate staging rows.', 'woo-bol-sync' ); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="wbs-staging-modal" id="wbs-staging-modal" aria-hidden="true">
        <div class="wbs-staging-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Edit draft', 'woo-bol-sync' ); ?>">
            <div class="wbs-staging-modal__header">
                <strong><?php esc_html_e( 'Edit Draft', 'woo-bol-sync' ); ?></strong>
                <button type="button" class="button button-secondary" id="wbs-staging-close-modal"><?php esc_html_e( 'Close', 'woo-bol-sync' ); ?></button>
            </div>
            <div class="wbs-staging-edit" id="wbs-staging-edit" aria-live="polite" hidden>
                <p class="wbs-empty-state"><?php esc_html_e( 'Select a draft to edit its product and variation data.', 'woo-bol-sync' ); ?></p>
            </div>
        </div>
    </div>

    <h2 class="wbs-section-title"><?php esc_html_e( 'Recent ingest batches', 'woo-bol-sync' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>#</th>
                <th><?php esc_html_e( 'Label', 'woo-bol-sync' ); ?></th>
                <th><?php esc_html_e( 'Status', 'woo-bol-sync' ); ?></th>
                <th><?php esc_html_e( 'Counts', 'woo-bol-sync' ); ?></th>
                <th><?php esc_html_e( 'Created', 'woo-bol-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $batches ) ) : ?>
                <?php foreach ( $batches as $batch ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $batch['id'] ); ?></td>
                        <td><?php echo esc_html( (string) $batch['label'] ); ?></td>
                        <td><?php echo esc_html( (string) $batch['status'] ); ?></td>
                        <td><code><?php echo esc_html( (string) $batch['counts'] ); ?></code></td>
                        <td><?php echo esc_html( (string) $batch['created_at'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No batches yet.', 'woo-bol-sync' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h2 class="wbs-section-title"><?php esc_html_e( 'Recent sync jobs', 'woo-bol-sync' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>#</th>
                <th><?php esc_html_e( 'Batch', 'woo-bol-sync' ); ?></th>
                <th><?php esc_html_e( 'Status', 'woo-bol-sync' ); ?></th>
                <th><?php esc_html_e( 'Counts', 'woo-bol-sync' ); ?></th>
                <th><?php esc_html_e( 'Message', 'woo-bol-sync' ); ?></th>
                <th><?php esc_html_e( 'Ran at', 'woo-bol-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $jobs ) ) : ?>
                <?php foreach ( $jobs as $job ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $job['id'] ); ?></td>
                        <td><?php echo esc_html( (string) $job['batch_id'] ); ?></td>
                        <td><?php echo esc_html( (string) $job['status'] ); ?></td>
                        <td><code><?php echo esc_html( (string) $job['counts'] ); ?></code></td>
                        <td><?php echo esc_html( (string) $job['message'] ); ?></td>
                        <td><?php echo esc_html( (string) ( $job['ended_at'] ?: $job['created_at'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No sync jobs yet.', 'woo-bol-sync' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
