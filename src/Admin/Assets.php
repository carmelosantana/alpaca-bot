<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Plugin;

/**
 * The chat screen's scripts and stylesheet, enqueued on `admin_enqueue_scripts` for that screen
 * only: htmx, then the chat bundle (which needs it, plus core's api-fetch and heartbeat), the
 * stylesheet, and the media library for the image picker. `alpacaBot` is the bundle's settings
 * object: the REST root (rest_url(), so it is right under either permalink form), the REST
 * nonce it signs requests with, and the strings it shows.
 *
 * The files are build outputs (`pnpm build`) and gitignored, enqueued by URL as any asset is:
 * a checkout that has not built them gets a 404 for each, and the screen still renders. Under
 * WP_DEBUG the version is the file's mtime, so a rebuild busts the browser cache; otherwise it
 * is the plugin version. The mtime is read only when the file is there to read, as the sprite
 * is, since filemtime() on a missing file warns.
 */
final class Assets
{
    /** The hook suffix of the chat screen: the top-level page whose first submenu repeats its slug. */
    public const HOOK = 'toplevel_page_' . Menu::SLUG;

    /** htmx as package.json pins it. */
    public const HTMX_VERSION = '2.0.10';

    public function enqueue(string $hook): void
    {
        if ($hook !== self::HOOK) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('alpaca-bot-htmx', plugins_url('assets/js/htmx.min.js', ALPACA_BOT_FILE), [], self::HTMX_VERSION, true);
        wp_enqueue_script('alpaca-bot-chat', plugins_url('assets/js/chat.js', ALPACA_BOT_FILE), ['alpaca-bot-htmx', 'wp-api-fetch', 'heartbeat'], self::version('assets/js/chat.js'), true);
        wp_enqueue_style('alpaca-bot', plugins_url('assets/css/alpaca-bot.css', ALPACA_BOT_FILE), [], self::version('assets/css/alpaca-bot.css'));
        wp_localize_script('alpaca-bot-chat', 'alpacaBot', [
            'rest' => rest_url('alpaca-bot/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'copy' => __('Copy', 'alpaca-bot'),
                'copied' => __('Copied', 'alpaca-bot'),
                'copyCode' => __('Copy code', 'alpaca-bot'),
                'failed' => __('The request failed. Try again.', 'alpaca-bot'),
                'sessionExpired' => __('Your session has expired. Reload the page to keep chatting.', 'alpaca-bot'),
                'imageTitle' => __('Attach an image', 'alpaca-bot'),
                'imageButton' => __('Use this image', 'alpaca-bot'),
            ],
            'offline' => __('You are offline. Messages will send once the connection is back.', 'alpaca-bot'),
        ]);
    }

    private static function version(string $relative): string
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return Plugin::VERSION;
        }
        $path = dirname(ALPACA_BOT_FILE) . '/' . $relative;
        $mtime = is_readable($path) ? filemtime($path) : false;
        return $mtime === false ? Plugin::VERSION : (string) $mtime;
    }
}
