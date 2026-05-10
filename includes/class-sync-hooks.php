<?php
/**
 * WooCommerce hooks → incremental bol.com product sync.
 *
 * @package WooBolSync
 */

namespace WooBolSync\Includes;

use WooBolSync\Services\Bol_API_Service;
use WooBolSync\Services\Order_Sync_Service;
use WooBolSync\Services\Product_Sync_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Sync_Hooks
 */
final class Sync_Hooks {

    /**
     * @var array<string, string>
     */
    private const CORE_BOL_META_FIELDS = [
        '_wbs_dutch_description' => 'html',
        '_wbs_net_content'       => 'text',
        '_wbs_net_content_value' => 'text',
        '_wbs_net_content_unit'  => 'text',
        '_wbs_net_content_pieces'=> 'text',
        '_wbs_ingredients'       => 'textarea',
        '_wbs_origin_country'    => 'text',
        '_wbs_brand'             => 'text',
    ];

    /**
     * @return void
     */
    public static function register( Bol_API_Service $api ): void {
        $sync = static function ( int $product_id ) use ( $api ): void {
            if ( $product_id <= 0 || wp_installing() ) {
                return;
            }
            if ( ! Mapping_Config::product_sync_enabled() ) {
                return;
            }
            $t = 'wbs_psync_' . $product_id;
            if ( get_transient( $t ) ) {
                return;
            }
            set_transient( $t, 1, 2 );
            ( new Product_Sync_Service( $api ) )->sync_product_by_id( $product_id );
        };

        $sync_order = static function ( int $order_id ) use ( $api ): void {
            if ( $order_id <= 0 || wp_installing() ) {
                return;
            }
            $t = 'wbs_osync_' . $order_id;
            if ( get_transient( $t ) ) {
                return;
            }
            set_transient( $t, 1, 15 );
            ( new Order_Sync_Service( $api ) )->sync_wc_order_status( $order_id );
        };

        add_action(
            'woocommerce_product_set_stock',
            static function ( $product ) use ( $sync ): void {
                if ( $product instanceof \WC_Product ) {
                    $sync( $product->get_id() );
                }
            }
        );

        add_action(
            'woocommerce_variation_set_stock',
            static function ( $variation ) use ( $sync ): void {
                if ( $variation instanceof \WC_Product ) {
                    $sync( $variation->get_id() );
                }
            }
        );

        add_action(
            'woocommerce_product_set_stock_status',
            static function ( $product_id, $stock_status = '', $product = null ) use ( $sync ): void {
                if ( $product instanceof \WC_Product ) {
                    $sync( $product->get_id() );
                    return;
                }
                $sync( (int) $product_id );
            },
            10,
            3
        );

        add_action(
            'save_post_product',
            static function ( int $post_id, \WP_Post $post, bool $update ) use ( $sync ): void {
                if ( ! $update || wp_is_post_revision( $post_id ) ) {
                    return;
                }
                $sync( $post_id );
            },
            10,
            3
        );

        add_action(
            'woocommerce_update_product',
            static function ( int $product_id ) use ( $sync ): void {
                $sync( $product_id );
            }
        );

        add_action(
            'woocommerce_order_status_changed',
            static function ( int $order_id ) use ( $sync_order ): void {
                $sync_order( $order_id );
            },
            20,
            1
        );

        // Removed: bol.com Required Product Data meta box on product edit page
        // add_action( 'add_meta_boxes_product', [ self::class, 'register_core_bol_metabox' ] );
        // add_action( 'woocommerce_process_product_meta', [ self::class, 'save_core_bol_fields' ], 20, 1 );
        // add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_product_editor_assets' ] );
    }

    /**
     * @return void
     */
    public static function register_core_bol_metabox(): void {
        add_meta_box(
            'wbs_core_bol_data',
            __( 'bol.com Required Product Data', 'woo-bol-sync' ),
            [ self::class, 'render_core_bol_metabox' ],
            'product',
            'normal',
            'high'
        );
    }

