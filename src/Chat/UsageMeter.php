<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

/**
 * Records one usage receipt per model response as a `chat_log` post (0.4's post type, so the
 * upgrade keeps existing rows), and answers "how many tokens this calendar month" for the caps.
 *
 * Months are UTC calendar months. Totals are cached in a transient per (user or site, month);
 * record() adds each receipt to the cached figures in place, so the enforcement path in front
 * of every provider call is normally two transient reads, not a scan of the month's rows.
 *
 * `privacy.usage_log` decides what the row *says*, never whether it exists: with the toggle
 * off the row is numbers only (no model, no conversation id), and it still counts toward the
 * caps. A privacy toggle that switched off a cost control would fail permissive. The row keeps
 * its author either way, because the per-user cap needs it — so "usage log off" still leaves a
 * per-user trail of token counts and timings; settings copy should say so plainly.
 *
 * That trail is why the rows do not live forever: `privacy.usage_retention_days` (90 by
 * default, 0 keeps everything) bounds it, and cleanup() enforces it once a day from a cron event
 * (CLEANUP_HOOK). The event is (re)scheduled on init rather than on activation so a site that
 * already had the plugin when the setting arrived gets it without a reactivation; Plugin's
 * deactivation hook clears it. Only chat_log rows are ever deleted here, never a conversation.
 */
final class UsageMeter
{
    public const POST_TYPE = 'chat_log';

    /** The daily cron event that runs cleanup(); Plugin wires the listener and clears it on deactivation. */
    public const CLEANUP_HOOK = 'alpaca_bot/usage/cleanup';

    /**
     * Rows deleted per query, and the most queries one run makes. A daily run that deletes ten
     * thousand rows and stops is a run that always ends; whatever is left is tomorrow's.
     */
    private const CLEANUP_BATCH = 500;
    private const CLEANUP_BATCHES = 20;

    /**
     * Month caches expire at the top of the hour, whether written fresh or bumped, so the rows
     * are re-read at least hourly however busy the site is. That bounds the drift an in-place
     * bump can accumulate (two bumps racing on one key lose an increment).
     */
    private const TTL = 3600;

