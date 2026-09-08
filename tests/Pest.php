<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapPolicy;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Cli\ChatCommand;
use AlpacaBot\Context\Collector;
use AlpacaBot\Context\ContextSourceInterface;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Rest\Controller;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

uses()->beforeEach(function (): void {
    Monkey\setUp();
    // Schema labels run through __(); pass the strings through untouched.
    Functions\stubTranslationFunctions();
    // Schema::sanitizeUrl() runs through esc_url_raw(). Brain Monkey's stand-in keeps
    // the value as is (adding http:// when there is no scheme); tests that care about
    // rejection alias esc_url_raw themselves.
    Functions\stubEscapeFunctions();
    // Rest\Sse frames are wp_json_encode()d, which is json_encode() plus a non-UTF-8 fallback
    // no test needs; the plain function stands in.
    Functions\when('wp_json_encode')->alias('json_encode');
    // Plugin is a process-wide singleton and Pest runs the suite in one process:
    // reset it so every test's boot() starts from a cold state.
    $instance = new ReflectionProperty(Plugin::class, 'instance');
    $instance->setValue(null, null);
})->afterEach(function (): void {
    // Mockery expectations are the only assertions in some tests; count them
    // before tearDown() closes the container so those tests are not "risky".
    $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    Monkey\tearDown();
    Mockery::close();
})->in('Unit');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('ALPACA_BOT_FILE')) {
    define('ALPACA_BOT_FILE', dirname(__DIR__) . '/alpaca-bot.php');
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor-prefixed/autoload.php';
// WP_Error, WP_REST_Request and WP_REST_Response are classes, which Brain Monkey cannot stub,
// and wordpress-stubs is PHPStan-only. Load the stand-ins unless a real WordPress is present.
if (!class_exists('WP_Error', false)) {
    require_once __DIR__ . '/stubs/wp-rest.php';
}

// ---------------------------------------------------------------- test helpers
// Pest loads every test file into one process, so a helper declared at the root of a test
// file is a global: a second file declaring the same name is a fatal redeclare, not a test
// failure. Helpers live here instead, one declaration each, prefixed by the suite they serve.

/**
 * Rest\ControllerTest (and every later REST test): a Controller over exactly the given route
 * list, so a test declares the one route it is about inline instead of a named subclass.
 * Controller is abstract and Pest.php is one process, so a named subclass per test file would
 * be the redeclare trap described above; an anonymous class has no name to collide on.
 *
 * @param list<array{path: string, methods: string, callback: callable, capability: string, args?: array<string, array<string, mixed>>, rate_limit?: bool}> $routes
 */
function restController(array $routes): Controller
{
    return new class ($routes) extends Controller {
        public function __construct(private array $routes) {}

        public function routes(): array
        {
            return $this->routes;
        }
    };
}

/**
 * FactoryTest and FieldsTest: runs `$script` (PHP source, fed on stdin) in a fresh PHP process
 * with `$args` following `$argv[0]`, and returns its stdout.
 *
 * OLLAMA_API_URL is a process-wide constant and Pest runs the whole suite in one process, so a
 * define() anywhere would silently retarget every other test that reads it. The suite never
 * defines it; the two places that must see it defined — Factory::baseUrl() preferring it, and
 * the Fields note that warns the administrator it is preferred — are probed out here instead,
 * which keeps the suite order-independent.
 *
 * @param list<string> $args
 */
function freshProcess(string $script, array $args): string
{
    $process = proc_open(
        [PHP_BINARY, '--', ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    expect($process)->toBeResource();
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, "probe failed: {$stderr}{$stdout}")->and($stderr)->toBe('');

    return $stdout;
}

/**
 * Admin\SettingsPageTest: a page over a pre-seeded Store and a catalog that is never asked
 * (do_settings_sections() is stubbed there, so the overrides table never renders).
 *
 * @param array<string, mixed> $settings
 */
function settingsPage(array $settings = []): AlpacaBot\Admin\SettingsPage
{
    $store = new Store($settings);
    return new AlpacaBot\Admin\SettingsPage($store, new ModelCatalog(new Factory($store)));
}

/**
 * ChatControllerTest and ConversationsControllerTest: a request with its parameters already set.
 * The stub's constructor takes route attributes as its third argument, as core's does, so
 * parameters go in through set_param(), the way core sets URL, query and body values.
 *
 * @param array<string, mixed> $params
 */
function restRequest(string $method, string $route, array $params = []): WP_REST_Request
{
    $request = new WP_REST_Request($method, $route);
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }
    return $request;
}

/**
 * ControllerTest and StreamControllerTest: rest_convert_error_to_response() as core does it, a
 * {code, message, data} body under the error's status, for a route that answers a refusal as a
 * WP_REST_Response because it has a header to carry (Retry-After on a 429, Allow on a 405).
 */
function restConvertsErrors(): void
{
    Functions\when('rest_convert_error_to_response')->alias(static fn(WP_Error $e): WP_REST_Response => new WP_REST_Response(['code' => $e->get_error_code(), 'message' => $e->get_error_message(), 'data' => $e->get_error_data()], (int) $e->get_error_data()['status']));
}

/** ConversationStoreTest: a chat_history post as get_post() hands it back (stdClass: WP_Post is not loaded here). */
function conversationChatPost(int $id = 42, string $author = '3', string $type = 'chat_history', string $date = '2024-01-01 00:00:00'): object
{
    return (object) ['ID' => $id, 'post_author' => $author, 'post_title' => 'T', 'post_type' => $type, 'post_date_gmt' => $date];
}

/**
 * ConversationStoreTest and pipelineWith(): the database as ConversationStore::storageBudget()
 * reads it. Installs a `$wpdb` stand-in whose get_var() answers `$raw` to `SELECT
 * @@max_allowed_packet` (wpdb is a class Brain Monkey cannot stub, and the real one is not loaded
 * here), records every query in `->queries`, and stubs maybe_serialize() as serialize(), which is
 * what core's does for an array. The store caches the figure for the request in a private
 * static, and Pest runs the suite in one process, so the cache is reset here the way Pest.php
 * resets Plugin::$instance; a test that wants a different figure calls this again. The reset
 * names the property outright: guarded by property_exists() it would go quiet the day the
 * property is renamed, and the first figure read would then leak into every later test.
 *
 * The default packet is MySQL's documented default, 16 MiB; a budget test passes something
 * smaller so a transcript of a few hundred bytes is over it.
 */
function conversationStoreDb(mixed $raw = '16777216'): object
{
    $db = new class ($raw) {
        /** @var list<string> */
        public array $queries = [];

        public function __construct(private mixed $raw) {}

        public function get_var(string $query): mixed
        {
            $this->queries[] = $query;
            return $this->raw;
        }
    };
    $GLOBALS['wpdb'] = $db;
    Functions\when('maybe_serialize')->alias(static fn(mixed $v): mixed => is_array($v) || is_object($v) ? serialize($v) : $v);
    (new ReflectionProperty(ConversationStore::class, 'maxAllowedPacket'))->setValue(null, null);
    return $db;
}

/** ConversationStoreTest: `$n` space-separated words. */
function conversationWords(int $n, string $prefix = 'w'): string
{
    return implode(' ', array_map(static fn(int $i): string => $prefix . $i, range(1, $n)));
}

/** Migrate04Test: a tiny options table: get_option()/update_option() read and write $stored, so flags persist across calls. */
function migrate04Options(array &$stored): void
{
    Functions\when('get_option')->alias(function (string $k, mixed $d = false) use (&$stored): mixed {
        return $stored[$k] ?? ($k === 'alpaca_bot_settings' ? [] : $d);
    });
    Functions\when('update_option')->alias(function (string $k, mixed $v) use (&$stored): bool {
        $stored[$k] = $v;
        return true;
    });
}

/** Migrate04Test: a legacy chat_history row as get_posts() hands it back. */
function migrate04LegacyRow(int $id, string $author = '0'): object
{
    return (object) ['ID' => $id, 'post_author' => $author, 'post_type' => 'chat_history', 'post_status' => 'publish'];
}

/** CapPolicyTest: serves the cached August 2024 month summaries the meter would find, keyed by scope. */
function capPolicyUsageCache(int $user, int $site): void
{
    Functions\when('get_transient')->alias(fn(string $key): array|false => match ($key) {
        'alpaca_bot_usage_3_2024-08' => ['tokens' => $user, 'requests' => 1],
        'alpaca_bot_usage_site_2024-08' => ['tokens' => $site, 'requests' => 1],
        default => false,
    });
}

/** CapPolicyTest: a policy over a real meter, both reading the given settings. */
function capPolicyWith(array $settings): CapPolicy
{
    Functions\when('get_option')->justReturn($settings);
    $store = new Store();
    return new CapPolicy($store, new UsageMeter($store));
}

/** CollectorTest: a source with a fixed id that returns the given contexts whatever the request. */
function collectorSource(string $id, array $contexts): ContextSourceInterface
{
    return new class ($id, $contexts) implements ContextSourceInterface {
        public function __construct(private string $id, private array $contexts) {}

        public function id(): string
        {
            return $this->id;
        }

        public function collect(int $userId, array $request): array
        {
            return $this->contexts;
        }
    };
}

/** CurrentScreenSourceTest: a post as get_post() hands it back (stdClass: WP_Post is not loaded here). */
function currentScreenPost(int $id, string $title, string $content, string $type = 'post', string $status = 'draft'): object
{
    return (object) ['ID' => $id, 'post_title' => $title, 'post_content' => $content, 'post_type' => $type, 'post_status' => $status];
}

/**
 * PipelineTest: a Pipeline over its real collaborators (every one of them is final, so nothing
 * is mocked) with WordPress stubbed. The provider is handed in through the alpaca_bot/provider
 * filter, Factory's documented seam; null means no provider may be built at all, and anything
 * that is not a provider is handed back as is (a broken third-party filter).
 *
 * The clock is 1_725_000_000 (2024-08-30 06:40:00 UTC). The conversation post is 42, owned by
 * user 3 unless a test swaps `$h->post`; a chat_log insert is post 9. Transients are served from
 * `$h->transients` (pre-seeded with the model catalog). The harness records every persisted
 * write in `$h->writes` as [function, post type or meta key, payload] in order (a
 * wp_delete_post as [function, post id, force]), and the model the factory was asked for in
 * `$h->model`. Post meta written through update_post_meta is readable back through
 * get_post_meta (`$h->meta[post id][key]`), so a store method that checks what a turn has
 * persisted before acting sees the turn's own writes; a test that needs a pre-existing
 * transcript stubs get_post_meta itself, after this. The Store, ModelCatalog and UsageMeter the pipeline
 * was built over are `$h->store`, `$h->catalog` and `$h->meter`, for a caller (cliCommand())
 * that must share them the way Plugin::register() shares one container's instances.
 *
 * @param array<string, mixed> $settings seeded into the shared Store
 * @param \AlpacaBot\Context\Context[] $contexts what the one registered source returns
 * @param list<string>|null $catalog model ids the cached catalog lists; null leaves the catalog transient expired,
 *   so the catalog is discovered from `$provider` (its models() is called) and the provider filter fires twice
 * @param \AlpacaBot\Chat\UserPrefs|null $prefs the per-user preferences the pipeline consults; null is the CLI's case (none)
 */
function pipelineWith(mixed $provider, array $settings = [], array $contexts = [], ?array $catalog = ['llama3.2'], ?AlpacaBot\Chat\UserPrefs $prefs = null): object
{
    $h = new class {
        public Pipeline $pipeline;
        public Store $store;
        public ModelCatalog $catalog;
        public UsageMeter $meter;
        /** @var list<array{0: string, 1: int|string, 2: mixed}> */
        public array $writes = [];
        public ?string $model = null;
        public object $post;
        /** @var array<string, mixed> */
        public array $transients = [];
        /** @var array<int, array<string, mixed>> */
        public array $meta = [];
    };
    $h->post = conversationChatPost();
    if ($catalog !== null) {
        $h->transients[ModelCatalog::TRANSIENT] = array_map(static fn(string $id): array => ['id' => $id, 'label' => $id], $catalog);
    }
    Functions\when('current_time')->justReturn(1_725_000_000);
    Functions\when('wp_generate_uuid4')->justReturn('uuid');
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_trim_words')->alias(static fn(string $text): string => $text);
    // A stock 8M post_max_size, as AssetsTest reads it: Pipeline::images() holds a turn's images
    // to Assets::maxImageBytes(), 6242304 decoded bytes here. A test about the cap itself stubs
    // this again with its own figure (Brain Monkey takes the later when()).
    Functions\when('wp_convert_hr_to_bytes')->justReturn(8 * 1024 * 1024);
    // ConversationStore::save() fits the transcript to the packet limit before writing; the stock 16 MiB here.
    conversationStoreDb();
    Functions\when('get_post')->alias(static fn(int $id): ?object => $id === (int) $h->post->ID ? $h->post : null);
    Functions\when('wp_insert_post')->alias(static function (array $post) use ($h): int {
        $h->writes[] = ['wp_insert_post', $post['post_type'], $post];
        return $post['post_type'] === ConversationStore::POST_TYPE ? 42 : 9;
    });
    Functions\when('update_post_meta')->alias(static function (int $id, string $key, mixed $value) use ($h): bool {
        $h->writes[] = ['update_post_meta', $key, $value];
        $h->meta[$id][$key] = $value;
        return true;
    });
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key = '', bool $single = false): mixed => $h->meta[$id][$key] ?? '');
    Functions\when('wp_delete_post')->alias(static function (int $id, bool $force = false) use ($h): object {
        $h->writes[] = ['wp_delete_post', $id, $force];
        unset($h->meta[$id]);
        return (object) ['ID' => $id];
    });
    Functions\when('wp_update_post')->alias(static function (array $post) use ($h): int {
        $h->writes[] = ['wp_update_post', (int) $post['ID'], $post];
        return (int) $post['ID'];
    });
    Functions\when('get_transient')->alias(static fn(string $key): mixed => $h->transients[$key] ?? false);
    Functions\when('set_transient')->justReturn(true);
    Functions\when('delete_transient')->justReturn(true);
    if ($provider === null) {
        Filters\expectApplied('alpaca_bot/provider')->never();
    } else {
        Filters\expectApplied('alpaca_bot/provider')->times($catalog === null ? 2 : 1)->andReturnUsing(static function (object $built, string $model) use ($h, $provider): mixed {
            $h->model = $model;
            return $provider;
        });
    }
    $h->store = new Store($settings + ['models.default' => 'llama3.2']);
    $factory = new Factory($h->store);
    $h->catalog = new ModelCatalog($factory);
    $h->meter = new UsageMeter($h->store);
    $h->pipeline = new Pipeline(
        $h->store,
        $factory,
        $h->catalog,
        new ConversationStore($h->store),
        $h->meter,
        new CapPolicy($h->store, $h->meter),
        new Collector([collectorSource('test', $contexts)]),
        $prefs,
    );
    return $h;
}

