<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Api;

use CarmeloSantana\AlpacaBot\Api\Tools;
use CarmeloSantana\AlpacaBot\Utils\Options;

class Ollama
{
    // Add to log post type. save all values as post meta
    private function addToLog(array $message): int|false
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
            'post_type' => 'log',
            'post_status' => 'publish',
        ]);

        $keys = [
            'model',
            'total_duration',
            'load_duration',
            'prompt_eval_count',
            'prompt_eval_duration',
            'eval_count',
            'eval_duration',
        ];

        // if no post id, we have an error
        if (!$post_id) {
            return false;
        }

        foreach ($keys as $key) {
            switch ($key) {
                case 'model':
                    $value = $message[$key];
                    break;
                default:
                    $value = (int) $message[$key];
                    break;
            }
            update_post_meta($post_id, $key, $value);
        }

        return $post_id;
    }

    private function getApiUrl()
    {
        $url = Options::get('api_url');

        // check for trailing slash, add if missing
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }

        return $url;
    }

    public function generate($args = [])
    {
        $url = $this->getEndpoint('generate');

        if (!$url) {
            return false;
        }

        // convert booleans
        $args = array_map(function ($value) {
            if ($value === 'true') {
                return true;
            } elseif ($value === 'false') {
                return false;
            } else {
                return $value;
            }
        }, $args);

        // hardcode
        $hardcode = [
            'stream' => false,
            'keep_alive' => '5m',
        ];
        $args = wp_parse_args($hardcode, $args);

        // remove empty args
        $args = array_filter($args, function ($value) {
            return $value !== '';
        });

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];
        $options = Tools::addAuth($options);

        $response = wp_remote_post($url, [
            'body' => json_encode($args),
            $options,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $response = json_decode(wp_remote_retrieve_body($response), true);
        $this->addToLog($response);

        return $response['response'];
    }

    public function decodeRemoteBody(array $options = [])
    {
        // WP extract args from $options array
        $options = wp_parse_args($options, [
            'endpoint' => '',
            'json_decode' => true,
            'method' => 'GET',
        ]);

        $url = $this->getEndpoint($options['endpoint']);

        // add auth to headers
        $options = Tools::addAuth($options);

        // make request
        $response = wp_remote_request($url, $options);

        // check for errors
        if (is_wp_error($response)) {
            return false;
        }

        $response = wp_remote_retrieve_body($response);

        if ($options['json_decode']) {
            $response = json_decode($response, true);
            $this->addToLog($response);
        }

        return $response;
    }

    public function getEndpoint($endpoint)
    {
        $allowed_endpoints = [
            '',    // default
            'generate',
            'chat',
            'tags',
        ];

        if (!in_array($endpoint, $allowed_endpoints)) {
            return false;
        }

        return $this->getApiUrl() . ($endpoint ? 'api/' . $endpoint : '');
    }

    public function isRunning()
    {
        $running = 'Ollama is running';
        $response = $this->decodeRemoteBody(['json_decode' => false]);

        if ($response == $running) {
            return true;
        }

        return false;
    }
}
