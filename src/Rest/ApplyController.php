<?php

namespace CoderEmbassy\AiSeoAutomation\Rest;

use CoderEmbassy\AiSeoAutomation\Database\Schema;
use CoderEmbassy\AiSeoAutomation\Repository\AuditRepository;
use CoderEmbassy\AiSeoAutomation\Repository\JobRepository;
use CoderEmbassy\AiSeoAutomation\Repository\ProductRepository;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for applying previews to products.
 */
class ApplyController {

    public function __construct(
        private JobRepository $jobRepo,
        private ProductRepository $productRepo,
        private AuditRepository $auditRepo
    ) {}

    public function register_routes(): void {
        register_rest_route( SeoController::NAMESPACE, '/apply/(?P<job_id>\d+)', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'apply_job' ],
                'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
                'args'                => [
                    'job_id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( SeoController::NAMESPACE, '/apply/(?P<job_id>\d+)/(?P<product_id>\d+)', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'apply_single' ],
                'permission_callback' => fn() => current_user_can( 'edit_products' ),
                'args'                => [
                    'job_id'     => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                    'product_id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( SeoController::NAMESPACE, '/apply-preview/(?P<product_id>\d+)', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'apply_preview' ],
                'permission_callback' => fn() => current_user_can( 'edit_products' ),
                'args'                => [
                    'product_id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                ],
            ],
        ] );
    }

    /**
     * POST /coderembassy-ai-seo/v1/apply/{job_id} — Apply all completed previews in a job.
     */
    public function apply_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $job_id  = absint( $request->get_param( 'job_id' ) );
        $job     = $this->jobRepo->getJob( $job_id );

        if ( ! $job ) {
            return new \WP_Error( 'job_not_found', 'Job not found.', [ 'status' => 404 ] );
        }

        if ( ! in_array( $job->status, [ 'complete', 'applied' ], true ) ) {
            return new \WP_Error( 'job_not_ready', 'Job must be complete before applying.', [ 'status' => 409 ] );
        }

        $items   = $this->getCompleteItems( $job_id );
        $applied = 0;
        $user_id = get_current_user_id();

        foreach ( $items as $item ) {
            $preview = json_decode( $item->preview, true );
            if ( ! is_array( $preview ) ) {
                continue;
            }
            $this->applyPreviewToProduct( (int) $item->product_id, $preview, $user_id, $job_id );
            $applied++;
        }

        $this->jobRepo->markJobStatus( $job_id, 'applied' );

