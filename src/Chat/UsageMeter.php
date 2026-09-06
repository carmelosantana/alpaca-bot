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
 */
final class UsageMeter
{
    public const POST_TYPE = 'chat_log';

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
        $id = wp_insert_post($post, true);
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
}
