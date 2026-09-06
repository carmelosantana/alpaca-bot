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
        // Unclamped, a limit of 0 or less refuses every request: the count is at least 1 after
        // this hit. That is the outcome of a filter that forgot to return (null casts to 0) or
        // returned false, and it would take the route down silently. The floor is one a minute,
        // so the route stays reachable and the mistake shows up as a 429 rather than an outage.
        // An operator who means "off" removes the route or returns a capability nobody holds
        // from the capability filter; a limit is not the tool for that.
        $limit = max(1, (int) apply_filters('alpaca_bot/rate_limit', $this->perMinute, $userId, $bucket));
        $subject = $userId > 0 ? (string) $userId : 'ip_' . self::client();
        $key = sprintf('alpaca_bot_rl_%s_%s_%s', $bucket, $subject, gmdate('YmdHi', $now));
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
     * The anonymous bucket subject: wp_hash() of REMOTE_ADDR, or 'unknown' when there is no
     * address that parses as one. The IPv4 space is small enough to reverse a plain digest by
     * brute force, which is why it is wp_hash() (an HMAC over the site's salts) and not md5().
     */
    private static function client(): string
    {
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        return is_string($ip) ? wp_hash($ip) : 'unknown';
    }
}
