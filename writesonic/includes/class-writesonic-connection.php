<?php

if (!defined('ABSPATH')) {
	exit;
}

if (class_exists('Writesonic_Connection')) {
	return;
}

/**
 * Owns the site's link to Writesonic.
 *
 * Connecting is WordPress-initiated: the admin clicks Connect, the plugin mints
 * a site token and hands it to the app, and the app provisions publishing and
 * analytics together.
 *
 * The analytics key reaches the site by whichever of three routes works first,
 * so no install can dead-end waiting for the others:
 *
 * 1. Writesonic pushes it to `/writesonic/v2/analytics-config` right after
 *    provisioning — instant, and its success proves the plugin is installed.
 * 2. This class polls `POST /v1/thirdparty/wordpress-connection-state` on
 *    `admin_init`, for sites Writesonic cannot reach inbound.
 * 3. The admin refreshes by hand from the AI Analytics tab.
 */
class Writesonic_Connection
{
	const SITE_TOKEN_OPTION      = 'writesonic_site_token';
	const ANALYTICS_KEY_OPTION   = 'writesonic_analytics_key';
	const TRACKING_ENABLED_OPTION = 'writesonic_analytics_tracking_enabled';
	const PROJECT_ID_OPTION      = 'writesonic_analytics_project_id';
	const INGESTION_DOMAIN_OPTION = 'writesonic_ingestion_domain';
	const HOME_URL_OPTION        = 'writesonic_site_home_url';
	const PAUSED_OPTION          = 'writesonic_analytics_paused';
	const DISCONNECTED_OPTION    = 'writesonic_analytics_disconnected';

	const STATE_TRANSIENT = 'writesonic_connection_state';

	public static function init()
	{
		add_action('admin_init', array(__CLASS__, 'maybe_refresh_state'));
	}

	/**
	 * The token the state endpoint resolves us by. Installs that connected before
	 * 2.0.0 have no dedicated option, so fall back to the publishing token store.
	 */
	public static function site_token()
	{
		$token = get_option(self::SITE_TOKEN_OPTION, '');

		if (!empty($token)) {
			return $token;
		}

		$tokens = get_option(WRITESONIC_API_KEY_OPTION, array());

		if (is_array($tokens) && !empty($tokens)) {
			return (string) reset($tokens);
		}

		return '';
	}

	public static function is_connected()
	{
		return '' !== self::site_token();
	}

	public static function analytics_key()
	{
		return (string) get_option(self::ANALYTICS_KEY_OPTION, '');
	}

	public static function ingestion_domain()
	{
		$domain = get_option(self::INGESTION_DOMAIN_OPTION, '');

		return $domain ? $domain : WRITESONIC_DEFAULT_INGESTION_DOMAIN;
	}

	public static function is_tracking_enabled()
	{
		return (bool) get_option(self::TRACKING_ENABLED_OPTION, false);
	}

	/**
	 * Local override, owned by the site admin. Kept separate from the server's
	 * grant so a later config push cannot silently resume collection the admin
	 * chose to stop.
	 */
	public static function is_paused()
	{
		return (bool) get_option(self::PAUSED_OPTION, false);
	}

	public static function set_paused($paused)
	{
		update_option(self::PAUSED_OPTION, (bool) $paused);
	}

	/**
	 * Applies an analytics configuration pushed by Writesonic. Same shape the
	 * state endpoint returns, so both routes converge on one code path.
	 */
	public static function apply_analytics_config(array $config)
	{
		delete_option(self::DISCONNECTED_OPTION);

		self::apply_state(array(
			'connected'        => true,
			'capabilities'     => array('analytics' => !empty($config['enabled'])),
			'analytics_key'    => isset($config['analytics_key']) ? $config['analytics_key'] : '',
			'project_id'       => isset($config['project_id']) ? $config['project_id'] : '',
			'ingestion_domain' => isset($config['ingestion_domain']) ? $config['ingestion_domain'] : '',
		));

		delete_transient(self::STATE_TRANSIENT);
	}

