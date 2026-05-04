<?php

namespace CoderEmbassy\AiSeoAutomation\Rest;

use CoderEmbassy\AiSeoAutomation\Repository\RulesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the rules engine.
 *
 * Routes (all require manage_woocommerce):
 *  GET    /coderembassy-ai-seo/v1/rules         — list rules
 *  POST   /coderembassy-ai-seo/v1/rules         — create rule
 *  GET    /coderembassy-ai-seo/v1/rules/{id}    — get single rule
 *  POST   /coderembassy-ai-seo/v1/rules/{id}    — update rule
 *  DELETE /coderembassy-ai-seo/v1/rules/{id}    — delete rule (blocked if last default)
 */
class RulesController {

    public function __construct( private RulesRepository $rules ) {}

    public function register_routes(): void {
        $ns = SeoController::NAMESPACE;

        register_rest_route( $ns, '/rules', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_rules' ],
                'permission_callback' => function() { return current_user_can( 'manage_woocommerce' ); },
                'args'                => [
                    'limit'  => [ 'type' => 'integer', 'default' => 50,  'sanitize_callback' => 'absint' ],
                    'offset' => [ 'type' => 'integer', 'default' => 0,   'sanitize_callback' => 'absint' ],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_rule' ],
                'permission_callback' => function() { return current_user_can( 'manage_woocommerce' ); },
                'args'                => $this->rule_args( true ),
            ],
        ] );

        register_rest_route( $ns, '/rules/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_rule' ],
                'permission_callback' => function() { return current_user_can( 'manage_woocommerce' ); },
                'args'                => $this->id_args(),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'update_rule' ],
                'permission_callback' => function() { return current_user_can( 'manage_woocommerce' ); },
                'args'                => array_merge( $this->id_args(), $this->rule_args( false ) ),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_rule' ],
                'permission_callback' => function() { return current_user_can( 'manage_woocommerce' ); },
                'args'                => $this->id_args(),
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * GET /coderembassy-ai-seo/v1/rules
     */
    public function list_rules( \WP_REST_Request $request ): \WP_REST_Response {
        $limit  = (int) ( $request->get_param( 'limit' )  ?: 50 );
        $offset = (int) ( $request->get_param( 'offset' ) ?: 0 );

        return rest_ensure_response( [
            'rules' => $this->rules->listRules( $limit, $offset ),
        ] );
    }

    /**
     * POST /coderembassy-ai-seo/v1/rules
     */
    public function create_rule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        // Free tier: max 1 rule
        if ( ( defined( 'CE_AI_SEO_TIER' ) ? CE_AI_SEO_TIER : 'free' ) === 'free' ) {
            $existing = $this->rules->listRules( 2, 0 );
            if ( count( $existing ) >= 1 ) {
                return new \WP_Error(
                    'upgrade_required',
                    'Free tier supports 1 rule. Upgrade to Pro for unlimited rules.',
                    [ 'status' => 403 ]
                );
            }
        }

        $data = $this->extractRuleData( $request );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $data['created_by'] = get_current_user_id();
        $id = $this->rules->createRule( $data );

        if ( ! $id ) {
            return new \WP_Error( 'create_failed', 'Failed to create rule.', [ 'status' => 500 ] );
        }

        $rule = $this->rules->getRule( $id );
        return new \WP_REST_Response( $rule, 201 );
    }

    /**
     * GET /coderembassy-ai-seo/v1/rules/{id}
     */
    public function get_rule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $rule = $this->rules->getRule( (int) $request->get_param( 'id' ) );

        if ( ! $rule ) {
            return new \WP_Error( 'not_found', 'Rule not found.', [ 'status' => 404 ] );
        }

        return rest_ensure_response( $rule );
    }

    /**
     * POST /coderembassy-ai-seo/v1/rules/{id}
     */
    public function update_rule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id   = (int) $request->get_param( 'id' );
        $rule = $this->rules->getRule( $id );

        if ( ! $rule ) {
            return new \WP_Error( 'not_found', 'Rule not found.', [ 'status' => 404 ] );
        }

        $data = $this->extractRuleData( $request );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $this->rules->updateRule( $id, $data );

        return rest_ensure_response( $this->rules->getRule( $id ) );
    }

    /**
     * DELETE /coderembassy-ai-seo/v1/rules/{id}
     * Blocked when deleting the last rule that is marked as default.
     */
    public function delete_rule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id   = (int) $request->get_param( 'id' );
        $rule = $this->rules->getRule( $id );

        if ( ! $rule ) {
            return new \WP_Error( 'not_found', 'Rule not found.', [ 'status' => 404 ] );
        }

        $this->rules->deleteRule( $id );
        return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function id_args(): array {
        return [
            'id' => [
                'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Extract and sanitize rule fields from the request.
     *
     * @return array|\WP_Error
     */
    private function extractRuleData( \WP_REST_Request $request ) {
        $data = [];

        if ( null !== $request->get_param( 'name' ) ) {
            $name = sanitize_text_field( (string) $request->get_param( 'name' ) );
            if ( empty( $name ) ) {
                return new \WP_Error( 'missing_name', 'Rule name is required.', [ 'status' => 400 ] );
            }
            $data['name'] = $name;
        }

        if ( null !== $request->get_param( 'description' ) ) {
            $data['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
        }

        if ( null !== $request->get_param( 'brand' ) ) {
            $data['brand'] = sanitize_text_field( (string) $request->get_param( 'brand' ) );
        }

        if ( null !== $request->get_param( 'language' ) ) {
            $data['language'] = sanitize_text_field( (string) $request->get_param( 'language' ) ) ?: 'en';
        }

        if ( null !== $request->get_param( 'tone' ) ) {
            $data['tone'] = sanitize_text_field( (string) $request->get_param( 'tone' ) );
        }

        if ( null !== $request->get_param( 'prompt_template' ) ) {
            // Admin-only field: stored as-is (not aggressively sanitized)
            $data['prompt_template'] = wp_kses_post( (string) $request->get_param( 'prompt_template' ) );
        }

        if ( null !== $request->get_param( 'category_ids' ) ) {
            $raw                 = $request->get_param( 'category_ids' );
            $data['category_ids'] = array_map( 'absint', is_array( $raw ) ? $raw : [] );
        }

        if ( null !== $request->get_param( 'is_default' ) ) {
            $data['is_default'] = (bool) $request->get_param( 'is_default' );
        }

        return $data;
    }

    /**
     * Argument definitions for create / update routes.
     *
     * @param bool $nameRequired Whether name is required (true for create).
     */
    private function rule_args( bool $nameRequired ): array {
        return [
            'name' => [
                'type'              => 'string',
                'required'          => $nameRequired,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'description' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'brand' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'language' => [
                'type'              => 'string',
                'default'           => 'en',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'tone' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'prompt_template' => [
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
            ],
            'category_ids' => [
                'type'              => 'array',
                'items'             => [ 'type' => 'integer' ],
                'sanitize_callback' => fn( $v ) => array_map( 'absint', is_array( $v ) ? $v : [] ),
            ],
            'is_default' => [
                'type' => 'boolean',
            ],
        ];
    }
}
