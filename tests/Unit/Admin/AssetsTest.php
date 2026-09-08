<?php

declare(strict_types=1);

use AlpacaBot\Admin\Assets;
use AlpacaBot\Plugin;
use Brain\Monkey\Functions;

it('enqueues nothing on any screen but the chat screen', function (): void {
    foreach (['index.php', 'alpaca-bot_page_alpaca-bot-settings', 'post.php', ''] as $hook) {
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_media')->never();
        (new Assets())->enqueue($hook);
    }
});

it('enqueues htmx, the chat bundle after it, the stylesheet and the media picker on the chat screen, with the REST root and nonce localised', function (): void {
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\stubTranslationFunctions();
    Functions\expect('wp_enqueue_media')->once();
    Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-htmx', '/plugins/alpaca-bot/assets/js/htmx.min.js', [], Assets::HTMX_VERSION, true);
    Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-chat', '/plugins/alpaca-bot/assets/js/chat.js', ['alpaca-bot-htmx', 'wp-api-fetch', 'heartbeat'], Mockery::type('string'), true);
    Functions\expect('wp_enqueue_style')->once()->with('alpaca-bot', '/plugins/alpaca-bot/assets/css/alpaca-bot.css', [], Mockery::type('string'));
    $localised = null;
    Functions\expect('wp_localize_script')->once()->with('alpaca-bot-chat', 'alpacaBot', Mockery::on(static function (array $data) use (&$localised): bool {
        $localised = $data;
        return true;
    }));
    (new Assets())->enqueue(Assets::HOOK);
    expect(Assets::HOOK)->toBe('toplevel_page_alpaca-bot')
        ->and(Assets::HTMX_VERSION)->toMatch('/^2\.0\.\d+$/')
        ->and($localised['rest'])->toBe('/wp-json/alpaca-bot/v1')
        ->and($localised['nonce'])->toBe('n')
        ->and($localised['offline'])->toBeString()->not->toBe('')
        ->and($localised['i18n'])->toBeArray()->not->toBeEmpty();
    foreach ($localised['i18n'] as $key => $text) {
        expect($key)->toBeString()->and($text)->toBeString()->not->toBe('');
    }
});

it('versions the build outputs by the plugin version outside WP_DEBUG, and under it by mtime only for a file that is there', function (): void {
    // The unit process has no WP_DEBUG: the enqueue path takes the plugin version for both files.
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\stubTranslationFunctions();
    Functions\when('wp_enqueue_media')->justReturn();
    Functions\when('wp_localize_script')->justReturn(true);
    $versions = [];
    Functions\when('wp_enqueue_script')->alias(static function (string $handle, string $src, array $deps, string|bool $ver) use (&$versions): void {
        $versions[$handle] = $ver;
    });
    Functions\when('wp_enqueue_style')->alias(static function (string $handle, string $src, array $deps, string|bool $ver) use (&$versions): void {
        $versions[$handle] = $ver;
    });
    (new Assets())->enqueue(Assets::HOOK);
    expect($versions)->toBe(['alpaca-bot-htmx' => Assets::HTMX_VERSION, 'alpaca-bot-chat' => Plugin::VERSION, 'alpaca-bot' => Plugin::VERSION]);

    // Under WP_DEBUG (asked for explicitly), a checkout without `pnpm build` run has no bundle
    // to stamp: the is_readable() guard answers the plugin version, and filemtime() never
    // warns. A file that is there is stamped with its mtime.
    set_error_handler(static function (int $no, string $msg): never {
        throw new ErrorException($msg, $no);
    });
    try {
        expect(Assets::version('assets/js/does-not-exist-' . getmypid() . '.js', true))->toBe(Plugin::VERSION)
            ->and(Assets::version('alpaca-bot.php', true))->toBe((string) filemtime(dirname(ALPACA_BOT_FILE) . '/alpaca-bot.php'))
            ->and(Assets::version('alpaca-bot.php', false))->toBe(Plugin::VERSION);
    } finally {
        restore_error_handler();
    }
});

it('pins htmx to the exact version package.json installs, so a bump busts the browser cache', function (): void {
    // "~2.0.10" would let pnpm install 2.0.11 while the enqueued version string stayed put.
    $package = json_decode((string) file_get_contents(dirname(ALPACA_BOT_FILE) . '/package.json'), true);
    expect($package['devDependencies']['htmx.org'])->toBe(Assets::HTMX_VERSION);
});

it('answers the heartbeat with a fresh REST nonce only when the chat screen asked for one', function (): void {
    // chat.ts sends alpaca_bot_nonce: 1 on heartbeat-send (Kanboard #302); any other heartbeat
    // (another screen, a poll without the flag) is left as it was.
    Functions\when('wp_create_nonce')->alias(fn(string $action) => 'nonce-for-' . $action);
    $assets = new Assets();
    expect($assets->heartbeat(['server_time' => 1], ['alpaca_bot_nonce' => 1]))->toBe(['server_time' => 1, 'alpaca_bot_nonce' => 'nonce-for-wp_rest'])
        ->and($assets->heartbeat(['server_time' => 1], ['alpaca_bot_nonce' => '1']))->toBe(['server_time' => 1, 'alpaca_bot_nonce' => 'nonce-for-wp_rest'])
        ->and($assets->heartbeat(['server_time' => 1], []))->toBe(['server_time' => 1])
        ->and($assets->heartbeat(['server_time' => 1], ['alpaca_bot_nonce' => 0]))->toBe(['server_time' => 1])
        ->and($assets->heartbeat(['server_time' => 1], ['wp-refresh-post-nonces' => ['post_id' => 1]]))->toBe(['server_time' => 1]);
});
