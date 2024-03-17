<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Api\Ollama;
use CarmeloSantana\AlpacaBot\Utils\Options;

class Define
{    
    public static function getModels()
    {
        if (Options::get('api_url')) {
            // get transient
            $models = get_transient('ollama_models');

            if ($models and is_array($models) and count($models) > 0) {
                return $models;
            }

            // get models from ollama
            $ollama = new Ollama();
            $response = $ollama->apiTags();

            // if ['models'] exists loop and set each key value to model[name]
            if ($response) {
                $models = [];
                foreach ($response as $model) {
                    $models[$model['name']] = $model['name'];
                }
                set_transient('ollama_models', $models, 60 * 60 * 5);
            }
        } else {
            $models = [];
        }

        return $models;
    }

    public static function fieldApiUrlValidate()
    {
        $api_url = Options::get('api_url');

        if ($api_url and !empty($api_url)) {
            $response = wp_remote_get($api_url);
            $body = wp_remote_retrieve_body($response);
            $header_x_ollama = wp_remote_retrieve_header($response, 'x-ollama-proxy');

            if (is_wp_error($response)) {
                echo '<p class="description"><span class="material-symbols-outlined">edit</span><span>' . esc_html__('Please enter a valid URL.', 'alpaca-bot') . '</span></p>';
            } elseif (preg_match('/^"?(Ollama is running)"?$/', $body)) {
                if ($header_x_ollama) {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">verified</span><span>' . esc_html__('Alpaca Bot Proxy connection established.', 'alpaca-bot') . '</span></p>';
                } else {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">check_circle</span><span>' . esc_html__('Verified connection.', 'alpaca-bot') . '</span></p>';
                }
            } else {
                echo '<p class="description"><span class="material-symbols-outlined label-error">error</span><span>' . esc_html__('Invalid response.', 'alpaca-bot') . '</span></p>';
            }
        } elseif (empty($api_url)) {
            $patreon = '<a href="' . esc_url(Define::support()['patreon']['url']) . '">' . Define::support()['patreon']['title'] . '</a>';
            echo '<p class="description"><span>No server? We got you covered! ' . wp_kses($patreon, Options::getAllowedTags()) . ' and share our community hosted instances.<span></p>';
        }
    }

    public static function fields()
    {
        return [
            'api_url' => [
                'label' => __('Ollama API URL', 'alpaca-bot'),
                'description' => __('The URL of your <a href="https://github.com/ollama/ollama">Ollama</a> installation.', 'alpaca-bot'),
                'placeholder' => 'http://localhost:11434',
                'section' => 'api',
                'description_callback' => [__CLASS__, 'fieldApiUrlValidate'],
            ],
            'api_username' => [
                'label' => __('API Username', 'alpaca-bot'),
                'description' => __('This is optional.', 'alpaca-bot'),
                'section' => 'api',
            ],
            'api_password' => [
                'label' => __('API Application Password', 'alpaca-bot'),
                'description' => __('This is optional.', 'alpaca-bot'),
                'section' => 'api',
                'type' => 'password',
            ],
            'ollama_timeout' => [
                'label' => __('Timeout', 'alpaca-bot'),
                'description' => __('The time in seconds to wait for a response from <a href="https://github.com/ollama/ollama">Ollama</a>.', 'alpaca-bot'),
                'section' => 'api',
                'type' => 'number',
                'placeholder' => 60,
            ],
            'default_model' => [
                'label' => __('Default Model', 'alpaca-bot'),
                'type' => 'select',
                'options' => self::getModels(),
                'section' => 'chat',
            ],
            'user_can_change_model' => [
                'label' => __('Can users change model?', 'alpaca-bot'),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', 'alpaca-bot'),
                    'false' => __('No', 'alpaca-bot'),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'save_chat_history' => [
                'label' => __('Save chat history?', 'alpaca-bot'),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', 'alpaca-bot'),
                    'false' => __('No', 'alpaca-bot'),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'log_chat_response' => [
                'label' => __('Log chat response?', 'alpaca-bot'),
                'description' => __('Log additional information provided by the completion. This does not include conversation history.', 'alpaca-bot'),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', 'alpaca-bot'),
                    'false' => __('No', 'alpaca-bot'),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'default_system_message' => [
                'label' => __('Default system message', 'alpaca-bot'),
                'placeholder' => __('How can I help you today?', 'alpaca-bot'),
                'section' => 'chat',
            ],
            'default_message_placeholder' => [
                'label' => __('Default message placeholder', 'alpaca-bot'),
                'placeholder' => __('Start chatting with Abie', 'alpaca-bot'),
                'section' => 'chat',
            ],
            'user_agent' => [
                'label' => __('User Agent', 'alpaca-bot'),
                'description' => __('Browser user agent to use when making requests.', 'alpaca-bot'),
                'section' => 'agents',
                'placeholder' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            ],
            'spellcheck' => [
                'label' => __('Spellcheck', 'alpaca-bot'),
                'description' => __('Enable spellcheck on the chat input.', 'alpaca-bot'),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', 'alpaca-bot'),
                    'false' => __('No', 'alpaca-bot'),
                ],
                'section' => 'privacy',
                'default' => false,
            ],
        ];
    }

    public static function sections()
    {
        return [
            'api' => [
                'title' => __('API', 'alpaca-bot'),
                'description' => __('Configure your <a href="https://github.com/ollama/ollama">Ollama</a> settings. ', 'alpaca-bot'),
            ],
            'chat' => [
                'title' => __('Chat', 'alpaca-bot'),
                'description' => __('Customize the user experience.', 'alpaca-bot'),
            ],
            'agents' => [
                'title' => __('Agents', 'alpaca-bot'),
                'description' => __('Manage your agents.', 'alpaca-bot'),
            ],
            'privacy' => [
                'title' => __('Privacy', 'alpaca-bot'),
                'description' => __('Privacy settings.', 'alpaca-bot'),
            ],
        ];
    }

    public static function support()
    {
        $support = [
            'discord' => [
                'description' => __('Join our <a href="https://discord.gg/vWQTHphkVt">Discord</a> community.', 'alpaca-bot'),
                'title' => __('Join our Discord', 'alpaca-bot'),
                'url' => 'https://discord.gg/vWQTHphkVt',
            ],
            'patreon' => [
                'description' => __('Support the development of this plugin by becoming a <a href="https://www.patreon.com/carmelosantana">Patreon</a>.', 'alpaca-bot'),
                'title' => __('Become a Patreon', 'alpaca-bot'),
                'url' => 'https://www.patreon.com/carmelosantana',
            ]
        ];

        return $support;
    }
}
