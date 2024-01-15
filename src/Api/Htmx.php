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
class Htmx
{
	private object $ollama;

	private int $timeout = 60;

	private string $user_settings_meta_key = 'ollama_user_settings';

	public function __construct()
	{
		$this->ollama = new Ollama();

		add_action('init', [$this, 'routeRequest']);
	}

	public function addChatLog($body, $json, int $post_id)
	{
		if ($post_id > 0) {
			$post = [
				'ID' => $post_id,
			];
		} else {
			$text_rank = new TextRankFacade();
			$stop_words = new English();
			$text_rank->setStopWords($stop_words);

			$stripped_json = wp_strip_all_tags($json['message']['content']);

			$summary = $text_rank->summarizeTextBasic($stripped_json);

			$post_title = !empty($summary) ? array_shift($summary) : 'Chat Log ' . date('Y-m-d H:i:s');

			$post = [
				'post_title' => $post_title,
				'post_excerpt' => wp_trim_excerpt($stripped_json),
				'post_type' => 'chat',
				'post_slug' => $this->uuid(),
				'post_status' => 'publish',
			];
		}

		$body['messages'][] = [
			'role' => $json['message']['role'],
			'content' => $json['message']['content'],
		];

		// add chat log to post_content
		ray($body['messages'])->label('body[\'messages\']');
		$post['post_content'] = json_encode($body['messages']);

		ray($post)->label('addChatLog() $post')->red();

		if ($post_id > 0) {
			$post_id = wp_update_post($post);
		} else {
			$post_id = wp_insert_post($post);
		}

		ray($post_id)->label('addChatLog() $post_id');

		if (is_wp_error($post_id)) {
			return false;
		}

		return $post_id;
	}

	public function checkRequest()
	{
		$headers = getallheaders();

		if (!isset($headers['HX-Request']) and !is_user_logged_in()) {
			return false;
		}

		return true;
	}

	public function checkUserInputs(array $inputs = ['model', 'prompt'])
	{
		// Check for input errors
		if (!isset($_POST['model']) and !isset($_POST['prompt'])) {
			$this->outputAssistantError('Please select a model and enter a prompt.');
			return false;
		} elseif (!isset($_POST['model'])) {
			$this->outputAssistantError('Please select a model.');
			return false;
		} elseif (!isset($_POST['prompt'])) {
			$this->outputAssistantError('Please enter a prompt.');
			return false;
		}

		// Check if inputs are empty
		if (empty($_POST['model']) or empty($_POST['prompt'])) {
			return false;
		}

		// All checks pass
		return true;
	}

	public function getAssistantAvatarImg($role)
	{
		return '<img src="' . $this->getAssistantAvatarUrl($role) . '" alt="gravatar">';
	}

	public function getAssistantAvatarUrl($role)
	{
		// Switch gravatar by role
		switch (strtolower($role)) {
			case 'assistant':
			case 'ollama':
				$url = OP_DIR_URL . 'assets/img/ollama-256.png';
				break;

			default:
				$url = OP_DIR_URL . 'assets/img/ollama-press-256.png';
				break;
		}

		return $url;
	}

