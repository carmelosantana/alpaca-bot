<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

/**
 * A fixed-window counter: N hits per user per UTC calendar minute, kept in a transient. Filter
 * `alpaca_bot/rate_limit` (int perMinute, int userId, string bucket) sets the limit per call.
 *
 * Calendar minutes rather than a sliding window because the counter must survive across PHP
 * processes and the only shared store a plain WordPress is guaranteed to have is options or
 * object cache: one integer per minute, incremented in place, is the cheapest thing that works
 * on both. The cost is a burst of up to 2N across a minute boundary, which is fine for a limiter
 * whose job is to stop runaway clients and scripts, not to meter spend (the caps do that).
 *
 * The window key is derived from the GMT clock, not the site's local time, so a DST change
 * cannot make two different minutes share a key or leave one minute unreachable.
 */
final class RateLimit
{
    /** The window is one minute; the transient outlives it so a late hit still sees the count, then core reaps it. */
    private const TTL = 120;

    public function __construct(private int $perMinute = 30) {}

    /**
     * Records one hit and reports whether it was within the limit.
     *
     * The hit is counted even when it is refused: a client hammering past the limit does not
     * get its bucket refilled by being refused. `retry_after` is the number of seconds until the
     * window turns over (0 when allowed), which is what a Retry-After header wants.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function hit(int $userId, string $bucket = 'chat'): array
    {
        $now = (int) current_time('timestamp', true);
        // A filter that returns 0 or less would either disable the limiter or refuse everyone;
        // clamp to one so a broken filter degrades to "very strict", never to "off".
        $limit = max(1, (int) apply_filters('alpaca_bot/rate_limit', $this->perMinute, $userId, $bucket));
        $key = sprintf('alpaca_bot_rl_%s_%d_%s', $bucket, $userId, gmdate('YmdHi', $now));
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, self::TTL);
        $allowed = $count <= $limit;
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $limit - $count),
            'retry_after' => $allowed ? 0 : 60 - ($now % 60),
        ];
    }
}
