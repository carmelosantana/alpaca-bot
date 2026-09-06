<?php

declare(strict_types=1);

use AlpacaBot\Rest\SettingsController;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

// restRequest() lives in tests/Pest.php. Store is final, so the controller runs over the real
// one with get_option()/update_option() stubbed: what is pinned here is the masking round trip
// (a masked key read back keeps the stored one, an empty one clears it), that a PUT is a partial
// update of top-level keys through Schema::sanitize(), and what the schema route lets out.
// SettingsRoutesTest (integration) runs the same routes over real core dispatch.

beforeEach(function (): void {
    Functions\when('is_user_logged_in')->justReturn(true);
    $this->stored = ['provider.api_key' => 'secret', 'models.temperature' => 0.5, 'models.overrides' => ['a' => ['temperature' => 0.1], 'b' => ['num_ctx' => 1024]]];
    Functions\when('get_option')->alias(fn(): array => $this->stored);
    $this->written = null;
    Functions\when('update_option')->alias(function (string $k, array $v): bool {
        $this->written = $v;
        return true;
    });
    $this->controller = new SettingsController(new Store());
});

it('declares read, write and schema routes for administrators only, none rate limited', function (): void {
    $routes = $this->controller->routes();
    expect(array_map(static fn(array $r): array => [$r['path'], $r['methods']], $routes))->toBe([
        ['/settings', 'GET'],
        ['/settings', 'PUT'],
        ['/settings/schema', 'GET'],
    ])
        ->and(array_unique(array_column($routes, 'capability')))->toBe(['manage_options'])
        ->and(array_filter($routes, static fn(array $r): bool => !empty($r['rate_limit'])))->toBe([])
        ->and($routes[0]['args'])->toBe(['reveal' => ['type' => 'boolean', 'default' => false]]);
});

it('masks a stored api key on read and leaves an empty one empty', function (): void {
    $data = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings'))->get_data();
    expect($data['provider.api_key'])->toBe(SettingsController::MASK)
        ->and($data['models.temperature'])->toBe(0.5)
        ->and(array_keys($data))->toBe(array_keys(Schema::fields()));

    $this->stored['provider.api_key'] = '';
    $data = (new SettingsController(new Store()))->show(restRequest('GET', '/alpaca-bot/v1/settings'))->get_data();
    expect($data['provider.api_key'])->toBe('');
});

it('reveals the raw key on request, and marks that response no-store', function (): void {
    $plain = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings'));
    expect($plain->get_headers())->toBe([]);
    $revealed = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/settings', ['reveal' => true]));
    expect($revealed->get_data()['provider.api_key'])->toBe('secret')
        ->and($revealed->get_headers())->toBe(['Cache-Control' => 'no-store']);
});

it('keeps the stored key when a write echoes the mask back, and merges the other keys', function (): void {
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['provider.api_key' => SettingsController::MASK, 'models.num_ctx' => 2048]));
    expect($this->written['provider.api_key'])->toBe('secret')
        ->and($this->written['models.num_ctx'])->toBe(2048)
        ->and($this->written['models.temperature'])->toBe(0.5)
        ->and(array_keys($this->written))->toBe(array_keys(Schema::fields()))
        // The reply is the masked read, never the raw key, whatever the body carried.
        ->and($res->get_data()['provider.api_key'])->toBe(SettingsController::MASK)
        ->and($res->get_data()['models.num_ctx'])->toBe(2048);
});

it('clears the key when the write sends an empty string', function (): void {
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['provider.api_key' => '']));
    expect($this->written['provider.api_key'])->toBe('')
        ->and($res->get_data()['provider.api_key'])->toBe('');
});

it('never reveals in a write reply, even when the body asks', function (): void {
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['reveal' => true, 'models.num_ctx' => 2048]));
    expect($res->get_data()['provider.api_key'])->toBe(SettingsController::MASK)
        ->and($res->get_headers())->toBe([]);
});

it('drops unknown keys, clamps ranges and coerces types through the schema', function (): void {
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['models.temperature' => 5, 'chat.spellcheck' => 'false', 'provider.timeout' => '90', 'bogus' => 1]));
    expect($this->written['models.temperature'])->toBe(2.0)
        ->and($this->written['chat.spellcheck'])->toBeFalse()
        ->and($this->written['provider.timeout'])->toBe(90)
        ->and($this->written)->not->toHaveKey('bogus')
        ->and($res->get_data())->not->toHaveKey('bogus');
});

it('replaces models.overrides wholesale rather than merging models in', function (): void {
    $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['models.overrides' => ['c' => ['keep_alive' => '1h']]]));
    expect($this->written['models.overrides'])->toBe(['c' => ['keep_alive' => '1h']]);
});

it('reads the keys from a JSON body under real dispatch semantics', function (): void {
    $request = new WP_REST_Request('PUT', '/alpaca-bot/v1/settings');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body((string) json_encode(['models.num_ctx' => 4096]));
    // What core carries on the query string of a plain-permalink request rides along and is ignored.
    $request->set_param('rest_route', '/alpaca-bot/v1/settings');
    $this->controller->update($request);
    expect($this->written['models.num_ctx'])->toBe(4096);
});

it('refuses a write that names no setting at all with a 400', function (): void {
    Functions\when('__')->returnArg();
    $res = $this->controller->update(restRequest('PUT', '/alpaca-bot/v1/settings', ['rest_route' => '/alpaca-bot/v1/settings', 'models_num_ctx' => 4096]));
    expect($res)->toBeInstanceOf(WP_Error::class)
        ->and($res->get_error_code())->toBe('alpaca_bot_bad_request')
        ->and($res->get_error_data()['status'])->toBe(400)
        ->and($this->written)->toBeNull();
});

it('exposes the schema without the sanitize callables, flagging the secret and naming the mask', function (): void {
    $data = $this->controller->schema(restRequest('GET', '/alpaca-bot/v1/settings/schema'))->get_data();
    expect($data['sections'])->toBe(Schema::sections())
        ->and($data['mask'])->toBe(SettingsController::MASK)
        ->and(array_keys($data['fields']))->toBe(array_keys(Schema::fields()))
        ->and($data['fields']['provider.api_key']['secret'])->toBeTrue()
        ->and($data['fields']['provider.base_url'])->not->toHaveKey('sanitize')
        ->and($data['fields']['provider.base_url'])->not->toHaveKey('secret')
        ->and($data['fields']['models.temperature'])->toMatchArray(['type' => 'number', 'default' => 0.7, 'min' => 0, 'max' => 2]);
    // Nothing left is an object (a Closure included): the payload is plain data all the way down.
    array_walk_recursive($data, static function (mixed $v): void {
        expect($v)->not->toBeObject();
    });
    expect(json_encode($data))->toBeString();
});
