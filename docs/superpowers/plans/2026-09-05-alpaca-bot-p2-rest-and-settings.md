# Alpaca Bot 1.0 P2: REST API and Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the P1 pipeline as one REST namespace `alpaca-bot/v1` (JSON resources plus a streaming SSE route), with capability filters, rate limits, Application Password access, an integration test suite that runs inside the harness site, and a Settings API admin page.

**Architecture:** `Rest\Controller` is the base for every route: it registers routes under `alpaca-bot/v1`, resolves capabilities through `alpaca_bot/capability/{route}` filters, and applies `Rest\RateLimit`. WordPress cookie auth + `X-WP-Nonce` covers admin; Application Passwords cover external clients; no custom nonce code. Streaming uses a `rest_pre_serve_request` hook so the route callback can write SSE frames straight to the client. Settings use `register_setting` with the P1 `Schema` as the sanitize callback and core field markup, rendered under the Alpaca Bot menu.

**Tech Stack:** PHP 8.4, WP REST API, WP Settings API, Pest 5 + Brain\Monkey (unit), `wp-phpunit/wp-phpunit ^7.1` + `yoast/phpunit-polyfills ^4` (integration, run inside `alpacabot.wp.test`'s `cli` container against a `wordpress_tests` database), curl for real checks.

**Spec:** `docs/superpowers/specs/2026-09-05-alpaca-bot-1-0-core-refactor.md` (sequencing steps 3-4). Decisions: Kanboard #2943 (API), #2948 (options), #2951 (tests).

> **Corrections from P1 execution (2026-09-05):** (1) `UsageMeter::record()` now ALWAYS writes a `chat_log` row so caps work when `privacy.usage_log` is off; the row then holds only numbers (tokens, duration, model, author), never content. The Privacy tab copy in Task 6 must say so, and Task 6 gains a `privacy.usage_retention_days` integer field (default 90, 0 = keep) with a daily `wp_schedule_event` cleanup in `UsageMeter`, plus a unit test. (2) `ConversationStore::listFor()` reads `post_status IN (private, publish)` because unmigrated 0.4 rows are `publish`; the P2 conversations route inherits that. (3) Hook order is `before_send` → `system_prompt`; the `Pipeline` class docblock is the truth, not P1's summary list.

## Global Constraints

- All P1 constraints hold (PHP 8.4, prefixed vendor, `alpaca_bot/*` hooks, branch `1.0`, `composer check` green per task).
- One namespace: `alpaca-bot/v1`. Route list (final): `POST /chat`, `GET /chat/{conversation}/stream`, `GET|DELETE /conversations`, `GET|DELETE /conversations/{id}`, `GET /models`, `GET|PUT /settings`, `GET /usage`. `/view/*` fragment routes are P3.
- Capabilities (defaults, each filterable via `alpaca_bot/capability/{route}`): chat/stream/conversations/models/usage → `edit_posts`; settings → `manage_options`. Filter signature: `(string $capability, \WP_REST_Request $request)`.
- Rate limit default: 30 chat requests per user per minute (transient), filter `alpaca_bot/rate_limit` `(int $perMinute, int $userId)`; 429 with `Retry-After`.
- No legacy routes remain registered: the old `Api\Htmx` is not booted (P1) and its file is deleted in P3.
- Integration tests: `composer test:integration` runs `bin/test-integration.sh`, which executes phpunit inside the harness site container. CI wiring is P5.

---

### Task 1: REST base controller, capability filters, rate limiter

**Files:**
- Create: `src/Rest/Controller.php`, `src/Rest/RateLimit.php`, `src/Rest/Errors.php`, `tests/Unit/Rest/ControllerTest.php`, `tests/Unit/Rest/RateLimitTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Produces:
  ```php
  namespace AlpacaBot\Rest;
  abstract class Controller {
      public const NAMESPACE = 'alpaca-bot/v1';
      abstract public function routes(): array;   // [ ['path' => '/chat', 'methods' => 'POST', 'callback' => [$this,'create'], 'capability' => 'edit_posts', 'args' => [...]], ... ]
      public function register(): void;           // register_rest_route for each; wraps permission + rate limit
      public function permission(string $route, string $capability): \Closure;   // returns fn(WP_REST_Request): true|WP_Error, applying alpaca_bot/capability/{route-key}
      protected function userId(): int;
  }
  final class RateLimit {
      public function __construct(private int $perMinute = 30) {}
      /** @return array{allowed:bool, remaining:int, retry_after:int} */ public function hit(int $userId, string $bucket = 'chat'): array;   // transient alpaca_bot_rl_{bucket}_{user}_{YmdHi}
  }
  final class Errors {
      public static function forbidden(string $message = ''): \WP_Error;         // rest_forbidden, 403 (401 when logged out)
      public static function tooMany(int $retryAfter): \WP_Error;                 // alpaca_bot_rate_limited, 429, data.retry_after
      public static function capExceeded(\AlpacaBot\Chat\CapExceeded $e): \WP_Error;   // alpaca_bot_cap_exceeded, 402, data.scope/limit/used
      public static function notFound(string $what): \WP_Error;                   // 404
      public static function provider(\Throwable $e): \WP_Error;                  // alpaca_bot_provider_error, 502
  }
  ```
- Route key for the capability filter: path with leading slash removed and regex groups dropped, e.g. `chat`, `chat/stream`, `conversations`, `settings`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Rest/RateLimitTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Rest\RateLimit;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('counts hits per user per minute and blocks past the limit', function (): void {
    Functions\when('current_time')->justReturn(1_725_000_000);
    Filters\expectApplied('alpaca_bot/rate_limit')->times(3)->andReturn(2);
    $count = 0;
    Functions\when('get_transient')->alias(function () use (&$count) { return $count ?: false; });
    Functions\when('set_transient')->alias(function (string $k, int $v) use (&$count): bool { $count = $v; return true; });
    $rl = new RateLimit(30);
    expect($rl->hit(3)['allowed'])->toBeTrue()->and($rl->hit(3))->toMatchArray(['allowed' => true, 'remaining' => 0]);
    $third = $rl->hit(3);
    expect($third['allowed'])->toBeFalse()->and($third['retry_after'])->toBeGreaterThan(0)->and($third['retry_after'])->toBeLessThanOrEqual(60);
});
```

`tests/Unit/Rest/ControllerTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Rest\Controller;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PingController extends Controller
{
    public function routes(): array
    {
        return [['path' => '/ping', 'methods' => 'GET', 'callback' => fn() => ['pong' => true], 'capability' => 'edit_posts']];
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
```
`WP_REST_Request` and `WP_Error` need minimal stubs for unit tests: create `tests/Stubs/wp-rest.php` with `class WP_Error { public function __construct(public string $code = '', public string $message = '', public mixed $data = null) {} public function get_error_data(): mixed { return $this->data; } public function get_error_code(): string { return $this->code; } }` and `class WP_REST_Request { public function __construct(public string $method = 'GET', public string $route = '', private array $params = []) {} public function get_param(string $k): mixed { return $this->params[$k] ?? null; } public function get_params(): array { return $this->params; } public function get_json_params(): array { return $this->params; } public function set_param(string $k, mixed $v): void { $this->params[$k] = $v; } }` and `class WP_REST_Response { public function __construct(public mixed $data = null, public int $status = 200, public array $headers = []) {} public function get_data(): mixed { return $this->data; } public function get_status(): int { return $this->status; } public function header(string $k, string $v): void { $this->headers[$k] = $v; } }`; require it from `tests/Pest.php` when the classes do not exist.

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing classes.

- [ ] **Step 3: Implement**

`src/Rest/RateLimit.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

final class RateLimit
{
    public function __construct(private int $perMinute = 30) {}

    public function hit(int $userId, string $bucket = 'chat'): array
    {
        $now = (int) current_time('timestamp', true);
        $limit = max(1, (int) apply_filters('alpaca_bot/rate_limit', $this->perMinute, $userId, $bucket));
        $key = sprintf('alpaca_bot_rl_%s_%d_%s', $bucket, $userId, gmdate('YmdHi', $now));
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, 120);
        $allowed = $count <= $limit;
        return ['allowed' => $allowed, 'remaining' => max(0, $limit - $count), 'retry_after' => $allowed ? 0 : 60 - ($now % 60)];
    }
}
```

`src/Rest/Errors.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\CapExceeded;

final class Errors
{
    public static function forbidden(string $message = ''): \WP_Error
    {
        return new \WP_Error('rest_forbidden', $message !== '' ? $message : __('You are not allowed to do that.', 'alpaca-bot'), ['status' => is_user_logged_in() ? 403 : 401]);
    }

    public static function tooMany(int $retryAfter): \WP_Error
    {
        return new \WP_Error('alpaca_bot_rate_limited', __('Too many requests. Try again shortly.', 'alpaca-bot'), ['status' => 429, 'retry_after' => $retryAfter]);
    }

    public static function capExceeded(CapExceeded $e): \WP_Error
    {
        return new \WP_Error('alpaca_bot_cap_exceeded', $e->getMessage(), ['status' => 402, 'scope' => $e->scope, 'limit' => $e->limit, 'used' => $e->used]);
    }

    public static function notFound(string $what): \WP_Error
    {
        return new \WP_Error('alpaca_bot_not_found', sprintf(__('%s not found.', 'alpaca-bot'), $what), ['status' => 404]);
    }

    public static function provider(\Throwable $e): \WP_Error
    {
        return new \WP_Error('alpaca_bot_provider_error', $e->getMessage(), ['status' => 502]);
    }
}
```

`src/Rest/Controller.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

abstract class Controller
{
    public const NAMESPACE = 'alpaca-bot/v1';

    /** @return array<int, array{path:string, methods:string, callback:callable, capability:string, args?:array, rate_limit?:bool}> */
    abstract public function routes(): array;

    public function register(): void
    {
        foreach ($this->routes() as $r) {
            $key = self::routeKey($r['path']);
            $callback = $r['callback'];
            if (!empty($r['rate_limit'])) {
                $callback = function (\WP_REST_Request $request) use ($callback): mixed {
                    $hit = (new RateLimit())->hit($this->userId(), 'chat');
                    if (!$hit['allowed']) {
                        return Errors::tooMany($hit['retry_after']);
                    }
                    return $callback($request);
                };
            }
            register_rest_route(self::NAMESPACE, $r['path'], ['methods' => $r['methods'], 'callback' => $callback, 'permission_callback' => $this->permission($key, $r['capability']), 'args' => $r['args'] ?? []]);
        }
    }

    public function permission(string $route, string $capability): \Closure
    {
        return static function (\WP_REST_Request $request) use ($route, $capability): bool|\WP_Error {
            $cap = (string) apply_filters("alpaca_bot/capability/{$route}", $capability, $request);
            return current_user_can($cap) ? true : Errors::forbidden();
        };
    }

    public static function routeKey(string $path): string
    {
        return trim((string) preg_replace('#/\(\?P<[^)]+\)[^/]*#', '', $path), '/');
    }

    protected function userId(): int
    {
        return (int) get_current_user_id();
    }
}
```

`Plugin::register()`: `add_action('rest_api_init', function (): void { foreach ($this->controllers() as $c) { $c->register(); } });` with `private function controllers(): array { return apply_filters('alpaca_bot/rest/controllers', []); }` — Task 2 onwards appends controllers via `set()` and this array.

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: REST base controller with capability filters, rate limit, and error helpers"
```

---

### Task 2: Integration test harness (wp-phpunit inside the site container)

**Files:**
- Create: `bin/test-integration.sh`, `tests/Integration/bootstrap.php`, `tests/Integration/wp-tests-config.php`, `tests/Integration/SmokeTest.php`, `phpunit.integration.xml`
- Modify: `composer.json` (require-dev `wp-phpunit/wp-phpunit ^7.1`, `yoast/phpunit-polyfills ^4.0`; script `test:integration`)

**Interfaces:**
- Produces: `composer test:integration` (runs against `alpacabot.wp.test`'s DB host `db`, database `wordpress_tests`, created on first run), base class `AlpacaBot\Tests\Integration\TestCase extends WP_UnitTestCase` with `protected function asAdmin(): int`, `protected function rest(string $method, string $path, array $body = []): WP_REST_Response`.

- [ ] **Step 1: Write the failing smoke test**

`tests/Integration/SmokeTest.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

final class SmokeTest extends TestCase
{
    public function test_plugin_is_loaded_and_routes_exist(): void
    {
        $this->assertTrue(class_exists(\AlpacaBot\Plugin::class));
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/alpaca-bot/v1', $routes);
    }
}
```

- [ ] **Step 2: Add the harness**

`tests/Integration/TestCase.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

abstract class TestCase extends \WP_UnitTestCase
{
    protected function asAdmin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        return $id;
    }

    protected function rest(string $method, string $path, array $body = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/alpaca-bot/v1' . $path);
        if ($body !== []) {
            $request->set_body_params($body);
        }
        return rest_get_server()->dispatch($request);
    }
}
```

`tests/Integration/bootstrap.php`:
```php
<?php

