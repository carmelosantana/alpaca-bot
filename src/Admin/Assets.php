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
 *
 * The nonce the bundle signs with expires after a day (Kanboard #302); heartbeat() answers the
 * bundle's request for a fresh one on core's heartbeat, which runs on admin-ajax and so is
 * hooked at register() time (Plugin), not from enqueue().
 */
final class Assets
{
    /** The hook suffix of the chat screen: the top-level page whose first submenu repeats its slug. */
    public const HOOK = 'toplevel_page_' . Menu::SLUG;

    /** htmx as package.json pins it, exactly (AssetsTest holds the two equal). */
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
                'copyFailed' => __('Copying failed. Select the text and copy it yourself.', 'alpaca-bot'),
                'failed' => __('The request failed. Try again.', 'alpaca-bot'),
                'sessionExpired' => __('Your session has expired. Reload the page to keep chatting.', 'alpaca-bot'),
                'imageTitle' => __('Attach an image', 'alpaca-bot'),
                'imageButton' => __('Use this image', 'alpaca-bot'),
                // The figure is MAX_IMAGE_BYTES in resources/ts/image.ts.
                'imageTooLarge' => __('That image is too large to send. Pick one under 4 MB.', 'alpaca-bot'),
                'thinking' => __('Thinking…', 'alpaca-bot'),
            ],
            'offline' => __('You are offline. Messages will send once the connection is back.', 'alpaca-bot'),
        ]);
    }

    /**
     * `heartbeat_received`: chat.ts sends `alpaca_bot_nonce: 1` on heartbeat-send, and the
     * answer carries a fresh `wp_rest` nonce under the same key. A heartbeat that did not ask
     * (another screen's) is left as it was.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $data what the browser sent
     * @return array<string, mixed>
     */
    public function heartbeat(array $response, array $data): array
    {
        if (!empty($data['alpaca_bot_nonce'])) {
            $response['alpaca_bot_nonce'] = wp_create_nonce('wp_rest');
        }
        return $response;
    }

    /**
     * The version string for a build output; `$debug` is WP_DEBUG unless a caller (a test) says otherwise.
     *
     * @internal Public only so the tests can call it; not part of the plugin's API.
     */
    public static function version(string $relative, ?bool $debug = null): string
    {
        if (!($debug ?? (defined('WP_DEBUG') && WP_DEBUG))) {
            return Plugin::VERSION;
        }
        $path = dirname(ALPACA_BOT_FILE) . '/' . $relative;
        $mtime = is_readable($path) ? filemtime($path) : false;
        return $mtime === false ? Plugin::VERSION : (string) $mtime;
    }
}
