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

it('versions the build outputs by the plugin version when a file is not there to be stamped', function (): void {
    // Outside WP_DEBUG the version is the plugin's; under it, filemtime of a file that exists.
    // The unit process has no WP_DEBUG, so what this pins is the fallback: a checkout without
    // `pnpm build` run (the outputs are gitignored) enqueues without a filemtime() warning.
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
});
