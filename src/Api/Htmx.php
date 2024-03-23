<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Api;

/**
 * HTMX API
 * 
 * @package AlpacaBot
 * @since 0.1.0
 */
class Htmx extends Base
{
	private object $render;

	private int $user_id = 0;

	private array $post = [];

	public function __construct()
	{
		add_action('rest_api_init', [$this, 'registerRoutes']);
	}

	public function registerRoutes(\WP_REST_Server $server)
	{
		// foreach loop for endpoints
		$routes = [
			'/htmx/chat' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::CREATABLE,
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/htmx/generate' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::CREATABLE,
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/htmx/regenerate' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::CREATABLE,
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/htmx/tags' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::READABLE,
				'permission_callback' => [$this, 'get_item_permissions_check'],
			],
			'/wp/chat' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::CREATABLE,
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/wp/history' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::READABLE,
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/wp/user/update' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::CREATABLE,
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/wp/page/insert' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::CREATABLE,
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/wp/post/insert' => [
				'callback' => [$this, 'renderOutput'],
				'methods' => $server::CREATABLE,
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
		];

		foreach ($routes as $route => $options) {
			register_rest_route(self::NAMESPACE, $route, $options);
		}
	}

	public function renderOutput(\WP_REST_Request $request)
	{
		// remove REST API JSON content type
		header_remove('Content-Type');
		// add text/html content type
		header('Content-Type: text/html; charset=' . get_option('blog_charset'), true);

		// normalize URLs, removing trailing and forward slashes from $request->get_route()
		$request_url = ltrim(rtrim($request->get_route(), '/'), '/');

		// setup render object
		$render = new Render($this->user_id);
		$render->setPost($this->validatePost($request_url));

		switch ($request_url) {
			case self::NAMESPACE . '/htmx/chat':
				$render->outputGenerate();
				break;

			case self::NAMESPACE . '/htmx/regenerate':
				$render->outputGenerate('regenerate');
				break;

			case self::NAMESPACE . '/htmx/tags':
				$render->outputTags();
				break;

			case self::NAMESPACE . '/wp/chat':
				$render->outputChatLoad();
				break;

			case self::NAMESPACE . '/wp/history':
				$render->outputChatHistory();
				break;

			case self::NAMESPACE . '/wp/page/insert':
				$render->outputPostInsert('page');
				break;

			case self::NAMESPACE . '/wp/post/insert':
				$render->outputPostInsert();
				break;

			case self::NAMESPACE . '/wp/user/update':
				$render->userSettingsUpdate();
				break;
		}
		exit();
	}

	public function validateNonce()
	{
		// validate nonce in header
		if (!isset($_SERVER['HTTP_X_WP_NONCE']) or !wp_verify_nonce(sanitize_text_field($_SERVER['HTTP_X_WP_NONCE']), 'wp_rest')) {
			return false;
		}

		return true;
	}

	public function validatePost()
	{
		$allowed_arguments = [
			'chat_history_id',
			'chat_id',
			'model',
			'post_content',
			'prompt',
			'set_default_model',
		];

		$post = [];

		// validate nonce in header
		if (!$this->validateNonce()) {
			wp_send_json_error('Invalid nonce.', 403);
		}

		// validate post arguments
		foreach ($allowed_arguments as $arg) {
			if (isset($_POST[$arg])) {
				$post[$arg] = sanitize_text_field($_POST[$arg]);
			}
		}

		return $post;
	}
}
