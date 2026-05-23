<?php
/**
 * bol.com order import and status sync.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Services;

use WooBolSync\Includes\Logger;
use WooBolSync\Includes\Mapping_Config;
use WooBolSync\Models\Order_Mapping;
use WooBolSync\Models\Product_Mapping;

defined( 'ABSPATH' ) || exit;

class Order_Sync_Service {

    public const META_BOL_ORDER_ID         = '_wbs_bol_order_id';
    public const META_BOL_ORDER_ITEMS      = '_wbs_bol_order_items';
    public const META_BOL_ORDER_RAW        = '_wbs_bol_order_raw';
    public const META_BOL_PAYMENT_RAW      = '_wbs_bol_payment_raw';
    public const META_BOL_LAST_PUSH        = '_wbs_bol_last_push';
    public const META_BOL_LAST_RETURN_SYNC = '_wbs_bol_last_return_sync';

    private Bol_API_Service $api;

    public function __construct( Bol_API_Service $api ) {
        $this->api = $api;
    }

    /**
     * @return array{created:int, skipped:int, failed:int, returns:int}
     */
    public function sync_orders(): array {
        $stats    = [
            'created' => 0,
            'skipped' => 0,
            'failed'  => 0,
            'returns' => 0,
        ];
        $response = $this->api->request_with_headers( $this->orders_list_path(), 'GET' );

        if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 ) {
            Logger::error(
                'Order sync failed.',
                [ 'message' => is_wp_error( $response ) ? $response->get_error_message() : (string) $response['summary'] ],
                'orders'
            );
            ++$stats['failed'];
            return $stats;
        }

        $body   = is_array( $response['body'] ) ? $response['body'] : [];
        $orders = $body['orders'] ?? $body['results'] ?? [];
        if ( ! is_array( $orders ) ) {
            $orders = [];
        }

        foreach ( $orders as $order ) {
            if ( ! is_array( $order ) ) {
                continue;
            }
            $result = $this->import_single_order( $this->get_order_import_payload( $order ) );
            if ( isset( $stats[ $result ] ) ) {
                ++$stats[ $result ];
            }
        }

        $stats['returns'] = $this->sync_returns();
        update_option( Mapping_Config::OPTION_LAST_ORDER_SYNC, current_time( 'mysql', true ) );
        Mapping_Config::set_order_sync_last_run();

        return $stats;
    }

    public function sync_wc_order_status( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $map = Order_Mapping::get_by_wc_order_id( $order_id );
        if ( ! is_array( $map ) || empty( $map['bol_order_id'] ) ) {
            return;
        }

        $status = (string) $order->get_status();
        if ( in_array( $status, [ 'completed', 'shipped' ], true ) ) {
            $this->push_shipment_for_order( $order, (string) $map['bol_order_id'] );
            return;
        }

        if ( in_array( $status, [ 'cancelled', 'refunded' ], true ) ) {
            $this->push_cancellation_for_order( $order, (string) $map['bol_order_id'] );
            return;
        }

        Logger::debug(
            'WooCommerce order status changed but does not trigger a bol.com update.',
            [
                'wc_order_id'  => $order_id,
                'bol_order_id' => (string) $map['bol_order_id'],
                'status'       => $status,
                'expected'     => [ 'completed', 'shipped', 'cancelled', 'refunded' ],
            ],
            'orders'
        );
    }

    /**
     * @return int
     */
    public function sync_returns(): int {
        $processed = 0;

        foreach ( [ 'FBR', 'FBB' ] as $fulfilment_method ) {
            $response = $this->api->list_returns( true, $fulfilment_method );
            if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 ) {
                continue;
            }

            $body    = is_array( $response['body'] ) ? $response['body'] : [];
            $returns = $body['returns'] ?? $body['results'] ?? [];
            if ( ! is_array( $returns ) ) {
                continue;
            }

            foreach ( $returns as $return ) {
                if ( ! is_array( $return ) ) {
                    continue;
                }
                $processed += $this->import_single_return( $return );
            }
        }

        return $processed;
    }

    private function orders_list_path(): string {
        return '/orders?' . http_build_query(
            [
                'fulfilment-method' => 'ALL',
                'status'            => 'ALL',
                'page'              => 1,
                'size'              => 50,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function get_order_import_payload( array $order ): array {
        $bol_order_id = (string) ( $order['orderId'] ?? $order['id'] ?? '' );
        if ( $bol_order_id === '' || Order_Mapping::get_by_bol_order_id( $bol_order_id ) ) {
            return $order;
        }

        $response = $this->api->get_order( $bol_order_id );
        if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 ) {
            Logger::warning(
                'Could not fetch bol.com order detail; importing list payload.',
                [
                    'bol_order_id' => $bol_order_id,
                    'message'      => is_wp_error( $response ) ? $response->get_error_message() : (string) $response['summary'],
                ],
                'orders'
            );
            return $order;
        }

        $body = is_array( $response['body'] ) ? $response['body'] : [];
        if ( isset( $body['order'] ) && is_array( $body['order'] ) ) {
            $body = $body['order'];
        }
        if ( $body === [] ) {
            Logger::warning(
                'bol.com order detail response was empty; importing list payload.',
                [ 'bol_order_id' => $bol_order_id ],
                'orders'
            );
            return $order;
        }

        $merged = array_merge( $order, $body );
        if ( isset( $order['orderItems'], $body['orderItems'] ) && is_array( $order['orderItems'] ) && is_array( $body['orderItems'] ) ) {
            $merged['orderItems'] = $this->merge_order_items( $order['orderItems'], $body['orderItems'] );
        }

        return $merged;
    }

    private function import_single_order( array $order ): string {
        $bol_order_id = (string) ( $order['orderId'] ?? $order['id'] ?? '' );
        if ( $bol_order_id === '' ) {
            return 'failed';
        }

        if ( Order_Mapping::get_by_bol_order_id( $bol_order_id ) ) {
            return 'skipped';
        }

        $wc_order = wc_create_order();
        if ( is_wp_error( $wc_order ) ) {
            Logger::error( 'Could not create WooCommerce order.', [ 'bol_order_id' => $bol_order_id ], 'orders' );
            return 'failed';
        }

        $items               = $order['orderItems'] ?? $order['items'] ?? [];
        $unmapped_order_items = [];
        if ( is_array( $items ) ) {
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $product = $this->resolve_wc_product_for_order_item( $item );
                if ( ! $product instanceof \WC_Product ) {
                    $this->add_unmapped_bol_order_item( $wc_order, $item );
                    $unmapped_order_items[] = [
                        'orderItemId' => (string) ( $item['orderItemId'] ?? '' ),
                        'ean'         => $this->extract_order_item_ean( $item ),
                        'title'       => $this->extract_order_item_title( $item ),
                        'quantity'    => (int) ( $item['quantity'] ?? 1 ),
                        'unit_price'  => $this->extract_order_item_unit_price( $item ),
                    ];
                    continue;
                }

                $qty = max( 1, (int) ( $item['quantity'] ?? 1 ) );
                $wc_order->add_product( $product, $qty );
            }
        }

        $billing_address  = $this->extract_billing_address( $order );
        $shipping_address = $this->extract_shipping_address( $order, $billing_address );

        if ( $billing_address !== [] ) {
            $wc_order->set_address( $billing_address, 'billing' );
        }
        if ( $shipping_address !== [] ) {
            $wc_order->set_address( $shipping_address, 'shipping' );
        }
        if ( $billing_address === [] || $shipping_address === [] ) {
            Logger::warning(
                'bol.com order imported without complete address data.',
                [
                    'bol_order_id'    => $bol_order_id,
                    'missing_billing' => $billing_address === [],
                    'missing_shipping' => $shipping_address === [],
                ],
                'orders'
            );
        }

        $this->apply_payment_context( $wc_order, $order );
        $wc_order->update_meta_data( self::META_BOL_ORDER_ID, $bol_order_id );
        $wc_order->update_meta_data( self::META_BOL_ORDER_ITEMS, wp_json_encode( $items ) );
        $wc_order->update_meta_data( self::META_BOL_ORDER_RAW, wp_json_encode( $order ) );
        $wc_order->calculate_totals();
        $wc_order->set_status( 'processing', __( 'bol.com order imported as externally paid.', 'woo-bol-sync' ) );
        $wc_order->save();

        if ( $unmapped_order_items !== [] ) {
            Logger::warning(
                'bol.com order imported with unmapped WooCommerce products.',
                [
                    'bol_order_id'         => $bol_order_id,
                    'wc_order_id'          => $wc_order->get_id(),
                    'unmapped_order_items' => $unmapped_order_items,
                    'catalog_hint'         => __( 'Map the WooCommerce product to the correct EAN so Sendcloud receives all order lines.', 'woo-bol-sync' ),
                ],
                'orders'
            );
        }

        Order_Mapping::save_mapping( $wc_order->get_id(), $bol_order_id, (string) ( $order['status'] ?? 'imported' ) );

        Logger::info(
            'bol.com order imported.',
            [
                'bol_order_id' => $bol_order_id,
                'wc_order_id'  => $wc_order->get_id(),
                'shipping_name' => trim( (string) ( $shipping_address['first_name'] ?? '' ) . ' ' . (string) ( $shipping_address['last_name'] ?? '' ) ),
                'shipping_postcode' => (string) ( $shipping_address['postcode'] ?? '' ),
                'shipping_country' => (string) ( $shipping_address['country'] ?? '' ),
                'status'       => $wc_order->get_status(),
                'unmapped_count' => count( $unmapped_order_items ),
            ],
            'orders'
        );

        return 'created';
    }

    private function resolve_wc_product_for_order_item( array $item ): ?\WC_Product {
        $ean = $this->extract_order_item_ean( $item );
        if ( $ean !== '' ) {
            $mapping = Product_Mapping::get_by_ean( $ean );
            if ( is_array( $mapping ) ) {
                $product = wc_get_product( (int) $mapping['wc_product_id'] );
                if ( $product instanceof \WC_Product ) {
                    return $product;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $list_items
     * @param array<int, mixed> $detail_items
     * @return array<int, mixed>
     */
    private function merge_order_items( array $list_items, array $detail_items ): array {
        $list_by_id = [];
        foreach ( $list_items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $order_item_id = (string) ( $item['orderItemId'] ?? '' );
            if ( $order_item_id !== '' ) {
                $list_by_id[ $order_item_id ] = $item;
            }
        }

        $merged = [];
        foreach ( $detail_items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $order_item_id = (string) ( $item['orderItemId'] ?? '' );
            $base          = $order_item_id !== '' && isset( $list_by_id[ $order_item_id ] ) ? $list_by_id[ $order_item_id ] : [];
            $merged[]      = array_merge( $base, $item );
        }

        return $merged !== [] ? $merged : $list_items;
    }

    private function add_unmapped_bol_order_item( \WC_Order $order, array $item ): void {
        $quantity   = max( 1, (int) ( $item['quantity'] ?? 1 ) );
        $unit_price = $this->extract_order_item_unit_price( $item );
        $line_total = $unit_price * $quantity;

        $order_item = new \WC_Order_Item_Product();
        $order_item->set_name( $this->extract_order_item_title( $item ) ?: __( 'bol.com order item', 'woo-bol-sync' ) );
        $order_item->set_quantity( $quantity );
        $order_item->set_subtotal( $line_total );
        $order_item->set_total( $line_total );

        $ean = $this->extract_order_item_ean( $item );
        if ( $ean !== '' ) {
            $order_item->add_meta_data( 'EAN', $ean, true );
        }

        $order_item_id = (string) ( $item['orderItemId'] ?? '' );
        if ( $order_item_id !== '' ) {
            $order_item->add_meta_data( 'bol order item id', $order_item_id, true );
        }

        $order->add_item( $order_item );
    }

    private function extract_order_item_ean( array $item ): string {
        $ean = (string) ( $item['ean'] ?? $item['product']['ean'] ?? $item['offer']['ean'] ?? '' );
        return preg_replace( '/\D/', '', $ean ) ?: '';
    }

    private function extract_order_item_title( array $item ): string {
        return trim( (string) ( $item['title'] ?? $item['productTitle'] ?? $item['product']['title'] ?? $item['product']['name'] ?? $item['offer']['title'] ?? '' ) );
    }

    private function extract_order_item_unit_price( array $item ): float {
        foreach ( [ 'unitPrice', 'price', 'sellingPrice', 'offerPrice' ] as $key ) {
            if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
                return max( 0.0, (float) str_replace( ',', '.', (string) $item[ $key ] ) );
            }
        }

        if ( isset( $item['totalPrice'] ) && is_scalar( $item['totalPrice'] ) ) {
            $quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
            return max( 0.0, (float) str_replace( ',', '.', (string) $item['totalPrice'] ) / $quantity );
        }

        if ( isset( $item['pricing']['bundlePrices'][0]['unitPrice'] ) && is_scalar( $item['pricing']['bundlePrices'][0]['unitPrice'] ) ) {
            return max( 0.0, (float) str_replace( ',', '.', (string) $item['pricing']['bundlePrices'][0]['unitPrice'] ) );
        }

        return 0.0;
    }

    /**
     * Keep imports resilient even when bol payment fields are absent/unknown.
     *
     * @param array<string, mixed> $order_data
     */
    private function apply_payment_context( \WC_Order $order, array $order_data ): void {
        $payment_data = $this->extract_payment_payload( $order_data );
        if ( $payment_data !== [] ) {
            $order->update_meta_data( self::META_BOL_PAYMENT_RAW, wp_json_encode( $payment_data ) );
        }

        $method_code  = $this->extract_payment_method_code( $payment_data, $order_data );
        $method_label = $this->extract_payment_method_label( $payment_data, $order_data );
        $reference    = $this->extract_payment_reference( $payment_data, $order_data );

        $method_slug = $method_code !== '' ? 'bol_' . sanitize_key( $method_code ) : 'bol_external';
        $title       = $method_label !== '' ? sprintf( __( 'bol.com (%s)', 'woo-bol-sync' ), $method_label ) : __( 'bol.com (external payment)', 'woo-bol-sync' );

        $order->set_payment_method( $method_slug );
        $order->set_payment_method_title( $title );

        if ( $reference !== '' ) {
            $order->set_transaction_id( $reference );
            $order->update_meta_data( '_wbs_bol_payment_reference', $reference );
        }
        if ( $method_code !== '' ) {
            $order->update_meta_data( '_wbs_bol_payment_method_detected', $method_code );
        }
    }

    /**
     * @param array<string, mixed> $order_data
     * @return array<string, mixed>
     */
    private function extract_payment_payload( array $order_data ): array {
        $candidates = [
            $order_data['payment'] ?? null,
            $order_data['paymentDetails'] ?? null,
            $order_data['paymentInfo'] ?? null,
            $order_data['paymentMethodDetails'] ?? null,
        ];

        foreach ( $candidates as $candidate ) {
            if ( is_array( $candidate ) && $candidate !== [] ) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payment_data
     * @param array<string, mixed> $order_data
     */
    private function extract_payment_method_code( array $payment_data, array $order_data ): string {
        $value = (string) (
            $payment_data['method'] ??
            $payment_data['methodCode'] ??
            $payment_data['paymentMethod'] ??
            $payment_data['type'] ??
            $order_data['paymentMethod'] ??
            ''
        );

        return sanitize_key( $value );
    }

    /**
     * @param array<string, mixed> $payment_data
     * @param array<string, mixed> $order_data
     */
    private function extract_payment_method_label( array $payment_data, array $order_data ): string {
        $value = (string) (
            $payment_data['methodName'] ??
            $payment_data['displayName'] ??
            $payment_data['name'] ??
            $order_data['paymentMethodName'] ??
            ''
        );

        return trim( $value );
    }

    /**
     * @param array<string, mixed> $payment_data
     * @param array<string, mixed> $order_data
     */
    private function extract_payment_reference( array $payment_data, array $order_data ): string {
        $value = (string) (
            $payment_data['transactionId'] ??
            $payment_data['paymentId'] ??
            $payment_data['reference'] ??
            $order_data['paymentReference'] ??
            $order_data['orderId'] ??
            ''
        );

        return trim( $value );
    }

    private function push_shipment_for_order( \WC_Order $order, string $bol_order_id ): void {
        $order_items = $this->extract_shippable_order_items( $order );
        if ( $order_items === [] ) {
            Order_Mapping::update_status_by_wc_order_id( $order->get_id(), 'failed' );
            Logger::error( 'Shipment push failed: no bol order items stored.', [ 'wc_order_id' => $order->get_id(), 'bol_order_id' => $bol_order_id ], 'orders' );
            return;
        }

        $tracking = $this->extract_tracking_details( $order );
        $payload  = [
            'orderItems' => $order_items,
            'shipmentReference' => 'wc-' . $order->get_id(),
        ];

        if ( $tracking !== [] ) {
            $payload['transport'] = $tracking;
        }

        $response = $this->api->request_with_headers( '/shipments', 'POST', $payload );
        if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 ) {
            Order_Mapping::update_status_by_wc_order_id( $order->get_id(), 'failed' );
            Logger::error(
                'Shipment push failed.',
                [
                    'wc_order_id'  => $order->get_id(),
                    'bol_order_id' => $bol_order_id,
                    'message'      => is_wp_error( $response ) ? $response->get_error_message() : (string) $response['summary'],
                    'shipment_payload' => $payload,
                ],
                'orders'
            );
            return;
        }

        $resolution = $this->api->resolve_offer_response( $response );
        if ( in_array( $resolution['status'], [ 'FAILURE', 'TIMEOUT' ], true ) ) {
            Order_Mapping::update_status_by_wc_order_id( $order->get_id(), 'failed' );
            Logger::error( 'Shipment push failed.', [ 'wc_order_id' => $order->get_id(), 'bol_order_id' => $bol_order_id, 'message' => $resolution['error'] ], 'orders' );
            return;
        }

        $order->update_meta_data( self::META_BOL_LAST_PUSH, current_time( 'mysql', true ) );
        $order->save();
        Order_Mapping::update_status_by_wc_order_id( $order->get_id(), 'shipped' );
    }

    private function push_cancellation_for_order( \WC_Order $order, string $bol_order_id ): void {
        $order_items = [];
        foreach ( $this->extract_shippable_order_items( $order ) as $item ) {
            if ( ! empty( $item['orderItemId'] ) ) {
                $order_items[] = [
                    'orderItemId' => $item['orderItemId'],
                    'reasonCode'  => 'OUT_OF_STOCK',
                ];
            }
        }
        if ( $order_items === [] ) {
            Order_Mapping::update_status_by_wc_order_id( $order->get_id(), 'failed' );
            Logger::error( 'Cancellation push failed: no bol order items stored.', [ 'wc_order_id' => $order->get_id(), 'bol_order_id' => $bol_order_id ], 'orders' );
            return;
        }

        $response = $this->api->request_with_headers(
            '/orders/cancellation',
            'POST',
            [
                'orderItems' => $order_items,
            ]
        );

        if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 ) {
            Order_Mapping::update_status_by_wc_order_id( $order->get_id(), 'failed' );
            Logger::error(
                'Cancellation push failed.',
                [
                    'wc_order_id'    => $order->get_id(),
                    'bol_order_id'   => $bol_order_id,
                    'message'        => is_wp_error( $response ) ? $response->get_error_message() : (string) $response['summary'],
                    'cancellation_payload' => [ 'orderItems' => $order_items ],
                ],
                'orders'
            );
            return;
        }

        $resolution = $this->api->resolve_offer_response( $response );
        if ( in_array( $resolution['status'], [ 'FAILURE', 'TIMEOUT' ], true ) ) {
            Order_Mapping::update_status_by_wc_order_id( $order->get_id(), 'failed' );
            Logger::error( 'Cancellation push failed.', [ 'wc_order_id' => $order->get_id(), 'bol_order_id' => $bol_order_id, 'message' => $resolution['error'] ], 'orders' );
            return;
        }

        $order->update_meta_data( self::META_BOL_LAST_PUSH, current_time( 'mysql', true ) );
        $order->save();
        Order_Mapping::update_status_by_wc_order_id( $order->get_id(), 'cancelled' );
    }

    /**
     * @return array<string, string>
     */
    private function extract_tracking_details( \WC_Order $order ): array {
        $carrier = (string) $order->get_meta( '_tracking_provider', true );
        $code    = (string) $order->get_meta( '_tracking_number', true );

        $out = [];
        if ( $carrier !== '' ) {
            $out['transporterCode'] = $carrier;
        }
        if ( $code !== '' ) {
            $out['trackAndTrace'] = $code;
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extract_bol_order_items( \WC_Order $order ): array {
        $raw = $order->get_meta( self::META_BOL_ORDER_ITEMS, true );
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                return array_values( array_filter( $decoded, 'is_array' ) );
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extract_shippable_order_items( \WC_Order $order ): array {
        $items = [];
        foreach ( $this->extract_bol_order_items( $order ) as $item ) {
            $order_item_id = (string) ( $item['orderItemId'] ?? '' );
            if ( $order_item_id === '' ) {
                continue;
            }
            $items[] = [
                'orderItemId' => $order_item_id,
                'quantity'    => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
            ];
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $return
     */
    private function import_single_return( array $return ): int {
        $order_item_id = (string) ( $return['orderItemId'] ?? '' );
        if ( $order_item_id === '' ) {
            return 0;
        }

        $order = $this->find_wc_order_by_order_item_id( $order_item_id );
        if ( ! $order instanceof \WC_Order ) {
            return 0;
        }

        $return_id     = (string) ( $return['returnId'] ?? $return['rmaId'] ?? '' );
        $handling      = (string) ( $return['handlingResult'] ?? $return['status'] ?? 'RETURNED' );
        $quantity      = (int) ( $return['quantityReturned'] ?? 1 );
        $return_marker = 'bol-return:' . $return_id . ':' . $order_item_id;

        $existing = (string) $order->get_meta( self::META_BOL_LAST_RETURN_SYNC, true );
        if ( str_contains( $existing, $return_marker ) ) {
            return 0;
        }

        $order->add_order_note(
            sprintf(
                /* translators: 1: return id, 2: order item id, 3: handling result, 4: quantity */
                __( 'bol.com return imported. Return %1$s, item %2$s, result %3$s, quantity %4$d.', 'woo-bol-sync' ),
                $return_id !== '' ? $return_id : __( 'unknown', 'woo-bol-sync' ),
                $order_item_id,
                $handling,
                max( 1, $quantity )
            )
        );
        $order->update_meta_data( self::META_BOL_LAST_RETURN_SYNC, $return_marker . ' @ ' . current_time( 'mysql', true ) );
        $order->save();

        Logger::info(
            'bol.com return imported.',
            [
                'wc_order_id'    => $order->get_id(),
                'return_id'      => $return_id,
                'order_item_id'  => $order_item_id,
                'handlingResult' => $handling,
                'quantity'       => $quantity,
            ],
            'orders'
        );

        return 1;
    }

    private function find_wc_order_by_order_item_id( string $order_item_id ): ?\WC_Order {
        $orders = wc_get_orders(
            [
                'limit'      => 50,
                'orderby'    => 'date',
                'order'      => 'DESC',
                'meta_query' => [
                    [
                        'key'     => self::META_BOL_ORDER_ITEMS,
                        'value'   => '"' . $order_item_id . '"',
                        'compare' => 'LIKE',
                    ],
                ],
            ]
        );

        foreach ( $orders as $order ) {
            if ( $order instanceof \WC_Order ) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    private function extract_billing_address( array $order ): array {
        $customer = $this->best_address_candidate(
            [
                $order['billingDetails'] ?? null,
                $order['customerDetails'] ?? null,
                $order['customer'] ?? null,
            ]
        );

        return $this->normalize_address_payload( $customer, [] );
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, string> $fallback
     * @return array<string, string>
     */
    private function extract_shipping_address( array $order, array $fallback ): array {
        $shipping = $this->best_address_candidate(
            [
                $order['shipmentDetails'] ?? null,
                $order['deliveryDetails'] ?? null,
                $order['shippingDetails'] ?? null,
                $order['shippingAddress'] ?? null,
                $order['deliveryAddress'] ?? null,
                $order['address'] ?? null,
            ]
        );

        return $this->normalize_address_payload( $shipping, $fallback );
    }

    /**
     * @param array<string, mixed>|null $source
     * @param array<string, string> $fallback
     * @return array<string, string>
     */
    private function normalize_address_payload( ?array $source, array $fallback ): array {
        $address = $source ?? [];

        foreach ( [ 'address', 'shippingAddress', 'billingAddress', 'shipmentDetails', 'deliveryAddress' ] as $nested_key ) {
            if ( isset( $address[ $nested_key ] ) && is_array( $address[ $nested_key ] ) ) {
                $address = array_merge( $address, $address[ $nested_key ] );
            }
        }

        $first_name = (string) ( $address['firstName'] ?? $address['firstname'] ?? $fallback['first_name'] ?? '' );
        $last_name  = (string) ( $address['surname'] ?? $address['lastName'] ?? $address['lastname'] ?? $fallback['last_name'] ?? '' );
        $company    = (string) ( $address['company'] ?? $address['companyName'] ?? $fallback['company'] ?? '' );
        $street     = (string) ( $address['streetName'] ?? $address['street'] ?? $fallback['address_1'] ?? '' );
        $house_no   = trim( (string) ( $address['houseNumber'] ?? '' ) . ' ' . (string) ( $address['houseNumberExtension'] ?? '' ) );
        $address_1  = trim( $street . ' ' . $house_no );
        $address_2  = (string) ( $address['additionalAddressInfo'] ?? $address['extraAddressInformation'] ?? $address['pickupPointName'] ?? $fallback['address_2'] ?? '' );
        if ( $address_2 === '' && isset( $address['pickupPoint'] ) && is_scalar( $address['pickupPoint'] ) && ! is_bool( $address['pickupPoint'] ) ) {
            $address_2 = (string) $address['pickupPoint'];
        }

        $normalized = [
            'first_name' => trim( $first_name ),
            'last_name'  => trim( $last_name ),
            'company'    => trim( $company ),
            'address_1'  => trim( $address_1 !== '' ? $address_1 : (string) ( $fallback['address_1'] ?? '' ) ),
            'address_2'  => trim( $address_2 ),
            'city'       => trim( (string) ( $address['city'] ?? $address['town'] ?? $fallback['city'] ?? '' ) ),
            'postcode'   => trim( (string) ( $address['zipCode'] ?? $address['postalCode'] ?? $fallback['postcode'] ?? '' ) ),
            'country'    => strtoupper( trim( (string) ( $address['countryCode'] ?? $address['country'] ?? $fallback['country'] ?? '' ) ) ),
            'email'      => trim( (string) ( $address['email'] ?? $address['emailAddress'] ?? $fallback['email'] ?? '' ) ),
            'phone'      => trim( (string) ( $address['phoneNumber'] ?? $address['phone'] ?? $fallback['phone'] ?? '' ) ),
        ];

        if ( ! $this->address_has_value( $normalized ) ) {
            return [];
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<string, mixed>|null
     */
    private function best_address_candidate( array $candidates ): ?array {
        $best       = null;
        $best_score = 0;

        foreach ( $candidates as $candidate ) {
            if ( ! is_array( $candidate ) || $candidate === [] ) {
                continue;
            }

            $score = $this->address_candidate_score( $candidate );
            if ( $score > $best_score ) {
                $best       = $candidate;
                $best_score = $score;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function address_candidate_score( array $candidate ): int {
        $normalized = $this->normalize_address_payload( $candidate, [] );
        if ( $normalized === [] ) {
            return 0;
        }

        $score = 1;
        foreach ( [ 'address_1', 'postcode', 'city', 'country' ] as $key ) {
            if ( trim( (string) ( $normalized[ $key ] ?? '' ) ) !== '' ) {
                $score += 2;
            }
        }
        foreach ( [ 'first_name', 'last_name', 'email', 'phone' ] as $key ) {
            if ( trim( (string) ( $normalized[ $key ] ?? '' ) ) !== '' ) {
                ++$score;
            }
        }

        return $score;
    }

    /**
     * @param array<string, string> $address
     */
    private function address_has_value( array $address ): bool {
        foreach ( $address as $value ) {
            if ( trim( (string) $value ) !== '' ) {
                return true;
            }
        }
        return false;
    }
}
