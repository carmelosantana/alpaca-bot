<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Capability;

/**
 * The Alpaca Bot admin menu: a top-level entry whose page is the chat screen (Plugin passes
 * ChatScreen::render), with "Chat" and "Settings" beneath it.
 *
 * The first submenu repeats the parent's slug so the top-level page reads "Chat" in the list
 * rather than "Alpaca Bot" twice, the way core does for Posts. The chat capability is
 * `edit_posts` through filter `alpaca_bot/admin/menu_capability` (the REST chat routes use the
 * same default under `alpaca_bot/capability/chat`, but the two are filtered separately: a site
 * may open the screen to a role and not the API, or the reverse); Settings is always
 * `manage_options`, because options.php demands it whatever the menu says.
 *
 * The menu filter goes through Capability::filtered() for the reason the REST one does. The
 * value to worry about is `__return_true`, the first thing a site tries when a menu will not
 * appear: `(string) true` is '1', which core reads as the legacy `level_1` check, not as a
 * capability. Stock `edit_posts` and `level_1` happen to cover the same roles, so on a default
 * site that swap is invisible; what it actually does is discard whatever capability the site
 * settled on — its own earlier filter, or a later default — and ask a user-level question
 * instead. The chat screen spends the site's provider tokens, so it is not a surface to widen by
 * accident.
 */
final class Menu
{
    public const SLUG = 'alpaca-bot';

    /**
     * The menu icon: assets/img/menu-icon.svg, the designer's mark with its root fill set to
     * `#a7aaad`, as the data URI core's menu-header.php takes. Core inlines it as the
     * background-image of `div.wp-menu-image.svg` (admin-menu.css sizes it to 20px wide) and
     * wp-admin/js/svg-painter.js recolours it per admin colour scheme. What the painter does, by
     * line of WordPress 7.1's svg-painter.js: on DOM ready it collects every
     * `#adminmenu .wp-menu-image` whose computed background-image contains
     * `data:image/svg+xml;base64` (lines 22, 43); for each it decodes the base64 with atob (103),
     * rewrites every `fill="…"` attribute in the XML to the scheme's hex colour (108) — in this
     * file that is the root's `fill="#a7aaad"`, its only fill attribute, which the `<path>`s
     * inherit; `fill-rule="evenodd"` does not match `fill="` — re-encodes it (116) and sets it as
     * an inline `background-image … !important` (125). The colour is `current` when the `<li>`
     * carries `current` or `wp-has-current-submenu`, otherwise `base`, then `focus` on mouseenter
     * and `base` again 100ms after mouseleave (52-69); the three hex values are
     * `_wpColorScheme.icons`, which wp_color_scheme_settings() prints for the user's scheme
     * (30-35). So the fill written in the file is never what the painted icon shows — the
     * painter overwrites it whatever it says — and it only matters before the painter runs, or
     * with JS off, when the browser draws the file as delivered. The designer's file says
     * `currentColor` there, and an SVG loaded as an image has no `color` to inherit, so that drew
     * black on the dark sidebar until the footer script ran (the designer's data URI drawn onto
     * a canvas reads 0,0,0 at the bubble; this one reads 167,170,173). `#a7aaad` is the Default
     * scheme's resting icon colour (its `icons.base`), so on that scheme the unpainted icon is
     * the grey the painted one settles on, and on any other it is a sidebar grey until the
     * painter runs.
     *
     * Embedded rather than read at registration: admin_menu fires on every request that builds
     * the site admin's menu (wp-admin/admin.php loads menu.php, which ends by loading
     * includes/menu.php, where it fires; the network and user admins fire their own action there
     * instead, admin-ajax.php and admin-post.php load no menu at all, and upgrade.php, install.php
     * and setup-config.php never load admin.php), and a value fixed at release time is not worth
     * reading and base64-encoding the file on each of those requests.
     * tests/Unit/Admin/MenuTest.php decodes this constant against the file's bytes and requires
     * exactly one `fill="…"` in it, `#a7aaad`, and no `currentColor`, so the file stays the source
     * of truth. When the mark changes: set that root fill in the new file and remove any other
     * `fill="…"`, paste `base64 -w0 assets/img/menu-icon.svg` here, and re-check the prose above
     * that describes this file (its only fill attribute, the `<path>`s that inherit it, the canvas
     * reading), which no test holds. MenuTest fails while either of the first two is undone.
     */
    public const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIiB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIGZpbGw9IiNhN2FhYWQiPjxwYXRoIGQ9Ik0yMiA2QzE5LjggNSAxOC4yIDYuNiAxOSA5QzE4IDE5IDIyIDM0IDMxIDQ0QzM1LjUgNDEgMzkgNDAuNSA0MyA0MUMzNyAzMSAyOSAxNiAyMiA2WiIvPjxwYXRoIGQ9Ik03NiA1Qzc4LjIgNCA3OS44IDUuNiA3OSA4QzgwIDE4IDc2IDMzIDY3IDQ0QzYyLjUgNDEgNTkgNDAuNSA1NSA0MUM2MSAzMSA2OSAxNSA3NiA1WiIvPjxwYXRoIGQ9Ik00MCA0MCA0MyAzMSA0NiAzNiA0OSAyOCA1MiAzNSA1NSAyOSA1OCAzNiA2MCA0MFoiLz48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0yNiAzNGg0OGExNiAxNiAwIDAgMSAxNiAxNnYxOGExNiAxNiAwIDAgMS0xNiAxNkg0NmwtMTYgMTRWODRoLTRhMTYgMTYgMCAwIDEtMTYtMTZWNTBhMTYgMTYgMCAwIDEgMTYtMTZaTTM0LjUgNThhNS41IDUuNSAwIDEgMCAxMSAwYTUuNSA1LjUgMCAxIDAtMTEgMFpNNTYuNSA1OGE1LjUgNS41IDAgMSAwIDExIDBhNS41IDUuNSAwIDEgMC0xMSAwWiIvPjwvc3ZnPgo=';

    /** @param callable(): void $chatRenderer */
    public function __construct(private SettingsPage $settings, private $chatRenderer) {}

    public function register(): void
    {
        /**
         * Filters the capability that shows the Alpaca Bot menu and its chat screen. Filtered apart
         * from the REST routes' `alpaca_bot/capability/chat`, so a site can open the screen to a
         * role and not the API, or the reverse. Only a non-empty, non-numeric string is honoured
         * (Capability::filtered()): `true`, `__return_true` or a number would turn the check into a
         * legacy user level, so they are ignored and `edit_posts` stands.
         *
         * @since 0.5.0
         * @param string $capability `edit_posts`
         */
        $cap = Capability::filtered('alpaca_bot/admin/menu_capability', 'edit_posts');
        add_menu_page(__('Alpaca Bot', 'alpaca-bot'), __('Alpaca Bot', 'alpaca-bot'), $cap, self::SLUG, $this->chatRenderer, self::ICON, 3);
        add_submenu_page(self::SLUG, __('Chat', 'alpaca-bot'), __('Chat', 'alpaca-bot'), $cap, self::SLUG, $this->chatRenderer);
        add_submenu_page(self::SLUG, __('Alpaca Bot Settings', 'alpaca-bot'), __('Settings', 'alpaca-bot'), 'manage_options', SettingsPage::SLUG, [$this->settings, 'render']);
    }
}
