<?php

namespace CoderEmbassy\AiSeoAutomation\Database\Migrations;

use CoderEmbassy\AiSeoAutomation\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Migration001CreateJobsTable {

    public function up(): void {
        global $wpdb;
        $table = Schema::tableName( Schema::JOBS );
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            user_id bigint(20) unsigned DEFAULT NULL,
            rule_id bigint(20) unsigned DEFAULT NULL,
            options longtext,
            total_items int(11) NOT NULL DEFAULT 0,
            done_items int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY user_id (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function down(): void {
        global $wpdb;
        $table = Schema::tableName( Schema::JOBS );
        $sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table );
        if ( is_string( $sql ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Migrations must execute DDL directly.
            $wpdb->query( $sql );
        }
    }
}
