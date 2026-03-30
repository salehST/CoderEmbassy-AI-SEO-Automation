<?php

namespace AiWooSeo\Admin;

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
        if ( $handle === 'aiwoo-metabox' ) {
            return str_replace( ' src=', ' type="module" src=', $tag );
        }
        return $tag;
    }

    public function add_meta_box(): void {
        add_meta_box(
            'aiwoo-seo-metabox',
            __( 'AI SEO', 'ai-woo-seo' ),
            [ $this, 'render' ],
            'product',
            'side',
            'high'
        );
    }

    /**
     * Render the metabox shell. React mounts into #aiwoo-metabox-root.
     *
     * @param \WP_Post $post Current product post.
     */
    public function render( \WP_Post $post ): void {
        $product = wc_get_product( $post->ID );
        $current = [];

        if ( $product ) {
            $current = [
                'title'          => get_post_meta( $post->ID, '_aiwoo_seo_title', true ) ?: '',
                'meta'           => get_post_meta( $post->ID, '_aiwoo_seo_meta', true ) ?: '',
                'alt'            => json_decode( (string) get_post_meta( $post->ID, '_aiwoo_seo_alt', true ), true ) ?: [],
                'schema'         => json_decode( (string) get_post_meta( $post->ID, '_aiwoo_seo_schema', true ), true ) ?: [],
                'focus_keyphrase'=> (string) get_post_meta( $post->ID, '_aiwoo_focus_keyphrase', true ),
            ];
        }
        ?>
        <div id="aiwoo-metabox-root"
             data-product-id="<?php echo esc_attr( (string) $post->ID ); ?>">
        </div>
        <script>
            window.AiWooProduct = {
                productId: <?php echo (int) $post->ID; ?>,
                lastJobId: <?php echo (int) get_post_meta( $post->ID, '_aiwoo_last_job_id', true ); ?>,
                current: <?php echo wp_json_encode( $current ); ?>,
                nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>,
                focusKeyphrase: <?php echo wp_json_encode( get_post_meta( $post->ID, '_aiwoo_focus_keyphrase', true ) ?: '' ); ?>,
            };
        </script>
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

        $dist_dir = plugin_dir_path( AIWOO_PLUGIN_FILE ) . 'dist/';
        $dist_url = plugin_dir_url( AIWOO_PLUGIN_FILE ) . 'dist/';

        if ( ! file_exists( $dist_dir . 'metabox.js' ) ) {
            return;
        }

        if ( file_exists( $dist_dir . 'main.css' ) ) {
            wp_enqueue_style(
                'aiwoo-metabox',
                $dist_url . 'main.css',
                [],
                AIWOO_VERSION
            );
        }

        wp_enqueue_script(
            'aiwoo-metabox',
            $dist_url . 'metabox.js',
            [],
            AIWOO_VERSION,
            true
        );

        // Shared REST API config
        wp_localize_script( 'aiwoo-metabox', 'AiWoo', [
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }
}
