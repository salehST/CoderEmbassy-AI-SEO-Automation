<?php

namespace AiWooSeo\Api;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI API client for chat completions.
 */
class OpenAiClient implements ApiClientInterface {

    private string $apiKey;
    private string $model;
    private int $maxRetries = 3;

    public function __construct( string $encryptedKey, string $model = 'gpt-4o-mini' ) {
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
        throw new \RuntimeException( 'OpenAI: max retries exceeded' );
    }

    /**
     * {@inheritdoc}
     */
    public function testConnection(): bool {
        return ! empty( $this->apiKey );
    }

    /**
     * Call OpenAI API.
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
            'https://api.openai.com/v1/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
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
            return new \WP_Error( 'invalid_response', 'Invalid OpenAI response' );
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
        $decoded = base64_decode( $encrypted, true );
        if ( $decoded === false ) {
            return '';
        }
        $iv  = substr( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : 'default_salt_16bytes!!', 0, 16 );
        $key = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
        if ( empty( $key ) || strlen( $key ) < 32 ) {
            return '';
        }
        $decrypted = openssl_decrypt( $decoded, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return is_string( $decrypted ) ? $decrypted : '';
    }
}
