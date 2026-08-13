<?php

if (!defined('ABSPATH')) {
	exit;
}

if (class_exists('WPM_Writesonic_Integration')) {
	return;
}

/**
 * Publishing REST surface.
 *
 * The `writesonic/v2` namespace, its route paths and its payload shapes are a
 * frozen contract: knowledge-and-integration-hub calls them directly with no
 * fallback to WordPress core routes, and installs in the field cannot be
 * updated. Nothing here may change shape without a hub release first.
 */
class WPM_Writesonic_Integration
{
	/**
	 * The two filters below are site-wide, so they stay off until publishing is
	 * actually in use — an analytics-only install has no business forcing every
	 * post type into the REST API.
	 */
	public static function init()
	{
		add_action('rest_api_init', array(__CLASS__, 'register_api_endpoints'));

		if (self::is_publishing_connected()) {
			add_filter('rest_user_query', array(__CLASS__, 'remove_has_published_posts_from_wp_api_user_query'), 10, 2);
			add_filter('register_post_type_args', array(__CLASS__, 'custom_post_types_show_in_rest_filter'), 10, 2);
		}
	}

	/**
	 * Tokens are deliberately left in place, so deactivating never costs a
	 * reconnect. `uninstall.php` is what clears them.
	 */
	public static function deactivation()
	{
	}

	public static function is_publishing_connected()
	{
		$tokens = get_option(WRITESONIC_API_KEY_OPTION, array());

		return is_array($tokens) && !empty($tokens);
	}

	public static function custom_post_types_show_in_rest_filter($args, $post_type)
	{
		$args['show_in_rest'] = true;

		return $args;
	}

	/**
	 * Returns users who have not published yet, so they can still be selected as
	 * an author from Writesonic.
	 */
	public static function remove_has_published_posts_from_wp_api_user_query($prepared_args, $request)
	{
		unset($prepared_args['has_published_posts']);

		return $prepared_args;
	}

	public static function register_api_endpoints()
	{
		$categories_controller = new WP_REST_Terms_Controller('category');
		register_rest_route('writesonic/v2', '/categories', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array(__CLASS__, 'get_categories'),
			'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
			'args'                => $categories_controller->get_collection_params(),
		));

