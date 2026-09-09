<?php

declare(strict_types=1);

use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Rest\ModelsController;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// restRequest() lives in tests/Pest.php. ModelCatalog and Factory are final, so the controller
// runs over the real catalog with the provider swapped through `alpaca_bot/provider`, the seam
// Factory documents, and the transient stubbed. SettingsRoutesTest (integration) runs the route
// over real core dispatch.

beforeEach(function (): void {
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('set_transient')->justReturn(true);
    Filters\expectApplied('alpaca_bot/models')->zeroOrMoreTimes()->andReturnFirstArg();
    $this->store = new Store(['models.default' => 'llava:7b']);
    $this->controller = new ModelsController(new ModelCatalog(new Factory($this->store)), $this->store);
});

it('declares one route for editors with a boolean refresh switch, rate limited with the chat routes', function (): void {
    $routes = $this->controller->routes();
    expect($routes)->toHaveCount(1)
        ->and($routes[0])->toMatchArray(['path' => '/models', 'methods' => 'GET', 'capability' => 'edit_posts', 'rate_limit' => true])
        ->and($routes[0]['args'])->toBe(['refresh' => ['type' => 'boolean', 'default' => false]]);
});

it('lists the catalog as plain arrays and names the default model in a header', function (): void {
    Functions\expect('get_transient')->once()->with(ModelCatalog::TRANSIENT)->andReturn([
        ['id' => 'llama3.2:latest', 'label' => 'llama3.2', 'tools' => true],
        ['id' => 'llava:7b', 'label' => 'llava', 'vision' => true],
    ]);
    Filters\expectApplied('alpaca_bot/provider')->never();
    $res = $this->controller->index(restRequest('GET', '/alpaca-bot/v1/models'));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)
        ->and($res->get_status())->toBe(200)
        ->and($res->get_data())->toBe([
            ['id' => 'llama3.2:latest', 'label' => 'llama3.2', 'tools' => true, 'vision' => false, 'thinking' => false],
            ['id' => 'llava:7b', 'label' => 'llava', 'tools' => false, 'vision' => true, 'thinking' => false],
        ])
        ->and($res->get_headers())->toBe(['X-Alpaca-Bot-Default-Model' => 'llava:7b']);
});

// The flag is a label here, nothing routes on it — but it is the label an operator reads back to
// check the switch they just threw, so it has to agree with the switch. The overlay is on the way
// out only: the catalog's own Model objects, its request memo and its transient keep the
// provider's answer, which is what the next turn's inherit case must still be able to read.
it('reports the operator\'s per-model tools override in the listing, leaving the catalog itself alone', function (): void {
    Functions\expect('get_transient')->once()->with(ModelCatalog::TRANSIENT)->andReturn([
        ['id' => 'llama3.2:latest', 'label' => 'llama3.2', 'tools' => true],
        ['id' => 'llava:7b', 'label' => 'llava', 'vision' => true],
        ['id' => 'qwen3:8b', 'label' => 'qwen3', 'tools' => true],
    ]);
    $store = new Store(['models.default' => 'llava:7b', 'models.overrides' => [
        'llama3.2:latest' => ['tools' => Schema::TOOLS_OFF],
        'llava:7b' => ['tools' => Schema::TOOLS_ON],
        // Inherit: a row with no tools key at all, as every row stored before 0.5.0 has.
        'qwen3:8b' => ['temperature' => '0.2'],
    ]]);
    $catalog = new ModelCatalog(new Factory($store));
    $res = (new ModelsController($catalog, $store))->index(restRequest('GET', '/alpaca-bot/v1/models'));

    expect(array_column($res->get_data(), 'tools'))->toBe([false, true, true])
        // Other flags are untouched, and so is the catalog the same request goes on to read.
        ->and(array_column($res->get_data(), 'vision'))->toBe([false, true, false])
        ->and($catalog->find('llama3.2:latest')?->tools)->toBeTrue()
        ->and($catalog->find('llava:7b')?->tools)->toBeFalse();
});

it('asks the provider again when refresh is set, skipping the transient', function (): void {
    Functions\expect('get_transient')->never();
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([new ModelDefinition(id: 'qwen3:8b', name: 'qwen3', provider: 'ollama')]);
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturn($provider);
    $res = $this->controller->index(restRequest('GET', '/alpaca-bot/v1/models', ['refresh' => true]));
    expect(array_column($res->get_data(), 'id'))->toBe(['qwen3:8b'])
        // The configured default is not listed, so the header falls back to the first model.
        ->and($res->get_headers())->toBe(['X-Alpaca-Bot-Default-Model' => 'qwen3:8b']);
});

it('answers an empty list, with the configured default, when the provider is unreachable', function (): void {
    Functions\when('get_transient')->justReturn(false);
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andThrow(new RuntimeException('Connection refused for "http://host:11434/v1/models"'));
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturn($provider);
    $res = $this->controller->index(restRequest('GET', '/alpaca-bot/v1/models'));
    expect($res->get_data())->toBe([])
        ->and($res->get_headers())->toBe(['X-Alpaca-Bot-Default-Model' => 'llava:7b']);
});

it('maps a provider that cannot be built to the 502 provider error', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('__')->returnArg();
    // A misbehaving alpaca_bot/provider filter: Factory::make() throws, and the catalog lets it out.
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturn('not a provider');
    $res = $this->controller->index(restRequest('GET', '/alpaca-bot/v1/models'));
    expect($res)->toBeInstanceOf(WP_Error::class)
        ->and($res->get_error_code())->toBe('alpaca_bot_provider_error')
        ->and($res->get_error_data()['status'])->toBe(502);
});
