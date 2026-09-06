<?php

declare(strict_types=1);

use AlpacaBot\Admin\Menu;
use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('registers the top-level menu, the chat page as its first entry and Settings under manage_options, with the capability filtered', function (): void {
    $store = new Store([]);
    $settings = new SettingsPage($store, new ModelCatalog(new Factory($store)));
    $chat = static function (): void {};
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->once()->with('edit_posts')->andReturn('read');
    Functions\expect('add_menu_page')->once()->with('Alpaca Bot', 'Alpaca Bot', 'read', Menu::SLUG, $chat, 'dashicons-format-chat', 3);
    Functions\expect('add_submenu_page')->once()->with(Menu::SLUG, 'Chat', 'Chat', 'read', Menu::SLUG, $chat);
    Functions\expect('add_submenu_page')->once()->with(Menu::SLUG, 'Alpaca Bot Settings', 'Settings', 'manage_options', SettingsPage::SLUG, [$settings, 'render']);
    (new Menu($settings, $chat))->register();
});
