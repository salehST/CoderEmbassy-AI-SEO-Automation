<?php

namespace AiWooSeo\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Google Gemini API client.
 */
class GeminiClient implements ApiClientInterface {

    private string $apiKey;
    private string $model;
    private int $maxRetries = 3;

    public function __construct( string $encryptedKey, string $model = 'gemini-2.0-flash' ) {
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
        throw new \RuntimeException( 'Gemini: max retries exceeded' );
    }

    /**
     * {@inheritdoc}
     */
    public function testConnection(): bool {
        return ! empty( $this->apiKey );
    }

    /**
     * Call Gemini generateContent API.
     *
     * @param array $payload Must contain 'prompt' key.
     * @return array|\WP_Error
     */
    private function callApi( array $payload ) {
        $prompt = $payload['prompt'] ?? '';
        if ( empty( $prompt ) ) {
            return new \WP_Error( 'missing_prompt', 'Prompt is required' );
        }

        $url  = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode( $this->model ),
            $this->apiKey
        );

        $body = [
            'contents'         => [
                [
                    'parts' => [
                        [ 'text' => $prompt ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 1024,
            ],
        ];

        $response = wp_remote_post(
            $url,
            [
                'headers' => [
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

        $body    = wp_remote_retrieve_body( $response );
        $data    = json_decode( $body, true );
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if ( empty( $content ) ) {
            return new \WP_Error( 'invalid_response', 'Invalid Gemini response' );
        }

        $result = $this->parseJsonResponse( trim( $content ) );

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
