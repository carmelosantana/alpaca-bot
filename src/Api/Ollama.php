<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress\Api;

use CarmeloSantana\OllamaPress\Options;

class Ollama
{
    private function getApiUrl()
    {
        $url = Options::get('api_url');
        
        // check for trailing slash, add if missing
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }

        return $url;
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
