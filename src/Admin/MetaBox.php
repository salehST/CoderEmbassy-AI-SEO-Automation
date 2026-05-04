<?php

namespace CoderEmbassy\AiSeoAutomation\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Injects the AI SEO metabox into the WooCommerce product edit screen.
 * React mounts into #aiwoo-metabox-root using dist/metabox.js.
 */
class MetaBox {

    public function register_hooks(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_metabox_assets' ] );
        add_filter( 'script_loader_tag', [ $this, 'add_module_type' ], 10, 2 );
    }

    /**
     * Add type="module" to the metabox script tag so ES module imports work.
     *
     * @param string $tag    The full <script> HTML tag.
     * @param string $handle Registered script handle.
     * @return string
     */
    public function add_module_type( string $tag, string $handle ): string {
        if ( $handle === 'ce-ai-seo-metabox' ) {
            return str_replace( ' src=', ' type="module" src=', $tag );
        }
        return $tag;
    }

    public function add_meta_box(): void {
        add_meta_box(
            'ce-ai-seo-metabox',
            __( 'AI SEO', 'coderembassy-ai-seo-automation' ),
            [ $this, 'render' ],
            'product',
            'side',
            'high'
        );
    }

    /**
     * Render the metabox shell. React mounts into #ce-ai-seo-metabox-root.
     *
     * @param \WP_Post $post Current product post.
     */
    public function render( \WP_Post $post ): void {
        ?>
        <div id="ce-ai-seo-metabox-root"
             data-product-id="<?php echo esc_attr( (string) $post->ID ); ?>">
        </div>
        <?php
    }

    /**
     * Enqueue metabox JS/CSS on product edit screens only.
     *
     * @param string $hook_suffix
     */
    public function enqueue_metabox_assets( string $hook_suffix ): void {
        if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'product' ) {
            return;
        }

        $dist_dir = plugin_dir_path( CE_AI_SEO_PLUGIN_FILE ) . 'dist/';
        $dist_url = plugin_dir_url( CE_AI_SEO_PLUGIN_FILE ) . 'dist/';

        if ( ! file_exists( $dist_dir . 'metabox.js' ) ) {
            return;
        }

        if ( file_exists( $dist_dir . 'main.css' ) ) {
            wp_enqueue_style(
                'ce-ai-seo-metabox',
                $dist_url . 'main.css',
                [],
                CE_AI_SEO_VERSION
            );
        }

        wp_enqueue_script(
            'ce-ai-seo-metabox',
            $dist_url . 'metabox.js',
            [],
            CE_AI_SEO_VERSION,
            true
        );

        // Shared REST API config
        wp_localize_script( 'ce-ai-seo-metabox', 'CeAiSeo', [
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );

        global $post;
        if ( $post ) {
            $current = [
                'title'          => get_post_meta( $post->ID, '_ce_ai_seo_seo_title', true ) ?: '',
                'meta'           => get_post_meta( $post->ID, '_ce_ai_seo_seo_meta', true ) ?: '',
                'alt'            => json_decode( (string) get_post_meta( $post->ID, '_ce_ai_seo_seo_alt', true ), true ) ?: [],
                'schema'         => json_decode( (string) get_post_meta( $post->ID, '_ce_ai_seo_seo_schema', true ), true ) ?: [],
                'focus_keyphrase'=> (string) get_post_meta( $post->ID, '_ce_ai_seo_focus_keyphrase', true ),
            ];

            $product_data = [
                'productId'      => (int) $post->ID,
                'lastJobId'      => (int) get_post_meta( $post->ID, '_ce_ai_seo_last_job_id', true ),
                'current'        => $current,
                'nonce'          => wp_create_nonce( 'wp_rest' ),
                'focusKeyphrase' => get_post_meta( $post->ID, '_ce_ai_seo_focus_keyphrase', true ) ?: '',
            ];

            wp_add_inline_script(
                'ce-ai-seo-metabox',
                'window.CeAiSeoProduct = ' . wp_json_encode( $product_data ) . ';',
                'before'
            );
        }
    }
}
