<?php

namespace CoderEmbassy\AiSeoAutomation\Database\Migrations;

use CoderEmbassy\AiSeoAutomation\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Migration002CreateJobItemsTable {

    public function up(): void {
        global $wpdb;
        $table   = Schema::tableName( Schema::JOB_ITEMS );
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            preview longtext,
            diff longtext,
            attempts int(11) NOT NULL DEFAULT 0,
            last_error text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY product_id (product_id),
            KEY status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function down(): void {
        global $wpdb;
        $table = Schema::tableName( Schema::JOB_ITEMS );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name is internal/known; schema changes are expected in migrations.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }
}