    /**
     * @return void
     */
    public static function render_core_bol_metabox( \WP_Post $post ): void {
        wp_nonce_field( 'wbs_core_bol_meta_save', 'wbs_core_bol_meta_nonce' );

        $description = (string) get_post_meta( $post->ID, '_wbs_dutch_description', true );
        $net_value = (string) get_post_meta( $post->ID, '_wbs_net_content_value', true );
        $net_unit = (string) get_post_meta( $post->ID, '_wbs_net_content_unit', true );
        $net_pieces = (string) get_post_meta( $post->ID, '_wbs_net_content_pieces', true );
        $net_content = (string) get_post_meta( $post->ID, '_wbs_net_content', true );
        $ingredients = (string) get_post_meta( $post->ID, '_wbs_ingredients', true );
        $origin_country = (string) get_post_meta( $post->ID, '_wbs_origin_country', true );
        $brand_default = Mapping_Config::get_default_brand();
        $product_brand = (string) get_post_meta( $post->ID, '_wbs_brand', true );

        echo '<div class="wbs-product-meta-box">';
        echo '<p class="wbs-product-meta-box__lead"><strong>' . esc_html__( 'Complete these fields for better bol.com validation and fewer sync errors.', 'woo-bol-sync' ) . '</strong></p>';
        echo '<p class="wbs-product-meta-box__examples">' . esc_html__( 'Examples: Net content = 500 g, 1 kg, 10 capsules. Country = NL.', 'woo-bol-sync' ) . '</p>';

        echo '<p><label for="_wbs_brand"><strong>' . esc_html__( 'Brand', 'woo-bol-sync' ) . '</strong></label><br />';
        echo '<input type="text" class="regular-text" id="_wbs_brand" name="_wbs_brand" value="' . esc_attr( $product_brand ) . '" placeholder="' . esc_attr( $brand_default ) . '" /></p>';
        if ( $brand_default !== '' ) {
            echo '<p class="description">' . esc_html(
                sprintf(
                    /* translators: %s: brand name */
                    __( 'Leave empty to use the store default brand: %s', 'woo-bol-sync' ),
                    $brand_default
                )
            ) . '</p>';
        }

        echo '<p><label><strong>' . esc_html__( 'Net content', 'woo-bol-sync' ) . '</strong></label></p>';
        echo '<div class="wbs-net-content-row">';
        echo '<input type="text" class="small-text" id="_wbs_net_content_value" name="_wbs_net_content_value" value="' . esc_attr( $net_value ) . '" placeholder="' . esc_attr__( 'Value', 'woo-bol-sync' ) . '" />';
        echo '<select id="_wbs_net_content_unit" name="_wbs_net_content_unit">';
        foreach ( [ '', 'g', 'kg', 'ml', 'l', 'capsules', 'pieces' ] as $unit ) {
            $label = $unit === '' ? __( 'Unit', 'woo-bol-sync' ) : $unit;
            echo '<option value="' . esc_attr( $unit ) . '"' . selected( $net_unit, $unit, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<input type="text" class="small-text" id="_wbs_net_content_pieces" name="_wbs_net_content_pieces" value="' . esc_attr( $net_pieces ) . '" placeholder="' . esc_attr__( 'Pieces (optional)', 'woo-bol-sync' ) . '" />';
        echo '</div>';
        echo '<p class="description" id="wbs-net-content-preview" data-fallback="' . esc_attr( $net_content ) . '"></p>';
        echo '<input type="hidden" id="_wbs_net_content" name="_wbs_net_content" value="' . esc_attr( $net_content ) . '" />';

        echo '<p><label for="_wbs_origin_country"><strong>' . esc_html__( 'Country of origin', 'woo-bol-sync' ) . '</strong></label><br />';
        echo '<input type="text" class="regular-text" id="_wbs_origin_country" name="_wbs_origin_country" value="' . esc_attr( $origin_country ) . '" maxlength="2" placeholder="NL" /></p>';

        echo '<p><label for="_wbs_ingredients"><strong>' . esc_html__( 'Ingredients', 'woo-bol-sync' ) . '</strong></label><br />';
        echo '<textarea class="widefat" rows="4" id="_wbs_ingredients" name="_wbs_ingredients">' . esc_textarea( $ingredients ) . '</textarea></p>';

        echo '<p><label for="_wbs_dutch_description"><strong>' . esc_html__( 'Dutch description', 'woo-bol-sync' ) . '</strong></label></p>';
        wp_editor(
            $description,
            '_wbs_dutch_description',
            [
                'textarea_name' => '_wbs_dutch_description',
                'textarea_rows' => 8,
                'media_buttons' => false,
                'teeny'         => true,
            ]
        );
        echo '</div>';
    }

    /**
     * @return void
     */
    public static function enqueue_product_editor_assets(): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'product' ) {
            return;
        }

        wp_enqueue_style( 'wbs-admin', WBS_PLUGIN_URL . 'assets/css/admin.css', [], WBS_VERSION );
        wp_enqueue_script( 'wbs-admin', WBS_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], WBS_VERSION, true );
    }

    /**
     * @return void
     */
    public static function save_core_bol_fields( int $product_id ): void {
        if ( ! isset( $_POST['wbs_core_bol_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wbs_core_bol_meta_nonce'] ) ), 'wbs_core_bol_meta_save' ) ) {
            return;
        }

        $normalized_net = self::normalize_net_content(
            isset( $_POST['_wbs_net_content_value'] ) ? (string) wp_unslash( $_POST['_wbs_net_content_value'] ) : '',
            isset( $_POST['_wbs_net_content_unit'] ) ? (string) wp_unslash( $_POST['_wbs_net_content_unit'] ) : '',
            isset( $_POST['_wbs_net_content_pieces'] ) ? (string) wp_unslash( $_POST['_wbs_net_content_pieces'] ) : ''
        );
        update_post_meta( $product_id, '_wbs_net_content', $normalized_net );

        foreach ( self::CORE_BOL_META_FIELDS as $meta_key => $field_type ) {
            if ( ! isset( $_POST[ $meta_key ] ) ) {
                continue;
            }

            $raw = (string) wp_unslash( $_POST[ $meta_key ] );
            if ( $field_type === 'textarea' ) {
                $value = sanitize_textarea_field( $raw );
            } elseif ( $field_type === 'html' ) {
                $value = wp_kses_post( $raw );
            } else {
                $value = sanitize_text_field( $raw );
            }
            update_post_meta( $product_id, $meta_key, $value );
        }
    }

    private static function normalize_net_content( string $value, string $unit, string $pieces ): string {
        $value  = trim( sanitize_text_field( $value ) );
        $unit   = trim( sanitize_text_field( $unit ) );
        $pieces = trim( sanitize_text_field( $pieces ) );

        $base = '';
        if ( $value !== '' && $unit !== '' ) {
            $base = $value . ' ' . $unit;
        } elseif ( $value !== '' ) {
            $base = $value;
        }

        if ( $pieces !== '' ) {
            if ( $base !== '' ) {
                return $base . ' (' . $pieces . ' pieces)';
            }
            return $pieces . ' pieces';
        }

        return $base;
    }
}
