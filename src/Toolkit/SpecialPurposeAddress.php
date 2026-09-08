<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

/**
 * The addresses a model-chosen fetch may not go to, whatever WordPress version is running.
 *
 * wp_http_validate_url() carries a table like this one, but what it covers depends on the core
 * version: 6.9, the plugin's floor, refuses loopback, RFC 1918 and 0/8 and nothing else, so on a
 * supported site it passes the link-local address where a cloud instance's metadata service
 * answers with the instance's credentials; 7.1 refuses the IPv4 special-purpose registry and
 * every bracketed IPv6 literal; no version looks at an AAAA record. This table is the plugin's
 * own floor, applied after core's, never instead of it: an address core refuses never gets here.
 *
 * The table is the IANA special-purpose registries for both families, plus the prefixes that
 * carry an IPv4 address inside an IPv6 one: an address written that way would be judged by the
 * IPv6 half of the table and never by the IPv4 half, so those prefixes go whole rather than
 * being unwrapped. What it does not cover: a public address that a site's own network routes
 * somewhere private (a split-horizon DNS name, a reverse proxy on a public address in front of
 * an internal service), which no address table can see, and a name whose answer changes between
 * this check and the connection (see WebFetchToolkit). Cost per address: a linear walk of the
 * table, microseconds.
 *
 * @since 0.5.0
 */
final class SpecialPurposeAddress
{
    /**
     * CIDR range => what it is and why a fetch there is refused. IPv4 first, in address order,
     * then IPv6. A range is matched by prefix bits, so the order only decides which range is
     * named when two overlap (none do).
     *
     * @var array<string, string>
     */
    public const RANGES = [
        // IPv4: the IANA IPv4 Special-Purpose Address Registry (RFC 6890) and multicast (RFC 5771).
        '0.0.0.0/8' => '"this network": 0.0.0.0 reaches the local host on most stacks',
        '10.0.0.0/8' => 'private (RFC 1918)',
        '100.64.0.0/10' => 'carrier-grade NAT shared space (RFC 6598): a provider\'s internal network',
        '127.0.0.0/8' => 'loopback',
        '169.254.0.0/16' => 'link-local (RFC 3927), where a cloud instance\'s metadata service answers',
        '172.16.0.0/12' => 'private (RFC 1918)',
        '192.0.0.0/24' => 'IETF protocol assignments',
        '192.0.2.0/24' => 'documentation (TEST-NET-1)',
        '192.88.99.0/24' => '6to4 relay anycast, deprecated',
        '192.168.0.0/16' => 'private (RFC 1918)',
        '198.18.0.0/15' => 'benchmarking',
        '198.51.100.0/24' => 'documentation (TEST-NET-2)',
        '203.0.113.0/24' => 'documentation (TEST-NET-3)',
        '224.0.0.0/4' => 'multicast',
        '240.0.0.0/4' => 'reserved, and 255.255.255.255, the limited broadcast address',
        // IPv6: the IANA IPv6 Special-Purpose Address Registry (RFC 6890) and multicast (RFC 4291).
        '::/128' => 'unspecified, which connects to the local host on most stacks',
        '::1/128' => 'loopback',
        '::ffff:0:0/96' => 'IPv4-mapped: an IPv4 address written as IPv6, which the IPv4 half of this table would never see',
        '64:ff9b::/96' => 'NAT64 well-known prefix: carries an IPv4 address this table does not inspect',
        '64:ff9b:1::/48' => 'local-use NAT64 (RFC 8215): carries an IPv4 address this table does not inspect',
        '100::/64' => 'discard-only (RFC 6666)',
        '2001::/32' => 'Teredo (RFC 4380): carries an IPv4 address this table does not inspect',
        '2001:db8::/32' => 'documentation',
        '2002::/16' => '6to4 (RFC 3056): carries an IPv4 address this table does not inspect',
        'fc00::/7' => 'unique local (RFC 4193), the IPv6 private range',
        'fe80::/10' => 'link-local',
        'ff00::/8' => 'multicast',
    ];

    /** Whether the string is an IP address literal in either family: a dotted quad or RFC 4291 text, no brackets, and none of the shorthands (`2130706433`, `0177.0.0.1`) a resolver would read as an address. */
    public static function isAddress(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * The range the address falls in, as its key in RANGES, or null when it is in none: a
     * public address.
     *
     * @throws \InvalidArgumentException for a string that is not an IP address; a name has to be resolved first, this does not judge names
     */
    public static function match(string $ip): ?string
    {
        if (!self::isAddress($ip)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an IP address.', $ip));
        }
        $packed = (string) inet_pton($ip);
        foreach (array_keys(self::RANGES) as $cidr) {
            if (self::within($packed, $cidr)) {
                return $cidr;
            }
        }
        return null;
    }

    /** Whether a packed address (inet_pton()) shares the range's first `bits` bits; an address of the other family never does. */
    private static function within(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $prefix = (string) inet_pton($network);
        if (strlen($prefix) !== strlen($packed)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($prefix, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($packed[$bytes]) & $mask) === (ord($prefix[$bytes]) & $mask);
    }
}
