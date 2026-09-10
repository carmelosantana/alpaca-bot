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
     * The menu icon: assets/img/menu-icon.svg, the designer's mark, as the data URI core's
     * menu-header.php takes. Core inlines it as the background-image of `div.wp-menu-image.svg`
     * (admin-menu.css sizes it to 20px wide) and wp-admin/js/svg-painter.js recolours it per
     * admin colour scheme. What the painter does, by line of WordPress 7.1's svg-painter.js: on
     * DOM ready it collects every `#adminmenu .wp-menu-image` whose computed background-image
     * contains `data:image/svg+xml;base64` (lines 22, 43); for each it decodes the base64 with
     * atob (103), rewrites every `fill="…"` attribute in the XML to the scheme's hex colour
     * (108) — in this file that is the root's `fill="currentColor"`, its only fill attribute,
     * which the `<path>`s inherit; `fill-rule="evenodd"` does not match `fill="` — re-encodes
     * it (116) and sets it as an inline `background-image … !important` (125). The colour is
     * `current` when the `<li>` carries `current` or `wp-has-current-submenu`, otherwise `base`,
     * then `focus` on mouseenter and `base` again 100ms after mouseleave (52-69); the three hex
     * values are `_wpColorScheme.icons`, which wp_color_scheme_settings() prints for the user's
     * scheme (30-35). So `currentColor` is not what recolours the icon — it is the value the
     * painter overwrites — and until the painter runs, or with JS off, the browser draws the
     * file as delivered, where an SVG loaded as an image has no `color` to inherit and
     * `currentColor` is black (this data URI drawn onto a canvas reads 0,0,0 at the bubble).
     *
     * Embedded rather than read at registration: admin_menu fires on every screen of the site's
     * admin (wp-admin/admin.php loads menu.php, which ends by loading includes/menu.php, where it
     * fires; the network and user admins fire their own action there instead, and admin-ajax.php
     * and admin-post.php load no menu at all), and a value fixed at release time is not worth
     * reading and base64-encoding the file on each of those screens. tests/Unit/Admin/MenuTest.php
     * decodes this constant against the file's bytes, so the file stays the source of truth and
     * the whole edit when the mark changes is `base64 -w0 assets/img/menu-icon.svg` pasted here.
     */
    public const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIiB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIGZpbGw9ImN1cnJlbnRDb2xvciI+PHBhdGggZD0iTTIyIDZDMTkuOCA1IDE4LjIgNi42IDE5IDlDMTggMTkgMjIgMzQgMzEgNDRDMzUuNSA0MSAzOSA0MC41IDQzIDQxQzM3IDMxIDI5IDE2IDIyIDZaIi8+PHBhdGggZD0iTTc2IDVDNzguMiA0IDc5LjggNS42IDc5IDhDODAgMTggNzYgMzMgNjcgNDRDNjIuNSA0MSA1OSA0MC41IDU1IDQxQzYxIDMxIDY5IDE1IDc2IDVaIi8+PHBhdGggZD0iTTQwIDQwIDQzIDMxIDQ2IDM2IDQ5IDI4IDUyIDM1IDU1IDI5IDU4IDM2IDYwIDQwWiIvPjxwYXRoIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTI2IDM0aDQ4YTE2IDE2IDAgMCAxIDE2IDE2djE4YTE2IDE2IDAgMCAxLTE2IDE2SDQ2bC0xNiAxNFY4NGgtNGExNiAxNiAwIDAgMS0xNi0xNlY1MGExNiAxNiAwIDAgMSAxNi0xNlpNMzQuNSA1OGE1LjUgNS41IDAgMSAwIDExIDBhNS41IDUuNSAwIDEgMC0xMSAwWk01Ni41IDU4YTUuNSA1LjUgMCAxIDAgMTEgMGE1LjUgNS41IDAgMSAwLTExIDBaIi8+PC9zdmc+Cg==';

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
