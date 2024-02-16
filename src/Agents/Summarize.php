<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Agents;

use CarmeloSantana\AlpacaBot\Agents\Get;

class Summarize extends Agent
{
    public function job($args = [], $content = ''): string
    {
        // use actionWpRemoteGet
        $summary = (new Get)->job($args);

        // if error pass along 'Error: '
        if (strpos($summary, 'Error: ') === 0) {
            return $summary;
        }

        // starting prompt
        $prompt = 'Please summarize this ' . $this->getArg($args, 'content') . ' from ' . $this->getArg($args, 'url');

        // add length to prompt
        if (!empty($args['length'])) {
            $prompt .= ' in ' . $args['length'];
        }

        // add text to prompt
        $prompt .= ': ' . $summary;

        return $prompt;
    }

    // list of filterable agents
    public function schema($agents = []): array
    {
        $agents['summarize'] = [
            'title' => 'Summarize',
            'description' => 'Summarize the content of a remote page.',
            'arguments' => [
                'content' => [
                    'type' => 'string',
                    'default' => 'webpage',
                    'description' => 'Type of content we\'re summarizing. (article, blog post, research paper, webpage)'
                ],
                'length' => [
                    'type' => 'string',
                    'default' => '',
                    'description' => 'Describe the length of the summary. (2 sentences, 3 paragraphs, 200 words, 5 bullet points)'
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL of the page to summarize.'
                ],
            ],
            'icon' => 'ink_highlighter_move',
            'callback' => [$this, 'job'],
            'examples' => [
                [
                    '[agent summarize url="https://example.com" length="2 paragraphs" format="text"]',
                    'Summarize the content of the <i>url</i> provided and pass it to the next agent or prompt.'
                ],
                [
                    '[alpaca agent=summarize url="https://example.com" length="2" format="list"]',
                    'Call the Summarize agent outside of chat.'
                ]
            ],
            'references' => (new Get)->schema()['get']['references'],
        ];

        return $agents;
    }
}
