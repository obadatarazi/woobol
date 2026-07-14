<?php
/**
 * bol.com API client.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Services;

use WooBolSync\Includes\Logger;
use WooBolSync\Includes\Mapping_Config;

defined( 'ABSPATH' ) || exit;

class Bol_API_Service {

    private const TOKEN_URL          = 'https://login.bol.com/token';
    private const API_BASE_URL       = 'https://api.bol.com/retailer';
    private const SHARED_BASE_URL    = 'https://api.bol.com/shared';
    private const MAX_RETRY_SLEEP_MS = 4000;
    private const RATE_LIMIT_PREFIX  = 'wbs_rate_limit_';
    private const RETAILER_MEDIA_TYPE_V10 = 'application/vnd.retailer.v10+json';
    private const RETAILER_MEDIA_TYPE_V11 = 'application/vnd.retailer.v11+json';

    private string $last_error = '';

    /**
     * @var array<string, mixed>
     */
    private array $last_request_meta = [];

    public function has_credentials(): bool {
        return trim( (string) get_option( 'wbs_client_id', '' ) ) !== '' && trim( (string) get_option( 'wbs_client_secret', '' ) ) !== '';
    }

    public function get_last_error(): string {
        return $this->last_error;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_last_request_meta(): array {
        return $this->last_request_meta;
    }

    public function test_connection(): bool {
        $response = $this->request_with_headers( '/orders?' . http_build_query( [ 'fulfilment-method' => 'ALL', 'status' => 'ALL', 'page' => 1, 'size' => 1 ], '', '&', PHP_QUERY_RFC3986 ), 'GET' );
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $ok = $response['code'] >= 200 && $response['code'] < 300;
        if ( ! $ok ) {
            $this->last_error = (string) ( $response['summary'] ?? __( 'Unexpected API response.', 'woo-bol-sync' ) );
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function create_economic_operator( array $payload ) {
        return $this->request_economic_operator( '/economic-operator', 'POST', $payload );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_economic_operator( string $operator_id ) {
        return $this->request_economic_operator( '/economic-operator/' . rawurlencode( $operator_id ), 'GET' );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function update_economic_operator( string $operator_id, array $payload ) {
        return $this->request_economic_operator( '/economic-operator/' . rawurlencode( $operator_id ), 'PUT', $payload );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function delete_economic_operator( string $operator_id ) {
        return $this->request_economic_operator( '/economic-operator/' . rawurlencode( $operator_id ), 'DELETE' );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function search_economic_operators() {
        return $this->request_economic_operator( '/economic-operators', 'GET' );
    }

    /**
     * @return true|\WP_Error
     */
    public function fetch_and_store_economic_operator() {
        $result = $this->search_economic_operators();
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( $result['code'] < 200 || $result['code'] >= 300 ) {
            return new \WP_Error( 'wbs_eo_fetch_failed', (string) ( $result['summary'] ?? __( 'Could not load economic operator.', 'woo-bol-sync' ) ) );
        }

        $body = is_array( $result['body'] ) ? $result['body'] : [];
        $item = $this->extract_first_economic_operator( $body );

        if ( $item === [] ) {
            Logger::warning(
                'Economic operator search returned no parsable records.',
                [
                    'summary' => $result['summary'] ?? '',
                    'body'    => $body,
                ],
                'api'
            );
            Mapping_Config::clear_economic_operator_options();
            return new \WP_Error( 'wbs_eo_empty', __( 'No economic operator found in bol.com.', 'woo-bol-sync' ) );
        }

        $validation_error = $this->validate_economic_operator_data( $item );
        if ( $validation_error !== '' ) {
            Logger::warning(
                'Economic operator data validation failed.',
                [ 'error' => $validation_error, 'data' => $item ],
                'api'
            );
            return new \WP_Error( 'wbs_eo_invalid', $validation_error );
        }

        $operator_id = (string) ( $item['economicOperatorId'] ?? $item['id'] ?? '' );
        if ( $operator_id === '' ) {
            Logger::warning(
                'Economic operator record is missing an identifier.',
                [ 'body' => $item ],
                'api'
            );
            return new \WP_Error( 'wbs_eo_missing_id', __( 'Economic operator was returned without an ID.', 'woo-bol-sync' ) );
        }

        update_option( Mapping_Config::OPTION_EO_ID, sanitize_text_field( $operator_id ) );
        update_option( Mapping_Config::OPTION_EO_NAME, sanitize_text_field( (string) ( $item['name'] ?? '' ) ) );
        update_option( Mapping_Config::OPTION_EO_STATUS, sanitize_text_field( (string) ( $item['status'] ?? '' ) ) );
        update_option( Mapping_Config::OPTION_EO_LAST_SYNC, current_time( 'mysql', true ) );

        return true;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_process_status( string $process_status_id ) {
        return $this->request_with_headers(
            '/process-status/' . rawurlencode( $process_status_id ),
            'GET',
            [],
            [
                'Accept' => self::RETAILER_MEDIA_TYPE_V10,
            ],
            self::SHARED_BASE_URL
        );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function list_subscriptions() {
        return $this->request_with_headers( '/subscriptions', 'GET' );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function create_subscription( array $payload ) {
        return $this->request_with_headers( '/subscriptions', 'POST', $payload );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_subscription( string $subscription_id ) {
        return $this->request_with_headers( '/subscriptions/' . rawurlencode( $subscription_id ), 'GET' );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function update_subscription( string $subscription_id, array $payload ) {
        return $this->request_with_headers( '/subscriptions/' . rawurlencode( $subscription_id ), 'PUT', $payload );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function delete_subscription( string $subscription_id ) {
        return $this->request_with_headers( '/subscriptions/' . rawurlencode( $subscription_id ), 'DELETE' );
    }

    /**
     * Permanently remove an offer from bol.com (Offer API).
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function delete_offer( string $offer_id ) {
        $id = trim( $offer_id );
        if ( $id === '' ) {
            return new \WP_Error( 'wbs_offer_id_required', __( 'Offer ID is required.', 'woo-bol-sync' ) );
        }

        return $this->request_with_headers( '/offers/' . rawurlencode( $id ), 'DELETE' );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function send_test_subscription_notification( string $subscription_id ) {
        // bol.com expects Content-Type on POST even when the body is empty (see demo SUBSCRIPTIONS spec).
        return $this->request_with_headers(
            '/subscriptions/test/' . rawurlencode( $subscription_id ),
            'POST',
            [],
            [],
            self::API_BASE_URL,
            true
        );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_subscription_signature_keys() {
        return $this->request_with_headers( '/subscriptions/signature-keys', 'GET' );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function create_product_content( array $payload ) {
        return $this->request_with_headers( '/content/products', 'POST', $payload );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_content_upload_report( string $upload_id ) {
        return $this->request_with_headers( '/content/upload-report/' . rawurlencode( $upload_id ), 'GET' );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_catalog_product( string $ean, string $language = 'nl' ) {
        return $this->request_with_headers(
            '/content/catalog-products/' . rawurlencode( $ean ),
            'GET',
            [],
            [ 'Accept-Language' => $language ]
        );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_product_assets( string $ean ) {
        return $this->request_with_headers( '/products/' . rawurlencode( $ean ) . '/assets', 'GET' );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function get_chunk_recommendations( array $payload ) {
        return $this->request_with_headers( '/content/chunk-recommendations', 'POST', $payload );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function list_returns( bool $handled = true, string $fulfilment_method = 'FBR' ) {
        $query = add_query_arg(
            [
                'handled'            => $handled ? 'true' : 'false',
                'fulfilment-method'  => $fulfilment_method,
            ],
            '/returns'
        );
        return $this->request_with_headers( $query, 'GET' );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_order( string $order_id ) {
        return $this->request_with_headers( '/orders/' . rawurlencode( $order_id ), 'GET' );
    }

    /**
     * @return array{ok:bool, checks:array<int, array<string, mixed>>, summary:string}
     */
    public function run_health_check(): array {
        $checks = [];

        $checks[] = [
            'label'   => __( 'Credentials configured', 'woo-bol-sync' ),
            'ok'      => $this->has_credentials(),
            'message' => $this->has_credentials() ? __( 'Client ID and secret are set.', 'woo-bol-sync' ) : __( 'Add Client ID and Client Secret in settings.', 'woo-bol-sync' ),
        ];

        $connected = $this->has_credentials() ? $this->test_connection() : false;
        $checks[]  = [
            'label'   => __( 'API connection', 'woo-bol-sync' ),
            'ok'      => $connected,
            'message' => $connected ? __( 'Connection to bol.com succeeded.', 'woo-bol-sync' ) : ( $this->last_error ?: __( 'Connection test failed.', 'woo-bol-sync' ) ),
        ];

        $checks[] = [
            'label'   => __( 'Webhook automation', 'woo-bol-sync' ),
            'ok'      => Mapping_Config::webhook_enabled(),
            'message' => Mapping_Config::webhook_enabled() ? __( 'Webhook automation is enabled.', 'woo-bol-sync' ) : __( 'Webhook automation is disabled.', 'woo-bol-sync' ),
        ];

        $ok = count( array_filter( $checks, static fn( array $item ): bool => ! empty( $item['ok'] ) ) ) === count( $checks );

        return [
            'ok'      => $ok,
            'checks'  => $checks,
            'summary' => $ok ? __( 'Everything looks healthy.', 'woo-bol-sync' ) : __( 'One or more health checks failed.', 'woo-bol-sync' ),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>|\WP_Error
     */
    public function request_with_headers( string $path, string $method = 'GET', array $body = [], array $headers = [], string $base_url = self::API_BASE_URL, bool $send_empty_json_object = false ) {
        if ( ! $this->has_credentials() ) {
            $this->last_error = __( 'Missing bol.com API credentials.', 'woo-bol-sync' );
            return new \WP_Error( 'wbs_missing_credentials', $this->last_error );
        }

        $route = $this->normalize_path( $path );
        
        if ( ! $this->check_rate_limit( $route ) ) {
            $wait_seconds = $this->get_rate_limit_wait_time( $route );
            $this->last_error = sprintf(
                __( 'Rate limit active for this endpoint. Please wait %d seconds.', 'woo-bol-sync' ),
                $wait_seconds
            );
            Logger::debug(
                'Request skipped due to active rate limit.',
                [ 'route' => $route, 'wait_seconds' => $wait_seconds ],
                'api'
            );
            return new \WP_Error( 'wbs_rate_limited', $this->last_error );
        }

        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            $this->last_error = $token->get_error_message();
            return $token;
        }

        $attempts = max( 0, (int) get_option( 'wbs_api_retry_count', 2 ) ) + 1;
        $url      = $this->build_request_url( $path, $base_url );
        $is_offer = $this->is_offer_endpoint( $route );
        $verb     = strtoupper( $method );
        $has_body = ( $body !== [] || $send_empty_json_object ) && ! in_array( $verb, [ 'GET', 'DELETE' ], true );
        $default_headers = $this->default_media_headers( $route, $base_url );
        // Never reuse this variable for response headers — merging response headers into the next
        // request breaks Offer API media-type fallback (and retry attempts).
        $header_overrides     = $headers;
        $effective_media_type = (string) ( $header_overrides['Accept'] ?? $default_headers['Accept'] ?? self::RETAILER_MEDIA_TYPE_V10 );

        for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
            $request_headers = array_merge(
                $default_headers,
                [ 'Authorization' => 'Bearer ' . $token ],
                $header_overrides
            );
            if ( ! $has_body ) {
                unset( $request_headers['Content-Type'] );
            } elseif ( ! isset( $request_headers['Content-Type'] ) ) {
                $request_headers['Content-Type'] = $effective_media_type;
            }

            $args = [
                'method'  => $verb,
                'timeout' => 30,
                'headers' => $request_headers,
            ];

            Logger::debug(
                'bol.com API request prepared.',
                [
                    'path'       => $route,
                    'method'     => $args['method'],
                    'media_type' => (string) ( $args['headers']['Accept'] ?? $effective_media_type ),
                    'attempt'    => $attempt,
                    'max_attempts' => $attempts,
                ],
                'api'
            );

            if ( $is_offer ) {
                Logger::debug(
                    'Offer API request prepared.',
                    [
                        'path'       => $route,
                        'method'     => $args['method'],
                        'media_type' => (string) ( $args['headers']['Accept'] ?? $effective_media_type ),
                        'attempt'    => $attempt,
                    ],
                    'api'
                );
            }

            if ( $has_body ) {
                $args['body'] = wp_json_encode( $body !== [] ? $body : new \stdClass() );
            }

            $response = wp_remote_request( $url, $args );
            if ( is_wp_error( $response ) ) {
                $this->last_error = $response->get_error_message();
                if ( $attempt < $attempts ) {
                    $delay_ms = $this->retry_sleep_ms( $attempt );
                    Logger::warning(
                        'bol.com API request attempt failed; retrying.',
                        [
                            'url'          => $url,
                            'path'         => $route,
                            'method'       => $args['method'],
                            'attempt'      => $attempt,
                            'max_attempts' => $attempts,
                            'error'        => $this->last_error,
                            'retry_delay_ms' => $delay_ms,
                        ],
                        'api'
                    );
                    usleep( $delay_ms * 1000 );
                    continue;
                }
                return $response;
            }

            $code             = (int) wp_remote_retrieve_response_code( $response );
            $response_headers = $this->normalize_response_headers( wp_remote_retrieve_headers( $response ) );
            $raw              = (string) wp_remote_retrieve_body( $response );
            $decoded          = $this->decode_body( $raw );
            $summary          = $this->summarize_http_response( $decoded, $raw, $code );
            $media_type       = (string) ( $args['headers']['Accept'] ?? $effective_media_type );
            $fallback_applied = false;
            $fallback_trigger_code = 0;
            $initial_media_type    = $media_type;
            $fallback_media_type   = $this->resolve_offer_media_fallback_target( $is_offer, $base_url, $media_type, $code, $summary );

            if ( $fallback_media_type !== '' ) {
                $fallback_trigger_code = $code;
                Logger::warning(
                    'Offer API request failed; retrying once with alternate media type.',
                    [
                        'path'         => $route,
                        'method'       => $verb,
                        'code'         => $code,
                        'request_id'   => $this->first_header_value( $response_headers, [ 'x-request-id', 'request-id' ] ),
                        'media_type'   => $media_type,
                        'fallback_to'  => $fallback_media_type,
                    ],
                    'api'
                );

                $fallback_response = $this->request_with_media_type( $url, $verb, $has_body, $body, $default_headers, $header_overrides, $token, $fallback_media_type );
                if ( ! is_wp_error( $fallback_response ) ) {
                    $response           = $fallback_response;
                    $code               = (int) wp_remote_retrieve_response_code( $response );
                    $response_headers   = $this->normalize_response_headers( wp_remote_retrieve_headers( $response ) );
                    $raw                = (string) wp_remote_retrieve_body( $response );
                    $decoded            = $this->decode_body( $raw );
                    $summary            = $this->summarize_http_response( $decoded, $raw, $code );
                    $media_type         = $fallback_media_type;
                    $fallback_applied   = true;
                } else {
                    $this->last_error = $fallback_response->get_error_message();
                }
            }

            $this->last_request_meta = [
                'url'            => $url,
                'path'           => $route,
                'method'         => $args['method'],
                'code'           => $code,
                'attempt'        => $attempt,
                'max_attempts'   => $attempts,
                'media_type'     => $media_type,
                'request_id'     => $this->first_header_value( $response_headers, [ 'x-request-id', 'request-id' ] ),
                'correlation_id' => $this->first_header_value( $response_headers, [ 'x-correlation-id', 'correlation-id' ] ),
                'traceparent'    => $this->first_header_value( $response_headers, [ 'traceparent' ] ),
                'initial_media_type' => $initial_media_type,
                'fallback_media_type' => $fallback_applied ? $media_type : '',
                'fallback_trigger_code' => $fallback_trigger_code,
                'fallback_applied' => $fallback_applied,
            ];

            if ( $code === 429 ) {
                $wait = $this->response_retry_after_seconds( $response_headers );
                $this->store_rate_limit( $route, $wait > 0 ? $wait : 60 );
                Logger::warning(
                    'bol.com API rate limit hit (HTTP 429).',
                    [
                        'route'       => $route,
                        'retry_after' => $wait,
                        'attempt'     => $attempt,
                    ],
                    'api'
                );
            }

            if ( $this->is_transient_http_status( $code ) && $attempt < $attempts ) {
                $wait = $this->response_retry_after_seconds( $response_headers );
                $delay_ms = $wait > 0 ? $wait * 1000 : $this->retry_sleep_ms( $attempt );
                Logger::warning(
                    'bol.com API request got transient status; retrying.',
                    [
                        'url'          => $url,
                        'path'         => $route,
                        'method'       => $args['method'],
                        'code'         => $code,
                        'attempt'      => $attempt,
                        'max_attempts' => $attempts,
                        'retry_after'  => $wait,
                        'retry_delay_ms' => $delay_ms,
                    ],
                    'api'
                );
                usleep( $delay_ms * 1000 );
                continue;
            }

            if ( $code < 200 || $code >= 300 ) {
                $summary = $this->with_version_guidance( $summary, $code, $media_type, $is_offer );
                $this->last_error = $summary;
                Logger::warning(
                    'bol.com API request failed.',
                    [
                        'url'     => $url,
                        'method'  => $args['method'],
                        'code'    => $code,
                        'media_type' => $media_type,
                        'summary' => $summary,
                        'request_id' => $this->last_request_meta['request_id'] ?? '',
                        'correlation_id' => $this->last_request_meta['correlation_id'] ?? '',
                        'initial_media_type' => $this->last_request_meta['initial_media_type'] ?? '',
                        'fallback_media_type' => $this->last_request_meta['fallback_media_type'] ?? '',
                        'fallback_trigger_code' => $this->last_request_meta['fallback_trigger_code'] ?? 0,
                        'fallback_applied' => $this->last_request_meta['fallback_applied'] ?? false,
                    ],
                    'api'
                );
            }

            return [
                'code'    => $code,
                'headers' => $response_headers,
                'body'    => $decoded,
                'raw'     => $raw,
                'summary' => $summary,
            ];
        }

        $this->last_error = __( 'API request failed after retries.', 'woo-bol-sync' );
        return new \WP_Error( 'wbs_request_failed', $this->last_error );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|\WP_Error
     */
    public function request_raw( string $path, string $method = 'GET', array $body = [], array $headers = [] ) {
        return $this->request_with_headers( $path, $method, $body, $headers );
    }

    /**
     * Poll async bol process statuses until they complete.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function wait_for_process_status( string $process_status_id, int $max_attempts = 10, int $sleep_ms = 1200 ) {
        $max_attempts = max( 1, $max_attempts );
        $sleep_ms     = max( 250, $sleep_ms );

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $result = $this->get_process_status( $process_status_id );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            if ( $result['code'] < 200 || $result['code'] >= 300 ) {
                return $result;
            }

            $body   = is_array( $result['body'] ) ? $result['body'] : [];
            $status = strtoupper( (string) ( $body['status'] ?? '' ) );
            if ( in_array( $status, [ 'SUCCESS', 'FAILURE', 'TIMEOUT' ], true ) ) {
                return $result;
            }

            if ( $attempt < $max_attempts ) {
                $wait_ms = min( $sleep_ms * $attempt, 5000 );
                usleep( $wait_ms * 1000 );
            }
        }

        return new \WP_Error( 'wbs_process_timeout', __( 'Timed out while waiting for bol.com process status.', 'woo-bol-sync' ) );
    }

    public function extract_process_status_id( array $response ): string {
        $body = is_array( $response['body'] ?? null ) ? $response['body'] : [];

        foreach ( [ 'processStatusId', 'process_status_id', 'statusId' ] as $key ) {
            if ( ! empty( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
                return (string) $body[ $key ];
            }
        }
        $summary = strtoupper( (string) ( $response['summary'] ?? '' ) );
        if ( ! empty( $body['id'] ) && is_scalar( $body['id'] ) && str_contains( $summary, 'PENDING' ) ) {
            return (string) $body['id'];
        }

        $location = (string) ( $response['headers']['location'] ?? '' );
        if ( $location !== '' && preg_match( '#/process-status/([^/?]+)#', $location, $matches ) ) {
            return (string) $matches[1];
        }
        $href = (string) ( $body['href'] ?? $body['url'] ?? '' );
        if ( $href !== '' && preg_match( '#/process-status/([^/?]+)#', $href, $matches ) ) {
            return (string) $matches[1];
        }

        return '';
    }

    /**
     * @return array{offer_id:string,status:string,error:string,process_status_id:string}
     */
    public function resolve_offer_response( array $response ): array {
        $body     = is_array( $response['body'] ?? null ) ? $response['body'] : [];
        $offer_id = '';
        foreach ( [ 'offerId', 'entityId', 'offerID', 'id' ] as $key ) {
            if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
                $offer_id = (string) $body[ $key ];
                break;
            }
        }
        $process_status_id = $this->extract_process_status_id( $response );
        if ( $offer_id !== '' && $process_status_id === '' ) {
            return [
                'offer_id'          => $offer_id,
                'status'            => 'SUCCESS',
                'error'             => '',
                'process_status_id' => '',
            ];
        }

        if ( $process_status_id === '' ) {
            return [
                'offer_id'          => $offer_id,
                'status'            => '',
                'error'             => '',
                'process_status_id' => '',
            ];
        }

        $process = $this->wait_for_process_status( $process_status_id );
        if ( is_wp_error( $process ) ) {
            $status = $process->get_error_code() === 'wbs_process_timeout' ? 'TIMEOUT' : 'FAILURE';
            return [
                'offer_id'          => '',
                'status'            => $status,
                'error'             => $process->get_error_message(),
                'process_status_id' => $process_status_id,
            ];
        }

        $process_body = is_array( $process['body'] ) ? $process['body'] : [];
        $resolved_offer_id = (string) ( $process_body['entityId'] ?? $process_body['offerId'] ?? $process_body['id'] ?? $offer_id );
        $status = strtoupper( (string) ( $process_body['status'] ?? $process_body['result'] ?? '' ) );
        $error  = (string) ( $process_body['errorMessage'] ?? $process_body['detail'] ?? $process_body['message'] ?? $process['summary'] ?? '' );

        $resolved_status = $status !== '' ? $status : ( $error !== '' ? 'FAILURE' : 'SUCCESS' );
        return [
            'offer_id'          => $resolved_offer_id,
            'status'            => $resolved_status,
            'error'             => $error,
            'process_status_id' => $process_status_id,
        ];
    }

    public function extract_upload_id( array $response ): string {
        $body = is_array( $response['body'] ?? null ) ? $response['body'] : [];
        foreach ( [ 'uploadId', 'id', 'processStatusId' ] as $key ) {
            if ( ! empty( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
                return (string) $body[ $key ];
            }
        }
        $location = (string) ( $response['headers']['location'] ?? '' );
        if ( $location !== '' && preg_match( '#/upload-report/([^/?]+)#', $location, $matches ) ) {
            return (string) $matches[1];
        }
        return '';
    }

    /**
     * Dedicated Economic Operators v1 request wrapper.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|\WP_Error
     */
    private function request_economic_operator( string $path, string $method = 'GET', array $body = [] ) {
        $headers = [
            'Accept' => 'application/vnd.economic-operator.v1+json',
        ];

        if ( ! in_array( strtoupper( $method ), [ 'GET', 'DELETE' ], true ) ) {
            $headers['Content-Type'] = 'application/vnd.economic-operator.v1+json';
        }

        return $this->request_with_headers(
            $path,
            $method,
            $body,
            $headers
        );
    }

    /**
     * @return array<string, string>
     */
    private function default_media_headers( string $path, string $base_url ): array {
        $media_type = self::RETAILER_MEDIA_TYPE_V10;
        if ( $base_url === self::API_BASE_URL && $this->is_offer_endpoint( $path ) ) {
            $media_type = Mapping_Config::get_offer_media_type();
        }

        return [
            'Accept' => $media_type,
        ];
    }

    private function normalize_path( string $path ): string {
        $route = (string) ( parse_url( $path, PHP_URL_PATH ) ?? $path );
        return '/' . ltrim( $route, '/' );
    }

    private function is_offer_endpoint( string $path ): bool {
        $normalized = ltrim( $this->normalize_path( $path ), '/' );
        return $normalized === 'offers' || str_starts_with( $normalized, 'offers/' );
    }

    private function resolve_offer_media_fallback_target( bool $is_offer, string $base_url, string $media_type, int $code, string $summary = '' ): string {
        if ( ! $is_offer || $base_url !== self::API_BASE_URL ) {
            return '';
        }
        if ( $code === 429 ) {
            return '';
        }

        $looks_like_media = in_array( $code, [ 400, 403, 406, 415, 422 ], true );
        if ( ! $looks_like_media && $code >= 400 && $code < 500 ) {
            $low = strtolower( $summary );
            $looks_like_media = str_contains( $low, 'unsupported media' )
                || str_contains( $low, 'unsupportedmediatype' )
                || str_contains( $low, 'not acceptable' );
        }
        if ( ! $looks_like_media ) {
            return '';
        }

        $canonical = $this->canonical_offer_media_type( $media_type );

        if ( $canonical === self::RETAILER_MEDIA_TYPE_V11 ) {
            return self::RETAILER_MEDIA_TYPE_V10;
        }
        if ( $canonical === self::RETAILER_MEDIA_TYPE_V10 ) {
            return self::RETAILER_MEDIA_TYPE_V11;
        }

        return '';
    }

    private function canonical_offer_media_type( string $media_type ): string {
        $value = strtolower( trim( $media_type ) );
        if ( str_contains( $value, 'v11+json' ) ) {
            return self::RETAILER_MEDIA_TYPE_V11;
        }
        if ( str_contains( $value, 'v10+json' ) ) {
            return self::RETAILER_MEDIA_TYPE_V10;
        }
        return $media_type;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $default_headers
     * @param array<string, string> $header_overrides Request header overrides from the caller (never HTTP response headers).
     * @return array<string, mixed>|\WP_Error
     */
    private function request_with_media_type( string $url, string $verb, bool $has_body, array $body, array $default_headers, array $header_overrides, string $token, string $media_type ) {
        $request_headers = array_merge(
            $default_headers,
            [ 'Authorization' => 'Bearer ' . $token ],
            $header_overrides
        );
        $request_headers['Accept'] = $media_type;
        if ( $has_body ) {
            $request_headers['Content-Type'] = $media_type;
        } else {
            unset( $request_headers['Content-Type'] );
        }

        $args = [
            'method'  => $verb,
            'timeout' => 30,
            'headers' => $request_headers,
        ];
        if ( $has_body ) {
            $body = $this->normalize_offer_body_for_media_type( $url, $verb, $body, $media_type );
            $args['body'] = wp_json_encode( $body );
        }
        return wp_remote_request( $url, $args );
    }

    /**
     * Ensure Offer create payload matches media-type schema when retrying fallback.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function normalize_offer_body_for_media_type( string $url, string $verb, array $body, string $media_type ): array {
        if ( strtoupper( $verb ) !== 'POST' ) {
            return $body;
        }

        $path = (string) ( parse_url( $url, PHP_URL_PATH ) ?? '' );
        if ( ! str_ends_with( $path, '/offers' ) ) {
            return $body;
        }

        $condition = isset( $body['condition'] ) && is_array( $body['condition'] )
            ? $body['condition']
            : [];

        $canonical = $this->canonical_offer_media_type( $media_type );
        if ( $canonical === self::RETAILER_MEDIA_TYPE_V11 ) {
            $category = trim( (string) ( $condition['category'] ?? $condition['name'] ?? 'NEW' ) );
            $body['condition'] = [
                'category' => $category !== '' ? $category : 'NEW',
            ];
            $body['fulfilment'] = $this->normalize_offer_fulfilment_for_media_type(
                isset( $body['fulfilment'] ) && is_array( $body['fulfilment'] ) ? $body['fulfilment'] : [],
                $canonical
            );
            return $body;
        }

        if ( $canonical === self::RETAILER_MEDIA_TYPE_V10 ) {
            $value = trim( (string) ( $condition['name'] ?? $condition['category'] ?? 'NEW' ) );
            if ( $value === '' ) {
                $value = 'NEW';
            }
            $body['condition'] = [
                'name'     => $value,
                'category' => $value,
            ];
            $body['fulfilment'] = $this->normalize_offer_fulfilment_for_media_type(
                isset( $body['fulfilment'] ) && is_array( $body['fulfilment'] ) ? $body['fulfilment'] : [],
                $canonical
            );
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $fulfilment
     * @return array<string, mixed>
     */
    private function normalize_offer_fulfilment_for_media_type( array $fulfilment, string $canonical_media_type ): array {
        $method = strtoupper( trim( (string) ( $fulfilment['method'] ?? 'FBR' ) ) );
        if ( ! in_array( $method, [ 'FBR', 'FBB' ], true ) ) {
            $method = 'FBR';
        }

        if ( $method !== 'FBR' ) {
            return [ 'method' => $method ];
        }

        if ( $canonical_media_type === self::RETAILER_MEDIA_TYPE_V11 ) {
            $schedule = strtoupper( trim( (string) ( $fulfilment['schedule'] ?? '' ) ) );
            if ( ! in_array( $schedule, [ 'MY_DELIVERY_PROMISE', 'SHIPPING_VIA_BOL', 'BOL_DELIVERY_PROMISE' ], true ) ) {
                $schedule = 'MY_DELIVERY_PROMISE';
            }
            $out = [
                'method'   => 'FBR',
                'schedule' => $schedule,
            ];
            if ( $schedule === 'BOL_DELIVERY_PROMISE' && isset( $fulfilment['deliveryPromise'] ) && is_array( $fulfilment['deliveryPromise'] ) ) {
                $out['deliveryPromise'] = $fulfilment['deliveryPromise'];
            }
            return $out;
        }

        return [
            'method'       => 'FBR',
            'deliveryCode' => Mapping_Config::get_default_delivery_code(),
        ];
    }

    private function with_version_guidance( string $summary, int $code, string $media_type, bool $is_offer ): string {
        if ( ! $is_offer || ! in_array( $code, [ 400, 406, 415, 422 ], true ) ) {
            return $summary;
        }
        $display_media_type = $this->canonical_offer_media_type( $media_type );
        $hint = sprintf(
            /* translators: %s: media type sent to bol.com */
            __( ' Offer API call used media type "%s". If this endpoint rejects the version, change the Offer API version in WooBol settings.', 'woo-bol-sync' ),
            $display_media_type
        );
        return $summary . $hint;
    }

    private function build_request_url( string $path, string $base_url ): string {
        if ( str_starts_with( $path, 'http://' ) || str_starts_with( $path, 'https://' ) ) {
            return $path;
        }

        return rtrim( $base_url, '/' ) . '/' . ltrim( $path, '/' );
    }

    /**
     * @return string|\WP_Error
     */
    private function get_access_token() {
        $cached = get_transient( 'wbs_bol_access_token' );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        $client_id     = (string) get_option( 'wbs_client_id', '' );
        $client_secret = (string) get_option( 'wbs_client_secret', '' );

        $response = wp_remote_post(
            self::TOKEN_URL,
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                ],
                'body'    => [
                    'grant_type' => 'client_credentials',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            $summary = $this->summarize_body( is_array( $body ) ? $body : $raw, $raw );
            return new \WP_Error(
                'wbs_token_failed',
                sprintf(
                    /* translators: 1: HTTP status code, 2: API response summary */
                    __( 'Could not retrieve bol.com access token (HTTP %1$d): %2$s', 'woo-bol-sync' ),
                    $code,
                    $summary
                )
            );
        }

        $token      = (string) $body['access_token'];
        $expires_in = max( 60, (int) ( $body['expires_in'] ?? 299 ) - 60 );
        set_transient( 'wbs_bol_access_token', $token, $expires_in );

        return $token;
    }

    private function response_retry_after_seconds( array $headers ): int {
        return isset( $headers['retry-after'] ) ? max( 0, (int) $headers['retry-after'] ) : 0;
    }

    private function is_transient_http_status( int $code ): bool {
        return in_array( $code, [ 408, 425, 429, 500, 502, 503, 504 ], true );
    }

    private function retry_sleep_ms( int $attempt ): int {
        return min( 500 * $attempt, self::MAX_RETRY_SLEEP_MS );
    }

    /**
     * @param mixed $headers
     * @return array<string, string>
     */
    private function normalize_response_headers( $headers ): array {
        if ( is_array( $headers ) ) {
            return array_change_key_case( array_map( 'strval', $headers ), CASE_LOWER );
        }
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            return array_change_key_case( array_map( 'strval', $headers->getAll() ), CASE_LOWER );
        }
        return [];
    }

    /**
     * @param array<string, string> $headers
     * @param array<int, string>    $keys
     */
    private function first_header_value( array $headers, array $keys ): string {
        foreach ( $keys as $key ) {
            if ( isset( $headers[ $key ] ) && is_scalar( $headers[ $key ] ) ) {
                return (string) $headers[ $key ];
            }
        }
        return '';
    }

    /**
     * @return array<string, mixed>|string
     */
    private function decode_body( string $raw ) {
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : $raw;
    }

    /**
     * @param array<string, mixed>|string $decoded
     */
    private function summarize_body( $decoded, string $raw ): string {
        if ( is_array( $decoded ) ) {
            $validation_error = $this->extract_validation_error_summary( $decoded );
            if ( $validation_error !== '' ) {
                return $validation_error;
            }
            foreach ( [ 'title', 'detail', 'message', 'error', 'description' ] as $key ) {
                if ( ! empty( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) ) {
                    return (string) $decoded[ $key ];
                }
            }
            return wp_json_encode( $decoded ) ?: __( 'Unknown API response.', 'woo-bol-sync' );
        }

        $raw = trim( $raw );
        return $raw !== '' ? mb_substr( $raw, 0, 500 ) : __( 'Empty API response.', 'woo-bol-sync' );
    }

    /**
     * Extract first human-readable validation error from bol API response.
     *
     * @param array<string, mixed> $decoded
     */
    private function extract_validation_error_summary( array $decoded ): string {
        foreach ( [ 'violations', 'errors' ] as $group_key ) {
            if ( empty( $decoded[ $group_key ] ) || ! is_array( $decoded[ $group_key ] ) ) {
                continue;
            }
            foreach ( $decoded[ $group_key ] as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $field   = '';
                $message = '';
                foreach ( [ 'field', 'name', 'property', 'path', 'pointer' ] as $field_key ) {
                    if ( isset( $item[ $field_key ] ) && is_scalar( $item[ $field_key ] ) ) {
                        $field = (string) $item[ $field_key ];
                        break;
                    }
                }
                foreach ( [ 'reason', 'message', 'detail', 'description' ] as $message_key ) {
                    if ( isset( $item[ $message_key ] ) && is_scalar( $item[ $message_key ] ) ) {
                        $message = trim( (string) $item[ $message_key ] );
                        if ( $message !== '' ) {
                            break;
                        }
                    }
                }
                if ( $field !== '' && $message !== '' ) {
                    return sprintf( '%s: %s', $field, $message );
                }
                if ( $message !== '' ) {
                    return $message;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>|string $decoded
     */
    private function summarize_http_response( $decoded, string $raw, int $code ): string {
        $summary = $this->summarize_body( $decoded, $raw );
        if ( $code >= 200 && $code < 300 ) {
            return $summary;
        }
        if ( trim( $raw ) === '' ) {
            return sprintf(
                /* translators: %d: HTTP status code */
                __( 'HTTP %d API error (empty response body).', 'woo-bol-sync' ),
                $code
            );
        }
        return $summary;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extract_first_economic_operator( array $body ): array {
        $candidates = [
            $body['operators'] ?? null,
            $body['economicOperators'] ?? null,
            $body['items'] ?? null,
            $body['results'] ?? null,
            $body['economicOperator'] ?? null,
        ];

        foreach ( $candidates as $candidate ) {
            if ( is_array( $candidate ) ) {
                if ( isset( $candidate[0] ) && is_array( $candidate[0] ) ) {
                    return $candidate[0];
                }
                if ( isset( $candidate['economicOperatorId'] ) || isset( $candidate['id'] ) ) {
                    return $candidate;
                }
            }
        }

        if ( isset( $body['economicOperatorId'] ) || isset( $body['id'] ) ) {
            return $body;
        }

        return [];
    }

    /**
     * Validate economic operator data before storing.
     *
     * @param array<string, mixed> $data
     * @return string Empty string if valid, error message otherwise
     */
    private function validate_economic_operator_data( array $data ): string {
        $required_fields = [
            'name'         => __( 'Economic operator name is required.', 'woo-bol-sync' ),
            'street'       => __( 'Street address is required.', 'woo-bol-sync' ),
            'houseNumber'  => __( 'House number is required.', 'woo-bol-sync' ),
            'postalCode'   => __( 'Postal code is required.', 'woo-bol-sync' ),
            'city'         => __( 'City is required.', 'woo-bol-sync' ),
            'country'      => __( 'Country code is required.', 'woo-bol-sync' ),
            'emailAddress' => __( 'Email address is required.', 'woo-bol-sync' ),
        ];

        foreach ( $required_fields as $field => $error_msg ) {
            if ( empty( $data[ $field ] ) || ! is_scalar( $data[ $field ] ) ) {
                return $error_msg;
            }
        }

        $email = (string) $data['emailAddress'];
        if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
            return __( 'Economic operator email address is invalid.', 'woo-bol-sync' );
        }

        $country = (string) $data['country'];
        if ( strlen( $country ) !== 2 || ! ctype_alpha( $country ) ) {
            return __( 'Country code must be a 2-letter ISO code (e.g., NL, BE, DE).', 'woo-bol-sync' );
        }

        return '';
    }

    /**
     * Check if endpoint is currently rate limited.
     */
    private function check_rate_limit( string $endpoint ): bool {
        $key         = self::RATE_LIMIT_PREFIX . md5( $endpoint );
        $blocked_until = get_transient( $key );
        
        if ( $blocked_until === false ) {
            return true;
        }

        return time() >= (int) $blocked_until;
    }

    /**
     * Store rate limit info for an endpoint.
     */
    private function store_rate_limit( string $endpoint, int $wait_seconds ): void {
        $key = self::RATE_LIMIT_PREFIX . md5( $endpoint );
        $blocked_until = time() + $wait_seconds;
        set_transient( $key, $blocked_until, $wait_seconds + 5 );
        
        Logger::debug(
            'Rate limit stored for endpoint.',
            [
                'endpoint'      => $endpoint,
                'wait_seconds'  => $wait_seconds,
                'blocked_until' => gmdate( 'Y-m-d H:i:s', $blocked_until ),
            ],
            'api'
        );
    }

    /**
     * Get remaining wait time for rate limited endpoint.
     */
    private function get_rate_limit_wait_time( string $endpoint ): int {
        $key           = self::RATE_LIMIT_PREFIX . md5( $endpoint );
        $blocked_until = get_transient( $key );
        
        if ( $blocked_until === false ) {
            return 0;
        }

        return max( 0, (int) $blocked_until - time() );
    }
}
