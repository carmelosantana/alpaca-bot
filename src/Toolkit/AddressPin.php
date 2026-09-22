<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

/**
 * The plugin's one address rule: resolve a name once, both families, hold every answer to
 * SpecialPurposeAddress, and hand back every answer that passed, for the caller to pin as many
 * of as its transport will take. web_fetch asks it and pins them into cURL in one
 * CURLOPT_RESOLVE entry (CurlPin, through core's `http_api_curl`, all of them on a libcurl that
 * reads more than one and the first alone below that, which CurlPin states); Mcp\Egress asks it
 * and pins the first into Symfony HttpClient's `resolve` option, which maps a host to one
 * address and no more (Mcp\Egress says what that costs there; Mcp\PinnedHttpClient says what
 * else it forces). That is why it is a class of its own rather than a method of
 * WebFetchToolkit: one rule, whichever transport asks it.
 *
 * Why the addresses are returned and not just judged: a check that only answers yes or no leaves
 * the transport to resolve the name again when it connects, and a name under someone else's
 * control with a short TTL can answer the check with a public address and the connection with
 * 127.0.0.1 or the cloud metadata address (DNS rebinding; audit H-1). The caller connects to an
 * address this returns and to no other, so no answer but a checked one is ever used. The converse
 * does not hold and is not claimed: Mcp\Egress connects to one of them, and so does CurlPin on a
 * libcurl that reads one address from an entry. The containment runs used-inside-checked, which
 * is the direction the rebinding case turns on.
 *
 * Every answer has to pass, not just one of them. A name that answers with a private address
 * among public ones is a name pointed at something private, and refusing it costs a legitimate
 * host nothing it had. A special-purpose answer is let through only when core's
 * `http_request_host_is_external` filter opts the host in, so that opt-in means the same thing
 * here as for any plugin; what it costs is what core charges for it, since a site that sets it
 * has already told WordPress that host is reachable.
 *
 * All of them are handed back, in lookup()'s order, because a pin is used *instead of* a lookup
 * and not alongside one: cURL connects to what is in the entry and to nothing else ("prevent the
 * otherwise normally resolved address to be used", `man curl` on `--resolve`), and Symfony's
 * `resolve` the same. Handing back only the first meant handing back an A record whenever there
 * was one, since lookup() lists A before AAAA and gethostbynamel() answers with A records
 * whether or not this server can route them; an IPv6-only server was then pinned to an
 * unroutable IPv4 address and could not reach a dual-stack host at all, where before the pin it
 * could, because the transport resolved for itself and chose the AAAA (Kanboard #4483). Handing
 * back all of them leaves the family choice where it was, with the transport, over addresses
 * every one of which passed the table -- so it admits nothing the check had not already
 * approved; what it stops is discarding what the check approved.
 *
 * Where a name was looked up, the list is the resolver's, unedited: a name that answers with the
 * same address twice is handed it back twice rather than collapsed, so nothing is dropped quietly
 * here. (An IP literal is not looked up at all and is its own one-element list.) A repeat costs
 * a caller a repeated connect attempt at worst, which is less than a rule about when an address
 * may disappear from an answer the check already passed.
 *
 * What this does not cover is the same as SpecialPurposeAddress: a public address the site's
 * own network routes somewhere private. And what it covers only holds while the caller really
 * connects to a returned address; a proxy that is sent the name resolves it again, and a
 * transport that never sees the pin never uses it. What a caller does about those two is the
 * caller's to state: WebFetchToolkit for web_fetch, Mcp\PinnedHttpClient for an MCP server.
 *
 * @since 0.6.0
 */
