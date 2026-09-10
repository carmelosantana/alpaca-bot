<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Capability;

/**
 * The Alpaca Bot admin menu: a top-level entry whose page is the chat screen (P3 supplies the
 * renderer; until then Plugin passes a placeholder), with "Chat" and "Settings" beneath it.
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
        add_menu_page(__('Alpaca Bot', 'alpaca-bot'), __('Alpaca Bot', 'alpaca-bot'), $cap, self::SLUG, $this->chatRenderer, 'dashicons-format-chat', 3);
        add_submenu_page(self::SLUG, __('Chat', 'alpaca-bot'), __('Chat', 'alpaca-bot'), $cap, self::SLUG, $this->chatRenderer);
        add_submenu_page(self::SLUG, __('Alpaca Bot Settings', 'alpaca-bot'), __('Settings', 'alpaca-bot'), 'manage_options', SettingsPage::SLUG, [$this->settings, 'render']);
    }
}
