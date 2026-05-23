<?php
/**
 * REST endpoint for bol.com push notifications.
 *
 * Authenticates inbound POSTs via {@see Webhook_Verifier} (RSA signature,
 * shared-secret token, or custom filter) and queues a debounced order sync.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

use WooBolSync\Services\Bol_API_Service;

defined( 'ABSPATH' ) || exit;

class Webhook_Controller {

    private const ORDER_SYNC_DEBOUNCE_TRANSIENT = 'wbs_webhook_order_sync_queued';
    private const RATE_LIMIT_TRANSIENT_PREFIX   = 'wbs_webhook_rl_';
    private const RATE_LIMIT_MAX_PER_MINUTE     = 60;

    private Bol_API_Service $api;

    public function __construct( Bol_API_Service $api ) {
        $this->api = $api;
    }

    public function register_routes(): void {
        register_rest_route(
            'woobol/v1',
            '/webhook',
            [
                'methods'             => [ 'GET', 'POST', 'HEAD' ],
                'callback'            => [ $this, 'handle' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $method = strtoupper( (string) $request->get_method() );

        if ( $method !== 'POST' ) {
            return new \WP_REST_Response( [ 'ok' => true ], 200 );
        }

        if ( $this->is_rate_limited( $request ) ) {
            Logger::warning(
                'Webhook rate limit reached.',
                $this->request_log_context( $request, '' ),
                'automation'
            );
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'rate_limited' ], 429 );
        }

        $raw_body = (string) $request->get_body();
        $auth     = Webhook_Verifier::authorize( $request, $raw_body );

        if ( ! $auth['ok'] ) {
            Logger::warning(
                'Webhook authentication failed.',
                array_merge(
                    $this->request_log_context( $request, $raw_body ),
                    [
                        'reason'   => $auth['reason'],
                        'strategy' => $auth['strategy'],
                    ]
                ),
                'automation'
            );

            $http_code = $auth['reason'] === Webhook_Verifier::REASON_NO_CONFIG ? 503 : 401;
            return new \WP_REST_Response(
                [
                    'ok'    => false,
                    'error' => $auth['reason'],
                ],
                $http_code
            );
        }

        $payload = $this->safe_decode_json( $raw_body );

        Logger::info(
            'Webhook received.',
            array_merge(
                $this->request_log_context( $request, $raw_body ),
                [
                    'auth_strategy' => $auth['strategy'],
                    'event'         => isset( $payload['event'] ) && is_scalar( $payload['event'] ) ? (string) $payload['event'] : '',
                    'resource'      => isset( $payload['resource'] ) && is_scalar( $payload['resource'] ) ? (string) $payload['resource'] : '',
                ]
            ),
            'automation'
        );

        if ( $payload === [] ) {
            return new \WP_REST_Response( [ 'ok' => true, 'test' => true ], 200 );
        }

        if ( ! Mapping_Config::webhook_enabled() ) {
            return new \WP_REST_Response( [ 'ok' => true, 'ignored' => true ], 200 );
        }

        $this->queue_order_sync();

        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }

    /**
     * @return array<string, mixed>
     */
    private function safe_decode_json( string $raw_body ): array {
        if ( $raw_body === '' ) {
            return [];
        }
        $decoded = json_decode( $raw_body, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Build a privacy-friendly log context: hash + length, but never the raw body.
     *
     * @return array<string, mixed>
     */
    private function request_log_context( \WP_REST_Request $request, string $raw_body ): array {
        $body_hash = $raw_body !== '' ? hash( 'sha256', $raw_body ) : '';
        return [
            'method'    => strtoupper( (string) $request->get_method() ),
            'route'     => (string) $request->get_route(),
            'body_size' => strlen( $raw_body ),
            'body_sha256' => $body_hash !== '' ? substr( $body_hash, 0, 16 ) : '',
        ];
    }

    private function is_rate_limited( \WP_REST_Request $request ): bool {
        $client = $this->client_signature( $request );
        if ( $client === '' ) {
            return false;
        }
        $key = self::RATE_LIMIT_TRANSIENT_PREFIX . md5( $client );
        $count = (int) get_transient( $key );
        if ( $count >= self::RATE_LIMIT_MAX_PER_MINUTE ) {
            return true;
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return false;
    }

    private function client_signature( \WP_REST_Request $request ): string {
        $candidates = [
            (string) $request->get_header( 'cf-connecting-ip' ),
            (string) $request->get_header( 'x-real-ip' ),
            (string) $request->get_header( 'x-forwarded-for' ),
            isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '',
        ];
        foreach ( $candidates as $candidate ) {
            $candidate = trim( $candidate );
            if ( $candidate === '' ) {
                continue;
            }
            $first = explode( ',', $candidate )[0];
            return trim( $first );
        }
        return '';
    }

    private function queue_order_sync(): void {
        $debounce = Mapping_Config::get_order_sync_interval_seconds();
        if ( get_transient( self::ORDER_SYNC_DEBOUNCE_TRANSIENT ) ) {
            return;
        }
        if ( ! Sync_Scheduler::can_run_order_sync() ) {
            return;
        }
        if ( wp_next_scheduled( 'wbs_cron_sync_orders' ) ) {
            return;
        }
        set_transient( self::ORDER_SYNC_DEBOUNCE_TRANSIENT, '1', $debounce );
        wp_schedule_single_event( time() + 2, 'wbs_cron_sync_orders' );
    }
}
