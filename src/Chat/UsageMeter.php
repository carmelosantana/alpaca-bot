<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

/**
 * Records one usage receipt per model response as a `chat_log` post (0.4's post type, so the
 * upgrade keeps existing rows), and answers "how many tokens this calendar month" for the caps.
 *
 * Months are UTC calendar months. Totals are cached in a transient per (user or site, month);
 * record() clears both so a cap check never trusts a stale figure after a response lands.
 *
 * Note the reach of `privacy.usage_log`: with it off, record() writes no post, and since the
 * month total is the sum of those posts, nothing accumulates for CapPolicy to compare against.
 * The `alpaca_bot/usage/recorded` action still fires with the full receipt either way.
 */
final class UsageMeter
{
    public const POST_TYPE = 'chat_log';

    /** Seconds a month total stays cached; record() invalidates it sooner. */
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
     * Writes the receipt (unless `privacy.usage_log` is off), clears the user's and the site's
     * month cache, and fires `alpaca_bot/usage/recorded` with the receipt array.
     *
     * Negative counts (a provider reporting -1 for "unknown") are stored as 0 so they can never
     * lower a month total.
     *
     * @return int post id, or 0 when logging is off or the insert failed
     */
    public function record(int $userId, string $model, int $promptTokens, int $completionTokens, int $durationMs, int $conversationId = 0): int
    {
        $now = (int) current_time('timestamp', true);
        $promptTokens = max(0, $promptTokens);
        $completionTokens = max(0, $completionTokens);
        $durationMs = max(0, $durationMs);
        $total = $promptTokens + $completionTokens;
        $logId = 0;
        if ($this->store->get('privacy.usage_log')) {
            $id = wp_insert_post([
                'post_type' => self::POST_TYPE,
                'post_status' => 'private',
                'post_author' => $userId,
                'post_title' => sprintf('%s · %d tokens', $model, $total),
                'meta_input' => [
                    'model' => $model,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $total,
                    'duration_ms' => $durationMs,
                    'conversation_id' => $conversationId,
                ],
            ], true);
            $logId = is_int($id) ? $id : 0;
        }
        delete_transient($this->key($userId, $now));
        delete_transient($this->key(null, $now));
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
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['tokens'], $cached['requests'])) {
            return ['tokens' => (int) $cached['tokens'], 'requests' => (int) $cached['requests'], 'month' => $month];
        }
        $query = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'numberposts' => -1,
            'fields' => 'ids',
            'date_query' => [['after' => $month . '-01 00:00:00', 'inclusive' => true, 'column' => 'post_date_gmt']],
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
        set_transient($key, $summary, self::TTL);
        return $summary + ['month' => $month];
    }

    private function key(?int $userId, int $now): string
    {
        return 'alpaca_bot_usage_' . ($userId ?? 'site') . '_' . gmdate('Y-m', $now);
    }
}
