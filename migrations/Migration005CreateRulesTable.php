<?php

namespace AiWooSeo\Database\Migrations;

use AiWooSeo\Database\Schema;

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
        dbDelta( $sql );
    }

    public function down(): void {
        global $wpdb;
        $table = Schema::tableName( Schema::RULES );
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }
}
