<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\AddressPin;
use AlpacaBot\Toolkit\AddressRefused;
use Brain\Monkey\Filters;

// AddressPin is the plugin's one address rule: it resolves a name once, holds every answer to
// SpecialPurposeAddress, and hands back every answer that passed, for the caller to pin as many
// of as its transport will take. web_fetch (cURL, through http_api_curl) pins them in one
// CURLOPT_RESOLVE entry, as many as its libcurl reads from one (CurlPinTest holds that);
// Mcp\Egress takes the first, because Symfony's `resolve` option maps a
// host to one address. It is a class of its own rather than a method of WebFetchToolkit for that
// reason: one rule, two transports. The lookup is injected, so nothing here touches DNS;
// SpecialPurposeAddressTest walks the table itself.

it('resolves a name once and returns every answer it checked, in lookup() order, IPv4 ahead of IPv6', function (): void {
    $calls = 0;
    $ips = AddressPin::resolve('dual.test', 'https://dual.test/', static function (string $host) use (&$calls): array {
        $calls++;
        return ['93.184.216.34', '2606:2800:220:1::1'];
    });
    expect($ips)->toBe(['93.184.216.34', '2606:2800:220:1::1'])->and($calls)->toBe(1);
    expect(AddressPin::resolve('v6only.test', '', static fn(string $host): array => ['2606:2800:220:1::1']))->toBe(['2606:2800:220:1::1']);
});

// The list is the resolver's, unedited: a name whose two lookups answer with the same address
// twice is handed back twice rather than collapsed, so what the caller pins is what was checked
// and nothing is dropped quietly. A repeated address costs a caller one repeated connect attempt
// at worst, which is cheaper than a rule about when an address disappears.
it('passes a repeated answer through rather than collapsing it', function (): void {
    expect(AddressPin::resolve('twice.test', '', static fn(string $host): array => ['93.184.216.34', '93.184.216.34']))
        ->toBe(['93.184.216.34', '93.184.216.34']);
});

// The rebinding case (audit H-1): a name that answers a public address the first time and
// 127.0.0.1 the next. Resolved once, the pin keeps the answers it checked and no second lookup
// is made; a later hop that asks again sees the rebound answer and is refused, never pinned to it.
it('keeps the answer it checked when the name rebinds, and refuses the rebound answer if asked again', function (): void {
    $answers = [['93.184.216.34'], ['127.0.0.1']];
    $lookup = static function (string $host) use (&$answers): array {
        return array_shift($answers) ?? [];
    };
    expect(AddressPin::resolve('rebind.test', 'http://rebind.test/', $lookup))->toBe(['93.184.216.34'])
        ->and($answers)->toBe([['127.0.0.1']]);
    expect(static fn() => AddressPin::resolve('rebind.test', 'http://rebind.test/', $lookup))->toThrow(AddressRefused::class, 'rebind.test');
});

it('refuses a name with any special-purpose answer among public ones, a name with no answer, and a special-purpose literal', function (): void {
    $answers = [
        'mixed.test' => ['93.184.216.34', '10.0.0.7'],
        'v6.test' => ['93.184.216.34', '::1'],
        'meta.test' => ['169.254.169.254'],
        'nowhere.test' => [],
    ];
    $lookup = static fn(string $host): array => $answers[$host];
    foreach (array_keys($answers) as $host) {
        expect(static fn() => AddressPin::resolve($host, '', $lookup))->toThrow(AddressRefused::class, $host);
    }
    $never = static fn(string $host): array => throw new LogicException('a literal is not looked up');
    foreach (['127.0.0.1', '169.254.169.254', '[::1]', '[::ffff:169.254.169.254]', '100.64.0.1'] as $literal) {
        expect(static fn() => AddressPin::resolve($literal, '', $never))->toThrow(AddressRefused::class);
    }
    expect(static fn() => AddressPin::resolve('', '', $never))->toThrow(AddressRefused::class);
});

it('returns a public literal as it is, without a lookup, and lower-cases and trims a name before looking it up', function (): void {
    $never = static fn(string $host): array => throw new LogicException('a literal is not looked up');
    expect(AddressPin::resolve('93.184.216.34', '', $never))->toBe(['93.184.216.34'])
        ->and(AddressPin::resolve('[2606:2800:220:1::1]', '', $never))->toBe(['2606:2800:220:1::1']);
    $asked = [];
    AddressPin::resolve('Example.TEST.', '', static function (string $host) use (&$asked): array {
        $asked[] = $host;
        return ['93.184.216.34'];
    });
    expect($asked)->toBe(['example.test']);
});

it('lets core\'s http_request_host_is_external opt a special-purpose answer in, with the host and URL core passes', function (): void {
    Filters\expectApplied('http_request_host_is_external')->once()->with(false, 'meta.test', 'http://meta.test/')->andReturn(true);
    expect(AddressPin::resolve('meta.test', 'http://meta.test/', static fn(string $host): array => ['169.254.169.254']))->toBe(['169.254.169.254']);
});

// Moved from WebFetchToolkitTest with the lookup itself: a failed AAAA query is not "no AAAA
// records". dns_get_record() returns false when the query fails, and reading that as [] let a
// nameserver that answers A with a public address and drops AAAA get past the table on the A
// alone, while the transport's own lookup, which does read AAAA, chose the address.
it('looks a name up in both families, and answers [] when either lookup failed', function (): void {
    $a = static fn(string $host): array|false => ['93.184.216.34'];
    $failed = static fn(string $host): array|false => false;
    $none = static fn(string $host): array|false => [];
    $some = static fn(string $host): array|false => [['host' => $host, 'type' => 'AAAA', 'ipv6' => '2606:4700::1']];
    expect(AddressPin::lookup('dropped.test', $a, $failed))->toBe([])
        ->and(AddressPin::lookup('v4only.test', $a, $none))->toBe(['93.184.216.34'])
        ->and(AddressPin::lookup('dual.test', $a, $some))->toBe(['93.184.216.34', '2606:4700::1'])
        ->and(AddressPin::lookup('v6only.test', $failed, $some))->toBe([]);
});
