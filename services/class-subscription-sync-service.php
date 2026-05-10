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
                    $resolved_new = $this->resolve_subscription_id( $create_body, '' );
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
        $resolved_id = $this->resolve_subscription_id( $body, $subscription_id );
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
    public function test_configured_subscription(): array {
        $subscription_id = Mapping_Config::get_subscription_id();
        if ( $subscription_id === '' ) {
            return [
                'ok'      => false,
                'message' => __( 'No webhook subscription ID is stored yet.', 'woo-bol-sync' ),
            ];
        }

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
                'message'   => (string) $response['summary'],
                'http_code' => (int) $response['code'],
            ];
        }

        $body = is_array( $response['body'] ) ? $response['body'] : [];
        return [
            'ok'             => true,
            'message'        => __( 'Test notification was scheduled.', 'woo-bol-sync' ),
            'subscription_id'=> $subscription_id,
            'process_status' => (string) ( $body['processStatusId'] ?? '' ),
            'status'         => (string) ( $body['status'] ?? '' ),
            'summary'        => (string) $response['summary'],
            'http_code'      => (int) $response['code'],
        ];
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
     * @param array<string, mixed> $body
     */
    private function resolve_subscription_id( array $body, string $fallback ): string {
        foreach ( [ 'subscriptionId', 'id', 'entityId' ] as $key ) {
            if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
                return trim( (string) $body[ $key ] );
            }
        }
        return $fallback;
    }
}
