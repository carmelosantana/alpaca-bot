<?php

declare(strict_types=1);

use AlpacaBot\Provider\BearerHttpClient;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\MockHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Response\MockResponse;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/** Read a protected/private property the library exposes no getter for. */
function providerProp(object $object, string $property, ?string $class = null): mixed
{
    return (new ReflectionProperty($class ?? $object::class, $property))->getValue($object);
}

/**
 * Factory::baseUrl() with OLLAMA_API_URL set to `$constant`, read in a fresh PHP process
 * (freshProcess() in Pest.php says why the suite never defines it in this one).
 *
 * @param string|null $constant null leaves the constant undefined
 * @param array<string, mixed> $settings seeded into the Store
 */
function baseUrlInFreshProcess(?string $constant, array $settings = []): string
{
    $script = <<<'PHP_SCRIPT'
    <?php
    [, $root, $defined, $constant, $settings] = $argv;
    require $root . '/vendor/autoload.php';
    require $root . '/vendor-prefixed/autoload.php';
    function __(string $text, string $domain = 'default'): string { return $text; }
    if ($defined === '1') { define('OLLAMA_API_URL', $constant); }
    echo (new AlpacaBot\Provider\Factory(new AlpacaBot\Settings\Store(json_decode($settings, true))))->baseUrl();
    PHP_SCRIPT;

    return freshProcess($script, [dirname(__DIR__, 3), $constant === null ? '0' : '1', (string) $constant, json_encode($settings, JSON_THROW_ON_ERROR)]);
}

beforeEach(function (): void {
    // Every in-process test here exercises the settings path; a stray define() anywhere in
    // the suite would silently retarget them, so make that a failure rather than a skip.
    expect(defined('OLLAMA_API_URL'))->toBeFalse('OLLAMA_API_URL must never be defined in the test process; probe it with baseUrlInFreshProcess().');
});

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
    $factory = new Factory(new Store(['provider.base_url' => $stored]));
    $provider = $factory->make('m');
    expect($factory->baseUrl())->toBe($expected)
        ->and(providerProp($provider, 'baseUrl', AbstractProvider::class))->toBe($expected);
})->with([
    'bare host' => ['http://ollama:11434', 'http://ollama:11434/v1'],
    'trailing slash' => ['http://ollama:11434/', 'http://ollama:11434/v1'],
    'already /v1' => ['http://ollama:11434/v1', 'http://ollama:11434/v1'],
    'already /v1 with slash' => ['http://ollama:11434/v1/', 'http://ollama:11434/v1'],
    'mounted under a prefix' => ['http://proxy/ollama/v1', 'http://proxy/ollama/v1'],
    'gateway mount with an earlier /v1 segment' => ['https://gw.example.com/v1/ai/ollama/v1', 'https://gw.example.com/v1/ai/ollama/v1'],
    'gateway mount with an earlier /v1 segment and a trailing slash' => ['https://gw.example.com/v1/ai/ollama/v1/', 'https://gw.example.com/v1/ai/ollama/v1'],
    'deeper path stored by mistake' => ['http://ollama:11434/v1/chat', 'http://ollama:11434/v1'],
    'v1-prefixed segment is not /v1' => ['http://ollama:11434/v1beta', 'http://ollama:11434/v1beta/v1'],
    'empty falls back to the schema default' => ['', 'http://localhost:11434/v1'],
    'whitespace falls back to the schema default' => ['   ', 'http://localhost:11434/v1'],
]);

it('wires provider.timeout into the HTTP client it injects', function (): void {
    $provider = (new Factory(new Store(['provider.timeout' => 42])))->make('m');
    $client = providerProp($provider, 'httpClient', AbstractProvider::class);
    expect($client)->toBeInstanceOf(HttpClientInterface::class)
        ->and($client)->not->toBeInstanceOf(BearerHttpClient::class)
        ->and(providerProp($client, 'defaultOptions')['timeout'])->toEqual(42);
});

it('keeps OllamaProvider and wraps the timeout client in a Bearer decorator when provider.api_key is set', function (): void {
    $store = new Store(['provider.api_key' => 'sk-secret', 'provider.timeout' => 42, 'provider.base_url' => 'http://ollama:11434', 'models.default' => 'llama3.2']);
    $p = (new Factory($store))->make();
    $client = providerProp($p, 'httpClient', AbstractProvider::class);
    expect($p)->toBeInstanceOf(OllamaProvider::class)
        ->and($p->getModel())->toBe('llama3.2')
        ->and(providerProp($p, 'baseUrl', AbstractProvider::class))->toBe('http://ollama:11434/v1')
        ->and($client)->toBeInstanceOf(BearerHttpClient::class)
        ->and(providerProp($client, 'token'))->toBe('sk-secret')
        ->and(providerProp(providerProp($client, 'client'), 'defaultOptions')['timeout'])->toEqual(42);
});

it('puts the configured key on the wire instead of ollama-local, on /v1 and native /api calls alike', function (): void {
    $store = new Store(['provider.api_key' => 'sk-secret', 'provider.base_url' => 'http://ollama:11434', 'models.default' => 'llama3.2']);
    $p = (new Factory($store))->make();

    // The decorator is the factory's; only the transport underneath it is swapped for a recorder.
    $wire = [];
    $recorder = new MockHttpClient(function (string $method, string $url, array $options) use (&$wire): MockResponse {
        $wire[$url] = $options['normalized_headers']['authorization'] ?? null;
        return new MockResponse(str_contains($url, '/api/tags')
            ? '{"models":[]}'
            : '{"choices":[{"message":{"content":"ok"},"finish_reason":"stop"}]}');
    });
    $decorator = providerProp($p, 'httpClient', AbstractProvider::class);
    expect($decorator)->toBeInstanceOf(BearerHttpClient::class); // fail here, not on a real socket, if the wrap regresses
    (new ReflectionProperty(BearerHttpClient::class, 'client'))->setValue($decorator, $recorder);

    expect($p->chat([])->content)->toBe('ok')                         // /v1: AbstractProvider::headers() set 'Bearer ollama-local'
        ->and($p->models())->toBe([])                                 // native: OllamaProvider passes no headers at all
        ->and($wire)->toBe([
            'http://ollama:11434/v1/chat/completions' => ['Authorization: Bearer sk-secret'],
            'http://ollama:11434/api/tags' => ['Authorization: Bearer sk-secret'],
        ]);
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

it('prefers the OLLAMA_API_URL constant over settings and normalises it like a stored URL', function (?string $constant, array $settings, string $expected): void {
    expect(baseUrlInFreshProcess($constant, $settings))->toBe($expected);
})->with([
    'constant wins and gets /v1' => ['http://host.docker.internal:11434', ['provider.base_url' => 'http://ollama:11434/v1'], 'http://host.docker.internal:11434/v1'],
    'constant deeper path is cut back to /v1' => ['http://host.docker.internal:11434/v1/chat', [], 'http://host.docker.internal:11434/v1'],
    'empty constant defers to settings' => ['', ['provider.base_url' => 'http://ollama:11434'], 'http://ollama:11434/v1'],
    'empty constant and empty setting fall back to the schema default' => ['', ['provider.base_url' => ''], 'http://localhost:11434/v1'],
    'undefined constant reads settings (probe sanity)' => [null, ['provider.base_url' => 'http://ollama:11434'], 'http://ollama:11434/v1'],
]);
