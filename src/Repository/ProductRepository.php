<?php

namespace CoderEmbassy\AiSeoAutomation\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and updates WooCommerce product data for SEO.
 */
class ProductRepository {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    /**
     * Get product data for AI generation.
     *
     * @param int $productId WooCommerce product (post) ID.
     * @return array<string, mixed> Product data array.
     */
    public function getProductData( int $productId ): array {
        $product = wc_get_product( $productId );
        if ( ! $product || ! $product->exists() ) {
            return [];
        }

        $categories = [];
        $terms      = get_the_terms( $productId, 'product_cat' );
        if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( $term instanceof \WP_Term ) {
                    $categories[] = $term->name;
                }
            }
        }

        $attributes = [];
        foreach ( $product->get_attributes() as $attr_name => $attr ) {
            if ( $attr->is_taxonomy() ) {
                $terms = wp_get_post_terms( $productId, $attr_name );
                if ( is_array( $terms ) ) {
                    $attributes[ $attr_name ] = array_map( fn( $t ) => $t->name, $terms );
                }
            } else {
                $attributes[ $attr_name ] = $attr->get_options();
            }
        }

        $brand      = '';
        $brand_term = get_the_terms( $productId, 'product_brand' );
        if ( is_array( $brand_term ) && ! is_wp_error( $brand_term ) && ! empty( $brand_term ) ) {
            $brand = $brand_term[0]->name ?? '';
        }
        if ( empty( $brand ) ) {
            $brand = get_post_meta( $productId, '_brand', true ) ?: '';
        }

        return [
            'id'                => $productId,
            'title'             => $product->get_name(),
            'name'              => $product->get_name(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku'               => $product->get_sku(),
            'price'             => $product->get_price(),
            'categories'        => $categories,
            'attributes'        => $attributes,
            'brand'             => $brand,
            'focus_keyphrase'   => (string) get_post_meta( $productId, '_ce_ai_seo_focus_keyphrase', true ),
        ];
    }

    /**
     * Update SEO fields on a product.
     *
     * @param int   $productId Product ID.
     * @param array $seoData   Keys: title, meta, alt, schema.
     * @param int   $userId    User performing the update.
     * @param int|null $jobId  Optional job ID for audit.
     * @return void
     */
    public function updateSeoFields( int $productId, array $seoData, int $userId = 0, ?int $jobId = null ): void {
        $meta_keys = [
            'title'  => '_ce_ai_seo_seo_title',
            'meta'   => '_ce_ai_seo_seo_meta',
            'alt'    => '_ce_ai_seo_seo_alt',
            'schema' => '_ce_ai_seo_seo_schema',
        ];

        foreach ( $meta_keys as $key => $meta_key ) {
            if ( ! isset( $seoData[ $key ] ) ) {
                continue;
            }
            $new_value = $key === 'alt' || $key === 'schema'
                ? wp_json_encode( $seoData[ $key ] )
                : (string) $seoData[ $key ];
            $old_value = get_post_meta( $productId, $meta_key, true );

            update_post_meta( $productId, $meta_key, $new_value );

            do_action( 'ce_ai_seo_seo_field_updated', $productId, $meta_key, $old_value, $new_value, $userId, $jobId );
        }

        // ── Write-through to third-party SEO plugins (unconditional) ─────────
        // We always write to all known meta keys. Writing to a key when the plugin
        // is not installed is harmless. This avoids relying on constants that may
        // not be defined during REST API requests.

        // Yoast: bypass Yoast's sanitize_post_meta filter by writing directly to
        // the DB. wp_postmeta has no UNIQUE index on (post_id, meta_key), so
        // $wpdb->replace() always INSERTs instead of updating. Use DELETE + INSERT.
        global $wpdb;
        if ( isset( $seoData['title'] ) ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required targeted postmeta write-through.
            $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $productId, 'meta_key' => '_yoast_wpseo_title' ] );
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required targeted postmeta write-through.
            $wpdb->insert( $wpdb->postmeta, [ 'post_id' => $productId, 'meta_key' => '_yoast_wpseo_title',    'meta_value' => $seoData['title'] ] );
        }
        if ( isset( $seoData['meta'] ) ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required targeted postmeta write-through.
            $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $productId, 'meta_key' => '_yoast_wpseo_metadesc' ] );
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required targeted postmeta write-through.
            $wpdb->insert( $wpdb->postmeta, [ 'post_id' => $productId, 'meta_key' => '_yoast_wpseo_metadesc', 'meta_value' => $seoData['meta'] ] );
        }
        wp_cache_delete( $productId, 'post_meta' );

        if ( isset( $seoData['title'] ) ) {
            update_post_meta( $productId, 'rank_math_title',   $seoData['title'] );
            update_post_meta( $productId, '_aioseo_title',     $seoData['title'] );
        }
        if ( isset( $seoData['meta'] ) ) {
            update_post_meta( $productId, 'rank_math_description', $seoData['meta'] );
            update_post_meta( $productId, '_aioseo_description',   $seoData['meta'] );
        }

        // ── Write AI alt text to the featured image attachment ────────────────
        if ( isset( $seoData['alt'] ) && is_array( $seoData['alt'] ) && ! empty( $seoData['alt'][0] ) ) {
            $thumb_id = (int) get_post_thumbnail_id( $productId );
            if ( $thumb_id > 0 ) {
                update_post_meta( $thumb_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $seoData['alt'][0] ) );
            }
        }
    }

    // phpcs:enable
}