final class AddressPin
{
    /**
     * Every address to connect to for `$host`, each one checked, in lookup()'s order.
     *
     * @param string $host a host name or an IP literal (brackets allowed), as wp_parse_url() gives it
     * @param string $url the URL the host came from, for core's `http_request_host_is_external` filter; '' when there is none
     * @param null|\Closure(string): list<string> $lookup every address the name answers with; lookup() over the system resolver by default, a test hands in its own
     * @return non-empty-list<string> a name with no answer is refused rather than returned as []; CurlPin's $ips requires non-empty, and PHPStan holds the call site to it (an entry built from [] would be `host:port:`, which libcurl cannot parse)
     * @throws AddressRefused with a translated message naming the host
     */
    public static function resolve(string $host, string $url = '', ?\Closure $lookup = null): array
    {
        $host = strtolower(trim($host, '.'));
        $literal = trim($host, '[]');
        if ($literal === '') {
            throw new AddressRefused(__('The address has no host name.', 'alpaca-bot'));
        }
        $addresses = SpecialPurposeAddress::isAddress($literal) ? [$literal] : ($lookup ?? self::lookup(...))($host);
        if ($addresses === []) {
            /* translators: %s: a host name */
            throw new AddressRefused(sprintf(__('%s does not resolve, or its lookup failed.', 'alpaca-bot'), $host));
        }
        foreach ($addresses as $address) {
            if (!SpecialPurposeAddress::isAddress($address)) {
                /* translators: %s: a host name */
                throw new AddressRefused(sprintf(__('%s answered with something that is not an address.', 'alpaca-bot'), $host));
            }
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately core's own filter, not a hook of ours: a site that has already told WordPress a private host is reachable should not have to say it again here.
            if (SpecialPurposeAddress::match($address) !== null && !apply_filters('http_request_host_is_external', false, $host, $url)) {
                /* translators: 1: a host name, 2: an IP address */
                throw new AddressRefused(sprintf(__('%1$s resolves to %2$s, a private, local or other special-purpose address.', 'alpaca-bot'), $host, $address));
            }
        }
        return $addresses;
    }

    /**
     * Every address a name answers with, both families, through the system resolver: A through
     * gethostbynamel(), AAAA through dns_get_record(). All of them are checked and all of them
     * are handed back (resolve() says why), so both families have to be read. Cost:
     * two lookups, and a resolver that times out costs its timeout. For web_fetch the A one is
     * normally a cache hit, since core's wp_http_validate_url() has just made it; the AAAA one
     * is a query either way, no core version having asked for it, and a caller that has not been
     * through core's HTTP API first, as Mcp\Egress has not, pays for both. dns_get_record() warns
     * as well as returning false on a failed query, and a warning here is a refused request, not
     * an error worth logging, hence the suppression.
     *
     * A lookup that failed is not a lookup that answered "none". Either function returns false
     * when the query could not be made (a timeout, a nameserver that drops the question), and
     * that is answered with an empty list, which resolve() refuses as it refuses a name that
     * does not resolve. Read as "no records" instead, a false from the AAAA half would let a name
     * whose nameserver answers A with a public address and drops AAAA queries through on the A
     * alone. What the refusal costs: a legitimate host behind a resolver that fails one of the
     * two queries cannot be reached until the resolver answers.
     *
     * @param null|\Closure(string): (list<string>|false) $a    the A lookup; gethostbynamel() by default
     * @param null|\Closure(string): (array<mixed>|false) $aaaa the AAAA lookup; dns_get_record($host, DNS_AAAA) by default
     * @return list<string> every address, A first, or [] when the name has none or either lookup failed
     */
    public static function lookup(string $host, ?\Closure $a = null, ?\Closure $aaaa = null): array
    {
        $a ??= static fn(string $host): array|false => gethostbynamel($host);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() emits a PHP warning as well as returning false when a lookup fails. The false is checked below and refuses the request; the warning would only leak resolver detail into the response of an SSRF guard.
        $aaaa ??= static fn(string $host): array|false => @dns_get_record($host, DNS_AAAA);
        $v4 = $a($host);
        $v6 = $aaaa($host);
        if ($v4 === false || $v6 === false) {
            return [];
        }
        $addresses = array_values(array_filter($v4, 'is_string'));
        foreach ($v6 as $record) {
            if (is_array($record) && is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }
        return $addresses;
    }
}
