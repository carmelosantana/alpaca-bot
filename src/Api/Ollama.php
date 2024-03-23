<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Api;

use CarmeloSantana\AlpacaBot\Define;
use CarmeloSantana\AlpacaBot\Utils\Options;

class Ollama
{
    private $api_endpoints = [
        'chat',
        'embedding',
        'generate',
        'tags',
    ];

    private $api_url;

    private $allowed_parameters = [
        'chat' => [
            'model',
            'messages',
            'format',
            'options',
            'template',
            'stream',
            'keep_alive',
        ],
        'embedding' => [
            'model',
            'prompt',
            'options',
            'keep_alive',
        ],
        'generate' => [
            'model',
            'prompt',
            'images',
            'format',
            'options',
            'system',
            'template',
            'context',
            'stream',
            'raw',
            'keep_alive',
        ],
    ];

    private $cache_timeout = 60 * 5;

    private $log_keys = [
        'model',
        'total_duration',
        'load_duration',
        'prompt_eval_count',
        'prompt_eval_duration',
        'eval_count',
        'eval_duration',
    ];

    public function __construct()
    {
        $this->api_url = $this->getApiUrl();
    }

    /**
     * Adds Ollama token usage to log, no message data is stored.
     *
     * @param  array $message Response data to log
     * @return int|false The post id of the log entry or false on fail/disabled
     */
    public function addToLog(array $message): int|false
    {
        // check if logging is enabled
        if (!Options::get('log_chat_response')) {
            return false;
        }

        // if model is not set, we have an error
        if (!isset($message['model'])) {
            return false;
        }

        $post_id = wp_insert_post([
            'post_type' => 'chat_log',
            'post_status' => 'publish',
        ]);

        // if no post id, we have an error
        if (!$post_id) {
            return false;
        }

        foreach ($this->log_keys as $key) {
            $value = $message[$key] ?? null;
            if (Options::validateValue($value)) {
                if (is_numeric($value)) {
                    $value = (int) $value;
                }
                update_post_meta($post_id, $key, $value);
            }
        }

        return $post_id;
    }

    public function apiChat(array $args): array|false
    {
        $response = $this->run('chat', $args);

        return $response;
    }

    public function apiEmbedding(array $args): array|false
    {
        $response = $this->run('embedding', $args);

        return $response['embedding'] ?? false;
    }

    public function apiGenerate(array $args, string $output = 'string'): array|string
    {
        $response = $this->run('generate', $args);

        $response = $this->response($response);

        switch ($output) {
                // emulate chat array
            case 'array':
                return [
                    'message' => [
                        'content' => $response,
                        'role' => 'assistant',
                    ],
                    'model' => $args['model'],
                ];
            case 'string':
            default:
                return $response;
        }
    }

    public function apiTags(): array|false
    {
        $args = [
            'method' => 'GET',
        ];

        $url = $this->getEndpoint('tags');

        $response = $this->request($url, $args);

        return apply_filters(Options::appendPrefix('ollama-tags'), $response['models'] ?? false);
    }

