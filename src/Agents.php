<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Api\Cache;
use CarmeloSantana\AlpacaBot\Api\Ollama;

class Agents
{
    private array $agents = [];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'adminPageAdd']);
        add_filter(Options::prefixUnderscore('user_prompt'), [$this, 'hookUserPrompt']);
        add_shortcode('agent', [$this, 'router']);
        add_shortcode('alpaca', [$this, 'router']);

        // Core agents
        (new Agents\Get())->init();
        (new Agents\Summarize())->init();
    }

    public function adminPageAdd()
    {
        add_submenu_page(
            AB_SLUG,
            'Agents',
            'Agents',
            'manage_options',
            Options::prefixDash('agents'),
            [$this, 'adminPageRender'],
            1
        );
    }

    // adminPageRender using accordion from pulling $this->getAgents()
    public function adminPageRender()
    {
        // Scripts
        wp_enqueue_script('prism', AB_DIR_URL . 'assets/js/prism.min.js', [], '1.29.0', true);
        wp_enqueue_style('prism', AB_DIR_URL . 'assets/css/prism.css', [], '1.29.0');

        // HTML
        echo '<div class="wrap ' . AB_SLUG  . ' ' . Options::prefixDash('options') . '">';
        echo '<h1>Agents</h1>';
        echo '<p>Agents are shortcodes that help Alpaca Bot perform tasks.</p>';
        echo '<div class="ab-accordion">';

        $agents = $this->getAgents();

        foreach ($agents as $slug => $agent) {
            $icon = $agent['icon'] ?? 'person_apron';
            $icon = '<span class="material-symbols-outlined">' . $icon . '</span>';

            echo '<button class="accordion-btn">' . $icon . ' <code>[' . $slug . ']</code> ' . $agent['description'] . '</button>';
            echo '<div class="panel">';

            if (isset($agent['arguments'])) {
                echo '<h3>Arguments</h3>';
                echo '<ul>';
                foreach ($agent['arguments'] as $name => $arg) {
                    echo '<li><i>' . $name . '</i> <code>' . $arg['type'] . '</code> ' . $arg['description'] . '</li>';
                }
                echo '</ul>';
            }

            if (isset($agent['examples'])) {
                echo '<h3>Examples</h3>';

                foreach ($agent['examples'] as $example) {
                    echo '<pre><code class="language-shortcode">' . $example[0] . '</code></pre>';
                    echo '<p>' . $example[1] . '</p>';
                    echo '<hr>';
                }
            }

            if (isset($agent['references'])) {
                echo '<h3>References</h3>';
                echo '<ul>';
                foreach ($agent['references'] as $title => $url) {
                    echo '<li><a href="' . $url . '" target="_blank">' . $title . '</a></li>';
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
        return apply_filters(Options::prefixUnderscore('core_agents'), []);
    }

    public function getCustomAgents()
    {
        return apply_filters(Options::prefixUnderscore('custom_agents'), []);
    }

    public function hookUserPrompt($prompt)
    {
        $out = do_shortcode($prompt);
        return $out;
    }

    public function router($atts, $content = '', $tag = '')
    {
        $cache = new Cache($atts, $content, $tag);

        $response = $cache->get();

        if ($response) {
            return $response;
        }

        switch ($tag) {
            case 'agent':
                $response = $this->routerAgent($atts, $content, $tag);
                break;
            case 'alpaca':
                $response = $this->routerAlpaca($atts, $content, $tag);
                break;
            default:
                return 'Error: Tag not found.';
        }

        $cache->set($response);

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

        // remove atts that don't belong
        foreach ($atts as $key => $value) {
            if (!isset($agent['arguments'][$key])) {
                unset($atts[$key]);
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
            'cache' => 0,
            'model' => Options::get('default_model'),
            'prompt' => do_shortcode($content),
        ];

        $atts = wp_parse_args($atts, $def);

        $cache = new Cache($atts, $content, $tag);

        $response = $cache->get();

        if ($response) {
            return $response;
        }

        $ollama = new Ollama();

        $response = $ollama->generate($atts);

        $cache->set($response);

        return $response;
    }
}
