<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress;

class Options
{
    public function getSections()
    {
        return [
            'api' => __('API', OP),
            'chat' => __('Chat', OP),
        ];
    }

    public static function appendPrefix(string $key)
    {
        return 'ollama_' . $key;
    }

    public static function default()
    {
        return [
            'api_url' => [
                'description' => __('Ollama API URL', OP),
                'type' => 'text',
                'placeholder' => 'http://localhost:11434',
                'section' => 'api',
                'env' => defined('OLLAMA_API_URL') ? OLLAMA_API_URL : null,
            ],
            'api_key' => [
                'description' => __('API key (leave blank if not needed)', OP),
                'type' => 'text',
                'section' => 'api',
            ],
            'default_model' => [
                'description' => __('Default model', OP),
                'type' => 'select',
                'options' => $models ?? [],
                'section' => 'chat',
            ],
            'user_can_change_model' => [
                'description' => __('Can users change model?', OP),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', OP),
                    'false' => __('No', OP),
                ],
                'section' => 'chat',
                'default' => 'true',
            ],
            'default_system_message' => [
                'description' => __('Default system message', OP),
                'type' => 'text',
                'placeholder' => __('How can I help you today?', OP),
                'section' => 'chat',
            ],
            'default_message_placeholder' => [
                'description' => __('Default message placeholder', OP),
                'type' => 'text',
                'placeholder' => __('Start chatting with Ollama', OP),
                'section' => 'chat',
            ],
        ];
    }

    // get option, check default and placeholder values using collapsing, apply any filters, and return
    public static function get(string $key)
    {
        $value = get_option(self::appendPrefix($key), (self::default()[$key]['env'] ?: self::default()[$key]['default'] ?: self::default()[$key]['placeholder'] ?: null));
        return apply_filters(self::appendPrefix($key), $value);
    }
}
