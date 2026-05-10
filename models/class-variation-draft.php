<?php
/**
 * Rows in {prefix}wbs_variation_sync_draft.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Models;

defined( 'ABSPATH' ) || exit;

class Variation_Draft {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . WBS_STAGING_VARIATION_DRAFT;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_by_wc_variation( int $wc_variation_id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_variation_id = %d", $wc_variation_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_for_product_draft( int $product_draft_id ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE product_draft_id = %d ORDER BY id ASC", $product_draft_id ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array{total:int,ready:int,warning:int,blocked:int,synced:int,failed:int}
     */
    public static function summary_for_product_draft( int $product_draft_id ): array {
        $rows = self::get_for_product_draft( $product_draft_id );
        $out  = [
            'total'   => count( $rows ),
            'ready'   => 0,
            'warning' => 0,
            'blocked' => 0,
            'synced'  => 0,
            'failed'  => 0,
        ];
        foreach ( $rows as $row ) {
            $validation = (string) ( $row['validation_status'] ?? '' );
            $sync       = (string) ( $row['sync_status'] ?? '' );
            if ( isset( $out[ $validation ] ) ) {
                ++$out[ $validation ];
            }
            if ( isset( $out[ $sync ] ) ) {
                ++$out[ $sync ];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function upsert_by_wc_variation( int $wc_variation_id, array $data ): int {
        global $wpdb;
        $existing = self::get_by_wc_variation( $wc_variation_id );
        $now      = current_time( 'mysql', true );

        $data['wc_variation_id'] = $wc_variation_id;
        $data['updated_at']      = $now;

        [ $values, $formats ] = self::normalize_columns( $data );

        if ( $existing ) {
            $wpdb->update( self::table(), $values, [ 'id' => (int) $existing['id'] ], $formats, [ '%d' ] );
            return (int) $existing['id'];
        }

        $values['created_at'] = $now;
        $formats[]            = '%s';
        $wpdb->insert( self::table(), $values, $formats );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update( int $id, array $data ): void {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql', true );
        [ $values, $formats ] = self::normalize_columns( $data );
        $wpdb->update( self::table(), $values, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    public static function bump_version( int $id ): void {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET version = version + 1, updated_at = %s WHERE id = %d", current_time( 'mysql', true ), $id ) );
    }

    /**
     * Find variations in draft table that would collide on attributes_signature for the same parent.
     *
     * @return array<string, int>  signature => count (only rows where count > 1)
     */
    public static function duplicate_signatures_for_parent( int $wc_parent_id ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT attributes_signature, COUNT(*) AS c
                 FROM {$table}
                 WHERE wc_parent_id = %d AND attributes_signature <> ''
                 GROUP BY attributes_signature
                 HAVING c > 1",
                $wc_parent_id
            ),
            ARRAY_A
        );
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (string) ( $row['attributes_signature'] ?? '' ) ] = (int) ( $row['c'] ?? 0 );
        }
        return $out;
    }

    /**
     * Count variations that reference the same image URL across different parents.
     */
    public static function count_image_url_usages_across_parents( string $image_url ): int {
        if ( $image_url === '' ) {
            return 0;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT wc_parent_id) FROM {$table} WHERE image_url = %s",
                $image_url
            )
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    private static function normalize_columns( array $data ): array {
        $column_formats = [
            'product_draft_id'          => '%d',
            'wc_variation_id'           => '%d',
            'wc_parent_id'              => '%d',
            'sku'                       => '%s',
            'ean'                       => '%s',
            'attributes_json'           => '%s',
            'attributes_signature'      => '%s',
            'regular_price'             => '%s',
            'sale_price'                => '%s',
            'stock_quantity'            => '%d',
            'stock_status'              => '%s',
            'manage_stock'              => '%d',
            'image_id'                  => '%d',
            'image_url'                 => '%s',
            'description'               => '%s',
            'mapped_payload_json'       => '%s',
            'admin_overrides_json'      => '%s',
            'final_payload_json'        => '%s',
            'validation_status'         => '%s',
            'validation_errors_json'    => '%s',
            'validation_warnings_json'  => '%s',
            'sync_status'               => '%s',
            'last_sync_error'           => '%s',
            'last_synced_at'            => '%s',
            'version'                   => '%d',
            'created_at'                => '%s',
            'updated_at'                => '%s',
        ];

        $values  = [];
        $formats = [];
        foreach ( $data as $col => $val ) {
            if ( ! isset( $column_formats[ $col ] ) ) {
                continue;
            }
            $values[ $col ] = $val;
            $formats[]      = $column_formats[ $col ];
        }
        return [ $values, $formats ];
    }
}