declare(strict_types=1);

$tests = getenv('WP_TESTS_DIR') ?: dirname(__DIR__, 2) . '/vendor/wp-phpunit/wp-phpunit';
putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
require_once dirname(__DIR__, 2) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $tests . '/includes/functions.php';
tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/alpaca-bot.php';
});
require $tests . '/includes/bootstrap.php';
```

`tests/Integration/wp-tests-config.php`:
```php
<?php
define('ABSPATH', '/var/www/html/');
define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_tests');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'wordpress');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'wordpress');
define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wptests_';
define('WP_TESTS_DOMAIN', 'alpacabot.wp.test');
define('WP_TESTS_EMAIL', 'admin@alpacabot.wp.test');
define('WP_TESTS_TITLE', 'Alpaca Bot tests');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
```

`phpunit.integration.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/Integration/bootstrap.php" colors="true" cacheDirectory=".phpunit.cache/integration">
    <testsuites><testsuite name="Integration"><directory>tests/Integration</directory></testsuite></testsuites>
</phpunit>
```

`bin/test-integration.sh`:
```bash
#!/usr/bin/env bash
# Run the WP integration suite inside the harness site's cli container (DB host "db").
set -euo pipefail
SITE="${WPH_SITE:-alpacabot}"
COMPOSE="$HOME/Sites/$SITE/.harness/compose.yml"
PLUGIN=/var/www/html/wp-content/plugins/alpaca-bot
docker compose -f "$COMPOSE" exec -T db sh -c 'mariadb -uroot -proot -e "CREATE DATABASE IF NOT EXISTS wordpress_tests; GRANT ALL ON wordpress_tests.* TO wordpress@\"%\";"'
docker compose -f "$COMPOSE" run --rm -T -w "$PLUGIN" -e WP_TESTS_DB_NAME=wordpress_tests cli php vendor/bin/phpunit -c phpunit.integration.xml "$@"
```
`chmod +x bin/test-integration.sh`; composer script `"test:integration": "bash bin/test-integration.sh"`.

```bash
composer require --dev wp-phpunit/wp-phpunit:^7.1 yoast/phpunit-polyfills:^4.0
```

- [ ] **Step 3: Run and verify**

Run: `composer test:integration`
Expected: `OK (1 test, 2 assertions)`. If the `cli` image lacks `mariadb` client tooling for the DB creation step, the `exec -T db` form above runs inside the db container, which has it.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "test: WordPress integration suite running inside the harness site container"
```

