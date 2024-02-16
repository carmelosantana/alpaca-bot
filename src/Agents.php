<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Api\Ollama;

class Agents
{
    private array $agents = [];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'adminPageAdd']);
        add_filter('alpaca_user_prompt', [$this, 'hookUserPrompt']);
        add_shortcode('agent', [$this, 'routerAgent']);
        add_shortcode('alpaca', [$this, 'routerAlpaca']);

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
            AB_SLUG . '-agents',
            [$this, 'adminPageRender'],
            1
        );
    }

    // adminPageRender using accordion from pulling $this->getAgents()
    public function adminPageRender()
    {
        echo '<div class="wrap">';
        echo '<h1>Agents</h1>';
        echo '<p>Agents are shortcodes that help Alpaca Bot perform tasks.</p>';
        echo '<div id="accordion">';

        $agents = $this->getAgents();

        foreach ($agents as $slug => $agent) {

            // icon <span class="material-symbols-outlined">summarize</span>
            $icon = $agent['icon'] ?? 'person_apron';
            $icon = '<span class="material-symbols-outlined">' . $icon . '</span>';
            echo '<button class="accordion">' . $icon . ' ' . $agent['title'] . '</button>';
            echo '<div class="panel">';
            echo '<p>' . $agent['description'] . '</p>';

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
                    echo '<pre>' . $example[0] . '</pre>';
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
        return apply_filters('alpaca_bot_core_agents', []);
    }

    public function getCustomAgents()
    {
        return apply_filters('alpaca_bot_custom_agents', []);
    }

    public function hookUserPrompt($prompt)
    {
        $out = do_shortcode($prompt);
        return $out;
    }

    public function routerAgent($atts, $content = '', $tag = '')
    {
        $agents = $this->getAgents();

        if (isset($agents[$atts[0]])) {
            $agent = $agents[$atts[0]];
        } elseif (isset($agents[$atts['agent'] ?? null])) {
            $agent = $agents[$atts['agent']];
        } else {
            return 'Error: Agent not found.';
        }

        foreach ($agent['arguments'] as $name => $arg) {
            if (isset($arg['default'])) {
                $atts[$name] = $atts[$name] ?? $arg['default'];
            }
        }

        // remove atts that dont belong
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
            'cache' => 'postmeta',
            'model' => Options::get('default_model'),
            'prompt' => do_shortcode($content),
        ];

        $atts = wp_parse_args($atts, $def);

        // create key from $atts
        $args_key = md5(json_encode($atts));
        $cache_key = 'alpaca_cache_' . $args_key;

        // if we're in a post, and no cache is set, default to postmeta
        if (empty($atts['cache']) && is_singular()) {
            $atts['cache'] = 'postmeta';
        }

        // if cache is numeric, set transient
        if (is_numeric($atts['cache']) and (int) $atts['cache'] > 0) {
            $timeout = $atts['cache'];
            $atts['cache'] = 'transient';
        }

        switch ($atts['cache']) {
            case 'postmeta':
                $cache = get_post_meta(get_the_ID(), $cache_key, true);
                break;

            case 'transient':
                $cache = get_transient($cache_key);
                break;

            default:
                $cache = get_option($cache_key);
                break;
        }

        if ($cache) {
            return $cache;
        }

        $ollama = new Ollama();

        $response = $ollama->generate($atts);

        if ($response) {
            switch ($atts['cache']) {
                case 'postmeta':
                    update_post_meta(get_the_ID(), $cache_key, $response);
                    break;

                case 'transient':
                    set_transient($cache_key, $response, $timeout);
                    break;

                default:
                    update_option($cache_key, $response);
                    break;
            }
        }

        return $response;
    }
}
