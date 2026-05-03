<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * When a plugin is uninstalled from the WordPress admin dashboard, this file
 * is called. It handles the cleanup of all plugin data (database tables and options).
 *
 * @link       https://coderembassy.com/
 * @since      1.0.0
 *
 * @package    CoderEmbassy\AiSeoAutomation
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

define( 'CE_AI_SEO_TIER', 'free' );
define( 'CE_AI_SEO_PLUGIN_FILE', dirname( __FILE__ ) . '/coderembassy-ai-seo-automation.php' );

// Load autoloader to access MigrationManager
if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__ ) . '/vendor/autoload.php';
}

/**
 * Perform cleanup.
 */
function ce_ai_seo_uninstall_cleanup() {
	global $wpdb;

	// 1. Drop Database Tables
	$migrationManager = new \CoderEmbassy\AiSeoAutomation\Database\MigrationManager();
	$migrationManager->rollback_all();

	// 2. Delete Options
	$options = [
		'ce_ai_seo_provider',
		'ce_ai_seo_openai_key',
		'ce_ai_seo_anthropic_key',
		'ce_ai_seo_groq_key',
		'ce_ai_seo_gemini_key',
		'ce_ai_seo_openai_model',
		'ce_ai_seo_anthropic_model',
		'ce_ai_seo_groq_model',
		'ce_ai_seo_gemini_model',
		'ce_ai_seo_onboarding_complete',
		'ce_ai_seo_autopilot_mode',
		'ce_ai_seo_db_version',
		'ce_ai_seo_tier',
		'ce_ai_seo_license_key',
		'ce_ai_seo_license_status',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// 3. Delete Usage Stats (wildcard options)
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ce_ai_seo_usage_%'" );
}

if ( is_multisite() ) {
	$blog_ids = get_sites( [ 'fields' => 'ids' ] );
	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( $blog_id );
		ce_ai_seo_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	ce_ai_seo_uninstall_cleanup();
}
