<?php

if (!defined('ABSPATH')) {
	exit;
}

if (class_exists('Writesonic_Migration')) {
	return;
}

/**
 * Takes over from the standalone "Writesonic AI Analytics" plugin.
 *
 * That plugin deletes its own API key from its **deactivation** hook, so the
 * obvious upgrade instruction — deactivate the old one, then install this —
 * destroys the customer's key. Everything here exists to make sure the key is
 * copied before anything deactivates, whatever order the user works in.
 *
 * The legacy options are only ever read. This plugin writes to its own keys, so
 * removing the old plugin later cannot take this plugin's key with it.
 */
class Writesonic_Migration
{
	const LEGACY_KEY_OPTION     = 'writesonic_analytics_api_key';
	const LEGACY_ENABLED_OPTION = 'writesonic_analytics_enabled';
	const LEGACY_PLUGIN_NAME    = 'Writesonic AI Analytics';
	const LEGACY_PLUGIN_FILE    = 'writesonic-ai-analytics.php';

	const MIGRATED_OPTION        = 'writesonic_migration_version';
	const NOTICE_DISMISSED_OPTION = 'writesonic_legacy_notice_dismissed';

	/**
	 * Skipped on front-end requests only: `get_plugins()` scans the plugin
	 * directory, and there is nothing to migrate there.
	 *
	 * WP-CLI is explicitly included. It does not define `WP_ADMIN`, but
	 * `wp plugin deactivate` still fires the legacy plugin's deactivation hook —
	 * which deletes the key. Hosts and agencies deactivate this way routinely,
	 * so bailing here would lose the key in the one case the hook exists for.
	 *
	 * The deactivation hook runs at priority 1, ahead of the legacy plugin's own
	 * at the default 10. The `admin_init` sweep is the reliable path: activation
	 * order is not controllable and, on multisite, activation fires for a single
	 * site only.
	 */
	public static function boot()
	{
		$is_cli = defined('WP_CLI') && WP_CLI;

		if (!is_admin() && !$is_cli) {
			return;
		}

		$legacy = self::legacy_plugin_basename();

		if ($legacy) {
			add_action('deactivate_' . $legacy, array(__CLASS__, 'copy_forward'), 1);
		}

		if (!$is_cli) {
			add_action('admin_init', array(__CLASS__, 'copy_forward'));
		}
	}

	public static function on_activate()
	{
		self::copy_forward();
	}

	/**
	 * Detected by the constant rather than a file path: the legacy plugin ships
	 * as a hand-uploaded zip, so its directory name is not guaranteed.
	 */
	public static function legacy_plugin_active()
	{
		return defined('WRITESONIC_ANALYTICS_VERSION');
	}

	public static function legacy_plugin_basename()
	{
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach (get_plugins() as $basename => $plugin) {
			if (self::LEGACY_PLUGIN_NAME === $plugin['Name']) {
				return $basename;
			}

			if (self::LEGACY_PLUGIN_FILE === basename($basename)) {
				return $basename;
			}
		}

		return '';
	}

	public static function has_legacy_settings()
	{
		return false !== get_option(self::LEGACY_KEY_OPTION, false);
	}

	/**
	 * Copies the legacy API key into this plugin's own option. Idempotent, and
	 * never destructive: the legacy value is left where it is so a rollback to
	 * the standalone plugin still works.
	 */
	/**
	 * Deliberately guarded on the destination being empty rather than on a
	 * "already migrated" flag. A flag set by an early run that had nothing to copy —
	 * the merged plugin installed before the user pasted a key into the old one —
	 * would make this a no-op at the one moment it has to work, the legacy
	 * plugin's deactivation.
	 *
	 * Tracking is carried over rather than defaulted on, so a site that was
	 * collecting keeps collecting and a site that never was does not start.
	 */
	public static function copy_forward()
	{
		if ('' !== Writesonic_Connection::analytics_key()) {
			return;
		}

		$legacy_key = get_option(self::LEGACY_KEY_OPTION, '');

		if (empty($legacy_key)) {
			return;
		}

		update_option(Writesonic_Connection::ANALYTICS_KEY_OPTION, sanitize_text_field($legacy_key));

		if (get_option(self::LEGACY_ENABLED_OPTION, false)) {
			update_option(Writesonic_Connection::TRACKING_ENABLED_OPTION, true);
		}

		if (!get_option(Writesonic_Connection::HOME_URL_OPTION, '')) {
			update_option(Writesonic_Connection::HOME_URL_OPTION, home_url());
		}

		update_option(self::MIGRATED_OPTION, WRITESONIC_VERSION);
	}

