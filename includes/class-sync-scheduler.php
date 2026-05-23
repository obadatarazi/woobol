<?php
/**
 * Configurable WP-Cron schedules for product batch sync and order import.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Product sync: chained single events (daily / weekly / monthly).
 * Order sync: recurring WP-Cron every 5, 10, 15, or 20 minutes.
 */
final class Sync_Scheduler {

    public const OPTION_MIGRATED    = 'wbs_sync_scheduler_v2';
    public const OPTION_MIGRATED_V3 = 'wbs_sync_scheduler_v3';

    private static bool $cron_schedules_registered = false;

    /**
     * Register custom cron intervals for order import.
     */
    public static function register_hooks(): void {
        if ( self::$cron_schedules_registered ) {
            return;
        }
        self::$cron_schedules_registered = true;
        add_filter( 'cron_schedules', [ self::class, 'filter_cron_schedules' ] );
    }

    /**
     * @param array<string, array{interval:int,display:string}> $schedules
     * @return array<string, array{interval:int,display:string}>
     */
    public static function filter_cron_schedules( array $schedules ): array {
        foreach ( Mapping_Config::ORDER_SYNC_INTERVAL_CHOICES as $minutes ) {
            $key = self::order_cron_schedule_name( $minutes );
            $schedules[ $key ] = [
                'interval' => $minutes * MINUTE_IN_SECONDS,
                /* translators: %d: number of minutes between order import runs */
                'display'  => sprintf(
                    __( 'Every %d minutes (WooBolSync orders)', 'woo-bol-sync' ),
                    $minutes
                ),
            ];
        }
        return $schedules;
    }

    /**
     * One-time migration: clear legacy hourly recurring events and apply saved modes.
     */
    public static function bootstrap_migration(): void {
        self::register_hooks();

        if ( (string) get_option( self::OPTION_MIGRATED, '' ) !== '1' ) {
            wp_clear_scheduled_hook( 'wbs_cron_sync_products' );
            wp_clear_scheduled_hook( 'wbs_cron_sync_orders' );
            self::apply_product_schedule();
            update_option( self::OPTION_MIGRATED, '1' );
        }

        self::bootstrap_order_interval_migration();
    }

    /**
     * Migrate order import from daily/weekly/monthly single events to recurring interval cron.
     */
    public static function bootstrap_order_interval_migration(): void {
        if ( (string) get_option( self::OPTION_MIGRATED_V3, '' ) === '1' ) {
            return;
        }

        wp_clear_scheduled_hook( 'wbs_cron_sync_orders' );

        if ( false === get_option( Mapping_Config::OPTION_ORDER_SYNC_INTERVAL, false ) ) {
            add_option(
                Mapping_Config::OPTION_ORDER_SYNC_INTERVAL,
                Mapping_Config::DEFAULT_ORDER_SYNC_INTERVAL_MINUTES
            );
        }

        self::apply_order_schedule();
        update_option( self::OPTION_MIGRATED_V3, '1' );
    }

    /**
     * Clear and schedule next product batch run (or none if WooCommerce-updates-only mode).
     */
    public static function apply_product_schedule(): void {
        wp_clear_scheduled_hook( 'wbs_cron_sync_products' );
        if ( ! Mapping_Config::product_sync_enabled() ) {
            return;
        }
        $mode = Mapping_Config::get_product_sync_mode();
        if ( $mode === Mapping_Config::SYNC_MODE_WC_UPDATES ) {
            return;
        }
        $next = self::compute_next_timestamp(
            $mode,
            Mapping_Config::get_product_sync_time(),
            Mapping_Config::get_product_sync_weekday(),
            Mapping_Config::get_product_sync_monthday()
        );
        if ( $next !== false ) {
            if ( $next <= time() ) {
                $next = time() + 60;
            }
            wp_schedule_single_event( $next, 'wbs_cron_sync_products' );
        }
    }

    /**
     * Clear and schedule recurring order import (5 / 10 / 15 / 20 minutes).
     */
    public static function apply_order_schedule(): void {
        self::register_hooks();
        wp_clear_scheduled_hook( 'wbs_cron_sync_orders' );

        $minutes  = Mapping_Config::get_order_sync_interval_minutes();
        $schedule = self::order_cron_schedule_name( $minutes );

        wp_schedule_event( time() + 60, $schedule, 'wbs_cron_sync_orders' );
    }

