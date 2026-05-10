<?php
/**
 * WooCommerce product sync to bol.com.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Services;

use WooBolSync\Includes\Logger;
use WooBolSync\Includes\Mapping_Config;
use WooBolSync\Models\Category_Map;
use WooBolSync\Models\Product_Mapping;

defined( 'ABSPATH' ) || exit;

class Product_Sync_Service {

    public const OPTION_SYNC_CATALOG_OFFSET = 'wbs_sync_catalog_offset';

    private Bol_API_Service $api;

    public function __construct( Bol_API_Service $api ) {
        $this->api = $api;
    }

    /**
     * Sync the next slice of the catalog.
     *
     * Walks all eligible products in deterministic ID order. The current
     * cursor is persisted in {@see self::OPTION_SYNC_CATALOG_OFFSET} so each
     * cron tick (or manual click) advances through the catalog and wraps
     * back to the start once exhausted.
     *
     * @return array{synced:int, failed:int, skipped:int, invalid:int, created:int, updated:int, pending_async:int}
     */
    public function sync_all(): array {
        $stats = [
            'synced'        => 0,
            'failed'        => 0,
            'skipped'       => 0,
            'invalid'       => 0,
            'created'       => 0,
            'updated'       => 0,
            'pending_async' => 0,
        ];

        $batch_size = max( 1, min( 100, (int) get_option( 'wbs_sync_batch_size', 25 ) ) );
        $offset     = max( 0, (int) get_option( self::OPTION_SYNC_CATALOG_OFFSET, 0 ) );

        $query_args = [
            'status'   => Mapping_Config::sync_only_published() ? 'publish' : [ 'publish', 'draft', 'private' ],
            'limit'    => $batch_size,
            'offset'   => $offset,
            'return'   => 'ids',
            'type'     => [ 'simple', 'variation' ],
            'orderby'  => 'ID',
            'order'    => 'ASC',
        ];

        $ids = wc_get_products( $query_args );
        if ( ! is_array( $ids ) ) {
            $ids = [];
        }

        // Wrap the cursor when we have fully cycled through the catalog.
        if ( $ids === [] && $offset > 0 ) {
            $offset       = 0;
            $query_args['offset'] = 0;
            $ids          = wc_get_products( $query_args );
            if ( ! is_array( $ids ) ) {
                $ids = [];
            }
        }

        foreach ( $ids as $product_id ) {
            $result = $this->sync_product_by_id( (int) $product_id );
            if ( isset( $stats[ $result['status'] ] ) ) {
                ++$stats[ $result['status'] ];
            }
            if ( isset( $stats[ $result['operation'] ] ) ) {
                ++$stats[ $result['operation'] ];
            }
        }

        $next_offset = count( $ids ) < $batch_size ? 0 : $offset + count( $ids );
        update_option( self::OPTION_SYNC_CATALOG_OFFSET, $next_offset, false );

        return $stats;
    }

    /**
     * @return array{status:string,operation:string}
     */
    public function sync_product_by_id( int $product_id, bool $force_update = false, bool $existing_only = false ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product instanceof \WC_Product ) {
            return [ 'status' => 'invalid', 'operation' => '' ];
        }

        // Variable parent products are containers; sync sellable variations instead.
        if ( $product->is_type( 'variable' ) ) {
            return [ 'status' => 'skipped', 'operation' => '' ];
        }

        if ( Mapping_Config::product_in_excluded_category( $product ) ) {
            return [ 'status' => 'skipped', 'operation' => '' ];
        }

        $ean = Mapping_Config::get_ean( $product );
        if ( $ean === '' ) {
            $this->store_failed_state( $product, '', '', 'Missing EAN / GTIN.' );
            return [ 'status' => 'invalid', 'operation' => '' ];
        }

        $base_price = Mapping_Config::get_base_price_for_bol( $product );
        if ( $base_price <= 0 ) {
            $this->store_failed_state( $product, '', $ean, 'Missing or invalid WooCommerce price.' );
            return [ 'status' => 'invalid', 'operation' => '' ];
        }

        $offer_id = Product_Mapping::get_offer_id( $product_id );
        if ( $offer_id === '' ) {
            $offer_id = $this->reconcile_offer_id( $product, $ean );
        }
        $offer_id = $this->reset_offer_mapping_on_ean_change( $product, $ean, $offer_id );
        if ( ! Mapping_Config::allow_new_offers() ) {
            $existing_only = true;
        }
        if ( $existing_only && $offer_id === '' ) {
            return [ 'status' => 'skipped', 'operation' => '' ];
        }

        if ( ! $force_update ) {
            $hash         = Mapping_Config::compute_sync_hash( $product );
            $current_hash = Product_Mapping::get_sync_hash( $product_id );
            if ( (int) get_option( 'wbs_smart_sync', 1 ) === 1 && $current_hash !== '' && hash_equals( $current_hash, $hash ) ) {
                return [ 'status' => 'skipped', 'operation' => '' ];
            }
        } else {
            $hash = Mapping_Config::compute_sync_hash( $product );
        }
        $is_update = $offer_id !== '';
        $operation = $is_update ? 'updated' : 'created';

        $create_payload = $this->build_create_payload( $product, $ean, $base_price );
        $update_payload = $this->build_update_payload( $product );
        $sync_content   = Mapping_Config::sync_product_content_enabled();

        $needs_offer_update = $is_update && (
            Mapping_Config::sync_content_name_enabled()
            || Mapping_Config::sync_offer_stock_enabled()
            || Mapping_Config::sync_offer_price_enabled()
        );

        $sync_state = [
            'stock_synced' => false,
            'price_synced' => false,
        ];
        if ( $is_update && ! $needs_offer_update ) {
            $sync_state = [
                'stock_synced' => ! Mapping_Config::sync_offer_stock_enabled(),
                'price_synced' => ! Mapping_Config::sync_offer_price_enabled(),
            ];
            $response = $this->offer_update_skip_response( $offer_id );
        } elseif ( $is_update ) {
            $response = $this->sync_existing_offer( $offer_id, $update_payload, $create_payload['stock'], $create_payload['pricing'], $sync_state );
        } else {
            $response = $this->api->request_with_headers( '/offers', 'POST', $create_payload );
        }

        if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 ) {
            $message = is_wp_error( $response ) ? $response->get_error_message() : (string) $response['summary'];
            $meta    = $this->build_api_failure_meta();
            $message = $this->with_api_failure_hint( $message, $meta );
            $recovery = $this->maybe_schedule_stale_offer_recovery( $product, $offer_id, $ean, $meta );
            if ( ! empty( $recovery['scheduled'] ) ) {
                $message .= ' ' . __( 'Offer mapping was reset and will be recreated on the next sync run.', 'woo-bol-sync' );
            }
            $this->store_failed_state( $product, $offer_id, $ean, $message, array_merge( $meta, $recovery ) );
            if ( ! empty( $recovery['scheduled'] ) ) {
                $this->apply_stale_offer_mapping_reset( $product, $ean, $recovery );
            }
            return [ 'status' => 'failed', 'operation' => '' ];
        }

        $resolution = $this->api->resolve_offer_response( $response );
        if ( $resolution['status'] === '' && $resolution['offer_id'] === '' && $resolution['process_status_id'] === '' ) {
            $this->store_failed_state(
                $product,
                $offer_id,
                $ean,
                __( 'Invalid success response: bol.com returned 2xx without offer or process identifiers.', 'woo-bol-sync' ),
                $this->build_api_failure_meta()
            );
            return [ 'status' => 'failed', 'operation' => '' ];
        }
        if ( $resolution['status'] === 'FAILURE' ) {
            $duplicate_offer_id = $this->extract_duplicate_offer_id( $resolution['error'] );
            if ( $duplicate_offer_id !== '' ) {
                $offer_id  = $duplicate_offer_id;
                $operation = 'updated';
                $retry_needs = Mapping_Config::sync_content_name_enabled()
                    || Mapping_Config::sync_offer_stock_enabled()
                    || Mapping_Config::sync_offer_price_enabled();
                $retry_state  = [
                    'stock_synced' => false,
                    'price_synced' => false,
                ];
                if ( $retry_needs ) {
                    $retry_response = $this->sync_existing_offer( $offer_id, $update_payload, $create_payload['stock'], $create_payload['pricing'], $retry_state );
                } else {
                    $retry_state = [
                        'stock_synced' => ! Mapping_Config::sync_offer_stock_enabled(),
                        'price_synced' => ! Mapping_Config::sync_offer_price_enabled(),
                    ];
                    $retry_response = $this->offer_update_skip_response( $offer_id );
                }
                if ( ! is_wp_error( $retry_response ) && $retry_response['code'] >= 200 && $retry_response['code'] < 300 ) {
                    $sync_state = $retry_state;
                    $resolution = $this->api->resolve_offer_response( $retry_response );
                    if ( $resolution['offer_id'] === '' ) {
                        $resolution['offer_id'] = $offer_id;
                    }
                }
            }
        }
        if ( $resolution['status'] === 'FAILURE' ) {
            $this->store_failed_state(
                $product,
                $offer_id,
                $ean,
                $resolution['error'] !== '' ? $resolution['error'] : __( 'Offer process failed on bol.com.', 'woo-bol-sync' ),
                $this->build_api_failure_meta(
                    [
                        'process_status_id' => $resolution['process_status_id'],
                        'catalog_hint'      => $this->catalog_hint_from_error( $resolution['error'] ),
                        'offer_status'      => $resolution['status'],
                    ]
                )
            );
            return [ 'status' => 'failed', 'operation' => '' ];
        }

        if ( $resolution['status'] === 'TIMEOUT' ) {
            Product_Mapping::save_row(
                $product_id,
                $offer_id,
                $ean,
                '',
                [
                    'failed'            => false,
                    'pending_async'     => true,
                    'message'           => __( 'Offer update accepted but still pending async completion. Retry shortly.', 'woo-bol-sync' ),
                    'synced'            => current_time( 'mysql', true ),
                    'product_name'      => $product->get_name(),
                    'process_status_id' => $resolution['process_status_id'],
                ]
            );
            Logger::warning(
                'Product sync pending async completion.',
                [
                    'product_id'        => $product_id,
                    'product_name'      => $product->get_name(),
                    'process_status_id' => $resolution['process_status_id'],
                ],
                'products'
            );
            return [ 'status' => 'pending_async', 'operation' => '' ];
        }

        $resolved = $resolution['offer_id'] !== '' ? $resolution['offer_id'] : $offer_id;
        if ( $operation === 'created' ) {
            $sync_state = [
                'stock_synced' => true,
                'price_synced' => true,
            ];
        }
        $meta     = [
            'failed'            => false,
            'pending_async'     => false,
            'message'           => '',
            'synced'            => current_time( 'mysql', true ),
            'process_status_id' => $resolution['process_status_id'],
            'offer_status'      => $resolution['status'] !== '' ? $resolution['status'] : 'SUCCESS',
            'catalog_hint'      => '',
            'product_name'      => $product->get_name(),
            'operation'         => $operation,
            'stock_synced'      => (bool) $sync_state['stock_synced'],
            'price_synced'      => (bool) $sync_state['price_synced'],
        ];
        $content_state = $sync_content
            ? $this->sync_catalog_content( $product, $ean )
            : [
                'content_upload_id' => '',
                'catalog_hint'      => '',
                'has_image'         => false,
                'content_synced'    => false,
                'image_synced'      => false,
            ];
        $meta          = array_merge( $meta, $content_state );
        Product_Mapping::save_row(
            $product_id,
            $resolved,
            $ean,
            $hash,
            $meta
        );

        Logger::info(
            'Product synced to bol.com.',
            [
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'offer_id'   => $resolved,
                'ean'        => $ean,
                'process_status_id' => $resolution['process_status_id'],
                'catalog_hint' => $content_state['catalog_hint'] ?? '',
                'content_upload_id' => $content_state['content_upload_id'] ?? '',
                'operation'  => $operation,
                'stock_synced' => (bool) $sync_state['stock_synced'],
                'price_synced' => (bool) $sync_state['price_synced'],
                'image_synced' => (bool) ( $content_state['image_synced'] ?? false ),
            ],
            'products'
        );

        return [ 'status' => 'synced', 'operation' => $operation ];
    }

    /**
     * @return array{synced:int, failed:int, skipped:int, invalid:int, created:int, updated:int, pending_async:int}
     */
    public function force_update_existing_products(): array {
        $stats = [
            'synced'        => 0,
            'failed'        => 0,
            'skipped'       => 0,
            'invalid'       => 0,
            'created'       => 0,
            'updated'       => 0,
            'pending_async' => 0,
        ];

        $ids = Product_Mapping::get_mapped_product_ids();
        foreach ( $ids as $product_id ) {
            $result = $this->sync_product_by_id( (int) $product_id, true, true );
            if ( isset( $stats[ $result['status'] ] ) ) {
                ++$stats[ $result['status'] ];
            }
            if ( isset( $stats[ $result['operation'] ] ) ) {
                ++$stats[ $result['operation'] ];
            }
        }

        return $stats;
    }

    /**
     * @return array{synced:int, failed:int, skipped:int, invalid:int, created:int, updated:int, pending_async:int}
     */
    public function retry_failed_products(): array {
        $stats = [
            'synced'        => 0,
            'failed'        => 0,
            'skipped'       => 0,
            'invalid'       => 0,
            'created'       => 0,
            'updated'       => 0,
            'pending_async' => 0,
        ];

        foreach ( Product_Mapping::get_retryable_rows() as $row ) {
            $result = $this->sync_product_by_id( (int) $row['wc_product_id'] );
            if ( isset( $stats[ $result['status'] ] ) ) {
                ++$stats[ $result['status'] ];
            }
            if ( isset( $stats[ $result['operation'] ] ) ) {
                ++$stats[ $result['operation'] ];
            }
        }

        return $stats;
    }

    private function reconcile_offer_id( \WC_Product $product, string $ean ): string {
        $existing = Product_Mapping::get_by_ean( $ean );
        if ( is_array( $existing ) && ! empty( $existing['bol_offer_id'] ) ) {
            return (string) $existing['bol_offer_id'];
        }

        return '';
    }

    private function reset_offer_mapping_on_ean_change( \WC_Product $product, string $ean, string $offer_id ): string {
        if ( $offer_id === '' ) {
            return '';
        }

        $row = Product_Mapping::get_row( $product->get_id() );
        if ( ! is_array( $row ) ) {
            return $offer_id;
        }

        $mapped_ean = trim( (string) ( $row['bol_ean'] ?? '' ) );
        if ( $mapped_ean === '' || $mapped_ean === $ean ) {
            return $offer_id;
        }

        $meta = $this->decode_mapping_meta( $row['meta'] ?? '' );
        $meta['ean_changed'] = true;
        $meta['ean_previous'] = $mapped_ean;
        $meta['ean_current'] = $ean;
        $meta['ean_changed_at'] = current_time( 'mysql', true );
        $meta['message'] = __( 'EAN changed in WooCommerce. Existing bol offer mapping was reset so the product can be recreated with the new EAN.', 'woo-bol-sync' );
        $meta['failed'] = false;
        $meta['pending_async'] = false;
        $meta['product_name'] = $product->get_name();

        Product_Mapping::save_row(
            $product->get_id(),
            '',
            $ean,
            '',
            $meta
        );

        Logger::warning(
            'EAN changed for mapped product; resetting offer mapping to recreate offer.',
            [
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'previous_ean' => $mapped_ean,
                'current_ean' => $ean,
                'previous_offer_id' => $offer_id,
            ],
            'products'
        );

        return '';
    }

    /**
     * @param array<string, mixed> $extra_meta
     */
    private function store_failed_state( \WC_Product $product, string $offer_id, string $ean, string $message, array $extra_meta = [] ): void {
        $product_id   = $product->get_id();
        $product_name = $product->get_name();

        Product_Mapping::save_row(
            $product_id,
            $offer_id,
            $ean,
            '',
            array_merge(
                [
                'failed'  => true,
                'message' => $message,
                'synced'  => current_time( 'mysql', true ),
                'product_name' => $product_name,
                ],
                $extra_meta
            )
        );

        Logger::error(
            sprintf(
                /* translators: 1: product name, 2: exact error text */
                __( 'Product sync failed for "%1$s": %2$s', 'woo-bol-sync' ),
                $product_name !== '' ? $product_name : '#' . $product_id,
                $message
            ),
            [
                'product_id'   => $product_id,
                'product_name' => $product_name,
                'offer_id'     => $offer_id,
                'ean'          => $ean,
                'message'      => $message,
                'edit_url'     => get_edit_post_link( $product_id, '' ),
                'catalog_hint' => $extra_meta['catalog_hint'] ?? '',
                'process_status_id' => $extra_meta['process_status_id'] ?? '',
                'api_path'     => $extra_meta['api_path'] ?? '',
                'api_method'   => $extra_meta['api_method'] ?? '',
                'api_code'     => $extra_meta['api_code'] ?? '',
                'api_attempt'  => $extra_meta['api_attempt'] ?? '',
                'api_media_type' => $extra_meta['api_media_type'] ?? '',
                'api_request_id' => $extra_meta['api_request_id'] ?? '',
                'api_correlation_id' => $extra_meta['api_correlation_id'] ?? '',
                'api_initial_media_type' => $extra_meta['api_initial_media_type'] ?? '',
                'api_fallback_media_type' => $extra_meta['api_fallback_media_type'] ?? '',
                'api_fallback_trigger_code' => $extra_meta['api_fallback_trigger_code'] ?? '',
                'api_fallback_applied' => $extra_meta['api_fallback_applied'] ?? false,
                'stale_offer_recovery' => $extra_meta['stale_offer_recovery'] ?? '',
                'stale_offer_recovery_scheduled_at' => $extra_meta['stale_offer_recovery_scheduled_at'] ?? '',
            ],
            'products'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build_create_payload( \WC_Product $product, string $ean, float $base_price ): array {
        $reference = (string) $product->get_sku();
        if ( '' === $reference ) {
            $reference = (string) $product->get_id();
        }

        $payload = [
            'ean'                 => $ean,
            'condition'           => Mapping_Config::build_offer_condition_new_for_api(),
            'reference'           => mb_substr( $reference, 0, 100 ),
            'onHoldByRetailer'    => false,
            'unknownProductTitle' => Mapping_Config::get_listing_title( $product ),
            'pricing'             => [
                'bundlePrices' => [
                    [
                        'quantity'  => 1,
                        'unitPrice' => Mapping_Config::apply_bol_price_margin( $base_price ),
                    ],
                ],
            ],
            'stock'               => [
                'amount'            => $this->resolve_stock_amount( $product ),
                'managedByRetailer' => true,
            ],
            'fulfilment'          => Mapping_Config::build_offer_fulfilment_for_api(),
        ];

        $economic_operator_id = Mapping_Config::get_economic_operator_id();
        if ( '' !== $economic_operator_id ) {
            $payload['economicOperatorId'] = $economic_operator_id;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_update_payload( \WC_Product $product ): array {
        $reference = (string) $product->get_sku();
        if ( '' === $reference ) {
            $reference = (string) $product->get_id();
        }

        $payload = [
            'reference'        => mb_substr( $reference, 0, 100 ),
            'onHoldByRetailer' => false,
            'fulfilment'       => Mapping_Config::build_offer_fulfilment_for_api(),
        ];

        if ( Mapping_Config::sync_content_name_enabled() ) {
            $payload['unknownProductTitle'] = Mapping_Config::get_listing_title( $product );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function offer_update_skip_response( string $offer_id ): array {
        return [
            'code'    => 200,
            'body'    => [ 'offerId' => $offer_id ],
            'summary' => '',
            'headers' => [],
        ];
    }

    /**
     * @param array<string, mixed> $offer_payload
     * @param array<string, mixed> $stock_payload
     * @param array<string, mixed> $price_payload
     * @return array<string, mixed>|\WP_Error
     */
    private function sync_existing_offer( string $offer_id, array $offer_payload, array $stock_payload, array $price_payload, array &$sync_state ) {
        $paths = [];
        if ( Mapping_Config::sync_content_name_enabled() ) {
            $paths[] = [ '/offers/' . rawurlencode( $offer_id ), 'PUT', $offer_payload ];
        }
        if ( Mapping_Config::sync_offer_stock_enabled() ) {
            $paths[] = [ '/offers/' . rawurlencode( $offer_id ) . '/stock', 'PUT', $stock_payload ];
        }
        if ( Mapping_Config::sync_offer_price_enabled() ) {
            $paths[] = [ '/offers/' . rawurlencode( $offer_id ) . '/price', 'PUT', [ 'pricing' => $price_payload ] ];
        }

        if ( $paths === [] ) {
            $sync_state = [
                'stock_synced' => ! Mapping_Config::sync_offer_stock_enabled(),
                'price_synced' => ! Mapping_Config::sync_offer_price_enabled(),
            ];
            return $this->offer_update_skip_response( $offer_id );
        }

        $last_response = null;
        $sync_state    = [
            'stock_synced' => false,
            'price_synced' => false,
        ];
        $delay_ms = max( 0, (int) get_option( 'wbs_rate_limit_delay', 500 ) );
        $last_idx = count( $paths ) - 1;
        foreach ( $paths as $idx => [ $path, $method, $payload ] ) {
            $response = $this->api->request_with_headers( $path, $method, $payload );
            if ( is_wp_error( $response ) ) {
                return $response;
            }
            if ( $response['code'] < 200 || $response['code'] >= 300 ) {
                return $response;
            }
            if ( str_ends_with( (string) $path, '/stock' ) ) {
                $sync_state['stock_synced'] = true;
            }
            if ( str_ends_with( (string) $path, '/price' ) ) {
                $sync_state['price_synced'] = true;
            }
            $last_response = $response;
            if ( $delay_ms > 0 && $idx < $last_idx ) {
                usleep( $delay_ms * 1000 );
            }
        }

        return is_array( $last_response ) ? $last_response : new \WP_Error( 'wbs_offer_update_failed', __( 'Offer update did not return a response.', 'woo-bol-sync' ) );
    }

    private function extract_duplicate_offer_id( string $message ): string {
        if ( preg_match( "/offer\\s+'([a-z0-9\\-]{20,})'/i", $message, $matches ) === 1 ) {
            return sanitize_text_field( (string) $matches[1] );
        }
        return '';
    }

    private function catalog_hint_from_error( string $message ): string {
        $normalized = strtolower( $message );
        if ( str_contains( $normalized, 'product group is missing' ) ) {
            return __( 'Offer exists in bol.com but needs a product group/content classification before it can go on sale.', 'woo-bol-sync' );
        }
        if ( str_contains( $normalized, 'not for sale' ) || str_contains( $normalized, 'image' ) ) {
            return __( 'Offer may be missing catalog content or other bol.com completeness requirements.', 'woo-bol-sync' );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function build_api_failure_meta( array $extra = [] ): array {
        $meta = $this->api->get_last_request_meta();
        return array_merge(
            [
                'api_url'            => (string) ( $meta['url'] ?? '' ),
                'api_path'           => (string) ( $meta['path'] ?? '' ),
                'api_method'         => (string) ( $meta['method'] ?? '' ),
                'api_code'           => isset( $meta['code'] ) ? (string) $meta['code'] : '',
                'api_attempt'        => isset( $meta['attempt'] ) ? (string) $meta['attempt'] : '',
                'api_max_attempts'   => isset( $meta['max_attempts'] ) ? (string) $meta['max_attempts'] : '',
                'api_media_type'     => (string) ( $meta['media_type'] ?? '' ),
                'api_request_id'     => (string) ( $meta['request_id'] ?? '' ),
                'api_correlation_id' => (string) ( $meta['correlation_id'] ?? '' ),
                'api_traceparent'    => (string) ( $meta['traceparent'] ?? '' ),
                'api_initial_media_type' => (string) ( $meta['initial_media_type'] ?? '' ),
                'api_fallback_media_type' => (string) ( $meta['fallback_media_type'] ?? '' ),
                'api_fallback_trigger_code' => isset( $meta['fallback_trigger_code'] ) ? (string) $meta['fallback_trigger_code'] : '',
                'api_fallback_applied' => ! empty( $meta['fallback_applied'] ),
            ],
            $extra
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function with_api_failure_hint( string $message, array $meta ): string {
        $code = (string) ( $meta['api_code'] ?? '' );
        $path = (string) ( $meta['api_path'] ?? '' );
        if ( $code === '403' && str_starts_with( $path, '/offers/' ) ) {
            $hint = __( 'Likely access/ownership mismatch: this offer may belong to a different retailer account than the current API credentials.', 'woo-bol-sync' );
            if ( ! str_contains( $message, $hint ) ) {
                return trim( $message ) . ' ' . $hint;
            }
        }
        return $message;
    }

    /**
     * @param array<string, mixed> $api_meta
     * @return array<string, mixed>
     */
    private function maybe_schedule_stale_offer_recovery( \WC_Product $product, string $offer_id, string $ean, array $api_meta ): array {
        if ( ! Mapping_Config::auto_recover_stale_offers_enabled() ) {
            return [];
        }
        if ( $offer_id === '' ) {
            return [];
        }

        $code = (string) ( $api_meta['api_code'] ?? '' );
        $path = (string) ( $api_meta['api_path'] ?? '' );
        if ( $code !== '403' || ! str_starts_with( $path, '/offers/' ) ) {
            return [];
        }

        $row = Product_Mapping::get_row( $product->get_id() );
        $meta = $this->decode_mapping_meta( $row['meta'] ?? '' );
        $last_offer = (string) ( $meta['stale_offer_recovery_offer_id'] ?? '' );
        $last_at = (string) ( $meta['stale_offer_recovery_scheduled_at'] ?? '' );
        $last_ts = $last_at !== '' ? strtotime( $last_at . ' UTC' ) : false;
        if ( $last_offer === $offer_id && is_int( $last_ts ) && $last_ts > 0 && ( time() - $last_ts ) < DAY_IN_SECONDS ) {
            return [];
        }

        return [
            'scheduled' => true,
            'stale_offer_recovery' => 'scheduled',
            'stale_offer_recovery_offer_id' => $offer_id,
            'stale_offer_recovery_scheduled_at' => current_time( 'mysql', true ),
            'stale_offer_recovery_reason' => '403_offers_forbidden',
            'stale_offer_recovery_ean' => $ean,
        ];
    }

    /**
     * @param array<string, mixed> $recovery
     */
    private function apply_stale_offer_mapping_reset( \WC_Product $product, string $ean, array $recovery ): void {
        if ( empty( $recovery['scheduled'] ) ) {
            return;
        }

        $row = Product_Mapping::get_row( $product->get_id() );
        $meta = $this->decode_mapping_meta( $row['meta'] ?? '' );
        $meta['stale_offer_recovery'] = 'scheduled';
        $meta['stale_offer_recovery_offer_id'] = (string) ( $recovery['stale_offer_recovery_offer_id'] ?? '' );
        $meta['stale_offer_recovery_scheduled_at'] = (string) ( $recovery['stale_offer_recovery_scheduled_at'] ?? current_time( 'mysql', true ) );
        $meta['stale_offer_recovery_reason'] = (string) ( $recovery['stale_offer_recovery_reason'] ?? '' );
        $meta['stale_offer_recovery_ean'] = (string) ( $recovery['stale_offer_recovery_ean'] ?? $ean );

        Product_Mapping::save_row(
            $product->get_id(),
            '',
            $ean,
            '',
            $meta
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode_mapping_meta( $raw ): array {
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function sync_catalog_content( \WC_Product $product, string $ean ): array {
        $state = [
            'content_upload_id' => '',
            'catalog_hint'      => '',
            'has_image'         => false,
            'content_synced'    => false,
            'image_synced'      => false,
        ];

        if ( ! Mapping_Config::sync_product_content_enabled() ) {
            return $state;
        }

        $assets = $this->api->get_product_assets( $ean );
        if ( ! is_wp_error( $assets ) && $assets['code'] >= 200 && $assets['code'] < 300 && is_array( $assets['body'] ) ) {
            $state['has_image'] = $this->response_has_assets( $assets['body'] );
        }

        $catalog = $this->api->get_catalog_product( $ean );
        if ( ! is_wp_error( $catalog ) && $catalog['code'] >= 200 && $catalog['code'] < 300 && is_array( $catalog['body'] ) ) {
            $state['catalog_hint'] = $this->catalog_hint_from_catalog( $catalog['body'] );
        }

        if ( $state['has_image'] && $state['catalog_hint'] === '' && ! Mapping_Config::sync_content_images_enabled() ) {
            if ( ! Mapping_Config::sync_content_name_enabled() && ! Mapping_Config::sync_content_description_enabled() ) {
                $state['image_synced'] = true;
                return $state;
            }
        }

        $payload = $this->build_content_payload( $product, $ean );
        if ( $payload === [] ) {
            if ( ! $state['has_image'] ) {
                $state['catalog_hint'] = __( 'No product image available in WooCommerce to send to bol.com.', 'woo-bol-sync' );
            }
            return $state;
        }

        Logger::info(
            'Prepared bol.com content payload for product sync.',
            [
                'product_id'          => $product->get_id(),
                'product_name'        => $product->get_name(),
                'ean'                 => $ean,
                'content_attributes'  => $this->summarize_content_attributes( $payload['attributes'] ?? [] ),
                'content_assets'      => $this->summarize_content_assets( $payload['assets'] ?? [] ),
            ],
            'products'
        );

        $response = $this->api->create_product_content( $payload );
        if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 ) {
            $state['catalog_hint'] = is_wp_error( $response ) ? $response->get_error_message() : (string) $response['summary'];
            Logger::warning(
                'bol.com content payload failed during product sync.',
                [
                    'product_id'         => $product->get_id(),
                    'product_name'       => $product->get_name(),
                    'ean'                => $ean,
                    'message'            => $state['catalog_hint'],
                    'content_attributes' => $this->summarize_content_attributes( $payload['attributes'] ?? [] ),
                    'content_assets'     => $this->summarize_content_assets( $payload['assets'] ?? [] ),
                ],
                'products'
            );
            return $state;
        }

        $upload_id = $this->api->extract_upload_id( $response );
        $state['content_upload_id'] = $upload_id;
        $state['content_synced']    = true;
        $state['image_synced']      = isset( $payload['assets'] ) && is_array( $payload['assets'] ) && $payload['assets'] !== [];

        if ( $upload_id !== '' ) {
            $report = $this->api->get_content_upload_report( $upload_id );
            if ( ! is_wp_error( $report ) && (int) ( $report['code'] ?? 0 ) === 404 ) {
                usleep( 350 * 1000 );
                $report = $this->api->get_content_upload_report( $upload_id );
            }
            if ( ! is_wp_error( $report ) && $report['code'] >= 200 && $report['code'] < 300 && is_array( $report['body'] ) ) {
                $state['catalog_hint'] = $this->catalog_hint_from_upload_report( $report['body'] );
                Logger::info(
                    'bol.com content upload report received for product sync.',
                    [
                        'upload_id'      => $upload_id,
                        'product_id'     => $product->get_id(),
                        'product_name'   => $product->get_name(),
                        'ean'            => $ean,
                        'upload_report'  => $report['body'],
                        'catalog_hint'   => $state['catalog_hint'],
                    ],
                    'products'
                );
            } elseif ( ! is_wp_error( $report ) && (int) ( $report['code'] ?? 0 ) === 404 ) {
                $meta = $this->api->get_last_request_meta();
                Logger::info(
                    'Content upload report not available yet; keeping successful product sync.',
                    [
                        'upload_id' => $upload_id,
                        'product_id' => $product->get_id(),
                        'ean' => $ean,
                        'request_id' => (string) ( $meta['request_id'] ?? '' ),
                    ],
                    'products'
                );
            }
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_content_payload( \WC_Product $product, string $ean ): array {
        $image_url = $this->resolve_image_url( $product );

        $attributes = [
            [
                'id'     => 'EAN',
                'values' => [
                    [ 'value' => $ean ],
                ],
            ],
        ];

        $name = Mapping_Config::get_listing_title( $product );
        if ( $name !== '' && Mapping_Config::sync_content_name_enabled() ) {
            $attributes[] = [
                'id'     => 'Name',
                'values' => [
                    [ 'value' => $name ],
                ],
            ];
        }

        $description = Mapping_Config::get_listing_description( $product );
        if ( $description !== '' && Mapping_Config::sync_content_description_enabled() ) {
            $attributes[] = [
                'id'     => 'Description',
                'values' => [
                    [ 'value' => mb_substr( $description, 0, 2000 ) ],
                ],
            ];
        }

        $core_meta_attributes = $this->core_bol_meta_attributes( $product );
        if ( $core_meta_attributes !== [] ) {
            $attributes = array_merge( $attributes, $core_meta_attributes );
        }

        $category_attributes = Category_Map::get_template_attributes_for_product( $product );
        if ( $category_attributes !== [] ) {
            $attributes = $this->merge_attributes_prefer_product_values( $attributes, $category_attributes );
        }

        $attributes = Mapping_Config::ensure_product_group_fallback_on_attributes( $attributes, $product );
        $attributes = $this->filter_content_attributes_by_sync_flags( $attributes );

        // bol Retailer Product Content API: classification is carried in `attributes` (and category-mapping
        // templates). Do not add undocumented top-level category keys here.

        $payload = [
            'language'   => 'nl',
            'attributes' => $attributes,
        ];

        $missing_core = $this->missing_core_meta_keys( $product );
        if ( $missing_core !== [] ) {
            Logger::info(
                'Product is missing some bol.com core metadata fields.',
                [
                    'product_id'     => $product->get_id(),
                    'ean'            => $ean,
                    'missing_fields' => $missing_core,
                ],
                'products'
            );
        }

        // Build assets array with main image + all gallery images
        $assets = [];

        if ( ! Mapping_Config::sync_content_images_enabled() ) {
            return $payload;
        }

        // Add main image first with FRONT label
        if ( is_string( $image_url ) && $image_url !== '' ) {
            $assets[] = [
                'url'    => $image_url,
                'labels' => [ 'FRONT' ],
            ];
        }
        
        // Add all gallery images, inheriting the parent gallery for variations.
        $gallery_ids = $this->get_content_gallery_image_ids( $product );
        if ( is_array( $gallery_ids ) && $gallery_ids !== [] ) {
            $additional_labels = [ 'BACK', 'LEFT', 'RIGHT', 'TOP', 'BOTTOM' ];
            $label_index = 0;
            
            foreach ( $gallery_ids as $gallery_id ) {
                $gallery_id = (int) $gallery_id;
                if ( $gallery_id <= 0 ) {
                    continue;
                }
                
                $gallery_url = wp_get_attachment_url( $gallery_id );
                if ( ! is_string( $gallery_url ) || $gallery_url === '' ) {
                    continue;
                }
                
                // Skip if this gallery image is same as main image (avoid duplicates)
                if ( $gallery_url === $image_url ) {
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
        }
        
        // Only add assets if we have at least one image
        if ( $assets !== [] ) {
            $payload['assets'] = $assets;
        }

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     * @return array<int, array<string, mixed>>
     */
    private function filter_content_attributes_by_sync_flags( array $attributes ): array {
        $out = [];
        foreach ( $attributes as $attr ) {
            if ( ! is_array( $attr ) ) {
                continue;
            }
            $id = isset( $attr['id'] ) ? trim( (string) $attr['id'] ) : '';
            if ( strcasecmp( $id, 'Name' ) === 0 && ! Mapping_Config::sync_content_name_enabled() ) {
                continue;
            }
            if (
                ( strcasecmp( $id, 'Description' ) === 0 || strcasecmp( $id, 'Dutch Description' ) === 0 )
                && ! Mapping_Config::sync_content_description_enabled()
            ) {
                continue;
            }
            $out[] = $attr;
        }
        return $out;
    }

    /**
     * @return array<int, array{id:string, values:array<int, array{value:string}>}>
     */
    private function core_bol_meta_attributes( \WC_Product $product ): array {
        $map = [
            '_wbs_net_content'       => 'Net Content',
            '_wbs_ingredients'       => 'Ingredients',
            '_wbs_origin_country'    => 'Country of Origin',
            '_wbs_dutch_description' => 'Dutch Description',
        ];

        $attributes = [];
        $meta_product = Mapping_Config::get_content_meta_source_product( $product );
        $brand        = Mapping_Config::get_content_brand( $product );
        if ( $brand !== '' ) {
            $attributes[] = [
                'id'     => 'Brand',
                'values' => [
                    [ 'value' => $brand ],
                ],
            ];
        }
        foreach ( $map as $meta_key => $attribute_id ) {
            if ( $meta_key === '_wbs_dutch_description' && ! Mapping_Config::sync_content_description_enabled() ) {
                continue;
            }
            $value = trim( (string) $meta_product->get_meta( $meta_key, true ) );
            if ( $meta_key === '_wbs_dutch_description' ) {
                $value = trim( wp_strip_all_tags( $value ) );
            }
            if ( $meta_key === '_wbs_net_content' && $value === '' ) {
                $value = $this->normalize_net_content_from_meta( $product );
                if ( $value === '' && $meta_product->get_id() !== $product->get_id() ) {
                    $value = $this->normalize_net_content_from_meta( $meta_product );
                }
            }
            if ( $value === '' ) {
                continue;
            }
            $attributes[] = [
                'id'     => $attribute_id,
                'values' => [
                    [ 'value' => $value ],
                ],
            ];
        }

        return $attributes;
    }

    private function normalize_net_content_from_meta( \WC_Product $product ): string {
        $value  = trim( (string) $product->get_meta( '_wbs_net_content_value', true ) );
        $unit   = trim( (string) $product->get_meta( '_wbs_net_content_unit', true ) );
        $pieces = trim( (string) $product->get_meta( '_wbs_net_content_pieces', true ) );

        $base = '';
        if ( $value !== '' && $unit !== '' ) {
            $base = $value . ' ' . $unit;
        } elseif ( $value !== '' ) {
            $base = $value;
        }
        if ( $pieces !== '' ) {
            return $base !== '' ? $base . ' (' . $pieces . ' pieces)' : $pieces . ' pieces';
        }

        if ( $base === '' && $product->is_type( 'variation' ) ) {
            $weight = trim( (string) $product->get_weight() );
            if ( $weight !== '' ) {
                $weight_unit = trim( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );
                return $weight . ( $weight_unit !== '' ? ' ' . $weight_unit : '' );
            }
        }

        return $base;
    }

    /**
     * @param array<int, array{id:string, values:array<int, array{value:string}>}> $product_attributes
     * @param array<int, array{id:string, values:array<int, array{value:string}>}> $template_attributes
     * @return array<int, array{id:string, values:array<int, array{value:string}>}>
     */
    private function merge_attributes_prefer_product_values( array $product_attributes, array $template_attributes ): array {
        $existing = [];
        foreach ( $product_attributes as $attr ) {
            if ( isset( $attr['id'] ) && is_string( $attr['id'] ) ) {
                $existing[ strtolower( trim( $attr['id'] ) ) ] = true;
            }
        }

        foreach ( $template_attributes as $attr ) {
            $id = isset( $attr['id'] ) && is_string( $attr['id'] ) ? trim( $attr['id'] ) : '';
            if ( $id === '' ) {
                continue;
            }
            if ( isset( $existing[ strtolower( $id ) ] ) ) {
                continue;
            }
            $product_attributes[] = $attr;
        }

        return $product_attributes;
    }

    /**
     * @return string[]
     */
    private function missing_core_meta_keys( \WC_Product $product ): array {
        $meta_product = Mapping_Config::get_content_meta_source_product( $product );
        $required = [
            '_wbs_net_content',
            '_wbs_ingredients',
            '_wbs_origin_country',
            '_wbs_dutch_description',
        ];
        $missing = [];
        foreach ( $required as $key ) {
            $value = trim( (string) $meta_product->get_meta( $key, true ) );
            if ( $key === '_wbs_net_content' && $value === '' ) {
                $value = $this->normalize_net_content_from_meta( $product );
                if ( $value === '' && $meta_product->get_id() !== $product->get_id() ) {
                    $value = $this->normalize_net_content_from_meta( $meta_product );
                }
            }
            if ( $value === '' ) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    private function resolve_stock_amount( \WC_Product $product ): int {
        $qty = $product->get_stock_quantity();
        if ( is_numeric( $qty ) ) {
            return max( 0, (int) $qty );
        }
        return $product->is_in_stock() ? 1 : 0;
    }

    private function resolve_image_url( \WC_Product $product ): string {
        $image_id = (int) $product->get_image_id();
        if ( $image_id > 0 ) {
            $url = wp_get_attachment_url( $image_id );
            if ( is_string( $url ) && $url !== '' ) {
                return $url;
            }
        }
        $gallery = $this->get_content_gallery_image_ids( $product );
        if ( is_array( $gallery ) ) {
            foreach ( $gallery as $gallery_id ) {
                $url = wp_get_attachment_url( (int) $gallery_id );
                if ( is_string( $url ) && $url !== '' ) {
                    return $url;
                }
            }
        }
        return '';
    }

    /**
     * Gallery images used for bol content payloads.
     * Variations inherit the parent gallery because WC variations do not keep their own gallery set.
     *
     * @return int[]
     */
    private function get_content_gallery_image_ids( \WC_Product $product ): array {
        $gallery = $product->get_gallery_image_ids();
        if ( is_array( $gallery ) && $gallery !== [] ) {
            return array_values( array_map( 'intval', $gallery ) );
        }

        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent instanceof \WC_Product ) {
                $parent_gallery = $parent->get_gallery_image_ids();
                if ( is_array( $parent_gallery ) ) {
                    return array_values( array_map( 'intval', $parent_gallery ) );
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function response_has_assets( array $body ): bool {
        foreach ( [ 'assets', 'images' ] as $key ) {
            if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) && $body[ $key ] !== [] ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function catalog_hint_from_catalog( array $body ): string {
        $serialized = strtolower( wp_json_encode( $body ) ?: '' );
        if ( str_contains( $serialized, 'product group is missing' ) ) {
            return __( 'bol.com still reports a missing product group for this item.', 'woo-bol-sync' );
        }
        if ( str_contains( $serialized, 'missing image' ) ) {
            return __( 'bol.com still reports missing image/content for this item.', 'woo-bol-sync' );
        }
        return '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function catalog_hint_from_upload_report( array $body ): string {
        $serialized = strtolower( wp_json_encode( $body ) ?: '' );
        if ( str_contains( $serialized, 'failed' ) || str_contains( $serialized, 'error' ) ) {
            return __( 'bol.com content upload reported issues. Check the upload report details in logs.', 'woo-bol-sync' );
        }
        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     * @return array<string, string>
     */
    private function summarize_content_attributes( array $attributes ): array {
        $summary = [];
        foreach ( $attributes as $attribute ) {
            if ( ! is_array( $attribute ) ) {
                continue;
            }
            $id = isset( $attribute['id'] ) ? trim( (string) $attribute['id'] ) : '';
            if ( $id === '' ) {
                continue;
            }
            $values = [];
            $raw_values = $attribute['values'] ?? [];
            if ( is_array( $raw_values ) ) {
                foreach ( $raw_values as $raw_value ) {
                    if ( ! is_array( $raw_value ) || ! array_key_exists( 'value', $raw_value ) ) {
                        continue;
                    }
                    $values[] = mb_substr( trim( wp_strip_all_tags( (string) $raw_value['value'] ) ), 0, 180 );
                }
            }
            $summary[ $id ] = implode( ' | ', array_filter( $values, static fn( string $value ): bool => $value !== '' ) );
        }
        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, array{url:string,labels:string}>
     */
    private function summarize_content_assets( array $assets ): array {
        $summary = [];
        foreach ( $assets as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }
            $labels = [];
            if ( isset( $asset['labels'] ) && is_array( $asset['labels'] ) ) {
                $labels = array_values( array_filter( array_map( 'strval', $asset['labels'] ) ) );
            }
            $summary[] = [
                'url'    => (string) ( $asset['url'] ?? '' ),
                'labels' => implode( ',', $labels ),
            ];
        }
        return $summary;
    }
}