---

### Task 3: Chat and conversations routes (JSON)

**Files:**
- Create: `src/Rest/ChatController.php`, `src/Rest/ConversationsController.php`, `tests/Unit/Rest/ChatControllerTest.php`, `tests/Integration/ChatRoutesTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- `POST /chat` body `{message: string, conversation_id?: int, model?: string, images?: string[], context?: {screen?: string, post_id?: int}, stream?: bool}`; response 200 `{conversation_id, message: MessageArray, receipt: Receipt, contexts: ContextArray[]}` when `stream` is false; when `stream` is true, response 202 `{conversation_id, stream_url, token}` where `token` is a one-time transient (`alpaca_bot_stream_{token}` = `{user_id, conversation_id, message, options}`, 120 s) consumed by Task 4.
- `GET /conversations?limit=20` → `[{id, title, created}]`; `GET /conversations/{id}` → `{id, title, created, mode, messages: MessageArray[]}`; `DELETE /conversations/{id}` → `{deleted: true}`; `DELETE /conversations` → `{deleted: n}` (all of the current user's).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Rest/ChatControllerTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\Result;
use AlpacaBot\Rest\ChatController;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('__')->returnArg();
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('rest_url')->alias(fn(string $p) => 'https://alpacabot.wp.test/wp-json/' . $p);
    Functions\when('wp_generate_password')->justReturn('tok');
});

it('completes a chat and returns conversation, message, receipt', function (): void {
    $pipeline = Mockery::mock(Pipeline::class);
    $pipeline->shouldReceive('complete')->once()->with(3, 'hi', Mockery::on(fn(array $o): bool => $o['conversation_id'] === 0 && $o['model'] === '' && $o['context'] === ['post_id' => 9]))
        ->andReturn(new Result(new Conversation(5, 3, 'T'), new Message('assistant', 'yo', 'm'), ['total_tokens' => 1], []));
    $res = (new ChatController($pipeline))->create(new WP_REST_Request('POST', '/alpaca-bot/v1/chat', ['message' => 'hi', 'context' => ['post_id' => 9]]));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)->and($res->get_status())->toBe(200)->and($res->get_data()['conversation_id'])->toBe(5)->and($res->get_data()['message']['content'])->toBe('yo');
});

it('returns a stream ticket when stream=true', function (): void {
    Functions\expect('set_transient')->once()->withArgs(fn(string $k, array $v, int $ttl): bool => $k === 'alpaca_bot_stream_tok' && $v['user_id'] === 3 && $v['message'] === 'hi' && $ttl === 120);
    $pipeline = Mockery::mock(Pipeline::class);
    $pipeline->shouldNotReceive('complete');
    $res = (new ChatController($pipeline))->create(new WP_REST_Request('POST', '/alpaca-bot/v1/chat', ['message' => 'hi', 'stream' => true, 'conversation_id' => 5]));
    expect($res->get_status())->toBe(202)->and($res->get_data()['stream_url'])->toBe('https://alpacabot.wp.test/wp-json/alpaca-bot/v1/chat/5/stream?token=tok');
});

it('maps CapExceeded to a 402 WP_Error and rejects empty messages', function (): void {
    $pipeline = Mockery::mock(Pipeline::class);
    $pipeline->shouldReceive('complete')->andThrow(new CapExceeded('user', 10, 11));
    $c = new ChatController($pipeline);
    $err = $c->create(new WP_REST_Request('POST', '/x', ['message' => 'hi']));
    expect($err)->toBeInstanceOf(WP_Error::class)->and($err->get_error_data()['status'])->toBe(402);
    $bad = $c->create(new WP_REST_Request('POST', '/x', ['message' => '   ']));
    expect($bad)->toBeInstanceOf(WP_Error::class)->and($bad->get_error_data()['status'])->toBe(400);
});
```

`tests/Integration/ChatRoutesTest.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;

final class ChatRoutesTest extends TestCase
{
    private function fakeProvider(): void
    {
        add_filter('alpaca_bot/provider', static fn() => new class implements ProviderInterface {
            public function chat(array $m, array $t = [], array $o = []): Response { return new Response('fake reply', usage: new Usage(3, 2, 5)); }
            public function stream(array $m, array $t = [], array $o = []): iterable { yield new Response('fake '); yield new Response('reply', usage: new Usage(3, 2, 5)); }
            public function structured(array $m, string $s, array $o = []): mixed { return []; }
            public function models(): array { return ['fake-model']; }
            public function isAvailable(): bool { return true; }
            public function getModel(): string { return 'fake-model'; }
            public function withModel(string $model): static { return $this; }
        });
        update_option('alpaca_bot_settings', ['models.default' => 'fake-model']);
    }

    public function test_chat_requires_login(): void
    {
        $res = $this->rest('POST', '/chat', ['message' => 'hi']);
        $this->assertSame(401, $res->get_status());
    }

    public function test_chat_creates_a_private_conversation_and_lists_it(): void
    {
        $this->fakeProvider();
        $this->asAdmin();
        $res = $this->rest('POST', '/chat', ['message' => 'hello there']);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $data = $res->get_data();
        $this->assertSame('fake reply', $data['message']['content']);
        $this->assertSame(5, $data['receipt']['total_tokens']);
        $list = $this->rest('GET', '/conversations')->get_data();
        $this->assertSame($data['conversation_id'], $list[0]['id']);
        $one = $this->rest('GET', '/conversations/' . $data['conversation_id'])->get_data();
        $this->assertCount(2, $one['messages']);
        $this->assertSame('private', get_post_status($data['conversation_id']));
        $this->assertTrue($this->rest('DELETE', '/conversations/' . $data['conversation_id'])->get_data()['deleted']);
    }

    public function test_other_users_cannot_read_a_conversation(): void
    {
        $this->fakeProvider();
        $this->asAdmin();
        $id = $this->rest('POST', '/chat', ['message' => 'secret'])->get_data()['conversation_id'];
        $this->asAdmin();
        $this->assertSame(404, $this->rest('GET', '/conversations/' . $id)->get_status());
    }

    public function test_rate_limit_returns_429(): void
    {
        $this->fakeProvider();
        $this->asAdmin();
        add_filter('alpaca_bot/rate_limit', static fn() => 1);
        $this->rest('POST', '/chat', ['message' => 'one']);
        $this->assertSame(429, $this->rest('POST', '/chat', ['message' => 'two'])->get_status());
    }
}
```

