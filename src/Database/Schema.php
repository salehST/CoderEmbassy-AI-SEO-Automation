<?php

namespace CoderEmbassy\AiSeoAutomation\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Database table name constants.
 * Never hardcode wp_ prefix — use tableName().
 */
class Schema {

    const JOBS      = 'ai_seo_jobs';
    const JOB_ITEMS = 'ai_seo_job_items';
    const AUDIT     = 'ai_seo_audit';
    const RULES     = 'ai_seo_rules';

    /**
     * Get full table name with prefix.
     * In multisite, $wpdb->prefix is already site-specific after switch_to_blog().
     *
     * @param string $constant One of the Schema constants (e.g. Schema::JOBS).
     * @return string
     */
    public static function tableName( string $constant ): string {
        global $wpdb;
        return $wpdb->prefix . $constant;
    }

    /**
     * Whether the current WordPress installation is a multisite network.
     *
     * @return bool
     */
    public static function isMultisite(): bool {
        return is_multisite();
    }
}
