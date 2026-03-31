<?php

namespace CoderEmbassy\AiSeoAutomation\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Creates plugin settings table for key-value storage.
 * Uses ai_seo_settings — not in Schema constants as settings are primarily in wp_options.
 */
class Migration004CreateSettingsTable {

    private const TABLE = 'ai_seo_settings';

    public function up(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            option_name varchar(191) NOT NULL,
            option_value longtext,
            autoload varchar(20) NOT NULL DEFAULT 'yes',
            PRIMARY KEY  (id),
            UNIQUE KEY option_name (option_name)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function down(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }
}
