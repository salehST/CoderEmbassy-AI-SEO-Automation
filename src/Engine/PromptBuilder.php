<?php

namespace AiWooSeo\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Builds prompts for AI SEO generation.
 */
class PromptBuilder {

    public const VARS = [
        '{product_name}',
        '{brand}',
        '{sku}',
        '{price}',
        '{short_description}',
        '{categories}',
        '{attributes}',
        '{lang}',
        '{focus_keyphrase}',
    ];

    /**
     * Build a prompt using a rule object from the database.
     *
     * When the rule has a prompt_template all {variables} are replaced with
     * live product data.  When there is no template the standard build() is
     * called with the rule's brand / language / tone injected as overrides.
     *
     * @param array<string, mixed> $product Product data.
     * @param object               $rule    Rule row from RulesRepository.
     * @return string
     */
    public static function buildFromRule( array $product, object $rule ): string {
        if ( ! empty( $rule->prompt_template ) ) {
            return self::fillTemplate( (string) $rule->prompt_template, $product, $rule );
        }

        return self::build( $product, [
            'brand'    => (string) ( $rule->brand    ?? '' ),
            'language' => (string) ( $rule->language ?? 'en' ),
            'tone'     => (string) ( $rule->tone     ?? '' ),
        ] );
    }

    /**
     * Build prompt for SEO generation.
     *
     * @param array<string, mixed> $product Product data.
     * @param array<string, mixed> $rule    Optional rule with template overrides.
     * @return string
     */
    public static function build( array $product, array $rule = [] ): string {
        $context  = self::buildContext( $product, $rule );
        $language = $rule['language'] ?? $product['lang'] ?? 'en';

        $keyphrase_instruction = ! empty( $product['focus_keyphrase'] )
            ? "\nIMPORTANT: The user wants to rank for the keyphrase \"{$product['focus_keyphrase']}\". Ensure it appears naturally in both the SEO title and meta description."
            : '';

        return
            "You are an e-commerce SEO expert. Given this product data:\n\n"
            . $context . "\n"
            . $keyphrase_instruction . "\n\n"
            . "Generate SEO content in the language: {$language}. ALL text fields (title, meta, alt) must be written in {$language}. "
            . "Respond ONLY with valid JSON, no other text or markdown. No preamble. Output format:\n"
            . "{\n"
            . "  \"title\": \"SEO title, max 60 characters\",\n"
            . "  \"meta\": \"Meta description, max 160 characters\",\n"
            . "  \"alt\": [\"alt text for image 1\", \"alt text for image 2\"],\n"
            . "  \"schema\": {\n"
            . "    \"name\": \"product name\",\n"
            . "    \"description\": \"product description\",\n"
            . "    \"sku\": \"SKU if available\",\n"
            . "    \"offers\": {\n"
            . "      \"price\": \"price\",\n"
            . "      \"priceCurrency\": \"USD\",\n"
            . "      \"availability\": \"InStock\"\n"
            . "    }\n"
            . "  }\n"
            . "}\n";
    }

    /**
     * Build context string from product and rule.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $rule
     * @return string
     */
    private static function buildContext( array $product, array $rule ): string {
        $replacements = [
            '{product_name}'       => (string) ( $product['title'] ?? $product['name'] ?? '' ),
            '{brand}'              => (string) ( $product['brand'] ?? $rule['brand'] ?? '' ),
            '{sku}'                => (string) ( $product['sku'] ?? '' ),
            '{price}'              => (string) ( $product['price'] ?? '' ),
            '{short_description}'  => (string) ( $product['short_description'] ?? '' ),
            '{categories}'         => (string) ( is_array( $product['categories'] ?? null )
                ? implode( ', ', $product['categories'] )
                : ( $product['categories'] ?? '' ) ),
            '{attributes}'         => (string) ( is_array( $product['attributes'] ?? null )
                ? wp_json_encode( $product['attributes'] )
                : ( $product['attributes'] ?? '' ) ),
            '{lang}'               => (string) ( $rule['language'] ?? $product['lang'] ?? 'en' ),
            '{focus_keyphrase}'    => (string) ( $product['focus_keyphrase'] ?? '' ),
        ];

        $lines = [];
        $lines[] = 'Product name: ' . $replacements['{product_name}'];
        $lines[] = 'Language: ' . $replacements['{lang}'];
        $lines[] = 'SKU: ' . $replacements['{sku}'];
        $lines[] = 'Price: ' . $replacements['{price}'];
        $lines[] = 'Short description: ' . $replacements['{short_description}'];
        $lines[] = 'Categories: ' . $replacements['{categories}'];
        if ( ! empty( $replacements['{attributes}'] ) ) {
            $lines[] = 'Attributes: ' . $replacements['{attributes}'];
        }
        if ( ! empty( $product['description'] ?? '' ) ) {
            $lines[] = 'Full description: ' . substr( (string) $product['description'], 0, 2000 );
        }
        if ( ! empty( $replacements['{focus_keyphrase}'] ) ) {
            $lines[] = 'Focus keyphrase: ' . $replacements['{focus_keyphrase}'];
        }

        return implode( "\n", $lines );
    }

    /**
     * Replace {variable} placeholders in a rule's custom prompt template.
     *
     * @param string               $template Raw prompt_template from the DB.
     * @param array<string, mixed> $product  Product data.
     * @param object               $rule     Rule row (used for brand/language).
     * @return string
     */
    private static function fillTemplate( string $template, array $product, object $rule ): string {
        $replacements = [
            '{product_name}'      => (string) ( $product['title'] ?? $product['name'] ?? '' ),
            '{brand}'             => (string) ( $rule->brand ?? $product['brand'] ?? '' ),
            '{sku}'               => (string) ( $product['sku'] ?? '' ),
            '{price}'             => (string) ( $product['price'] ?? '' ),
            '{short_description}' => (string) ( $product['short_description'] ?? '' ),
            '{categories}'        => is_array( $product['categories'] ?? null )
                ? implode( ', ', $product['categories'] )
                : (string) ( $product['categories'] ?? '' ),
            '{attributes}'        => is_array( $product['attributes'] ?? null )
                ? (string) wp_json_encode( $product['attributes'] )
                : (string) ( $product['attributes'] ?? '' ),
            '{lang}'              => (string) ( $rule->language ?? $product['lang'] ?? 'en' ),
            '{focus_keyphrase}'   => (string) ( $product['focus_keyphrase'] ?? '' ),
        ];

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }
}
