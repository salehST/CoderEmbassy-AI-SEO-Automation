<?php

namespace CoderEmbassy\AiSeoAutomation\Admin;

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
            __( 'CoderEmbassy AI SEO Automation', 'coderembassy-ai-seo-automation' ),
            __( 'AI SEO', 'coderembassy-ai-seo-automation' ),
            'manage_woocommerce',
            'ce-ai-seo',
            [ $this, 'render_page' ],
            'dashicons-superhero',
            58
        );
    }

    /**
     * Render the admin page shell. React mounts into #ce-ai-seo-admin-root.
     *
     * WordPress outputs .notice divs inside .wrap (via the admin_notices hook)
     * which fires before our content. #ce-ai-seo-admin-root is a separate sibling
     * so WP notices appear above our plugin panel, never inside it.
     */
    public function render_page(): void {
        ?>
        <div class="wrap">
            <h1 class="screen-reader-text"><?php esc_html_e( 'CoderEmbassy AI SEO Automation', 'coderembassy-ai-seo-automation' ); ?></h1>
        </div>
        <div id="ce-ai-seo-admin-root"></div>
        <?php
    }
}
