<?php

namespace AiWooSeo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation, deactivation, and capability checks.
 */
class Activator {

    /**
     * Register activation and deactivation hooks.
     */
    public function register_hooks(): void {
        register_activation_hook( AIWOO_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( AIWOO_PLUGIN_FILE, [ $this, 'deactivate' ] );
    }

    /**
     * Run on plugin activation.
     *
     * When multisite and network admin, runs migrations for every site in the
     * network. Otherwise runs migrations for the current site only.
     */
    public function activate(): void {
        if ( is_multisite() && is_network_admin() ) {
            foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blogId ) {
                switch_to_blog( (int) $blogId );
                try {
                    ( new \AiWooSeo\Database\MigrationManager() )->run_pending();
                } finally {
                    restore_current_blog();
                }
            }
        } else {
            ( new \AiWooSeo\Database\MigrationManager() )->run_pending();
        }
    }

    /**
     * Run on plugin deactivation.
     * Clears cron hooks only — no data removal.
     */
    public function deactivate(): void {
        $this->clear_cron_hooks();
    }

    /**
     * Clear all plugin cron hooks.
     */
    private function clear_cron_hooks(): void {
        wp_clear_scheduled_hook( 'aiwoo_queue_runner' );
    }
}
