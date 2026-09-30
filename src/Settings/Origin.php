<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

/**
 * The one answer to "is this URL still the server a stored secret was set for?", shared by the two
 * rules that keep a secret from following its URL somewhere else: an MCP server's header value
 * (Mcp\ServerSettings::clearedByMove()) and the provider API key
 * (Schema::providerKeyClearedByMove()). A write that moves the URL to another origin and sends the
 * secret back as "keep" loses it; a path or query change is not a move.
 *
 * @since 0.6.1
 */
final class Origin
{
    /**
     * Whether `$to` is another origin than `$from`: scheme, host or port. Scheme and host are
     * compared lowercase and the host without the dots a name may end in, as Mcp\Egress reads it;
     * a port left out is the scheme's own (443 for https, 80 for anything else), so `:443` written
     * out on https is the same origin. A `$from` that does not parse has no origin, and every
     * `$to` is another one.
     */
    public static function moved(string $from, string $to): bool
    {
        return self::of($from) === null || self::of($from) !== self::of($to);
    }

    /** `scheme://host:port` of `$url`, normalised as moved() says, or null when it has no scheme or host. */
    private static function of(string $url): ?string
    {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) wp_parse_url($url, PHP_URL_HOST), '.'));
        if ($scheme === '' || $host === '') {
            return null;
        }
        $port = wp_parse_url($url, PHP_URL_PORT);
        return $scheme . '://' . $host . ':' . (is_int($port) ? $port : ($scheme === 'https' ? 443 : 80));
    }
}
