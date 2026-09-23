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
 * (`WP_Screen::is_block_editor()` is how core answers that); and any request on which core has
 * defined `IFRAME_REQUEST`. That guard keys on the constant, not on how the page is shown. Core
 * defines it on pages it prints through its iframe templates (the plugin details modal,
 * update.php's update and activate actions, media-upload.php, among others) and on some it does
 * not (customize.php). A page printed through iframe_header() and iframe_footer() fires both hooks
 * this listens on, and iframe_footer() puts admin_footer's output in a hidden div, so a drawer
 * there would be a second chat nobody can see; media-upload.php's wp_iframe() fires the first.
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
 * all autoloaded. What footer() adds for the context chips is read off globals core has set by
 * then: the screen, its page title, and on the classic editor the post, which get_post() reads
 * from memory (footer() says how, for post.php and for post-new.php).
 * tests/Integration/DrawerQueriesTest.php counts it on the dashboard, on the classic editor, and
 * on the classic editor's Add New screen.
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

    /** The block editor sidebar's script handle (resources/ts/editor.ts, enqueueEditor()). */
    public const EDITOR_HANDLE = 'alpaca-bot-editor';

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

    /**
     * `enqueue_block_editor_assets`: the chat as the block editor's own sidebar
     * (resources/ts/editor.ts), which mounts the fragment the drawer mounts, for the user the
     * drawer is for (Menu::capability(), as wanted() asks). Core fires this hook wherever it loads
     * a block editor, and the site editor, the widgets editor and the Customizer's widgets have no
     * post to edit, so only a screen whose base is `post` and that core says is a block editor
     * gets the sidebar; the classic editor gets the drawer instead (wanted()).
     *
     * The four `wp-*` dependencies are the globals editor.ts reads off `window.wp`, so it runs
     * after the bundles that set them. `wp-editor` depends on the other three already, and they
     * are named anyway because editor.ts reads all four and a dependency of a dependency is not a
     * promise. `heartbeat` is here for the reason it is on the loader (enqueue()). Both settings
     * objects are the loader's, because the sidebar mounts the chat the way the drawer does
     * (resources/ts/mount.ts).
     */
    public function enqueueEditor(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen instanceof \WP_Screen || $screen->base !== 'post' || !$screen->is_block_editor()) {
            return;
        }
        if (!current_user_can(Menu::capability($this->access))) {
            return;
        }
        wp_enqueue_script(self::EDITOR_HANDLE, plugins_url('assets/js/editor.js', ALPACA_BOT_FILE), ['wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'heartbeat'], Assets::version('assets/js/editor.js'), true);
        wp_localize_script(self::EDITOR_HANDLE, 'alpacaBot', $this->assets->settings());
        wp_localize_script(self::EDITOR_HANDLE, 'alpacaBotMount', $this->assets->mount());
    }

    /**
     * `admin_footer`: the launcher, and the empty element the loader fills on the first open. The
     * element also names what the chat's context chips show (View\Chat\Composer), which only this
     * request knows: the screen's id and page title, and on the classic editor the post.
     *
     * The title is the global `$title`, read as it stands when `admin_footer` fires. Whoever set
     * it last is not this method's concern: get_admin_page_title() answers with that global
     * whenever it is not empty, and otherwise walks the admin menus and stores what it finds in
     * it; admin-header.php calls it and strips the global's tags; and a screen may set the global
     * again after its header (update.php does, while it installs a plugin or a theme). So a
     * non-empty global is the title get_admin_page_title() would give at this point, and an empty
     * one is left empty, not walked for here, which is no title and so no screen chip. The title is
     * HTML (edit-comments.php, for one post's comments, puts the post's title between `&#8220;`
     * and `&#8221;`), so its tags are stripped and its entities decoded to make it text; the panel
     * cleans it for the chip by Context\CurrentScreenSource::screenFrom(), and the turn cleans it
     * again.
     *
     * The post is get_post() of the global the screen's own file set. On post.php that is the post
     * it loaded, which get_post() reads back from the object cache that load filled; on
     * post-new.php it is the auto-draft get_default_post_to_edit() made, which get_post() hands back
     * as it is. Whether it is a post the chip may name is the panel's question (View\Chat\Shell),
     * asked when the drawer is opened: not one the user may not edit, and not an auto-draft.
     */
    public function footer(): void
    {
        if (!$this->wanted()) {
            return;
        }
        $userId = (int) get_current_user_id();
        $screen = get_current_screen();
        $title = is_string($GLOBALS['title'] ?? null) ? html_entity_decode(wp_strip_all_tags($GLOBALS['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $post = $screen instanceof \WP_Screen && $screen->base === 'post' ? get_post() : null;
        printf(
            '<button type="button" id="ab-drawer-launcher" class="ab-drawer-launcher" aria-controls="ab-drawer" aria-expanded="false"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><span class="screen-reader-text">%1$s</span></button>'
            . '<aside id="ab-drawer" class="ab-drawer" aria-label="%2$s" data-open="%3$s" data-conversation="%4$d" data-screen-id="%5$s" data-screen-title="%6$s" data-post="%7$d" hidden></aside>',
            esc_html__('Open the Alpaca Bot chat', 'alpaca-bot'),
            esc_attr__('Alpaca Bot chat', 'alpaca-bot'),
            $this->prefs->drawerOpen($userId) ? '1' : '0',
            (int) $this->prefs->drawerConversation($userId),
            esc_attr($screen instanceof \WP_Screen ? $screen->id : ''),
            esc_attr($title),
            (int) ($post instanceof \WP_Post ? $post->ID : 0),
        );
    }
}