	public function outputGenerate($endpoint = 'generate')
	{
		// Quick input validation
		if (!$this->checkUserInputs())
			return;

		// Sanitize inputs
		$model = sanitize_text_field($_POST['model']);
		$prompt = sanitize_text_field($_POST['prompt']);

		// Choose completion type
		switch ($endpoint) {
			case 'chat':
				$post_id = (int) sanitize_text_field((int)$_POST['chat_id'] > 0 ? $_POST['chat_id'] : 0);
				ray($post_id)->label('outputGenerate() $post_id');

				$messages = [];

				// if post_id > 0 then get post_content and add to messages
				if ($post_id > 0) {
					$post = get_post($post_id);

					if (!empty($post->post_content)) {
						$messages = json_decode($post->post_content, true);
						ray($messages)->label('outputGenerate() $messages');
					}
				}

				$message = [
					'role' => 'user',
					'content' => $prompt,
				];

				$messages[] = $message;

				$body = [
					'model' => $model,
					'messages' => $messages,
					'stream' => false,
				];

				break;

			case 'generate':
				// Build request body
				$body = [
					'model' => $model,
					'prompt' => $prompt,
					'stream' => false,
				];
				break;
		}

		// Build request options
		$options = [
			'endpoint' => $endpoint,
			'method' => 'POST',
			'body' => json_encode($body),
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'timeout' => $this->timeout,
		];

		// Start dialog HTML
		$op_time = time();
		$class = 'op-dialog-' . $op_time;
		$this->outputHtmlTag('start', 'div', $class);

		// add user chat message to body
		$this->outputUserMessage($prompt);

		// get assistant response
		$json = $this->ollama->decodeRemoteBody($options);

		// Choose completion output
		$response = $json['response'] ?? $json['message']['content'] ?? null;

		// add assistant response to body
		if ($response) {
			$this->outputAssistantResponse($response, $model);
		} else {
			$this->outputAssistantError();
		}

		// Save messages to chat log
		$post_id = $this->addChatLog($body, $json, $post_id);

		// End dialog HTML
		$this->outputPostScript($class);
		$this->outputHtmlTag('end');

		// We also output hidden field for replacement
		$this->outputHiddenFields($post_id);
	}

	public function outputAssistantError($message = '', $model = '', $role = 'System')
	{
		$this->outputAssistantResponse('Sorry but an error occurred during your last request.', $model, $role);
	}

	public function outputAssistantResponse(string $response, string $model = '', string $role = 'Ollama')
	{
		$out = '<div class="op-chat-message op-chat-message-assistant">';
		$out .= '<div class="op-chat-message-gravatar">' . $this->getAssistantAvatarImg($role) . '</div>';
		$out .= '<div class="op-chat-message-username">' . $role . ' ' . $this->getAssistantModel($model) . '</div>';
		$out .= '<div class="op-chat-message-response">' . $this->zeroScript($response) . '</div>';
		$out .= '</div>';

		echo $out;
	}

	public function outputChatLoad()
	{
		// Quick input validation
		if (!isset($_POST['chat_id']))
			return;

		// Sanitize inputs
		$post_id = (int) sanitize_text_field($_POST['chat_log_id']);

		// Get post
		$post = get_post($post_id);

		// Check if post exists
		if (!$post) {
			$this->outputAssistantError('Chat log not found.');
			return;
		}

		// Get post_content
		$messages = json_decode($post->post_content, true);

		// Start dialog HTML
		$this->outputHtmlTag('start');

		// Check if $messages is valid array
		if (is_array($messages)) {
			// Loop through messages
			foreach ($messages as $message) {
				// Check if message is from user or assistant
				if ($message['role'] == 'user') {
					$this->outputUserMessage($message['content']);
				} else {
					$this->outputAssistantResponse($message['content']);
				}
			}
		} else {
			$this->outputAssistantError('Error loading chat log, log may be corrupted.');
			return;
		}

		// End dialog HTML
		$this->outputHtmlTag('end');
		$this->outputHiddenFields($post_id);
	}

	public function outputChatLogs()
	{
		// get posts by current user
		$posts = get_posts([
			'author' => get_current_user_id(),
			'post_type' => 'chat',
			'numberposts' => -1,

		]);

		// if posts output chat logs in foreach loop for select items, if not output empty disabled select option
		echo '<option value="" disabled>Chat History</option>';
		echo '<option value="0" selected>New Chat</option>';

		if ($posts) {
			$last_optgroup = '';
			foreach ($posts as $post) {
				$interval = date_diff(date_create($post->post_date), date_create('now'))->days;

				if ($interval <= 1) {
					$optgroup = 'Today';
				} elseif ($interval <= 2) {
					$optgroup = 'Yesterday';
				} elseif ($interval <= 7) {
					$optgroup = 'This Week';
				} elseif ($interval <= 14) {
					$optgroup = 'Last Week';
				} elseif ($interval <= 30) {
					$optgroup = 'This Month';
				} elseif ($interval <= 60) {
					$optgroup = 'Last Month';
				} elseif ($interval <= 90) {
					$optgroup = 'Last 3 Months';
				} elseif ($interval <= 180) {
					$optgroup = 'Last 6 Months';
				} elseif ($interval <= 365) {
					$optgroup = 'Last Year';
				} else {
					$optgroup = 'Older';
				}

				if ($optgroup != $last_optgroup) {
					$last_optgroup = $optgroup;
					echo '<optgroup label="' . $optgroup . '">';
				}

				echo '<option value="' . $post->ID . '">' . wp_trim_words($post->post_title, 8, '') . '</option>';

				if ($optgroup != $last_optgroup) {
					echo '</optgroup>';
				}
			}
		}
	}

