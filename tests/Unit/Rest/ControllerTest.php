<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use AlpacaBot\Rest\Controller;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PingController extends Controller
{
    public function routes(): array
    {
        return [['path' => '/ping', 'methods' => 'GET', 'callback' => fn() => ['pong' => true], 'capability' => 'edit_posts']];
    }
}

/** A chat-shaped route: rate limited, so its callback only runs while the caller's bucket has room. */
final class LimitedController extends Controller
{
    public function routes(): array
    {
        return [['path' => '/limited', 'methods' => 'POST', 'callback' => fn() => ['ok' => true], 'capability' => 'edit_posts', 'rate_limit' => true]];
    }
}

it('registers routes under the namespace with a permission callback', function (): void {
    Functions\expect('register_rest_route')->once()->withArgs(fn(string $ns, string $path, array $opts): bool => $ns === 'alpaca-bot/v1' && $path === '/ping' && $opts['methods'] === 'GET' && is_callable($opts['permission_callback']));
    (new PingController())->register();
});

it('permission applies the capability filter keyed by route and returns WP_Error when denied', function (): void {
    Filters\expectApplied('alpaca_bot/capability/ping')->once()->with('edit_posts', Mockery::type('WP_REST_Request'))->andReturn('manage_options');
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('__')->returnArg();
    $perm = (new PingController())->permission('ping', 'edit_posts');
    $res = $perm(new WP_REST_Request('GET', '/alpaca-bot/v1/ping'));
    expect($res)->toBeInstanceOf(WP_Error::class)->and($res->get_error_data()['status'])->toBe(403);
});

it('permission answers 401, not 403, to a visitor who is not logged in, and true when the capability holds', function (): void {
    Filters\expectApplied('alpaca_bot/capability/ping')->twice()->andReturn('edit_posts');
    Functions\when('current_user_can')->alias(static fn(string $cap): bool => $cap === 'edit_posts' && is_user_logged_in());
    Functions\when('is_user_logged_in')->justReturn(false);
    $perm = (new PingController())->permission('ping', 'edit_posts');
    $anonymous = $perm(new WP_REST_Request('GET', '/alpaca-bot/v1/ping'));
    expect($anonymous)->toBeInstanceOf(WP_Error::class)
        ->and($anonymous->get_error_code())->toBe('rest_forbidden')
        ->and($anonymous->get_error_data()['status'])->toBe(401);
    Functions\when('is_user_logged_in')->justReturn(true);
    expect($perm(new WP_REST_Request('GET', '/alpaca-bot/v1/ping')))->toBeTrue();
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
    (new LimitedController())->register();

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
    Functions\when('rest_convert_error_to_response')->alias(static fn(WP_Error $e): WP_REST_Response => new WP_REST_Response(['code' => $e->get_error_code(), 'message' => $e->get_error_message(), 'data' => $e->get_error_data()], (int) $e->get_error_data()['status']));

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

    // Task 1 ships no controllers: the seam starts empty and a third party (or a later task)
    // appends to it. Anything that is not a Controller is dropped rather than fatal on register().
    Filters\expectApplied('alpaca_bot/rest/controllers')->once()->with([])->andReturn([new PingController(), 'not-a-controller', new LimitedController()]);
    Functions\expect('register_rest_route')->once()->with('alpaca-bot/v1', '/ping', Mockery::type('array'));
    Functions\expect('register_rest_route')->once()->with('alpaca-bot/v1', '/limited', Mockery::type('array'));
    $onRestInit();
});
