<?php

namespace AiWooSeo\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Enriches AI-generated schema data with real WooCommerce product data
 * and outputs a JSON-LD <script> tag for structured data.
 */
class SchemaGenerator {

    /**
     * Build a full Schema.org/Product JSON-LD array from a WC product
     * merged with any AI-provided schema hints.
     *
     * @param int   $productId WooCommerce product post ID.
     * @param array $aiSchema  Optional AI-generated schema hints (e.g. brand).
     * @return array Structured schema array; empty array if product not found.
     */
    public function generate( int $productId, array $aiSchema = [] ): array {
        $product = wc_get_product( $productId );
        if ( ! $product ) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $product->get_name(),
        ];

        $description = wp_strip_all_tags( (string) $product->get_short_description(), true );
        if ( $description ) {
            $schema['description'] = $description;
        }

        $sku = $product->get_sku();
        if ( $sku ) {
            $schema['sku'] = $sku;
        }

        // Brand — prefer AI hint, then plugin setting
        $brand_name = ! empty( $aiSchema['brand'] )
            ? (string) $aiSchema['brand']
            : (string) get_option( 'aiwoo_brand', '' );
        if ( $brand_name ) {
            $schema['brand'] = [ '@type' => 'Brand', 'name' => $brand_name ];
        }

        // Images — featured image + gallery
        $images     = [];
        $featured_id = $product->get_image_id();
        if ( $featured_id ) {
            $url = wp_get_attachment_url( (int) $featured_id );
            if ( $url ) {
                $images[] = $url;
            }
        }
        foreach ( $product->get_gallery_image_ids() as $img_id ) {
            $url = wp_get_attachment_url( (int) $img_id );
            if ( $url ) {
                $images[] = $url;
            }
        }
        if ( ! empty( $images ) ) {
            $schema['image'] = $images;
        }

        // Offers
        $schema['offers'] = [
            '@type'        => 'Offer',
            'price'        => $product->get_price(),
            'priceCurrency' => get_woocommerce_currency(),
            'availability'  => $product->is_in_stock()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'url'           => (string) get_permalink( $productId ),
        ];

        // Aggregate rating — only include when reviews exist
        if ( $product->get_rating_count() > 0 ) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (float) $product->get_average_rating(),
                'reviewCount' => (int) $product->get_rating_count(),
            ];
        }

        // GTIN (WooCommerce 8.6+ stores as _global_unique_id; legacy as _gtin)
        $gtin = (string) get_post_meta( $productId, '_global_unique_id', true )
             ?: (string) get_post_meta( $productId, '_gtin', true );
        if ( $gtin ) {
            $schema['gtin'] = $gtin;
        }

        // MPN
        $mpn = (string) get_post_meta( $productId, '_mpn', true );
        if ( $mpn ) {
            $schema['mpn'] = $mpn;
        }

        return $schema;
    }

    /**
     * Fetch the stored AI schema for a product, enrich it with live WC data,
     * and echo a JSON-LD <script> tag.
     * Only runs on single product pages.
     *
     * @param int $productId WooCommerce product post ID.
     */
    public function outputScriptTag( int $productId ): void {
        if ( ! is_product() ) {
            return;
        }

        $stored = (string) get_post_meta( $productId, '_aiwoo_seo_schema', true );
        if ( empty( $stored ) ) {
            return;
        }

        $ai_schema = json_decode( $stored, true );
        if ( ! is_array( $ai_schema ) ) {
            $ai_schema = [];
        }

        $enriched = $this->generate( $productId, $ai_schema );
        if ( empty( $enriched ) ) {
            return;
        }

        echo '<script type="application/ld+json">'
            . wp_json_encode( $enriched, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
            . '</script>' . "\n";
    }
}