	/**
	 * `key_lost` is the deactivate-first path: the old plugin is gone and took
	 * its key with it, so there is nothing left to copy forward.
	 */
	public static function notice_state()
	{
		if (get_option(self::NOTICE_DISMISSED_OPTION, false)) {
			return '';
		}

		if (self::legacy_plugin_active()) {
			return 'conflict';
		}

		if (self::has_legacy_settings() && '' === Writesonic_Connection::analytics_key()) {
			return 'key_lost';
		}

		return '';
	}

	public static function render_notice()
	{
		$state = self::notice_state();

		if ('' === $state || !current_user_can('activate_plugins')) {
			return;
		}

		if ('key_lost' === $state) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong><br>%s</p><p><a href="%s" class="button">%s</a></p></div>',
				esc_html__('Writesonic: your analytics API key is missing', 'writesonic'),
				esc_html__('The standalone Writesonic AI Analytics plugin deletes its API key when deactivated, and we could not recover it. Reconnect this site to start collecting data again.', 'writesonic'),
				esc_url(admin_url('options-general.php?page=writesonic&tab=analytics')),
				esc_html__('Open Writesonic settings', 'writesonic')
			);

			return;
		}

		$deactivate_url = wp_nonce_url(
			admin_url('admin-post.php?action=writesonic_deactivate_legacy'),
			'writesonic_deactivate_legacy'
		);

		printf(
			'<div class="notice notice-info"><p><strong>%s</strong><br>%s<br>%s</p><p><a href="%s" class="button button-primary">%s</a> <a href="%s" class="button">%s</a></p></div>',
			esc_html__('Writesonic: finish your analytics migration', 'writesonic'),
			esc_html__('Writesonic AI Analytics is now built into this plugin. Your API key has been copied over — there is nothing to re-enter.', 'writesonic'),
			esc_html__('Analytics is paused while both plugins are active, so your traffic is not counted twice. Remove the old plugin to resume.', 'writesonic'),
			esc_url($deactivate_url),
			esc_html__('Deactivate the old plugin', 'writesonic'),
			esc_url(wp_nonce_url(admin_url('admin-post.php?action=writesonic_dismiss_legacy_notice'), 'writesonic_dismiss_legacy_notice')),
			esc_html__('Dismiss', 'writesonic')
		);
	}

	/**
	 * User-initiated, and refuses unless the key is safely copied first —
	 * deactivating is what destroys it. Deactivation is also allowed when the old
	 * plugin never held a key, since there is then nothing to lose.
	 */
	public static function handle_deactivate_legacy()
	{
		if (!current_user_can('activate_plugins')) {
			wp_die(esc_html__('You are not allowed to deactivate plugins.', 'writesonic'));
		}

		check_admin_referer('writesonic_deactivate_legacy');

		self::copy_forward();

		$basename = self::legacy_plugin_basename();

		$key_is_safe = '' !== Writesonic_Connection::analytics_key()
			|| '' === (string) get_option(self::LEGACY_KEY_OPTION, '');

		if ($basename && $key_is_safe) {
			if (!function_exists('deactivate_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			deactivate_plugins($basename);
		}

		wp_safe_redirect(admin_url('options-general.php?page=writesonic&tab=analytics'));
		exit;
	}

	public static function handle_dismiss_notice()
	{
		if (!current_user_can('activate_plugins')) {
			wp_die(esc_html__('You are not allowed to do that.', 'writesonic'));
		}

		check_admin_referer('writesonic_dismiss_legacy_notice');

		update_option(self::NOTICE_DISMISSED_OPTION, true);

		wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
		exit;
	}
}
