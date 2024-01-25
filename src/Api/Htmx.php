<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress\Api;

use CarmeloSantana\OllamaPress\Api\Ollama;
use PhpScience\TextRank\TextRankFacade;
use PhpScience\TextRank\Tool\StopWords\English;

/**
 * HTMX API
 * 
 * @package OllamaPress
 * @since 0.1.0
 */
class Htmx extends Base
{
	private object $render;

	private int $user_id = 0;

	public function __construct()
	{
		$this->user_id = $this->cacheUserId();	// hack to store user ID for REST API
		add_action('rest_api_init', [$this, 'registerRoutes']);
	}

	public function cacheUserId($user_id = 0)
	{
		if ($this->user_id == 0 and get_current_user_id() > 0) {
			$this->user_id = get_current_user_id();
		}

		return $this->user_id;
	}

	public function getCachedUserId()
	{
		return $this->user_id;
	}

	public function getOrSetCurrentUserId()
	{
		// If user is NOT set
		if ($user_id = get_current_user_id() == 0) {
			$user_id = $this->user_id;
			wp_clear_auth_cookie();
			wp_set_current_user($user_id); // Set the current user detail
			wp_set_auth_cookie($user_id); // Set auth details in cookie
		}

		return get_user_by('id', $this->user_id);
	}

	public function registerRoutes(\WP_REST_Server $server)
	{
		// foreach loop for endpoints
		$endpoints = [
			'/htmx/chat' => [
				'methods' => 'POST',
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/htmx/generate' => [
				'methods' => 'POST',
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/htmx/tags' => [],
			'/wp/chat' => [
				'methods' => 'POST',
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
			'/wp/chats' => [],
			'/wp/user/update' => [
				'methods' => 'POST',
				'permission_callback' => [$this, 'update_item_permissions_check'],
			],
		];

		$default = [
			'callback' => [$this, 'renderOutput'],
			'permission_callback' => [$this, 'update_item_permissions_check'],
		];

		foreach ($endpoints as $endpoint => $options) {
			$options = array_merge($default, $options);
			register_rest_route(self::NAMESPACE, $endpoint, $options);
		}
	}

	public function renderOutput(\WP_REST_Request $request)
	{
		// remove application header type
		header_remove('Content-Type');

		// setup render object
		$this->render = new Render($this->user_id);

		switch ($request->get_route()) {
			case self::NAMESPACE . '/htmx/chat':
				$this->render->outputGenerate('chat');
				break;

			case self::NAMESPACE . '/htmx/generate':
				$this->render->outputGenerate();
				break;

			case self::NAMESPACE . '/htmx/tags':
				$this->render->outputTags();
				break;

			case self::NAMESPACE . '/wp/chat':
				$this->render->outputChatLoad();
				break;

			case self::NAMESPACE . '/wp/chats':
				$this->render->outputChatLogs();
				break;

			case self::NAMESPACE . '/wp/user/update':
				$this->render->userSettingsUpdate();
				break;
		}
		do_action('shutdown');
		exit();
	}
}
