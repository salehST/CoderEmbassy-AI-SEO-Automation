<?php

namespace CoderEmbassy\AiSeoAutomation\Rest;

use CoderEmbassy\AiSeoAutomation\Engine\GenerationEngine;
use CoderEmbassy\AiSeoAutomation\Jobs\JobManager;
use CoderEmbassy\AiSeoAutomation\Jobs\Worker;
use CoderEmbassy\AiSeoAutomation\Repository\JobRepository;
use CoderEmbassy\AiSeoAutomation\Services\CostEstimator;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for job creation, status, and single-product preview.
 */
class SeoController {

    public const NAMESPACE = 'coderembassy-ai-seo/v1';

    public function __construct(
        private JobManager $jobManager,
        private JobRepository $jobRepo,
        private GenerationEngine $engine,
        private Worker $worker
    ) {}

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/job', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_job' ],
                'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
                'args'                => [
                    'name'          => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'product_ids'   => [ 'type' => 'array',   'default' => [] ],  // explicit list (no categories)
                    'category_ids'  => [ 'type' => 'array',   'default' => [] ],  // empty = all categories
                    'exclude_ids'   => [ 'type' => 'array',   'default' => [] ],  // always applied
                    'skip_existing' => [ 'type' => 'boolean', 'default' => false ], // skip products with existing AI SEO
                    'rule_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
                    'options'       => [ 'type' => 'object' ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/job/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_job' ],
                'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
                'args'                => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'cancel_job' ],
                'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
                'args'                => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/job/(?P<id>\d+)/process', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'process_job' ],
                'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
                'args'                => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/estimate', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'estimate_cost' ],
                'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
                'args'                => [
                    'product_count' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                    'provider'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'openai' ],
                    'model'         => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'gpt-4o-mini' ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/preview/(?P<product_id>\d+)', [
            [
                'methods'             => [ \WP_REST_Server::READABLE, \WP_REST_Server::EDITABLE ],
                'callback'            => [ $this, 'preview_product' ],
                'permission_callback' => fn() => current_user_can( 'edit_products' ),
                'args'                => [
                    'product_id'      => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                    'focus_keyphrase' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ],
                ],
            ],
        ] );
    }

    /**
     * POST /coderembassy-ai-seo/v1/job — Create a bulk generation job.
     *
     * Two modes:
     *  1. Explicit: product_ids provided → use them directly.
     *  2. Category: category_ids provided (empty = all) → resolve via wc_get_products().
     * In both modes, exclude_ids are subtracted from the final list.
     */
    public function create_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

        $direct_ids   = array_filter( array_map( 'absint', (array) $request->get_param( 'product_ids' ) ) );
        $category_ids = array_filter( array_map( 'absint', (array) $request->get_param( 'category_ids' ) ) );
        $exclude_ids  = array_filter( array_map( 'absint', (array) $request->get_param( 'exclude_ids' ) ) );

        if ( ! empty( $direct_ids ) ) {
            // Mode 1 — explicit product list
            $product_ids = array_values( array_diff( $direct_ids, $exclude_ids ) );
        } else {
            // Mode 2 — category-based (empty category_ids = all published products)
            $query_args = [
                'status' => 'publish',
                'limit'  => -1,
                'return' => 'ids',
            ];

            if ( ! empty( $category_ids ) ) {
                // wc_get_products() accepts category slugs, so convert IDs → slugs
                $slugs = array_filter( array_map( function ( int $id ): string {
                    $term = get_term( $id, 'product_cat' );
                    return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
                }, $category_ids ) );

                if ( ! empty( $slugs ) ) {
                    $query_args['category'] = array_values( $slugs );
                }
            }

            $resolved    = wc_get_products( $query_args );
            $product_ids = array_values( array_diff( array_map( 'absint', $resolved ), $exclude_ids ) );
        }

        if ( empty( $product_ids ) ) {
            return new \WP_Error(
                'no_products',
                'No products found matching the selected criteria.',
                [ 'status' => 422 ]
            );
        }

        $name    = sanitize_text_field( (string) ( $request->get_param( 'name' ) ?: 'SEO Job ' . gmdate( 'Y-m-d H:i' ) ) );
        $options = (array) ( $request->get_param( 'options' ) ?: [] );
        if ( $rule_id = absint( $request->get_param( 'rule_id' ) ) ) {
            $options['rule_id'] = $rule_id;
        }
        if ( (bool) $request->get_param( 'skip_existing' ) ) {
            $options['skip_existing'] = true;
        }

        try {
            $job_id = $this->jobManager->createJob( $name, get_current_user_id(), $product_ids, $options );
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'job_create_failed', $e->getMessage(), [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'job_id'      => $job_id,
            'status'      => 'queued',
            'total_items' => count( $product_ids ),
        ] );
    }

    /**
     * GET /coderembassy-ai-seo/v1/job/{id} — Get job status and progress.
     */
    public function get_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $job_id = absint( $request->get_param( 'id' ) );
        $job    = $this->jobRepo->getJob( $job_id );

        if ( ! $job ) {
            return new \WP_Error( 'job_not_found', 'Job not found.', [ 'status' => 404 ] );
        }

        $progress = $this->jobRepo->getJobProgress( $job_id );

        return rest_ensure_response( [
            'id'           => (int) $job->id,
            'name'         => $job->name,
            'status'       => $job->status,
            'total_items'  => (int) $job->total_items,
            'done_items'   => $progress['done'],
            'failed_items' => $progress['failed'],
            'progress_pct' => $progress['pct'],
            'created_at'   => $job->created_at,
            'completed_at' => $job->completed_at,
        ] );
    }

    /**
     * DELETE /coderembassy-ai-seo/v1/job/{id} — Cancel a pending job.
     */
    public function cancel_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $job_id = absint( $request->get_param( 'id' ) );
        $job    = $this->jobRepo->getJob( $job_id );

        if ( ! $job ) {
            return new \WP_Error( 'job_not_found', 'Job not found.', [ 'status' => 404 ] );
        }

        if ( ! in_array( $job->status, [ 'pending', 'processing' ], true ) ) {
            return new \WP_Error( 'job_not_cancellable', 'Only pending or processing jobs can be cancelled.', [ 'status' => 409 ] );
        }

        $this->jobRepo->markJobStatus( $job_id, 'cancelled' );

        return rest_ensure_response( [ 'job_id' => $job_id, 'status' => 'cancelled' ] );
    }

    /**
     * POST /coderembassy-ai-seo/v1/job/{id}/process — Synchronously run one Worker batch for a job.
     * Called by the frontend while polling; works on all tiers without WP-Cron.
     */
    public function process_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $job_id = absint( $request->get_param( 'id' ) );
        $job    = $this->jobRepo->getJob( $job_id );

        if ( ! $job ) {
            return new \WP_Error( 'not_found', 'Job not found.', [ 'status' => 404 ] );
        }

        if ( ! in_array( $job->status, [ 'pending', 'processing' ], true ) ) {
            return rest_ensure_response( [
                'status'     => $job->status,
                'done_items' => $job->done_items ?? 0,
                'message'    => 'Job already in terminal state.',
            ] );
        }

        $this->worker->processJob( $job_id );

        $updated = $this->jobRepo->getJob( $job_id );
        return rest_ensure_response( [
            'status'     => $updated->status,
            'done_items' => $updated->done_items ?? 0,
        ] );
    }

    /**
     * GET /coderembassy-ai-seo/v1/estimate — Return cost estimate for a planned bulk job.
     */
    public function estimate_cost( \WP_REST_Request $request ): \WP_REST_Response {
        $product_count = absint( $request->get_param( 'product_count' ) );
        $provider      = sanitize_text_field( (string) $request->get_param( 'provider' ) );
        $model         = sanitize_text_field( (string) $request->get_param( 'model' ) );

        return rest_ensure_response(
            CostEstimator::estimateForJob( $product_count, $provider, $model )
        );
    }

    /**
     * GET /coderembassy-ai-seo/v1/preview/{product_id} — On-demand single-product preview.
     */
    public function preview_product( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $product_id = absint( $request->get_param( 'product_id' ) );

        $keyphrase = sanitize_text_field( (string) $request->get_param( 'focus_keyphrase' ) );
        if ( $keyphrase !== '' ) {
            update_post_meta( $product_id, '_ce_ai_seo_focus_keyphrase', $keyphrase );
        }

        try {
            $preview = $this->engine->generatePreviewForProduct( $product_id );
        } catch ( \RuntimeException $e ) {
            $status = str_contains( $e->getMessage(), 'Usage limit' ) ? 402 : 422;
            return new \WP_Error( 'preview_failed', $e->getMessage(), [ 'status' => $status ] );
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'preview_failed', $e->getMessage(), [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'product_id'      => $product_id,
            'preview'         => $preview,
            'focus_keyphrase' => get_post_meta( $product_id, '_ce_ai_seo_focus_keyphrase', true ) ?: '',
        ] );
    }
}
