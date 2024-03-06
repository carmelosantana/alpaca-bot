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
				'permission_callback' => [$this, 'update_item_permissions_check'],
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

		// setup render object
		$this->render = new Render($this->user_id);

		// normalize URLs, removing trailing and forward slashes from $request->get_route()
		$request_url = ltrim(rtrim($request->get_route(), '/'), '/');

		switch ($request_url) {
			case self::NAMESPACE . '/htmx/chat':
				$this->render->outputGenerate('chat');
				break;

			case self::NAMESPACE . '/htmx/generate':
				$this->render->outputGenerate();
				break;

			case self::NAMESPACE . '/htmx/regenerate':
				$this->render->outputGenerate('regenerate');
				break;

			case self::NAMESPACE . '/htmx/tags':
				$this->render->outputTags();
				break;

			case self::NAMESPACE . '/wp/chat':
				$this->render->outputChatLoad();
				break;

			case self::NAMESPACE . '/wp/history':
				$this->render->outputChatHistory();
				break;

			case self::NAMESPACE . '/wp/page/insert':
				$this->render->outputPostInsert('page');
				break;

			case self::NAMESPACE . '/wp/post/insert':
				$this->render->outputPostInsert();
				break;

			case self::NAMESPACE . '/wp/user/update':
				$this->render->userSettingsUpdate();
				break;
		}
		exit();
	}
}
