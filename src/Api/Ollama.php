<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Api;

use CarmeloSantana\AlpacaBot\Api\Tools;
use CarmeloSantana\AlpacaBot\Utils\Options;

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

    public function generate($args = [])
    {
        $url = $this->getEndpoint('generate');

        if (!$url) {
            return false;
        }

        // user args
        $def = [
            'model' => Options::get('default_model'),
            'prompt' => '',
        ];
        $args = wp_parse_args($args, $def);

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

        $headers = [
            'Content-Type' => 'application/json',
        ];

        $headers = Tools::addAuth($headers);

        $response = wp_remote_post($url, [
            'body' => json_encode($args),
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $response = json_decode(wp_remote_retrieve_body($response), true);

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
        $options['headers'] = Tools::addAuth($options['headers']);

        // make request
        $response = wp_remote_request($url, $options);

        // check for errors
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