        return rest_ensure_response( [
            'job_id'  => $job_id,
            'status'  => 'applied',
            'applied' => $applied,
        ] );
    }

    /**
     * POST /coderembassy-ai-seo/v1/apply/{job_id}/{product_id} — Apply preview for a single product.
     */
    public function apply_single( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $job_id     = absint( $request->get_param( 'job_id' ) );
        $product_id = absint( $request->get_param( 'product_id' ) );
        $user_id    = get_current_user_id();

        $item = $this->getCompleteItemForProduct( $job_id, $product_id );

        if ( ! $item ) {
            return new \WP_Error( 'item_not_found', 'No completed preview found for this product in the job.', [ 'status' => 404 ] );
        }

        $preview = json_decode( $item->preview, true );
        if ( ! is_array( $preview ) ) {
            return new \WP_Error( 'invalid_preview', 'Preview data is invalid.', [ 'status' => 422 ] );
        }

        $this->applyPreviewToProduct( $product_id, $preview, $user_id, $job_id );

        return rest_ensure_response( [
            'job_id'     => $job_id,
            'product_id' => $product_id,
            'status'     => 'applied',
        ] );
    }

    /**
     * POST /coderembassy-ai-seo/v1/apply-preview/{product_id}
     *
     * Directly applies a preview object from the metabox to a product's SEO
     * fields without requiring an existing bulk job.
     */
    public function apply_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $product_id = absint( $request->get_param( 'product_id' ) );

        if ( ! get_post( $product_id ) ) {
            return new \WP_Error( 'product_not_found', 'Product not found.', [ 'status' => 404 ] );
        }

        $body = $request->get_json_params();

        $title  = isset( $body['title'] )  ? (string) $body['title']  : null;
        $meta   = isset( $body['meta'] )   ? (string) $body['meta']   : null;
        $alt    = isset( $body['alt'] )    ? (array)  $body['alt']    : null;
        $schema = isset( $body['schema'] ) ? (array)  $body['schema'] : null;

        $preview = array_filter( [
            'title'  => $title,
            'meta'   => $meta,
            'alt'    => $alt,
            'schema' => $schema,
        ], fn( $v ) => $v !== null );

        $this->applyPreviewToProduct( $product_id, $preview, get_current_user_id(), 0 );

        return rest_ensure_response( [ 'applied' => 1 ] );
    }

    /**
     * Write preview fields to postmeta and record audit rows.
     *
     * @param int   $product_id Product ID.
     * @param array $preview    Preview data: {title, meta, alt, schema}.
     * @param int   $user_id    User performing the apply.
     * @param int   $job_id     Source job ID for audit trail (0 for direct metabox applies).
     */
    private function applyPreviewToProduct( int $product_id, array $preview, int $user_id, int $job_id ): void {
        $meta_map = [
            'title'  => '_ce_ai_seo_seo_title',
            'meta'   => '_ce_ai_seo_seo_meta',
            'alt'    => '_ce_ai_seo_seo_alt',
            'schema' => '_ce_ai_seo_seo_schema',
        ];

        foreach ( $meta_map as $key => $meta_key ) {
            if ( ! array_key_exists( $key, $preview ) ) {
                continue;
            }

            $new_value = ( $key === 'alt' || $key === 'schema' )
                ? wp_json_encode( $preview[ $key ] )
                : (string) $preview[ $key ];

            $old_value = get_post_meta( $product_id, $meta_key, true );
            $old_value = $old_value !== '' ? (string) $old_value : null;

            update_post_meta( $product_id, $meta_key, $new_value );

            $this->auditRepo->insertAuditRow( [
                'product_id'  => $product_id,
                'field_name'  => $meta_key,
                'old_value'   => $old_value,
                'new_value'   => $new_value,
                'event_type'  => 'apply',
                'changed_by'  => $user_id,
                'job_id'      => $job_id,
            ] );
        }

        // ── Write-through to third-party SEO plugins (unconditional) ─────────
        // We write to all known meta keys regardless of which plugin is active.
        // Writing to a key when the plugin is not installed is harmless.

        global $wpdb;

        // Yoast: bypass Yoast's sanitize_post_meta filter (which strips values
        // written via update_post_meta) by writing directly to the DB.
        // NOTE: wp_postmeta has NO unique index on (post_id, meta_key), so
        // $wpdb->replace() always INSERTs a new row instead of updating.
        // We must DELETE the old row first, then INSERT fresh, then clear
        // WordPress's internal object cache for this post.
        if ( isset( $preview['title'] ) ) {
            $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $product_id, 'meta_key' => '_yoast_wpseo_title' ] );
            $wpdb->insert( $wpdb->postmeta, [ 'post_id' => $product_id, 'meta_key' => '_yoast_wpseo_title', 'meta_value' => $preview['title'] ] );
        }
        if ( isset( $preview['meta'] ) ) {
            $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $product_id, 'meta_key' => '_yoast_wpseo_metadesc' ] );
            $wpdb->insert( $wpdb->postmeta, [ 'post_id' => $product_id, 'meta_key' => '_yoast_wpseo_metadesc', 'meta_value' => $preview['meta'] ] );
        }
        // Flush WP's postmeta object cache so subsequent get_post_meta() calls
        // in the same request see the new values.
        wp_cache_delete( $product_id, 'post_meta' );

        if ( isset( $preview['title'] ) ) {
            update_post_meta( $product_id, 'rank_math_title',   $preview['title'] );
            update_post_meta( $product_id, '_aioseo_title',     $preview['title'] );
        }
        if ( isset( $preview['meta'] ) ) {
            update_post_meta( $product_id, 'rank_math_description', $preview['meta'] );
            update_post_meta( $product_id, '_aioseo_description',   $preview['meta'] );
        }

        // ── Write AI alt text to the featured image attachment ────────────────
        if ( isset( $preview['alt'] ) && is_array( $preview['alt'] ) && ! empty( $preview['alt'][0] ) ) {
            $thumb_id = (int) get_post_thumbnail_id( $product_id );
            if ( $thumb_id > 0 ) {
                update_post_meta( $thumb_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $preview['alt'][0] ) );
            }
        }

    }

    /**
     * Fetch all complete job items.
     *
     * @param int $job_id Job ID.
     * @return object[]
     */
    private function getCompleteItems( int $job_id ): array {
        global $wpdb;
        $table = Schema::tableName( Schema::JOB_ITEMS );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is internal/known; value is prepared.
                "SELECT * FROM {$table} WHERE job_id = %d AND status = 'complete' ORDER BY id ASC",
                $job_id
            )
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Fetch a single complete item for a specific product in a job.
     *
     * @param int $job_id     Job ID.
     * @param int $product_id Product ID.
     * @return object|null
     */
    private function getCompleteItemForProduct( int $job_id, int $product_id ): ?object {
        global $wpdb;
        $table = Schema::tableName( Schema::JOB_ITEMS );
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is internal/known; values are prepared.
                "SELECT * FROM {$table} WHERE job_id = %d AND product_id = %d AND status = 'complete' LIMIT 1",
                $job_id,
                $product_id
            )
        );
        return $row ?: null;
    }
}
