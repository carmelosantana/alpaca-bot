<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Plugin;

/**
 * The chat screen's scripts and stylesheet, enqueued on `admin_enqueue_scripts` for that screen
 * only (and, through enqueueFront(), for a front-end page that rendered the shell): htmx, then the chat bundle (which needs it, plus core's heartbeat for the nonce
 * refresh; it fetches with bare fetch(), so api-fetch is not among its dependencies), the
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
        $this->enqueueChat();
    }

    /**
     * The same bundle for a `[alpacabot]` shell on a front-end page, called from the shortcode
     * handler (Shortcodes\Chat) as it renders, so only a page that rendered a shell loads it and
     * every other front-end page loads nothing. That is later than wp_enqueue_scripts: the
     * scripts are footer scripts (`in_footer`) and print with wp_footer, and a stylesheet
     * enqueued after wp_head prints there too, through print_late_styles(), so the shell is
     * unstyled for the moment between its markup and the footer. The alternative, scanning the
     * queried post for the shortcode on wp_enqueue_scripts, loads the bundle for a page whose
     * shortcode then renders nothing (a visitor, a `prompt` form) and for one whose content the
     * theme never prints; the flash is the cheaper cost.
     *
     * What of the admin enqueue is kept: the same handles, so a page that somehow carries both
     * loads each file once; the localised settings whole, since the bundle signs every request
     * with the nonce and reads the REST root and the image cap on the front end as in wp-admin,
     * and rest_url() is right under either permalink form; core's heartbeat, which the bundle
     * depends on for the nonce refresh and which runs on the front end for a logged-in user
     * (the `heartbeat_received` answer is hooked at register(), not from any screen). What is
     * not: wp_enqueue_media() unconditionally. It is the whole media library (Backbone, the
     * views, plupload, the modal templates in wp_footer), and the picker it opens queries the
     * library as the viewer, which core refuses to a user without `upload_files`: an editor's
     * Contributor would load all of it for an empty modal. So it is loaded for a viewer who can
     * upload, and the composer's image button is inert for one who cannot (chat.ts's
     * pickImage() returns when `wp.media` is absent).
     */
    public function enqueueFront(): void
    {
        if (current_user_can('upload_files')) {
            wp_enqueue_media();
        }
        $this->enqueueChat();
    }

    /**
     * The few rules for what a shortcode prints on a page that is not the chat screen: the
     * `.alpaca-bot-answer` block and the `.alpaca-bot-notice` line. Its own small file (900
     * bytes) rather than the shell's stylesheet, since a visitor's login notice must not cost
     * that file's fourteen kilobytes, and theme-neutral (the theme's type and colour, a quiet box
     * for the notice) so a theme's own rules over the two classes win without a fight. The
     * shortcode runs after `wp_head`, so it prints from the footer through print_late_styles():
     * the markup is unstyled for the moment between its position and the footer, which for a
     * paragraph is a reflow, not a flash of the shell.
     */
    public function enqueueShortcode(): void
    {
        wp_enqueue_style('alpaca-bot-shortcode', plugins_url('assets/css/alpaca-bot-shortcode.css', ALPACA_BOT_FILE), [], self::version('assets/css/alpaca-bot-shortcode.css'));
    }

    /** htmx, the bundle, the stylesheet and the bundle's settings: the class docblock. */
    private function enqueueChat(): void
    {
        wp_enqueue_script('alpaca-bot-htmx', plugins_url('assets/js/htmx.min.js', ALPACA_BOT_FILE), [], self::HTMX_VERSION, true);
        wp_enqueue_script('alpaca-bot-chat', plugins_url('assets/js/chat.js', ALPACA_BOT_FILE), ['alpaca-bot-htmx', 'heartbeat'], self::version('assets/js/chat.js'), true);
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
                // The same two figures for the turn's images together: image.ts fills {size} with their total. The
                // allowance is a per-message total on the server too (Pipeline::images()), in the same words.
                /* translators: {size} and {max} are filled in by the browser with figures such as "7 MB". */
                'imagesTooLarge' => __('Those images total {size}; this site takes up to {max} per message. Attach fewer or smaller images.', 'alpaca-bot'),
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
     * The budget for the part of a POST /chat body that is not the image: the ids, the model,
     * the data URL's own prefix, the JSON around them all, and the message text. The fixed
     * fields come to well under a kibibyte, so for them 64 KiB is generous. The message text
     * has no cap (the composer sets no maxlength and the /chat schema no maxLength, on purpose:
     * pasting a long document into a prompt is a real thing to do), so for it the allowance is
     * a ceiling, and one it meets only when a maximum-size image is attached: the cap consumes
     * the whole remainder, every raw image byte spends 4/3 of one, and there is no slack
     * elsewhere to absorb the overrun. A message past ~64 KB (some ten thousand words of ASCII)
     * beside an image at the cap lands in exactly the bare failure the guard exists to prevent.
     * That corner is the trade taken: the budget is 0.8% of a stock 8M, and a bound on the
     * field would cost a real capability to make it go away.
     */
    public const IMAGE_BODY_ALLOWANCE = 64 * 1024;

    /**
     * The largest image the chat bundle sends, in bytes. The image is not an upload: it travels
     * base64-encoded inside the JSON body of POST /chat, so the only PHP limit it meets is
     * post_max_size. upload_max_filesize and wp_max_upload_size() govern multipart uploads and
     * have no say here. A body past post_max_size is dropped before WordPress sees it and the
     * only answer is a bare failure, which is what image.ts's guard exists to prevent: the guard
     * is only as good as its figure, and this is the site's own rather than a guess at it.
     *
     * The figure is the raw image size, which is what image.ts compares and the message prints,
     * so it is post_max_size less the allowance for the rest of the body, scaled by 3/4 for the
     * encoding: base64 spends four characters on every three bytes, and those characters need
     * no JSON escaping, so the body is the allowance plus 4/3 of the image. AssetsTest holds
     * that an image at the cap fits under post_max_size and one quantum more does not.
     *
     * 0 is "no limit": post_max_size=0 is PHP's own spelling of it, and image.ts reads 0 as "no
     * figure" and keeps its constant (imageLimit() says why the two are let coincide). A
     * post_max_size the allowance alone exhausts is a site nothing posts to, and answers 0 too
     * rather than a negative. The ini value is a parameter so a test can pass one; a caller
     * passes nothing. The division comes before the multiplication so the arithmetic stays in
     * int on a 32-bit build: wp_convert_hr_to_bytes() clamps at PHP_INT_MAX, a product past it
     * is a float, and a float into intdiv() is a TypeError under strict_types. The order costs
     * at most 2 bytes of cap.
     *
     * A web-server body limit (nginx's client_max_body_size) is invisible to PHP and is not
     * counted; that failure mode stays as it was.
     *
     * @internal Public only so the tests can call it; not part of the plugin's API.
     */
    public static function maxImageBytes(?string $postMaxSize = null): int
    {
        $postMax = wp_convert_hr_to_bytes($postMaxSize ?? (string) ini_get('post_max_size'));
        if ($postMax <= 0) {
            return 0;
        }
        return max(0, intdiv($postMax - self::IMAGE_BODY_ALLOWANCE, 4) * 3);
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
