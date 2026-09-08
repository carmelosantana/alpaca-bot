<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Plugin;

/**
 * The chat screen's scripts and stylesheet, enqueued on `admin_enqueue_scripts` for that screen
 * only: htmx, then the chat bundle (which needs it, plus core's api-fetch and heartbeat), the
 * stylesheet, and the media library for the image picker. `alpacaBot` is the bundle's settings
 * object: the REST root (rest_url(), so it is right under either permalink form), the REST
 * nonce it signs requests with, the largest image the site takes (maxImageBytes()), and the
 * strings it shows.
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
            'maxImageBytes' => self::maxImageBytes(),
            'i18n' => [
                'copy' => __('Copy', 'alpaca-bot'),
                'copied' => __('Copied', 'alpaca-bot'),
                'copyCode' => __('Copy code', 'alpaca-bot'),
                'copyFailed' => __('Copying failed. Select the text and copy it yourself.', 'alpaca-bot'),
                'failed' => __('The request failed. Try again.', 'alpaca-bot'),
                'sessionExpired' => __('Your session has expired. Reload the page to keep chatting.', 'alpaca-bot'),
                'imageTitle' => __('Attach an image', 'alpaca-bot'),
                'imageButton' => __('Use this image', 'alpaca-bot'),
                // image.ts fills {size} with the image's size and {max} with maxImageBytes, both formatted.
                /* translators: {size} and {max} are filled in by the browser with figures such as "4 MB". */
                'imageTooLarge' => __('That image is {size}; this site takes an image up to {max}. Pick a smaller one.', 'alpaca-bot'),
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
     * The largest image the chat bundle sends, in bytes: the smaller of the site's upload limit
     * (wp_max_upload_size(), which a site filters and multisite caps) and post_max_size, the
     * limit the image actually meets, since it travels base64-encoded inside a JSON body and
     * not as an upload. A body past post_max_size is dropped before WordPress sees it and the
     * only answer is a bare failure, which is what image.ts's guard exists to prevent: the guard
     * is only as good as its figure, and this is the site's own rather than a guess at it. The
     * figure is the raw image size, not the encoded body's, so it reads as the media uploader's
     * "maximum upload file size" does; the base64 overhead fits under post_max_size wherever the
     * upload limit is the smaller of the two, which is where a stock PHP puts it.
     *
     * 0 from either source is "no limit" (post_max_size=0 is PHP's own spelling of it) and does
     * not cap; 0 from both means no figure, and image.ts keeps its constant. The two figures are
     * parameters so a test can pass them; a caller passes neither.
     *
     * @internal Public only so the tests can call it; not part of the plugin's API.
     */
    public static function maxImageBytes(?int $uploadLimit = null, ?string $postMaxSize = null): int
    {
        $limits = array_filter([
            $uploadLimit ?? (int) wp_max_upload_size(),
            wp_convert_hr_to_bytes($postMaxSize ?? (string) ini_get('post_max_size')),
        ], static fn(int $bytes): bool => $bytes > 0);
        return $limits === [] ? 0 : min($limits);
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