	public function outputPostScript($class)
	{
		echo '<script type="text/javascript">';
		echo 'document.querySelector(".' . $class . '").scrollIntoView({behavior: "smooth", block: "end", inline: "nearest"});';
		echo '</script>';
	}

	public function outputHtmlTag($action = 'start', $tag = 'div', $class = 'op-dialog', $id = 'op-dialog',)
	{
		switch ($action) {
			case 'start':
				echo '<' . $tag . ' id="' . $id . '" class="' . $class . '">';
				break;

			case 'end':
				echo '</' . $tag . '>';
				break;
		}
	}

	public function outputHiddenFields(int $post_id)
	{
		echo '<input type="hidden" name="chat_id" id="chat_id" value="' . (string) $post_id . '">';
	}

	public function outputHxMultiSwapLoadChat(string $endpoint = 'chat', string $trigger = 'click')
	{
		echo 'hx-post="' . $this->getRenderEndpoint($endpoint) . '" hx-trigger="' . $trigger . '" hx-ext="multi-swap" hx-swap="multi:#op-dialog:outerHTML,#chat_id:outerHTML" hx-disabled-elt="this" hx-indicator="#indicator"';
	}

	public function outputRenderEndpoint($endpoint)
	{
		echo $this->getRenderEndpoint($endpoint);
	}

	public function outputUserMessage(string $message)
	{
		$user = wp_get_current_user();
		$gravatar = get_avatar_url($user->user_email, ['size' => 128]);

		$out = '<div class="op-chat-message op-chat-message-user">';
		$out .= '<div class="op-chat-message-gravatar"><img src="' . $gravatar . '" alt="gravatar"></div>';
		$out .= '<div class="op-chat-message-username">' . $user->user_login . '</div>';
		$out .= '<div class="op-chat-message-response">' . $this->zeroScript($message) . '</div>';
		$out .= '</div>';

		echo $out;
	}

	public function outputTags($tag = 'option')
	{
		$json = $this->ollama->decodeRemoteBody(['endpoint' => 'tags']);

		// get user default model
		$default_model = $this->getUserSetting('default_model');

		// if no models found then output disabled option
		if (!isset($json['models']) or empty($json['models'])) {
			echo '<' . $tag . ' value="disabled" disabled>No models found</' . $tag . '>';
			return;
		}

		// What are we doing?
		echo '<' . $tag . ' value="disabled" disabled>Select a model</' . $tag . '>';

		// Loop through models and output options
		foreach ($json['models'] as $model) {
			if ($model['name'] == $default_model) {
				echo '<' . $tag . ' value="' . $model['name'] . '" selected>' . $model['name'] . '</' . $tag . '>';
				continue;
			}
			echo '<' . $tag . ' value="' . $model['name'] . '">' . $model['name'] . '</' . $tag . '>';
		}
	}

	public function getRenderEndpoint($endpoint)
	{
		return get_bloginfo('url') . '/ollama/' . $endpoint . '/';
	}

	public function isRunning()
	{
		if ($this->ollama->isRunning()) {
			echo '🟢 Online';
		} else {
			echo '🔴 Offline';
		}
	}

