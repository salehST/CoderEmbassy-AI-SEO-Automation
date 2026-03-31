<?php

/**
 * Plugin Name: CoderEmbassy AI SEO Automation
 * Description: AI-powered SEO title, meta description & schema generation for products.
 * Plugin URI:  https://github.com/salehST/CoderEmbassy-AI-SEO-Automation
 * Version:     1.0.0
 * Author:      codersaleh
 * Author URI:  https://coderembassy.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coderembassy-ai-seo-automation
 * Tested up to: 6.9
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 * WC Tested up to: 12.0
 * Requires Plugins: WooCommerce
 */

defined('ABSPATH') || exit;

define('CE_AI_SEO_TIER',        'free');
define('CE_AI_SEO_PLUGIN_FILE', __FILE__);
define('CE_AI_SEO_VERSION',     '1.0.0');
define('CE_AI_SEO_STORE_URL',   'https://plugin.coderembassy.com');

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

register_activation_hook(__FILE__, [new \CoderEmbassy\AiSeoAutomation\Core\Activator(), 'activate']);
register_deactivation_hook(__FILE__, [new \CoderEmbassy\AiSeoAutomation\Core\Activator(), 'deactivate']);

add_action('plugins_loaded', [\CoderEmbassy\AiSeoAutomation\Core\Plugin::class, 'boot']);

// Protect AI-written Yoast meta from being deleted by Yoast's default-value cleanup
add_filter('delete_post_metadata', function ($check, $object_id, $meta_key) {
    if (! in_array($meta_key, ['_yoast_wpseo_title', '_yoast_wpseo_metadesc'], true)) {
        return $check;
    }
    $ai_key = ($meta_key === '_yoast_wpseo_title') ? '_ce_ai_seo_seo_title' : '_ce_ai_seo_seo_meta';
    return get_post_meta($object_id, $ai_key, true) ? false : $check;
}, 1, 3);
