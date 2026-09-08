<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\SpecialPurposeAddress;

// The table is the plugin's own security boundary for web_fetch, independent of what the
// running WordPress version's wp_http_validate_url() knows (6.9 refuses five IPv4 ranges and no
// IPv6 at all). Every entry is walked at both ends, and the addresses one step outside each
// range are public, so a range written one bit too wide or too narrow fails here.

/** The first and last address of a CIDR range, as strings inet_pton() would give back. */
function specialPurposeEnds(string $cidr): array
{
    [$network, $bits] = explode('/', $cidr);
    $packed = (string) inet_pton($network);
    $bits = (int) $bits;
    $first = $last = '';
    foreach (str_split($packed) as $i => $byte) {
        $covered = max(0, min(8, $bits - $i * 8));
        $mask = $covered === 0 ? 0 : (0xFF << (8 - $covered)) & 0xFF;
        $first .= chr(ord($byte) & $mask);
        $last .= chr((ord($byte) & $mask) | (~$mask & 0xFF));
    }
    return [(string) inet_ntop($first), (string) inet_ntop($last)];
}

it('refuses the first and last address of every range in the table, naming the range', function (): void {
    expect(SpecialPurposeAddress::RANGES)->not->toBeEmpty();
    foreach (array_keys(SpecialPurposeAddress::RANGES) as $cidr) {
        [$first, $last] = specialPurposeEnds($cidr);
        expect(SpecialPurposeAddress::match($first))->toBe($cidr, "$first (first of $cidr)")
            ->and(SpecialPurposeAddress::match($last))->toBe($cidr, "$last (last of $cidr)");
    }
});

it('covers what the brief names: loopback, link-local, CGNAT, RFC 1918, this-network, multicast, reserved, unique-local, IPv4-mapped, and the unspecified address, in both families', function (): void {
    foreach ([
        '127.0.0.1' => '127.0.0.0/8',
        '127.255.255.254' => '127.0.0.0/8',
        '::1' => '::1/128',
        '169.254.169.254' => '169.254.0.0/16',
        'fe80::1' => 'fe80::/10',
        '100.64.0.1' => '100.64.0.0/10',
        '10.1.2.3' => '10.0.0.0/8',
        '172.16.0.1' => '172.16.0.0/12',
        '172.31.255.255' => '172.16.0.0/12',
        '192.168.1.1' => '192.168.0.0/16',
        '0.0.0.0' => '0.0.0.0/8',
        '224.0.0.1' => '224.0.0.0/4',
        '239.255.255.255' => '224.0.0.0/4',
        'ff02::1' => 'ff00::/8',
        '255.255.255.255' => '240.0.0.0/4',
        '240.0.0.1' => '240.0.0.0/4',
        'fc00::1' => 'fc00::/7',
        'fd12:3456::1' => 'fc00::/7',
        '::ffff:169.254.169.254' => '::ffff:0:0/96',
        '::ffff:7f00:1' => '::ffff:0:0/96',
        '::' => '::/128',
    ] as $ip => $cidr) {
        expect(SpecialPurposeAddress::match($ip))->toBe($cidr, $ip);
    }
});

it('passes a public address, including the ones one step outside each range', function (): void {
    foreach ([
        '8.8.8.8', '1.1.1.1', '93.184.216.34', '223.255.255.255',
        '1.0.0.0',
        '9.255.255.255', '11.0.0.0',
        '100.63.255.255', '100.128.0.0',
        '126.255.255.255', '128.0.0.0',
        '169.253.255.255', '169.255.0.0',
        '172.15.255.255', '172.32.0.0',
        '191.255.255.255', '192.0.1.0', '192.0.3.0', '192.88.98.255', '192.88.100.0',
        '192.167.255.255', '192.169.0.0',
        '198.17.255.255', '198.20.0.0', '198.51.99.255', '198.51.101.0',
        '203.0.112.255', '203.0.114.0',
        '2606:4700::1', '2001:4860:4860::8888', '2a00:1450:4001:80b::200e',
        '::2', '64:ff9a:ffff:ffff:ffff:ffff:ffff:ffff', '64:ff9b::1:0:0', '64:ff9b:2::',
        '100::1:0:0:0:0', 'ff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '101::',
        '2000:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '2001:1::', '2001:db7:ffff::', '2001:db9::', '2001:ffff::',
        '2003::', 'fbff::1', 'fe00::1', 'fe7f:ffff::1', 'fec0::1', 'feff::1',
    ] as $ip) {
        expect(SpecialPurposeAddress::match($ip))->toBeNull($ip);
    }
});

it('tells an address from a name, and refuses to judge a name', function (): void {
    expect(SpecialPurposeAddress::isAddress('127.0.0.1'))->toBeTrue()
        ->and(SpecialPurposeAddress::isAddress('::ffff:1.2.3.4'))->toBeTrue()
        ->and(SpecialPurposeAddress::isAddress('example.test'))->toBeFalse()
        ->and(SpecialPurposeAddress::isAddress('2130706433'))->toBeFalse()
        ->and(SpecialPurposeAddress::isAddress('0177.0.0.1'))->toBeFalse()
        ->and(SpecialPurposeAddress::isAddress('[::1]'))->toBeFalse()
        ->and(SpecialPurposeAddress::isAddress(''))->toBeFalse();
    expect(fn() => SpecialPurposeAddress::match('example.test'))->toThrow(\InvalidArgumentException::class);
});
