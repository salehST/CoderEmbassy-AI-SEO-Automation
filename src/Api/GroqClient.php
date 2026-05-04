<?php

namespace CoderEmbassy\AiSeoAutomation\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Groq API client — uses the OpenAI-compatible chat completions endpoint.
 */
class GroqClient implements ApiClientInterface {

    private string $apiKey;
    private string $model;
    private int $maxRetries = 3;

    public function __construct( string $encryptedKey, string $model = 'llama-3.3-70b-versatile' ) {
        $this->apiKey = $this->decrypt( $encryptedKey );
        $this->model  = $model;
    }

    /**
     * {@inheritdoc}
     */
    public function generate( array $payload ): array {
        $attempt = 0;
        while ( $attempt < $this->maxRetries ) {
            $response = $this->callApi( $payload );
            if ( ! is_wp_error( $response ) ) {
                return $response;
            }
            $attempt++;
            usleep( (int) ( pow( 2, $attempt ) * 500000 ) );
        }
        throw new \RuntimeException( 'Groq: max retries exceeded' );
    }

    /**
     * {@inheritdoc}
     */
    public function testConnection(): bool {
        return ! empty( $this->apiKey );
    }

    /**
     * Call Groq API (OpenAI-compatible endpoint).
     *
     * @param array $payload Must contain 'prompt' key.
     * @return array|\WP_Error
     */
    private function callApi( array $payload ) {
        $prompt = $payload['prompt'] ?? '';
        if ( empty( $prompt ) ) {
            return new \WP_Error( 'missing_prompt', 'Prompt is required' );
        }

        $body = [
            'model'       => $this->model,
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens'  => 1024,
            'temperature' => 0.7,
        ];

        $response = wp_remote_post(
            'https://api.groq.com/openai/v1/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 429 || ( $code >= 500 && $code < 600 ) ) {
            return new \WP_Error( 'api_error', 'API returned ' . $code, [ 'status' => $code ] );
        }

        if ( $code >= 400 ) {
            $body = wp_remote_retrieve_body( $response );
            return new \WP_Error( 'api_error', $body ?: 'API error', [ 'status' => $code ] );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || empty( $data['choices'][0]['message']['content'] ) ) {
            return new \WP_Error( 'invalid_response', 'Invalid Groq response' );
        }

        $content = trim( $data['choices'][0]['message']['content'] );
        $result  = $this->parseJsonResponse( $content );

        if ( ! is_array( $result ) ) {
            return new \WP_Error( 'invalid_json', 'Model did not return valid JSON' );
        }

        return $this->normalizeResult( $result );
    }

    /**
     * Parse JSON from response, handling markdown code blocks.
     */
    private function parseJsonResponse( string $content ): ?array {
        $content = preg_replace( '/^```(?:json)?\s*/', '', $content );
        $content = preg_replace( '/\s*```\s*$/', '', $content );
        $content = trim( $content );

        $decoded = json_decode( $content, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Normalize API result to expected structure.
     *
     * @param array $result Raw decoded JSON.
     * @return array{title: string, meta: string, alt: array, schema: array}
     */
    private function normalizeResult( array $result ): array {
        return [
            'title'  => (string) ( $result['title'] ?? '' ),
            'meta'   => (string) ( $result['meta'] ?? '' ),
            'alt'    => (array) ( $result['alt'] ?? [] ),
            'schema' => (array) ( $result['schema'] ?? [] ),
        ];
    }

    private function decrypt( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }

        if ( ! str_starts_with( $encrypted, 'ENC:' ) ) {
            return $encrypted;
        }

        $payload = substr( $encrypted, 4 );
        $decoded = base64_decode( $payload, true );
        if ( $decoded === false ) {
            return '';
        }

        $auth_key = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
        $salt     = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '';

        if ( empty( $auth_key ) ) {
            return '';
        }

        $method         = 'AES-256-CBC';
        $encryption_key = hash( 'sha256', $auth_key, true );
        $iv             = substr( hash( 'sha256', $salt ), 0, 16 );

        $decrypted = openssl_decrypt( $decoded, $method, $encryption_key, OPENSSL_RAW_DATA, $iv );
        return is_string( $decrypted ) ? $decrypted : '';
    }
}
