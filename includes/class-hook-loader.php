<?php
/**
 * Collects WordPress hook registrations and registers them in one pass.
 *
 * Supports both `add_action` and `add_filter` callbacks bound to method
 * names on plain PHP objects, so the registration order is preserved and
 * the binding is testable.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Hook_Loader
 */
class Hook_Loader {

    /** @var array<int, array{hook:string, component:object, callback:string, priority:int, accepted_args:int}> */
    protected array $actions = [];

    /** @var array<int, array{hook:string, component:object, callback:string, priority:int, accepted_args:int}> */
    protected array $filters = [];

    public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
    }

    public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
    }

    /**
     * Register all queued hooks with WordPress.
     */
    public function run(): void {
        foreach ( $this->actions as $a ) {
            add_action( $a['hook'], [ $a['component'], $a['callback'] ], $a['priority'], $a['accepted_args'] );
        }
        foreach ( $this->filters as $f ) {
            add_filter( $f['hook'], [ $f['component'], $f['callback'] ], $f['priority'], $f['accepted_args'] );
        }
    }
}
