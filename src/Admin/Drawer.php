<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Access;
use AlpacaBot\Chat\UserPrefs;

/**
 * The chat on the other wp-admin screens: a launcher and an empty drawer printed on
 * `admin_footer`, and a loader script with its stylesheet enqueued on `admin_enqueue_scripts`.
 * Opening the drawer is what loads the chat: resources/ts/mount.ts adds the chat's stylesheet and
 * htmx, fetches `GET /view/panel` into the drawer through htmx, and adds the chat bundle once that
 * fragment is in the page. Until the first open, the screen carries the launcher, the loader and
 * their stylesheet, and none of the chat.
 *
 * Three kinds of screen are left out. The plugin's own chat screen, where this would be the chat
 * twice over; a block editor screen, which owns the whole viewport and where Kanboard #4369
 * settled on an editor sidebar rather than a panel fixed over the block settings
 * (`WP_Screen::is_block_editor()` is how core answers that); and a request that defines
 * `IFRAME_REQUEST`, core's mark for a page it loads into a modal: the plugin details modal and
 * update.php's update and activate actions, which print through iframe_header() and
 * iframe_footer() and so fire both hooks this listens on, and media-upload.php, whose wp_iframe()
 * fires the first. A drawer there would be a second, hidden chat inside someone else's dialog.
 *
 * Who sees it is Menu::capability(), the question the menu and the chat screen ask: the Chat row
 * of Settings › Access through `alpaca_bot/admin/menu_capability`. Not Access::allows($user,
 * 'chat'), which answers the stored row with no filter at all (Access::effective() returns the
 * Chat row before any hook), so a site that opened or closed the chat screen through the menu
 * filter would get a drawer that disagreed with it.
 *
 * The budget (spec §4): until it is opened, this adds no database query to a screen. What it reads
 * is in memory by the time these hooks fire. The capability check answers from the WP_User core
 * built when it loaded the current user, and both preferences are user meta, whose cache that
 * load primed whole: WP_User::get_caps_data() reads the capabilities with get_user_meta(), and a
 * miss there reads every row of the user's meta in one query (get_metadata_raw(),
 * update_meta_cache()). The Chat row is the settings option and the URLs are built from core's,
 * all autoloaded. tests/Integration/DrawerQueriesTest.php counts it.
 *
 * Open or closed, and the conversation shown, are the user's (Chat\UserPrefs), stored through
 * `POST /view/drawer`, so the drawer comes back the way it was left on the next screen. A drawer
 * left open fetches `GET /view/panel` again on each screen it is reopened on, and that fragment
 * renders the model select, which asks the provider when the model cache is cold
 * (ViewController::routes() says so).
 *
 * @since 0.6.0
 */
final class Drawer
{
    /** The loader's script and stylesheet handle. */
    public const HANDLE = 'alpaca-bot-drawer';

    public function __construct(private Access $access, private UserPrefs $prefs, private Assets $assets) {}

    /** Whether this screen, and this user, get the drawer: the class docblock says why each. */
    public function wanted(): bool
    {
        if (defined('IFRAME_REQUEST')) {
            return false;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen instanceof \WP_Screen || $screen->id === Assets::HOOK || $screen->is_block_editor()) {
            return false;
        }
        return current_user_can(Menu::capability($this->access));
    }

    /**
     * `admin_enqueue_scripts`: the launcher's few rules and the loader, with the two settings
     * objects the chat reads once the loader adds it (Assets::settings() and Assets::mount()).
     * `heartbeat` is a dependency because the chat bundle refreshes its nonce on core's heartbeat
     * (resources/ts/nonce.ts), and a script the loader adds to the page by hand cannot declare
     * one. wp_enqueue_media() is not called: where the screen has not loaded the media library
     * itself, resources/ts/drawer.ts marks the drawer and this stylesheet hides the image button.
     */
    public function enqueue(): void
    {
        if (!$this->wanted()) {
            return;
        }
        wp_enqueue_style(self::HANDLE, plugins_url('assets/css/alpaca-bot-drawer.css', ALPACA_BOT_FILE), ['dashicons'], Assets::version('assets/css/alpaca-bot-drawer.css'));
        wp_enqueue_script(self::HANDLE, plugins_url('assets/js/drawer.js', ALPACA_BOT_FILE), ['heartbeat'], Assets::version('assets/js/drawer.js'), true);
        wp_localize_script(self::HANDLE, 'alpacaBot', $this->assets->settings());
        wp_localize_script(self::HANDLE, 'alpacaBotMount', $this->assets->mount());
    }

    /** `admin_footer`: the launcher, and the empty element the loader fills on the first open. */
    public function footer(): void
    {
        if (!$this->wanted()) {
            return;
        }
        $userId = (int) get_current_user_id();
        printf(
            '<button type="button" id="ab-drawer-launcher" class="ab-drawer-launcher" aria-controls="ab-drawer" aria-expanded="false"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><span class="screen-reader-text">%1$s</span></button>'
            . '<aside id="ab-drawer" class="ab-drawer" aria-label="%2$s" data-open="%3$s" data-conversation="%4$d" hidden></aside>',
            esc_html__('Open the Alpaca Bot chat', 'alpaca-bot'),
            esc_attr__('Alpaca Bot chat', 'alpaca-bot'),
            $this->prefs->drawerOpen($userId) ? '1' : '0',
            (int) $this->prefs->drawerConversation($userId),
        );
    }
}
