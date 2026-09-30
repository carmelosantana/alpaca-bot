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
 * handle's DNS cache with `host:port:addr[,addr]...`: cURL connects to one of those addresses
 * and to no other, and still speaks to the host, so the Host header, TLS SNI and certificate
 * verification all use the name in the URL.
 *
 * The entry is applied to whatever handle the hook hands over, with no check that it belongs
 * to the request it was made for, and that is safe rather than careless: an entry for
 * `host:port` changes nothing for a request to any other host or port, and pins a request to
 * the same one to the addresses that were checked. Matching on the URL instead would be the
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
    /**
     * libcurl 7.59.0, the release its changelog gives for reading more than one address from a
     * CURLOPT_RESOLVE entry, in the form curl_version()['version_number'] reports. 8.5.0 was
     * checked against directly and reads two; the 7.59.0 floor is the changelog's, not this
     * suite's, and it is the safe direction to be wrong in -- a floor set too high pins one
     * address on a build that could have taken two, which costs reachability and not the pin.
     */
    private const MULTI_ADDRESS = 0x073B00;

    /**
     * The CURLOPT_RESOLVE entry: `host:port:addr[,addr]...`, every address AddressPin checked,
     * comma-joined in the order it handed them over. The comma-separated form is the option's
     * own (`man curl`: `--resolve <[+]host:port:addr[,addr]...>`), and cURL chooses among those
     * addresses as it would among a name's own answers, which is what keeps a dual-stack host
     * reachable from a server that can route only one family (AddressPin says why that matters).
     */
    public readonly string $entry;

    /**
     * `$ips` is non-empty by type rather than by argument: an empty list would build `host:port:`,
     * which is an entry libcurl cannot parse and so the unpinned-request case this class exists
     * to prevent. This parameter is where that is enforced -- PHPStan refuses an empty list here
     * and proves the one call site (WebFetchToolkit::pin()) hands over a non-empty one --
     * and AddressPin::resolve() declares non-empty-list so a reader of either sees the same fact.
     * Which of them go into the entry is carried()'s.
     *
     * @param non-empty-list<string>                 $ips     every address the check passed, AddressPin's order
     * @param null|\Closure(mixed, int, mixed): bool $setopt  curl_setopt() by default; a test hands in a recorder
     * @param null|\Closure(): int                   $version libcurl's version_number by default, 0 where the extension is absent; a test hands in its own
     */
    public function __construct(string $host, int $port, array $ips, private ?\Closure $setopt = null, ?\Closure $version = null)
    {
        $this->entry = $host . ':' . $port . ':' . implode(',', self::carried($ips, $version));
    }

    /**
     * The addresses a CURLOPT_RESOLVE entry can carry on this libcurl: every one of `$ips` on a
     * libcurl that reads the comma form, the first alone below that. CurlPin's entry is built from
     * this, and so is the `resolve` value Mcp\Egress hands Symfony's Curl client, which writes it
     * into the same option (Egress says how).
     *
     * Why libcurl's version is read rather than assumed. The comma form is younger than the
     * option, so a build that predates it reads `1.2.3.4,::1` as one address it cannot parse,
     * and an entry libcurl cannot parse is an entry it may drop -- a dropped entry being a
     * request that resolves the name for itself, unpinned, which is the one thing a pin exists
     * to prevent. 8.5.0 refuses such a request instead ("Couldn't parse CURLOPT_RESOLVE
     * entry", connecting to nothing), and that is the half of this class's fail-closed claim
     * that a single address has always rested on; which way a build older than the comma form
     * answers is not something this code can find out from inside a request, and guessing it is
     * not a guess to make about a pin. So below the floor the entry carries the first address
     * alone -- 0.5's shape exactly, with 0.5's cost, an IPv6-only server that cannot reach a
     * dual-stack host -- and the pin holds either way. Reachability is what an old libcurl gives
     * up here; the pin never is.
     *
     * The `function_exists()` around curl_version() is load-bearing today and not a nod to old
     * builds: WebFetchToolkit::fetch() builds the pin (`:163`) before it asks curlCarries()
     * whether cURL would carry the request at all (`:169`), so on a server with no ext-curl --
     * a configuration the plugin supports and answers with a refusal in the user's own words
     * (`:170`) -- the constructor runs this first. An unguarded curl_version() would be a fatal
     * Error there, reached before that refusal could ever be returned.
     *
     * @param list<string>         $ips     every address the check passed, in the check's order
     * @param null|\Closure(): int $version libcurl's version_number by default, 0 where the extension is absent; a test hands in its own
     * @return list<string> `$ips`, or its first address alone; empty only when `$ips` is
     */
    public static function carried(array $ips, ?\Closure $version = null): array
    {
        $version ??= static function (): int {
            $info = function_exists('curl_version') ? curl_version() : false;
            return is_array($info) ? (int) ($info['version_number'] ?? 0) : 0;
        };
        return $version() >= self::MULTI_ADDRESS ? $ips : array_slice($ips, 0, 1);
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
