<?php
/**
 * Extends PHP runtime limits for long admin-ajax handlers (sync, ingest).
 *
 * Reverse proxies (nginx, Cloudflare, LiteSpeed) may still close the connection
 * before PHP finishes; raise those timeouts on the server if errors persist.
 *
 * @package WooBolSync\Includes
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

final class Ajax_Runtime {

    /**
     * Allow sync-style AJAX requests to run longer than the default PHP limit.
     *
     * Filter `wbs_ajax_long_request_time_limit`:
     * - Positive int: seconds for set_time_limit().
     * - `0`: no limit (calls set_time_limit(0) when allowed by the host).
     *
     * @return void
     */
    public static function prepare_long_request(): void {
        if ( function_exists( 'ignore_user_abort' ) ) {
            ignore_user_abort( true );
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $limit = (int) apply_filters( 'wbs_ajax_long_request_time_limit', 600 );

        if ( ! function_exists( 'set_time_limit' ) ) {
            return;
        }

        if ( $limit === 0 ) {
            @set_time_limit( 0 );
            return;
        }

        @set_time_limit( max( 120, $limit ) );
    }
}
