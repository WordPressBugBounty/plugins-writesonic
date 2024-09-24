<?php

/**
 * Plugin Name: Writesonic
 * Description: Writesonic WordPress plugin
 * Version: 1.0.4
 * Author: <a href="https://writesonic.com/">Writesonic</a>
 * Author URI: https://writesonic.com/
 * Text Domain: writesonic
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

const WRITESONIC_API_KEY_OPTION = 'writesonic_api_key';

const WRITESONIC_CONNECT_URL = 'https://app.writesonic.com/wordpress-authentication/';
// const WRITESONIC_CONNECT_URL = 'http://localhost:3000/wordpress-authentication/';
// const WRITESONIC_CONNECT_URL = 'https://staging.writesonic.com/wordpress-authentication/';

const WRITESONIC_CHECK_AUTH_URL = 'https://api.writesonic.com/v1/thirdparty/wordpress-org-authorization-status';
// const WRITESONIC_CHECK_AUTH_URL = 'http://localhost:8081/v1/thirdparty/wordpress-org-authorization-status';
// const WRITESONIC_CHECK_AUTH_URL = 'https://dev-backend.writesonic.com/v1/thirdparty/wordpress-org-authorization-status';

if (!class_exists('WPM_Writesonic_Integration')) {
	class WPM_Writesonic_Integration
	{
		/**
		 * Plugin init, filters, hooks
		 */
		public static function init()
		{
			// Initialize option with empty string
			add_option(WRITESONIC_API_KEY_OPTION, '');

			add_action('admin_menu', array('WPM_Writesonic_Integration', 'create_settings_menu'));
			add_action('rest_api_init', array('WPM_Writesonic_Integration', 'register_api_endpoints'));
			register_deactivation_hook(__FILE__, array('WPM_Writesonic_Integration', 'deactivation'));
			add_action('admin_init', array('WPM_Writesonic_Integration', 'register_settings'));

			// Get users w/o posts published
			add_filter('rest_user_query', array('WPM_Writesonic_Integration', 'remove_has_published_posts_from_wp_api_user_query'), 10, 2);

			// Force custom posts to be visible in REST API
			add_filter('register_post_type_args', array('WPM_Writesonic_Integration', 'custom_post_types_show_in_rest_filter'), 10, 2);
		}

		/**
		 * Deactivation hook
		 */
		public static function deactivation()
		{
			// We can delete key on deactivation, but it's not needed now
			// delete_option(WRITESONIC_API_KEY_OPTION);
		}

		/**
		 * WP settings registration
		 */
		public static function register_settings()
		{
			register_setting('writesonic', WRITESONIC_API_KEY_OPTION);
		}

		/**
		 * Settings menu registration
		 */
		public static function create_settings_menu()
		{
			add_options_page('Writesonic Settings', 'Writesonic', 'manage_options', 'writesonic', array('WPM_Writesonic_Integration', 'create_settings_page'));
		}

		/**
		 * Settings page registration
		 */
		public static function create_settings_page()
		{
			include plugin_dir_path(__FILE__) . '/templates/settings.php';
		}

		/**
		 * @param $args
		 * @param $post_type
		 *
		 * @return mixed
		 */
		public static function custom_post_types_show_in_rest_filter($args, $post_type)
		{
			$args['show_in_rest'] = true;

			return $args;
		}

		/**
		 * Removes `has_published_posts` from the query args so even users who have not
		 * published content are returned by the request.
		 *
		 * @see https://developer.wordpress.org/reference/classes/wp_user_query/
		 *
		 * @param array $prepared_args Array of arguments for WP_User_Query.
		 * @param WP_REST_Request $request The current request.
		 *
		 * @return array
		 */
		function remove_has_published_posts_from_wp_api_user_query($prepared_args, $request)
		{
			unset($prepared_args['has_published_posts']);

			return $prepared_args;
		}

		/**
		 * Register VidApp custom REST API endpoints
		 */
		public static function register_api_endpoints()
		{
			/**
			 * Categories
			 */
			$categories_controller = new WP_REST_Terms_Controller('category');
			register_rest_route('writesonic/v2', '/categories', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array('WPM_Writesonic_Integration', 'get_categories'),
				'permission_callback' => array('WPM_Writesonic_Integration', 'get_categories_permissions_check'),
				'args'                => $categories_controller->get_collection_params()
			));

			$tags_controller = new WP_REST_Terms_Controller('post_tag');
			register_rest_route('writesonic/v2', '/tags', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array('WPM_Writesonic_Integration', 'get_tags'),
				'permission_callback' => array('WPM_Writesonic_Integration', 'get_tags_permissions_check'),
				'args'                => $tags_controller->get_collection_params()
			));

			/**
			 * Posts
			 */
			$posts_controller = new WP_REST_Posts_Controller('post');
			register_rest_route('writesonic/v2', '/posts', array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array('WPM_Writesonic_Integration', 'get_posts'),
					'permission_callback' => array('WPM_Writesonic_Integration', 'get_posts_permission_check'),
					'args'                => $posts_controller->get_collection_params()
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array('WPM_Writesonic_Integration', 'create_post'),
					'permission_callback' => array('WPM_Writesonic_Integration', 'create_post_permissions_check'),
					'args'                => array(
						'title' => array(
							'required' => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'content' => array(
							'required' => false,
						),
						'status' => array(
							'required' => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'slug' => array(
							'required' => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'categories' => array(
							'required' => false,
							'type' => 'array',
						),
						'tags' => array(
							'required' => false,
							'type' => 'array',
						),
						'meta_description' => array(
							'required' => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'featured_image' => array(
							'required' => false,
							'type' => 'string',
						),
					),
				),
				'schema' => array('WPM_Writesonic_Integration', 'get_public_item_schema'),
			));

			/**
			 * Posts update
			 */
			register_rest_route(
				'writesonic/v2',
				'/posts/(?P<id>\d+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array('WPM_Writesonic_Integration', 'get_post'),
						'permission_callback' => array('WPM_Writesonic_Integration', 'get_post_permission_check'),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array('WPM_Writesonic_Integration', 'update_post'),
						'permission_callback' => array('WPM_Writesonic_Integration', 'update_post_permissions_check'),
						'args' => array(
							'title' => array(
								'required' => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'content' => array(
								'required' => false,
							),
							'status' => array(
								'required' => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'slug' => array(
								'required' => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'categories' => array(
								'required' => false,
								'type' => 'array',
							),
							'tags' => array(
								'required' => false,
								'type' => 'array',
							),
							'meta_description' => array(
								'required' => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'featured_image' => array(
								'required' => false,
								'type' => 'string',
							),
						),
						'schema' => array('WPM_Writesonic_Integration', 'get_public_item_schema'),
					),
				)
			);

			/**
			 * Media
			 */
			$attachment_controller = new WP_REST_Attachments_Controller('attachment');
			register_rest_route('writesonic/v2', '/media', array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array('WPM_Writesonic_Integration', 'get_media'),
					'permission_callback' => array('WPM_Writesonic_Integration', 'get_media_permission_check'),
					'args'                => $attachment_controller->get_collection_params()

				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array('WPM_Writesonic_Integration', 'create_media'),
					'permission_callback' => array('WPM_Writesonic_Integration', 'create_media_permissions_check'),
					'args'                => $attachment_controller->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
				),
				'schema' => array('WPM_Writesonic_Integration', 'get_public_item_schema'),
			));

			/**
			 * Comments
			 */
			$comment_controller = new WP_REST_Comments_Controller();
			register_rest_route('writesonic/v2', '/comments', array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array('WPM_Writesonic_Integration', 'get_comments'),
					'permission_callback' => array('WPM_Writesonic_Integration', 'get_comments_permission_check'),
					'args'                => $comment_controller->get_collection_params()

				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array('WPM_Writesonic_Integration', 'create_comment'),
					'permission_callback' => array('WPM_Writesonic_Integration', 'create_comment_permissions_check'),
					'args'                => $comment_controller->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
				),
				'schema' => array('WPM_Writesonic_Integration', 'get_public_item_schema'),
			));

			/**
			 * Users
			 */
			$users_controller = new WP_REST_Users_Controller();
			register_rest_route('writesonic/v2', '/users', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array('WPM_Writesonic_Integration', 'get_users'),
				'permission_callback' => array('WPM_Writesonic_Integration', 'get_users_permissions_check'),
				'args'                => $users_controller->get_collection_params()
			));

			register_rest_route('writesonic/v2', '/password', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array('WPM_Writesonic_Integration', 'validate_user_password'),
				'permission_callback' => array('WPM_Writesonic_Integration', 'validate_password_permissions_check'),
				'args'                => array(
					'password' => array(
						'default'           => null,           // значение параметра по умолчанию
						'required'          => true,           // является ли параметр обязательным. Может быть только true
						'sanitize_callback' => 'sanitize_text_field', // функция очистки значения параметра. Должна вернуть очищенное значение
					)
				)
			));

			register_rest_route('writesonic/v2', '/authors', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array('WPM_Writesonic_Integration', 'get_authors'),
				'permission_callback' => array('WPM_Writesonic_Integration', 'get_authors_permissions_check'),
				'args'                => $users_controller->get_collection_params()
			));
		}

		public static function get_user_by_token($token, $user)
		{
			$wpb_writesonic_tokens = get_option(WRITESONIC_API_KEY_OPTION);

			if (!is_array($wpb_writesonic_tokens)) {
				return $user;
			}

			$user_email = array_search($token, $wpb_writesonic_tokens);

			if ($user_email) {
				$user = get_user_by('email', $user_email);

				return $user->ID;
			}

			return $user;
		}

		public static function get_public_item_schema()
		{
			$posts_controller = new WP_REST_Posts_Controller('post');

			return $posts_controller->get_public_item_schema();
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return bool
		 */
		public static function get_users_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return bool
		 */
		public static function get_categories_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function get_users(WP_REST_Request $request)
		{
			$controller = new WP_REST_Users_Controller();

			$response = $controller->get_items($request);

			return $response;
		}

		/**
		 * Get users who can create or edit posts
		 *
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response
		 */
		public static function get_authors(WP_REST_Request $request)
		{
			// Define the query arguments to get users with post creation/editing capabilities
			$args = array(
				'role__in' => array('administrator', 'editor', 'author'), // Roles capable of creating/editing posts
			);

			// Fetch the users
			$users = get_users($args);

			// Prepare the response data
			$author_data = array_map(function ($user) {
				return array(
					'ID'       => $user->ID,
					'username' => $user->user_login,
					'name'     => $user->display_name,
					'email'    => $user->user_email,
				);
			}, $users);

			// Return the response
			return rest_ensure_response($author_data);
		}

		/**
		 * Permissions check for getting authors
		 *
		 * @param WP_REST_Request $request
		 * @return bool
		 */
		public static function get_authors_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}


		/**
		 * Get all categories
		 *
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response
		 */
		public static function get_categories(WP_REST_Request $request)
		{
			// Define arguments to fetch all categories
			$args = array(
				'hide_empty' => false, // Include categories even if they have no posts
			);

			// Fetch all categories
			$categories = get_categories($args);

			// Prepare the response data
			$category_data = array_map(function ($category) {
				return array(
					'ID'    => $category->term_id,
					'name'  => $category->name,
					'slug'  => $category->slug,
					'count' => $category->count, // Number of posts in the category
				);
			}, $categories);

			// Return the response
			return rest_ensure_response($category_data);
		}

		/**
		 * Get all tags
		 *
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response
		 */
		public static function get_tags(WP_REST_Request $request)
		{
			// Define arguments to fetch all tags
			$args = array(
				'hide_empty' => false, // Include tags even if they have no posts
			);

			// Fetch all tags
			$tags = get_tags($args);

			// Prepare the response data
			$tag_data = array_map(function ($tag) {
				return array(
					'ID'    => $tag->term_id,
					'name'  => $tag->name,
					'slug'  => $tag->slug,
				);
			}, $tags);

			// Return the response
			return rest_ensure_response($tag_data);
		}

		/**
		 * Permissions check for getting tags
		 *
		 * @param WP_REST_Request $request
		 * @return bool
		 */
		public static function get_tags_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}



		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function get_posts_permission_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function get_comments(WP_REST_Request $request)
		{
			$controller = new WP_REST_Comments_Controller();
			$response   = $controller->get_items($request);

			return $response;
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return bool
		 */
		public static function get_comments_permission_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * Get posts of any status and include tag and category names
		 *
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response
		 */
		public static function get_posts(WP_REST_Request $request)
		{
			// Determine the post type from the request, default to 'post'
			$post_type = isset($request['post_type']) ? sanitize_text_field($request['post_type']) : 'post';

			// Set the status parameter to 'any' to fetch posts of any status
			$request['status'] = 'any';

			// Create a new WP_REST_Posts_Controller for the specified post type
			$controller = new WP_REST_Posts_Controller($post_type);

			// Fetch the posts using the modified request parameters
			$response = $controller->get_items($request);

			// Check if the response is successful
			if ($response instanceof WP_REST_Response && $response->is_error()) {
				return $response;
			}

			// Get the posts from the response
			$posts = $response->get_data();

			// Loop through each post to fetch and append category and tag names
			foreach ($posts as &$post) {
				// Fetch category names
				$categories = get_the_category($post['id']);
				$category_names = array_map(function ($cat) {
					return $cat->name;
				}, $categories);

				// Fetch tag names
				$tags = get_the_tags($post['id']);
				$tag_names = array();
				if ($tags) {
					$tag_names = array_map(function ($tag) {
						return $tag->name;
					}, $tags);
				}

				// Replace the IDs with names in the post data
				$post['categories'] = $category_names;
				$post['tags'] = $tag_names;

				// * get the meta description and add it to the response
				$meta_description = get_post_meta($post['id'], 'description', true);
				// * if it's not empty, add it to the response
				if (!empty($meta_description)) {
					$post['meta_description'] = $meta_description;
				}

				if (!empty($post['featured_media'])) {
					// * we need to get the link to the image
					$featured_image = wp_get_attachment_image_src($post['featured_media'], 'full');
					$post['featured_image'] = $featured_image[0];
				}
			}

			// Return the modified response
			return rest_ensure_response($posts);
		}

		public static function get_post(WP_REST_Request $request)
		{
			$controller = new WP_REST_Posts_Controller('post');
			$response   = $controller->get_item($request);

			return $response;
		}

		public static function get_post_permission_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * Check if correct API key provided in request
		 *
		 * @param WP_REST_Request $request
		 *
		 * @return bool
		 */
		public static function checkAPIKeyAuth(WP_REST_Request $request)
		{
			$auth             = isset($_SERVER['HTTP_TOKEN']) ? sanitize_text_field($_SERVER['HTTP_TOKEN']) : false;
			$writesonic_token_key = get_option(WRITESONIC_API_KEY_OPTION, true);

			wp_set_current_user(self::get_user_by_token($auth, $user));

			if (is_array($writesonic_token_key) && in_array($auth, $writesonic_token_key)) {
				return true;
			}

			return false;
		}

		/**
		 * Handle the creation or updating of a post, including handling mixed input for tags and categories
		 *
		 * @param WP_REST_Request $request
		 * @param bool $is_update Whether this is an update operation
		 * @return WP_REST_Response
		 */
		private static function handle_post_creation_or_update(WP_REST_Request $request, $is_update = false)
		{
			// Determine the post type from the request, default to 'post'
			$post_type = isset($request['post_type']) ? sanitize_text_field($request['post_type']) : 'post';

			// Prepare the parameters for creating or updating the post
			$params = array(
				'post_type'    => $post_type,
				'post_title'   => isset($request['title']) ? sanitize_text_field($request['title']) : '',
				'post_content' => isset($request['content']) ? $request['content'] : '',
				'post_status'  => isset($request['status']) ? sanitize_text_field($request['status']) : 'draft',
			);

			// Handle the optional slug parameter
			if (isset($request['slug'])) {
				$params['post_name'] = sanitize_text_field($request['slug']);
			}

			// Process categories (names as strings)
			if (isset($request['categories']) && is_array($request['categories'])) {
				$category_ids = array();
				foreach ($request['categories'] as $category_name) {
					// Try to get the category by name
					$category = get_term_by('name', sanitize_text_field($category_name), 'category');
					if ($category) {
						// Existing category, use its ID
						$category_ids[] = $category->term_id;
					} else {
						// New category, create it
						$new_category = wp_insert_term(sanitize_text_field($category_name), 'category');
						if (!is_wp_error($new_category)) {
							$category_ids[] = $new_category['term_id'];
						}
					}
				}
				$params['post_category'] = $category_ids;
			}

			// Process tags (names as strings)
			if (isset($request['tags']) && is_array($request['tags'])) {
				$tag_ids = array();
				foreach ($request['tags'] as $tag_name) {
					// Try to get the tag by name
					$tag = get_term_by('name', sanitize_text_field($tag_name), 'post_tag');
					if ($tag) {
						// Existing tag, use its ID
						$tag_ids[] = $tag->term_id;
					} else {
						// New tag, create it
						$new_tag = wp_insert_term(sanitize_text_field($tag_name), 'post_tag');
						if (!is_wp_error($new_tag)) {
							$tag_ids[] = $new_tag['term_id'];
						}
					}
				}
				$params['tax_input'] = array('post_tag' => $tag_ids);
			}

			if (isset($request['author']) && is_numeric($request['author'])) {
				$params['post_author'] = (int) $request['author'];
			}


			// If this is an update, include the post ID
			if ($is_update) {
				$post_id = (int) $request['id'];
				if (!get_post($post_id)) {
					return new WP_REST_Response(array(
						'message' => 'Post not found'
					), 404);
				}
				$params['ID'] = $post_id;
			}

			// Create or update the post
			$result = $is_update ? wp_update_post($params, true) : wp_insert_post($params, true);

			if (is_wp_error($result)) {
				return new WP_REST_Response(array(
					'message' => $result->get_error_message()
				), 400);
			}

			// * update the meta description. 
			if (isset($request['meta_description'])) {
				update_post_meta($result, 'description', sanitize_text_field($request['meta_description']));
			}
			if (isset($request['featured_image'])) {
				$attachment_id = (int) $request['featured_image'];
				if ($attachment_id) {
					set_post_thumbnail($result, $attachment_id);
				}
			}

			// Fetch the newly created or updated post to return as a response
			$controller = new WP_REST_Posts_Controller($post_type);
			$request->set_param('id', $result);
			$response = $controller->prepare_item_for_response(get_post($result), $request);

			return $response;
		}



		/**
		 * @param WP_REST_Request $request
		 *
		 * @return bool
		 */
		public static function create_post_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function create_post(WP_REST_Request $request)
		{
			return self::handle_post_creation_or_update($request, false);
		}

		/**
		 * Update an existing post
		 *
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response
		 */
		public static function update_post(WP_REST_Request $request)
		{
			return self::handle_post_creation_or_update($request, true);
		}

		/**
		 * Permissions check for updating a post
		 *
		 * @param WP_REST_Request $request
		 * @return bool
		 */
		public static function update_post_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}


		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function get_media_permission_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function get_media(WP_REST_Request $request)
		{
			$controller = new WP_REST_Attachments_Controller('attachment');
			$response   = $controller->get_items($request);

			return $response;
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function create_media_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function create_media(WP_REST_Request $request)
		{
			$controller = new WP_REST_Attachments_Controller('attachment');
			$response   = $controller->create_item($request);

			return $response;
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function create_comment_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public static function create_comment(WP_REST_Request $request)
		{
			$controller = new WP_REST_Comments_Controller();
			$response   = $controller->create_item($request);

			return $response;
		}

		/**
		 * @param WP_REST_Request $request
		 *
		 * @return bool
		 */
		public static function validate_password_permissions_check(WP_REST_Request $request)
		{
			return self::checkAPIKeyAuth($request);
		}

		/**
		 * Validate user's password
		 *
		 * @param WP_REST_Request $request
		 * @return string
		 */
		public static function validate_user_password(WP_REST_Request $request)
		{
			if (!isset($request['password']) || !isset($request['user_id'])) {
				return [];
			}

			$password = $request['password'];
			$user     = get_user_by('id', $request['user_id']);
			if (wp_check_password($password, $user->user_pass)) {
				$data = array('result' => 'true');
			} else {
				$data = array('result' => 'false');
			}

			$response = rest_ensure_response($data);

			wp_send_json($response);
		}

		/**
		 * Check authorization
		 *
		 * @param string $token
		 * @param string $domain
		 * @return bool
		 */
		public static function checkAuthorization($token, $domain)
		{
			$body = [
				'token'  => $token,
				'domain' => $domain,
			];

			$body = wp_json_encode($body);

			$options = [
				'body'        => $body,
				'headers'     => [
					'Content-Type' => 'application/json',
				],
				'data_format' => 'body',
			];

			$request = wp_remote_post(WRITESONIC_CHECK_AUTH_URL, $options);

			$tmp = json_decode(wp_remote_retrieve_body($request), true);

			return (bool) $tmp;
		}
	}

	WPM_Writesonic_Integration::init();
}
