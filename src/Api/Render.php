<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Api;

use CarmeloSantana\AlpacaBot\Api\Ollama;
use CarmeloSantana\AlpacaBot\Utils\Options;
use PhpScience\TextRank\TextRankFacade;
use PhpScience\TextRank\Tool\StopWords\English;

/**
 * HTMX API
 * 
 * @package AlpacaBot
 * @since 0.1.0
 */
class Render
{
	private object $ollama;

	private int $timeout = 60;

	private string $user_settings_meta_key;

	public function __construct(private int $user_id, object $request = null)
	{
		$this->user_settings_meta_key = Options::appendPrefix('user_settings');
		$this->ollama = new Ollama();
	}

	public function getSummarizedTitle(string $message, $title_prefix = 'Chat Log')
	{
		$message = wp_strip_all_tags($message);
		$summary = $this->getSummary($message);

		return !empty($summary) ? array_shift($summary) : $title_prefix . ' ' . date('Y-m-d H:i:s');
	}

	public function getSummary(string $message)
	{
		$text_rank = new TextRankFacade();
		$stop_words = new English();
		$text_rank->setStopWords($stop_words);

		$stripped_json = wp_strip_all_tags($message);

		return $text_rank->summarizeTextBasic($stripped_json);
	}

	public function addChatLog($body, $json, int $post_id)
	{
		// check if saving is enabled
		if (!Options::get('save_chat_history')) {
			return $post_id;
		}

		if ($post_id > 0) {
			$post = [
				'ID' => $post_id,
			];
		} else {
			$post_title = $this->getSummarizedTitle($json['message']['content']);
			$stripped_json = wp_strip_all_tags($json['message']['content']);

			$post = [
				'post_title' => $post_title,
				'post_excerpt' => wp_trim_excerpt($stripped_json),
				'post_type' => 'chat',
				'post_slug' => $this->uuid(),
				'post_status' => 'publish',
			];
		}

		// build user message matching api response
		$user_message = [
			'model' => $body['model'],
			'message' => [
				'role' => get_current_user_id(),
				'content' => $body['message']['content'],
			]
		];

		if ($post_id > 0) {
			$post_id = wp_update_post($post);
		} else {
			$post_id = wp_insert_post($post);
		}

		// pull existing meta, add new meta, update meta
		$meta = get_post_meta($post_id, 'messages', true);

		if (!is_array($meta)) $meta = [];

		$meta[] = $user_message;
		$meta[] = $json;

		update_post_meta($post_id, 'messages', $meta);

		if (is_wp_error($post_id)) {
			return false;
		}

		return $post_id;
	}

