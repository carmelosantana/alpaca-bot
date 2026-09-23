<?php

declare(strict_types=1);

use AlpacaBot\Access;
use AlpacaBot\Plugin;
use AlpacaBot\Rest\ChatController;
use AlpacaBot\Rest\Controller;
use AlpacaBot\Rest\ConversationsController;
use AlpacaBot\Rest\ModelsController;
use AlpacaBot\Rest\SettingsController;
use AlpacaBot\Rest\StreamController;
use AlpacaBot\Rest\UsageController;
use AlpacaBot\Rest\ViewController;
use AlpacaBot\Settings\Store;
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
    // register() also adds the two shortcodes, which Brain Monkey does not know as a hook function.
    Functions\when('add_shortcode')->justReturn();
    Plugin::boot()->register();
    expect($onRestInit)->toBeInstanceOf(Closure::class);

    // The plugin's own controllers (chat, stream, conversations, models, settings, usage, view) are what the filter is handed;
    // what it returns is what registers, so a third party can append to the list or replace it
    // outright. Anything that is not a Controller is dropped rather than fatal on register().
    Filters\expectApplied('alpaca_bot/rest/controllers')->once()->with(Mockery::on(static fn(array $own): bool => count($own) === 7
        && $own[0] instanceof ChatController
        && $own[1] instanceof StreamController
        && $own[2] instanceof ConversationsController
        && $own[3] instanceof ModelsController
        && $own[4] instanceof SettingsController
        && $own[5] instanceof UsageController
        && $own[6] instanceof ViewController))->andReturn([$this->ping, 'not-a-controller', $this->limited]);
    Functions\expect('register_rest_route')->once()->with('alpaca-bot/v1', '/ping', Mockery::type('array'));
    Functions\expect('register_rest_route')->once()->with('alpaca-bot/v1', '/limited', Mockery::type('array'));
    $onRestInit();
});