- [ ] **Step 2: Run to verify failure** — `composer test` and `composer test:integration` FAIL on missing classes.

- [ ] **Step 3: Implement**

`src/Rest/ChatController.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Context\Context;

final class ChatController extends Controller
{
    public const STREAM_TTL = 120;

    public function __construct(private Pipeline $pipeline) {}

    public function routes(): array
    {
        return [[
            'path' => '/chat',
            'methods' => 'POST',
            'callback' => [$this, 'create'],
            'capability' => 'edit_posts',
            'rate_limit' => true,
            'args' => [
                'message' => ['type' => 'string', 'required' => true],
                'conversation_id' => ['type' => 'integer', 'default' => 0],
                'model' => ['type' => 'string', 'default' => ''],
                'images' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                'context' => ['type' => 'object', 'default' => []],
                'stream' => ['type' => 'boolean', 'default' => false],
            ],
        ]];
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $message = trim((string) $request->get_param('message'));
        if ($message === '') {
            return new \WP_Error('alpaca_bot_empty_message', __('Message is empty.', 'alpaca-bot'), ['status' => 400]);
        }
        $options = [
            'conversation_id' => (int) $request->get_param('conversation_id'),
            'model' => sanitize_text_field((string) $request->get_param('model')),
            'images' => array_values(array_filter((array) $request->get_param('images'), 'is_string')),
            'context' => (array) $request->get_param('context'),
        ];
        if ((bool) $request->get_param('stream')) {
            $token = wp_generate_password(32, false);
            set_transient('alpaca_bot_stream_' . $token, ['user_id' => $this->userId(), 'message' => $message, 'options' => $options], self::STREAM_TTL);
            $cid = $options['conversation_id'];
            return new \WP_REST_Response(['conversation_id' => $cid, 'token' => $token, 'stream_url' => rest_url(self::NAMESPACE . "/chat/{$cid}/stream?token={$token}")], 202);
        }
        try {
            $r = $this->pipeline->complete($this->userId(), $message, $options);
        } catch (CapExceeded $e) {
            return Errors::capExceeded($e);
        } catch (\Throwable $e) {
            return Errors::provider($e);
        }
        return new \WP_REST_Response(['conversation_id' => $r->conversation->id, 'message' => $r->reply->toArray(), 'receipt' => $r->receipt, 'contexts' => array_map(static fn(Context $c): array => $c->toArray(), $r->contexts)], 200);
    }
}
```

`src/Rest/ConversationsController.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Settings\Store;

final class ConversationsController extends Controller
{
    public function __construct(private ConversationStore $conversations, private Store $store) {}

    public function routes(): array
    {
        return [
            ['path' => '/conversations', 'methods' => 'GET', 'callback' => [$this, 'index'], 'capability' => 'edit_posts', 'args' => ['limit' => ['type' => 'integer', 'default' => 0]]],
            ['path' => '/conversations', 'methods' => 'DELETE', 'callback' => [$this, 'destroyAll'], 'capability' => 'edit_posts'],
            ['path' => '/conversations/(?P<id>\d+)', 'methods' => 'GET', 'callback' => [$this, 'show'], 'capability' => 'edit_posts'],
            ['path' => '/conversations/(?P<id>\d+)', 'methods' => 'DELETE', 'callback' => [$this, 'destroy'], 'capability' => 'edit_posts'],
        ];
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $limit = (int) $request->get_param('limit') ?: (int) $this->store->get('chat.history_limit');
        return new \WP_REST_Response($this->conversations->listFor($this->userId(), max(1, min(200, $limit))));
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $c = $this->conversations->load((int) $request->get_param('id'), $this->userId());
        if ($c === null) {
            return Errors::notFound(__('Conversation', 'alpaca-bot'));
        }
        return new \WP_REST_Response(['id' => $c->id, 'title' => $c->title, 'created' => $c->created, 'mode' => $c->mode, 'messages' => array_map(static fn(Message $m): array => $m->toArray(), $c->messages)]);
    }

    public function destroy(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->conversations->delete((int) $request->get_param('id'), $this->userId())
            ? new \WP_REST_Response(['deleted' => true])
            : Errors::notFound(__('Conversation', 'alpaca-bot'));
    }

    public function destroyAll(\WP_REST_Request $request): \WP_REST_Response
    {
        $n = 0;
        foreach ($this->conversations->listFor($this->userId(), 10000) as $row) {
            $n += $this->conversations->delete($row['id'], $this->userId()) ? 1 : 0;
        }
        return new \WP_REST_Response(['deleted' => $n]);
    }
}
```

`Plugin::register()`: build both controllers and append them in `controllers()`: `return apply_filters('alpaca_bot/rest/controllers', [new Rest\ChatController($this->get(Chat\Pipeline::class)), new Rest\ConversationsController($conversations, $store)]);`

- [ ] **Step 4: Verify green, real check, commit**

Run: `composer check && composer test:integration` — pass.
Real: `wph open-admin alpacabot --print`, log in, then from the browser console: `wp.apiFetch({path:'/alpaca-bot/v1/chat', method:'POST', data:{message:'Say hi'}}).then(console.log)` → object with `message.content`. And with an Application Password (`wph wp alpacabot -- user application-password create admin cli --porcelain`): `curl -u admin:<pw> https://alpacabot.wp.test/wp-json/alpaca-bot/v1/conversations` → JSON list.
```bash
git add -A && git commit -m "feat: chat and conversations REST routes"
```

---

### Task 4: Streaming route (SSE)

