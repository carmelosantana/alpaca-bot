<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Agents;

class Get extends Agent
{
    public function job($args = [], $user_content = ''): string
    {
        $url = $args['url'] ?? '';

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return 'Error: ' . $response->get_error_message();
        }

        // retrieve meta tags from body
        $meta = get_meta_tags($url);

        // retrieve body
        $body = wp_remote_retrieve_body($response);

        // strip all HTML tags
        $body = wp_strip_all_tags($body);
        $body = strip_tags($body);

        // loop through meta tags and add to content
        $meta_tags = 'Metadata: ' . PHP_EOL;
        foreach ($meta as $name => $value) {
            $meta_tags .= $name . ': ' . $value . PHP_EOL;
        }

        // add meta tags to content
        $content = $meta_tags;
        $content .= 'Body: ' . PHP_EOL . $body;

        // return content
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
                    '[agent get url="https://example.com"]',
                    'Retrieves the <code>body</code> content and <code>metadata</code> from the <i>url</i> provided.'
                ],
                [
                    '[alpaca model=llama2]
    What do you think of this webpage? [agent get url="https://example.com"]
[/alpaca]',
                    'Retrieves the <code>body</code> content and <code>metadata</code> from the <i>url</i> provided and passes it to the <i>llama2</i> model.'
                ],
            ],
            'references' => [
                'get_meta_tags' => 'https://www.php.net/manual/en/function.get-meta-tags.php',
                'strip_tags' => 'https://www.php.net/manual/en/function.strip-tags.php',
                'wp_remote_get' => 'https://developer.wordpress.org/reference/functions/wp_remote_get/',
                'wp_strip_all_tags' => 'https://developer.wordpress.org/reference/functions/wp_strip_all_tags/',
            ]
        ];

        return $agents;
    }
}
