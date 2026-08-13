<?php

if (!defined('ABSPATH')) {
	exit;
}

if (class_exists('Writesonic_Analytics')) {
	return;
}

/**
 * Reports front-end requests to the Writesonic ingestion service so AI crawler
 * traffic can be attributed.
 *
 * Timing is deliberately identical to the standalone analytics plugin: one send
 * per front-end request, at `shutdown`, with no filtering by status code or user
 * agent. Onboarding verification works by fetching a URL that 404s and watching
 * for it to arrive, so filtering 404s — or deferring sends into a batch — breaks
 * setup for every new customer. Batching is possible (the ingest endpoint accepts
 * arrays) but must flush synchronously on the verification token; that is not
 * this change.
 */
class Writesonic_Analytics
{
	const INGEST_ROUTE      = 'api/v1/analytics/ingest';
	const BACKOFF_TRANSIENT = 'writesonic_analytics_backoff';
	const LAST_SEND_TRANSIENT = 'writesonic_analytics_last_send';

	public static function boot()
	{
		if (!self::should_track()) {
			return;
		}

		add_action('template_redirect', array(__CLASS__, 'schedule_send'), 1);
	}

	/**
	 * Standing down while the legacy plugin is active is what prevents
	 * double-counting: both would send for the same page view under the same key,
	 * and nothing downstream can tell the two events apart.
	 */
	public static function should_track()
	{
		if (defined('WRITESONIC_DISABLE_ANALYTICS') && WRITESONIC_DISABLE_ANALYTICS) {
			return false;
		}

		if (Writesonic_Migration::legacy_plugin_active()) {
			return false;
		}

		if (Writesonic_Connection::is_site_moved()) {
			return false;
		}

		if (Writesonic_Connection::is_paused()) {
			return false;
		}

		if (!Writesonic_Connection::is_tracking_enabled()) {
			return false;
		}

		if ('' === Writesonic_Connection::analytics_key()) {
			return false;
		}

		if (get_transient(self::BACKOFF_TRANSIENT)) {
			return false;
		}

		return (bool) apply_filters('writesonic_analytics_should_track', true);
	}

	public static function schedule_send()
	{
		add_action('shutdown', array(__CLASS__, 'send'));
	}

	/**
	 * A non-blocking request only surfaces transport-level failures, which is
	 * exactly the case worth backing off from — on a host with outbound HTTP
	 * blocked, every page view would otherwise pay the connection timeout.
	 */
	public static function send()
	{
		if (is_admin() || wp_doing_cron()) {
			return;
		}

		$endpoint = trailingslashit(Writesonic_Connection::ingestion_domain()) . self::INGEST_ROUTE;

		$response = wp_remote_post($endpoint, array(
			'method'   => 'POST',
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array(
				'Content-Type' => 'application/json',
				'x-api-key'    => Writesonic_Connection::analytics_key(),
			),
			'body'     => wp_json_encode(self::gather_request_data(), JSON_INVALID_UTF8_SUBSTITUTE),
			'cookies'  => array(),
		));

		if (is_wp_error($response)) {
			set_transient(self::BACKOFF_TRANSIENT, 1, 15 * MINUTE_IN_SECONDS);

			return;
		}

		$last = get_transient(self::LAST_SEND_TRANSIENT);

		if (!$last || (time() - (int) $last) > 5 * MINUTE_IN_SECONDS) {
			set_transient(self::LAST_SEND_TRANSIENT, time(), DAY_IN_SECONDS);
		}
	}

	private static function gather_request_data()
	{
		$headers = self::get_all_headers();
		$ip      = self::get_client_ip($headers);

		return array(
			'ua'              => isset($headers['user-agent']) ? $headers['user-agent'] : '',
			'referrer'        => isset($headers['referer']) ? $headers['referer'] : '',
			'ip'              => $ip,
			'country_code'    => self::get_country_code($headers),
			'url'             => self::get_current_url(),
			'method'          => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET',
			'x_forwarded_for' => isset($headers['x-forwarded-for']) ? $headers['x-forwarded-for'] : '',
			'x_real_ip'       => isset($headers['x-real-ip']) ? $headers['x-real-ip'] : $ip,
			'response_status' => (string) http_response_code(),
			'integration_name' => 'wordpress',
			'wp_version'      => get_bloginfo('version'),
			'site_title'      => get_bloginfo('name'),
			'plugin_version'  => WRITESONIC_VERSION,
			'plugin_source'   => 'writesonic-unified',
			'request_id'      => wp_generate_uuid4(),
		);
	}

	/**
	 * Built from WordPress's own notion of the site URL. Deriving it from
	 * `HTTP_HOST` through `esc_url_raw()` reports every request as `http://`,
	 * because the host carries no scheme for the check to find.
	 */
	private static function get_current_url()
	{
		$request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';

		return esc_url_raw(home_url($request_uri));
	}

	/**
	 * Lower-cased keys throughout: `getallheaders()` and the `$_SERVER` fallback
	 * disagree on capitalisation, which silently broke country detection on
	 * every host that lacks `getallheaders()`.
	 */
	private static function get_all_headers()
	{
		$headers = array();

		if (function_exists('getallheaders')) {
			foreach ((array) getallheaders() as $name => $value) {
				$headers[strtolower($name)] = sanitize_text_field($value);
			}

			return $headers;
		}

		foreach ($_SERVER as $name => $value) {
			if (0 === strpos($name, 'HTTP_')) {
				$key = strtolower(str_replace('_', '-', substr($name, 5)));
				$headers[$key] = sanitize_text_field(wp_unslash($value));
			} elseif ('CONTENT_TYPE' === $name || 'CONTENT_LENGTH' === $name) {
				$key = strtolower(str_replace('_', '-', $name));
				$headers[$key] = sanitize_text_field(wp_unslash($value));
			}
		}

		return $headers;
	}

	/**
	 * `X-Forwarded-For` is a chain of proxies; the originating client is the
	 * first hop, and the whole header fails IP validation if passed as-is.
	 */
	private static function get_client_ip(array $headers)
	{
		$candidates = array(
			'cf-connecting-ip',
			'client-ip',
			'x-forwarded-for',
			'x-cluster-client-ip',
			'forwarded-for',
			'x-real-ip',
		);

		foreach ($candidates as $key) {
			if (empty($headers[$key])) {
				continue;
			}

			$first = trim(explode(',', $headers[$key])[0]);

			if (filter_var($first, FILTER_VALIDATE_IP)) {
				return $first;
			}
		}

		if (isset($_SERVER['REMOTE_ADDR'])) {
			$remote = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));

			if (filter_var($remote, FILTER_VALIDATE_IP)) {
				return $remote;
			}
		}

		return '';
	}

	private static function get_country_code(array $headers)
	{
		foreach (array('cf-ipcountry', 'x-country-code', 'geoip-country-code', 'x-country') as $key) {
			if (!empty($headers[$key])) {
				return $headers[$key];
			}
		}

		return '';
	}

	public static function last_send_time()
	{
		return get_transient(self::LAST_SEND_TRANSIENT);
	}

	public static function is_backing_off()
	{
		return (bool) get_transient(self::BACKOFF_TRANSIENT);
	}
}
