<?php

namespace CoderEmbassy\AiSeoAutomation\Database\Migrations;

use CoderEmbassy\AiSeoAutomation\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Migration003CreateAuditTable {

    public function up(): void {
        global $wpdb;
        $table = Schema::tableName( Schema::AUDIT );
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            field_name varchar(100) NOT NULL,
            old_value longtext,
            new_value longtext,
            event_type varchar(50) NOT NULL DEFAULT 'apply',
            changed_by bigint(20) unsigned DEFAULT NULL,
            job_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY job_id (job_id),
            KEY event_type (event_type)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dbDelta() executes schema SQL.
    }

    public function down(): void {
        global $wpdb;
        $table = Schema::tableName( Schema::AUDIT );
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema operation, no user input.
    }
}
