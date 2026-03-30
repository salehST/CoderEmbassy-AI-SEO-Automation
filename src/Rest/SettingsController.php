<?php

namespace AiWooSeo\Rest;

use AiWooSeo\Api\AnthropicClient;
use AiWooSeo\Api\GeminiClient;
use AiWooSeo\Api\GroqClient;
use AiWooSeo\Api\OpenAiClient;
use AiWooSeo\Services\LicenseManager;
use AiWooSeo\Services\UsageMeter;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for settings, test-connection, and usage.
 */
class SettingsController {

    private const VALID_PROVIDERS   = [ 'openai', 'anthropic', 'groq', 'gemini' ];
    private const AUTOPILOT_MODES  = [ 'off', 'new_only', 'all_changes' ];

    public function __construct(
        private UsageMeter      $meter,
        private ?LicenseManager $license = null
    ) {}

    public function register_routes(): void {
        register_rest_route( SeoController::NAMESPACE, '/settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'save_settings' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
                'args'                => [
                    'provider'             => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'api_key'              => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'openai_model'         => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'anthropic_model'      => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'groq_model'           => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'gemini_model'         => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'onboarding_complete'  => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 1 ],
                    'autopilot_mode'       => [ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_autopilot_mode' ] ],
                ],
            ],
        ] );

        register_rest_route( SeoController::NAMESPACE, '/test-connection', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'test_connection' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
            ],
        ] );

        register_rest_route( SeoController::NAMESPACE, '/usage', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_usage' ],
                'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
            ],
        ] );
    }

    /**
     * GET /aiwoo/v1/settings — Return settings (API key masked).
     */
    public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $provider   = (string) get_option( 'aiwoo_provider', 'openai' );
        $stored_key = (string) get_option( "aiwoo_{$provider}_key", '' );
        $masked_key = '';
        if ( ! empty( $stored_key ) ) {
            $masked_key = substr( $stored_key, 0, 8 ) . '••••••••';
        }

        return rest_ensure_response( [
            'provider'            => $provider,
            'api_key'             => $masked_key,
            'openai_model'        => (string) get_option( 'aiwoo_openai_model', 'gpt-4o-mini' ),
            'anthropic_model'     => (string) get_option( 'aiwoo_anthropic_model', 'claude-haiku-4-5-20251001' ),
            'groq_model'          => (string) get_option( 'aiwoo_groq_model', 'llama-3.3-70b-versatile' ),
            'gemini_model'        => (string) get_option( 'aiwoo_gemini_model', 'gemini-2.0-flash' ),
            'onboarding_complete' => (bool) get_option( 'aiwoo_onboarding_complete', false ),
            'autopilot_mode'      => (string) get_option( 'aiwoo_autopilot_mode', 'off' ),
        ] );
    }

    /**
     * Sanitize autopilot_mode: only allow off, new_only, all_changes.
     *
     * @param mixed $value Raw value from request.
     * @return string
     */
    public function sanitize_autopilot_mode( $value ): string {
        $v = sanitize_text_field( (string) $value );
        return in_array( $v, self::AUTOPILOT_MODES, true ) ? $v : 'off';
    }

    /**
     * POST /aiwoo/v1/settings — Save settings, encrypting the API key.
     */
    public function save_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $provider = sanitize_text_field( (string) ( $request->get_param( 'provider' ) ?: 'openai' ) );

        if ( ! in_array( $provider, self::VALID_PROVIDERS, true ) ) {
            return new \WP_Error( 'invalid_provider', 'Provider must be one of: openai, anthropic, groq, gemini.', [ 'status' => 400 ] );
        }

        update_option( 'aiwoo_provider', $provider );

        // Only update the key if a new one was explicitly submitted
        $raw_key = sanitize_text_field( (string) ( $request->get_param( 'api_key' ) ?: '' ) );
        if ( ! empty( $raw_key ) ) {
            $enc = $this->encryptKey( $raw_key );
            update_option( "aiwoo_{$provider}_key", $enc );
        }

        if ( $model = sanitize_text_field( (string) ( $request->get_param( 'openai_model' ) ?: '' ) ) ) {
            update_option( 'aiwoo_openai_model', $model );
        }
        if ( $model = sanitize_text_field( (string) ( $request->get_param( 'anthropic_model' ) ?: '' ) ) ) {
            update_option( 'aiwoo_anthropic_model', $model );
        }
        if ( $model = sanitize_text_field( (string) ( $request->get_param( 'groq_model' ) ?: '' ) ) ) {
            update_option( 'aiwoo_groq_model', $model );
        }
        if ( $model = sanitize_text_field( (string) ( $request->get_param( 'gemini_model' ) ?: '' ) ) ) {
            update_option( 'aiwoo_gemini_model', $model );
        }

        if ( $request->get_param( 'onboarding_complete' ) !== null ) {
            update_option( 'aiwoo_onboarding_complete', 1 );
        }

        $autopilot = $request->get_param( 'autopilot_mode' );
        if ( $autopilot !== null && $autopilot !== '' ) {
            $autopilot = $this->sanitize_autopilot_mode( $autopilot );
            update_option( 'aiwoo_autopilot_mode', $autopilot );
        }

        return rest_ensure_response( [ 'saved' => true ] );
    }

    /**
     * POST /aiwoo/v1/test-connection — Test AI provider credentials.
     */
    public function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
        $provider = (string) get_option( 'aiwoo_provider', 'openai' );
        $enc_key  = (string) get_option( "aiwoo_{$provider}_key", '' );

        try {
            if ( $provider === 'anthropic' ) {
                $client = new AnthropicClient( $enc_key );
            } elseif ( $provider === 'groq' ) {
                $client = new GroqClient( $enc_key );
            } elseif ( $provider === 'gemini' ) {
                $client = new GeminiClient( $enc_key );
            } else {
                $client = new OpenAiClient( $enc_key );
            }

            $success = $client->testConnection();
            $message = $success ? 'Connection successful.' : 'API key appears empty.';
        } catch ( \Throwable $e ) {
            $success = false;
            $message = $e->getMessage();
        }

        return rest_ensure_response( [
            'success'  => $success,
            'message'  => $message,
            'provider' => $provider,
        ] );
    }

    /**
     * GET /aiwoo/v1/usage — Current tier and usage (no usage-based limit).
     */
    public function get_usage( \WP_REST_Request $request ): \WP_REST_Response {
        $summary = $this->meter->getUsageSummary();
        $reset_date = gmdate( 'Y-m-01', strtotime( 'first day of next month' ) );

        return rest_ensure_response( [
            'tier'           => $summary['tier'],
            'used'           => $summary['used'],
            'limit'          => $summary['limit'],
            'pct'            => $summary['percent'],
            'reset_date'     => $reset_date,
            'license_status' => $this->license?->getStatus() ?? 'inactive',
            'upgrade_url'    => defined( 'AIWOO_STORE_URL' ) ? AIWOO_STORE_URL . '/pricing' : '',
        ] );
    }

    /**
     * Encrypt an API key for storage.
     *
     * @param string $key Plain-text API key.
     * @return string Base64-encoded encrypted key.
     */
    private function encryptKey( string $key ): string {
        $auth_key  = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
        $salt      = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '';
        $iv        = substr( $salt, 0, 16 );

        if ( strlen( $auth_key ) < 32 ) {
            // Fall back to storing as-is if constants are not set (local dev only)
            return $key;
        }

        $encrypted = openssl_encrypt( $key, 'AES-256-CBC', $auth_key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $encrypted );
    }
}
