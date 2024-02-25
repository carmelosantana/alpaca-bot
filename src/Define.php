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

            if ($models) {
                return $models;
            }

            $url = Options::get('api_url') . '/api/tags';
            $response = wp_remote_get($url);

            $body = wp_remote_retrieve_body($response);
            $body = json_decode($body, true);

            // if ['models'] exists loop and set each key value to model[name]
            if (isset($body['models'])) {
                $models = [];
                foreach ($body['models'] as $model) {
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
                echo '<p class="description">' . __('Invalid URL', AB_SLUG) . '</p>';
            } elseif (preg_match('/^"?(Ollama is running)"?$/', $body)) {
                if ($header_x_ollama) {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">verified</span><span>' . __('Alpaca Bot Proxy connection established.', AB_SLUG) . '</span></p>';
                } else {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">check_circle</span><span>' . __('Verified connection.', AB_SLUG) . '</span></p>';
                }
            } else {
                echo '<p class="description"><span class="material-symbols-outlined label-error">error</span><span>' . __('Invalid response.', AB_SLUG) . '</span></p>';
            }
        } elseif (empty($api_url)) {
            echo '<p class="description"><span class="material-symbols-outlined">edit</span><span>' . __('Please enter a URL.', AB_SLUG) . '</span></p>';
        }
    }

    public static function fields()
    {
        return [
            'api_url' => [
                'label' => __('Ollama API URL', AB_SLUG),
                'description' => __('The URL of your <a href="https://github.com/ollama/ollama">Ollama</a> installation.', AB_SLUG),
                'placeholder' => 'http://localhost:11434',
                'section' => 'api',
                'description_callback' => [__CLASS__, 'fieldApiUrlValidate'],
            ],
            'api_token' => [
                'label' => __('API Token', AB_SLUG),
                'description' => __('This is optional.', AB_SLUG),
                'section' => 'api',
            ],
            'default_model' => [
                'label' => __('Default Model', AB_SLUG),
                'type' => 'select',
                'options' => self::getModels(),
                'section' => 'chat',
            ],
            'user_can_change_model' => [
                'label' => __('Can users change model?', AB_SLUG),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', AB_SLUG),
                    'false' => __('No', AB_SLUG),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'save_chat_history' => [
                'label' => __('Save chat history?', AB_SLUG),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', AB_SLUG),
                    'false' => __('No', AB_SLUG),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'default_system_message' => [
                'label' => __('Default system message', AB_SLUG),
                'placeholder' => __('How can I help you today?', AB_SLUG),
                'section' => 'chat',
            ],
            'default_message_placeholder' => [
                'label' => __('Default message placeholder', AB_SLUG),
                'placeholder' => __('Start chatting with Abie', AB_SLUG),
                'section' => 'chat',
            ],
        ];
    }

    public static function sections()
    {
        return [
            'api' => [
                'title' => __('API', AB_SLUG),
                'description' => __('Configure your <a href="https://github.com/ollama/ollama">Ollama</a> settings.', AB_SLUG),
            ],
            'chat' => [
                'title' => __('Chat', AB_SLUG),
                'description' => __('Customize the user experience.', AB_SLUG),
            ],
        ];
    }
}