    /**
     * After a product cron run, queue the following occurrence.
     */
    public static function chain_next_product_run(): void {
        if ( ! Mapping_Config::product_sync_enabled() ) {
            return;
        }
        if ( Mapping_Config::get_product_sync_mode() === Mapping_Config::SYNC_MODE_WC_UPDATES ) {
            return;
        }
        self::apply_product_schedule();
    }

    /**
     * Recurring order cron self-repeats; retained for backward compatibility.
     */
    public static function chain_next_order_run(): void {
        // No-op: order import uses wp_schedule_event.
    }

    /**
     * Whether enough time has passed since the last order import.
     */
    public static function can_run_order_sync(): bool {
        $last = Mapping_Config::get_order_sync_last_run();
        if ( $last <= 0 ) {
            return true;
        }
        return ( time() - $last ) >= Mapping_Config::get_order_sync_interval_seconds();
    }

    /**
     * Seconds until the next order import is allowed (0 if allowed now).
     */
    public static function seconds_until_order_sync_allowed(): int {
        if ( self::can_run_order_sync() ) {
            return 0;
        }
        $elapsed = time() - Mapping_Config::get_order_sync_last_run();
        return max( 0, Mapping_Config::get_order_sync_interval_seconds() - $elapsed );
    }

    public static function order_cron_schedule_name( int $minutes ): string {
        $minutes = Mapping_Config::sanitize_order_sync_interval_minutes( $minutes );
        return 'wbs_every_' . $minutes . '_minutes';
    }

    /**
     * @param string $mode    daily|weekly|monthly
     * @param string $time_hm H:i (24h)
     * @param int    $weekday 0 (Sun) … 6 (Sat)
     * @param int    $monthday 1–28
     * @return int|false Unix timestamp in UTC for wp-cron
     */
    private static function compute_next_timestamp( string $mode, string $time_hm, int $weekday, int $monthday ) {
        $mode = sanitize_key( $mode );
        if ( ! in_array( $mode, [ Mapping_Config::SYNC_MODE_DAILY, Mapping_Config::SYNC_MODE_WEEKLY, Mapping_Config::SYNC_MODE_MONTHLY ], true ) ) {
            return false;
        }

        $tz = wp_timezone();
        try {
            $now = new \DateTimeImmutable( 'now', $tz );
        } catch ( \Exception $e ) {
            return false;
        }

        [ $hour, $minute ] = self::parse_time_hm( $time_hm );

        if ( $mode === Mapping_Config::SYNC_MODE_DAILY ) {
            $candidate = $now->setTime( $hour, $minute, 0 );
            if ( $candidate <= $now ) {
                $candidate = $candidate->modify( '+1 day' );
            }
            return $candidate->getTimestamp();
        }

        if ( $mode === Mapping_Config::SYNC_MODE_WEEKLY ) {
            $weekday = min( 6, max( 0, $weekday ) );
            $cur     = (int) $now->format( 'w' );
            $ahead   = ( $weekday - $cur + 7 ) % 7;
            $candidate = $now->modify( '+' . $ahead . ' days' )->setTime( $hour, $minute, 0 );
            if ( $candidate <= $now ) {
                $candidate = $candidate->modify( '+7 days' );
            }
            return $candidate->getTimestamp();
        }

        // monthly
        $monthday = min( 28, max( 1, $monthday ) );
        $first    = $now->modify( 'first day of this month' )->setTime( $hour, $minute, 0 );
        $dim      = (int) $first->format( 't' );
        $day      = min( $monthday, $dim );
        $candidate = $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day );
        if ( $candidate <= $now ) {
            $next_month = $first->modify( 'first day of next month' );
            $dim2       = (int) $next_month->format( 't' );
            $day2       = min( $monthday, $dim2 );
            $candidate  = $next_month->setDate( (int) $next_month->format( 'Y' ), (int) $next_month->format( 'n' ), $day2 )->setTime( $hour, $minute, 0 );
        }

        return $candidate->getTimestamp();
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function parse_time_hm( string $time_hm ): array {
        $time_hm = trim( $time_hm );
        if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $time_hm, $m ) === 1 ) {
            $h = min( 23, max( 0, (int) $m[1] ) );
            $i = min( 59, max( 0, (int) $m[2] ) );
            return [ $h, $i ];
        }
        return [ 2, 0 ];
    }
}
