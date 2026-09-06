<?php

declare(strict_types=1);

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

// Brain Monkey 2.7: put once()/never() before with(), or expectations on one function collapse.
// gmdate() is a PHP internal and is not stubbed: with a fixed UTC timestamp it is deterministic.
//
// The clock is 1_725_000_000 = 2024-08-30 06:40:00 UTC throughout. Month caches expire at the
// top of the hour, so an entry written or bumped at 06:40 gets a 1200s TTL.

beforeEach(function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\when('current_time')->justReturn(1_725_000_000);
});

// ---------------------------------------------------------------- record()

it('records a chat_log post with token meta, bumps both cached month totals in place and fires the full receipt', function (): void {
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p, bool $wpError): bool => $wpError
        && $p['post_type'] === 'chat_log'
        && $p['post_status'] === 'private'
        && $p['post_author'] === 3
        && $p['post_title'] === 'm · 30 tokens'
        && $p['meta_input'] === ['model' => 'm', 'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30, 'duration_ms' => 1234, 'conversation_id' => 5])->andReturn(77);
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(['tokens' => 100, 'requests' => 4]);
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(['tokens' => 1000, 'requests' => 40]);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-08', ['tokens' => 130, 'requests' => 5], 1200)->andReturn(true);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_site_2024-08', ['tokens' => 1030, 'requests' => 41], 1200)->andReturn(true);
    Functions\expect('delete_transient')->never();
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
        'created' => 1_725_000_000,
    ]);
});

// A privacy toggle must not switch off a cost control: with the usage log off the row is still
// written and still counted, it just carries numbers only (no model, no conversation). The
// author stays, because the per-user cap needs it.
it('writes a numbers-only row that still counts when the usage log is off', function (): void {
    Functions\when('get_option')->justReturn(['privacy.usage_log' => false]);
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p, bool $wpError): bool => $wpError
        && $p['post_type'] === 'chat_log'
        && $p['post_status'] === 'private'
        && $p['post_author'] === 3
        && $p['post_title'] === '2 tokens'
        && $p['meta_input'] === ['model' => '', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2, 'duration_ms' => 1, 'conversation_id' => 0])->andReturn(79);
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(['tokens' => 8, 'requests' => 1]);
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(['tokens' => 8, 'requests' => 1]);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-08', ['tokens' => 10, 'requests' => 2], 1200)->andReturn(true);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_site_2024-08', ['tokens' => 10, 'requests' => 2], 1200)->andReturn(true);
    $receipt = null;
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(function (array $r) use (&$receipt): bool {
        $receipt = $r;
        return true;
    });
    expect((new UsageMeter(new Store()))->record(3, 'm', 1, 1, 1, 9))->toBe(79);
    // The in-process receipt is not the stored trail: it keeps the full detail for listeners.
    expect($receipt)->toBe([
        'user_id' => 3,
        'model' => 'm',
        'prompt_tokens' => 1,
        'completion_tokens' => 1,
        'total_tokens' => 2,
        'duration_ms' => 1,
        'conversation_id' => 9,
        'log_id' => 79,
        'created' => 1_725_000_000,
    ]);
});

it('clears both month caches, reports log_id 0 and still fires the receipt when the insert fails', function (): void {
    // Stands in for a WP_Error (not loaded here); the meter only asks is_int() of the result.
    Functions\when('wp_insert_post')->justReturn((object) ['errors' => ['db_insert_error' => ['Could not insert post into the database.']]]);
    Functions\expect('get_transient')->never();
    Functions\expect('set_transient')->never();
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(true);
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(true);
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(fn(array $r): bool => $r['log_id'] === 0 && $r['total_tokens'] === 30);
    expect((new UsageMeter(new Store()))->record(3, 'm', 10, 20, 1))->toBe(0);
});

// A provider that reports -1 for "unknown" must not lower a user's month total.
it('clamps negative token counts and durations to zero', function (): void {
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p): bool => $p['meta_input']['prompt_tokens'] === 0
        && $p['meta_input']['completion_tokens'] === 7
        && $p['meta_input']['total_tokens'] === 7
        && $p['meta_input']['duration_ms'] === 0)->andReturn(78);
    Functions\when('get_transient')->justReturn(false);
    Functions\when('delete_transient')->justReturn(true);
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(fn(array $r): bool => $r['prompt_tokens'] === 0 && $r['total_tokens'] === 7 && $r['duration_ms'] === 0);
    expect((new UsageMeter(new Store()))->record(3, 'm', -5, 7, -1))->toBe(78);
});

