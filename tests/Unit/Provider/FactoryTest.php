<?php

declare(strict_types=1);

use AlpacaBot\Provider\Factory;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/** Read a protected/private property the library exposes no getter for. */
function providerProp(object $object, string $property, ?string $class = null): mixed
{
    return (new ReflectionProperty($class ?? $object::class, $property))->getValue($object);
}

/**
 * OLLAMA_API_URL is a process-wide constant and Pest runs the suite in one
 * process: the constant test is last in this file, and the settings-path
 * tests guard against a reordered run rather than asserting a stale value.
 */
function skipIfOllamaConstantDefined(): void
{
    if (defined('OLLAMA_API_URL')) {
        test()->markTestSkipped('OLLAMA_API_URL was defined by an earlier test; settings path not testable in this run.');
    }
}

it('builds an OllamaProvider from settings with the default model', function (): void {
    Functions\when('get_option')->justReturn(['models.default' => 'llama3.2', 'provider.base_url' => 'http://ollama:11434/v1']);
    Filters\expectApplied('alpaca_bot/provider')->once()
        ->with(Mockery::type(OllamaProvider::class), 'llama3.2', Mockery::type(Store::class))
        ->andReturnFirstArg();
    $p = (new Factory(new Store()))->make();
    expect($p)->toBeInstanceOf(OllamaProvider::class)->and($p->getModel())->toBe('llama3.2');
});

it('uses an explicit model over the settings default', function (): void {
    $p = (new Factory(new Store(['models.default' => 'llama3.2'])))->make('qwen3:8b');
    expect($p->getModel())->toBe('qwen3:8b');
});

it('reads provider.base_url from settings and normalises it to a /v1 endpoint', function (string $stored, string $expected): void {
    skipIfOllamaConstantDefined();
    $factory = new Factory(new Store(['provider.base_url' => $stored]));
    $provider = $factory->make('m');
    expect($factory->baseUrl())->toBe($expected)
        ->and(providerProp($provider, 'baseUrl', AbstractProvider::class))->toBe($expected);
})->with([
    'bare host' => ['http://ollama:11434', 'http://ollama:11434/v1'],
    'trailing slash' => ['http://ollama:11434/', 'http://ollama:11434/v1'],
    'already /v1' => ['http://ollama:11434/v1', 'http://ollama:11434/v1'],
    'already /v1 with slash' => ['http://ollama:11434/v1/', 'http://ollama:11434/v1'],
]);

it('wires provider.timeout into the HTTP client it injects', function (): void {
    $provider = (new Factory(new Store(['provider.timeout' => 42])))->make('m');
    $client = providerProp($provider, 'httpClient', AbstractProvider::class);
    expect($client)->toBeInstanceOf(HttpClientInterface::class)
        ->and(providerProp($client, 'defaultOptions')['timeout'])->toEqual(42);
});

it('builds an OpenAICompatibleProvider carrying the key when provider.api_key is set', function (): void {
    skipIfOllamaConstantDefined();
    $store = new Store(['provider.api_key' => 'sk-secret', 'provider.base_url' => 'http://ollama:11434', 'models.default' => 'llama3.2']);
    $p = (new Factory($store))->make();
    expect($p)->toBeInstanceOf(OpenAICompatibleProvider::class)
        ->and($p)->not->toBeInstanceOf(OllamaProvider::class)
        ->and($p->getModel())->toBe('llama3.2')
        ->and(providerProp($p, 'apiKey', AbstractProvider::class))->toBe('sk-secret')
        ->and(providerProp($p, 'baseUrl', AbstractProvider::class))->toBe('http://ollama:11434/v1');
});

it('falls through to the default provider for the wp-ai kind until its adapter lands', function (): void {
    $p = (new Factory(new Store(['provider.kind' => 'wp-ai'])))->make('m');
    expect($p)->toBeInstanceOf(OllamaProvider::class);
});

it('lets the alpaca_bot/provider filter replace the provider', function (): void {
    Functions\when('get_option')->justReturn([]);
    $fake = Mockery::mock(ProviderInterface::class);
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturn($fake);
    expect((new Factory(new Store()))->make('x'))->toBe($fake);
});

it('rejects a filter that does not return a provider', function (): void {
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturn('not a provider');
    (new Factory(new Store([])))->make('x');
})->throws(UnexpectedValueException::class, 'alpaca_bot/provider');

it('prefers the OLLAMA_API_URL constant and appends /v1', function (): void {
    if (!defined('OLLAMA_API_URL')) {
        define('OLLAMA_API_URL', 'http://host.docker.internal:11434');
    }
    Functions\when('get_option')->justReturn([]);
    expect((new Factory(new Store()))->baseUrl())->toBe('http://host.docker.internal:11434/v1');
});
