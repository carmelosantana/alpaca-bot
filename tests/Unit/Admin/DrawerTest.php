<?php

declare(strict_types=1);

use AlpacaBot\Admin\Assets;
use AlpacaBot\Admin\Drawer;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Plugin;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// adminDrawer() lives in tests/Pest.php. WP_Screen is a class Mockery declares (as HelpTabsTest
// does): `id` and `base` are the properties core sets, is_block_editor() the method core answers
// the editor question with, and get_current_screen() is how a hook gets at it. The page title is
// the global `$title`, as core's admin-header.php leaves it.
beforeEach(function (): void {
    $this->screen = static function (string $id, bool $blockEditor = false, ?string $base = null): Mockery\MockInterface {
        $screen = Mockery::mock('WP_Screen');
        $screen->id = $id;
        $screen->base = $base ?? $id;
        $screen->shouldReceive('is_block_editor')->andReturn($blockEditor);
        return $screen;
    };
    $GLOBALS['title'] = 'Dashboard';
    Functions\when('wp_strip_all_tags')->alias(static fn(string $s): string => trim(strip_tags($s)));
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('get_user_meta')->alias(static fn(int $id, string $key): string => match ($key) {
        UserPrefs::META_DRAWER_OPEN => '1',
        UserPrefs::META_DRAWER_CONVERSATION => '42',
        default => '',
    });
});

afterEach(function (): void {
    unset($GLOBALS['title']);
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

// ---------------------------------------------------------------- Task 19: the screen, for the chip

it('names the screen and its page title on the drawer, for the panel to render as a chip, and no post off the editor', function (): void {
    Functions\when('get_current_screen')->justReturn(($this->screen)('edit-post', false, 'edit'));
    Functions\when('current_user_can')->justReturn(true);
    $GLOBALS['title'] = 'Posts';
    Functions\expect('get_post')->never();
    ob_start();
    adminDrawer()->footer();
    expect((string) ob_get_clean())->toContain('<aside id="ab-drawer" class="ab-drawer" aria-label="Alpaca Bot chat" data-open="1" data-conversation="42" data-screen-id="edit-post" data-screen-title="Posts" data-post="0" hidden></aside>');
});

it('names the classic editor\'s post, the one core loaded for the screen', function (): void {
    Functions\when('get_current_screen')->justReturn(($this->screen)('post', false, 'post'));
    Functions\when('current_user_can')->justReturn(true);
    $GLOBALS['title'] = 'Edit Post';
    $post = Mockery::mock('WP_Post');
    $post->ID = 12;
    Functions\expect('get_post')->once()->withNoArgs()->andReturn($post);
    ob_start();
    adminDrawer()->footer();
    expect((string) ob_get_clean())->toContain('data-screen-id="post" data-screen-title="Edit Post" data-post="12"');

    // A page's classic editor is the same screen base under the post type's own id.
    Functions\when('get_current_screen')->justReturn(($this->screen)('page', false, 'post'));
    $page = Mockery::mock('WP_Post');
    $page->ID = 8;
    Functions\expect('get_post')->once()->withNoArgs()->andReturn($page);
    ob_start();
    adminDrawer()->footer();
    expect((string) ob_get_clean())->toContain('data-screen-id="page" data-screen-title="Edit Post" data-post="8"');

    // No post in the global: none named.
    Functions\expect('get_post')->once()->withNoArgs()->andReturn(null);
    ob_start();
    adminDrawer()->footer();
    expect((string) ob_get_clean())->toContain('data-post="0"');
});

it('writes the page title as text, its entities decoded and its markup gone, and escapes it into the attribute', function (): void {
    Functions\when('get_current_screen')->justReturn(($this->screen)('edit-comments'));
    Functions\when('current_user_can')->justReturn(true);
    // edit-comments.php?p= titles itself this way, with the entities in the translated string.
    // Core has stripped the tags by now (admin-header.php); these are left in to show the footer
    // does not depend on it.
    $GLOBALS['title'] = 'Comments on &#8220;<em>Hello</em>&#8221; &amp; more';
    ob_start();
    adminDrawer()->footer();
    expect((string) ob_get_clean())->toContain('data-screen-title="Comments on “Hello” &amp; more"');

    $GLOBALS['title'] = '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;';
    ob_start();
    adminDrawer()->footer();
    $html = (string) ob_get_clean();
    expect($html)->not->toContain('<script>')
        ->toContain('data-screen-title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"');
});

it('reads the title core left, without asking get_admin_page_title() to walk the menus again', function (): void {
    Functions\when('get_current_screen')->justReturn(($this->screen)('dashboard'));
    Functions\when('current_user_can')->justReturn(true);
    Functions\expect('get_admin_page_title')->never();
    // A screen core found no title for: no title, and so no screen chip on the panel.
    unset($GLOBALS['title']);
    ob_start();
    adminDrawer()->footer();
    expect((string) ob_get_clean())->toContain('data-screen-id="dashboard" data-screen-title="" data-post="0"');
});
