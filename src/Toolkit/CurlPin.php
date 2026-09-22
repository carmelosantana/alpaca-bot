<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

/**
 * One request's DNS pin for cURL: the `http_api_curl` callback web_fetch hooks for exactly one
 * wp_safe_remote_get() call and unhooks in a `finally`.
 *
 * Core fires `http_api_curl` from the Requests cURL transport's `curl.before_send`, with the
 * request's handle, after the handle is set up and before it runs (WP 7.1
 * class-wp-http-requests-hooks.php:56-58; Requests/src/Transport/Curl.php:172-174, with
 * CURLOPT_URL set at :447), and every request gets a fresh handle (Requests.php:268-276,
 * Curl.php:102-105), so the entry set here dies with its request. CURLOPT_RESOLVE seeds that
 * handle's DNS cache with `host:port:address`: cURL connects to the address and still speaks to
 * the host, so the Host header, TLS SNI and certificate verification all use the name in the URL.
 *
 * The entry is applied to whatever handle the hook hands over, with no check that it belongs
 * to the request it was made for, and that is safe rather than careless: an entry for
 * `host:port` changes nothing for a request to any other host or port, and pins a request to
 * the same one to the address that was checked. Matching on the URL instead would be the
 * fragile choice, since core rewrites the URL (wp_kses_bad_protocol(), class-wp-http.php:283-289)
 * before it builds the hook's arguments (`:345`).
 *
 * A handle the option cannot be set on fails the request: the exception is a Requests one,
 * which WP_Http::request() catches and returns as a WP_Error (class-wp-http.php:416, :424-425),
 * so an unpinned connection is never the fallback. What this cannot fail closed on is a request
 * that never fires the hook at all: a site whose Requests picks the Fsockopen transport instead
 * (Requests.php:141-144) never calls this. WebFetchToolkit asks before each hop and refuses a
 * fetch this server would send that way, which is not the same as every such request -- its
 * curlCarries() names the listener that can still change the transport after the question is
 * answered -- so the gap is deferred to there rather than settled here. An IPv6 address goes in unbracketed, as Symfony HttpClient
 * writes the same option (CurlHttpClient.php:199).
 *
 * @since 0.6.0
 */
final class CurlPin
{
    /** The CURLOPT_RESOLVE entry: `host:port:address`. */
    public readonly string $entry;

    /**
     * @param null|\Closure(mixed, int, mixed): bool $setopt curl_setopt() by default; a test hands in a recorder
     */
    public function __construct(string $host, int $port, string $ip, private ?\Closure $setopt = null)
    {
        $this->entry = $host . ':' . $port . ':' . $ip;
    }

    /** The `http_api_curl` callback (accepted args: 1, the handle). */
    public function __invoke(mixed $handle): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- This is a callback on core's own http_api_curl action, whose whole purpose is setting options on the HTTP API's cURL handle; there is no WordPress wrapper for CURLOPT_RESOLVE.
        $setopt = $this->setopt ?? static fn(mixed $h, int $option, mixed $value): bool => curl_setopt($h, $option, $value);
        if (!$handle instanceof \CurlHandle || !$setopt($handle, CURLOPT_RESOLVE, [$this->entry])) {
            throw new \WpOrg\Requests\Exception(__('The request could not be held to the address that was checked.', 'alpaca-bot'), 'alpaca_bot.pin_failed');
        }
    }
}