	public function renderOutput()
	{
		switch ($_SERVER['REQUEST_URI']) {
			case '/ollama/chat/':
				$this->outputGenerate('chat');

				// if debugging and is admin
				if (WP_DEBUG and current_user_can('administrator')) {
					$this->outputDebug();
				}
				break;

			case '/ollama/generate/':
				$this->outputGenerate();
				break;

			case '/ollama/tags/':
				$this->outputTags();
				break;

			case '/ollama/wp/chat/':
				$this->outputChatLoad();
				break;

			case '/ollama/wp/user/update/':
				$this->userSettingsUpdate();
				break;

			case '/ollama/wp/chats/':
				$this->outputChatLogs();
				break;
		}
	}

	private function outputDebug()
	{
		// ray($_SERVER)->label('$_SERVER')->orange();
		// ray($_POST)->label('$_POST')->orange();
		// ray($_GET)->label('$_GET')->orange();
		// ray($_REQUEST)->label('$_REQUEST')->orange();
		// ray($_FILES)->label('$_FILES')->orange();
		// ray($_COOKIE)->label('$_COOKIE')->orange();
		// ray($_ENV)->label('$_ENV')->orange();
		// ray($_SESSION)->label('$_SESSION')->orange()

		// chat post_type count
		$count = wp_count_posts('chat');
		ray($count->publish)->label('chat post_type count')->orange();
	}

	public function routeRequest()
	{
		if (!$this->checkRequest()) {
			return false;
		}

		$this->renderOutput();
	}

	// Store user settings in ollama_user_settings serialized array in user meta
	public function userSettingsUpdate()
	{
		// Check if user is logged in
		if (!is_user_logged_in() or !isset($_POST['set_default_model']) or !isset($_POST['model'])) {
			echo '<span>Error validating entry.</span>';
			return false;
		}

		// Get current user
		$user_id = get_current_user_id();

		// Get user settings
		$user_settings = $settings = get_user_meta($user_id, $this->user_settings_meta_key, true);

		// If user settings are empty then create new array
		if (empty($user_settings)) {
			$user_settings = [];
		}

		// Get user inputs
		$inputs = [
			$this->user_settings_meta_key => [
				'default_model' => sanitize_text_field($_POST['model'] ?? null),
			],
		];

		// Loop through inputs and update user settings
		foreach ($inputs as $meta_key => $meta_value) {
			foreach ($meta_value as $key => $value) {
				$user_settings[$key] = $value;
			}
		}

		// Check if settings changed, if not return false
		if ($user_settings == $settings) {
			echo 'Set as default';
			return;
		}

		// Update user meta
		$response = update_user_meta($user_id, $this->user_settings_meta_key, $user_settings);

		// Output updated user settings
		if ($response) {
			$time = time();
			$class = 'fadeOut-' . $time;
			echo 'Set as default <span class="' . $class . '">✔︎</span>';
			echo '<script type="text/javascript">';
			echo 'setTimeout(function() {';
			echo 'document.querySelector(".' . $class . '").classList.add("fadeOut");';
			echo '}, 2400);';
			echo '</script>';
		} else {
			echo '<span class="fadeOut">Error updating user settings.</span>';
		}
	}

	private function getAssistantModel(string $model = '', $wrap = 'span')
	{
		if (!empty($model)) {
			return '<' . $wrap . ' class="model">' . $model . '</' . $wrap . '>';
		}
	}

	private function getUserSetting(string $option, $default = null)
	{
		$user_id = get_current_user_id();
		$user_settings = get_user_meta($user_id, $this->user_settings_meta_key, true);
		return $user_settings[$option] ?? null;
	}

	// https://www.uuidgenerator.net/dev-corner/php
	private function uuid($data = null): string
	{
		// Generate 16 bytes (128 bits) of random data or use the data passed into the function.
		$data = $data ?? random_bytes(16);
		assert(strlen($data) == 16);

		// Set version to 0100
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		// Set bits 6-7 to 10
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		// Output the 36 character UUID.
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	private function zeroScript(string $message): string
	{
		return '<zero-md><script type="text/markdown">' . $message . '</script></zero-md>';
	}
}