/**
 * StreamControllerTest: makes `$hook` behave as core's do_action() does for the duration of a
 * test. Brain Monkey records what add_action() registers and what do_action() fires but never
 * runs the one for the other, so code under test that listens to a pipeline action (the stream
 * route's `start` frame rides on `alpaca_bot/chat/started`) would never hear it. Wiring the two
 * expectations together here calls every listener added so far with do_action()'s arguments,
 * in the order they were added; priorities are ignored, which no caller relies on.
 */
function actionRuns(string $hook): void
{
    $listeners = [];
    Actions\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(static function (callable $callback) use (&$listeners): void {
        $listeners[] = $callback;
    });
    Actions\expectDone($hook)->zeroOrMoreTimes()->whenHappen(static function (mixed ...$args) use (&$listeners): void {
        foreach ($listeners as $listener) {
            $listener(...$args);
        }
    });
}

/**
 * StreamControllerTest: a stream ticket as ChatController::ticket() stores it, for user 3 and
 * the harness's conversation 42, with `$changes` merged over it.
 *
 * @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
function streamTicket(array $changes = []): array
{
    return array_replace_recursive([
        'user_id' => 3,
        'conversation_id' => 42,
        'message' => 'hi',
        'options' => ['conversation_id' => 42, 'model' => '', 'images' => [], 'context' => []],
    ], $changes);
}

/** PipelineTest: a provider mock whose one stream() call yields the given chunks and captures its arguments into `$call`. */
function pipelineProvider(array $chunks, ?array &$call = null): ProviderInterface
{
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('stream')->once()->andReturnUsing(static function (array $messages, array $tools, array $options) use ($chunks, &$call): \Generator {
        $call = ['messages' => $messages, 'tools' => $tools, 'options' => $options];
        foreach ($chunks as $chunk) {
            if ($chunk instanceof \Throwable) {
                throw $chunk;
            }
            yield $chunk;
        }
    });
    return $provider;
}

