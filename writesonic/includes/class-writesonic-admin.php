<?php

if (!defined('ABSPATH')) {
	exit;
}

if (class_exists('Writesonic_Admin')) {
	return;
}

class Writesonic_Admin
{
	const PAGE_SLUG        = 'writesonic';
	const LEGACY_PAGE_SLUG = 'writesonic-ai-analytics';

	public static function init()
	{
		add_action('admin_menu', array(__CLASS__, 'register_menu'));
		add_action('admin_init', array(__CLASS__, 'redirect_legacy_page'));
		add_action('admin_notices', array('Writesonic_Migration', 'render_notice'));

		add_action('admin_post_writesonic_connect', array(__CLASS__, 'handle_connect'));
		add_action('admin_post_writesonic_disconnect', array(__CLASS__, 'handle_disconnect'));
		add_action('admin_init', array(__CLASS__, 'maybe_claim_analytics_key'));
		add_action('admin_post_writesonic_refresh_analytics', array(__CLASS__, 'handle_refresh_analytics'));
		add_action('admin_post_writesonic_save_analytics_key', array(__CLASS__, 'handle_save_analytics_key'));
		add_action('admin_post_writesonic_disconnect_analytics', array(__CLASS__, 'handle_disconnect_analytics'));
		add_action('admin_post_writesonic_toggle_analytics', array(__CLASS__, 'handle_toggle_analytics'));
		add_action('admin_post_writesonic_deactivate_legacy', array('Writesonic_Migration', 'handle_deactivate_legacy'));
		add_action('admin_post_writesonic_dismiss_legacy_notice', array('Writesonic_Migration', 'handle_dismiss_notice'));

		add_filter('plugin_action_links_' . plugin_basename(WRITESONIC_FILE), array(__CLASS__, 'add_settings_link'));
	}

	public static function register_menu()
	{
		add_options_page(
			__('Writesonic Settings', 'writesonic'),
			'Writesonic',
			'manage_options',
			self::PAGE_SLUG,
			array(__CLASS__, 'render_page')
		);
	}

	/**
	 * The standalone plugin's settings URL is in support docs, saved bookmarks
	 * and the app's install instructions. Without this it 404s as "not allowed
	 * to access this page". While that plugin is still active it owns the screen,
	 * so the redirect stands down.
	 */
	public static function redirect_legacy_page()
	{
		if (!isset($_GET['page']) || self::LEGACY_PAGE_SLUG !== sanitize_key(wp_unslash($_GET['page']))) {
			return;
		}

		if (Writesonic_Migration::legacy_plugin_active()) {
			return;
		}

		wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=analytics'));
		exit;
	}

	public static function add_settings_link($links)
	{
		array_unshift($links, sprintf(
			'<a href="%s">%s</a>',
			esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)),
			esc_html__('Settings', 'writesonic')
		));

		return $links;
	}

	public static function current_tab()
	{
		$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'publishing';

		return in_array($tab, array('publishing', 'analytics'), true) ? $tab : 'publishing';
	}

	public static function tab_url($tab)
	{
		return admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=' . $tab);
	}

	public static function render_page()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'writesonic'));
		}

		include WRITESONIC_DIR . 'templates/settings.php';
	}

	public static function handle_connect()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to connect this site.', 'writesonic'));
		}

		check_admin_referer('writesonic_connect');

		wp_redirect(Writesonic_Connection::build_connect_url());
		exit;
	}

	/**
	 * Writesonic returns the admin here carrying a single-use code after they
	 * approve the connection. No nonce is possible on an inbound link from
	 * another origin, so this is gated on the capability instead, and the code
	 * is worthless without this site's token.
	 */
	public static function maybe_claim_analytics_key()
	{
		if (empty($_GET['writesonic_code']) || !current_user_can('manage_options')) {
			return;
		}

		$code = sanitize_text_field(wp_unslash($_GET['writesonic_code']));
		$claimed = Writesonic_Connection::claim_analytics_key($code);

		wp_safe_redirect(add_query_arg(
			'writesonic_claim',
			$claimed ? 'ok' : 'failed',
			self::tab_url('analytics')
		));
		exit;
	}

	public static function handle_disconnect_analytics()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do that.', 'writesonic'));
		}

		check_admin_referer('writesonic_disconnect_analytics');

		Writesonic_Connection::disconnect_analytics();

		wp_safe_redirect(self::tab_url('analytics'));
		exit;
	}

	public static function handle_save_analytics_key()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do that.', 'writesonic'));
		}

		check_admin_referer('writesonic_save_analytics_key');

		$key = isset($_POST['analytics_key'])
			? sanitize_text_field(wp_unslash($_POST['analytics_key']))
			: '';

		if ('' !== $key) {
			Writesonic_Connection::save_analytics_key($key);
		}

		wp_safe_redirect(self::tab_url('analytics'));
		exit;
	}

	/**
	 * The manual route to the analytics key, for sites Writesonic cannot reach
	 * inbound and installs old enough to have no config endpoint.
	 */
	public static function handle_refresh_analytics()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do that.', 'writesonic'));
		}

		check_admin_referer('writesonic_refresh_analytics');

		Writesonic_Connection::refresh_state();

		wp_safe_redirect(self::tab_url('analytics'));
		exit;
	}

	public static function handle_toggle_analytics()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do that.', 'writesonic'));
		}

		check_admin_referer('writesonic_toggle_analytics');

		Writesonic_Connection::set_paused(!Writesonic_Connection::is_paused());

		wp_safe_redirect(self::tab_url('analytics'));
		exit;
	}

	public static function handle_disconnect()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to disconnect this site.', 'writesonic'));
		}

		check_admin_referer('writesonic_disconnect');

		$token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

		Writesonic_Connection::disconnect($token);

		wp_safe_redirect(self::tab_url('publishing'));
		exit;
	}
}
