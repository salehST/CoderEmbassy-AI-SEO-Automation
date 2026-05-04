<?php

namespace CoderEmbassy\AiSeoAutomation\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Handles SEO frontend output.
 *
 * Strategy (Option C):
 *  - If Yoast / RankMath / AIOSEO is active, their own meta tags take priority.
 *    We skip wp_head output but still write to their meta keys via ProductRepository.
 *  - If no SEO plugin is detected, we output our own <meta> tags at priority 1.
 *  - We always filter wp_get_attachment_image_attributes to inject our AI alt text.
 *  - JSON-LD structured data is output via SchemaGenerator at priority 10.
 */
class SeoOutputService {

    public function __construct(
        private SchemaGenerator $schemaGenerator
    ) {}

    public function register_hooks(): void {
        // Always register — the callback itself checks for third-party SEO plugins.
        // Priority 1 ensures we run before most other head output.
        add_action( 'wp_head', [ $this, 'output_meta_tags' ], 1 );

        // Always inject alt text on attachment image attributes.
        add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_alt_text' ], 10, 2 );

        // Hook into Yoast SEO output filters to inject our AI values at render time.
        // This avoids fighting Yoast's database write-protection entirely.
        add_filter( 'wpseo_title',    [ $this, 'filter_yoast_title' ] );
        add_filter( 'wpseo_metadesc', [ $this, 'filter_yoast_metadesc' ] );

        // JSON-LD structured data — output after Yoast/RankMath have run (priority 10).
        add_action( 'wp_head', [ $this, 'output_schema_tag' ], 10 );
    }

    /**
     * wp_head wrapper that resolves the product ID from the query and delegates
     * to SchemaGenerator. Only fires on single product pages with stored schema.
     */
    public function output_schema_tag(): void {
        if ( ! is_product() ) {
            return;
        }
        $product_id = (int) get_queried_object_id();
        $this->schemaGenerator->outputScriptTag( $product_id );
    }

    /**
     * Output <meta> tags for AI-generated SEO data on single product pages.
     * Defers to Yoast / RankMath / AIOSEO when any of them is active.
     */
    public function output_meta_tags(): void {
        // Let Yoast / RankMath / AIOSEO handle their own output.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
            return;
        }

        if ( ! is_product() ) {
            return;
        }

        $product_id = (int) get_queried_object_id();
        $title      = (string) get_post_meta( $product_id, '_ce_ai_seo_seo_title', true );
        $desc       = (string) get_post_meta( $product_id, '_ce_ai_seo_seo_meta',  true );

        if ( empty( $title ) && empty( $desc ) ) {
            return;
        }
        ?>
        <!-- CoderEmbassy AI SEO -->
        <?php if ( $desc ) : ?>
        <meta name="description" content="<?php echo esc_attr( $desc ); ?>">
        <?php endif; ?>
        <?php if ( $title ) : ?>
        <meta property="og:title" content="<?php echo esc_attr( $title ); ?>">
        <?php endif; ?>
        <?php if ( $desc ) : ?>
        <meta property="og:description" content="<?php echo esc_attr( $desc ); ?>">
        <?php endif; ?>
        <?php if ( $title ) : ?>
        <meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>">
        <?php endif; ?>
        <?php if ( $desc ) : ?>
        <meta name="twitter:description" content="<?php echo esc_attr( $desc ); ?>">
        <?php endif; ?>
        <?php
    }

    /**
     * Override Yoast's rendered title with our AI-generated value on product pages.
     *
     * @param string $title Yoast's computed title string.
     * @return string
     */
    public function filter_yoast_title( string $title ): string {
        if ( ! is_singular( 'product' ) ) {
            return $title;
        }
        $ai_title = (string) get_post_meta( get_the_ID(), '_ce_ai_seo_seo_title', true );
        return ! empty( $ai_title ) ? $ai_title : $title;
    }

    /**
     * Override Yoast's rendered meta description with our AI-generated value on product pages.
     *
     * @param string $desc Yoast's computed meta description string.
     * @return string
     */
    public function filter_yoast_metadesc( string $desc ): string {
        if ( ! is_singular( 'product' ) ) {
            return $desc;
        }
        $ai_meta = (string) get_post_meta( get_the_ID(), '_ce_ai_seo_seo_meta', true );
        return ! empty( $ai_meta ) ? $ai_meta : $desc;
    }

    /**
     * Inject AI-generated alt text into attachment image attributes.
     *
     * @param array    $attrs      Current image attributes.
     * @param \WP_Post $attachment The attachment post object.
     * @return array Modified attributes.
     */
    public function filter_alt_text( array $attrs, \WP_Post $attachment ): array {
        if ( empty( $attachment->post_parent ) ) {
            return $attrs;
        }

        $alt_data = get_post_meta( $attachment->post_parent, '_ce_ai_seo_seo_alt', true );
        if ( empty( $alt_data ) ) {
            return $attrs;
        }

        $alt = json_decode( $alt_data, true );
        if ( is_array( $alt ) && ! empty( $alt[0] ) ) {
            $attrs['alt'] = esc_attr( (string) $alt[0] );
        }

        return $attrs;
    }

}