// With nothing cached there is nothing to bump: the key is cleared and the next read recomputes.
it('clears a month cache it cannot bump, under the month the record lands in', function (): void {
    Functions\when('wp_insert_post')->justReturn(80);
    Functions\when('current_time')->justReturn(gmmktime(0, 0, 0, 9, 1, 2024));
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-09')->andReturn(false);
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_site_2024-09')->andReturn(['tokens' => 5]); // malformed
    Functions\expect('set_transient')->never();
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_3_2024-09')->andReturn(true);
    Functions\expect('delete_transient')->once()->with('alpaca_bot_usage_site_2024-09')->andReturn(true);
    Actions\expectDone('alpaca_bot/usage/recorded')->once();
    (new UsageMeter(new Store()))->record(3, 'm', 1, 1, 1);
});

// A bumped entry keeps the expiry it would have had: the DB is re-read at least once an hour
// however busy the site is, which bounds the drift a lost concurrent bump can cause.
it('bumps an entry to expire at the top of the hour, not an hour from now', function (): void {
    Functions\when('wp_insert_post')->justReturn(81);
    Functions\when('current_time')->justReturn(gmmktime(6, 59, 59, 8, 30, 2024));
    Functions\when('get_transient')->justReturn(['tokens' => 1, 'requests' => 1]);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-08', ['tokens' => 3, 'requests' => 2], 1)->andReturn(true);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_site_2024-08', ['tokens' => 3, 'requests' => 2], 1)->andReturn(true);
    Actions\expectDone('alpaca_bot/usage/recorded')->once();
    (new UsageMeter(new Store()))->record(3, 'm', 1, 1, 1);
});

// WordPress treats post_author 0 as "unset" and falls back to the current user, so passing it
// would leave the row under a user whose cache the meter never touched. Leaving it out makes
// the fallback explicit, and the only cache anonymous usage can honestly update is the site's.
it('records anonymous usage without an author and counts it against the site cache only', function (): void {
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p): bool => !array_key_exists('post_author', $p) && $p['meta_input']['total_tokens'] === 4)->andReturn(82);
    Functions\expect('get_transient')->never()->with('alpaca_bot_usage_0_2024-08');
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(['tokens' => 10, 'requests' => 1]);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_site_2024-08', ['tokens' => 14, 'requests' => 2], 1200)->andReturn(true);
    Functions\expect('delete_transient')->never();
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(fn(array $r): bool => $r['user_id'] === 0 && $r['log_id'] === 82);
    expect((new UsageMeter(new Store()))->record(0, 'm', 2, 2, 1))->toBe(82);
});

// ------------------------------------------------------------ monthTotal()

it('sums this month\'s tokens per user via a single query and caches it until the top of the hour', function (): void {
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(false);
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_log'
        && $q['post_status'] === 'private'
        && $q['author'] === 3
        && $q['fields'] === 'ids'
        && $q['numberposts'] === -1
        && $q['date_query'] === [
            ['after' => '2024-08-01 00:00:00', 'inclusive' => true, 'column' => 'post_date_gmt'],
            ['before' => '2024-09-01 00:00:00', 'inclusive' => false, 'column' => 'post_date_gmt'],
        ])->andReturn([1, 2]);
    Functions\expect('get_post_meta')->once()->with(1, 'total_tokens', true)->andReturn('30');
    Functions\expect('get_post_meta')->once()->with(2, 'total_tokens', true)->andReturn('12');
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-08', ['tokens' => 42, 'requests' => 2], 1200)->andReturn(true);
    expect((new UsageMeter(new Store()))->monthTotal(3))->toBe(42);
});

it('sums site-wide with no author clause under the site key', function (): void {
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(false);
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_log' && !array_key_exists('author', $q) && $q['fields'] === 'ids')->andReturn([1, 2, 3]);
    Functions\when('get_post_meta')->justReturn('5');
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_site_2024-08', ['tokens' => 15, 'requests' => 3], 1200)->andReturn(true);
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
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-08', ['tokens' => 0, 'requests' => 0], 1200)->andReturn(true);
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
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['date_query'][0]['after'] === '2024-09-01 00:00:00'
        && $q['date_query'][1]['before'] === '2024-10-01 00:00:00')->andReturn([]);
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-09', ['tokens' => 0, 'requests' => 0], 3600)->andReturn(true);
    expect((new UsageMeter(new Store()))->monthSummary(3))->toBe(['tokens' => 0, 'requests' => 0, 'month' => '2024-09']);
});

