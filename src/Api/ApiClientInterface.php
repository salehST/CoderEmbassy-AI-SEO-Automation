<?php

namespace AiWooSeo\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for AI API clients (OpenAI, Anthropic).
 */
interface ApiClientInterface {

    /**
     * Send prompt payload, return structured SEO result.
     *
     * Expected return keys: title (string), meta (string), alt (string[]), schema (array)
     *
     * @param array $payload Must contain 'prompt' key.
     * @return array{title: string, meta: string, alt: array, schema: array}
     * @throws \RuntimeException On API error or rate limit.
     */
    public function generate( array $payload ): array;

    /**
     * Test credentials / connectivity.
     *
     * @return bool True on success.
     */
    public function testConnection(): bool;
}
