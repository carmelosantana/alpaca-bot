<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

/**
 * The plugin's one address rule: resolve a name once, both families, hold every answer to
 * SpecialPurposeAddress, and hand back the single address the connection is then pinned to.
 * web_fetch is its only caller today, and pins that address into cURL (CurlPin, through core's
 * `http_api_curl`). It is a class of its own rather than a method of WebFetchToolkit because the
 * MCP egress client the 0.6 spec plans has to pin the same address into Symfony HttpClient's
 * `resolve` option: one rule, whichever transport asks it.
 *
 * Why the address is returned and not just judged: a check that only answers yes or no leaves
 * the transport to resolve the name again when it connects, and a name under someone else's
 * control with a short TTL can answer the check with a public address and the connection with
 * 127.0.0.1 or the cloud metadata address (DNS rebinding; audit H-1). The caller connects to
 * the address this returns, so the answer that was checked is the answer that is used.
 *
 * Every answer has to pass, not just the one returned. The connection only goes to the first
 * one, but a name that answers with a private address among public ones is a name pointed at
 * something private, and refusing it costs a legitimate host nothing it had. A special-purpose
 * answer is let through only when core's `http_request_host_is_external` filter opts the host
 * in, so that opt-in means the same thing here as for any plugin; what it costs is what core
 * charges for it, since a site that sets it has already told WordPress that host is reachable.
 * The first answer is the first A record when there is one (lookup() lists A before AAAA): a
 * dual-stack host is reached over IPv4, which every server that can reach it at all can route.
 *
 * What this does not cover is the same as SpecialPurposeAddress: a public address the site's
 * own network routes somewhere private. And what it covers only holds while the caller really
 * connects to the returned address; a proxy that is sent the name resolves it again, and a
 * transport that never sees the pin never uses it (WebFetchToolkit says what it does about
 * both).
 *
 * @since 0.6.0
 */
final class AddressPin
{
    /**
     * The address to connect to for `$host`, checked.
     *
     * @param string $host a host name or an IP literal (brackets allowed), as wp_parse_url() gives it
     * @param string $url the URL the host came from, for core's `http_request_host_is_external` filter; '' when there is none
     * @param null|\Closure(string): list<string> $lookup every address the name answers with; lookup() over the system resolver by default, a test hands in its own
     * @throws AddressRefused with a translated message naming the host
     */
    public static function resolve(string $host, string $url = '', ?\Closure $lookup = null): string
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
        return $addresses[0];
    }

    /**
     * Every address a name answers with, both families, through the system resolver: A through
     * gethostbynamel(), AAAA through dns_get_record(). The connection is pinned to one of them,
     * but all of them are checked (resolve() says why), so both families have to be read. Cost:
     * two lookups, normally from the resolver's cache, since core's wp_http_validate_url() has
     * just made the A one; a resolver that times out costs its timeout. dns_get_record() warns
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