**Files:**
- Create: `src/Rest/Sse.php`, `src/Rest/StreamController.php`, `tests/Unit/Rest/SseTest.php`, `tests/Unit/Rest/StreamControllerTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- `GET /chat/{conversation}/stream?token=…` → `text/event-stream`. Events: `event: delta` `data: {"text": "..", "reasoning": ".."}`; `event: done` `data: {conversation_id, message, receipt}`; `event: error` `data: {code, message}`. Ends with `event: done` or `event: error` then the connection closes.
- ```php
  namespace AlpacaBot\Rest;
  final class Sse {
      public static function frame(string $event, array $data): string;   // "event: X\ndata: {json}\n\n"
      public static function headers(): array;                             // Content-Type, Cache-Control: no-cache, X-Accel-Buffering: no, Connection: keep-alive
      public static function prepareOutput(): void;                       // ends all output buffers, disables zlib compression, sets implicit flush, ignore_user_abort(false)
  }
  final class StreamController extends Controller {
      public function __construct(private Pipeline $pipeline) {}
      public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;   // validates token → returns a Response with header X-Alpaca-Bot-Stream: 1 and data = ticket; actual streaming happens in serve()
      public function serve(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool;   // rest_pre_serve_request: when the marker header is present, writes SSE via a writer callable and returns true
      public function stream(array $ticket, callable $write): void;   // pure: runs the pipeline and calls $write(frame) for each event
  }
  ```

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Rest/SseTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Rest\Sse;

it('formats frames and headers', function (): void {
    expect(Sse::frame('delta', ['text' => 'a"b']))->toBe("event: delta\ndata: {\"text\":\"a\\\"b\"}\n\n");
    expect(Sse::headers())->toMatchArray(['Content-Type' => 'text/event-stream; charset=utf-8', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
});
```

`tests/Unit/Rest/StreamControllerTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Delta;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\Result;
use AlpacaBot\Rest\StreamController;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('__')->returnArg();
});

it('rejects a missing or foreign token', function (): void {
    Functions\when('get_transient')->justReturn(['user_id' => 9, 'message' => 'x', 'options' => []]);
    $c = new StreamController(Mockery::mock(Pipeline::class));
    $res = $c->handle(new WP_REST_Request('GET', '/x', ['id' => '5', 'token' => 't']));
    expect($res)->toBeInstanceOf(WP_Error::class)->and($res->get_error_data()['status'])->toBe(403);
});

it('accepts a valid token once and marks the response for streaming', function (): void {
    Functions\expect('get_transient')->once()->with('alpaca_bot_stream_t')->andReturn(['user_id' => 3, 'message' => 'hi', 'options' => ['conversation_id' => 5]]);
    Functions\expect('delete_transient')->once()->with('alpaca_bot_stream_t');
    $res = (new StreamController(Mockery::mock(Pipeline::class)))->handle(new WP_REST_Request('GET', '/x', ['id' => '5', 'token' => 't']));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)->and($res->headers['X-Alpaca-Bot-Stream'])->toBe('1')->and($res->get_data()['message'])->toBe('hi');
});

it('writes delta frames then a done frame', function (): void {
    $pipeline = Mockery::mock(Pipeline::class);
    $pipeline->shouldReceive('send')->once()->with(3, 'hi', ['conversation_id' => 5])->andReturn((function () {
        yield new Delta('a');
        yield new Delta('b', 'thinking');
        return new Result(new Conversation(5, 3, 'T'), new Message('assistant', 'ab', 'm'), ['total_tokens' => 2], []);
    })());
    $frames = [];
    (new StreamController($pipeline))->stream(['user_id' => 3, 'message' => 'hi', 'options' => ['conversation_id' => 5]], function (string $f) use (&$frames): void { $frames[] = $f; });
    expect($frames)->toHaveCount(3)
        ->and($frames[0])->toBe("event: delta\ndata: {\"text\":\"a\",\"reasoning\":\"\"}\n\n")
        ->and($frames[1])->toContain('"reasoning":"thinking"')
        ->and($frames[2])->toStartWith("event: done\n")->and($frames[2])->toContain('"conversation_id":5');
});

it('writes an error frame on CapExceeded', function (): void {
    $pipeline = Mockery::mock(Pipeline::class);
    $pipeline->shouldReceive('send')->andThrow(new CapExceeded('site', 1, 2));
    $frames = [];
    (new StreamController($pipeline))->stream(['user_id' => 3, 'message' => 'hi', 'options' => []], function (string $f) use (&$frames): void { $frames[] = $f; });
    expect($frames[0])->toStartWith("event: error\n")->and($frames[0])->toContain('alpaca_bot_cap_exceeded');
});
```

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing classes.

- [ ] **Step 3: Implement**

`src/Rest/Sse.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

final class Sse
{
    public static function frame(string $event, array $data): string
    {
        return "event: {$event}\ndata: " . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    public static function headers(): array
    {
        return ['Content-Type' => 'text/event-stream; charset=utf-8', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no', 'Connection' => 'keep-alive'];
    }

    public static function prepareOutput(): void
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ignore_user_abort(false);
        set_time_limit(0);
    }
}
```
(Unit tests stub `wp_json_encode` with `Functions\when('wp_json_encode')->alias('json_encode')` in `tests/Pest.php`'s beforeEach.)

`src/Rest/StreamController.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Chat\Pipeline;

final class StreamController extends Controller
{
    public const MARKER = 'X-Alpaca-Bot-Stream';

    public function __construct(private Pipeline $pipeline) {}

    public function routes(): array
    {
        return [['path' => '/chat/(?P<id>\d+)/stream', 'methods' => 'GET', 'callback' => [$this, 'handle'], 'capability' => 'edit_posts', 'args' => ['token' => ['type' => 'string', 'required' => true]]]];
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $request->get_param('token')) ?? '';
        $ticket = get_transient('alpaca_bot_stream_' . $token);
        if (!is_array($ticket) || (int) ($ticket['user_id'] ?? 0) !== $this->userId()) {
            return Errors::forbidden(__('Invalid or expired stream token.', 'alpaca-bot'));
        }
        delete_transient('alpaca_bot_stream_' . $token);
        $ticket['options']['conversation_id'] = (int) $request->get_param('id');
        $res = new \WP_REST_Response($ticket, 200);
        $res->header(self::MARKER, '1');
        return $res;
    }

    public function serve(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool
    {
        $headers = $result->get_headers();
        if ($served || empty($headers[self::MARKER])) {
            return $served;
        }
        foreach (Sse::headers() as $k => $v) {
            $server->send_header($k, $v);
        }
        Sse::prepareOutput();
        $this->stream((array) $result->get_data(), static function (string $frame): void {
            echo $frame;
            flush();
        });
        return true;
    }

    public function stream(array $ticket, callable $write): void
    {
        try {
            $gen = $this->pipeline->send((int) $ticket['user_id'], (string) $ticket['message'], (array) ($ticket['options'] ?? []));
            foreach ($gen as $delta) {
                $write(Sse::frame('delta', ['text' => $delta->text, 'reasoning' => $delta->reasoning]));
                if (connection_aborted()) {
                    return;
                }
            }
            $r = $gen->getReturn();
            $write(Sse::frame('done', ['conversation_id' => $r->conversation->id, 'message' => $r->reply->toArray(), 'receipt' => $r->receipt]));
        } catch (CapExceeded $e) {
            $write(Sse::frame('error', ['code' => 'alpaca_bot_cap_exceeded', 'message' => $e->getMessage(), 'scope' => $e->scope, 'limit' => $e->limit, 'used' => $e->used]));
        } catch (\Throwable $e) {
            $write(Sse::frame('error', ['code' => 'alpaca_bot_provider_error', 'message' => $e->getMessage()]));
        }
    }
}
```

`Plugin::register()`: `$stream = new Rest\StreamController($this->get(Chat\Pipeline::class)); add_filter('rest_pre_serve_request', [$stream, 'serve'], 10, 4);` and add `$stream` to the controllers array.

- [ ] **Step 4: Verify green, real check, commit**

Run: `composer check` — pass.
Real, in the browser console on alpacabot.wp.test (logged in):
```js
const t = await wp.apiFetch({path:'/alpaca-bot/v1/chat', method:'POST', data:{message:'Count from 1 to 5 slowly', stream:true}});
const r = await fetch(t.stream_url, {headers:{'X-WP-Nonce': wpApiSettings.nonce}}); const rd = r.body.getReader(); const td = new TextDecoder();
for(;;){const {value,done}=await rd.read(); if(done)break; console.log(td.decode(value));}
```
Expected: multiple `event: delta` chunks arriving progressively (not one burst), then `event: done`. If the deltas arrive as a single burst, Apache `mod_deflate` or PHP output buffering is still on; confirm `Sse::prepareOutput()` ran and add `SetEnv no-gzip 1` for the REST path in the harness site's `.htaccess` if needed.
```bash
git add -A && git commit -m "feat: SSE streaming route via rest_pre_serve_request"
```

---

### Task 5: Models, settings, and usage routes

**Files:**
- Create: `src/Rest/ModelsController.php`, `src/Rest/SettingsController.php`, `src/Rest/UsageController.php`, `tests/Unit/Rest/SettingsControllerTest.php`, `tests/Integration/SettingsRoutesTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- `GET /models?refresh=1` → `[{id,label,tools,vision,thinking}]` plus `default` in header `X-Alpaca-Bot-Default-Model`.
- `GET /settings` (manage_options) → full settings array; secrets (`provider.api_key`) masked as `"••••"` unless `?reveal=1`. `PUT /settings` body: partial dotted keys → sanitized full array; masked value for api_key is ignored (keeps stored).
- `GET /usage?user=me|all` → `{month, tokens, requests, caps: {user: int, site: int}}`; `all` needs manage_options.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Rest/SettingsControllerTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Rest\SettingsController;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

it('masks the api key on read and keeps it on masked write', function (): void {
    Functions\when('get_option')->justReturn(['provider.api_key' => 'secret']);
    $written = null;
    Functions\when('update_option')->alias(function (string $k, array $v) use (&$written): bool { $written = $v; return true; });
    $c = new SettingsController(new Store());
    expect($c->show(new WP_REST_Request('GET', '/x'))->get_data()['provider.api_key'])->toBe('••••');
    $c->update(new WP_REST_Request('PUT', '/x', ['provider.api_key' => '••••', 'models.num_ctx' => 2048]));
    expect($written['provider.api_key'])->toBe('secret')->and($written['models.num_ctx'])->toBe(2048);
});
```

`tests/Integration/SettingsRoutesTest.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

final class SettingsRoutesTest extends TestCase
{
    public function test_settings_need_manage_options(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertSame(403, $this->rest('GET', '/settings')->get_status());
        $this->asAdmin();
        $this->assertSame(200, $this->rest('GET', '/settings')->get_status());
        $res = $this->rest('PUT', '/settings', ['models.temperature' => 1.5]);
        $this->assertSame(1.5, $res->get_data()['models.temperature']);
        $this->assertSame(1.5, get_option('alpaca_bot_settings')['models.temperature']);
    }

    public function test_usage_route_reports_the_month(): void
    {
        $this->asAdmin();
        $data = $this->rest('GET', '/usage')->get_data();
        $this->assertArrayHasKey('tokens', $data);
        $this->assertSame(gmdate('Y-m'), $data['month']);
    }
}
```

- [ ] **Step 2: Run to verify failure** — FAIL on missing classes.

- [ ] **Step 3: Implement**

`src/Rest/ModelsController.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Provider\Model;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;

final class ModelsController extends Controller
{
    public function __construct(private ModelCatalog $catalog, private Store $store) {}

    public function routes(): array
    {
        return [['path' => '/models', 'methods' => 'GET', 'callback' => [$this, 'index'], 'capability' => 'edit_posts', 'args' => ['refresh' => ['type' => 'boolean', 'default' => false]]]];
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $models = $this->catalog->all((bool) $request->get_param('refresh'));
        $res = new \WP_REST_Response(array_map(static fn(Model $m): array => $m->toArray(), $models));
        $res->header('X-Alpaca-Bot-Default-Model', $this->catalog->defaultId($this->store));
        return $res;
    }
}
```

`src/Rest/SettingsController.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

final class SettingsController extends Controller
{
    public const MASK = '••••';
    private const SECRETS = ['provider.api_key'];

    public function __construct(private Store $store) {}

    public function routes(): array
    {
        return [
            ['path' => '/settings', 'methods' => 'GET', 'callback' => [$this, 'show'], 'capability' => 'manage_options', 'args' => ['reveal' => ['type' => 'boolean', 'default' => false]]],
            ['path' => '/settings', 'methods' => 'PUT', 'callback' => [$this, 'update'], 'capability' => 'manage_options'],
            ['path' => '/settings/schema', 'methods' => 'GET', 'callback' => fn() => new \WP_REST_Response(['sections' => Schema::sections(), 'fields' => array_map(static fn(array $f): array => array_diff_key($f, ['sanitize' => 1]), Schema::fields())]), 'capability' => 'manage_options'],
        ];
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $all = $this->store->all();
        if (!(bool) $request->get_param('reveal')) {
            foreach (self::SECRETS as $k) {
                if (($all[$k] ?? '') !== '') {
                    $all[$k] = self::MASK;
                }
            }
        }
        return new \WP_REST_Response($all);
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $current = $this->store->all();
        $input = array_intersect_key((array) $request->get_json_params() ?: (array) $request->get_params(), Schema::fields());
        foreach (self::SECRETS as $k) {
            if (($input[$k] ?? null) === self::MASK) {
                unset($input[$k]);
            }
        }
        $this->store->replace(array_merge($current, $input));
        return $this->show($request);
    }
}
```

`src/Rest/UsageController.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Settings\Store;

final class UsageController extends Controller
{
    public function __construct(private UsageMeter $meter, private Store $store) {}

    public function routes(): array
    {
        return [['path' => '/usage', 'methods' => 'GET', 'callback' => [$this, 'show'], 'capability' => 'edit_posts', 'args' => ['user' => ['type' => 'string', 'default' => 'me']]]];
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $all = $request->get_param('user') === 'all';
        if ($all && !current_user_can('manage_options')) {
            return Errors::forbidden();
        }
        $s = $this->meter->monthSummary($all ? null : $this->userId());
        return new \WP_REST_Response($s + ['caps' => ['user' => (int) $this->store->get('governance.user_monthly_tokens'), 'site' => (int) $this->store->get('governance.site_monthly_tokens')]]);
    }
}
```

Register all three in `Plugin::controllers()`.

- [ ] **Step 4: Verify green, commit**

Run: `composer check && composer test:integration` — pass.
```bash
git add -A && git commit -m "feat: models, settings, and usage REST routes"
```

---

### Task 6: Settings admin page (Settings API)

**Files:**
- Create: `src/Admin/Menu.php`, `src/Admin/SettingsPage.php`, `src/Admin/Fields.php`, `tests/Unit/Admin/FieldsTest.php`, `tests/Integration/SettingsPageTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- `Admin\Menu` registers top-level "Alpaca Bot" (`alpaca-bot`, icon `dashicons-format-chat`, capability `edit_posts`, position 3) whose first submenu is the chat page placeholder (P3 replaces the callback) and a "Settings" submenu (`alpaca-bot-settings`, `manage_options`). Filter `alpaca_bot/admin/menu_capability`.
- `Admin\SettingsPage`: `register()` on `admin_init` calls `register_setting('alpaca_bot', Plugin::OPTION, ['type' => 'array', 'sanitize_callback' => [Schema::class, 'sanitize'], 'default' => Schema::defaults()])`, one `add_settings_section` per `Schema::sections()`, one `add_settings_field` per field; `render()` outputs the page with `settings_fields('alpaca_bot')`, tabs per section (`?tab=`), `do_settings_sections`, `submit_button()`. Per-model overrides render as a table with a row per known model (from `ModelCatalog`) and inputs named `alpaca_bot_settings[models.overrides][<model>][temperature]` etc. All fields post under `alpaca_bot_settings[<dotted.key>]`; the sanitize callback receives the full array so every tab's fields are submitted every time (fixes the old first-save bug) by rendering hidden inputs for fields on non-active tabs.
- `Admin\Fields::render(string $key, array $field, mixed $value): string` returns core markup per type (`text`, `number`, `checkbox`, `select`, `textarea` for `chat.system_prompt`/`chat.welcome`), with `regular-text`, `description` classes.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Admin/FieldsTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Admin\Fields;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('esc_attr')->returnArg();
    Functions\when('esc_html')->returnArg();
    Functions\when('esc_textarea')->returnArg();
    Functions\when('checked')->alias(fn($a, $b = true, $echo = true) => $a == $b ? ' checked="checked"' : '');
    Functions\when('selected')->alias(fn($a, $b = true, $echo = true) => $a == $b ? ' selected="selected"' : '');
});

it('renders each type with the option array name', function (): void {
    expect(Fields::render('provider.base_url', ['type' => 'string', 'label' => 'Base URL', 'default' => ''], 'http://x'))->toContain('name="alpaca_bot_settings[provider.base_url]"')->toContain('value="http://x"')->toContain('class="regular-text"');
    expect(Fields::render('chat.spellcheck', ['type' => 'boolean', 'label' => 'S', 'default' => true], true))->toContain('type="checkbox"')->toContain('checked="checked"');
    expect(Fields::render('provider.kind', ['type' => 'select', 'label' => 'P', 'default' => 'ollama', 'options' => ['ollama' => 'Ollama', 'wp-ai' => 'WP']], 'wp-ai'))->toContain('<option value="wp-ai" selected="selected">');
    expect(Fields::render('models.temperature', ['type' => 'number', 'label' => 'T', 'default' => 0.7, 'min' => 0, 'max' => 2], 0.7))->toContain('type="number"')->toContain('step="0.1"')->toContain('max="2"');
    expect(Fields::render('chat.system_prompt', ['type' => 'string', 'label' => 'S', 'default' => '', 'description' => 'd'], 'x'))->toContain('<textarea')->toContain('class="description"');
});
```

`tests/Integration/SettingsPageTest.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

final class SettingsPageTest extends TestCase
{
    public function test_setting_is_registered_and_sanitized_on_save(): void
    {
        $this->asAdmin();
        do_action('admin_init');
        $registered = get_registered_settings();
        $this->assertArrayHasKey('alpaca_bot_settings', $registered);
        update_option('alpaca_bot_settings', ['models.num_ctx' => '99999999999', 'provider.kind' => 'nope']);
        $saved = get_option('alpaca_bot_settings');
        $this->assertSame(1048576, $saved['models.num_ctx']);
        $this->assertSame('ollama', $saved['provider.kind']);
    }

    public function test_menu_pages_are_registered(): void
    {
        $this->asAdmin();
        set_current_screen('dashboard');
        do_action('admin_menu');
        global $submenu;
        $slugs = array_column($submenu['alpaca-bot'] ?? [], 2);
        $this->assertContains('alpaca-bot-settings', $slugs);
    }
}
```

- [ ] **Step 2: Run to verify failure** — FAIL on missing classes.

- [ ] **Step 3: Implement**

`src/Admin/Fields.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Plugin;

final class Fields
{
    private const TEXTAREAS = ['chat.system_prompt', 'chat.welcome'];

    public static function name(string $key): string
    {
        return Plugin::OPTION . '[' . $key . ']';
    }

    public static function render(string $key, array $f, mixed $value): string
    {
        $name = esc_attr(self::name($key));
        $id = esc_attr('ab-' . str_replace('.', '-', $key));
        $desc = isset($f['description']) ? '<p class="description">' . esc_html($f['description']) . '</p>' : '';
        switch ($f['type']) {
            case 'boolean':
                return sprintf('<input type="hidden" name="%1$s" value="0"><label><input type="checkbox" id="%2$s" name="%1$s" value="1"%3$s> %4$s</label>%5$s', $name, $id, checked((bool) $value, true, false), esc_html($f['label']), $desc);
            case 'select':
                $opts = '';
                foreach ($f['options'] as $v => $label) {
                    $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr((string) $v), selected((string) $value, (string) $v, false), esc_html($label));
                }
                return sprintf('<select id="%s" name="%s">%s</select>%s', $id, $name, $opts, $desc);
            case 'integer':
            case 'number':
                $step = $f['type'] === 'integer' ? '1' : '0.1';
                return sprintf('<input type="number" class="small-text" id="%s" name="%s" value="%s" step="%s" min="%s" max="%s">%s', $id, $name, esc_attr((string) $value), $step, esc_attr((string) ($f['min'] ?? '')), esc_attr((string) ($f['max'] ?? '')), $desc);
            case 'array':
                return '';
            default:
                if (in_array($key, self::TEXTAREAS, true)) {
                    return sprintf('<textarea id="%s" name="%s" rows="5" class="large-text code">%s</textarea>%s', $id, $name, esc_textarea((string) $value), $desc);
                }
                $type = $key === 'provider.api_key' ? 'password' : 'text';
                return sprintf('<input type="%s" class="regular-text" id="%s" name="%s" value="%s"%s>%s', $type, $id, $name, esc_attr((string) $value), $type === 'password' ? ' autocomplete="new-password"' : '', $desc);
        }
    }
}
```

`src/Admin/SettingsPage.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

final class SettingsPage
{
    public const SLUG = 'alpaca-bot-settings';

    public function __construct(private Store $store, private ModelCatalog $catalog) {}

    public function register(): void
    {
        register_setting('alpaca_bot', Plugin::OPTION, ['type' => 'array', 'sanitize_callback' => [Schema::class, 'sanitize'], 'default' => Schema::defaults()]);
        foreach (Schema::sections() as $id => $s) {
            add_settings_section('alpaca_bot_' . $id, $s['label'], static function () use ($s): void { echo '<p>' . esc_html($s['description']) . '</p>'; }, self::SLUG . '-' . $id);
        }
        foreach (Schema::fields() as $key => $f) {
            add_settings_field('alpaca_bot_' . $key, $f['type'] === 'boolean' ? '' : $f['label'], function () use ($key, $f): void {
                echo $key === 'models.overrides' ? $this->renderOverrides() : Fields::render($key, $f, $this->store->get($key)); // phpcs:ignore WordPress.Security.EscapeOutput
            }, self::SLUG . '-' . $f['section'], 'alpaca_bot_' . $f['section'], ['label_for' => 'ab-' . str_replace('.', '-', $key)]);
        }
    }

    public function render(): void
    {
        $sections = Schema::sections();
        $active = sanitize_key((string) ($_GET['tab'] ?? 'provider')); // phpcs:ignore WordPress.Security.NonceVerification
        $active = isset($sections[$active]) ? $active : 'provider';
        echo '<div class="wrap"><h1>' . esc_html__('Alpaca Bot Settings', 'alpaca-bot') . '</h1>';
        settings_errors('alpaca_bot');
        echo '<nav class="nav-tab-wrapper">';
        foreach ($sections as $id => $s) {
            printf('<a href="%s" class="nav-tab%s">%s</a>', esc_url(add_query_arg(['page' => self::SLUG, 'tab' => $id], admin_url('admin.php'))), $id === $active ? ' nav-tab-active' : '', esc_html($s['label']));
        }
        echo '</nav><form method="post" action="options.php">';
        settings_fields('alpaca_bot');
        do_settings_sections(self::SLUG . '-' . $active);
        foreach (Schema::fields() as $key => $f) {
            if ($f['section'] !== $active) {
                $this->hidden($key, $this->store->get($key));
            }
        }
        submit_button();
        echo '</form></div>';
    }

    private function hidden(string $key, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                foreach ((array) $v as $kk => $vv) {
                    printf('<input type="hidden" name="%s" value="%s">', esc_attr(Plugin::OPTION . "[{$key}][{$k}][{$kk}]"), esc_attr((string) $vv));
                }
            }
            return;
        }
        printf('<input type="hidden" name="%s" value="%s">', esc_attr(Fields::name($key)), esc_attr(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
    }

    private function renderOverrides(): string
    {
        $over = (array) $this->store->get('models.overrides', []);
        $rows = '';
        foreach ($this->catalog->all() as $m) {
            $o = $over[$m->id] ?? [];
            $n = static fn(string $f): string => esc_attr(Plugin::OPTION . "[models.overrides][{$m->id}][{$f}]");
            $rows .= sprintf('<tr><th scope="row">%s</th><td><input type="number" step="0.1" min="0" max="2" class="small-text" name="%s" value="%s" placeholder="%s"></td><td><input type="number" step="1" min="512" class="small-text" name="%s" value="%s" placeholder="%s"></td><td><input type="text" class="small-text" name="%s" value="%s" placeholder="%s"></td><td><input type="text" class="regular-text" name="%s" value="%s"></td></tr>',
                esc_html($m->id), $n('temperature'), esc_attr((string) ($o['temperature'] ?? '')), esc_attr((string) $this->store->get('models.temperature')), $n('num_ctx'), esc_attr((string) ($o['num_ctx'] ?? '')), esc_attr((string) $this->store->get('models.num_ctx')), $n('keep_alive'), esc_attr((string) ($o['keep_alive'] ?? '')), esc_attr((string) $this->store->get('models.keep_alive')), $n('system'), esc_attr((string) ($o['system'] ?? '')));
        }
        if ($rows === '') {
            return '<p class="description">' . esc_html__('No models reported by the provider yet. Check the Provider tab.', 'alpaca-bot') . '</p>';
        }
        return '<table class="widefat striped"><thead><tr><th>' . esc_html__('Model', 'alpaca-bot') . '</th><th>' . esc_html__('Temperature', 'alpaca-bot') . '</th><th>num_ctx</th><th>keep_alive</th><th>' . esc_html__('System prompt', 'alpaca-bot') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }
}
```

`src/Admin/Menu.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

final class Menu
{
    public const SLUG = 'alpaca-bot';

    /** @param callable():void $chatRenderer */
    public function __construct(private SettingsPage $settings, private $chatRenderer) {}

    public function register(): void
    {
        $cap = (string) apply_filters('alpaca_bot/admin/menu_capability', 'edit_posts');
        add_menu_page(__('Alpaca Bot', 'alpaca-bot'), __('Alpaca Bot', 'alpaca-bot'), $cap, self::SLUG, $this->chatRenderer, 'dashicons-format-chat', 3);
        add_submenu_page(self::SLUG, __('Chat', 'alpaca-bot'), __('Chat', 'alpaca-bot'), $cap, self::SLUG, $this->chatRenderer);
        add_submenu_page(self::SLUG, __('Alpaca Bot Settings', 'alpaca-bot'), __('Settings', 'alpaca-bot'), 'manage_options', SettingsPage::SLUG, [$this->settings, 'render']);
    }
}
```

`Plugin::register()` (admin only):
```php
if (is_admin()) {
    $settingsPage = new Admin\SettingsPage($store, $this->get(Provider\ModelCatalog::class));
    add_action('admin_init', [$settingsPage, 'register']);
    $menu = new Admin\Menu($settingsPage, static function (): void { echo '<div class="wrap"><h1>Alpaca Bot</h1><p>' . esc_html__('The chat screen arrives in the next milestone.', 'alpaca-bot') . '</p></div>'; });
    add_action('admin_menu', [$menu, 'register']);
}
```

- [ ] **Step 4: Verify green, real check, commit**

Run: `composer check && composer test:integration` — pass.
Real: `wph open-admin alpacabot`, open Alpaca Bot → Settings; change Temperature on the Models tab, save; switch to Provider tab and confirm the value persisted (the first-save bug is gone); enter a fake API key, save, reload: field shows masked, REST `GET /settings` shows `••••`.
```bash
git add -A && git commit -m "feat: Settings API admin page with tabs, hidden carry-over, and per-model overrides"
```

---

### Task 7: API docs and Application Password walkthrough

**Files:**
- Create: `docs/api.md`
- Modify: `README.md` (REST section replaced with a pointer to docs/api.md)

- [ ] **Step 1: Write `docs/api.md`** with: namespace, auth (cookie+nonce, Application Passwords with a `curl -u` example, capability filters with a PHP snippet), every route with request/response JSON examples taken from the integration tests, the SSE event format with a `fetch` reader example (from Task 4), error codes table (400, 401/403, 402 cap, 404, 429 with Retry-After, 502), and rate-limit filter example.
- [ ] **Step 2: Verify every example against alpacabot.wp.test** by running the curl commands; paste real output where the doc shows output.
- [ ] **Step 3: Commit and push**

```bash
git add -A && git commit -m "docs: REST API reference with auth and streaming examples" && git push
```
Comment the verification output on the Kanboard ticket for this plan and close it. P3 may start.

---

## Not in this plan

- `/view/*` htmx fragment routes and the chat screen (P3). Abilities-based exposure (P4). CI execution of the integration suite (P5).

## Self-review

- **Spec coverage (steps 3-4):** single namespace + JSON resources (T3, T5), streaming (T4), auth model + capability filters + rate limits (T1), legacy routes gone (P1 no longer boots them; file deletion P3), Settings API + schema + migration (P1 T2 + T6), per-model overrides table (T6). Application Password path verified (T3, T7).
- **Placeholders:** none; docs task lists the exact content required.
- **Type consistency:** `Controller::routes()` array shape identical across T1/T3/T4/T5; `Errors::*` return `WP_Error` with `status` in data everywhere; ticket array `{user_id, message, options}` written in T3 and consumed in T4; `Pipeline::send/complete` signatures as in P1.
