<?php
/**
 * Post-sync reconciliation checks for staging jobs.
 *
 * Compares what was expected from the staged drafts vs. what actually got
 * synced so admins can see discrepancies in variation counts and images.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Services;

use WooBolSync\Includes\Logger;
use WooBolSync\Models\Product_Draft;
use WooBolSync\Models\Sync_Audit;
use WooBolSync\Models\Sync_Job;
use WooBolSync\Models\Variation_Draft;

defined( 'ABSPATH' ) || exit;

class Reconciliation_Service {

    /**
     * Build a reconciliation report for a sync job.
     *
     * @return array{
     *   job_id:int,
     *   products_checked:int,
     *   variations_expected:int,
     *   variations_synced:int,
     *   variations_missing:int,
     *   images_expected:int,
     *   images_missing:int,
     *   issues:array<int, array<string, mixed>>
     * }
     */
    public function report_for_job( int $job_id ): array {
        $items = Sync_Job::items_for_job( $job_id );

        $report = [
            'job_id'              => $job_id,
            'products_checked'    => 0,
            'variations_expected' => 0,
            'variations_synced'   => 0,
            'variations_missing'  => 0,
            'images_expected'     => 0,
            'images_missing'      => 0,
            'issues'              => [],
        ];

        foreach ( $items as $item ) {
            if ( (string) $item['entity_type'] !== 'product' ) {
                continue;
            }
            $draft = Product_Draft::get( (int) $item['draft_id'] );
            if ( ! $draft ) {
                continue;
            }

            ++$report['products_checked'];

            // Image reconciliation.
            $main_image = (string) $draft['main_image_url'];
            $sync_images_enabled = (int) ( $draft['sync_images'] ?? 0 ) === 1;
            if ( $sync_images_enabled && $main_image !== '' ) {
                ++$report['images_expected'];
                if ( $item['status'] !== Sync_Job::ITEM_SUCCESS ) {
                    ++$report['images_missing'];
                    $report['issues'][] = [
                        'type'        => 'image_not_uploaded',
                        'draft_id'    => (int) $draft['id'],
                        'wc_product_id' => (int) $draft['wc_product_id'],
                        'main_image_url' => $main_image,
                    ];
                }
            }

            // Variation reconciliation.
            $expected_variations = Variation_Draft::get_for_product_draft( (int) $draft['id'] );
            $expected_count      = count( $expected_variations );
            $synced_count        = 0;
            foreach ( $expected_variations as $v ) {
                if ( (string) $v['sync_status'] === Product_Draft::SYNC_SYNCED ) {
                    ++$synced_count;
                }
            }

            $report['variations_expected'] += $expected_count;
            $report['variations_synced']   += $synced_count;
            if ( $expected_count > $synced_count ) {
                $report['variations_missing'] += ( $expected_count - $synced_count );
                $report['issues'][] = [
                    'type'        => 'variation_count_mismatch',
                    'draft_id'    => (int) $draft['id'],
                    'wc_product_id' => (int) $draft['wc_product_id'],
                    'expected'    => $expected_count,
                    'synced'      => $synced_count,
                ];
            }
        }

        Sync_Audit::log_change(
            Sync_Audit::ENTITY_JOB,
            $job_id,
            'reconciliation',
            '',
            wp_json_encode(
                [
                    'products_checked'    => $report['products_checked'],
                    'variations_expected' => $report['variations_expected'],
                    'variations_synced'   => $report['variations_synced'],
                    'variations_missing'  => $report['variations_missing'],
                    'images_expected'     => $report['images_expected'],
                    'images_missing'      => $report['images_missing'],
                    'issue_count'         => count( $report['issues'] ),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            0,
            'post_sync_reconcile'
        );

        if ( $report['issues'] !== [] ) {
            Logger::warning(
                'Staging sync reconciliation found discrepancies.',
                $report,
                'staging'
            );
        } else {
            Logger::info(
                'Staging sync reconciliation clean.',
                $report,
                'staging'
            );
        }

        return $report;
    }
}
