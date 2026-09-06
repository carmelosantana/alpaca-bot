<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

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
 */
final class Menu
{
    public const SLUG = 'alpaca-bot';

    /** @param callable(): void $chatRenderer */
    public function __construct(private SettingsPage $settings, private $chatRenderer) {}

    public function register(): void
    {
        $cap = (string) apply_filters('alpaca_bot/admin/menu_capability', 'edit_posts');
        add_menu_page(__('Alpaca Bot', 'alpaca-bot'), __('Alpaca Bot', 'alpaca-bot'), $cap, self::SLUG, $this->chatRenderer, 'dashicons-format-chat', 3);
        add_submenu_page(self::SLUG, __('Chat', 'alpaca-bot'), __('Chat', 'alpaca-bot'), $cap, self::SLUG, $this->chatRenderer);
        add_submenu_page(self::SLUG, __('Alpaca Bot Settings', 'alpaca-bot'), __('Settings', 'alpaca-bot'), 'manage_options', SettingsPage::SLUG, [$this->settings, 'render']);
    }
}
