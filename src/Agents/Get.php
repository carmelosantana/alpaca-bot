<?php

declare(strict_types=1);

namespace AlpacaBot\Agents;

use AlpacaBot\Utils\Options;
use AlpacaBot\Api\Render;

class Get extends Agent
{
    public function job($atts = [], $content = ''): string
    {
        $url = $atts['url'] ?? '';

        $args = [];

        if (Options::get('user_agent')) {
            $args['user-agent'] = Options::get('user_agent');
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return 'Error: ' . $response->get_error_message();
        }

        // retrieve meta tags from body
        $meta = get_meta_tags($url);

        // retrieve body
        $body = wp_remote_retrieve_body($response);

        // strip all HTML tags
        $body = wp_strip_all_tags($body, true);

        // loop through meta tags and add to content
        $meta_tags = 'Metadata: ' . PHP_EOL;
        foreach ($meta as $name => $value) {
            $meta_tags .= $name . ': ' . $value . PHP_EOL;
        }

        // add meta tags to content
        $content = $meta_tags;
        $content .= 'Body: ' . PHP_EOL . $body;

        // wrap content in div to toggle visibility
        $id = 'shortcode-' . md5(wp_json_encode($atts));

        // Output raw data to Alpaca or HTML to end user
        switch ($atts['raw'] ?? false) {
            case true:
                return $content;
                break;

            default:
                // add show/hide content
                $content = '<div class="' . Options::appendPrefix('shortcode-processed', '-') . '" id="' . $id . '" data-url="' . $url . '" style="display: none;">' . $content . '</div>';

                // Close and reopen zero so we can manipulate the incoming content
                $content = '<button onclick="showHide(\'' . $id . '\')">Show Work</button>' . $content;
                break;
        }

        // return output
        return $content;
    }

    public function schema($agents = []): array
    {
        $agents['get'] = [
            'title' => 'Get',
            'description' => 'Retrieve the content of a remote page.',
            'arguments' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL of the page to retrieve.'
                ]
            ],
            'icon' => 'download_for_offline',
            'callback' => [$this, 'job'],
            'examples' => [
                [
                    '[alpacabot_agent name=get url=https://example.com]',
                    'Retrieves the <code>body</code> content and <code>metadata</code> from the <i>url</i> provided.'
                ],
                [
                    '[alpacabot model=llama2]
    What do you think of this webpage? [alpacabot_agent name=get url=https://example.com]
[/alpaca]',
                    'Retrieves the <code>body</code> content and <code>metadata</code> from the <i>url</i> provided and passes it to the <strong>llama2</strong> <i>model</i>.'
                ],
            ],
            'references' => [
                'get_meta_tags' => 'https://www.php.net/manual/en/function.get-meta-tags.php',
                'wp_remote_get' => 'https://developer.wordpress.org/reference/functions/wp_remote_get/',
                'wp_strip_all_tags' => 'https://developer.wordpress.org/reference/functions/wp_strip_all_tags/',
            ]
        ];

        return $agents;
    }
}
