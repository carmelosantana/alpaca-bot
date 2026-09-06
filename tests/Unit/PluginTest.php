<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

it('exposes a version and boots once', function (): void {
    // Mockery only honours a matcher at the top level of an argument, so the
    // [instance, 'register'] callback is pinned with on() rather than type().
    $registerCallback = Mockery::on(
        static fn (mixed $cb): bool => is_array($cb)
            && ($cb[0] ?? null) instanceof Plugin
            && ($cb[1] ?? null) === 'register'
    );
    Actions\expectAdded('plugins_loaded')->once()->with($registerCallback, 9);
    $a = Plugin::boot();
    $b = Plugin::boot();
    expect($a)->toBe($b)
        ->and($a->version())->toBe(Plugin::VERSION)
        ->and(Plugin::VERSION)->toMatch('/^1\.0\.0/');
});

it('registers the settings store and runs the 0.4 migration once on admin_init', function (): void {
    $onAdminInit = null;
    Actions\expectAdded('admin_init')->once()->with(Mockery::on(
        static function (mixed $cb) use (&$onAdminInit): bool {
            $onAdminInit = $cb;
            return $cb instanceof Closure;
        }
    ));
    $plugin = Plugin::boot();
    $plugin->register();
    expect($plugin->get(Store::class))->toBeInstanceOf(Store::class);

    // A 0.4 site: one legacy option present, no flag -> the hook migrates and flags.
    $legacy = ['alpaca_bot_api_url' => 'http://localhost:11434'];
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $legacy[$k] ?? ($k === Plugin::OPTION ? [] : $d));
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::type('array'))->andReturn(true);
    Functions\expect('update_option')->once()->with(Migrate04::FLAG, '1', false)->andReturn(true);
    $onAdminInit();
    expect($plugin->get(Store::class)->get('provider.base_url'))->toBe('http://localhost:11434/v1');
});
