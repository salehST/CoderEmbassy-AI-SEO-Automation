<?php

namespace AiWooSeo\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Version-tracked migration runner.
 */
class MigrationManager {

    private const OPTION_VERSION = 'aiwoo_db_version';

    /**
     * Run all pending migrations.
     */
    public function run_pending(): void {
        $current    = (int) get_option( self::OPTION_VERSION, 0 );
        $migrations = $this->get_migrations();

        foreach ( $migrations as $version => $migration ) {
            if ( $version > $current ) {
                $migration->up();
                update_option( self::OPTION_VERSION, $version );
            }
        }

        // Always run dbDelta on the rules table to repair column mismatches.
        // dbDelta is safe to run repeatedly — it only makes additive changes.
        $this->repair_tables();
    }

    private function repair_tables(): void {
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        ( new \AiWooSeo\Database\Migrations\Migration005CreateRulesTable() )->up();
    }

    /**
     * Get ordered migrations.
     *
     * @return array<int, object> Version => migration instance.
     */
    private function get_migrations(): array {
        $base_path = plugin_dir_path( AIWOO_PLUGIN_FILE ) . 'migrations/';

        return [
            1 => new \AiWooSeo\Database\Migrations\Migration001CreateJobsTable(),
            2 => new \AiWooSeo\Database\Migrations\Migration002CreateJobItemsTable(),
            3 => new \AiWooSeo\Database\Migrations\Migration003CreateAuditTable(),
            4 => new \AiWooSeo\Database\Migrations\Migration004CreateSettingsTable(),
            5 => new \AiWooSeo\Database\Migrations\Migration005CreateRulesTable(),
        ];
    }
}
