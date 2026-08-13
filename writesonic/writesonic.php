<?php

/**
 * Plugin Name: Writesonic
 * Description: Publish content from Writesonic to your site and measure how AI crawlers read it.
 * Version: 2.0.0
 * Author: Writesonic
 * Author URI: https://writesonic.com/
 * Text Domain: writesonic
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
	exit;
}

if (version_compare(PHP_VERSION, '7.4', '<')) {
	add_action('admin_notices', function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__('Writesonic requires PHP 7.4 or newer. The plugin is inactive.', 'writesonic')
		);
	});

	return;
}

if (!defined('WRITESONIC_VERSION')) {
	define('WRITESONIC_VERSION', '2.0.0');
}
if (!defined('WRITESONIC_FILE')) {
	define('WRITESONIC_FILE', __FILE__);
}
if (!defined('WRITESONIC_DIR')) {
	define('WRITESONIC_DIR', plugin_dir_path(__FILE__));
}
if (!defined('WRITESONIC_URL')) {
	define('WRITESONIC_URL', plugin_dir_url(__FILE__));
}

/**
 * Publishing token store. Shape is frozen: an array of `user_email => token`.
 * knowledge-and-integration-hub authenticates every `/writesonic/v2/*` call
 * against these values, so existing tokens must keep validating forever.
 */
if (!defined('WRITESONIC_API_KEY_OPTION')) {
	define('WRITESONIC_API_KEY_OPTION', 'writesonic_api_key');
}

if (!defined('WRITESONIC_CONNECT_URL')) {
	define('WRITESONIC_CONNECT_URL', 'https://app.writesonic.com/wordpress-authentication/');
}

if (!defined('WRITESONIC_STATE_URL')) {
	define('WRITESONIC_STATE_URL', 'https://api.writesonic.com/v1/thirdparty/wordpress-connection-state');
}

if (!defined('WRITESONIC_CLAIM_URL')) {
	define('WRITESONIC_CLAIM_URL', 'https://api.writesonic.com/v1/thirdparty/wordpress-analytics-claim');
}

if (!defined('WRITESONIC_DEFAULT_INGESTION_DOMAIN')) {
	define('WRITESONIC_DEFAULT_INGESTION_DOMAIN', 'https://ingestion.writesonic.com');
}

require_once WRITESONIC_DIR . 'includes/class-writesonic-rest.php';
require_once WRITESONIC_DIR . 'includes/class-writesonic-connection.php';
require_once WRITESONIC_DIR . 'includes/class-writesonic-analytics.php';
require_once WRITESONIC_DIR . 'includes/class-writesonic-migration.php';
require_once WRITESONIC_DIR . 'includes/class-writesonic-admin.php';

add_action('plugins_loaded', function () {
	load_plugin_textdomain('writesonic', false, dirname(plugin_basename(WRITESONIC_FILE)) . '/languages');
});

/**
 * Analytics is decided at `init` rather than at load time: the legacy plugin
 * registers its own interceptor on `init` too, so by this point every plugin
 * file is loaded and the conflict probe is authoritative regardless of the
 * order WordPress activated them in.
 */
add_action('init', array('Writesonic_Migration', 'boot'), 0);
add_action('init', array('Writesonic_Analytics', 'boot'), 1);

WPM_Writesonic_Integration::init();
Writesonic_Connection::init();
Writesonic_Admin::init();

register_activation_hook(__FILE__, array('Writesonic_Migration', 'on_activate'));
register_deactivation_hook(__FILE__, array('WPM_Writesonic_Integration', 'deactivation'));
