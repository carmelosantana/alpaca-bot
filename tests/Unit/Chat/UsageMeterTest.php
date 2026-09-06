<?php

declare(strict_types=1);

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

// Brain Monkey 2.7: put once()/never() before with(), or expectations on one function collapse.
// gmdate() is a PHP internal and is not stubbed: with a fixed UTC timestamp it is deterministic.

/** 2024-08-30 07:20:00 UTC. */
const AUG_30 = 1_725_000_000;

beforeEach(function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\when('current_time')->justReturn(AUG_30);
});

// ---------------------------------------------------------------- record()

it('records a chat_log post with token meta, clears both month caches and fires the full receipt', function (): void {
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p, bool $wpError): bool => $wpError
        && $p['post_type'] === 'chat_log'
        && $p['post_status'] === 'private'
        && $p['post_author'] === 3
        && $p['meta_input'] === ['model' => 'm', 'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30, 'duration_ms' => 1234, 'conversation_id' => 5])->andReturn(77);
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(true);
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(true);
    $receipt = null;
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(function (array $r) use (&$receipt): bool {
        $receipt = $r;
        return true;
    });
    expect((new UsageMeter(new Store()))->record(3, 'm', 10, 20, 1234, 5))->toBe(77);
    expect($receipt)->toBe([
        'user_id' => 3,
        'model' => 'm',
        'prompt_tokens' => 10,
        'completion_tokens' => 20,
        'total_tokens' => 30,
        'duration_ms' => 1234,
        'conversation_id' => 5,
        'log_id' => 77,
        'created' => AUG_30,
    ]);
});

// The brief's contract: no post when the log is off, but the receipt still fires and the month
// caches are still cleared. Note what this means for CapPolicy: monthTotal() sums chat_log
// posts, so with the log off nothing accumulates and a configured cap never trips.
it('skips the post but still fires the receipt and clears the caches when the usage log is off', function (): void {
    Functions\when('get_option')->justReturn(['privacy.usage_log' => false]);
    Functions\expect('wp_insert_post')->never();
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(true);
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(true);
    $receipt = null;
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(function (array $r) use (&$receipt): bool {
        $receipt = $r;
        return true;
    });
    expect((new UsageMeter(new Store()))->record(3, 'm', 1, 1, 1))->toBe(0);
    expect($receipt)->toBe([
        'user_id' => 3,
        'model' => 'm',
        'prompt_tokens' => 1,
        'completion_tokens' => 1,
        'total_tokens' => 2,
        'duration_ms' => 1,
        'conversation_id' => 0,
        'log_id' => 0,
        'created' => AUG_30,
    ]);
});

it('reports log_id 0 and still fires the receipt when the insert fails', function (): void {
    // Stands in for a WP_Error (not loaded here); the meter only asks is_int() of the result.
    Functions\when('wp_insert_post')->justReturn((object) ['errors' => ['db_insert_error' => ['Could not insert post into the database.']]]);
    Functions\when('delete_transient')->justReturn(true);
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(fn(array $r): bool => $r['log_id'] === 0 && $r['total_tokens'] === 30);
    expect((new UsageMeter(new Store()))->record(3, 'm', 10, 20, 1))->toBe(0);
});

// A provider that reports -1 for "unknown" must not lower a user's month total.
it('clamps negative token counts and durations to zero', function (): void {
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p): bool => $p['meta_input']['prompt_tokens'] === 0
        && $p['meta_input']['completion_tokens'] === 7
        && $p['meta_input']['total_tokens'] === 7
        && $p['meta_input']['duration_ms'] === 0)->andReturn(78);
    Functions\when('delete_transient')->justReturn(true);
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(fn(array $r): bool => $r['prompt_tokens'] === 0 && $r['total_tokens'] === 7 && $r['duration_ms'] === 0);
    expect((new UsageMeter(new Store()))->record(3, 'm', -5, 7, -1))->toBe(78);
});

// ------------------------------------------------------------ monthTotal()

it('sums this month\'s tokens per user via a single query and caches it for an hour', function (): void {
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(false);
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_log'
        && $q['post_status'] === 'private'
        && $q['author'] === 3
        && $q['fields'] === 'ids'
        && $q['numberposts'] === -1
        && $q['date_query'] === [['after' => '2024-08-01 00:00:00', 'inclusive' => true, 'column' => 'post_date_gmt']])->andReturn([1, 2]);
    Functions\expect('get_post_meta')->once()->with(1, 'total_tokens', true)->andReturn('30');
    Functions\expect('get_post_meta')->once()->with(2, 'total_tokens', true)->andReturn('12');
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-08', ['tokens' => 42, 'requests' => 2], 3600)->andReturn(true);
    expect((new UsageMeter(new Store()))->monthTotal(3))->toBe(42);
});

