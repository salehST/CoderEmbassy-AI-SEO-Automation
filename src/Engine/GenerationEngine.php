<?php

namespace CoderEmbassy\AiSeoAutomation\Engine;

use CoderEmbassy\AiSeoAutomation\Api\ApiClientInterface;
use CoderEmbassy\AiSeoAutomation\Repository\ProductRepository;
use CoderEmbassy\AiSeoAutomation\Repository\RulesRepository;
use CoderEmbassy\AiSeoAutomation\Services\UsageMeter;

defined( 'ABSPATH' ) || exit;

/**
 * Central orchestrator for AI SEO generation.
 */
class GenerationEngine {

    public function __construct(
        private ApiClientInterface $api,
        private ProductRepository  $productRepo,
        private UsageMeter         $meter,
        private ?RulesRepository   $rulesRepo = null
    ) {}

    /**
     * Generate SEO preview for a single product.
     *
     * Rule resolution order:
     *  1. If $rule is explicitly provided (non-empty array), use it as-is.
     *  2. If $rule is empty and the product has WC categories, auto-fetch the
     *     best matching rule from RulesRepository.
     *  3. If no rule is found, generation proceeds with an empty rule (graceful
     *     fallback — same behaviour as before this feature was added).
     *
     * WPML/Polylang: the product's language is always resolved and injected
     * into the rule context as 'language', so PromptBuilder receives it.
     *
     * @param int   $productId Product ID.
     * @param array $rule      Optional explicit rule overrides (array format).
     * @return array{title: string, meta: string, alt: array, schema: array}
     * @throws \RuntimeException When usage limit reached or product not found.
     */
    public function generatePreviewForProduct( int $productId, array $rule = [] ): array {
        if ( ! $this->meter->canGenerate() ) {
            throw new \RuntimeException( 'Usage limit reached for current tier' );
        }

        $product = $this->productRepo->getProductData( $productId );
        if ( empty( $product ) ) {
            throw new \RuntimeException( 'Product not found' );
        }

        $product = PiiScrubber::scrub( $product );

        // Auto-resolve a rule from the product's categories when none was
        // explicitly provided and RulesRepository is available.
        $resolvedRuleObject = null;
        if ( empty( $rule ) && $this->rulesRepo !== null ) {
            $categoryIds = $this->extractCategoryIds( $product );
            if ( ! empty( $categoryIds ) ) {
                $resolvedRuleObject = $this->rulesRepo->getRuleForCategories( $categoryIds );
            }
        }

        // Inject resolved language into rule for PromptBuilder (never override explicit).
        $lang = $this->resolveProductLanguage( $productId );
        $rule['language'] = $rule['language'] ?? $lang;

        // Build the prompt with the best available rule source
        if ( $resolvedRuleObject !== null ) {
            $prompt = PromptBuilder::buildFromRule( $product, $resolvedRuleObject );
        } else {
            $prompt = PromptBuilder::build( $product, $rule );
        }

        $result = $this->api->generate( [ 'prompt' => $prompt ] );
        $this->meter->increment();

        return $this->normalizeResult( $result, $product );
    }

    /**
     * Resolve the product's language from WPML or Polylang when active.
     *
     * - WPML:     defined('ICL_SITEPRESS_VERSION') — use wpml_element_language_code filter.
     * - Polylang: function_exists('pll_get_post_language') — use pll_get_post_language().
     * - Fallback: 'en'.
     *
     * @param int $productId WooCommerce product post ID.
     * @return string ISO 639-1 language code (e.g. 'en', 'de', 'fr').
     */
    private function resolveProductLanguage( int $productId ): string {
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $lang = apply_filters(
                'wpml_element_language_code',
                null,
                [ 'element_id' => $productId, 'element_type' => 'post_product' ]
            );
            return is_string( $lang ) && $lang !== '' ? $lang : 'en';
        }

        if ( function_exists( 'pll_get_post_language' ) ) {
            return pll_get_post_language( $productId ) ?? 'en';
        }

        return 'en';
    }

    /**
     * Normalize and enforce limits on API result.
     *
     * @param array $result  Raw API result.
     * @param array $product Product data for fallbacks.
     * @return array{title: string, meta: string, alt: array, schema: array}
     */
    private function normalizeResult( array $result, array $product ): array {
        $title = $result['title'] ?? $product['title'] ?? $product['name'] ?? '';
        $title = is_string( $title ) ? substr( $title, 0, 60 ) : '';

        $meta = $result['meta'] ?? '';
        $meta = is_string( $meta ) ? substr( $meta, 0, 160 ) : '';

        $alt = $result['alt'] ?? [];
        $alt = is_array( $alt ) ? array_values( $alt ) : [];

        $schema = $result['schema'] ?? [];
        $schema = is_array( $schema ) ? $schema : [];

        return [
            'title'  => $title,
            'meta'   => $meta,
            'alt'    => $alt,
            'schema' => $schema,
        ];
    }

    /**
     * Extract integer category IDs from product data.
     * Handles both arrays of IDs and arrays of term objects/arrays.
     *
     * @param array $product Product data from ProductRepository.
     * @return int[]
     */
    private function extractCategoryIds( array $product ): array {
        $categories = $product['category_ids'] ?? $product['categories'] ?? [];

        if ( ! is_array( $categories ) || empty( $categories ) ) {
            return [];
        }

        $ids = [];
        foreach ( $categories as $item ) {
            if ( is_numeric( $item ) ) {
                $ids[] = (int) $item;
            } elseif ( is_object( $item ) && isset( $item->term_id ) ) {
                $ids[] = (int) $item->term_id;
            } elseif ( is_array( $item ) && isset( $item['term_id'] ) ) {
                $ids[] = (int) $item['term_id'];
            }
        }

        return array_filter( $ids );
    }
}
