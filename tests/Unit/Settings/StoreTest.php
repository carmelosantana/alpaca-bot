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

it('resolves a masked secret against what it already holds, on set and on replace', function (): void {
    Functions\when('get_option')->justReturn(['provider.api_key' => 'sk-stored']);
    $written = [];
    Functions\when('update_option')->alias(function (string $name, array $value) use (&$written): bool {
        $written[] = $value['provider.api_key'];
        return true;
    });
    $s = new Store();
    $s->set('provider.api_key', Schema::MASK);
    expect($s->get('provider.api_key'))->toBe('sk-stored');
    $s->replace(['provider.api_key' => Schema::MASK, 'models.num_ctx' => 1024]);
    expect($s->get('provider.api_key'))->toBe('sk-stored')
        ->and($s->get('models.num_ctx'))->toBe(1024);
    $s->replace(['provider.api_key' => null]);
    expect($s->get('provider.api_key'))->toBe('sk-stored');
    $s->replace(['provider.api_key' => '']);
    expect($s->get('provider.api_key'))->toBe('')
        ->and($written)->toBe(['sk-stored', 'sk-stored', 'sk-stored', '']);
});

// The tools override is read on the turn, not baked into the model catalog, so the Store is
// where the pipeline asks. Only the two stored literals answer; anything else is inherit, which
// is null, and null is the only value the caller may read as "ask the catalogue".
it('toolsOverride answers true, false or inherit for one model', function (): void {
    Functions\when('get_option')->justReturn(['models.overrides' => [
        'on-model' => ['tools' => Schema::TOOLS_ON],
        'off-model' => ['tools' => Schema::TOOLS_OFF, 'temperature' => 0.2],
        'blank-model' => ['tools' => ''],
        'other-model' => ['temperature' => 0.2],
        'junk-model' => ['tools' => 'yes'],
    ]]);
    $s = new Store();
    expect($s->toolsOverride('on-model'))->toBeTrue()
        ->and($s->toolsOverride('off-model'))->toBeFalse()
        ->and($s->toolsOverride('blank-model'))->toBeNull()
        ->and($s->toolsOverride('other-model'))->toBeNull()
        ->and($s->toolsOverride('junk-model'))->toBeNull()
        ->and($s->toolsOverride('never-heard-of-it'))->toBeNull();
    // The generation options are untouched by it: `tools` is not one of them.
    expect($s->modelOverrides('off-model'))->toMatchArray(['temperature' => 0.2, 'num_ctx' => 8192, 'keep_alive' => '5m']);
});

it('toolsOverride reads inherit from settings that hold no overrides at all', function (): void {
    Functions\expect('get_option')->never();
    expect((new Store([]))->toolsOverride('anything'))->toBeNull()
        ->and((new Store(['models.overrides' => 'not an array']))->toolsOverride('anything'))->toBeNull();
});
