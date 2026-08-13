<?php

/**
 * Removes this plugin's own settings. Deactivating deliberately leaves
 * everything in place — only an explicit delete clears credentials.
 *
 * The standalone analytics plugin's options are left untouched: they belong to
 * a different plugin, and deleting them would break a rollback to it.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$writesonic_options = array(
	'writesonic_api_key',
	'writesonic_site_token',
	'writesonic_analytics_key',
	'writesonic_analytics_tracking_enabled',
	'writesonic_analytics_project_id',
	'writesonic_ingestion_domain',
	'writesonic_site_home_url',
	'writesonic_analytics_paused',
	'writesonic_analytics_disconnected',
	'writesonic_migration_version',
	'writesonic_legacy_notice_dismissed',
);

$writesonic_transients = array(
	'writesonic_connection_state',
	'writesonic_analytics_backoff',
	'writesonic_analytics_last_send',
);

$writesonic_cleanup = function () use ($writesonic_options, $writesonic_transients) {
	foreach ($writesonic_options as $option) {
		delete_option($option);
	}

	foreach ($writesonic_transients as $transient) {
		delete_transient($transient);
	}
};

if (is_multisite()) {
	foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $writesonic_site_id) {
		switch_to_blog($writesonic_site_id);
		$writesonic_cleanup();
		restore_current_blog();
	}
} else {
	$writesonic_cleanup();
}
