<?php

declare(strict_types=1);

namespace AlpacaBot\Api;

use AlpacaBot\Utils\Options;

class Cache
{
    private array $disable = ['0', 'disable',  'false'];

    private bool $active = false;

    private int $timeout = 60 * 60;

    private string $args_key;

    private string $cache;

    private string $cache_key;

    private string $store;

    /**
     * Shortcode caching.
     *
     * @param  mixed $atts
     * @param  mixed $content
     * @param  mixed $tag
     * @return void
     */
    public function __construct(array|string $atts = [], $content = '', $tag = '')
    {
        // String is passed during post_content rendering in admin panel, edit pages and customizer
        if (is_string($atts)) {
            return;
        }

        // build settings
        $this->cache = $atts['cache'] ?? '';

        // if no storage is set, and we're in_the_loop(), set storage to postmeta
        if ($this->cache == '' and in_the_loop()) {
            $this->store = 'postmeta';

            // if cache is set to a number above 0, change storage to transient
        } elseif (is_numeric($this->cache) and (int) $this->cache > 0) {
            $this->timeout = (int) $this->cache;
            $this->store = 'transient';
        } elseif (in_array(strtolower($this->cache), $this->disable)) {
            $this->store = '';
        } else {
            $this->store = 'option';
        }

        if ($this->store) {
            // if store ends in s change, remove s
            if (substr($this->store, -1) === 's' and strlen($this->store) > 1) {
                $this->store = substr($this->store, 0, -1);
            }

            // create key from $this->atts
            $this->args_key = md5(wp_json_encode($atts) . $content . $tag . ($GLOBALS['post']->ID ?? 0));
            $this->cache_key = Options::appendPrefix('cache_' . $this->args_key);

            // set active
            $this->active = true;
        }
    }

    public function get()
    {
        if (!$this->active) {
            return false;
        }

        switch ($this->store) {
            case 'postmeta':
                $cache = get_post_meta(get_the_ID(), $this->cache_key, true);
                break;

            case 'transient':
                $cache = get_transient($this->cache_key);
                break;

            default:
                $cache = get_option($this->cache_key);
                break;
        }

        if (Options::validateValue($cache)) {
            return $cache;
        }
    }

    public function enabled()
    {
        return $this->active;
    }

    public function set($response)
    {
        if (!$this->active) {
            return false;
        }

        if (Options::validateValue($response)) {
            switch ($this->store) {
                case 'postmeta':
                    return update_post_meta(get_the_ID(), $this->cache_key, $response);
                    break;

                case 'transient':
                    return set_transient($this->cache_key, $response, $this->timeout);
                    break;

                default:
                    return update_option($this->cache_key, $response);
                    break;
            }
        }
    }
}
