<?php

declare(strict_types=1);

use AlpacaBot\Admin\Assets;
use AlpacaBot\Admin\Drawer;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Plugin;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// adminDrawer() lives in tests/Pest.php. WP_Screen is a class Mockery declares (as HelpTabsTest
// does): `id` is the property core sets, is_block_editor() the method core answers the editor
// question with, and get_current_screen() is how a hook gets at it.
beforeEach(function (): void {
    $this->screen = static function (string $id, bool $blockEditor = false): Mockery\MockInterface {
        $screen = Mockery::mock('WP_Screen');
        $screen->id = $id;
        $screen->shouldReceive('is_block_editor')->andReturn($blockEditor);
        return $screen;
    };
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('get_user_meta')->alias(static fn(int $id, string $key): string => match ($key) {
        UserPrefs::META_DRAWER_OPEN => '1',
        UserPrefs::META_DRAWER_CONVERSATION => '42',
        default => '',
    });
});

it('prints a launcher and an empty drawer on an ordinary admin screen, opening where the user left off', function (): void {
    Functions\when('get_current_screen')->justReturn(($this->screen)('dashboard'));
    // The gate is the menu's question, asked of the Chat row's default.
    Functions\expect('current_user_can')->once()->with('edit_posts')->andReturn(true);

    ob_start();
    adminDrawer()->footer();
    $html = (string) ob_get_clean();

    expect($html)->toContain('<button type="button" id="ab-drawer-launcher"')
        ->toContain('aria-controls="ab-drawer"')
        ->toContain('aria-expanded="false"')
        ->toContain('<aside id="ab-drawer"')
        ->toContain('data-open="1"')
        ->toContain('data-conversation="42"')
        ->toContain('hidden></aside>')
        // Nothing of the chat is in the page until it is opened: no shell, no sprite, no fragment.
        ->not->toContain('ab-form')
        ->not->toContain('ab-wrap')
        ->not->toContain('<svg');

    // Left closed, on no conversation: the same markup, saying so.
    Functions\when('get_user_meta')->justReturn('');
    Functions\expect('current_user_can')->once()->with('edit_posts')->andReturn(true);
    ob_start();
    adminDrawer()->footer();
    expect((string) ob_get_clean())->toContain('data-open="0"')->toContain('data-conversation="0"');
});

it('asks the menu\'s filter who may see it, not the Chat row alone', function (): void {
    // A site that opened the menu to Subscribers through the filter opens the drawer with it; the
    // row's own `access.chat` stays what the filter is handed.
    Functions\when('get_current_screen')->justReturn(($this->screen)('dashboard'));
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->once()->with('publish_posts')->andReturn('read');
    Functions\expect('current_user_can')->once()->with('read')->andReturn(true);
    ob_start();
    adminDrawer(['access.chat' => 'publish_posts'])->footer();
    expect((string) ob_get_clean())->toContain('id="ab-drawer-launcher"');
});

it('prints and enqueues nothing on the chat screen, on a block editor screen, or for a user who cannot open the chat', function (): void {
    Functions\expect('wp_enqueue_script')->never();
    Functions\expect('wp_enqueue_style')->never();
    Functions\expect('wp_localize_script')->never();
    foreach ([
        'the chat screen itself' => [($this->screen)(Assets::HOOK), true],
        'a block editor screen' => [($this->screen)('post', true), true],
        'a user without the capability' => [($this->screen)('dashboard'), false],
        'no screen at all' => [null, true],
    ] as $case => [$screen, $can]) {
        Functions\when('get_current_screen')->justReturn($screen);
        Functions\when('current_user_can')->justReturn($can);
        ob_start();
        adminDrawer()->footer();
        adminDrawer()->enqueue();
        expect((string) ob_get_clean())->toBe('', $case)
            ->and(adminDrawer()->wanted())->toBeFalse($case);
    }
});

it('enqueues the loader and its stylesheet only: no chat bundle, no htmx, no media library, with the settings the lazy chat will read', function (): void {
    Functions\when('get_current_screen')->justReturn(($this->screen)('edit-post'));
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('wp_convert_hr_to_bytes')->justReturn(8 * 1024 * 1024);
    Functions\expect('wp_enqueue_media')->never();
    $scripts = [];
    Functions\when('wp_enqueue_script')->alias(function (string $handle, string $src, array $deps, string $ver, bool $footer) use (&$scripts): void {
        $scripts[$handle] = [$src, $deps, $ver, $footer];
    });
    $styles = [];
    Functions\when('wp_enqueue_style')->alias(function (string $handle, string $src, array $deps, string $ver) use (&$styles): void {
        $styles[$handle] = [$src, $deps, $ver];
    });
    $localized = [];
    Functions\when('wp_localize_script')->alias(function (string $handle, string $name, array $data) use (&$localized): void {
        $localized[$name] = [$handle, $data];
    });

    adminDrawer()->enqueue();

    expect($scripts)->toBe([Drawer::HANDLE => ['/plugins/alpaca-bot/assets/js/drawer.js', ['heartbeat'], Plugin::VERSION, true]])
        ->and($styles)->toBe([Drawer::HANDLE => ['/plugins/alpaca-bot/assets/css/alpaca-bot-drawer.css', ['dashicons'], Plugin::VERSION]])
        ->and(array_keys($localized))->toBe(['alpacaBot', 'alpacaBotMount'])
        ->and($localized['alpacaBot'])->toBe([Drawer::HANDLE, (new Assets())->settings()])
        ->and($localized['alpacaBotMount'])->toBe([Drawer::HANDLE, (new Assets())->mount()]);
});
