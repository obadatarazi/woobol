<?php
/**
 * bol.com subscription automation.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Services;

use WooBolSync\Includes\Mapping_Config;

defined( 'ABSPATH' ) || exit;

class Subscription_Sync_Service {
    private const WEBHOOK_SUBSCRIPTION_TYPE = 'WEBHOOK';
    private const RESOURCE_PROCESS_STATUS   = 'PROCESS_STATUS';

    private Bol_API_Service $api;

    public function __construct( Bol_API_Service $api ) {
        $this->api = $api;
    }

    /**
     * @return array<string, mixed>
     */
    public function ensure_process_status_subscription(): array {
        if ( ! Mapping_Config::webhook_enabled() ) {
            return [
                'ok'      => false,
                'message' => __( 'Webhook automation is disabled in settings.', 'woo-bol-sync' ),
            ];
        }

        $webhook_url = Mapping_Config::get_webhook_url();
        $payload     = $this->build_webhook_payload( $webhook_url );

        $subscription_id = Mapping_Config::get_subscription_id();
        if ( $subscription_id === '' ) {
            $existing = $this->find_existing_subscription( $webhook_url );
            if ( $existing !== [] ) {
                $subscription_id = (string) ( $existing['id'] ?? $existing['subscriptionId'] ?? '' );
            }
        }

        $response = $subscription_id !== ''
            ? $this->api->update_subscription( $subscription_id, $payload )
            : $this->api->create_subscription( $payload );

        if ( is_wp_error( $response ) ) {
            return [
                'ok'      => false,
                'message' => $response->get_error_message(),
            ];
        }

        if ( $response['code'] < 200 || $response['code'] >= 300 ) {
            if ( $subscription_id !== '' && in_array( (int) $response['code'], [ 400, 404 ], true ) ) {
                // Recover from stale saved IDs by creating a fresh subscription.
                $create_response = $this->api->create_subscription( $payload );
                if ( ! is_wp_error( $create_response ) && $create_response['code'] >= 200 && $create_response['code'] < 300 ) {
                    $create_body  = is_array( $create_response['body'] ) ? $create_response['body'] : [];
                    $create_body  = $this->await_subscription_process( $create_body );
                    $resolved_new = $this->resolve_subscription_id_from_response( $create_body, '', $webhook_url );
                    if ( $resolved_new !== '' ) {
                        update_option( Mapping_Config::OPTION_WEBHOOK_SUBSCRIPTION_ID, $resolved_new );
                    }
                    return [
                        'ok'              => true,
                        'message'         => __( 'Webhook subscription was recreated after stale ID recovery.', 'woo-bol-sync' ),
                        'subscription_id' => $resolved_new,
                        'url'             => $webhook_url,
                        'process_status'  => (string) ( $create_body['processStatusId'] ?? '' ),
                        'event_type'      => (string) ( $create_body['eventType'] ?? '' ),
                        'status'          => (string) ( $create_body['status'] ?? '' ),
                        'summary'         => (string) $create_response['summary'],
                        'http_code'       => (int) $create_response['code'],
                    ];
                }
            }
            return [
                'ok'      => false,
                'message' => (string) $response['summary'],
                'code'    => $response['code'],
            ];
        }

        $body        = is_array( $response['body'] ) ? $response['body'] : [];
        $body        = $this->await_subscription_process( $body );
        $resolved_id = $this->resolve_subscription_id_from_response( $body, $subscription_id, $webhook_url );
        if ( $resolved_id !== '' ) {
            update_option( Mapping_Config::OPTION_WEBHOOK_SUBSCRIPTION_ID, $resolved_id );
        }

        return [
            'ok'              => true,
            'message'         => __( 'Webhook subscription is configured.', 'woo-bol-sync' ),
            'subscription_id' => $resolved_id,
            'url'             => $webhook_url,
            'process_status'  => (string) ( $body['processStatusId'] ?? '' ),
            'event_type'      => (string) ( $body['eventType'] ?? '' ),
            'status'          => (string) ( $body['status'] ?? '' ),
            'summary'         => (string) $response['summary'],
            'http_code'       => (int) $response['code'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $ensure_result
     * @return array<string, mixed>
     */
    public function test_configured_subscription( array $ensure_result = [] ): array {
        $webhook_url     = Mapping_Config::get_webhook_url();
        $subscription_id = $this->resolve_subscription_id_for_test( $webhook_url );
        if ( $subscription_id === '' ) {
            return [
                'ok'      => false,
                'message' => __( 'No webhook subscription ID is stored yet.', 'woo-bol-sync' ),
            ];
        }

        $pending_process = trim( (string) ( $ensure_result['process_status'] ?? '' ) );
        if (
            $pending_process !== ''
            && strtoupper( (string) ( $ensure_result['status'] ?? '' ) ) === 'PENDING'
        ) {
            $this->await_subscription_process(
                [
                    'processStatusId' => $pending_process,
                    'status'          => 'PENDING',
                ]
            );
        }

        $subscription_state = $this->get_subscription_state( $subscription_id );
        if ( $subscription_state !== [] && empty( $subscription_state['enabled'] ) ) {
            return [
                'ok'        => false,
                'message'   => __( 'Webhook subscription exists but is disabled on bol.com (often after failed deliveries). Run “Ensure subscription” again to re-enable it, then retry the test.', 'woo-bol-sync' ),
                'http_code' => 0,
            ];
        }

        $response = $this->send_test_notification( $subscription_id );
        if ( ! empty( $response['ok'] ) ) {
            return $response;
        }

        // Stale IDs (e.g. process status UUID saved by mistake) often yield HTTP 400.
        if ( (int) ( $response['http_code'] ?? 0 ) === 400 ) {
            $reconciled = $this->reconcile_subscription_id_from_api( $webhook_url );
            if ( $reconciled !== '' && $reconciled !== $subscription_id ) {
                update_option( Mapping_Config::OPTION_WEBHOOK_SUBSCRIPTION_ID, $reconciled, false );
                $retry = $this->send_test_notification( $reconciled );
                if ( ! empty( $retry['ok'] ) ) {
                    $retry['message'] = __( 'Test notification was scheduled (subscription ID was corrected).', 'woo-bol-sync' );
                    return $retry;
                }
                return $retry;
            }
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function send_test_notification( string $subscription_id ): array {
        $response = $this->api->send_test_subscription_notification( $subscription_id );
        if ( is_wp_error( $response ) ) {
            return [
                'ok'      => false,
                'message' => $response->get_error_message(),
            ];
        }
        if ( $response['code'] < 200 || $response['code'] >= 300 ) {
            return [
                'ok'        => false,
                'message'   => $this->format_api_failure_message( $response ),
                'http_code' => (int) $response['code'],
                'body'      => is_array( $response['body'] ?? null ) ? $response['body'] : [],
            ];
        }

        $body = is_array( $response['body'] ) ? $response['body'] : [];
        return [
            'ok'              => true,
            'message'         => __( 'Test notification was scheduled.', 'woo-bol-sync' ),
            'subscription_id' => $subscription_id,
            'process_status'  => (string) ( $body['processStatusId'] ?? '' ),
            'status'          => (string) ( $body['status'] ?? '' ),
            'summary'         => (string) $response['summary'],
            'http_code'       => (int) $response['code'],
        ];
    }

    private function resolve_subscription_id_for_test( string $webhook_url ): string {
        $stored = Mapping_Config::get_subscription_id();
        if ( $stored === '' ) {
            return $this->reconcile_subscription_id_from_api( $webhook_url );
        }

        $lookup = $this->api->get_subscription( $stored );
        if ( ! is_wp_error( $lookup ) && $lookup['code'] >= 200 && $lookup['code'] < 300 ) {
            return $stored;
        }

        return $this->reconcile_subscription_id_from_api( $webhook_url ) ?: $stored;
    }

    private function reconcile_subscription_id_from_api( string $webhook_url ): string {
        $existing = $this->find_existing_subscription( $webhook_url );
        if ( $existing === [] ) {
            return '';
        }

        $id = $this->extract_subscription_id_from_record( $existing );
        if ( $id !== '' ) {
            update_option( Mapping_Config::OPTION_WEBHOOK_SUBSCRIPTION_ID, $id, false );
        }
        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh_signature_keys(): array {
        $response = $this->api->get_subscription_signature_keys();
        if ( is_wp_error( $response ) ) {
            return [
                'ok'      => false,
                'message' => $response->get_error_message(),
            ];
        }
        if ( $response['code'] < 200 || $response['code'] >= 300 ) {
            return [
                'ok'        => false,
                'message'   => (string) $response['summary'],
                'http_code' => (int) $response['code'],
            ];
        }

        $body = is_array( $response['body'] ) ? $response['body'] : [];
        $keys = isset( $body['signatureKeys'] ) && is_array( $body['signatureKeys'] ) ? $body['signatureKeys'] : [];

        $sanitized_keys = array_values(
            array_filter(
                array_map(
                    static function ( $entry ): array {
                        return is_array( $entry ) ? $entry : [];
                    },
                    $keys
                ),
                static fn( array $entry ): bool => $entry !== []
            )
        );

        set_transient( 'wbs_signature_keys', $sanitized_keys, HOUR_IN_SECONDS );
        update_option( Mapping_Config::OPTION_WEBHOOK_SIGNATURE_KEYS, $sanitized_keys, false );
        update_option( Mapping_Config::OPTION_WEBHOOK_SIGNATURE_KEYS_FETCHED, current_time( 'mysql', true ), false );

        return [
            'ok'        => true,
            'message'   => __( 'Signature keys refreshed.', 'woo-bol-sync' ),
            'count'     => count( $sanitized_keys ),
            'http_code' => (int) $response['code'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function find_existing_subscription( string $url ): array {
        $response = $this->api->list_subscriptions();
        if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 || ! is_array( $response['body'] ) ) {
            return [];
        }

        $body  = $response['body'];
        $items = [];
        foreach ( [ 'subscriptions', 'items', 'results' ] as $key ) {
            if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
                $items = $body[ $key ];
                break;
            }
        }

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $resources = isset( $item['resources'] ) && is_array( $item['resources'] ) ? $item['resources'] : [];
            $subscription_type = strtoupper( (string) ( $item['subscriptionType'] ?? self::WEBHOOK_SUBSCRIPTION_TYPE ) );
            if (
                (string) ( $item['url'] ?? '' ) === $url
                && $subscription_type === self::WEBHOOK_SUBSCRIPTION_TYPE
                && in_array( self::RESOURCE_PROCESS_STATUS, $resources, true )
            ) {
                return $item;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function build_webhook_payload( string $webhook_url ): array {
        return [
            'resources'        => [ self::RESOURCE_PROCESS_STATUS ],
            'url'              => $webhook_url,
            'subscriptionType' => self::WEBHOOK_SUBSCRIPTION_TYPE,
            'enabled'          => true,
        ];
    }

    /**
     * Resolve subscription ID from a create/update API response.
     *
     * Create/update are asynchronous: the immediate body carries processStatusId;
     * the real subscriptionId is entityId on the completed process status.
     *
     * @param array<string, mixed> $body
     */
    private function resolve_subscription_id_from_response( array $body, string $fallback, string $webhook_url ): string {
        foreach ( [ 'subscriptionId' ] as $key ) {
            if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
                $id = trim( (string) $body[ $key ] );
                if ( $id !== '' ) {
                    return $id;
                }
            }
        }

        $process_status_id = '';
        foreach ( [ 'processStatusId', 'process_status_id' ] as $key ) {
            if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
                $process_status_id = trim( (string) $body[ $key ] );
                break;
            }
        }

        if ( $process_status_id !== '' ) {
            $from_process = $this->subscription_id_from_process_status( $process_status_id );
            if ( $from_process !== '' ) {
                return $from_process;
            }
        }

        $status = strtoupper( (string) ( $body['status'] ?? '' ) );
        if ( $process_status_id === '' && $status !== 'PENDING' ) {
            foreach ( [ 'entityId' ] as $key ) {
                if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
                    $id = trim( (string) $body[ $key ] );
                    if ( $id !== '' ) {
                        return $id;
                    }
                }
            }
        }

        if ( $fallback !== '' ) {
            return $fallback;
        }

        return $this->reconcile_subscription_id_from_api( $webhook_url );
    }

    /**
     * Wait for async subscription create/update (bol returns 202 + PENDING).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function await_subscription_process( array $body ): array {
        $process_status_id = '';
        foreach ( [ 'processStatusId', 'process_status_id' ] as $key ) {
            if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
                $process_status_id = trim( (string) $body[ $key ] );
                break;
            }
        }
        if ( $process_status_id === '' ) {
            return $body;
        }

        $status = strtoupper( (string) ( $body['status'] ?? '' ) );
        if ( $status !== '' && $status !== 'PENDING' ) {
            return $body;
        }

        $process = $this->api->wait_for_process_status( $process_status_id, 25, 1500 );
        if ( is_wp_error( $process ) || ! is_array( $process['body'] ?? null ) ) {
            return $body;
        }

        return array_merge( $body, $process['body'] );
    }

    /**
     * @return array{enabled:bool, url:string}
     */
    private function get_subscription_state( string $subscription_id ): array {
        $lookup = $this->api->get_subscription( $subscription_id );
        if ( is_wp_error( $lookup ) || $lookup['code'] < 200 || $lookup['code'] >= 300 || ! is_array( $lookup['body'] ?? null ) ) {
            return [];
        }

        $body = $lookup['body'];
        return [
            'enabled' => (bool) ( $body['enabled'] ?? true ),
            'url'     => (string) ( $body['url'] ?? '' ),
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function format_api_failure_message( array $response ): string {
        $summary = trim( (string) ( $response['summary'] ?? '' ) );
        $body    = is_array( $response['body'] ?? null ) ? $response['body'] : [];
        $detail  = trim( (string) ( $body['detail'] ?? '' ) );

        if ( $detail !== '' && ( $summary === '' || strcasecmp( $summary, $detail ) === 0 ) ) {
            return $detail;
        }
        if ( $detail !== '' && $summary !== '' ) {
            return $summary . ' — ' . $detail;
        }

        return $summary !== '' ? $summary : __( 'Unknown API error.', 'woo-bol-sync' );
    }

    private function subscription_id_from_process_status( string $process_status_id ): string {
        $process = $this->api->wait_for_process_status( $process_status_id, 25, 1500 );
        if ( is_wp_error( $process ) || ! is_array( $process['body'] ?? null ) ) {
            return '';
        }

        $process_body = $process['body'];
        foreach ( [ 'entityId', 'subscriptionId' ] as $key ) {
            if ( isset( $process_body[ $key ] ) && is_scalar( $process_body[ $key ] ) ) {
                $id = trim( (string) $process_body[ $key ] );
                if ( $id !== '' ) {
                    return $id;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extract_subscription_id_from_record( array $record ): string {
        foreach ( [ 'subscriptionId', 'id' ] as $key ) {
            if ( isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ) {
                $id = trim( (string) $record[ $key ] );
                if ( $id !== '' ) {
                    return $id;
                }
            }
        }
        return '';
    }
}
