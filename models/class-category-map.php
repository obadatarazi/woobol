<?php
/**
 * Rows in {prefix}wbs_category_mapping.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Models;

defined( 'ABSPATH' ) || exit;

class Category_Map {

    /**
     * @return array<int, string>
     */
    public static function get_all(): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_CATEGORY_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT wc_term_id, bol_category_id FROM {$table}", ARRAY_A ) ?: [];

        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['wc_term_id'] ] = (string) $row['bol_category_id'];
        }

        return $out;
    }

    public static function upsert( int $wc_term_id, string $bol_category_id ): void {
        self::upsert_with_template( $wc_term_id, $bol_category_id, [] );
    }

    /**
     * @param array<int, array{id:string, value:string}> $template_attributes
     */
    public static function upsert_with_template( int $wc_term_id, string $bol_category_id, array $template_attributes ): void {
        global $wpdb;

        $table = $wpdb->prefix . WBS_CATEGORY_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wc_term_id = %d", $wc_term_id ) );
        $template = wp_json_encode( array_values( $template_attributes ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $template ) ) {
            $template = '[]';
        }

        $data = [
            'wc_term_id'       => $wc_term_id,
            'bol_category_id'  => $bol_category_id,
            'template'         => $template,
            'updated_at'       => current_time( 'mysql', true ),
        ];

        if ( $existing ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->update(
                $table,
                [
                    'bol_category_id' => $bol_category_id,
                    'template'        => $template,
                    'updated_at'      => current_time( 'mysql', true ),
                ],
                [ 'wc_term_id' => $wc_term_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->insert(
            $table,
            $data,
            [ '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * @return array<int, array{id:string, value:string}>
     */
    public static function get_template_attributes( int $wc_term_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . WBS_CATEGORY_MAP;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT template FROM {$table} WHERE wc_term_id = %d LIMIT 1", $wc_term_id ) );
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $out = [];
        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $id    = isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '';
            $value = isset( $item['value'] ) ? sanitize_text_field( (string) $item['value'] ) : '';
            if ( $id === '' || $value === '' ) {
                continue;
            }
            $out[] = [
                'id'    => $id,
                'value' => $value,
            ];
        }
        return $out;
    }

    /**
     * WC `product_cat` term id whose bol mapping row supplies the category id for this product.
     *
     * Walk order matches Mapping_Config::get_bol_category_id_for_product: each assigned term,
     * then its ancestors; first row with non-empty bol_category_id wins.
     *
     * @return int 0 if none.
     */
    public static function resolve_mapped_wc_term_id_for_product( \WC_Product $product ): int {
        $pid = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();

        $terms = get_the_terms( $pid, 'product_cat' );
        if ( ! is_array( $terms ) || $terms === [] ) {
            return 0;
        }

        $map = self::get_all();
        foreach ( $terms as $term ) {
            $tid = (int) $term->term_id;
            if ( isset( $map[ $tid ] ) && trim( (string) $map[ $tid ] ) !== '' ) {
                return $tid;
            }
            $ancestors = get_ancestors( $tid, 'product_cat', 'taxonomy' );
            foreach ( $ancestors as $aid ) {
                $aid = (int) $aid;
                if ( isset( $map[ $aid ] ) && trim( (string) $map[ $aid ] ) !== '' ) {
                    return $aid;
                }
            }
        }

        return 0;
    }

    /**
     * @return array<int, array{id:string, values:array<int, array{value:string}>}>
     */
    public static function get_template_attributes_for_product( \WC_Product $product ): array {
        $tid = self::resolve_mapped_wc_term_id_for_product( $product );
        if ( $tid <= 0 ) {
            return [];
        }

        $template = self::get_template_attributes( $tid );
        if ( $template === [] ) {
            return [];
        }

        return array_map(
            static fn( array $item ): array => [
                'id'     => $item['id'],
                'values' => [ [ 'value' => $item['value'] ] ],
            ],
            $template
        );
    }
}
