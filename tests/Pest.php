<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapPolicy;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;
use Brain\Monkey;
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
