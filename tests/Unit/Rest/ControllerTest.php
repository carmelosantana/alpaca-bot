<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use AlpacaBot\Rest\ChatController;
use AlpacaBot\Rest\Controller;
use AlpacaBot\Rest\ConversationsController;
use AlpacaBot\Rest\StreamController;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// Fixtures hang off the test case rather than being named classes or functions: anything
// declared at the top of this file is a global shared with every other test file in the run.
beforeEach(function (): void {
    // The plainest route there is: GET, editors only, no limiter.
    $this->ping = restController([['path' => '/ping', 'methods' => 'GET', 'callback' => fn() => ['pong' => true], 'capability' => 'edit_posts']]);
    // A chat-shaped route: rate limited, so its callback only runs while the caller's bucket has room.
    $this->limited = restController([['path' => '/limited', 'methods' => 'POST', 'callback' => fn() => ['ok' => true], 'capability' => 'edit_posts', 'rate_limit' => true]]);
});

it('registers routes under the namespace with a permission callback', function (): void {
    Functions\expect('register_rest_route')->once()->withArgs(fn(string $ns, string $path, array $opts): bool => $ns === 'alpaca-bot/v1' && $path === '/ping' && $opts['methods'] === 'GET' && is_callable($opts['permission_callback']));
    $this->ping->register();
});

it('permission applies the capability filter keyed by route and returns WP_Error when denied', function (): void {
    Filters\expectApplied('alpaca_bot/capability/ping')->once()->with('edit_posts', Mockery::type('WP_REST_Request'))->andReturn('manage_options');
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('__')->returnArg();
    $perm = $this->ping->permission('ping', 'edit_posts');
    $res = $perm(new WP_REST_Request('GET', '/alpaca-bot/v1/ping'));
    expect($res)->toBeInstanceOf(WP_Error::class)->and($res->get_error_data()['status'])->toBe(403);
});

it('permission answers 401, not 403, to a visitor who is not logged in, and true when the capability holds', function (): void {
    Filters\expectApplied('alpaca_bot/capability/ping')->twice()->andReturn('edit_posts');
    Functions\when('current_user_can')->alias(static fn(string $cap): bool => $cap === 'edit_posts' && is_user_logged_in());
    Functions\when('is_user_logged_in')->justReturn(false);
    $perm = $this->ping->permission('ping', 'edit_posts');
    $anonymous = $perm(new WP_REST_Request('GET', '/alpaca-bot/v1/ping'));
    expect($anonymous)->toBeInstanceOf(WP_Error::class)
        ->and($anonymous->get_error_code())->toBe('rest_forbidden')
        ->and($anonymous->get_error_data()['status'])->toBe(401);
    Functions\when('is_user_logged_in')->justReturn(true);
    expect($perm(new WP_REST_Request('GET', '/alpaca-bot/v1/ping')))->toBeTrue();
});

it('register hands core a permission callback keyed by the route key, not the raw path', function (): void {
    // A regex path, so the filter key and the path differ: a permission callback built from
    // the raw path would fire `alpaca_bot/capability//conversations/(?P<id>\d+)`.
    $permission = null;
    Functions\expect('register_rest_route')->once()->withArgs(function (string $ns, string $path, array $opts) use (&$permission): bool {
        $permission = $opts['permission_callback'];
        return $path === '/conversations/(?P<id>\d+)';
    });
    restController([['path' => '/conversations/(?P<id>\d+)', 'methods' => 'GET', 'callback' => fn() => [], 'capability' => 'edit_posts']])->register();

    Filters\expectApplied('alpaca_bot/capability/conversations')->once()->with('edit_posts', Mockery::type('WP_REST_Request'))->andReturn('edit_posts');
    Functions\expect('current_user_can')->once()->with('edit_posts')->andReturn(true);
    expect($permission(new WP_REST_Request('GET', '/alpaca-bot/v1/conversations/7')))->toBeTrue();
});

