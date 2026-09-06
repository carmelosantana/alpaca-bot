<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

it('reads the option once and merges defaults', function (): void {
    Functions\expect('get_option')->once()->with(Plugin::OPTION, [])->andReturn(['models.default' => 'llama3.2']);
    $s = new Store();
    expect($s->get('models.default'))->toBe('llama3.2')
        ->and($s->get('models.temperature'))->toBe(0.7)
        ->and($s->get('missing', 'dflt'))->toBe('dflt');
    $s->all();
});

it('set writes the sanitized full array', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('update_option')->once()->withArgs(function (string $name, array $value): bool {
        return $name === Plugin::OPTION && $value['models.num_ctx'] === 4096 && $value['provider.kind'] === 'ollama';
    })->andReturn(true);
    (new Store())->set('models.num_ctx', '4096');
});

it('modelOverrides merges per-model values over globals', function (): void {
    Functions\when('get_option')->justReturn(['models.temperature' => 0.5, 'models.overrides' => ['qwen3:8b' => ['temperature' => 0.1, 'num_ctx' => 32768]]]);
    $s = new Store();
    expect($s->modelOverrides('qwen3:8b'))->toBe(['temperature' => 0.1, 'num_ctx' => 32768, 'keep_alive' => '5m'])
        ->and($s->modelOverrides('other'))->toBe(['temperature' => 0.5, 'num_ctx' => 8192, 'keep_alive' => '5m']);
});

it('serves an injected cache merged over the defaults, without touching the database', function (): void {
    Functions\expect('get_option')->never();
    $s = new Store(['models.default' => 'seeded']);
    expect($s->get('models.default'))->toBe('seeded')
        ->and($s->all())->toMatchArray(['models.default' => 'seeded'])
        ->and($s->all())->toHaveCount(count(Schema::defaults()))
        ->and($s->get('provider.timeout'))->toBe(60)
        ->and($s->modelOverrides('any'))->toBe(['temperature' => 0.7, 'num_ctx' => 8192, 'keep_alive' => '5m']);
});

it('replace sanitizes, persists, and refreshes the memoized cache', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::type('array'))->andReturn(true);
    $s = new Store();
    $s->replace(['models.temperature' => '1.5', 'nope' => 'x']);
    expect($s->get('models.temperature'))->toBe(1.5)
        ->and($s->all())->not->toHaveKey('nope')
        ->and($s->get('provider.kind'))->toBe('ollama');
});
