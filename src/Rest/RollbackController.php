<?php

namespace CoderEmbassy\AiSeoAutomation\Rest;

use CoderEmbassy\AiSeoAutomation\Services\RollbackManager;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for rolling back applied jobs.
 */
class RollbackController {

    public function __construct( private RollbackManager $rollbackManager ) {}

    public function register_routes(): void {
        register_rest_route( SeoController::NAMESPACE, '/rollback/(?P<job_id>\d+)', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'rollback_job' ],
                'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
                'args'                => [
                    'job_id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( SeoController::NAMESPACE, '/rollback/(?P<job_id>\d+)/(?P<product_id>\d+)', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'rollback_single' ],
                'permission_callback' => fn() => current_user_can( 'edit_products' ),
                'args'                => [
                    'job_id'     => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                    'product_id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                ],
            ],
        ] );
    }

    /**
     * POST /coderembassy-ai-seo/v1/rollback/{job_id} — Roll back all changes from a job.
     */
    public function rollback_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $job_id = absint( $request->get_param( 'job_id' ) );

        try {
            $result = $this->rollbackManager->rollbackJob( $job_id, get_current_user_id() );
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'rollback_failed', $e->getMessage(), [ 'status' => 500 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * POST /coderembassy-ai-seo/v1/rollback/{job_id}/{product_id} — Roll back a single product.
     */
    public function rollback_single( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $job_id     = absint( $request->get_param( 'job_id' ) );
        $product_id = absint( $request->get_param( 'product_id' ) );

        try {
            $result = $this->rollbackManager->rollbackProduct( $job_id, $product_id, get_current_user_id() );
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'rollback_failed', $e->getMessage(), [ 'status' => 500 ] );
        }

        return rest_ensure_response( $result );
    }
}
