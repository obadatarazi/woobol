<?php
/**
 * License key storage and validation.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * License_Manager
 */
class License_Manager {

    public const KEY_OPTION    = 'wbs_license_key';
    public const STATUS_OPTION = 'wbs_license_status';

    /**
     * @return string
     */
    public static function get_key(): string {
        return (string) get_option( self::KEY_OPTION, '' );
    }

    /**
     * @return bool
     */
    public static function is_active(): bool {
        return 'active' === (string) get_option( self::STATUS_OPTION, 'inactive' );
    }

    /**
     * @param string $key Raw license key.
     */
    private static function validate_key_format( string $key ): bool {
        $key = trim( $key );
        return (bool) preg_match( '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $key );
    }

    /**
     * Persist and optionally validate the license key.
     *
     * @param string $key        License key (may be empty when deactivating).
     * @param bool   $deactivate When true, clear the key and set status inactive.
     * @return array{valid:bool, message:string}
     */
    public static function save_and_validate( string $key, bool $deactivate = false ): array {
        if ( $deactivate ) {
            delete_option( self::KEY_OPTION );
            update_option( self::STATUS_OPTION, 'inactive' );
            delete_transient( 'wbs_license_valid' );
            return [
                'valid'   => true,
                'message' => __( 'License deactivated.', 'woo-bol-sync' ),
            ];
        }

        $key = trim( $key );

        if ( $key === '' ) {
            return [
                'valid'   => false,
                'message' => __( 'Please enter a license key.', 'woo-bol-sync' ),
            ];
        }

        /**
         * Allow remote license checks. Return array{valid:bool, message:string}|null.
         *
         * @param string $key License key.
         */
        $remote = apply_filters( 'wbs_validate_license_response', null, $key );
        if ( is_array( $remote ) && isset( $remote['valid'], $remote['message'] ) ) {
            if ( $remote['valid'] ) {
                update_option( self::KEY_OPTION, sanitize_text_field( $key ) );
                update_option( self::STATUS_OPTION, 'active' );
                set_transient( 'wbs_license_valid', true, DAY_IN_SECONDS );
            } else {
                update_option( self::KEY_OPTION, sanitize_text_field( $key ) );
                update_option( self::STATUS_OPTION, 'invalid' );
            }
            return [
                'valid'   => (bool) $remote['valid'],
                'message' => (string) $remote['message'],
            ];
        }

        if ( self::validate_key_format( $key ) ) {
            update_option( self::KEY_OPTION, sanitize_text_field( $key ) );
            update_option( self::STATUS_OPTION, 'active' );
            set_transient( 'wbs_license_valid', true, DAY_IN_SECONDS );
            return [
                'valid'   => true,
                'message' => __( 'License activated successfully.', 'woo-bol-sync' ),
            ];
        }

        update_option( self::KEY_OPTION, sanitize_text_field( $key ) );
        update_option( self::STATUS_OPTION, 'invalid' );
        delete_transient( 'wbs_license_valid' );

        return [
            'valid'   => false,
            'message' => __( 'Invalid license key format.', 'woo-bol-sync' ),
        ];
    }
}