	public function checkUserInputs(array $inputs = ['model', 'prompt'])
	{
		// Model should not be set if user cannot change model
		if (Options::get('user_can_change_model') == false) {
			unset($_POST['model']);
		}

		// Check for input errors
		if (!isset($_POST['model']) and !isset($_POST['prompt'])) {
			$this->outputAssistantErrorDialog('Please select a model and enter a prompt.');
			return false;
		} elseif (!isset($_POST['model'])) {
			if (Options::get('user_can_change_model') and Options::get('default_model')) {
				$_POST['model'] = Options::get('default_model');
			} else {
				$this->outputAssistantErrorDialog('Ask your system administrator to select a default model.');
				return false;
			}
		} elseif (!isset($_POST['prompt'])) {
			$this->outputAssistantErrorDialog('Please enter a prompt.');
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
				$url = AB_DIR_URL . 'assets/img/ollama-large.png';
				break;

			default:
				$url = AB_DIR_URL . 'assets/img/alpaca-bot-512.png';
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
		$original_prompt = sanitize_text_field($_POST['prompt']);

		// Filter prompt
		$prompt = apply_filters(Options::appendPrefix('user_prompt'), stripslashes($original_prompt));

		// Choose completion type
		switch ($endpoint) {
			case 'chat':
			case 'regenerate':
				$post_id = (int) sanitize_text_field($_POST['chat_id'] ?? 0);

				// if post_id > 0 then get post_content and add to messages
				if ($post_id > 0) {
					$messages_raw = get_post_meta($post_id, 'messages', true);
				}

				// process messages to match api request
				if (isset($messages_raw) and is_array($messages_raw)) {
					foreach ($messages_raw as $message) {
						$messages[] = [
							'role' => (is_int($message['message']['role']) ? 'user' : 'assistant'),
							'content' => $message['message']['content'],
						];
					}
				} else {
					$messages = [];
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

		// Open wrapper and dialog
		$dialog_id = $this->outputDialogStart();

		// add user chat message to body
		switch ($endpoint) {
			case 'chat':
			case 'generate':
				$this->outputChatMessage(['message' => ['role' => get_current_user_id(), 'content' => $prompt, 'dialog_id' => $dialog_id]]);
				break;

			case 'regenerate':
				$endpoint = 'chat';
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

		// get assistant response
		$json = $this->ollama->decodeRemoteBody($options);

		// add assistant response to body
		if ($json) {
			$this->outputChatMessage($json);
		} else {
			$this->outputAssistantError();
		}

		// Save messages to chat log
		$post_id = $this->addChatLog(['model' => $model, 'message' => $message], $json, $post_id);

		// Close dialog and wrapper
		$this->outputDialogEnd();

		// We also output hidden field for replacement
		$this->outputHiddenFields($post_id);
	}

	public function outputDialogEnd()
	{
		// End .ab-dialog
		$this->outputHtmlTag(false);
		$this->outputPostScript();

		// End #ab-response
		$this->outputHtmlTag(false);
	}

	// Add #ab-response wrapper used for innerHTML replacement and opens .ab-dialog
	public function outputDialogStart()
	{
		$time = time();

		$this->outputHtmlTag([
			'id' => 'ab-response',
		]);

		// add user chat message to body
		$this->outputHtmlTag([
			'class' => 'ab-dialog',
			'id' => 'ab-dialog-' . $time,
		]);

		return $time;
	}

	public function outputAssistantError($message = '', $model = '', $role = 'System')
	{
		if (empty($message)) {
			$message = 'Sorry but an error occurred during your last request.';
		}

		$this->outputChatMessage([
			'message' => [
				'role' => $role,
				'content' => $message,
			],
			'model' => $model,
		]);
	}

	public function outputAssistantErrorDialog($message = '', $model = '', $role = 'System')
	{
		// Open wrapper and dialog
		$this->outputDialogStart();

		// Output error message
		$this->outputAssistantError($message, $model, $role);

		// Close dialog and wrapper
		$this->outputDialogEnd();
	}

	public function outputChatLoad()
	{
		// Quick input validation
		if (!isset($_POST['chat_id']))
			return;

		// Open wrapper and dialog
		$this->outputDialogStart();

		// Sanitize inputs
		$post_id = (int) sanitize_text_field($_POST['chat_log_id']);

		// Get post
		$post = get_post($post_id);

		// Check if post exists
		if (!$post) {
			$this->outputAssistantError('Chat log not found.');
			return;
		}

		// Get post_meta messages
		$messages = get_post_meta($post_id, 'messages', true);

		// Check if $messages is valid array
		if (is_array($messages)) {
			// Loop through messages
			foreach ($messages as $message) {
				if (is_array($message))
					$this->outputChatMessage($message);
				else {
					$this->outputAssistantError('Error loading chat log, log may be corrupted.');
					// return;
				}
			}
		} else {
			$this->outputAssistantError('Error loading chat log, log may be corrupted.');
			return;
		}

		// Close dialog and wrapper
		$this->outputDialogEnd();

		// We also output hidden field for replacement
		$this->outputHiddenFields($post_id);
	}

	public function outputChatHistory()
	{
		// get posts by current user
		$posts = get_posts([
			'author' => get_current_user_id(),
			'post_type' => 'chat',
			'numberposts' => -1,

		]);
		ray(get_current_user_id())->label('outputChatHistory');

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

	public function outputChatMessage(array $message)
	{
		$out = $tools = '';
		// php function uuid
		$uuid = 'a' . uniqid();
		$response_id = 'response-' . $uuid;

		// is_chat
		if (isset($message['message'])) {
			if (is_int($message['message']['role'])) {
				$role = 'user';
			} else {
				$role = strtolower($message['message']['role']);
			}
			$response = $message['message']['content'];
		} else {
			$role = 'system';
			$response = $message['response'];
		}


		// Copy
		$tools .= '<span aria-label="Copy to clipboard" id="copy-' . $uuid . '" class="tools hint--bottom hint--rounded material-symbols-outlined" onclick="copyToClipboard(\'' . $uuid . '\')">';
		$tools .= 'content_paste';
		$tools .= '</span>';

		switch ($role) {
			case 'user':
				// if ID is 0, we load the current user, otherwise we load the user by ID
				$user = $message['message']['role'] == 0 ? get_user_by('id', $this->user_id) : get_user_by('id', $message['message']['role']);
				$gravatar = get_avatar_url($user->user_email, ['size' => 128]);
				$user_name = $user->user_login;

				// regenerate response, send previous message again
				$tools .= '<span aria-label="Regenerate response" class="tools hint--bottom hint--rounded material-symbols-outlined" ' . $this->getHxMultiSwapLoadChat('htmx/regenerate', 'click') . ' onclick="resubmitPrompt(\'' . $response_id . '\')">';
				$tools .= 'autorenew';
				$tools .= '</span>';
				break;

			case 'assistant':
				// on click get innerhtml from response and send to wp/post/insert
				$tools .= '<span aria-label="Save to post" class="hint--bottom hint--rounded">';
				$tools .= '<span class="tools material-symbols-outlined rotate-push-pin" ' . $this->getWpNonce('wp/post/insert') . ' hx-post="' . $this->getRenderEndpoint('wp/post/insert') . '" hx-vars="post_content:getResponseInnerHTML(\'' . $response_id . '\')" hx-target="#' . $response_id . '" hx-swap="afterend">';
				$tools .= 'push_pin';
				$tools .= '</span>';
				$tools .= '</span>';

				// add page with note_add icon
				$tools .= '<span aria-label="Save to page" class="tools hint--bottom hint--rounded material-symbols-outlined" ' . $this->getWpNonce('wp/page/insert') . ' hx-post="' . $this->getRenderEndpoint('wp/page/insert') . '" hx-vars="post_content:getResponseInnerHTML(\'' . $response_id . '\')" hx-target="#' . $response_id . '" hx-swap="afterend">';
				$tools .= 'content_copy';
				$tools .= '</span>';

			default:
				$gravatar = $this->getAssistantAvatarUrl($message['message']['role']);
				$user_name = $message['message']['role'];
				if (!empty($message['model'])) {
					$user_name .= ' ' . $this->getAssistantModel($message['model']);
				}
				break;
		}

		$class = 'ab-chat-message-' . $role;

		$out .= '<div class="ab-chat-message ' . $class . '" id="ab-chat-message-' . $uuid . '">';
		$out .= '<div class="ab-chat-message-gravatar"><img src="' . $gravatar . '" alt="gravatar"></div>';
		$out .= '<div class="ab-chat-message-parts">';
		$out .= '<div class="ab-chat-message-username">' . $user_name . '</div>';
		$out .= '<div class="ab-chat-message-response" id="' . $response_id . '">' . self::zeroScript($response) . '</div>';
		$out .= '<div class="ab-chat-message-tools">';
		$out .= $tools;
		$out .= '</div>';	// .ab-chat-message-tools
		$out .= '</div>';	// .ab-chat-message-parts
		$out .= '</div>';	// .ab-chat-message

		echo $out;
	}

	public function getToolTip($message = '', $class = 'ab-tooltip-text')
	{
		return '<div class="' . $class . '">' . $message . '</div>';
	}

	public function getAdminNotice($message = '', $class = 'notice-success')
	{
		$id = 'ab-notice-' . uniqid();

		$out = '<div class="notice ' . $class . ' is-dismissible" id="' . $id . '"><p>' . $message . '</p></div>';

		return $out;
	}

	public function outputPostScript(string $class = '')
	{
		echo '<script type="text/javascript">smoothScrollTo(' . $class . ');</script>';
	}

	public function outputScriptShowHide(string $id)
	{
		echo '<script type="text/javascript">showHide(' . $id . ');</script>';
	}

	public function outputHtmlTag($attributes = [])
	{
		$defaults = [
			'element' => true,
			'tag' => 'div',
			'class' => null,
			'id' => null,
		];

		if (!is_array($attributes) and !$attributes) {
			$attributes = [
				'element' => false,
			];
		}

		$attributes = wp_parse_args($attributes, $defaults);

		// Extract $attributes_options as variables
		extract($attributes);

		if ($element) {
			// only output id= or class= if values are not empty
			$id = !empty($id) ? ' id="' . $id . '"' : null;
			$class = !empty($class) ? ' class="' . $class . '"' : null;

			echo '<' . $tag . $id . $class . '>';
		} else {
			echo '</' . $tag . '>';
		}
	}

	public function outputHiddenFields(int $post_id)
	{
		echo '<input type="hidden" name="chat_id" id="chat_id" value="' . (string) $post_id . '">';
	}

	public function getHxMultiSwapLoadChat(string $endpoint, string $trigger)
	{
		return $this->getWpNonce($endpoint) . ' hx-post="' . $this->getRenderEndpoint($endpoint) . '" hx-trigger="' . $trigger . '" hx-ext="multi-swap" hx-swap="multi:#ab-response:beforeend,#chat_id:outerHTML" hx-disabled-elt="this" hx-indicator="#indicator"';
	}


	public function outputHxMultiSwapLoadChat(string $endpoint = 'htmx/chat', string $trigger = 'click')
	{
		echo $this->getHxMultiSwapLoadChat($endpoint, $trigger);
	}

	public function outputRenderEndpoint($endpoint)
	{
		$endpoint = $this->getRenderEndpoint($endpoint);
		echo $endpoint;
	}

	public function outputPostInsert($post_type = 'post')
	{
		// check if post_content is set	and not empty
		if (!isset($_POST['post_content']) or empty($_POST['post_content'])) {
			echo $this->getAdminNotice('Post content is empty.', 'notice-error');
			return;
		}

		// insert into post as draft
		$post_content = sanitize_text_field($_POST['post_content']);
		$post_id = wp_insert_post([
			'post_content' => $post_content,
			'post_title' => $this->getSummarizedTitle($post_content),
			'post_status' => 'draft',
			'post_type' => $post_type,
		]);

		// if post_id is not an integer then output error
		if (!is_int($post_id)) {
			echo $this->getAdminNotice('Error inserting post.', 'notice-error');
			return;
		}

		// output success message with link to post
		echo $this->getAdminNotice(ucfirst($post_type) . ' drafted. <a href="' . get_edit_post_link($post_id) . '">Edit ' . ucfirst($post_type) . '</a>');
	}

	public function getWpNonce($action = -1, $key = 'wp_rest',)
	{
		$nonce = wp_create_nonce($key, $action);
		return 'hx-headers=\'{"X-WP-Nonce": "' . $nonce . '"}\'';
	}

	public function outputWpNonce($action = -1, $key = 'wp_rest',)
	{
		echo $this->getWpNonce($action, $key);
	}

	public function outputTags($tag = 'option')
	{
		$json = $this->ollama->decodeRemoteBody(['endpoint' => 'tags']);

		// get user default model
		$default_model = $this->getUserSetting('default_model', Options::get('default_model'));

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
		return get_bloginfo('url') . '/wp-json/' . AB_SLUG . '/v1/' . $endpoint;
	}

	public function isRunning()
	{
		if ($this->ollama->isRunning()) {
			echo '🟢 Online';
		} else {
			echo '🔴 Offline';
		}
	}

	// Store user settings in alpaca_bot_user_settings serialized array in user meta
	public function userSettingsUpdate()
	{
		// Check if user is logged in
		if (!isset($_POST['set_default_model']) or !isset($_POST['model'])) {
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

		$value = Options::validateValue($user_settings[$option] ?? '');

		if ($value) {
			return $value;
		}

		return $default;
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

	/**
	 * Adds zero-md script for markdown rendering post response.
	 *
	 * @param  string $message
	 * @return string
	 */
	public static function zeroScript(string $message, string $position = ''): string
	{
		switch ($position) {
			case 'open':
				return '<zero-md><script type="text/markdown">';
				break;

			case 'close':
				return '</script></zero-md>';
				break;

			default:
				return '<zero-md><script type="text/markdown">' . $message . '</script></zero-md>';
				break;
		}
	}
}