	/**
	 * True once the admin has disconnected analytics here. Writesonic keeps the
	 * binding either way, so without this the next state poll would hand the key
	 * straight back and collection would silently resume.
	 */
	public static function is_analytics_disconnected()
	{
		return (bool) get_option(self::DISCONNECTED_OPTION, false);
	}

	/**
	 * Mints a fresh site token for the current user and returns the app URL to
	 * hand off to. `caps` tells the app which capabilities to offer; the user
	 * confirms or opts out there.
	 *
	 * The query string is built explicitly because `add_query_arg()` does not
	 * encode its values, and an unencoded `https://` domain would not survive.
	 */
	public static function build_connect_url()
	{
		$user  = wp_get_current_user();
		$token = bin2hex(random_bytes(16));

		$tokens = get_option(WRITESONIC_API_KEY_OPTION, array());

		if (!is_array($tokens)) {
			$tokens = array();
		}

		$tokens[$user->user_email] = $token;

		update_option(WRITESONIC_API_KEY_OPTION, $tokens);
		update_option(self::SITE_TOKEN_OPTION, $token);
		update_option(self::HOME_URL_OPTION, home_url());
		delete_option(self::DISCONNECTED_OPTION);

		delete_transient(self::STATE_TRANSIENT);

		return WRITESONIC_CONNECT_URL . '?' . http_build_query(
			array(
				'domain' => home_url(),
				'user'   => $user->user_email,
				'token'  => $token,
				'caps'   => 'publishing,analytics',
				'return' => Writesonic_Admin::tab_url('analytics'),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Redeems the single-use code Writesonic sends the admin back with. The key
	 * itself never travels in the URL; this exchanges the code for it over a
	 * server-side request, authenticated by the code plus this site's token.
	 */
	public static function claim_analytics_key($code)
	{
		$token = self::site_token();

		if ('' === $token || '' === $code) {
			return false;
		}

		$response = wp_remote_post(WRITESONIC_CLAIM_URL, array(
			'timeout'     => 15,
			'headers'     => array('Content-Type' => 'application/json'),
			'body'        => wp_json_encode(array(
				'domain'     => home_url(),
				'site_token' => $token,
				'code'       => $code,
			)),
			'data_format' => 'body',
		));

		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
			return false;
		}

		$claim = json_decode(wp_remote_retrieve_body($response), true);

		if (!is_array($claim) || empty($claim['analytics_key'])) {
			return false;
		}

		self::apply_analytics_config(array(
			'analytics_key'    => $claim['analytics_key'],
			'project_id'       => isset($claim['project_id']) ? $claim['project_id'] : '',
			'ingestion_domain' => isset($claim['ingestion_domain']) ? $claim['ingestion_domain'] : '',
			'enabled'          => true,
		));

		return true;
	}

	/**
	 * The manual route, for sites Writesonic could not redirect back to. Same
	 * key, entered by hand.
	 */
	public static function save_analytics_key($key)
	{
		self::apply_analytics_config(array(
			'analytics_key' => $key,
			'enabled'       => true,
		));
	}

	public static function disconnect($token)
	{
		$tokens = get_option(WRITESONIC_API_KEY_OPTION, array());

		if (is_array($tokens)) {
			$email = array_search($token, $tokens, true);

			if (false !== $email) {
				unset($tokens[$email]);
				update_option(WRITESONIC_API_KEY_OPTION, $tokens);
			}
		}

		delete_option(self::SITE_TOKEN_OPTION);
		delete_transient(self::STATE_TRANSIENT);
	}

	/**
	 * Stops analytics on this site and forgets its key, the same thing the
	 * standalone plugin did when it was deactivated. Publishing is untouched.
	 *
	 * Writesonic keeps the binding — there is no revoke — so this also records
	 * the opt-out. Otherwise the next state poll would return the key and
	 * collection would resume on its own.
	 */
	public static function disconnect_analytics()
	{
		delete_option(self::ANALYTICS_KEY_OPTION);
		delete_option(self::PROJECT_ID_OPTION);
		delete_option(self::PAUSED_OPTION);
		update_option(self::TRACKING_ENABLED_OPTION, false);
		update_option(self::DISCONNECTED_OPTION, true);
		delete_transient(self::STATE_TRANSIENT);
	}

	/**
	 * Polls more eagerly while waiting on the user to finish in the app, then
	 * settles down once the connection has resolved.
	 */
	public static function maybe_refresh_state()
	{
		if (!self::is_connected() || false !== get_transient(self::STATE_TRANSIENT)) {
			return;
		}

		self::refresh_state();
	}

	public static function get_state($force = false)
	{
		$cached = get_transient(self::STATE_TRANSIENT);

		if (!$force && is_array($cached)) {
			return $cached;
		}

		return self::refresh_state();
	}

	/**
	 * Failures are cached briefly as well as successes, so a slow or broken API
	 * does not put an outbound request in front of every admin page load.
	 */
	public static function refresh_state()
	{
		$token = self::site_token();

		if ('' === $token) {
			return null;
		}

		$response = wp_remote_post(WRITESONIC_STATE_URL, array(
			'timeout'     => 10,
			'headers'     => array('Content-Type' => 'application/json'),
			'body'        => wp_json_encode(array(
				'domain'     => home_url(),
				'site_token' => $token,
			)),
			'data_format' => 'body',
		));

		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
			set_transient(self::STATE_TRANSIENT, array('connected' => false, 'error' => true), MINUTE_IN_SECONDS);

			return null;
		}

		$state = json_decode(wp_remote_retrieve_body($response), true);

		if (!is_array($state)) {
			set_transient(self::STATE_TRANSIENT, array('connected' => false, 'error' => true), MINUTE_IN_SECONDS);

			return null;
		}

		self::apply_state($state);

		$ttl = !empty($state['connected']) ? 15 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS;
		set_transient(self::STATE_TRANSIENT, $state, $ttl);

		return $state;
	}

	/**
	 * The server is authoritative for the analytics binding, but never for the
	 * publishing token — that is minted here and only ever revoked locally.
	 */
	/**
	 * The server may grant analytics here, never silently withdraw it. A site
	 * that pasted its key has no publishing connection for the state endpoint to
	 * resolve, so it answers `connected: false` — which carries no information
	 * about analytics and must not be read as a revocation.
	 */
	protected static function apply_state(array $state)
	{
		if (self::is_analytics_disconnected() || empty($state['connected'])) {
			return;
		}

		$capabilities = isset($state['capabilities']) && is_array($state['capabilities'])
			? $state['capabilities']
			: array();

		if (!empty($state['ingestion_domain']) && self::is_writesonic_host($state['ingestion_domain'])) {
			update_option(self::INGESTION_DOMAIN_OPTION, esc_url_raw($state['ingestion_domain']));
		}

		if (!empty($state['project_id'])) {
			update_option(self::PROJECT_ID_OPTION, sanitize_text_field($state['project_id']));
		}

		if (!empty($state['analytics_key'])) {
			update_option(self::ANALYTICS_KEY_OPTION, sanitize_text_field($state['analytics_key']));
		}

		$analytics_granted = !empty($capabilities['analytics']) && '' !== self::analytics_key();

		update_option(self::TRACKING_ENABLED_OPTION, $analytics_granted);
	}

	/**
	 * The ingestion host arrives in a server response but decides where the
	 * analytics key is later POSTed, so pin it to Writesonic before trusting it.
	 */
	protected static function is_writesonic_host($url)
	{
		$host = wp_parse_url($url, PHP_URL_HOST);

		if (!$host) {
			return false;
		}

		$host = strtolower($host);

		return 'writesonic.com' === $host || '.writesonic.com' === substr($host, -15);
	}

	/**
	 * A restored or cloned site carries someone else's credentials. Publishing to
	 * the wrong site and double-counting traffic both start here.
	 */
	public static function is_site_moved()
	{
		$recorded = get_option(self::HOME_URL_OPTION, '');

		return '' !== $recorded && untrailingslashit($recorded) !== untrailingslashit(home_url());
	}
}
