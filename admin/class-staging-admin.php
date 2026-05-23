<?php
/**
 * Admin Staging screen: review-before-sync drafts.
 *
 * Adds a submenu under Bol Sync that lets admins inspect generated drafts,
 * edit product/variation fields and images, (re)validate, approve, and queue
 * sync jobs. All persistence flows through the staging models.
 *
 * @package WooBolSync\Admin
 */

namespace WooBolSync\Admin;

use WooBolSync\Includes\Ajax_Runtime;
use WooBolSync\Includes\Hook_Loader;
use WooBolSync\Includes\Logger;
use WooBolSync\Includes\Mapping_Config;
use WooBolSync\Models\Product_Draft;
use WooBolSync\Models\Sync_Audit;
use WooBolSync\Models\Sync_Batch;
use WooBolSync\Models\Sync_Job;
use WooBolSync\Models\Variation_Draft;
use WooBolSync\Services\Bol_API_Service;
use WooBolSync\Services\Draft_Builder_Service;
use WooBolSync\Services\Draft_Validator_Service;
use WooBolSync\Services\Staging_Sync_Service;

defined( 'ABSPATH' ) || exit;

class Staging_Admin {

    public const SLUG = 'wbs-staging';

    private Bol_API_Service $api;

    /**
     * @var string[] Whitelisted editable product draft fields.
     */
    private const EDITABLE_PRODUCT_FIELDS = [
        'name',
        'ean',
        'sku',
        'short_description',
        'description',
        'regular_price',
        'sale_price',
        'stock_quantity',
        'stock_status',
        'manage_stock',
        'main_image_id',
        'main_image_url',
        'gallery_json',
        'sync_price',
        'sync_stock',
        'sync_content',
        'sync_images',
    ];

    /**
     * @var string[] Whitelisted editable variation fields.
     */
    private const EDITABLE_VARIATION_FIELDS = [
        'sku',
        'ean',
        'attributes_json',
        'regular_price',
        'sale_price',
        'stock_quantity',
        'stock_status',
        'manage_stock',
        'image_id',
        'image_url',
        'description',
    ];

    public function __construct( Bol_API_Service $api ) {
        $this->api = $api;
    }

    public function register( Hook_Loader $loader ): void {
        $loader->add_action( 'admin_menu', $this, 'register_menu', 20 );
        $loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets' );

        $loader->add_action( 'wp_ajax_wbs_staging_ingest',        $this, 'ajax_ingest' );
        $loader->add_action( 'wp_ajax_wbs_staging_list',          $this, 'ajax_list' );
        $loader->add_action( 'wp_ajax_wbs_staging_get',           $this, 'ajax_get' );
        $loader->add_action( 'wp_ajax_wbs_staging_save',          $this, 'ajax_save' );
        $loader->add_action( 'wp_ajax_wbs_staging_save_variation', $this, 'ajax_save_variation' );
        $loader->add_action( 'wp_ajax_wbs_staging_validate',      $this, 'ajax_validate' );
        $loader->add_action( 'wp_ajax_wbs_staging_approve',       $this, 'ajax_approve' );
        $loader->add_action( 'wp_ajax_wbs_staging_reject',        $this, 'ajax_reject' );
        $loader->add_action( 'wp_ajax_wbs_staging_sync',          $this, 'ajax_sync' );
    }

