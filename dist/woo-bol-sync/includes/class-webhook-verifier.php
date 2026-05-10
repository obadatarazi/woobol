<?php
/**
 * Webhook authentication for inbound bol.com push notifications.
 *
 * Supports three strategies (any may authorize the request):
 *
 *   1. RSA signature via the `X-BOL-Signature` header. The webhook body is
 *      verified against public keys returned by /subscriptions/signature-keys
 *      and cached in {@see Mapping_Config::OPTION_WEBHOOK_SIGNATURE_KEYS}.
 *   2. Shared-secret token via the `X-WBS-Webhook-Token` header or `?token=`
 *      query argument, hash-compared to the option
 *      {@see Mapping_Config::OPTION_WEBHOOK_SHARED_SECRET}.
 *   3. The {@see wbs_webhook_verify_request} filter, which lets integrators
 *      plug a custom verifier when neither of the above is suitable.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook_Verifier
 */
final class Webhook_Verifier {

    public const REASON_OK              = 'ok';
    public const REASON_FILTER          = 'filter_authorized';
    public const REASON_SHARED_SECRET   = 'shared_secret_match';
    public const REASON_RSA_SIGNATURE   = 'rsa_signature_match';
    public const REASON_NOT_REQUIRED    = 'verification_not_required';
    public const REASON_NO_SIGNATURE    = 'missing_signature';
    public const REASON_INVALID         = 'verification_failed';
    public const REASON_NO_CONFIG       = 'no_verification_configured';

    /**
     * @return array{ok:bool, reason:string, strategy:string}
     */
    public static function authorize( \WP_REST_Request $request, string $raw_body ): array {
        /**
         * Allow integrators to short-circuit verification.
         *
         * Return one of:
         *   - true / array{ok:true, ...}  → request authorized.
         *   - false / array{ok:false, ...} → request rejected.
         *   - null  → fall through to default verification chain.
         *
         * @param mixed             $decision Pre-existing decision (default null).
         * @param \WP_REST_Request  $request  Inbound REST request.
         * @param string            $raw_body Raw request body.
         */
        $decision = apply_filters( 'wbs_webhook_verify_request', null, $request, $raw_body );
        if ( $decision === true ) {
            return [ 'ok' => true, 'reason' => self::REASON_FILTER, 'strategy' => 'filter' ];
        }
        if ( is_array( $decision ) && array_key_exists( 'ok', $decision ) ) {
            return [
                'ok'       => (bool) $decision['ok'],
                'reason'   => isset( $decision['reason'] ) ? (string) $decision['reason'] : ( $decision['ok'] ? self::REASON_FILTER : self::REASON_INVALID ),
                'strategy' => isset( $decision['strategy'] ) ? (string) $decision['strategy'] : 'filter',
            ];
        }
        if ( $decision === false ) {
            return [ 'ok' => false, 'reason' => self::REASON_INVALID, 'strategy' => 'filter' ];
        }

        $shared_secret = Mapping_Config::get_webhook_shared_secret();
        if ( $shared_secret !== '' ) {
            $token = self::extract_shared_secret_token( $request );
            if ( $token !== '' && hash_equals( $shared_secret, $token ) ) {
                return [ 'ok' => true, 'reason' => self::REASON_SHARED_SECRET, 'strategy' => 'shared_secret' ];
            }
        }

        $signature_header = self::header_value( $request, 'x-bol-signature' );
        if ( $signature_header !== '' ) {
            $keys = Mapping_Config::get_webhook_signature_keys();
            if ( $keys !== [] && self::verify_rsa_signature( $raw_body, $signature_header, $keys ) ) {
                return [ 'ok' => true, 'reason' => self::REASON_RSA_SIGNATURE, 'strategy' => 'rsa_signature' ];
            }

            return [ 'ok' => false, 'reason' => self::REASON_INVALID, 'strategy' => 'rsa_signature' ];
        }

        if ( ! Mapping_Config::webhook_signing_required() ) {
            return [ 'ok' => true, 'reason' => self::REASON_NOT_REQUIRED, 'strategy' => 'open' ];
        }

        if ( $shared_secret === '' && Mapping_Config::get_webhook_signature_keys() === [] ) {
            return [ 'ok' => false, 'reason' => self::REASON_NO_CONFIG, 'strategy' => 'none' ];
        }

        return [ 'ok' => false, 'reason' => self::REASON_NO_SIGNATURE, 'strategy' => 'none' ];
    }

    /**
     * @param array<int, array<string, mixed>> $signature_keys
     */
    private static function verify_rsa_signature( string $body, string $signature_b64, array $signature_keys ): bool {
        $signature = base64_decode( $signature_b64, true );
        if ( ! is_string( $signature ) || $signature === '' ) {
            return false;
        }

        if ( ! function_exists( 'openssl_verify' ) ) {
            return false;
        }

        foreach ( $signature_keys as $key_record ) {
            if ( ! is_array( $key_record ) ) {
                continue;
            }
            $pem = self::extract_public_key_pem( $key_record );
            if ( $pem === '' ) {
                continue;
            }

            $public_key = openssl_pkey_get_public( $pem );
            if ( $public_key === false ) {
                continue;
            }

            $verified = openssl_verify( $body, $signature, $public_key, OPENSSL_ALGO_SHA256 );
            if ( $verified === 1 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $key_record
     */
    private static function extract_public_key_pem( array $key_record ): string {
        foreach ( [ 'publicKey', 'public_key', 'key' ] as $field ) {
            if ( isset( $key_record[ $field ] ) && is_string( $key_record[ $field ] ) ) {
                $value = trim( $key_record[ $field ] );
                if ( $value !== '' ) {
                    return self::normalize_pem( $value );
                }
            }
        }
        return '';
    }

    private static function normalize_pem( string $value ): string {
        if ( str_contains( $value, 'BEGIN PUBLIC KEY' ) ) {
            return $value;
        }
        $value = preg_replace( '/\s+/', '', $value ) ?? '';
        if ( $value === '' ) {
            return '';
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $value, 64, "\n" ) . "-----END PUBLIC KEY-----\n";
    }

    private static function header_value( \WP_REST_Request $request, string $name ): string {
        $value = $request->get_header( $name );
        return is_string( $value ) ? trim( $value ) : '';
    }

    private static function extract_shared_secret_token( \WP_REST_Request $request ): string {
        $candidates = [
            self::header_value( $request, 'x-wbs-webhook-token' ),
            self::header_value( $request, 'x-woobol-token' ),
            (string) ( $request->get_param( 'token' ) ?? '' ),
        ];
        foreach ( $candidates as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( $candidate !== '' ) {
                return $candidate;
            }
        }
        return '';
    }
}