    /**
     * Adds Content-Type and Authorization headers if username and password are set.
     *
     * @return array
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        $username = Options::get('api_username');
        $password = Options::get('api_password');

        if ($username and $password) {
            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
        }

        return $headers;
    }

    /**
     * Builds the parameters for the API request.
     * Validates and merges custom parameters with default parameters.
     * Removes any empty parameters.
     * Removes any parameters that are not allowed.
     *
     * @param  array $args Parameters to send to the API
     * @param  string $section Use section to check for allowed parameters
     * @return array
     */
    private function buildParameters(array $args, string $section = ''): array
    {
        // Validate all options
        $args = array_map([Options::class, 'validateValue'], $args);

        // Default API parameters
        $parameters = [
            'stream' => apply_filters(Options::appendPrefix('ollama-stream'), false),
            'keep_alive' => apply_filters(Options::appendPrefix('ollama-keep_alive'), '5m'),
        ];

        // if generate, we can check for system
        if ($section === 'generate') {
            $parameters['system'] = do_shortcode(apply_filters(Options::appendPrefix('ollama-system'), Options::get('default_system', '')));
            $parameters['template'] = do_shortcode(apply_filters(Options::appendPrefix('ollama-template'), Options::get('default_template', '')));
        }

        // merge custom parameters with default parameters
        $args = wp_parse_args($args, $parameters);

        // Check for any custom model parameters in the options panel
        foreach (Define::getFieldsInSection('parameters') as $option => $field) {
            // remove default_ prefix
            $key = str_replace('default_', '', $option);

            // check args first, then option
            $value = $args[$key] ?? Options::get($option);

            if ($value) {
                $options[$key] = $value;
            }
        }

        // Add options
        if (!empty($options)) {
            // remove empty options
            $options = array_filter($options, function ($value) {
                return $value !== '';
            });

            // convert numeric strings to numbers
            $options = array_map(function ($value) {
                if (is_numeric($value) and strpos($value, '.') !== false) {
                    return (float) $value;
                } elseif (is_numeric($value)) {
                    return (int) $value;
                } else {
                    return $value;
                }
            }, $options);

            $args['options'] = $options;
        }

        // remove empty
        $args = array_filter($args, function ($value) {
            return $value !== '';
        });

        // remove any args that are not allowed
        if (isset($this->allowed_parameters[$section])) {
            $args = array_intersect_key($args, array_flip($this->allowed_parameters[$section]));
        }

        return $args;
    }

    public function getApiUrl(): string
    {
        $url = Options::get('api_url', '');

        // check for trailing slash, add if missing
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }

        return $url;
    }

    public function getEndpoint($endpoint): string|false
    {
        if (!in_array($endpoint, $this->api_endpoints)) {
            return false;
        }

        return $this->api_url . ($endpoint ? 'api/' . $endpoint : '');
    }

    public function getModelNameSize(array $model): string
    {
        $size = number_format($model['size'] / 1000000000, 2) . ' GB';

        $name = $model['name'] . ' (' . $size . ')';
        $name = str_replace(':latest', '', $name);

        return $name;
    }

    public function getModels(): array
    {
        $cache = get_transient(Options::appendPrefix('ollama-models'));

        if ($cache) {
            return apply_filters(Options::appendPrefix('ollama-tags'), $cache);
        }

        $models = $this->apiTags();

        if ($models and is_array($models) and !empty($models)) {
            set_transient(Options::appendPrefix('ollama-models'), $models, $this->cache_timeout);
            return apply_filters(Options::appendPrefix('ollama-tags'), $models);
        }

        return [];
    }

    /**
     * Builds request and sends to Ollama API.
     *
     * @param  string $url
     * @param  array $options
     * @param  bool $json_decode
     * @return array
     */
    private function request(string $url, array $options, bool $json_decode = true): array|false
    {
        $default = [
            'headers' => $this->buildHeaders(),
            'timeout' => Options::get('ollama_timeout', 60),
        ];

        $options = wp_parse_args($options, $default);

        $response = wp_remote_request($url, $options);

        if (is_wp_error($response)) {
            return false;
        }

        $response = wp_remote_retrieve_body($response);

        if ($json_decode and $response) {
            $response = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
        }

        return $response;
    }

    /**
     * Returns the 'response' key or error message from the Ollama API response.
     *
     * @param  array $response The response from the Ollama API
     * @return string
     */
    private function response(array $response): string
    {
        return $response['response'] ?? $response['error'] ?? __('Error: No response from Ollama.', 'alpaca-bot');
    }

    /**
     * Runs an API request that requires a model.
     *
     * @param  mixed $endpoint
     * @param  mixed $args
     * @return void
     */
    private function run(string $endpoint, array $args): array|false
    {
        $response = [];

        $args = $this->buildParameters($args, $endpoint);

        $options = [
            'body' => wp_json_encode($args),
            'method' => 'POST',
        ];

        $url = $this->getEndpoint($endpoint);

        $response = $this->request($url, $options);

        $this->addToLog($response);

        return $response;
    }
}