    public function register_menu(): void {
        add_submenu_page(
            'wbs-dashboard',
            __( 'Staging & Review', 'woo-bol-sync' ),
            __( 'Staging & Review', 'woo-bol-sync' ),
            'manage_woocommerce',
            self::SLUG,
            [ $this, 'render' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'bol-sync_page_' . self::SLUG ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style( 'wbs-staging', WBS_PLUGIN_URL . 'assets/css/staging.css', [], WBS_VERSION );
        wp_enqueue_script( 'wbs-staging', WBS_PLUGIN_URL . 'assets/js/staging.js', [ 'jquery', 'wp-i18n' ], WBS_VERSION, true );
        wp_localize_script(
            'wbs-staging',
            'wbsStaging',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wbs_staging_nonce' ),
                'i18n'    => [
                    'loading'          => __( 'Loading…', 'woo-bol-sync' ),
                    'saving'           => __( 'Saving…', 'woo-bol-sync' ),
                    'saved'            => __( 'Saved.', 'woo-bol-sync' ),
                    'approving'        => __( 'Approving…', 'woo-bol-sync' ),
                    'syncing'          => __( 'Syncing approved drafts…', 'woo-bol-sync' ),
                    'ingesting'        => __( 'Ingesting products into drafts…', 'woo-bol-sync' ),
                    'validating'       => __( 'Validating…', 'woo-bol-sync' ),
                    'error'            => __( 'An error occurred.', 'woo-bol-sync' ),
                    'confirmApprove'   => __( 'Approve this draft for sync?', 'woo-bol-sync' ),
                    'confirmSync'      => __( 'Sync all approved drafts now?', 'woo-bol-sync' ),
                    'confirmSyncSelected' => __( 'Sync selected drafts now?', 'woo-bol-sync' ),
                    'noSelectedDrafts' => __( 'Select one or more drafts first.', 'woo-bol-sync' ),
                    'chooseImage'      => __( 'Select image', 'woo-bol-sync' ),
                    'useImage'         => __( 'Use this image', 'woo-bol-sync' ),
                    'noDraftSelected'  => __( 'Select a draft to edit.', 'woo-bol-sync' ),
                    'noVariations'     => __( 'No variations.', 'woo-bol-sync' ),
                    'variations'       => __( 'Variations', 'woo-bol-sync' ),
                    'images'           => __( 'Images', 'woo-bol-sync' ),
                    'addGalleryImage'  => __( 'Add gallery image', 'woo-bol-sync' ),
                    'removeGalleryImage' => __( 'Remove', 'woo-bol-sync' ),
                    'mainImage'        => __( 'Main image', 'woo-bol-sync' ),
                ],
            ]
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'woo-bol-sync' ) );
        }
        $counts = Product_Draft::count_by_status();
        $batches = Sync_Batch::recent( 10 );
        $jobs    = Sync_Job::recent( 10 );
        $field_map = Mapping_Config::get_field_map();