it('sums site-wide with no author clause under the site key', function (): void {
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(false);
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_log' && !array_key_exists('author', $q) && $q['fields'] === 'ids')->andReturn([1, 2, 3]);
    Functions\when('get_post_meta')->justReturn('5');
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_site_2024-08', ['tokens' => 15, 'requests' => 3], 3600)->andReturn(true);
    expect((new UsageMeter(new Store()))->monthSummary())->toBe(['tokens' => 15, 'requests' => 3, 'month' => '2024-08']);
});

it('serves a cached month without querying and stamps the month on the summary', function (): void {
    Functions\expect('get_transient')->twice()->with('alpaca_bot_usage_3_2024-08')->andReturn(['tokens' => 42, 'requests' => 2]);
    Functions\expect('get_posts')->never();
    Functions\expect('set_transient')->never();
    $meter = new UsageMeter(new Store());
    expect($meter->monthTotal(3))->toBe(42)
        ->and($meter->monthSummary(3))->toBe(['tokens' => 42, 'requests' => 2, 'month' => '2024-08']);
});

it('treats a malformed cache entry as a miss', function (): void {
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(['tokens' => 42]);
    Functions\expect('get_posts')->once()->andReturn([]);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-08', ['tokens' => 0, 'requests' => 0], 3600)->andReturn(true);
    expect((new UsageMeter(new Store()))->monthSummary(3))->toBe(['tokens' => 0, 'requests' => 0, 'month' => '2024-08']);
});

it('reports zero for a user with no history this month', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\expect('get_posts')->once()->andReturn([]);
    Functions\expect('get_post_meta')->never();
    Functions\when('set_transient')->justReturn(true);
    expect((new UsageMeter(new Store()))->monthTotal(9))->toBe(0);
});

// The month is the UTC calendar month: at 2024-09-01 00:00:00 UTC the key, the query window and
// the summary all roll over, so an August cache (an hour's TTL can straddle midnight) is never read.
it('rolls the cache key and the query window over at the UTC month boundary', function (): void {
    Functions\when('current_time')->justReturn(gmmktime(0, 0, 0, 9, 1, 2024));
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-09')->andReturn(false);
    Functions\expect('get_transient')->never()->with('alpaca_bot_usage_3_2024-08');
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['date_query'][0]['after'] === '2024-09-01 00:00:00')->andReturn([]);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-09', ['tokens' => 0, 'requests' => 0], 3600)->andReturn(true);
    expect((new UsageMeter(new Store()))->monthSummary(3))->toBe(['tokens' => 0, 'requests' => 0, 'month' => '2024-09']);
});

it('invalidates under the month the record lands in, not the month of an earlier read', function (): void {
    Functions\when('wp_insert_post')->justReturn(80);
    Functions\when('current_time')->justReturn(gmmktime(0, 0, 0, 9, 1, 2024));
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_3_2024-09')->andReturn(true);
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_site_2024-09')->andReturn(true);
    Actions\expectDone('alpaca_bot/usage/recorded')->once();
    (new UsageMeter(new Store()))->record(3, 'm', 1, 1, 1);
});

// WP_Query ignores author => 0, so a query for "user 0" would count every user's usage against
// nobody. Anonymous usage is not attributable to a user; the site cap is what covers it.
it('reports zero for nobody without querying or caching', function (): void {
    Functions\expect('get_transient')->never();
    Functions\expect('get_posts')->never();
    Functions\expect('set_transient')->never();
    $meter = new UsageMeter(new Store());
    expect($meter->monthTotal(0))->toBe(0)
        ->and($meter->monthSummary(-1))->toBe(['tokens' => 0, 'requests' => 0, 'month' => '2024-08']);
});

// ------------------------------------------------------- registerPostType()

it('registers a private, non-searchable chat_log type with no UI that dies with its user and cannot be created from an editor', function (): void {
    Functions\expect('register_post_type')->once()->withArgs(function (string $type, array $args): bool {
        return $type === UsageMeter::POST_TYPE
            && $type === 'chat_log'
            && $args['public'] === false
            && $args['show_ui'] === false
            && $args['show_in_rest'] === false
            && $args['exclude_from_search'] === true
            && $args['delete_with_user'] === true
            && $args['supports'] === ['title', 'author', 'custom-fields']
            && $args['capabilities'] === ['create_posts' => 'do_not_allow']
            && $args['map_meta_cap'] === true;
    });
    (new UsageMeter(new Store()))->registerPostType();
});
