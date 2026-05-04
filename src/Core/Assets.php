<?php

namespace CoderEmbassy\AiSeoAutomation\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues built React SPA assets on the plugin admin page.
 */
class Assets {

    public function register_hooks(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_filter( 'script_loader_tag', [ $this, 'add_module_type' ], 10, 2 );
    }

    /**
     * Add type="module" to the admin SPA script tag so ES module imports work.
     *
     * @param string $tag    The full <script> HTML tag.
     * @param string $handle Registered script handle.
     * @return string
     */
    public function add_module_type( string $tag, string $handle ): string {
        if ( $handle === 'ce-ai-seo-admin' ) {
            return str_replace( ' src=', ' type="module" src=', $tag );
        }
        return $tag;
    }

    /**
     * Enqueue JS and CSS only on the plugin's own admin page.
     *
     * @param string $hook_suffix Current admin page hook.
     */
    public function enqueue_admin_assets( string $hook_suffix ): void {
        if ( ! $this->is_plugin_page( $hook_suffix ) ) {
            return;
        }

        $dist_dir = plugin_dir_path( CE_AI_SEO_PLUGIN_FILE ) . 'dist/';
        $dist_url = plugin_dir_url( CE_AI_SEO_PLUGIN_FILE ) . 'dist/';

        // Only enqueue if the built files exist
        if ( ! file_exists( $dist_dir . 'main.js' ) ) {
            return;
        }

        if ( file_exists( $dist_dir . 'main.css' ) ) {
            wp_enqueue_style(
                'ce-ai-seo-admin',
                $dist_url . 'main.css',
                [],
                CE_AI_SEO_VERSION
            );
        }

        if ( file_exists( $dist_dir . 'load-style.css' ) ) {
            wp_enqueue_style(
                'ce-ai-seo-load-style',
                $dist_url . 'load-style.css',
                [],
                CE_AI_SEO_VERSION
            );
        }

        wp_enqueue_script(
            'ce-ai-seo-admin',
            $dist_url . 'main.js',
            [],
            CE_AI_SEO_VERSION,
            true
        );

        $current_user = wp_get_current_user();
        $display_name = $current_user->display_name ?: $current_user->user_login ?: 'User';
        $initial      = strtoupper( mb_substr( $display_name, 0, 1 ) );

        $tier           = defined( 'CE_AI_SEO_TIER' ) ? CE_AI_SEO_TIER : 'free';
        $licenseEnabled = in_array( $tier, [ 'pro', 'scale' ], true );

        // Pass WordPress REST API data to the SPA via window.CeAiSeo
        wp_localize_script( 'ce-ai-seo-admin', 'CeAiSeo', [
            'root'        => esc_url_raw( rest_url() ),
            'wcRoot'      => esc_url_raw( rest_url( 'wc/v3/' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'version'     => CE_AI_SEO_VERSION,
            'tier'           => $tier,
            'licenseEnabled' => $licenseEnabled,
            'pricingUrl'     => defined( 'CE_AI_SEO_STORE_URL' ) ? CE_AI_SEO_STORE_URL . '/pricing' : '',
            'logoDark'    => esc_url( $dist_url . 'logo-dark.png' ),
            'logoLight'   => esc_url( $dist_url . 'logo-light.png' ),
            'userName'    => esc_html( $display_name ),
            'userInitial' => esc_html( $initial ),
        ] );

        // Remove padding from #wpcontent to prevent it from clipping the full-width SPA
        wp_register_style( 'ce-ai-seo-admin-inline', false );
        wp_enqueue_style( 'ce-ai-seo-admin-inline' );
        wp_add_inline_style( 'ce-ai-seo-admin-inline', '#wpcontent { padding-left: 0 !important; }' );
    }

    /**
     * Check if the current admin page belongs to this plugin.
     *
     * @param string $hook_suffix
     * @return bool
     */
    private function is_plugin_page( string $hook_suffix ): bool {
        return str_contains( $hook_suffix, 'ce-ai-seo' );
    }
}