        $view = WBS_PLUGIN_DIR . 'views/admin/staging.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    public function ajax_ingest(): void {
        $this->verify();
        Ajax_Runtime::prepare_long_request();
        $ids_raw = isset( $_POST['product_ids'] ) ? (string) wp_unslash( $_POST['product_ids'] ) : '';
        $ids     = array_values( array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) ) );

        $builder = new Draft_Builder_Service();
        $result  = $builder->ingest( $ids );

        wp_send_json_success(
            [
                'message' => sprintf(
                    /* translators: 1: batch id, 2: products, 3: variations, 4: errors, 5: skipped excluded */
                    __( 'Ingested batch #%1$d — products: %2$d, variations: %3$d, errors: %4$d, skipped (excluded categories): %5$d', 'woo-bol-sync' ),
                    $result['batch_id'],
                    $result['products'],
                    $result['variations'],
                    $result['errors'],
                    $result['skipped_excluded'] ?? 0
                ),
                'result'  => $result,
                'counts'  => Product_Draft::count_by_status(),
            ]
        );
    }

    public function ajax_list(): void {
        $this->verify();
        $include_variations = ! empty( $_POST['include_variations'] ) && (int) wp_unslash( $_POST['include_variations'] ) === 1;
        $filters = [
            'batch_id'          => isset( $_POST['batch_id'] ) ? absint( wp_unslash( $_POST['batch_id'] ) ) : 0,
            'validation_status' => isset( $_POST['validation_status'] ) ? sanitize_key( wp_unslash( $_POST['validation_status'] ) ) : '',
            'review_status'     => isset( $_POST['review_status'] ) ? sanitize_key( wp_unslash( $_POST['review_status'] ) ) : '',
            'sync_status'       => isset( $_POST['sync_status'] ) ? sanitize_key( wp_unslash( $_POST['sync_status'] ) ) : '',
            'search'            => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
        ];
        $limit  = isset( $_POST['limit'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 50;
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( wp_unslash( $_POST['offset'] ) ) ) : 0;

        $rows = Product_Draft::search( $filters, $limit, $offset );
        $items = array_map( [ $this, 'public_draft_row' ], $rows );
        if ( $include_variations ) {
            $expanded = [];
            foreach ( $rows as $row ) {
                $parent = $this->public_draft_row( $row );
                $variations = Variation_Draft::get_for_product_draft( (int) $row['id'] );

                if ( ! empty( $variations ) ) {
                    // For variable products, list child rows only (hide parent row).
                    foreach ( $variations as $variation ) {
                        $expanded[] = $this->public_variation_table_row( $variation, $parent );
                    }
                } else {
                    // Keep non-variable/simple products visible.
                    $expanded[] = $parent;
                }
            }
            $items = $expanded;
            $counts = $this->table_counts_from_rows( $items );
        } else {
            $counts = Product_Draft::count_by_status();
        }
        wp_send_json_success(
            [
                'items'  => $items,
                'counts' => $counts,
            ]
        );
    }

    public function ajax_get(): void {
        $this->verify();
        $id    = isset( $_POST['draft_id'] ) ? absint( wp_unslash( $_POST['draft_id'] ) ) : 0;
        $draft = Product_Draft::get( $id );
        if ( ! $draft ) {
            wp_send_json_error( [ 'message' => __( 'Draft not found.', 'woo-bol-sync' ) ], 404 );
        }

        $variations = Variation_Draft::get_for_product_draft( (int) $draft['id'] );
        $audit      = Sync_Audit::recent_for_entity( Sync_Audit::ENTITY_PRODUCT_DRAFT, (int) $draft['id'], 20 );

        wp_send_json_success(
            [
                'product'    => $this->public_draft_row( $draft, true ),
                'variations' => array_map( [ $this, 'public_variation_row' ], $variations ),
                'audit'      => $audit,
            ]
        );
    }

    public function ajax_save(): void {
        $this->verify();
        $id    = isset( $_POST['draft_id'] ) ? absint( wp_unslash( $_POST['draft_id'] ) ) : 0;
        $draft = Product_Draft::get( $id );
        if ( ! $draft ) {
            wp_send_json_error( [ 'message' => __( 'Draft not found.', 'woo-bol-sync' ) ], 404 );
        }

        $payload = $this->sanitize_product_payload( (array) ( $_POST['fields'] ?? [] ) );
        if ( $payload === [] ) {
            wp_send_json_error( [ 'message' => __( 'Nothing to save.', 'woo-bol-sync' ) ], 400 );
        }

        $overrides = $this->decode_json( (string) ( $draft['admin_overrides_json'] ?? '' ) );
        foreach ( $payload as $key => $value ) {
            $overrides[ $key ] = $value;
        }

        Sync_Audit::log_diff(
            Sync_Audit::ENTITY_PRODUCT_DRAFT,
            $id,
            $draft,
            $payload,
            array_keys( $payload ),
            0,
            'admin_edit'
        );

        $updates = $payload;
        $updates['admin_overrides_json'] = $this->encode_json( $overrides );
        Product_Draft::update( $id, $updates );
        Product_Draft::bump_version( $id );

        $validator = new Draft_Validator_Service();
        $status    = $validator->revalidate_product_draft( $id );

        wp_send_json_success(
            [
                'status'  => $status,
                'product' => $this->public_draft_row( Product_Draft::get( $id ), true ),
            ]
        );
    }

    public function ajax_save_variation(): void {
        $this->verify();
        $id = isset( $_POST['variation_draft_id'] ) ? absint( wp_unslash( $_POST['variation_draft_id'] ) ) : 0;
        $variation = Variation_Draft::get( $id );
        if ( ! $variation ) {
            wp_send_json_error( [ 'message' => __( 'Variation not found.', 'woo-bol-sync' ) ], 404 );
        }

        $payload = $this->sanitize_variation_payload( (array) ( $_POST['fields'] ?? [] ) );
        if ( $payload === [] ) {
            wp_send_json_error( [ 'message' => __( 'Nothing to save.', 'woo-bol-sync' ) ], 400 );
        }

        $overrides = $this->decode_json( (string) ( $variation['admin_overrides_json'] ?? '' ) );
        foreach ( $payload as $key => $value ) {
            $overrides[ $key ] = $value;
        }

        Sync_Audit::log_diff(
            Sync_Audit::ENTITY_VARIATION_DRAFT,
            $id,
            $variation,
            $payload,
            array_keys( $payload ),
            0,
            'admin_edit'
        );

        $updates = $payload;
        $updates['admin_overrides_json'] = $this->encode_json( $overrides );

        if ( isset( $payload['attributes_json'] ) ) {
            $attrs_decoded = $this->decode_json( (string) $payload['attributes_json'] );
            $updates['attributes_signature'] = $this->signature_from_attributes( $attrs_decoded );
        }

        Variation_Draft::update( $id, $updates );
        Variation_Draft::bump_version( $id );

        // Keep WooCommerce variation image in sync when admin selects one.
        $new_image_id = isset( $updates['image_id'] ) ? (int) $updates['image_id'] : 0;
        if ( $new_image_id > 0 ) {
            $wc_variation_id = (int) $variation['wc_variation_id'];
            $wc_variation = wc_get_product( $wc_variation_id );
            if ( $wc_variation instanceof \WC_Product_Variation ) {
                $wc_variation->set_image_id( $new_image_id );
                $wc_variation->save();
            }
        }

        // Revalidate parent product draft (covers duplicate-signature detection across set).
        $parent_id = (int) $variation['product_draft_id'];
        if ( $parent_id > 0 ) {
            ( new Draft_Validator_Service() )->revalidate_product_draft( $parent_id );
        }

        wp_send_json_success(
            [
                'variation' => $this->public_variation_row( Variation_Draft::get( $id ) ),
            ]
        );
    }

    public function ajax_validate(): void {
        $this->verify();
        $id = isset( $_POST['draft_id'] ) ? absint( wp_unslash( $_POST['draft_id'] ) ) : 0;
        if ( ! Product_Draft::get( $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Draft not found.', 'woo-bol-sync' ) ], 404 );
        }
        $status = ( new Draft_Validator_Service() )->revalidate_product_draft( $id );

        wp_send_json_success(
            [
                'status'  => $status,
                'product' => $this->public_draft_row( Product_Draft::get( $id ), true ),
            ]
        );
    }

    public function ajax_approve(): void {
        $this->verify();
        $id = isset( $_POST['draft_id'] ) ? absint( wp_unslash( $_POST['draft_id'] ) ) : 0;
        $draft = Product_Draft::get( $id );
        if ( ! $draft ) {
            wp_send_json_error( [ 'message' => __( 'Draft not found.', 'woo-bol-sync' ) ], 404 );
        }
        if ( $draft['validation_status'] === Product_Draft::VALIDATION_BLOCKED ) {
            wp_send_json_error( [ 'message' => __( 'Cannot approve a blocked draft. Fix validation errors first.', 'woo-bol-sync' ) ], 409 );
        }

        Product_Draft::update(
            $id,
            [
                'review_status' => Product_Draft::REVIEW_APPROVED,
                'approved_by'   => (int) get_current_user_id(),
                'approved_at'   => current_time( 'mysql', true ),
            ]
        );
        Sync_Audit::log_change(
            Sync_Audit::ENTITY_PRODUCT_DRAFT,
            $id,
            'review_status',
            $draft['review_status'],
            Product_Draft::REVIEW_APPROVED,
            0,
            'approved_for_sync'
        );

        wp_send_json_success( [ 'product' => $this->public_draft_row( Product_Draft::get( $id ), true ) ] );
    }

    public function ajax_reject(): void {
        $this->verify();
        $id = isset( $_POST['draft_id'] ) ? absint( wp_unslash( $_POST['draft_id'] ) ) : 0;
        $draft = Product_Draft::get( $id );
        if ( ! $draft ) {
            wp_send_json_error( [ 'message' => __( 'Draft not found.', 'woo-bol-sync' ) ], 404 );
        }
        Product_Draft::update(
            $id,
            [
                'review_status' => Product_Draft::REVIEW_REJECTED,
            ]
        );
        Sync_Audit::log_change(
            Sync_Audit::ENTITY_PRODUCT_DRAFT,
            $id,
            'review_status',
            $draft['review_status'],
            Product_Draft::REVIEW_REJECTED,
            0,
            'rejected'
        );
        wp_send_json_success( [ 'product' => $this->public_draft_row( Product_Draft::get( $id ), true ) ] );
    }

    public function ajax_sync(): void {
        $this->verify();
        Ajax_Runtime::prepare_long_request();
        $batch_id = isset( $_POST['batch_id'] ) ? absint( wp_unslash( $_POST['batch_id'] ) ) : 0;
        $limit    = isset( $_POST['limit'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 25;
        $draft_ids_raw = isset( $_POST['draft_ids'] ) ? (string) wp_unslash( $_POST['draft_ids'] ) : '';
        $draft_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $draft_ids_raw ) ) ) ) );
        $variation_ids_raw = isset( $_POST['variation_draft_ids'] ) ? (string) wp_unslash( $_POST['variation_draft_ids'] ) : '';
        $variation_draft_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $variation_ids_raw ) ) ) ) );

        $service = new Staging_Sync_Service( $this->api );
        if ( $draft_ids !== [] || $variation_draft_ids !== [] ) {
            $result = $service->sync_selected( $draft_ids, $variation_draft_ids, $batch_id );
        } else {
            $result = $service->sync_approved( $limit, $batch_id );
        }

        $first_error = '';
        if ( ! empty( $result['job_id'] ) ) {
            $items = Sync_Job::items_for_job( (int) $result['job_id'] );
            foreach ( $items as $item ) {
                if ( (string) ( $item['status'] ?? '' ) !== Sync_Job::ITEM_FAILED ) {
                    continue;
                }
                $candidate = trim( (string) ( $item['error_message'] ?? '' ) );
                if ( $candidate !== '' ) {
                    $first_error = $candidate;
                    break;
                }
            }
        }

        $message = sprintf(
            /* translators: 1: success count, 2: failed count, 3: skipped count */
            __( 'Sync complete — success: %1$d, failed: %2$d, skipped: %3$d.', 'woo-bol-sync' ),
            $result['success'],
            $result['failed'],
            $result['skipped']
        );
        if ( $first_error !== '' ) {
            $message .= ' ' . sprintf(
                /* translators: %s: first sync failure message */
                __( 'First error: %s', 'woo-bol-sync' ),
                $first_error
            );
        }
        if ( ! Mapping_Config::staging_sync_enabled() ) {
            $message .= ' ' . __( 'Staging sync is currently blocked by the "Allow staging sync to bol.com" setting.', 'woo-bol-sync' );
        }

        wp_send_json_success(
            [
                'message' => $message,
                'result'  => $result,
                'first_error' => $first_error,
                'counts'  => Product_Draft::count_by_status(),
            ]
        );
    }

    // ── Sanitizers ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitize_product_payload( array $raw ): array {
        $out = [];
        foreach ( self::EDITABLE_PRODUCT_FIELDS as $field ) {
            if ( ! array_key_exists( $field, $raw ) ) {
                continue;
            }
            $value = wp_unslash( $raw[ $field ] );

            switch ( $field ) {
                case 'regular_price':
                case 'sale_price':
                    $out[ $field ] = $value === '' || $value === null ? null : (string) (float) $value;
                    break;
                case 'stock_quantity':
                    $out[ $field ] = $value === '' || $value === null ? null : (int) $value;
                    break;
                case 'manage_stock':
                    $out[ $field ] = $value ? 1 : 0;
                    break;
                case 'main_image_id':
                    $out[ $field ] = (int) $value;
                    break;
                case 'sync_price':
                case 'sync_stock':
                case 'sync_content':
                case 'sync_images':
                    $out[ $field ] = $value ? 1 : 0;
                    break;
                case 'main_image_url':
                    $out[ $field ] = esc_url_raw( (string) $value );
                    break;
                case 'description':
                case 'short_description':
                    $out[ $field ] = wp_kses_post( (string) $value );
                    break;
                case 'gallery_json':
                    $out[ $field ] = $this->sanitize_gallery_json( (string) $value );
                    break;
                default:
                    $out[ $field ] = sanitize_text_field( (string) $value );
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitize_variation_payload( array $raw ): array {
        $out = [];
        foreach ( self::EDITABLE_VARIATION_FIELDS as $field ) {
            if ( ! array_key_exists( $field, $raw ) ) {
                continue;
            }
            $value = wp_unslash( $raw[ $field ] );

            switch ( $field ) {
                case 'regular_price':
                case 'sale_price':
                    $out[ $field ] = $value === '' || $value === null ? null : (string) (float) $value;
                    break;
                case 'stock_quantity':
                    $out[ $field ] = $value === '' || $value === null ? null : (int) $value;
                    break;
                case 'manage_stock':
                    $out[ $field ] = $value ? 1 : 0;
                    break;
                case 'image_id':
                    $out[ $field ] = (int) $value;
                    break;
                case 'image_url':
                    $out[ $field ] = esc_url_raw( (string) $value );
                    break;
                case 'description':
                    $out[ $field ] = wp_kses_post( (string) $value );
                    break;
                case 'attributes_json':
                    $out[ $field ] = $this->sanitize_attributes_json( (string) $value );
                    break;
                default:
                    $out[ $field ] = sanitize_text_field( (string) $value );
            }
        }
        return $out;
    }

    private function sanitize_gallery_json( string $raw ): string {
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return '[]';
        }
        $clean = [];
        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $clean[] = [
                'id'  => isset( $item['id'] ) ? (int) $item['id'] : 0,
                'url' => isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '',
                'alt' => isset( $item['alt'] ) ? sanitize_text_field( (string) $item['alt'] ) : '',
            ];
        }
        return $this->encode_json( $clean );
    }

    private function sanitize_attributes_json( string $raw ): string {
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return '{}';
        }
        $clean = [];
        foreach ( $decoded as $key => $value ) {
            if ( ! is_scalar( $value ) ) {
                continue;
            }
            $ck = sanitize_key( (string) $key );
            $cv = trim( (string) $value );
            if ( $ck === '' || $cv === '' ) {
                continue;
            }
            $clean[ $ck ] = $cv;
        }
        ksort( $clean );
        return $this->encode_json( $clean );
    }

    /**
     * @param array<string, string> $attributes
     */
    private function signature_from_attributes( array $attributes ): string {
        if ( $attributes === [] ) {
            return '';
        }
        ksort( $attributes );
        $parts = [];
        foreach ( $attributes as $k => $v ) {
            $parts[] = $k . '=' . strtolower( (string) $v );
        }
        return mb_substr( implode( '|', $parts ), 0, 191 );
    }

    // ── Presenter helpers ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function public_draft_row( array $draft, bool $include_payload = false ): array {
        $row = [
            'id'                       => (int) $draft['id'],
            'batch_id'                 => (int) $draft['batch_id'],
            'wc_product_id'            => (int) $draft['wc_product_id'],
            'product_type'             => (string) $draft['product_type'],
            'name'                     => (string) $draft['name'],
            'sku'                      => (string) $draft['sku'],
            'ean'                      => (string) $draft['ean'],
            'regular_price'            => $draft['regular_price'],
            'sale_price'               => $draft['sale_price'],
            'stock_quantity'           => $draft['stock_quantity'],
            'stock_status'             => (string) $draft['stock_status'],
            'short_description'        => (string) ( $draft['short_description'] ?? '' ),
            'description'              => (string) ( $draft['description'] ?? '' ),
            'main_image_id'            => (int) $draft['main_image_id'],
            'main_image_url'           => (string) $draft['main_image_url'],
            'sync_price'               => (int) ( $draft['sync_price'] ?? 1 ),
            'sync_stock'               => (int) ( $draft['sync_stock'] ?? 1 ),
            'sync_content'             => (int) ( $draft['sync_content'] ?? 0 ),
            'sync_images'              => (int) ( $draft['sync_images'] ?? 0 ),
            'validation_status'        => (string) $draft['validation_status'],
            'review_status'            => (string) $draft['review_status'],
            'sync_status'              => (string) $draft['sync_status'],
            'last_sync_error'          => (string) ( $draft['last_sync_error'] ?? '' ),
            'validation_errors'        => $this->decode_json( (string) ( $draft['validation_errors_json'] ?? '' ) ),
            'validation_warnings'      => $this->decode_json( (string) ( $draft['validation_warnings_json'] ?? '' ) ),
            'updated_at'               => (string) $draft['updated_at'],
            'edit_url'                 => get_edit_post_link( (int) $draft['wc_product_id'], '' ),
        ];
        $row['variation_summary'] = Variation_Draft::summary_for_product_draft( (int) $draft['id'] );
        if ( $include_payload ) {
            $row['short_description']    = (string) $draft['short_description'];
            $row['description']          = (string) $draft['description'];
            $row['gallery']              = $this->decode_json( (string) ( $draft['gallery_json'] ?? '' ) );
            $row['admin_overrides']      = $this->decode_json( (string) ( $draft['admin_overrides_json'] ?? '' ) );
            $row['mapped_payload']       = $this->decode_json( (string) ( $draft['mapped_payload_json'] ?? '' ) );
            $row['final_payload']        = $this->decode_json( (string) ( $draft['final_payload_json'] ?? '' ) );
            $row['bol_offer_snapshot']   = $this->decode_json( (string) ( $draft['bol_offer_snapshot_json'] ?? '' ) );
            $row['bol_content_snapshot'] = $this->decode_json( (string) ( $draft['bol_content_snapshot_json'] ?? '' ) );
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $variation
     * @return array<string, mixed>
     */
    private function public_variation_row( array $variation ): array {
        return [
            'item_type'            => 'variation',
            'id'                   => (int) $variation['id'],
            'product_draft_id'     => (int) $variation['product_draft_id'],
            'wc_variation_id'      => (int) $variation['wc_variation_id'],
            'wc_parent_id'         => (int) $variation['wc_parent_id'],
            'sku'                  => (string) $variation['sku'],
            'ean'                  => (string) $variation['ean'],
            'attributes'           => $this->decode_json( (string) ( $variation['attributes_json'] ?? '' ) ),
            'attributes_signature' => (string) $variation['attributes_signature'],
            'regular_price'        => $variation['regular_price'],
            'sale_price'           => $variation['sale_price'],
            'stock_quantity'       => $variation['stock_quantity'],
            'stock_status'         => (string) $variation['stock_status'],
            'manage_stock'         => (int) $variation['manage_stock'],
            'image_id'             => (int) $variation['image_id'],
            'image_url'            => (string) $variation['image_url'],
            'description'          => (string) $variation['description'],
            'validation_status'    => (string) $variation['validation_status'],
            'validation_errors'    => $this->decode_json( (string) ( $variation['validation_errors_json'] ?? '' ) ),
            'validation_warnings'  => $this->decode_json( (string) ( $variation['validation_warnings_json'] ?? '' ) ),
            'sync_status'          => (string) $variation['sync_status'],
            'last_sync_error'      => (string) ( $variation['last_sync_error'] ?? '' ),
        ];
    }

    /**
     * @param array<string,mixed> $variation
     * @param array<string,mixed> $parent
     * @return array<string,mixed>
     */
    private function public_variation_table_row( array $variation, array $parent ): array {
        $row = $this->public_variation_row( $variation );
        $row['item_type'] = 'variation';
        $row['parent_draft_id'] = (int) ( $parent['id'] ?? 0 );
        $row['parent_name'] = (string) ( $parent['name'] ?? '' );
        $row['review_status'] = (string) ( $parent['review_status'] ?? Product_Draft::REVIEW_PENDING );
        $row['wc_product_id'] = (int) ( $row['wc_variation_id'] ?? 0 );
        $row['product_type'] = 'variation';
        $row['name'] = $this->variation_display_name( $row, $parent );
        $row['short_description'] = (string) ( $parent['short_description'] ?? '' );
        $row['description'] = (string) ( $parent['description'] ?? '' );
        $row['variation_summary'] = [
            'total' => 1,
            'ready' => ( (string) ( $row['validation_status'] ?? '' ) === 'ready' ) ? 1 : 0,
            'blocked' => ( (string) ( $row['validation_status'] ?? '' ) === 'blocked' ) ? 1 : 0,
        ];
        $row['sync_price'] = (int) ( $parent['sync_price'] ?? 1 );
        $row['sync_stock'] = (int) ( $parent['sync_stock'] ?? 1 );
        $row['sync_content'] = (int) ( $parent['sync_content'] ?? 0 );
        $row['sync_images'] = (int) ( $parent['sync_images'] ?? 1 );
        return $row;
    }

    /**
     * Build a user-friendly variation title for table rows.
     *
     * @param array<string,mixed> $variation_row
     * @param array<string,mixed> $parent_row
     */
    private function variation_display_name( array $variation_row, array $parent_row ): string {
        $parent_name = trim( (string) ( $parent_row['name'] ?? '' ) );
        $attributes  = $variation_row['attributes'] ?? [];
        $parts       = [];

        if ( is_array( $attributes ) ) {
            foreach ( $attributes as $key => $value ) {
                if ( ! is_scalar( $value ) ) {
                    continue;
                }
                $label = trim( str_replace( [ 'pa_', '_' ], [ '', ' ' ], (string) $key ) );
                $label = $label !== '' ? ucwords( $label ) : 'Attribute';
                $parts[] = $label . ': ' . (string) $value;
            }
        }

        $suffix = $parts !== [] ? implode( ' | ', $parts ) : '';

        if ( $parent_name !== '' && $suffix !== '' ) {
            return $parent_name . ' - ' . $suffix;
        }
        if ( $parent_name !== '' ) {
            return $parent_name;
        }
        if ( $suffix !== '' ) {
            return $suffix;
        }

        $sku = trim( (string) ( $variation_row['sku'] ?? '' ) );
        if ( $sku !== '' ) {
            return 'Variation: ' . $sku;
        }
        return 'Variation #' . (string) ( $variation_row['wc_variation_id'] ?? '' );
    }

    /**
     * Build counters from currently returned table rows.
     *
     * @param array<int, array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function table_counts_from_rows( array $rows ): array {
        $counts = [
            'total'    => 0,
            'ready'    => 0,
            'warning'  => 0,
            'blocked'  => 0,
            'approved' => 0,
            'synced'   => 0,
            'failed'   => 0,
        ];

        foreach ( $rows as $row ) {
            ++$counts['total'];

            $validation = (string) ( $row['validation_status'] ?? '' );
            if ( isset( $counts[ $validation ] ) ) {
                ++$counts[ $validation ];
            }

            $review = (string) ( $row['review_status'] ?? '' );
            if ( $review === Product_Draft::REVIEW_APPROVED ) {
                ++$counts['approved'];
            }

            $sync = (string) ( $row['sync_status'] ?? '' );
            if ( $sync === Product_Draft::SYNC_SYNCED ) {
                ++$counts['synced'];
            } elseif ( $sync === Product_Draft::SYNC_FAILED ) {
                ++$counts['failed'];
            }
        }

        return $counts;
    }

    // ── Utils ─────────────────────────────────────────────────────────────────

    private function verify(): void {
        check_ajax_referer( 'wbs_staging_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-bol-sync' ) ], 403 );
        }
    }

    /**
     * @param mixed $data
     */
    private function encode_json( $data ): string {
        $encoded = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? $encoded : 'null';
    }

    /**
     * @return array<mixed>|array<string, mixed>
     */
    private function decode_json( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
