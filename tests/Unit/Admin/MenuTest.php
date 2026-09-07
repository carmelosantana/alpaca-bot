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

it('ignores a menu capability filter that returns anything but a capability name', function (): void {
    // The same hazard Controller::permission() guards, on the surface that spends tokens:
    // `(string) true` is '1', which core reads as the legacy level_1 check, not as a capability.
    // `add_filter('alpaca_bot/admin/menu_capability', '__return_true')` is the obvious thing to
    // reach for when the menu will not show up, and would otherwise throw away the capability
    // the site settled on. Anything that is not a capability name is no opinion; the integration
    // suite asserts the same against a real WordPress, where the two checks come apart.
    $store = new Store([]);
    $settings = new SettingsPage($store, new ModelCatalog(new Factory($store)));
    $chat = static function (): void {};
    $caps = [];
    Functions\when('add_menu_page')->alias(function (mixed ...$args) use (&$caps): void {
        $caps[] = $args[2];
    });
    Functions\when('add_submenu_page')->alias(function (mixed ...$args) use (&$caps): void {
        $caps[] = $args[3];
    });
    $bad = [true, false, '1', 0, 7, '', null, ['manage_options']];
    foreach ($bad as $value) {
        Filters\expectApplied('alpaca_bot/admin/menu_capability')->once()->with('edit_posts')->andReturn($value);
        (new Menu($settings, $chat))->register();
    }
    // Per register(): the menu page, the Chat submenu, then Settings, which is manage_options
    // whatever the filter says.
    expect($caps)->toBe(array_merge(...array_fill(0, count($bad), ['edit_posts', 'edit_posts', 'manage_options'])));
});