/**
 * ChatCommandTest: a ChatCommand over a pipelineWith() harness `$h`, sharing its pipeline,
 * catalog, meter and store: the same four instances, as Plugin::register() hands the command
 * the container's. Stdout lands in `$c->out` and failures in `$c->errors` instead of going
 * through WP_CLI::error().
 */
function cliCommand(object $h): object
{
    $c = new class {
        public ChatCommand $command;
        public string $out = '';
        /** @var list<string> */
        public array $errors = [];
    };
    $c->command = new ChatCommand(
        $h->pipeline,
        $h->catalog,
        $h->meter,
        $h->store,
        static function (string $s) use ($c): void {
            $c->out .= $s;
        },
        static function (string $message) use ($c): void {
            $c->errors[] = $message;
        },
    );
    return $c;
}

/** ChatCommandTest: the WordPress user table as the command sees it: `$existing` ids resolve through get_userdata(), `$current` is get_current_user_id(). */
function cliUsers(array $existing = [3], int $current = 0): void
{
    Functions\when('get_userdata')->alias(static fn(int $id): object|false => in_array($id, $existing, true) ? (object) ['ID' => $id] : false);
    Functions\when('get_current_user_id')->justReturn($current);
}

/**
 * View\Chat\ComponentsTest: a Shell over a one-model catalog (served from the transient), the
 * given conversation and history, user 3 "Carmelo", and the given sprite path.
 *
 * @param list<array{id: int, title: string, created: int}> $history
 */
function chatShell(?AlpacaBot\Chat\Conversation $conversation, array $history, ?string $sprite, int $postId = 0): AlpacaBot\View\Chat\Shell
{
    Functions\when('get_transient')->justReturn([['id' => 'llama3.2', 'label' => 'llama3.2']]);
    Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'Carmelo', 'ID' => 3]);
    Functions\when('get_avatar_url')->justReturn('/u.png');
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('admin_url')->alias(fn(string $p) => '/wp-admin/' . $p);
    $store = new Store(['models.default' => 'llama3.2', 'chat.history_limit' => 15]);
    return new AlpacaBot\View\Chat\Shell($store, new ModelCatalog(new Factory($store)), $conversation, $history, $postId, $sprite);
}
