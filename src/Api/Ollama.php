<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress\Api;

use CarmeloSantana\OllamaPress\Options;

class Ollama
{
    private function getApiUrl()
    {
        return Options::get('ollama_api_url') ?: getenv('OLLAMA_API_URL') ?: (defined('OLLAMA_API_URL') ? OLLAMA_API_URL : false);
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
        $response = wp_remote_request($url, $options);

        if (is_wp_error($response)) {
            ray($options, $url, $response)->label('decodeRemoteBody')->red();
            return false;
        }

        $body = wp_remote_retrieve_body($response);

        if ($options['json_decode']) {
            return json_decode($body, true);
        } else {
            return $body;
        }
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