    public function __construct(private Store $store) {}

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'delete_with_user' => true,
            'supports' => ['title', 'author', 'custom-fields'],
            // Receipts are only ever written here, never from an editor screen or REST.
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
        ]);
    }

    /**
     * Writes the receipt row, folds it into the user's and the site's cached month totals, and
     * fires `alpaca_bot/usage/recorded` with the receipt array.
     *
     * With `privacy.usage_log` off the row carries the numbers only: `model` is '' and
     * `conversation_id` is 0. The action still carries the full receipt — it fires in-process
     * and stores nothing; a listener that persists it is the site owner's choice.
     *
     * A `$userId` below 1 is not written as `post_author`: WordPress treats 0 as unset and
     * falls back to the current user, so leaving it out makes that fallback explicit. Only the
     * site's cache is updated for such a row — the user it may land under is not known here.
     *
     * Negative counts (a provider reporting -1 for "unknown") are stored as 0 so they can never
     * lower a month total.
     *
     * @return int post id, or 0 when the insert failed
     */
    public function record(int $userId, string $model, int $promptTokens, int $completionTokens, int $durationMs, int $conversationId = 0): int
    {
        $now = (int) current_time('timestamp', true);
        $promptTokens = max(0, $promptTokens);
        $completionTokens = max(0, $completionTokens);
        $durationMs = max(0, $durationMs);
        $total = $promptTokens + $completionTokens;
        $detailed = (bool) $this->store->get('privacy.usage_log');
        $post = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => $detailed ? sprintf('%s · %d tokens', $model, $total) : sprintf('%d tokens', $total),
            'meta_input' => [
                'model' => $detailed ? $model : '',
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $total,
                'duration_ms' => $durationMs,
                'conversation_id' => $detailed ? $conversationId : 0,
            ],
        ];
        if ($userId >= 1) {
            $post['post_author'] = $userId;
        }
        // Slashed at the write and nowhere else: wp_insert_post() unslashes post_title, and it
        // passes meta_input through update_post_meta(), which unslashes too. `$post` itself stays
        // as it is — the receipt below is built from the same values, unslashed.
        $id = wp_insert_post(wp_slash($post), true);
        $logId = is_int($id) ? $id : 0;
        foreach ($this->keys($userId, $now) as $key) {
            if ($logId > 0) {
                $this->bump($key, $total, $now);
            } else {
                // Nothing landed; make the next check re-read the rows rather than trust a figure
                // from before the failure.
                delete_transient($key);
            }
        }
        $receipt = [
            'user_id' => $userId,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $total,
            'duration_ms' => $durationMs,
            'conversation_id' => $conversationId,
            'log_id' => $logId,
            'created' => $now,
        ];
        /**
         * Fires after a usage receipt has been written for a turn (finished or partial) and folded
         * into the user's and the site's cached month totals. The receipt is complete whatever the
         * `privacy.usage_log` setting says: with the log off the stored row has no model or
         * conversation id, but this action fires in-process and stores nothing, so a listener that
         * keeps the full receipt somewhere is the site owner's choice to make. `log_id` is the
         * receipt row's post id, 0 when the insert failed.
         *
         * @since 0.5.0
         * @param array<string, int|string> $receipt `user_id`, `model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `duration_ms`, `conversation_id`, `log_id`, `created` (a UTC timestamp)
         */
        do_action('alpaca_bot/usage/recorded', $receipt);
        return $logId;
    }

    /** Tokens used this UTC calendar month by the user, or site-wide when null. */
    public function monthTotal(?int $userId = null): int
    {
        return $this->monthSummary($userId)['tokens'];
    }

    /**
     * Tokens and request count this UTC calendar month, cached per (user or site, month).
     *
     * A user id below 1 reports zero without a query: WP_Query drops `author => 0`, so asking
     * for "user 0" would count every user's usage against nobody.
     *
     * @return array{tokens: int, requests: int, month: string}
     */
    public function monthSummary(?int $userId = null): array
    {
        $now = (int) current_time('timestamp', true);
        $month = gmdate('Y-m', $now);
        if ($userId !== null && $userId < 1) {
            return ['tokens' => 0, 'requests' => 0, 'month' => $month];
        }
        $key = $this->key($userId, $now);
        $cached = $this->cached($key);
        if ($cached !== null) {
            return $cached + ['month' => $month];
        }
        $nextMonth = gmmktime(0, 0, 0, (int) gmdate('n', $now) + 1, 1, (int) gmdate('Y', $now));
        $query = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'numberposts' => -1,
            'fields' => 'ids',
            'date_query' => [
                ['after' => $month . '-01 00:00:00', 'inclusive' => true, 'column' => 'post_date_gmt'],
                // Exclusive upper bound: a row stamped in the future must not count twice.
                ['before' => gmdate('Y-m-d H:i:s', $nextMonth), 'inclusive' => false, 'column' => 'post_date_gmt'],
            ],
        ];
        if ($userId !== null) {
            $query['author'] = $userId;
        }
        $ids = get_posts($query);
        // One query for the whole month's meta instead of one per receipt. `fields => 'ids'`
        // leaves WP_Query::get_posts() at both of its exits -- the cached one and the fresh
        // query -- before it reaches _prime_post_caches() (WP 7.1 class-wp-query.php:3289 and
        // :3339 return; :3311 is the prime, on the `else` branch that builds WP_Post objects), so
        // every get_post_meta() below would otherwise miss the cache and go to the database on
        // its own -- and this walk runs synchronously ahead of the model call on a capped site
        // whenever the hourly transient has expired. Measured on the integration site: 4
        // receipts cost 9 queries and 20 cost 25 without this line, and 6 each with it
        // (UsageSummaryQueriesTest holds the two counts equal).
        update_meta_cache('post', array_map('intval', $ids));
        $tokens = 0;
        foreach ($ids as $id) {
            $tokens += (int) get_post_meta((int) $id, 'total_tokens', true);
        }
        $summary = ['tokens' => $tokens, 'requests' => count($ids)];
        set_transient($key, $summary, $this->ttl($now));
        return $summary + ['month' => $month];
    }

    /** Adds one receipt to a cached month in place; with nothing valid to add to, clears the key. */
    private function bump(string $key, int $total, int $now): void
    {
        $cached = $this->cached($key);
        if ($cached === null) {
            delete_transient($key);
            return;
        }
        set_transient($key, ['tokens' => $cached['tokens'] + $total, 'requests' => $cached['requests'] + 1], $this->ttl($now));
    }

    /** @return array{tokens: int, requests: int}|null null for a miss or a malformed entry */
    private function cached(string $key): ?array
    {
        $cached = get_transient($key);
        if (!is_array($cached) || !isset($cached['tokens'], $cached['requests'])) {
            return null;
        }
        return ['tokens' => (int) $cached['tokens'], 'requests' => (int) $cached['requests']];
    }

    /**
     * The month cache keys a receipt for `$userId` belongs to: the user's (when there is one) and the site's.
     *
     * @return list<string>
     */
    private function keys(int $userId, int $now): array
    {
        $keys = [];
        if ($userId >= 1) {
            $keys[] = $this->key($userId, $now);
        }
        $keys[] = $this->key(null, $now);
        return $keys;
    }

    private function key(?int $userId, int $now): string
    {
        return 'alpaca_bot_usage_' . ($userId ?? 'site') . '_' . gmdate('Y-m', $now);
    }

    /** Seconds until the top of the hour: 1..3600, never 0 (which would mean "never expire"). */
    private function ttl(int $now): int
    {
        return self::TTL - $now % self::TTL;
    }

    /**
     * Makes sure the daily cleanup event exists. Runs on init on every request: wp_next_scheduled()
     * reads the (autoloaded) cron option, so the check costs nothing, and it is the one place that
     * reaches an already-installed site. The first run is due a day out, not at once: a site that
     * has just gained the setting should have a day to lower or zero it before anything is deleted.
     */
    public function scheduleCleanup(): void
    {
        if (wp_next_scheduled(self::CLEANUP_HOOK) === false) {
            wp_schedule_event((int) current_time('timestamp', true) + 86400, 'daily', self::CLEANUP_HOOK);
        }
    }

    /** Static so the deactivation hook needs no service to have been built first. */
    public static function unscheduleCleanup(): void
    {
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
    }

    /**
     * Deletes chat_log rows whose GMT date is older than `privacy.usage_retention_days` days, in
     * batches; 0 deletes nothing. The month caches are left alone: a window shorter than a month
     * makes this month's total drop by the deleted rows, and the hourly expiry re-reads the rows
     * within the hour, which is the drift record() already tolerates.
     *
     * The rows come back as objects, not ids, so the type is checked on each before it is
     * deleted: the query asks for chat_log, and this is the guard that a filter on the query, or
     * a bug in the one above, cannot turn into a deleted conversation. Permanently (`true`), and
     * out of the trash as well (every registered status by name, where 'any' would leave the
     * trash out): a trashed receipt is still a row of per-user numbers, which is what retention
     * exists to remove. A row a batch could not remove, whether the guard skipped it or
     * wp_delete_post() refused (a `delete_post` filter), is left out of the next query, so no
     * batch asks for the same rows twice.
     *
     * @return int rows deleted
     */
    public function cleanup(): int
    {
        $days = (int) $this->store->get('privacy.usage_retention_days');
        if ($days <= 0) {
            return 0;
        }
        $before = gmdate('Y-m-d H:i:s', (int) current_time('timestamp', true) - $days * 86400);
        $deleted = 0;
        $skip = [];
        for ($batch = 0; $batch < self::CLEANUP_BATCHES; $batch++) {
            $rows = get_posts([
                'post_type' => self::POST_TYPE,
                'post_status' => array_keys(get_post_stati()),
                'post__not_in' => $skip,
                'numberposts' => self::CLEANUP_BATCH,
                'orderby' => 'ID',
                'order' => 'ASC',
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'date_query' => [['before' => $before, 'inclusive' => false, 'column' => 'post_date_gmt']],
            ]);
            foreach ($rows as $row) {
                if ($row->post_type !== self::POST_TYPE || !wp_delete_post((int) $row->ID, true)) {
                    $skip[] = (int) $row->ID;
                    continue;
                }
                ++$deleted;
            }
            if (count($rows) < self::CLEANUP_BATCH) {
                break;
            }
        }
        return $deleted;
    }
}
