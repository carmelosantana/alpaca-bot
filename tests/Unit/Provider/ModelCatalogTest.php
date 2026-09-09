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

it('lists models from the provider, caches them, and carries their capability flags', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('get_transient')->once()->with(ModelCatalog::TRANSIENT)->andReturn(false);
    Functions\expect('set_transient')->once()->withArgs(fn(string $k, array $v, int $ttl): bool => $k === ModelCatalog::TRANSIENT && $ttl === 300 && count($v) === 2);
    Filters\expectApplied('alpaca_bot/models')->once()->andReturnFirstArg();
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([
        new ModelDefinition(id: 'llama3.2:latest', name: 'llama3.2', provider: 'ollama'),
        // Ollama reports llava as vision and not tools; the plugin used to guess that from the
        // name and now reads it here, which is why the definition carries it (Provider\ModelTest).
        new ModelDefinition(id: 'llava:7b', name: 'llava', provider: 'ollama', vision: true),
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

it('serves a second catalog from the transient the first one wrote, hitting the provider once', function (): void {
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
    $factory = catalogFactory($provider);
    $first = (new ModelCatalog($factory))->all();
    $second = (new ModelCatalog($factory))->all();
    expect($cached)->toBe([['id' => 'qwen3:8b', 'label' => 'qwen3:8b', 'tools' => true, 'vision' => false, 'thinking' => true]])
        ->and($second)->toEqual($first)
        ->and($second[0]->thinking)->toBeTrue();
});

it('memoises the discovered list per request, including an empty one from a downed provider', function (): void {
    Functions\expect('get_transient')->once()->andReturn(false);
    Functions\expect('set_transient')->never();
    $down = Mockery::mock(ProviderInterface::class);
    $down->shouldReceive('models')->once()->andThrow(new RuntimeException('connection refused'));
    $catalog = new ModelCatalog(catalogFactory($down));
    expect($catalog->all())->toBe([])
        ->and($catalog->find('llama3.2'))->toBeNull()
        ->and($catalog->defaultId(new Store(['models.default' => 'configured'])))->toBe('configured')
        ->and($catalog->all())->toBe([]);
});

it('refresh bypasses the memo and the transient and hits the provider again', function (): void {
    Functions\expect('get_transient')->never();
    Functions\expect('set_transient')->twice()->andReturn(true);
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->twice()->andReturn([new ModelDefinition(id: 'llama3.2', name: 'llama3.2', provider: 'ollama')]);
    $catalog = new ModelCatalog(catalogFactory($provider, $provider));
    expect($catalog->all(true))->toHaveCount(1)
        ->and($catalog->all(true))->toHaveCount(1);
});

it('uses the cached list and resolves the default id', function (): void {
    Functions\when('get_option')->justReturn(['models.default' => 'qwen3:8b']);
    Functions\when('get_transient')->justReturn([['id' => 'qwen3:8b', 'label' => 'qwen3:8b', 'tools' => true, 'vision' => false, 'thinking' => true]]);
    $c = new ModelCatalog(untouchedFactory());
    expect($c->find('qwen3:8b')?->thinking)->toBeTrue()->and($c->defaultId(new Store()))->toBe('qwen3:8b')->and($c->find('nope'))->toBeNull();
});

it('drops cached entries that are not arrays or carry no id', function (): void {
    Functions\when('get_transient')->justReturn([['id' => ''], ['label' => 'no id'], 'junk', null, ['id' => 'ok:1b']]);
    $c = new ModelCatalog(untouchedFactory());
    expect($c->all())->toHaveCount(1)
        ->and($c->all()[0]->id)->toBe('ok:1b')
        ->and($c->find(''))->toBeNull();
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

it('reads as an empty catalog when a filter-supplied provider returns something other than ModelDefinition[] from models()', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\expect('set_transient')->never();
    $malformed = Mockery::mock(ProviderInterface::class);
    $malformed->shouldReceive('models')->once()->andReturn(['llama3.2', ['id' => 'qwen3:8b'], null]);
    $catalog = new ModelCatalog(catalogFactory($malformed));
    expect($catalog->all())->toBe([])
        ->and($catalog->find('llama3.2'))->toBeNull();
});

it('lets a broken alpaca_bot/provider filter fail loudly instead of reading as an empty catalog', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\expect('set_transient')->never();
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturn('not a provider');
    (new ModelCatalog(new Factory(new Store([]))))->all();
})->throws(UnexpectedValueException::class, 'alpaca_bot/provider');

it('honours capability flags the provider discovered and drops embedding models', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([
        new ModelDefinition(id: 'mystery:1b', name: 'mystery', provider: 'ollama', toolCalls: true, vision: true, thinking: true),
        new ModelDefinition(id: 'nomic-embed-text:latest', name: 'nomic-embed-text', provider: 'ollama'),
        new ModelDefinition(id: 'Mxbai-EMBED-large', name: 'mxbai', provider: 'ollama'),
    ]);
    $models = (new ModelCatalog(catalogFactory($provider)))->all();
    expect($models)->toHaveCount(1)
        ->and($models[0]->id)->toBe('mystery:1b')
        ->and($models[0]->tools)->toBeTrue()
        ->and($models[0]->vision)->toBeTrue()
        ->and($models[0]->thinking)->toBeTrue();
});

it('caches the discovered list, not the filtered one, and ignores non-Model entries from the filter', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\expect('set_transient')->once()->withArgs(fn(string $k, array $v): bool => count($v) === 1 && $v[0]['id'] === 'real:1b');
    Filters\expectApplied('alpaca_bot/models')->once()
        ->with(Mockery::on(fn(array $in): bool => count($in) === 1 && $in[0]->id === 'real:1b'))
        ->andReturn([new Model('added:1b', 'Added'), 'garbage']);
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([new ModelDefinition(id: 'real:1b', name: 'real', provider: 'ollama')]);
    $models = (new ModelCatalog(catalogFactory($provider)))->all();
    expect($models)->toHaveCount(1)->and($models[0]->label)->toBe('Added');
});

it('applies alpaca_bot/models on a transient hit too, so a context-dependent filter is never baked in', function (): void {
    Functions\when('get_transient')->justReturn([['id' => 'a:1b'], ['id' => 'b:1b']]);
    Functions\expect('set_transient')->never();
    Filters\expectApplied('alpaca_bot/models')->twice()
        ->with(Mockery::on(fn(array $in): bool => array_map(fn(Model $m): string => $m->id, $in) === ['a:1b', 'b:1b']))
        ->andReturnUsing(fn(array $in): array => [$in[1]]);
    $c = new ModelCatalog(untouchedFactory());
    expect(array_map(fn(Model $m): string => $m->id, $c->all()))->toBe(['b:1b'])
        ->and($c->find('a:1b'))->toBeNull();
});
