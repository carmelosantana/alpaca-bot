<?php

declare(strict_types=1);

namespace AlpacaBot\Agents;

use AlpacaBot\Agents\Get;
use AlpacaBot\Api\Ollama;
use AlpacaBot\Utils\Options;

class Summarize extends Agent
{
    public function job($atts = [], $content = ''): string
    {
        // use actionWpRemoteGet
        $summary = (new Get)->job($atts);

        // if error pass along 'Error: '
        if (strpos($summary, 'Error: ') === 0) {
            return $summary;
        }

        // starting prompt
        $prompt = 'Please summarize this ' . $this->getArg($atts, 'content') . ' from ' . $this->getArg($atts, 'url');

        // add length to prompt
        if (!empty($atts['length'])) {
            $prompt .= ' in ' . $atts['length'];
        }

        // add text to prompt
        $prompt .= ': ' . $summary;

        // Build args for Ollama
        $args = [
            'prompt' => $prompt,
            'model' => $atts['model'] ?? Options::get('default_model'),
        ];
                
        // send prompt to Ollama
        $content = (new Ollama)->apiGenerate($args);

        return $content;
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
                'model' => [
                    'type' => 'string',
                    'default' => '',
                    'description' => 'The model to use for summarization.'
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
                    '[alpacabot_agent name=summarize url=https://example.com length="2 paragraphs"]',
                    'Summarize the content of the <i>url</i> provided and pass it to the next agent or prompt.'
                ],
                [
                    '[alpacabot_agent name=summarize url=https://example.com length="3 bullet points"]',
                    'Call the Summarize agent outside of chat.'
                ]
            ],
            'references' => (new Get)->schema()['get']['references'],
        ];

        return $agents;
    }
}
