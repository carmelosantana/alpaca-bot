<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapPolicy;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Context\Collector;
use AlpacaBot\Context\ContextSourceInterface;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use Brain\Monkey;
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

// ---------------------------------------------------------------- test helpers
// Pest loads every test file into one process, so a helper declared at the root of a test
// file is a global: a second file declaring the same name is a fatal redeclare, not a test
// failure. Helpers live here instead, one declaration each, prefixed by the suite they serve.

/** ConversationStoreTest: a chat_history post as get_post() hands it back (stdClass: WP_Post is not loaded here). */
function conversationChatPost(int $id = 42, string $author = '3', string $type = 'chat_history', string $date = '2024-01-01 00:00:00'): object
{
    return (object) ['ID' => $id, 'post_author' => $author, 'post_title' => 'T', 'post_type' => $type, 'post_date_gmt' => $date];
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
 * write in `$h->writes` as [function, post type or meta key, payload] in order, and the model
 * the factory was asked for in `$h->model`.
 *
 * @param array<string, mixed> $settings seeded into the shared Store
 * @param \AlpacaBot\Context\Context[] $contexts what the one registered source returns
 * @param list<string>|null $catalog model ids the cached catalog lists; null leaves the catalog transient expired,
 *   so the catalog is discovered from `$provider` (its models() is called) and the provider filter fires twice
 */
function pipelineWith(mixed $provider, array $settings = [], array $contexts = [], ?array $catalog = ['llama3.2']): object
{
    $h = new class {
        public Pipeline $pipeline;
        /** @var list<array{0: string, 1: int|string, 2: mixed}> */
        public array $writes = [];
        public ?string $model = null;
        public object $post;
        /** @var array<string, mixed> */
        public array $transients = [];
    };
    $h->post = conversationChatPost();
    if ($catalog !== null) {
        $h->transients[ModelCatalog::TRANSIENT] = array_map(static fn(string $id): array => ['id' => $id, 'label' => $id], $catalog);
    }
    Functions\when('current_time')->justReturn(1_725_000_000);
    Functions\when('wp_generate_uuid4')->justReturn('uuid');
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_trim_words')->alias(static fn(string $text): string => $text);
    Functions\when('get_post')->alias(static fn(int $id): ?object => $id === (int) $h->post->ID ? $h->post : null);
    Functions\when('wp_insert_post')->alias(static function (array $post) use ($h): int {
        $h->writes[] = ['wp_insert_post', $post['post_type'], $post];
        return $post['post_type'] === ConversationStore::POST_TYPE ? 42 : 9;
    });
    Functions\when('update_post_meta')->alias(static function (int $id, string $key, mixed $value) use ($h): bool {
        $h->writes[] = ['update_post_meta', $key, $value];
        return true;
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
    $store = new Store($settings + ['models.default' => 'llama3.2']);
    $factory = new Factory($store);
    $meter = new UsageMeter($store);
    $h->pipeline = new Pipeline(
        $store,
        $factory,
        new ModelCatalog($factory),
        new ConversationStore($store),
        $meter,
        new CapPolicy($store, $meter),
        new Collector([collectorSource('test', $contexts)]),
    );
    return $h;
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
