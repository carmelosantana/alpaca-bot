<?php

declare(strict_types=1);

use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\Model;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A real Factory whose alpaca_bot/provider filter hands back the given provider mock: Factory is
 * final (not mockable), and the filter is the documented seam for swapping the provider anyway.
 */
function catalogFactory(ProviderInterface ...$providers): Factory
{
    Filters\expectApplied('alpaca_bot/provider')->times(count($providers))->andReturn(...$providers);
    return new Factory(new Store([]));
}

/** A Factory that must never build a provider. */
function untouchedFactory(): Factory
{
    Filters\expectApplied('alpaca_bot/provider')->never();
    return new Factory(new Store([]));
}

it('lists models from the provider, caches them, and flags capabilities by name', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('get_transient')->once()->with(ModelCatalog::TRANSIENT)->andReturn(false);
    Functions\expect('set_transient')->once()->withArgs(fn(string $k, array $v, int $ttl): bool => $k === ModelCatalog::TRANSIENT && $ttl === 300 && count($v) === 2);
    Filters\expectApplied('alpaca_bot/models')->once()->andReturnFirstArg();
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([
        new ModelDefinition(id: 'llama3.2:latest', name: 'llama3.2', provider: 'ollama'),
        new ModelDefinition(id: 'llava:7b', name: 'llava', provider: 'ollama'),
    ]);
    $models = (new ModelCatalog(catalogFactory($provider)))->all();
    expect($models)->toHaveCount(2)
        ->and($models[0])->toBeInstanceOf(Model::class)
        ->and($models[0]->id)->toBe('llama3.2:latest')
        ->and($models[0]->tools)->toBeTrue()
        ->and($models[0]->vision)->toBeFalse()
        ->and($models[1]->vision)->toBeTrue()
        ->and($models[1]->tools)->toBeFalse();
});

it('reads the cached list on the second call instead of hitting the provider twice', function (): void {
    $cached = false;
    Functions\expect('get_transient')->twice()->with(ModelCatalog::TRANSIENT)->andReturnUsing(function () use (&$cached): mixed {
        return $cached;
    });
    Functions\expect('set_transient')->once()->andReturnUsing(function (string $k, array $v, int $ttl) use (&$cached): bool {
        $cached = $v;
        return true;
    });
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([new ModelDefinition(id: 'qwen3:8b', name: 'qwen3', provider: 'ollama')]);
    $catalog = new ModelCatalog(catalogFactory($provider));
    $first = $catalog->all();
    $second = $catalog->all();
    expect($cached)->toBe([['id' => 'qwen3:8b', 'label' => 'qwen3:8b', 'tools' => true, 'vision' => false, 'thinking' => true]])
        ->and($second)->toEqual($first)
        ->and($second[0]->thinking)->toBeTrue();
});

it('refresh bypasses the cache and hits the provider again', function (): void {
    Functions\expect('get_transient')->never();
    Functions\expect('set_transient')->once()->andReturn(true);
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([new ModelDefinition(id: 'llama3.2', name: 'llama3.2', provider: 'ollama')]);
    expect((new ModelCatalog(catalogFactory($provider)))->all(true))->toHaveCount(1);
});

it('uses the cached list and resolves the default id', function (): void {
    Functions\when('get_option')->justReturn(['models.default' => 'qwen3:8b']);
    Functions\when('get_transient')->justReturn([['id' => 'qwen3:8b', 'label' => 'qwen3:8b', 'tools' => true, 'vision' => false, 'thinking' => true]]);
    $c = new ModelCatalog(untouchedFactory());
    expect($c->find('qwen3:8b')?->thinking)->toBeTrue()->and($c->defaultId(new Store()))->toBe('qwen3:8b')->and($c->find('nope'))->toBeNull();
});

it('falls back to the first model when models.default is empty or not in the catalog', function (): void {
    Functions\when('get_transient')->justReturn([['id' => 'first:1b'], ['id' => 'second:1b']]);
    $c = new ModelCatalog(untouchedFactory());
    expect($c->defaultId(new Store(['models.default' => ''])))->toBe('first:1b')
        ->and($c->defaultId(new Store(['models.default' => 'ghost'])))->toBe('first:1b')
        ->and($c->defaultId(new Store(['models.default' => 'second:1b'])))->toBe('second:1b');
});

it('returns an empty list and caches nothing when the provider fails or has no models', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\expect('set_transient')->never();
    $throwing = Mockery::mock(ProviderInterface::class);
    $throwing->shouldReceive('models')->once()->andThrow(new RuntimeException('connection refused'));
    $empty = Mockery::mock(ProviderInterface::class);
    $empty->shouldReceive('models')->twice()->andReturn([]);
    $factory = catalogFactory($throwing, $empty, $empty);
    expect((new ModelCatalog($factory))->all())->toBe([])
        ->and((new ModelCatalog($factory))->all())->toBe([])
        ->and((new ModelCatalog($factory))->defaultId(new Store(['models.default' => 'configured'])))->toBe('configured');
});

it('honours capability flags the provider discovered and drops embedding models', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([
        new ModelDefinition(id: 'mystery:1b', name: 'mystery', provider: 'ollama', toolCalls: true, vision: true, thinking: true),
        new ModelDefinition(id: 'nomic-embed-text:latest', name: 'nomic-embed-text', provider: 'ollama'),
    ]);
    $models = (new ModelCatalog(catalogFactory($provider)))->all();
    expect($models)->toHaveCount(1)
        ->and($models[0]->id)->toBe('mystery:1b')
        ->and($models[0]->tools)->toBeTrue()
        ->and($models[0]->vision)->toBeTrue()
        ->and($models[0]->thinking)->toBeTrue();
});

it('lets the alpaca_bot/models filter reshape the list and ignores non-Model entries', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\expect('set_transient')->once()->withArgs(fn(string $k, array $v): bool => count($v) === 1 && $v[0]['id'] === 'added:1b');
    Filters\expectApplied('alpaca_bot/models')->once()->andReturn([new Model('added:1b', 'Added'), 'garbage']);
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([]);
    $models = (new ModelCatalog(catalogFactory($provider)))->all();
    expect($models)->toHaveCount(1)->and($models[0]->label)->toBe('Added');
});