		$tags_controller = new WP_REST_Terms_Controller('post_tag');
		register_rest_route('writesonic/v2', '/tags', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array(__CLASS__, 'get_tags'),
			'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
			'args'                => $tags_controller->get_collection_params(),
		));

		$posts_controller = new WP_REST_Posts_Controller('post');
		register_rest_route('writesonic/v2', '/posts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array(__CLASS__, 'get_posts'),
				'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
				'args'                => $posts_controller->get_collection_params(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array(__CLASS__, 'create_post'),
				'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
				'args'                => self::get_post_args(),
			),
			'schema' => array(__CLASS__, 'get_public_item_schema'),
		));

		register_rest_route('writesonic/v2', '/posts/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array(__CLASS__, 'get_post'),
				'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array(__CLASS__, 'update_post'),
				'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
				'args'                => self::get_post_args(),
				'schema'              => array(__CLASS__, 'get_public_item_schema'),
			),
		));

		$attachment_controller = new WP_REST_Attachments_Controller('attachment');
		register_rest_route('writesonic/v2', '/media', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array(__CLASS__, 'get_media'),
				'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
				'args'                => $attachment_controller->get_collection_params(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array(__CLASS__, 'create_media'),
				'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
				'args'                => $attachment_controller->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
			),
			'schema' => array(__CLASS__, 'get_public_item_schema'),
		));

		$users_controller = new WP_REST_Users_Controller();
		register_rest_route('writesonic/v2', '/authors', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array(__CLASS__, 'get_authors'),
			'permission_callback' => array(__CLASS__, 'check_api_key_auth'),
			'args'                => $users_controller->get_collection_params(),
		));
	}

	private static function get_post_args()
	{
		return array(
			'title' => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content' => array(
				'required' => false,
			),
			'status' => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug' => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'categories' => array(
				'required' => false,
				'type'     => 'array',
			),
			'tags' => array(
				'required' => false,
				'type'     => 'array',
			),
			'meta_description' => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'featured_image' => array(
				'required' => false,
				'type'     => 'string',
			),
		);
	}

	public static function get_public_item_schema()
	{
		$posts_controller = new WP_REST_Posts_Controller('post');

		return $posts_controller->get_public_item_schema();
	}

	/**
	 * Authenticates a hub request by its `Token` header and adopts the matching
	 * WordPress user, so posts are attributed to the account that connected. A
	 * deleted WordPress user does not invalidate a token the hub still holds —
	 * the request proceeds unattributed rather than failing.
	 */
	public static function check_api_key_auth(WP_REST_Request $request)
	{
		$presented = $request->get_header('token');

		if (empty($presented)) {
			return false;
		}

		$tokens = get_option(WRITESONIC_API_KEY_OPTION, array());

		if (!is_array($tokens)) {
			return false;
		}

		foreach ($tokens as $email => $token) {
			if (!is_string($token) || !hash_equals($token, $presented)) {
				continue;
			}

			$user = get_user_by('email', $email);

			if ($user) {
				wp_set_current_user($user->ID);
			}

			return true;
		}

		return false;
	}

	public static function get_authors(WP_REST_Request $request)
	{
		$users = get_users(array(
			'role__in' => array('administrator', 'editor', 'author'),
		));

		$author_data = array_map(function ($user) {
			return array(
				'ID'       => $user->ID,
				'username' => $user->user_login,
				'name'     => $user->display_name,
				'email'    => $user->user_email,
			);
		}, $users);

		return rest_ensure_response($author_data);
	}

	public static function get_categories(WP_REST_Request $request)
	{
		$categories = get_categories(array('hide_empty' => false));

		$category_data = array_map(function ($category) {
			return array(
				'ID'    => $category->term_id,
				'name'  => $category->name,
				'slug'  => $category->slug,
				'count' => $category->count,
			);
		}, $categories);

		return rest_ensure_response($category_data);
	}

	public static function get_tags(WP_REST_Request $request)
	{
		$tags = get_tags(array('hide_empty' => false));

		$tag_data = array_map(function ($tag) {
			return array(
				'ID'   => $tag->term_id,
				'name' => $tag->name,
				'slug' => $tag->slug,
			);
		}, $tags);

		return rest_ensure_response($tag_data);
	}

	/**
	 * Returns posts of any status. The hub sends no `status` param and relies on
	 * drafts being included — narrowing this empties the post picker in the app.
	 */
	public static function get_posts(WP_REST_Request $request)
	{
		$post_type = isset($request['post_type']) ? sanitize_text_field($request['post_type']) : 'post';

		$request['status'] = 'any';

		$controller = new WP_REST_Posts_Controller($post_type);
		$response   = $controller->get_items($request);

		if (is_wp_error($response)) {
			return $response;
		}

		if ($response instanceof WP_REST_Response && $response->is_error()) {
			return $response;
		}

		$posts = $response->get_data();

		foreach ($posts as &$post) {
			$categories = get_the_category($post['id']);
			$post['categories'] = array_map(function ($category) {
				return $category->name;
			}, $categories);

			$tags = get_the_tags($post['id']);
			$post['tags'] = $tags ? array_map(function ($tag) {
				return $tag->name;
			}, $tags) : array();

			$meta_description = get_post_meta($post['id'], 'description', true);
			if (!empty($meta_description)) {
				$post['meta_description'] = $meta_description;
			}

			if (!empty($post['featured_media'])) {
				$featured_image = wp_get_attachment_image_src($post['featured_media'], 'full');
				if ($featured_image) {
					$post['featured_image'] = $featured_image[0];
				}
			}
		}

		return rest_ensure_response($posts);
	}

	public static function get_post(WP_REST_Request $request)
	{
		$controller = new WP_REST_Posts_Controller('post');

		return $controller->get_item($request);
	}

	public static function create_post(WP_REST_Request $request)
	{
		return self::handle_post_creation_or_update($request, false);
	}

	public static function update_post(WP_REST_Request $request)
	{
		return self::handle_post_creation_or_update($request, true);
	}

	/**
	 * Categories and tags arrive as names rather than IDs, and `featured_image`
	 * as an attachment ID. Both are part of the frozen hub contract.
	 */
	private static function handle_post_creation_or_update(WP_REST_Request $request, $is_update = false)
	{
		$post_type = isset($request['post_type']) ? sanitize_text_field($request['post_type']) : 'post';

		$params = array(
			'post_type'    => $post_type,
			'post_title'   => isset($request['title']) ? sanitize_text_field($request['title']) : '',
			'post_content' => isset($request['content']) ? $request['content'] : '',
			'post_status'  => isset($request['status']) ? sanitize_text_field($request['status']) : 'draft',
		);

		if (isset($request['slug'])) {
			$params['post_name'] = sanitize_text_field($request['slug']);
		}

		if (isset($request['categories']) && is_array($request['categories'])) {
			$params['post_category'] = self::resolve_term_ids($request['categories'], 'category');
		}

		if (isset($request['tags']) && is_array($request['tags'])) {
			$params['tax_input'] = array('post_tag' => self::resolve_term_ids($request['tags'], 'post_tag'));
		}

		if (isset($request['author']) && is_numeric($request['author'])) {
			$params['post_author'] = (int) $request['author'];
		}

		if ($is_update) {
			$post_id = (int) $request['id'];

			if (!get_post($post_id)) {
				return new WP_REST_Response(array('message' => 'Post not found'), 404);
			}

			$params['ID'] = $post_id;
		}

		$result = $is_update ? wp_update_post($params, true) : wp_insert_post($params, true);

		if (is_wp_error($result)) {
			return new WP_REST_Response(array('message' => $result->get_error_message()), 400);
		}

		if (isset($request['meta_description'])) {
			update_post_meta($result, 'description', sanitize_text_field($request['meta_description']));
		}

		if (isset($request['featured_image'])) {
			$attachment_id = (int) $request['featured_image'];

			if ($attachment_id) {
				set_post_thumbnail($result, $attachment_id);
			}
		}

		$controller = new WP_REST_Posts_Controller($post_type);
		$request->set_param('id', $result);

		return $controller->prepare_item_for_response(get_post($result), $request);
	}

	private static function resolve_term_ids(array $names, $taxonomy)
	{
		$ids = array();

		foreach ($names as $name) {
			$name = sanitize_text_field($name);
			$term = get_term_by('name', $name, $taxonomy);

			if ($term) {
				$ids[] = $term->term_id;
				continue;
			}

			$created = wp_insert_term($name, $taxonomy);

			if (!is_wp_error($created)) {
				$ids[] = $created['term_id'];
			}
		}

		return $ids;
	}

	public static function get_media(WP_REST_Request $request)
	{
		$controller = new WP_REST_Attachments_Controller('attachment');

		return $controller->get_items($request);
	}

	public static function create_media(WP_REST_Request $request)
	{
		$controller = new WP_REST_Attachments_Controller('attachment');

		return $controller->create_item($request);
	}

}