it('resolves a route declared CHAT to the stored Chat row, which is the default its filter receives', function (): void {
    $chat = restController([['path' => '/chat', 'methods' => 'POST', 'callback' => fn() => [], 'capability' => Controller::CHAT]]);
    $chat->useAccess(new Access(new Store(['access.chat' => 'read'])));
    Filters\expectApplied('alpaca_bot/capability/chat')->once()->with('read', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    Functions\expect('current_user_can')->once()->with('read')->andReturn(true);
    $perm = $chat->permission('chat', Controller::CHAT);
    expect($perm(new WP_REST_Request('POST', '/alpaca-bot/v1/chat')))->toBeTrue();
});

it('lets a route filter win over the Chat row, in either direction', function (): void {
    $chat = restController([['path' => '/chat', 'methods' => 'POST', 'callback' => fn() => [], 'capability' => Controller::CHAT]]);
    $chat->useAccess(new Access(new Store(['access.chat' => 'read'])));
    Filters\expectApplied('alpaca_bot/capability/chat')->once()->with('read', Mockery::type('WP_REST_Request'))->andReturn('manage_options');
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('__')->returnArg();
    $perm = $chat->permission('chat', Controller::CHAT);
    expect($perm(new WP_REST_Request('POST', '/alpaca-bot/v1/chat')))->toBeInstanceOf(WP_Error::class);
});

it('falls back to the Chat row\'s own default when nobody handed the controller an Access', function (): void {
    // A controller a site registered by hand, or a test: the token still has to mean something,
    // and what it means is the shipped default, never an unresolved literal.
    Filters\expectApplied('alpaca_bot/capability/chat')->once()->with('edit_posts', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    Functions\expect('current_user_can')->once()->with('edit_posts')->andReturn(true);
    $perm = restController([])->permission('chat', Controller::CHAT);
    expect($perm(new WP_REST_Request('POST', '/alpaca-bot/v1/chat')))->toBeTrue();
});

it('reads the settings once however many chat routes one request authorises', function (): void {
    Functions\expect('get_option')->once()->with(Plugin::OPTION, [])->andReturn(['access.chat' => 'publish_posts']);
    $controller = restController([]);
    $controller->useAccess(new Access(new Store()));
    Functions\when('current_user_can')->justReturn(true);
    foreach (['chat', 'chat/stream', 'conversations', 'view/bubble'] as $route) {
        Filters\expectApplied("alpaca_bot/capability/{$route}")->once()->with('publish_posts', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
        $perm = $controller->permission($route, Controller::CHAT);
        expect($perm(new WP_REST_Request('GET', '/alpaca-bot/v1/' . $route)))->toBeTrue();
    }
});

it('Plugin hands the container\'s Access to every controller the filter returns, a third party\'s included', function (): void {
    $onRestInit = null;
    Actions\expectAdded('rest_api_init')->once()->with(Mockery::on(static function (mixed $cb) use (&$onRestInit): bool {
        $onRestInit = $cb;
        return $cb instanceof Closure;
    }));
    Functions\when('add_shortcode')->justReturn();
    Functions\when('get_option')->justReturn(['access.chat' => 'read']);
    Plugin::boot()->register();
    $theirs = restController([['path' => '/theirs', 'methods' => 'GET', 'callback' => fn() => [], 'capability' => Controller::CHAT]]);
    Filters\expectApplied('alpaca_bot/rest/controllers')->once()->andReturn([$theirs]);
    $permission = null;
    Functions\expect('register_rest_route')->once()->withArgs(function (string $ns, string $path, array $opts) use (&$permission): bool {
        $permission = $opts['permission_callback'];
        return $path === '/theirs';
    });
    $onRestInit();

    Filters\expectApplied('alpaca_bot/capability/theirs')->once()->with('read', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    Functions\expect('current_user_can')->once()->with('read')->andReturn(true);
    expect($permission(new WP_REST_Request('GET', '/alpaca-bot/v1/theirs')))->toBeTrue();
});

it('reads no setting to register a CHAT route, so rest_api_init stays free of the option', function (): void {
    // Why a route declares a token rather than being handed a resolved capability: routes() is
    // called from register(), on rest_api_init, which fires for every REST request the site
    // serves, `/wp/v2/*` included. get_option() is deliberately not stubbed here — Brain Monkey
    // fails the test the moment anything reaches for it — so registering costs no read, and only
    // a request that reaches one of these routes pays for one.
    $chat = restController([['path' => '/chat', 'methods' => 'POST', 'callback' => fn() => [], 'capability' => Controller::CHAT]]);
    $chat->useAccess(new Access(new Store()));
    Functions\expect('register_rest_route')->once()->withArgs(fn(string $ns, string $path): bool => $path === '/chat');
    $chat->register();
});

it('lets a subclass resolve a row of its own, arguments and all, and hand the answer to the base', function (): void {
    // The seam the settings read/write split is dispatched against: an override of capability()
    // that resolves a row of its own and passes the answer down as the route's default, so the
    // route's filter still runs last over it. accessRow() is what makes that reachable — the
    // override never sees the null, and never builds a second Access over the same Store.
    $controller = new class extends Controller {
        public function routes(): array
        {
            return [['path' => '/rows', 'methods' => 'GET', 'callback' => fn() => [], 'capability' => 'manage_options']];
        }

        protected function capability(string $route, string $declared, \WP_REST_Request $request): string
        {
            return parent::capability($route, $this->accessRow('settings.read', $request), $request);
        }
    };
    $controller->useAccess(new Access(new Store(['access.settings.read' => 'edit_others_posts'])));
    // The row's own filter runs first, with the argument that row fires with...
    Filters\expectApplied('alpaca_bot/capability/settings/read')->once()->with('edit_others_posts', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    // ...and the route's filter last, over whatever the row resolved to.
    Filters\expectApplied('alpaca_bot/capability/rows')->once()->with('edit_others_posts', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    Functions\expect('current_user_can')->once()->with('edit_others_posts')->andReturn(true);
    $perm = $controller->permission('rows', 'manage_options');
    expect($perm(new WP_REST_Request('GET', '/alpaca-bot/v1/rows')))->toBeTrue();
});

it('resolves a subclass\'s row to its shipped default, and fires no row filter, with no Access in hand', function (): void {
    // The same override with nobody having called useAccess(): the row still means something,
    // and what it means is Access::defaults() — `manage_options` for settings.read. The row's
    // own filter cannot run, because there is no stored row for it to be handed.
    $controller = new class extends Controller {
        public function routes(): array
        {
            return [];
        }

        protected function capability(string $route, string $declared, \WP_REST_Request $request): string
        {
            return parent::capability($route, $this->accessRow('settings.read', $request), $request);
        }
    };
    Filters\expectApplied('alpaca_bot/capability/settings/read')->never();
    Filters\expectApplied('alpaca_bot/capability/rows')->once()->with('manage_options', Mockery::type('WP_REST_Request'))->andReturnFirstArg();
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);
    $perm = $controller->permission('rows', 'manage_options');
    expect($perm(new WP_REST_Request('GET', '/alpaca-bot/v1/rows')))->toBeTrue();
});

// Controller is the class docs/api.md invites a site to extend, and a method added to it can clash
// with one a subclass already declares. Probed on PHP 8.4 against fe88268, which had a public
// static filteredCapability(): a subclass declaring it non-static, or protected, did not load (a
// fatal when the subclass is declared). The route filter the Access tab also asks lives on a
// final class of its own for that reason, and this pins Controller's methods to the ones it had
// before this task (6ac6fd3), as reflection reads them.
it('keeps the surface a subclass inherits to the methods it had, and applies the route filter from a final class', function (): void {
    $surface = [];
    foreach ((new ReflectionClass(Controller::class))->getMethods() as $method) {
        $surface[$method->getName()] = implode(' ', Reflection::getModifierNames($method->getModifiers()));
    }
    ksort($surface);
    expect($surface)->toBe([
        'accessRow' => 'protected',
        'capability' => 'protected',
        'permission' => 'public',
        'rateLimited' => 'private',
        'register' => 'public',
        'routeKey' => 'public static',
        'routes' => 'abstract public',
        'useAccess' => 'public',
        'userId' => 'protected',
    ]);
    expect((new ReflectionClass(AlpacaBot\Rest\RouteCapability::class))->isFinal())->toBeTrue();
});
