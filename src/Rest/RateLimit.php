<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

/**
 * A fixed-window counter: PER_MINUTE hits per user per UTC calendar minute, kept in a transient.
 * Filter `alpaca_bot/rate_limit` (int $perMinute, int $userId, string $bucket) sets the limit
 * per call, and is the only way the limit changes. Every surface that spends provider tokens
 * on a request records a hit here, in the one `chat` bucket: the REST chat and stream routes
 * (Controller), the chat and summarize abilities (Abilities\Register) and the shortcodes
 * (Shortcodes\Chat), so one person on any of them is one person to the counter.
 *
 * Calendar minutes rather than a sliding window because the counter must survive across PHP
 * processes and the only shared store a plain WordPress is guaranteed to have is options or
 * object cache: one integer per minute, incremented in place, is the cheapest thing that works
 * on both. The cost is a burst of up to 2N across a minute boundary, which is fine for a limiter
 * whose job is to stop runaway clients and scripts, not to meter spend (the caps do that).
 *
 * The window key is derived from the GMT clock, not the site's local time, so a DST change
 * cannot make two different minutes share a key or leave one minute unreachable.
 *
 * A hit with no user (user 0: a route a site has opened to visitors through its capability
 * filter) is keyed by the client address instead, salted and hashed through wp_hash(), so
 * visitors do not all share one bucket that a single script could empty, and no address is
 * written into an option name. Only REMOTE_ADDR is read: a forwarding header is whatever the
 * client chose to send, and honouring it would let one client pick its bucket. A site behind a
 * proxy sets REMOTE_ADDR from its trusted header at the server or in wp-config.php, the same
 * place core expects it fixed. With no valid address (a non-HTTP SAPI) every anonymous hit
 * shares one bucket, which is the pre-address behaviour and is as strict as it can be.
 */
final class RateLimit
{
    /** The window is one minute; the transient outlives it so a late hit still sees the count, then core reaps it. */
    private const TTL = 120;

    /**
     * The limit before the filter sees it. A constant rather than a constructor argument
     * because there is one limiter and the filter is the knob: it receives the user and the
     * bucket, so a site that wants a different limit per route or per role says so there. A
     * settable default would be a second way to do the same thing that nothing constructs.
     */
    public const PER_MINUTE = 30;

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
        // Unclamped, a limit of 0 or less refuses every request: the count is at least 1 after
        // this hit. That is the outcome of a filter that forgot to return (null casts to 0) or
        // returned false, and it would take the route down silently. The floor is one a minute,
        // so the route stays reachable and the mistake shows up as a 429 rather than an outage.
        // An operator who means "off" removes the route or returns a capability nobody holds
        // from the capability filter; a limit is not the tool for that.
        /**
         * Filters the per-minute request limit for one hit, the only way the limit changes. The
         * bucket names the surface that shares the counter: `chat` covers the REST chat and stream
         * routes and, because Abilities\Register and Shortcodes\Chat count against the same bucket,
         * the chat and summarize abilities and a generation (or the agent shim's fetch) for a
         * shortcode on a page; set a limit per bucket, per role, or per user. Anything below 1 is
         * raised to 1 rather than closing the route: a filter that forgot to return should show up
         * as a 429, not an outage.
         *
         * @since 0.5.0
         * @param int    $perMinute the default, RateLimit::PER_MINUTE (30)
         * @param int    $userId    the user, 0 for a visitor (then keyed by client address)
         * @param string $bucket    which counter the hit lands in, `chat`
         */
        $limit = max(1, (int) apply_filters('alpaca_bot/rate_limit', self::PER_MINUTE, $userId, $bucket));
        $key = sprintf('alpaca_bot_rl_%s_%s_%s', $bucket, self::subject($userId), gmdate('YmdHi', $now));
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, self::TTL);
        $allowed = $count <= $limit;
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $limit - $count),
            'retry_after' => $allowed ? 0 : 60 - ($now % 60),
        ];
    }

    /**
     * Who a per-person counter is kept for: the user id, or the hashed client address for a
     * request with no user. Public because StreamBudget keys its own per-person counter the same
     * way and the two must agree on what "one person" means — a route a site has opened to
     * visitors through its capability filter is every visitor to get_current_user_id(), and one
     * shared bucket there would let one script spend everyone's allowance of either counter.
     */
    public static function subject(int $userId): string
    {
        return $userId > 0 ? (string) $userId : 'ip_' . self::client();
    }

    /**
     * The anonymous bucket subject: wp_hash() of REMOTE_ADDR, or 'unknown' when there is no
     * address that parses as one. The IPv4 space is small enough to reverse a plain digest by
     * brute force, which is why it is wp_hash() (an HMAC over the site's salts) and not md5().
     */
    private static function client(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_magic_quotes() slashes $_GET, $_POST and $_COOKIE, never REMOTE_ADDR, so there is nothing to unslash; FILTER_VALIDATE_IP is the sanitisation and rejects anything that is not a bare address.
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        return is_string($ip) ? wp_hash($ip) : 'unknown';
    }
}