it('permission ignores a filter that returns anything but a non-numeric capability name and checks the declared one', function (): void {
    // WP_User::has_cap() turns a numeric capability into level_N, and level_0/level_1 are held
    // by Subscribers and Contributors: a filter returning true (`(string) true` is '1') would
    // quietly open the route to every Contributor. Anything that is not a capability name is
    // treated as "no opinion" and the route's own capability is what gets checked.
    Functions\when('is_user_logged_in')->justReturn(true);
    foreach ([true, false, '1', 0, 7, '', null, ['manage_options']] as $bad) {
        Filters\expectApplied('alpaca_bot/capability/settings')->once()->andReturn($bad);
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);
        $perm = $this->ping->permission('settings', 'manage_options');
        expect($perm(new WP_REST_Request('GET', '/alpaca-bot/v1/settings')))->toBeTrue();
    }
});

it('derives the capability filter key from the path with the leading slash and regex groups dropped', function (): void {
    expect(Controller::routeKey('/chat'))->toBe('chat')
        ->and(Controller::routeKey('/chat/(?P<id>\d+)/stream'))->toBe('chat/stream')
        ->and(Controller::routeKey('/conversations/(?P<id>\d+)'))->toBe('conversations')
        ->and(Controller::routeKey('/conversations'))->toBe('conversations')
        ->and(Controller::routeKey('/settings'))->toBe('settings');
});

it('wraps a rate-limited route so an exhausted bucket answers 429 with Retry-After and the callback never runs', function (): void {
    $callback = null;
    Functions\expect('register_rest_route')->once()->withArgs(function (string $ns, string $path, array $opts) use (&$callback): bool {
        $callback = $opts['callback'];
        return $path === '/limited' && $opts['methods'] === 'POST';
    });
    $this->limited->register();

    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('current_time')->justReturn(1_725_000_030);
    Filters\expectApplied('alpaca_bot/rate_limit')->twice()->with(30, 3, 'chat')->andReturn(1);
    $count = 0;
    Functions\when('get_transient')->alias(function () use (&$count) {
        return $count ?: false;
    });
    Functions\when('set_transient')->alias(function (string $k, int $v) use (&$count): bool {
        $count = $v;
        return true;
    });
    // Core turns a WP_Error into the {code, message, data} JSON body; the wrapper goes through
    // it so the 429 can also carry the Retry-After header a WP_Error has nowhere to put.
    restConvertsErrors();

    $request = new WP_REST_Request('POST', '/alpaca-bot/v1/limited');
    expect($callback($request))->toBe(['ok' => true]);
    $blocked = $callback($request);
    expect($blocked)->toBeInstanceOf(WP_REST_Response::class)
        ->and($blocked->get_status())->toBe(429)
        ->and($blocked->get_headers())->toBe(['Retry-After' => '30'])
        ->and($blocked->get_data()['code'])->toBe('alpaca_bot_rate_limited')
        ->and($blocked->get_data()['data'])->toMatchArray(['status' => 429, 'retry_after' => 30]);
});

it('Plugin registers every controller the alpaca_bot/rest/controllers filter hands back, on rest_api_init', function (): void {
    $onRestInit = null;
    Actions\expectAdded('rest_api_init')->once()->with(Mockery::on(static function (mixed $cb) use (&$onRestInit): bool {
        $onRestInit = $cb;
        return $cb instanceof Closure;
    }));
    Plugin::boot()->register();
    expect($onRestInit)->toBeInstanceOf(Closure::class);

    // The plugin's own controllers (chat, stream, conversations) are what the filter is handed;
    // what it returns is what registers, so a third party can append to the list or replace it
    // outright. Anything that is not a Controller is dropped rather than fatal on register().
    Filters\expectApplied('alpaca_bot/rest/controllers')->once()->with(Mockery::on(static fn(array $own): bool => count($own) === 3
        && $own[0] instanceof ChatController
        && $own[1] instanceof StreamController
        && $own[2] instanceof ConversationsController))->andReturn([$this->ping, 'not-a-controller', $this->limited]);
    Functions\expect('register_rest_route')->once()->with('alpaca-bot/v1', '/ping', Mockery::type('array'));
    Functions\expect('register_rest_route')->once()->with('alpaca-bot/v1', '/limited', Mockery::type('array'));
    $onRestInit();
});
