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
    // The icon is assets/img/menu-icon.svg, handed to core as the base64 data URI
    // menu-header.php inlines; computed from the file here rather than from Menu::ICON so the
    // expectation does not pass by definition.
    $icon = 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents(dirname(__DIR__, 3) . '/assets/img/menu-icon.svg'));
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->once()->with('edit_posts')->andReturn('read');
    Functions\expect('add_menu_page')->once()->with('Alpaca Bot', 'Alpaca Bot', 'read', Menu::SLUG, $chat, $icon, 3);
    Functions\expect('add_submenu_page')->once()->with(Menu::SLUG, 'Chat', 'Chat', 'read', Menu::SLUG, $chat);
    Functions\expect('add_submenu_page')->once()->with(Menu::SLUG, 'Alpaca Bot Settings', 'Settings', 'manage_options', SettingsPage::SLUG, [$settings, 'render']);
    (new Menu($settings, $chat))->register();
});

it('embeds assets/img/menu-icon.svg byte for byte, so the file stays the single source of truth', function (): void {
    // The constant is a copy, and this is what keeps it one: replace the file and this fails
    // until the constant is re-encoded. The file is the designer's mark with its root fill set
    // to `#a7aaad` (the next test says why), 590 bytes, trailing newline included; the newline
    // is inside the base64, after `</svg>`, which XML allows and svg-painter's three regexes do
    // not touch.
    $svg = file_get_contents(dirname(__DIR__, 3) . '/assets/img/menu-icon.svg');
    expect($svg)->toBeString()->toStartWith('<svg ');
    expect(Menu::ICON)->toStartWith('data:image/svg+xml;base64,');
    expect(base64_decode(substr(Menu::ICON, strlen('data:image/svg+xml;base64,')), true))->toBe($svg);
});

it('ships the icon with the root fill set to the Default scheme\'s icon grey, not currentColor', function (): void {
    // Core inlines the data URI as a CSS background-image and svg-painter.js recolours it only
    // once it runs in the footer; before that, and for good with JS off, the browser draws the
    // file as delivered, and an SVG loaded as an image has no `color` for `currentColor` to
    // inherit, so it paints black on the dark sidebar. `#a7aaad` is the Default scheme's resting
    // icon colour (`_wpColorScheme.icons.base`), so on that scheme the unpainted icon matches
    // the painted one, and on any other it is a sidebar grey. The painter rewrites every
    // `fill="…"` whatever its value, so this changes nothing after it has run. Decoded from the
    // constant, not read from the file: the constant is what core gets.
    $svg = base64_decode(substr(Menu::ICON, strlen('data:image/svg+xml;base64,')), true);
    expect($svg)->toBeString();
    preg_match_all('/fill="([^"]*)"/', (string) $svg, $fills);
    expect($fills[1])->toBe(['#a7aaad']);
    expect((string) $svg)->not->toContain('currentColor');
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
