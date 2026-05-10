<?php
/**
 * Syncs approved product drafts from the staging tables to bol.com.
 *
 * Uses the admin-reviewed `final_payload_json` as the single source of truth
 * instead of re-reading live WooCommerce data. This is the path that fixes
 * "wrong images / wrong variation data" since the data is frozen by the admin.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Services;

use WooBolSync\Includes\Logger;
use WooBolSync\Includes\Mapping_Config;
use WooBolSync\Models\Product_Draft;
use WooBolSync\Models\Product_Mapping;
use WooBolSync\Models\Sync_Audit;
use WooBolSync\Models\Sync_Job;
use WooBolSync\Models\Variation_Draft;

defined( 'ABSPATH' ) || exit;

class Staging_Sync_Service {

    private Bol_API_Service $api;

    public function __construct( Bol_API_Service $api ) {
        $this->api = $api;
    }

    /**
     * Sync approved drafts.
     *
     * @return array{job_id:int, success:int, failed:int, skipped:int, total:int}
     */
    public function sync_approved( int $limit = 25, int $batch_id = 0 ): array {
        $draft_ids = Product_Draft::get_syncable_ids( $limit );
        return $this->sync_draft_ids( $draft_ids, $batch_id );
    }

    /**
     * Sync an explicit set of selected product drafts and/or variation drafts.
     * Non-syncable IDs are ignored safely.
     *
     * @param int[] $selected_draft_ids
     * @param int[] $selected_variation_draft_ids
     * @return array{job_id:int, success:int, failed:int, skipped:int, total:int}
     */
    public function sync_selected( array $selected_draft_ids, array $selected_variation_draft_ids = [], int $batch_id = 0 ): array {
        $selected = array_values( array_unique( array_map( 'absint', $selected_draft_ids ) ) );
        $selected_variations = array_values( array_unique( array_map( 'absint', $selected_variation_draft_ids ) ) );
        if ( $selected === [] && $selected_variations === [] ) {
            return $this->sync_draft_ids( [], $batch_id );
        }

        // Only allow product IDs that are currently approved + syncable.
        $syncable_pool = Product_Draft::get_syncable_ids( 500 );
        $draft_ids = array_values( array_intersect( $syncable_pool, $selected ) );
        $variation_ids = array_values( array_filter( $selected_variations, static function ( int $id ): bool {
            return $id > 0;
        } ) );

        return $this->sync_item_ids( $draft_ids, $variation_ids, $batch_id );
    }

    /**
     * @param int[] $draft_ids
     * @return array{job_id:int, success:int, failed:int, skipped:int, total:int}
     */
    private function sync_draft_ids( array $draft_ids, int $batch_id = 0 ): array {
        if ( $batch_id > 0 ) {
            $draft_ids = array_values( array_filter( $draft_ids, static function ( int $id ) use ( $batch_id ): bool {
                $row = Product_Draft::get( $id );
                return $row && (int) $row['batch_id'] === $batch_id;
            } ) );
        }

        $job_id = Sync_Job::create( $batch_id, function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
        Sync_Job::mark_started( $job_id );

        $stats = [
            'job_id'  => $job_id,
            'success' => 0,
            'failed'  => 0,
            'skipped' => 0,
            'total'   => count( $draft_ids ),
        ];

        foreach ( $draft_ids as $draft_id ) {
            $result = $this->sync_draft( $job_id, $draft_id );
            if ( $result === 'success' ) {
                ++$stats['success'];
            } elseif ( $result === 'failed' ) {
                ++$stats['failed'];
            } else {
                ++$stats['skipped'];
            }
        }

        $reconcile   = ( new Reconciliation_Service() )->report_for_job( $job_id );

        $stats['reconcile'] = $reconcile;

        $stale_items = Sync_Job::finalize_stale_running_items( $job_id );
        if ( $stale_items > 0 ) {
            $stats['failed'] += $stale_items;
        }

        $final_status = $stats['failed'] > 0 && $stats['success'] === 0 ? Sync_Job::STATUS_FAILED : Sync_Job::STATUS_DONE;

        $message = sprintf(
            /* translators: 1: success, 2: failed, 3: skipped, 4: total, 5: variations expected, 6: variations synced, 7: images missing */
            'Staging sync summary — success: %1$d, failed: %2$d, skipped: %3$d, total: %4$d. Variations %6$d/%5$d synced, images missing: %7$d.',
            $stats['success'],
            $stats['failed'],
            $stats['skipped'],
            $stats['total'],
            $reconcile['variations_expected'],
            $reconcile['variations_synced'],
            $reconcile['images_missing']
        );
        $message .= $this->first_job_item_failure_suffix( $job_id );

        Sync_Job::mark_finished( $job_id, $final_status, $stats, $message );

        return $stats;
    }

    /**
     * @param int[] $draft_ids
     * @param int[] $variation_draft_ids
     * @return array{job_id:int, success:int, failed:int, skipped:int, total:int}
     */
    private function sync_item_ids( array $draft_ids, array $variation_draft_ids, int $batch_id = 0 ): array {
        if ( $batch_id > 0 ) {
            $draft_ids = array_values( array_filter( $draft_ids, static function ( int $id ) use ( $batch_id ): bool {
                $row = Product_Draft::get( $id );
                return $row && (int) $row['batch_id'] === $batch_id;
            } ) );

            $variation_draft_ids = array_values( array_filter( $variation_draft_ids, static function ( int $id ) use ( $batch_id ): bool {
                $variation = Variation_Draft::get( $id );
                if ( ! is_array( $variation ) ) {
                    return false;
                }
                $parent = Product_Draft::get( (int) ( $variation['product_draft_id'] ?? 0 ) );
                return is_array( $parent ) && (int) ( $parent['batch_id'] ?? 0 ) === $batch_id;
            } ) );
        }

        $job_id = Sync_Job::create( $batch_id, function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
        Sync_Job::mark_started( $job_id );

        $stats = [
            'job_id'  => $job_id,
            'success' => 0,
            'failed'  => 0,
            'skipped' => 0,
            'total'   => count( $draft_ids ) + count( $variation_draft_ids ),
        ];

        foreach ( $draft_ids as $draft_id ) {
            $result = $this->sync_draft( $job_id, $draft_id );
            if ( $result === 'success' ) {
                ++$stats['success'];
            } elseif ( $result === 'failed' ) {
                ++$stats['failed'];
            } else {
                ++$stats['skipped'];
            }
        }

        foreach ( $variation_draft_ids as $variation_draft_id ) {
            $result = $this->sync_selected_variation_draft( $job_id, $variation_draft_id );
            if ( $result === 'success' ) {
                ++$stats['success'];
            } elseif ( $result === 'failed' ) {
                ++$stats['failed'];
            } else {
                ++$stats['skipped'];
            }
        }

        $reconcile   = ( new Reconciliation_Service() )->report_for_job( $job_id );
        $stats['reconcile'] = $reconcile;

        $stale_items = Sync_Job::finalize_stale_running_items( $job_id );
        if ( $stale_items > 0 ) {
            $stats['failed'] += $stale_items;
        }

        $final_status = $stats['failed'] > 0 && $stats['success'] === 0 ? Sync_Job::STATUS_FAILED : Sync_Job::STATUS_DONE;

        $message = sprintf(
            /* translators: 1: success, 2: failed, 3: skipped, 4: total, 5: variations expected, 6: variations synced, 7: images missing */
            'Staging sync summary — success: %1$d, failed: %2$d, skipped: %3$d, total: %4$d. Variations %6$d/%5$d synced, images missing: %7$d.',
            $stats['success'],
            $stats['failed'],
            $stats['skipped'],
            $stats['total'],
            $reconcile['variations_expected'],
            $reconcile['variations_synced'],
            $reconcile['images_missing']
        );
        $message .= $this->first_job_item_failure_suffix( $job_id );

        Sync_Job::mark_finished( $job_id, $final_status, $stats, $message );

        return $stats;
    }

    /**
     * Append first failed job item error to the job message (visible in DB + Recent sync jobs).
     */
    private function first_job_item_failure_suffix( int $job_id ): string {
        foreach ( Sync_Job::items_for_job( $job_id ) as $item ) {
            if ( (string) ( $item['status'] ?? '' ) !== Sync_Job::ITEM_FAILED ) {
                continue;
            }
            $err = trim( (string) ( $item['error_message'] ?? '' ) );
            if ( $err === '' ) {
                continue;
            }
            return ' ' . sprintf(
                /* translators: %s: first sync failure detail from bol.com or validation */
                __( 'First error: %s', 'woo-bol-sync' ),
                $err
            );
        }
        return '';
    }

    /**
     * @return string one of success|failed|skipped
     */
    private function sync_draft( int $job_id, int $draft_id ): string {
        $draft = Product_Draft::get( $draft_id );
        if ( ! $draft ) {
            return 'skipped';
        }

        $item_id = Sync_Job::add_item( $job_id, $draft_id, 'product' );
        Sync_Job::mark_item_started( $item_id );

        Product_Draft::update( $draft_id, [ 'sync_status' => Product_Draft::SYNC_SYNCING ] );

        $final_payload = $this->decode_json( (string) ( $draft['final_payload_json'] ?? '' ) );
        $wc_product_id = (int) $draft['wc_product_id'];
        $is_variable_parent = (string) ( $draft['product_type'] ?? '' ) === 'variable';

        if ( $is_variable_parent ) {
            return $this->sync_variable_parent_draft( $job_id, $draft, $item_id );
        }

        $ean           = Mapping_Config::normalize_offer_ean( (string) ( $final_payload['ean'] ?? $draft['ean'] ?? '' ) );

        if ( $ean === '' ) {
            return $this->mark_failed( $draft_id, $item_id, 'Missing or invalid EAN in final payload.', $final_payload );
        }

        $offer_payload = $this->build_offer_payload( $final_payload, $draft );
        $sync_price    = (int) ( $draft['sync_price'] ?? 1 ) === 1 && Mapping_Config::sync_offer_price_enabled();
        $sync_stock    = (int) ( $draft['sync_stock'] ?? 1 ) === 1 && Mapping_Config::sync_offer_stock_enabled();
        $sync_content  = (int) ( $draft['sync_content'] ?? 0 ) === 1;
        $sync_images   = (int) ( $draft['sync_images'] ?? 0 ) === 1;

        $offer_id = $this->resolve_offer_id_for_ean( $wc_product_id, $ean );
        $is_update = $offer_id !== '';

        if ( $is_update ) {
            if ( Mapping_Config::sync_content_name_enabled() ) {
                $response = $this->api->request_with_headers(
                    '/offers/' . rawurlencode( $offer_id ),
                    'PUT',
                    $this->build_offer_update_payload( $offer_payload )
                );
            } else {
                $response = [
                    'code'    => 200,
                    'body'    => [ 'offerId' => $offer_id ],
                    'summary' => '',
                    'headers' => [],
                ];
            }
            if ( ! is_wp_error( $response ) && $response['code'] >= 200 && $response['code'] < 300 ) {
                if ( $sync_stock ) {
                    $stock_response = $this->api->request_with_headers(
                        '/offers/' . rawurlencode( $offer_id ) . '/stock',
                        'PUT',
                        $offer_payload['stock']
                    );
                    if ( is_wp_error( $stock_response ) || $stock_response['code'] < 200 || $stock_response['code'] >= 300 ) {
                        $response = $stock_response;
                    }
                }
                if ( ! is_wp_error( $response ) && $response['code'] >= 200 && $response['code'] < 300 && $sync_price ) {
                    $price_response = $this->api->request_with_headers(
                        '/offers/' . rawurlencode( $offer_id ) . '/price',
                        'PUT',
                        [ 'pricing' => $offer_payload['pricing'] ]
                    );
                    if ( is_wp_error( $price_response ) || $price_response['code'] < 200 || $price_response['code'] >= 300 ) {
                        $response = $price_response;
                    }
                }
            }
        } else {
            $response = $this->api->request_with_headers( '/offers', 'POST', $offer_payload );
        }

        if ( is_wp_error( $response ) ) {
            return $this->mark_failed( $draft_id, $item_id, $response->get_error_message(), $offer_payload, [] );
        }
        if ( $response['code'] < 200 || $response['code'] >= 300 ) {
            return $this->mark_failed(
                $draft_id,
                $item_id,
                (string) ( $response['summary'] ?? 'HTTP ' . $response['code'] ),
                $offer_payload,
                is_array( $response['body'] ?? null ) ? $response['body'] : []
            );
        }

        $resolution = $this->api->resolve_offer_response( $response );
        if ( $resolution['status'] === 'FAILURE' ) {
            return $this->mark_failed(
                $draft_id,
                $item_id,
                $resolution['error'] !== '' ? $resolution['error'] : 'Offer processing failed on bol.com.',
                $offer_payload,
                is_array( $response['body'] ?? null ) ? $response['body'] : []
            );
        }

        $resolved_offer_id = $resolution['offer_id'] !== '' ? $resolution['offer_id'] : $offer_id;

        $push_name = $sync_content && Mapping_Config::sync_content_name_enabled();
        $push_desc = $sync_content && Mapping_Config::sync_content_description_enabled();
        $push_img  = $sync_images && Mapping_Config::sync_content_images_enabled();
        if ( $push_name || $push_desc || $push_img ) {
            $this->sync_catalog_content( $final_payload, $ean, $push_img, $wc_product_id, $push_name, $push_desc );
        }

        if ( $resolved_offer_id !== '' ) {
            Product_Mapping::save_row(
                $wc_product_id,
                $resolved_offer_id,
                $ean,
                '',
                [
                    'failed'            => false,
                    'pending_async'     => $resolution['status'] === 'TIMEOUT',
                    'message'           => '',
                    'synced'            => current_time( 'mysql', true ),
                    'product_name'      => (string) $draft['name'],
                    'source'            => 'staging',
                    'process_status_id' => $resolution['process_status_id'] ?? '',
                ]
            );
        }

        Product_Draft::update(
            $draft_id,
            [
                'sync_status'     => Product_Draft::SYNC_SYNCED,
                'last_sync_error' => '',
                'last_synced_at'  => current_time( 'mysql', true ),
            ]
        );

        Sync_Job::record_item_result(
            $item_id,
            Sync_Job::ITEM_SUCCESS,
            $offer_payload,
            is_array( $response['body'] ?? null ) ? $response['body'] : [],
            ''
        );

        Sync_Audit::log_change(
            Sync_Audit::ENTITY_PRODUCT_DRAFT,
            $draft_id,
            'sync_status',
            (string) $draft['sync_status'],
            Product_Draft::SYNC_SYNCED,
            0,
            'staging_sync'
        );

        Logger::info(
            'Staging sync success.',
            [
                'draft_id'     => $draft_id,
                'wc_product_id' => $wc_product_id,
                'offer_id'     => $resolved_offer_id,
                'ean'          => $ean,
                'operation'    => $is_update ? 'updated' : 'created',
            ],
            'staging'
        );

        return 'success';
    }

    /**
     * Variable parent should sync its sellable variations, not the container.
     *
     * @param array<string,mixed> $draft
     */
    private function sync_variable_parent_draft( int $job_id, array $draft, int $parent_item_id ): string {
        $draft_id    = (int) $draft['id'];
        $variations  = Variation_Draft::get_for_product_draft( $draft_id );
        $variations  = array_values(
            array_filter(
                $variations,
                static function ( array $variation ): bool {
                    $validation = (string) ( $variation['validation_status'] ?? '' );
                    $sync       = (string) ( $variation['sync_status'] ?? '' );
                    return in_array( $validation, [ Product_Draft::VALIDATION_READY, Product_Draft::VALIDATION_WARNING ], true )
                        && in_array( $sync, [ Product_Draft::SYNC_NOT_SYNCED, Product_Draft::SYNC_QUEUED, Product_Draft::SYNC_FAILED ], true );
                }
            )
        );
        $successes   = 0;
        $failures    = 0;

        if ( $variations === [] ) {
            return $this->mark_failed( $draft_id, $parent_item_id, 'Variable product has no syncable variation drafts (ready/warning + approved parent).', [] );
        }

        foreach ( $variations as $variation ) {
            $item_id = Sync_Job::add_item( $job_id, (int) $variation['id'], 'variation' );
            Sync_Job::mark_item_started( $item_id );
            $ok = $this->sync_variation_offer( $variation, $draft, $item_id );
            if ( $ok ) {
                ++$successes;
            } else {
                ++$failures;
            }
        }

        if ( $successes > 0 && $failures === 0 ) {
            Product_Draft::update(
                $draft_id,
                [
                    'sync_status'     => Product_Draft::SYNC_SYNCED,
                    'last_sync_error' => '',
                    'last_synced_at'  => current_time( 'mysql', true ),
                ]
            );
            Sync_Job::record_item_result(
                $parent_item_id,
                Sync_Job::ITEM_SUCCESS,
                [ 'variation_count' => count( $variations ) ],
                [ 'synced' => $successes, 'failed' => $failures ],
                ''
            );
            return 'success';
        }

        Product_Draft::update(
            $draft_id,
            [
                'sync_status'     => Product_Draft::SYNC_FAILED,
                'last_sync_error' => sprintf( 'Variation sync failures: %d/%d', $failures, count( $variations ) ),
                'last_synced_at'  => current_time( 'mysql', true ),
            ]
        );
        Sync_Job::record_item_result(
            $parent_item_id,
            Sync_Job::ITEM_FAILED,
            [ 'variation_count' => count( $variations ) ],
            [ 'synced' => $successes, 'failed' => $failures ],
            sprintf( 'One or more variations failed (%d).', $failures )
        );
        return 'failed';
    }

    /**
     * Sync a single variation draft directly (without syncing sibling variations).
     *
     * @return string one of success|failed|skipped
     */
    private function sync_selected_variation_draft( int $job_id, int $variation_draft_id ): string {
        $variation = Variation_Draft::get( $variation_draft_id );
        if ( ! is_array( $variation ) ) {
            return 'skipped';
        }

        $item_id = Sync_Job::add_item( $job_id, $variation_draft_id, 'variation' );
        Sync_Job::mark_item_started( $item_id );

        $parent_draft = Product_Draft::get( (int) ( $variation['product_draft_id'] ?? 0 ) );
        if ( ! is_array( $parent_draft ) ) {
            Sync_Job::record_item_result(
                $item_id,
                Sync_Job::ITEM_SKIPPED,
                [],
                [],
                'Parent product draft not found for selected variation.'
            );
            return 'skipped';
        }

        $parent_review = (string) ( $parent_draft['review_status'] ?? '' );
        $variation_validation = (string) ( $variation['validation_status'] ?? '' );
        $variation_sync = (string) ( $variation['sync_status'] ?? '' );

        $is_syncable = $parent_review === Product_Draft::REVIEW_APPROVED
            && in_array( $variation_validation, [ Product_Draft::VALIDATION_READY, Product_Draft::VALIDATION_WARNING ], true )
            && in_array( $variation_sync, [ Product_Draft::SYNC_NOT_SYNCED, Product_Draft::SYNC_QUEUED, Product_Draft::SYNC_FAILED ], true );

        if ( ! $is_syncable ) {
            Sync_Job::record_item_result(
                $item_id,
                Sync_Job::ITEM_SKIPPED,
                [],
                [],
                'Selected variation is not syncable (parent must be approved, variation must be ready/warning and not already synced).'
            );
            return 'skipped';
        }

        $ok = $this->sync_variation_offer( $variation, $parent_draft, $item_id );
        return $ok ? 'success' : 'failed';
    }

    /**
     * @param array<string,mixed> $variation
     * @param array<string,mixed> $parent_draft
     */
    private function sync_variation_offer( array $variation, array $parent_draft, int $item_id ): bool {
        $variation_id = (int) $variation['id'];
        $wc_variation_id = (int) $variation['wc_variation_id'];
        $final_payload = $this->decode_json( (string) ( $variation['final_payload_json'] ?? '' ) );
        $ean = Mapping_Config::normalize_offer_ean( (string) ( $final_payload['ean'] ?? $variation['ean'] ?? '' ) );
        if ( $ean === '' ) {
            Variation_Draft::update(
                $variation_id,
                [
                    'sync_status' => Product_Draft::SYNC_FAILED,
                    'last_sync_error' => 'Missing or invalid EAN in variation final payload.',
                    'last_synced_at' => current_time( 'mysql', true ),
                ]
            );
            Sync_Job::record_item_result( $item_id, Sync_Job::ITEM_FAILED, $final_payload, [], 'Missing or invalid EAN in variation final payload.' );
            return false;
        }

        try {
            // Merge parent and variation data:
            // - Parent provides: title base, descriptions, gallery, categories
            // - Variation provides: EAN, price, stock, SKU, attributes
            // - Variation data overwrites parent data where both exist
            $combined = array_merge(
                $this->decode_json( (string) ( $parent_draft['final_payload_json'] ?? '' ) ),
                $final_payload
            );
            $offer_payload = $this->build_offer_payload( $combined, $variation );
            $offer_id = $this->resolve_offer_id_for_ean( $wc_variation_id, $ean );
            $update_payload = $offer_id !== '' ? $this->build_offer_update_payload( $offer_payload ) : [];
            if ( $offer_id !== '' ) {
                if ( Mapping_Config::sync_content_name_enabled() ) {
                    $response = $this->api->request_with_headers( '/offers/' . rawurlencode( $offer_id ), 'PUT', $update_payload );
                } else {
                    $response = [
                        'code'    => 200,
                        'body'    => [ 'offerId' => $offer_id ],
                        'summary' => '',
                        'headers' => [],
                    ];
                }
                if ( ! is_wp_error( $response ) && $response['code'] >= 200 && $response['code'] < 300 ) {
                    $sync_stock = (int) ( $parent_draft['sync_stock'] ?? 1 ) === 1 && Mapping_Config::sync_offer_stock_enabled();
                    $sync_price = (int) ( $parent_draft['sync_price'] ?? 1 ) === 1 && Mapping_Config::sync_offer_price_enabled();
                    if ( $sync_stock ) {
                        $stock_response = $this->api->request_with_headers(
                            '/offers/' . rawurlencode( $offer_id ) . '/stock',
                            'PUT',
                            $offer_payload['stock']
                        );
                        if ( is_wp_error( $stock_response ) || $stock_response['code'] < 200 || $stock_response['code'] >= 300 ) {
                            $response = $stock_response;
                        }
                    }
                    if ( ! is_wp_error( $response ) && $response['code'] >= 200 && $response['code'] < 300 && $sync_price ) {
                        $price_response = $this->api->request_with_headers(
                            '/offers/' . rawurlencode( $offer_id ) . '/price',
                            'PUT',
                            [ 'pricing' => $offer_payload['pricing'] ]
                        );
                        if ( is_wp_error( $price_response ) || $price_response['code'] < 200 || $price_response['code'] >= 300 ) {
                            $response = $price_response;
                        }
                    }
                }
            } else {
                $response = $this->api->request_with_headers( '/offers', 'POST', $offer_payload );
            }

            if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 ) {
                $message = is_wp_error( $response ) ? $response->get_error_message() : (string) ( $response['summary'] ?? 'Variation offer sync failed.' );
                Variation_Draft::update(
                    $variation_id,
                    [
                        'sync_status' => Product_Draft::SYNC_FAILED,
                        'last_sync_error' => mb_substr( $message, 0, 65535 ),
                        'last_synced_at' => current_time( 'mysql', true ),
                    ]
                );
                Sync_Job::record_item_result(
                    $item_id,
                    Sync_Job::ITEM_FAILED,
                    $offer_payload,
                    is_array( $response['body'] ?? null ) ? $response['body'] : [],
                    $message
                );
                return false;
            }

            $resolution = $this->api->resolve_offer_response( $response );
            if ( $resolution['status'] === 'FAILURE' ) {
                Variation_Draft::update(
                    $variation_id,
                    [
                        'sync_status' => Product_Draft::SYNC_FAILED,
                        'last_sync_error' => mb_substr( (string) $resolution['error'], 0, 65535 ),
                        'last_synced_at' => current_time( 'mysql', true ),
                    ]
                );
                Sync_Job::record_item_result(
                    $item_id,
                    Sync_Job::ITEM_FAILED,
                    $offer_payload,
                    is_array( $response['body'] ?? null ) ? $response['body'] : [],
                    (string) $resolution['error']
                );
                return false;
            }

            $resolved_offer_id = $resolution['offer_id'] !== '' ? $resolution['offer_id'] : $offer_id;

            // Match simple-product staging: push catalog/content for this EAN (Product Group, name, description, images).
            // Variations previously only hit POST/PUT /offers, so bol showed "product group is missing" for API-created offers.
            $sync_content_parent = (int) ( $parent_draft['sync_content'] ?? 0 ) === 1;
            $sync_images_parent  = (int) ( $parent_draft['sync_images'] ?? 0 ) === 1;
            $push_name           = $sync_content_parent && Mapping_Config::sync_content_name_enabled();
            $push_desc           = $sync_content_parent && Mapping_Config::sync_content_description_enabled();
            $push_img            = $sync_images_parent && Mapping_Config::sync_content_images_enabled();
            if ( $push_name || $push_desc || $push_img ) {
                $this->sync_catalog_content( $combined, $ean, $push_img, $wc_variation_id, $push_name, $push_desc );
            } elseif ( $offer_id === '' ) {
                // Brand-new offers from the API often need at least product classification; push EAN + Product Group (default coffee beans).
                $this->sync_catalog_content( $combined, $ean, false, $wc_variation_id, false, false );
            }

            if ( $resolved_offer_id !== '' ) {
                Product_Mapping::save_row(
                    $wc_variation_id,
                    $resolved_offer_id,
                    $ean,
                    '',
                    [
                        'failed' => false,
                        'pending_async' => $resolution['status'] === 'TIMEOUT',
                        'synced' => current_time( 'mysql', true ),
                        'product_name' => (string) ( $parent_draft['name'] ?? '' ),
                        'source' => 'staging_variation',
                        'process_status_id' => $resolution['process_status_id'] ?? '',
                    ]
                );
            }

            Variation_Draft::update(
                $variation_id,
                [
                    'sync_status' => Product_Draft::SYNC_SYNCED,
                    'last_sync_error' => '',
                    'last_synced_at' => current_time( 'mysql', true ),
                ]
            );
            Sync_Job::record_item_result(
                $item_id,
                Sync_Job::ITEM_SUCCESS,
                $offer_payload,
                is_array( $response['body'] ?? null ) ? $response['body'] : [],
                ''
            );
            return true;
        } catch ( \Throwable $e ) {
            Logger::error(
                'Staging variation offer sync failed with an exception.',
                [
                    'item_id' => $item_id,
                    'variation_draft_id' => $variation_id,
                    'message' => $e->getMessage(),
                ],
                'staging_sync'
            );
            $err = sprintf(
                /* translators: %s: exception message */
                __( 'Unexpected error during offer sync: %s', 'woo-bol-sync' ),
                $e->getMessage()
            );
            Variation_Draft::update(
                $variation_id,
                [
                    'sync_status'     => Product_Draft::SYNC_FAILED,
                    'last_sync_error' => mb_substr( $err, 0, 65535 ),
                    'last_synced_at'  => current_time( 'mysql', true ),
                ]
            );
            Sync_Job::record_item_result( $item_id, Sync_Job::ITEM_FAILED, [], [], $err );
            return false;
        }
    }

    private function sync_variation_drafts_for_product( int $product_draft_id ): void {
        $variations = Variation_Draft::get_for_product_draft( $product_draft_id );
        $now        = current_time( 'mysql', true );
        foreach ( $variations as $variation ) {
            Variation_Draft::update(
                (int) $variation['id'],
                [
                    'sync_status'    => Product_Draft::SYNC_SYNCED,
                    'last_synced_at' => $now,
                    'last_sync_error' => '',
                ]
            );
        }
    }

    /**
     * Build the create-offer payload from a staged final payload.
     *
     * @param array<string, mixed> $final
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function build_offer_payload( array $final, array $draft ): array {
        $reference = (string) ( $final['sku'] ?? $draft['sku'] ?? '' );
        if ( $reference === '' ) {
            $reference = (string) ( $draft['wc_product_id'] ?? '' );
        }

        $price = isset( $final['price_for_bol'] )
            ? (float) $final['price_for_bol']
            : Mapping_Config::apply_bol_price_margin( (float) ( $final['regular_price'] ?? 0 ) );

        $stock = isset( $final['stock_quantity'] ) && is_numeric( $final['stock_quantity'] )
            ? max( 0, (int) $final['stock_quantity'] )
            : ( ( $final['stock_status'] ?? '' ) === 'instock' ? 1 : 0 );

        $condition = Mapping_Config::build_offer_condition_new_for_api();

        $wc_pid = (int) ( $draft['wc_variation_id'] ?? $draft['wc_product_id'] ?? 0 );
        $wc_product = $wc_pid > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $wc_pid ) : null;
        $title_fallback = (string) ( $final['name'] ?? $draft['name'] ?? '' );
        $unknown_title  = ( $wc_product instanceof \WC_Product )
            ? Mapping_Config::get_listing_title( $wc_product )
            : $title_fallback;

        $payload = [
            'ean'                 => Mapping_Config::normalize_offer_ean( (string) ( $final['ean'] ?? $draft['ean'] ?? '' ) ),
            'condition'           => $condition,
            'reference'           => mb_substr( $reference, 0, 100 ),
            'onHoldByRetailer'    => false,
            'unknownProductTitle' => mb_substr( $unknown_title, 0, 500 ),
            'pricing'             => [
                'bundlePrices' => [
                    [
                        'quantity'  => 1,
                        'unitPrice' => $price,
                    ],
                ],
            ],
            'stock'               => [
                'amount'            => $stock,
                'managedByRetailer' => true,
            ],
            // Match Product_Sync_Service::build_create_payload — use store defaults only so staging matches dashboard sync.
            'fulfilment'          => Mapping_Config::build_offer_fulfilment_for_api(),
        ];

        $economic_operator_id = Mapping_Config::get_economic_operator_id();
        if ( $economic_operator_id !== '' ) {
            $payload['economicOperatorId'] = $economic_operator_id;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $create_payload
     * @return array<string, mixed>
     */
    private function build_offer_update_payload( array $create_payload ): array {
        return [
            'reference'           => $create_payload['reference'] ?? '',
            'onHoldByRetailer'    => false,
            'unknownProductTitle' => $create_payload['unknownProductTitle'] ?? '',
            'fulfilment'          => $create_payload['fulfilment'] ?? [],
        ];
    }

    /**
     * Push catalog content (main image + title + description) using the staged payload.
     *
     * @param array<string, mixed> $final
     */
    private function sync_catalog_content( array $final, string $ean, bool $include_images, int $wc_product_id = 0, bool $include_name = true, bool $include_description = true ): void {
        $attributes = [
            [
                'id'     => 'EAN',
                'values' => [ [ 'value' => $ean ] ],
            ],
        ];
        $name = (string) ( $final['name'] ?? '' );
        if ( $name !== '' && $include_name ) {
            $attributes[] = [
                'id'     => 'Name',
                'values' => [ [ 'value' => mb_substr( $name, 0, 255 ) ] ],
            ];
        }
        $description = (string) ( $final['description'] ?? $final['short_description'] ?? '' );
        $description = wp_strip_all_tags( $description );
        if ( $description !== '' && $include_description ) {
            $attributes[] = [
                'id'     => 'Description',
                'values' => [ [ 'value' => mb_substr( $description, 0, 2000 ) ] ],
            ];
        }

        $wc_product = null;
        if ( $wc_product_id > 0 && function_exists( 'wc_get_product' ) ) {
            $p = wc_get_product( $wc_product_id );
            if ( $p instanceof \WC_Product ) {
                $wc_product = $p;
            }
        }
        $attributes = Mapping_Config::ensure_product_group_fallback_on_attributes( $attributes, $wc_product );

        $payload = [
            'language'   => 'nl',
            'attributes' => $attributes,
        ];

        // Build assets array with main image + all gallery images
        if ( $include_images ) {
            $assets = [];
            
            // Add main image first with FRONT label
            $main_image_url = (string) ( $final['main_image_url'] ?? '' );
            if ( $main_image_url !== '' ) {
                $assets[] = [
                    'url'    => $main_image_url,
                    'labels' => [ 'FRONT' ],
                ];
            }
            
            // Add all gallery images
            $gallery = is_array( $final['gallery'] ?? null ) ? $final['gallery'] : [];
            $additional_labels = [ 'BACK', 'LEFT', 'RIGHT', 'TOP', 'BOTTOM' ];
            $label_index = 0;
            
            foreach ( $gallery as $gallery_item ) {
                $gallery_url = (string) ( $gallery_item['url'] ?? '' );
                
                // Skip if this gallery image is same as main image (avoid duplicates)
                if ( $gallery_url === '' || $gallery_url === $main_image_url ) {
                    continue;
                }
                
                // Use specific label if available, otherwise use generic label
                $label = $label_index < count( $additional_labels ) 
                    ? $additional_labels[ $label_index ] 
                    : 'IMAGE';
                
                $assets[] = [
                    'url'    => $gallery_url,
                    'labels' => [ $label ],
                ];
                
                $label_index++;
            }
            
            // Only add assets if we have at least one image
            if ( $assets !== [] ) {
                $payload['assets'] = $assets;
            }
        }

        $response = $this->api->create_product_content( $payload );
        if ( is_wp_error( $response ) ) {
            Logger::warning(
                'Staging catalog content call failed.',
                [
                    'ean'     => $ean,
                    'message' => $response->get_error_message(),
                ],
                'staging'
            );
            return;
        }
        if ( $response['code'] < 200 || $response['code'] >= 300 ) {
            Logger::warning(
                'Staging catalog content returned non-2xx.',
                [
                    'ean'     => $ean,
                    'code'    => $response['code'],
                    'summary' => $response['summary'] ?? '',
                ],
                'staging'
            );
        }
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     */
    private function mark_failed( int $draft_id, int $item_id, string $error, array $request, array $response = [] ): string {
        Product_Draft::update(
            $draft_id,
            [
                'sync_status'     => Product_Draft::SYNC_FAILED,
                'last_sync_error' => mb_substr( $error, 0, 65535 ),
                'last_synced_at'  => current_time( 'mysql', true ),
            ]
        );
        Sync_Job::record_item_result( $item_id, Sync_Job::ITEM_FAILED, $request, $response, $error );
        Sync_Audit::log_change(
            Sync_Audit::ENTITY_PRODUCT_DRAFT,
            $draft_id,
            'sync_status',
            Product_Draft::SYNC_SYNCING,
            Product_Draft::SYNC_FAILED,
            0,
            'staging_sync_failed'
        );
        Logger::warning(
            'Staging sync failed for draft.',
            [
                'draft_id' => $draft_id,
                'error'    => $error,
            ],
            'staging'
        );
        return 'failed';
    }

    /**
     * @return array<mixed>|array<string, mixed>
     */
    private function decode_json( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Resolve mapped offer ID for current EAN and auto-reset stale mapping after EAN change.
     */
    private function resolve_offer_id_for_ean( int $wc_product_id, string $ean ): string {
        $offer_id = Product_Mapping::get_offer_id( $wc_product_id );
        $row      = Product_Mapping::get_row( $wc_product_id );

        if ( is_array( $row ) ) {
            $mapped_ean = trim( (string) ( $row['bol_ean'] ?? '' ) );
            if ( $offer_id !== '' && $mapped_ean !== '' && $ean !== '' && $mapped_ean !== $ean ) {
                $meta = [];
                if ( isset( $row['meta'] ) && is_string( $row['meta'] ) && $row['meta'] !== '' ) {
                    $decoded = json_decode( $row['meta'], true );
                    if ( is_array( $decoded ) ) {
                        $meta = $decoded;
                    }
                }
                $meta['ean_changed']    = true;
                $meta['ean_previous']   = $mapped_ean;
                $meta['ean_current']    = $ean;
                $meta['ean_changed_at'] = current_time( 'mysql', true );
                $meta['message']        = __( 'EAN changed in staging. Existing bol offer mapping was reset so the product can be recreated with the new EAN.', 'woo-bol-sync' );
                $meta['failed']         = false;
                $meta['pending_async']  = false;

                Product_Mapping::save_row(
                    $wc_product_id,
                    '',
                    $ean,
                    '',
                    $meta
                );

                Logger::warning(
                    'Staging sync detected EAN change; resetting mapped offer id.',
                    [
                        'wc_product_id' => $wc_product_id,
                        'previous_ean'  => $mapped_ean,
                        'current_ean'   => $ean,
                        'previous_offer_id' => $offer_id,
                    ],
                    'staging'
                );

                $offer_id = '';
            }
        }

        if ( $offer_id === '' && $ean !== '' ) {
            $existing = Product_Mapping::get_by_ean( $ean );
            if ( is_array( $existing ) && ! empty( $existing['bol_offer_id'] ) ) {
                $offer_id = (string) $existing['bol_offer_id'];
            }
        }

        return $offer_id;
    }
}
