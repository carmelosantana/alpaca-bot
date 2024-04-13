<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Api\Cache;
use CarmeloSantana\AlpacaBot\Api\Ollama;
use CarmeloSantana\AlpacaBot\Utils\Options;

class Agents
{
    private array $agents = [];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'adminPageAdd']);
        add_filter(Options::appendPrefix('user_prompt'), [$this, 'hookUserPrompt']);
        add_shortcode('alpacabot_agent', [$this, 'router']);
        add_shortcode('alpacabot', [$this, 'router']);

        // Core agents
        (new Agents\Get())->init();
        (new Agents\Summarize())->init();
    }

    public function adminPageAdd()
    {
        add_submenu_page(
            ALPACA_BOT,
            'Agents',
            'Agents',
            'manage_options',
            Options::appendPrefix('agents', '-'),
            [$this, 'adminPageRender'],
            2
        );
    }

    // adminPageRender using accordion from pulling $this->getAgents()
    public function adminPageRender()
    {
        // HTML
        echo '<div class="wrap ' . esc_attr(ALPACA_BOT  . ' ' . Options::appendPrefix('options', '-')) . '">';
        echo '<h1>Agents</h1>';
        echo '<p>Agents are shortcodes that help Alpaca Bot perform tasks.</p>';
        echo '<div class="ab-accordion">';

        $agents = $this->getAgents();

        foreach ($agents as $slug => $agent) {
            $icon = $agent['icon'] ?? 'person_apron';

            echo '<button class="accordion-btn">';
            echo '<span class="material-symbols-outlined">' . esc_html($icon) . '</span>';
            echo ' <code>' . esc_html($slug) . '</code> ';
            echo esc_html($agent['description']);
            echo '</button>';
            echo '<div class="panel">';

            if (isset($agent['arguments'])) {
                echo '<h3>Arguments</h3>';
                echo '<ul>';
                foreach ($agent['arguments'] as $name => $arg) {
                    echo '<li><i>' . esc_html($name) . '</i> <code>' . esc_html($arg['type']) . '</code> ' . wp_kses($arg['description'], Options::getAllowedTags('p')) . '</li>';
                }
                echo '</ul>';
            }

            if (isset($agent['examples'])) {
                echo '<h3>Examples</h3>';

                foreach ($agent['examples'] as $example) {
                    echo '<pre><code class="language-shortcode">' . esc_html($example[0]) . '</code></pre>';
                    echo '<p>' . wp_kses($example[1], Options::getAllowedTags('p')) . '</p>';
                    echo '<hr>';
                }
            }

            if (isset($agent['references'])) {
                echo '<h3>References</h3>';
                echo '<ul>';
                foreach ($agent['references'] as $title => $url) {
                    echo '<li><a href="' . esc_url($url) . '" target="_blank">' . esc_html($title) . '</a></li>';
                }
                echo '</ul>';
            }

            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    public function getAgents()
    {
        $this->agents = array_merge($this->getCoreAgents(), $this->getCustomAgents());

        return $this->agents;
    }

    public function getCoreAgents()
    {
        return apply_filters(Options::appendPrefix('core_agents'), []);
    }

    public function getCustomAgents()
    {
        return apply_filters(Options::appendPrefix('custom_agents'), []);
    }

    public function hookUserPrompt($prompt)
    {
        $out = do_shortcode($prompt);
        return $out;
    }

    public function router($atts, $content = '', $tag = '')
    {
        // Do not process during autosave (this may not be necessary)
        if (defined('DOING_AUTOSAVE') and DOING_AUTOSAVE) {
            return;
        }

        // Do not process during post save
        if (current_action() === 'render_block_core/shortcode' and did_action('save_post') >= 1) {
            return;
        }

        $cache = new Cache($atts, $content, $tag);

        $response = $cache->get();

        if ($response) {
            return $response;
        }

        switch ($tag) {
            case 'alpacabot_agent':
                $response = $this->routerAgent($atts, $content, $tag);
                break;
            case 'alpacabot':
                $response = $this->routerAlpaca($atts, $content, $tag);
                break;
            default:
                return 'Error: Tag not found.';
        }

        if (Options::validateValue($response)) {
            $cache->set($response);
            return $response;
        } else {
            return 'Error: No content returned.';
        }

        return $response;
    }

    public function routerAgent($atts, $content = '', $tag = '')
    {
        $agents = $this->getAgents();

        // Check if agent is the first argument
        if (isset($agents[$atts[0] ?? null])) {
            $agent = $agents[$atts[0]];
        } elseif (isset($agents[$atts['agent'] ?? null])) {
            $agent = $agents[$atts['agent']];
        } elseif (isset($agents[$atts['name'] ?? null])) {
            $agent = $agents[$atts['name']];
        } else {
            return 'Error: Agent not found.';
        }

        // set defaults
        foreach ($agent['arguments'] as $name => $arg) {
            if (isset($arg['default'])) {
                $atts[$name] = $atts[$name] ?? $arg['default'];
            }
        }

        // if valid callback $agent['callback']
        if (is_callable($agent['callback'])) {
            $content = call_user_func($agent['callback'], $atts, $content);
        }

        return $content;
    }

    public function routerAlpaca($atts, $content = '', $tag = '')
    {
        $def = [
            'model' => Options::get('default_model'),
            'prompt' => do_shortcode($content),
        ];

        $atts = wp_parse_args($atts, $def);

        $ollama = new Ollama();

        return $ollama->apiGenerate($atts);
    }
}
