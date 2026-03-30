<?php

namespace AiWooSeo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's top-level admin menu page.
 */
class AdminMenu {

    public function register_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
    }

    public function add_menu_page(): void {
        add_menu_page(
            __( 'CoderEmbassy AI SEO Automation', 'ai-woo-seo' ),
            __( 'AI SEO', 'ai-woo-seo' ),
            'manage_woocommerce',
            'aiwoo-seo',
            [ $this, 'render_page' ],
            'dashicons-superhero',
            58
        );
    }

    /**
     * Render the admin page shell. React mounts into #aiwoo-admin-root.
     *
     * WordPress outputs .notice divs inside .wrap (via the admin_notices hook)
     * which fires before our content. #aiwoo-admin-root is a separate sibling
     * so WP notices appear above our plugin panel, never inside it.
     */
    public function render_page(): void {
        ?>
        <div class="wrap">
            <h1 class="screen-reader-text"><?php esc_html_e( 'CoderEmbassy AI SEO Automation', 'ai-woo-seo' ); ?></h1>
        </div>
        <div id="aiwoo-admin-root"></div>
        <?php
    }
}
