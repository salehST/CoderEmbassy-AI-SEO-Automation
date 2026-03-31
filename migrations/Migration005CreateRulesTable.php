<?php

namespace CoderEmbassy\AiSeoAutomation\Database\Migrations;

use CoderEmbassy\AiSeoAutomation\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Migration005CreateRulesTable {

    public function up(): void {
        global $wpdb;
        $table   = Schema::tableName( Schema::RULES );
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            description text DEFAULT NULL,
            brand varchar(191) DEFAULT NULL,
            language varchar(10) NOT NULL DEFAULT 'en',
            tone varchar(50) DEFAULT NULL,
            prompt_template longtext DEFAULT NULL,
            category_ids text DEFAULT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_is_default (is_default)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dbDelta() executes schema SQL.
    }

    public function down(): void {
        global $wpdb;
        $table = Schema::tableName( Schema::RULES );
        $sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table );
        if ( is_string( $sql ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Migrations must execute DDL directly.
            $wpdb->query( $sql );
        }
    }
}
