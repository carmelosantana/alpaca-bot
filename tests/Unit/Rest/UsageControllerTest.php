<?php

declare(strict_types=1);

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Rest\UsageController;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

// restRequest() and capPolicyUsageCache() live in tests/Pest.php. UsageMeter is final, so the
// controller runs over the real meter with the month caches served from stubbed transients: the
// clock is 1_725_000_000 (August 2024) and the current user is 3, as in UsageMeterTest.
// SettingsRoutesTest (integration) runs the route over real chat_log rows.

beforeEach(function (): void {
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('current_time')->justReturn(1_725_000_000);
    capPolicyUsageCache(1500, 90000);
    $store = new Store(['governance.user_monthly_tokens' => 10000, 'governance.site_monthly_tokens' => 0]);
    $this->controller = new UsageController(new UsageMeter($store), $store);
});

it('declares one route for editors whose user switch is me or all, not rate limited', function (): void {
    $routes = $this->controller->routes();
    expect($routes)->toHaveCount(1)
        ->and($routes[0])->toMatchArray(['path' => '/usage', 'methods' => 'GET', 'capability' => 'edit_posts'])
        ->and($routes[0]['args'])->toBe(['user' => ['type' => 'string', 'default' => 'me', 'enum' => ['me', 'all']]])
        ->and($routes[0])->not->toHaveKey('rate_limit');
});

it('reports the current user\'s month with both caps by default', function (): void {
    Functions\expect('current_user_can')->never();
    $res = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/usage'));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)
        ->and($res->get_data())->toBe(['tokens' => 1500, 'requests' => 1, 'month' => '2024-08', 'caps' => ['user' => 10000, 'site' => 0]]);
    expect($this->controller->show(restRequest('GET', '/alpaca-bot/v1/usage', ['user' => 'me']))->get_data()['tokens'])->toBe(1500);
});

it('reports the whole site for an administrator asking for all', function (): void {
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);
    $res = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/usage', ['user' => 'all']));
    expect($res->get_data())->toBe(['tokens' => 90000, 'requests' => 1, 'month' => '2024-08', 'caps' => ['user' => 10000, 'site' => 0]]);
});

it('refuses all to anyone else with a 403', function (): void {
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
    Functions\when('__')->returnArg();
    $res = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/usage', ['user' => 'all']));
    expect($res)->toBeInstanceOf(WP_Error::class)
        ->and($res->get_error_code())->toBe('rest_forbidden')
        ->and($res->get_error_data()['status'])->toBe(403);
});
