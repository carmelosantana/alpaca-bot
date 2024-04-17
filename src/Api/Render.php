<?php

declare(strict_types=1);

namespace AlpacaBot\Api;

use AlpacaBot\Api\Ollama;
use AlpacaBot\Utils\Options;
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

	private string $user_settings_meta_key;

	private array $post = []; // Validated POST data

	public function __construct(private int $user_id, object $request = null)
	{
		$this->user_settings_meta_key = Options::appendPrefix('user_settings');
		$this->ollama = new Ollama();
	}

	public function getSummarizedTitle(string $message, $title_prefix = 'Chat Log')
	{
		$message = wp_strip_all_tags($message);

		switch (Options::get('title_generation_method')) {
			case 'ollama':
				$summary = $this->getSummaryOllama($message);
				break;

			default:
				$summary = $this->getSummaryTextRank($message);
				break;
		}

		return !empty($summary) ? array_shift($summary) : $title_prefix . ' ' . gmdate('Y-m-d H:i:s');
	}

	public function getSummaryOllama(string $message)
	{
		// Send text generation request to Ollama
		// Prompt: Quickly summarize the text into a single short sentence.
		// use model used for chat
		$body = [
			'model' => $this->getPostInput('model'),
			'prompt' => '[INST]Summarize this text into a single short sentence.[/INST]' . $message,
		];

		// get assistant response
		$response = $this->ollama->apiGenerate($body);

		// return response
		return $response;
	}

	public function getSummaryTextRank(string $message)
	{
		$text_rank = new TextRankFacade();
		$stop_words = new English();
		$text_rank->setStopWords($stop_words);

		$stripped_json = wp_strip_all_tags($message);

		return $text_rank->summarizeTextBasic($stripped_json);
	}

	public function addChatLog(string $model, string $prompt, array $json, int $post_id)
	{
		// check if saving is enabled
		if (!Options::get('chat_history_save')) {
			return $post_id;
		}

		if ($post_id > 0) {
			$post = [
				'ID' => $post_id,
			];
		} else {
			$message_content = $json['message']['content'] ?? '';
			$post_title = $this->getSummarizedTitle($message_content);
			$stripped_json = wp_strip_all_tags($message_content);

			$post = [
				'post_title' => $post_title,
				'post_excerpt' => wp_trim_excerpt($stripped_json),
				'post_type' => 'chat_history',
				'post_slug' => wp_generate_uuid4(),
				'post_status' => 'publish',
			];
		}

		// build user message matching api response
		$user_message = [
			'model' => $model,
			'message' => [
				'role' => get_current_user_id(),
				'content' => $prompt,
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

		if ($chat_mode = $this->getPostInput('chat_mode')) {
			$chat_mode = 'chat_mode_' . $chat_mode; // 'chat_mode_chat' or 'chat_mode_generate
			update_post_meta($post_id, $chat_mode, true);
		}

		if (is_wp_error($post_id)) {
			return false;
		}

		return $post_id;
	}

	public function checkUserInputs()
	{
		// Model should not be set if user cannot change model
		if (Options::get('user_can_change_model') === false) {
			$this->setPostInput('model', false);
		}

		// Check for input errors
		if (!$this->getPostInput('model') and !$this->getPostInput('prompt')) {
			$this->outputAssistantErrorDialog('Please select a model and enter a prompt.');
			return false;
		} elseif (!$this->getPostInput('model')) {
			if (Options::get('user_can_change_model') === false and Options::get('default_model')) {
				// If user can change model and default model is set then set model to default model
				$this->setPostInput('model', Options::get('default_model'));
			} else {
				// If user cannot change model or model isn't sent and default model is not set then output error
				$this->outputAssistantErrorDialog('Ask your system administrator to select a default model.');
				return false;
			}
		} elseif (!$this->getPostInput('prompt')) {
			$this->outputAssistantErrorDialog('Please enter a prompt.');
			return false;
		}

		// Check if inputs are empty
		if (empty($this->getPostInput('model')) or empty($this->getPostInput('prompt'))) {
			return false;
		}

		// All checks pass
		return true;
	}

	public function getAssistantAvatarImg($role)
	{
		return '<img src="' . esc_url($this->getAssistantAvatarUrl($role)) . '" alt="gravatar">';
	}

	public function getAssistantAvatarUrl($role)
	{
		// Switch gravatar by role
		switch (strtolower($role)) {
			case 'assistant':
			case 'ollama':
				$url = ALPACA_BOT_DIR_URL . 'assets/img/ollama-large.png';
				break;

			default:
				$url = ALPACA_BOT_DIR_URL . 'assets/img/alpaca-bot-512.png';
				break;
		}

		return $url;
	}

	public function getHxMultiSwapLoadChat(string $endpoint = 'htmx/chat', string $trigger = 'click')
	{
		return ' hx-post="' . esc_attr($this->getRenderEndpoint($endpoint)) . '" hx-trigger="' . esc_attr($trigger) . '" hx-ext="multi-swap" hx-swap="multi:#ab-response:beforeend,#chat_id:outerHTML" hx-disabled-elt="this" hx-indicator="#indicator"';
	}

	public function getPostInput(string $name, $default = false)
	{
		return $this->post[$name] ?? $default;
	}

	public function getRenderEndpoint(string $endpoint = '', string $version = 'v1')
	{
		return get_rest_url(null, ALPACA_BOT . '/' . $version . '/' . $endpoint);
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

	public function outputAdminNotice($message = '', $class = 'notice-success')
	{
		$out = '<div class="notice ' . esc_attr($class) . ' is-dismissible" id="' . esc_attr('ab-notice-' . uniqid()) . '"><p>' . $message . '</p></div>';

		echo wp_kses($out, 'post');
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

	public function outputChatHistory()
	{
		// get posts by current user
		$args = [
			'author' => get_current_user_id(),
			'post_type' => 'chat_history',
			'numberposts' => 128,
			'orderby' => 'date',
			'order' => 'DESC',
		];

		// check input chat_mode
		$mode = $this->getPostInput('chat_mode');
		switch ($mode) {
			case 'generate':
				$chat_mode = 'chat_mode_generate';
				$description = 'Select previous response';
				$new = 'New Generation';
				break;

			default:
				$chat_mode = 'chat_mode_chat';
				$description = 'Select previous chat';
				$new = 'New Chat';
				break;
		}

		// WordPress.DB.SlowDBQuery.slow_db_query_meta_query 
		// Improving meta_query performance https://docs.wpvip.com/code-quality/querying-on-meta_value/
		$args['meta_query'] = [
			[
				'key' => $chat_mode,
				'compare' => 'EXISTS',
			],
		];

		$posts = get_posts($args);

		echo '<option value="" disabled>' . esc_html($description) . '</option>';
		echo '<option value="0" selected>' . esc_html($new) . '</option>';

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
					if ($last_optgroup != '') {
						echo '</optgroup>';
					}
					$last_optgroup = $optgroup;
					echo '<optgroup label="' . esc_attr($optgroup) . '">';
				}

				echo '<option value="' . esc_attr($post->ID) . '">' . esc_html(wp_trim_words($post->post_title, 8, '')) . '</option>';

				if ($post === end($posts)) {
					echo '</optgroup>';
				}
			}
		}
	}

	public function outputChatLoad()
	{
		// Quick input validation
		if (!$this->getPostInput('chat_history_id')) {
			return;
		}

		// Open wrapper and dialog
		$this->outputDialogStart();

		// Sanitize inputs
		$post_id = (int) $this->getPostInput('chat_history_id');

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

	public function outputChatMessage(array|string $message)
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
				$tools .= '<span aria-label="Regenerate response" id="chat_regenerate" class="tools hint--bottom hint--rounded material-symbols-outlined" ' . $this->getHxMultiSwapLoadChat('htmx/regenerate', 'click') . ' onclick="promptResubmit(\'' . $response_id . '\')">';
				$tools .= 'autorenew';
				$tools .= '</span>';

				// edit response, send to message
				$tools .= '<span aria-label="Edit prompt" class="tools hint--bottom hint--rounded material-symbols-outlined" onclick="promptEdit(\'' . $response_id . '\')">';
				$tools .= 'edit';
				$tools .= '</span>';
				break;

			case 'assistant':
				switch ($this->getPostInput('chat_mode')) {
					case 'generate':
						if ($default_avatar = Options::get('default_avatar')) {
							$gravatar = apply_filters(Options::appendPrefix('default_avatar'), $default_avatar);
						}
						break;
				}

				// on click get innerhtml from response and send to wp/post/insert
				$tools .= '<span aria-label="Save to post" class="hint--bottom hint--rounded">';
				$tools .= '<span class="tools material-symbols-outlined rotate-push-pin" hx-post="' . $this->getRenderEndpoint('wp/post/insert') . '" hx-vars="post_content:getResponseInnerHTML(\'' . $response_id . '\')" hx-target="#' . $response_id . '" hx-swap="afterend">';
				$tools .= 'push_pin';
				$tools .= '</span>';
				$tools .= '</span>';

				// add page with note_add icon
				$tools .= '<span aria-label="Save to page" class="tools hint--bottom hint--rounded material-symbols-outlined" hx-post="' . $this->getRenderEndpoint('wp/page/insert') . '" hx-vars="post_content:getResponseInnerHTML(\'' . $response_id . '\')" hx-target="#' . $response_id . '" hx-swap="afterend">';
				$tools .= 'content_copy';
				$tools .= '</span>';

			default:
				if (!isset($gravatar)) {
					$gravatar = $this->getAssistantAvatarUrl($message['message']['role']);
				}
				if (!isset($user_name)) {
					$user_name = $message['message']['role'];
				}
				if (!empty($message['model'])) {
					$user_name = $user_name . ' <span class="model">' . $message['model'] . '</span>';
				}
				break;
		}

		$class = 'ab-chat-message-' . $role;

		$out .= '<div class="ab-chat-message ' . $class . '" id="ab-chat-message-' . $uuid . '">';
		$out .= '<div class="ab-chat-message-gravatar"><img src="' . $gravatar . '" alt="gravatar"></div>';
		$out .= '<div class="ab-chat-message-parts">';
		$out .= '<div class="ab-chat-message-username">' . $user_name . '</div>';
		$out .= '<div class="ab-chat-message-response" id="' . $response_id . '">' . $this->parseResponse($response) . '</div>';
		$out .= '<div class="ab-chat-message-tools">';
		$out .= $tools;
		$out .= '</div>';	// .ab-chat-message-tools
		$out .= '</div>';	// .ab-chat-message-parts
		$out .= '</div>';	// .ab-chat-message

		echo wp_kses($out, Options::getAllowedTags());
	}

	public function outputDialogEnd()
	{
		// End .ab-dialog
		echo '</div>';

		// End #ab-response
		echo '</div>';
	}

	// Add #ab-response wrapper used for innerHTML replacement and opens .ab-dialog
	public function outputDialogStart()
	{
		$time = time();

		// open wrapper
		echo '<div id="ab-response">';

		// add user chat message to body
		echo '<div class="ab-dialog" id="ab-dialog-' . esc_attr($time) . '">';

		return $time;
	}

	public function outputGenerate(string $endpoint = ''): void
	{
		// Quick input validation
		if (!$this->checkUserInputs())
			return;

		// Sanitize inputs
		$model = $this->getPostInput('model');
		$original_prompt = $this->getPostInput('prompt');

		// if endpoint is empty, check chat_mode input
		if (empty($endpoint)) {
			$endpoint = $this->getPostInput('chat_mode', 'chat');
		}

		// Filter prompt
		$prompt = apply_filters(Options::appendPrefix('user_prompt'), stripslashes($original_prompt));

		$post_id = (int) $this->getPostInput('chat_id', 0);

		// Build request body
		$body = [
			'model' => $model,
		];

		// check if images were uploaded
		if ($this->getPostInput('images')) {
			// add image to $message
			$image_ids = $this->getPostInput('images');

			// foreach image, get local file path and base64 encode, trim extra ,
			$image_ids = explode(',', $image_ids);

			// remove empty values
			$image_ids = array_filter($image_ids);

			$images = [];

			foreach ($image_ids as $image) {
				// get thumbnail img for prompt
				$img_src = wp_get_attachment_image_url($image, 'medium');

				// build thumbnail
				$thumbnail = '<img src="' . esc_url($img_src) . '" alt="image" class="chat-image">';

				// add thumbnail to prompt
				$prompt .= $thumbnail;

				// get local file path of image with post id with wp
				$image = get_attached_file($image);

				// base64 encode image 
				$image = base64_encode(file_get_contents($image));

				// add to array
				$images[] = $image;
			}
		}

		// Choose completion type, checks POST chat_mode fist, then $endpoint
		switch ($this->getPostInput('chat_mode', $endpoint)) {
			case 'generate':
				// Build request body
				$body['prompt'] = $prompt;

				// add image to body
				if (isset($images)) {
					$body['images'] = $images;
				}

				// get assistant response
				$json = $this->ollama->apiGenerate($body, 'array');
				break;

			default:
				// if post_id > 0 then get post_content and add to messages
				if ($post_id > 0) {
					$messages_raw = get_post_meta($post_id, 'messages', true);
				}

				// process messages to match api request
				if (isset($messages_raw) and is_array($messages_raw)) {
					// limit chat history
					if (Options::getPlaceholder('chat_history_limit') > 0) {
						$messages_raw = array_slice($messages_raw, -Options::getPlaceholder('chat_history_limit'));
					}

					foreach ($messages_raw as $message) {
						$messages[] = [
							'role' => (is_int($message['message']['role']) ? 'user' : 'assistant'),
							'content' => $message['message']['content'],
						];
					}
				} else {
					$messages = [];
				}

				// build message for chat
				$message = [
					'role' => 'user',
					'content' => $prompt,
				];

				// add images
				if (isset($images)) {
					$message['images'] = $images;
				}

				// add message to messages
				$messages[] = $message;

				$body['messages'] = $messages;

				// get assistant response
				$json = $this->ollama->apiChat($body);
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
		}

		// add assistant response to body
		if ($json) {
			$this->outputChatMessage($json);
		} else {
			$this->outputAssistantError();
		}

		// Save messages to chat log
		$post_id = $this->addChatLog($model, $prompt, $json, $post_id, $image);

		// Close dialog and wrapper
		$this->outputDialogEnd();

		// We also output hidden field for replacement
		$this->outputHiddenFields($post_id);
	}

	public function outputHiddenFields(int $post_id)
	{
		echo '<input type="hidden" name="chat_id" id="chat_id" value="' . esc_attr($post_id) . '">';
	}

	public function outputPostInsert($post_type = 'post')
	{
		// check if post_content is set	and not empty
		if (!$this->getPostInput('post_content')) {
			$this->outputAdminNotice('Post content is empty.', 'notice-error');
			return;
		}

		// insert into post as draft
		$post_content = $this->getPostInput('post_content');
		$post_id = wp_insert_post([
			'post_content' => $post_content,
			'post_title' => $this->getSummarizedTitle($post_content),
			'post_status' => 'draft',
			'post_type' => $post_type,
		]);

		// if post_id is not an integer then output error
		if (!is_int($post_id)) {
			$this->outputAdminNotice('Error inserting post.', 'notice-error');
			return;
		}

		// output success message with link to post
		$this->outputAdminNotice(ucfirst($post_type) . ' drafted. <a href="' . get_edit_post_link($post_id) . '">Edit ' . ucfirst($post_type) . '</a>');
	}

	public function outputTags($tag = 'option')
	{
		$models = $this->ollama->getModels();

		// get user default model
		$default_model = $this->getUserSetting('default_model', Options::get('default_model'));

		// if no models found then output disabled option
		if (!$models or empty($models)) {
			echo '<' . esc_html($tag) . ' value="disabled" disabled>No models found</' . esc_html($tag) . '>';
			return;
		}

		// What are we doing?
		echo '<' . esc_html($tag) . ' value="disabled" disabled>Select a model</' . esc_html($tag) . '>';

		// Loop through models and output options
		foreach ($models as $model) {
			$size = number_format($model['size'] / 1000000000, 2) . ' GB';

			$name = $model['name'] . ' (' . $size . ')';
			$name = str_replace(':latest', '', $name);

			if ($model['name'] == $default_model) {
				echo '<' . esc_html($tag) . ' value="' . esc_attr($model['name']) . '" selected>' . esc_html($name) . '</' . esc_html($tag) . '>';
				continue;
			}

			echo '<' . esc_html($tag) . ' value="' . esc_attr($model['name']) . '">' . esc_html($name) . '</' . esc_html($tag) . '>';
		}
	}

	public function parseResponse(string $response)
	{
		// Use parse down to convert to HTML
		$Parsedown = new \Parsedown();

		// Parse response
		$parsed_response = $Parsedown->text($response);

		// Return parsed response
		return $parsed_response;
	}

	public function setPost($post)
	{
		$this->post = $post;
	}

	public function setPostInput($name, $value)
	{
		$this->post[$name] = $value;
	}

	public function userSettingsUpdate()
	{
		// Check if user is logged in
		if (!$this->getPostInput('set_default_model') or !$this->getPostInput('model')) {
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
				'default_model' => $this->getPostInput('model', null),
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
			echo 'Settings updated ✔︎';
		} else {
			echo 'Error updating user settings.';
		}
	}
}