// Without an upper bound a row stamped in the future (clock skew, a hand-inserted row) counts
// against this month and again against its own.
it('bounds the query window at the first second of next month, across the year end', function (): void {
    Functions\when('current_time')->justReturn(gmmktime(12, 0, 0, 12, 15, 2024));
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_site_2024-12')->andReturn(false);
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['date_query'] === [
        ['after' => '2024-12-01 00:00:00', 'inclusive' => true, 'column' => 'post_date_gmt'],
        ['before' => '2025-01-01 00:00:00', 'inclusive' => false, 'column' => 'post_date_gmt'],
    ])->andReturn([]);
    Functions\when('set_transient')->justReturn(true);
    expect((new UsageMeter(new Store()))->monthTotal())->toBe(0);
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

// ---------------------------------------------------------------- retention

// The daily cleanup exists so a site that keeps the usage log on is not keeping a per-user
// trail of every request forever. It deletes chat_log rows only: a conversation is never its
// business, and the guard is on what get_posts() hands back, not only on what it was asked for.
it('schedules the daily cleanup once and clears it on request', function (): void {
    Functions\expect('wp_next_scheduled')->once()->with(UsageMeter::CLEANUP_HOOK)->andReturn(false);
    Functions\expect('wp_schedule_event')->once()->withArgs(fn(int $at, string $recurrence, string $hook): bool => $at >= 1_725_000_000 && $recurrence === 'daily' && $hook === 'alpaca_bot/usage/cleanup')->andReturn(true);
    (new UsageMeter(new Store()))->scheduleCleanup();

    Functions\expect('wp_next_scheduled')->once()->with(UsageMeter::CLEANUP_HOOK)->andReturn(1_725_086_400);
    Functions\expect('wp_schedule_event')->never();
    (new UsageMeter(new Store()))->scheduleCleanup();

    Functions\expect('wp_clear_scheduled_hook')->once()->with(UsageMeter::CLEANUP_HOOK)->andReturn(1);
    UsageMeter::unscheduleCleanup();
});

it('deletes nothing when retention is 0 (keep forever)', function (): void {
    Functions\when('get_option')->justReturn(['privacy.usage_retention_days' => 0]);
    Functions\expect('get_posts')->never();
    Functions\expect('wp_delete_post')->never();
    expect((new UsageMeter(new Store()))->cleanup())->toBe(0);
});

it('deletes chat_log rows older than the retention window, permanently, trashed ones included, and never anything else', function (): void {
    Functions\when('get_option')->justReturn(['privacy.usage_retention_days' => 90]);
    // Every registered status by name: 'any' would leave a trashed receipt out, and in the trash forever.
    Functions\when('get_post_stati')->justReturn(['publish' => 'publish', 'private' => 'private', 'trash' => 'trash', 'auto-draft' => 'auto-draft']);
    // 90 days before 2024-08-30 06:40:00 UTC.
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_log'
        && $q['post_status'] === ['publish', 'private', 'trash', 'auto-draft']
        && $q['post__not_in'] === []
        && $q['date_query'] === [['before' => '2024-06-01 06:40:00', 'inclusive' => false, 'column' => 'post_date_gmt']]
        && $q['numberposts'] === 500)->andReturn([
            (object) ['ID' => 5, 'post_type' => 'chat_log'],
            (object) ['ID' => 6, 'post_type' => 'chat_history'],
            (object) ['ID' => 7, 'post_type' => 'chat_log'],
        ]);
    Functions\expect('wp_delete_post')->once()->with(5, true)->andReturn((object) ['ID' => 5]);
    Functions\expect('wp_delete_post')->once()->with(7, true)->andReturn(false);
    Functions\expect('wp_delete_post')->never()->with(6, Mockery::any());
    expect((new UsageMeter(new Store()))->cleanup())->toBe(1);
});

// A row wp_delete_post() refuses (a `delete_post` filter, say) would come back in every batch
// and make a full batch of refusals repeat the same query until the batch limit; a row the
// type guard skips likewise. Both are left out of the next query, so every batch moves on.
it('moves past rows it could not delete instead of asking for them again', function (): void {
    Functions\when('get_option')->justReturn(['privacy.usage_retention_days' => 1]);
    Functions\when('get_post_stati')->justReturn(['private' => 'private']);
    $stuck = array_map(static fn(int $i): object => (object) ['ID' => $i, 'post_type' => $i === 500 ? 'chat_history' : 'chat_log'], range(1, 500));
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post__not_in'] === [])->andReturn($stuck);
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post__not_in'] === range(1, 500))->andReturn([]);
    Functions\expect('wp_delete_post')->times(499)->andReturn(false);
    expect((new UsageMeter(new Store()))->cleanup())->toBe(0);
});

it('works through a backlog in batches and stops after a bounded number of them', function (): void {
    Functions\when('get_option')->justReturn(['privacy.usage_retention_days' => 1]);
    Functions\when('get_post_stati')->justReturn(['private' => 'private']);
    $batch = array_map(static fn(int $i): object => (object) ['ID' => $i, 'post_type' => 'chat_log'], range(1, 500));
    // Every batch comes back full: the run must still end.
    Functions\expect('get_posts')->times(20)->andReturn($batch);
    Functions\when('wp_delete_post')->alias(static fn(int $id): object => (object) ['ID' => $id]);
    expect((new UsageMeter(new Store()))->cleanup())->toBe(10_000);
});
