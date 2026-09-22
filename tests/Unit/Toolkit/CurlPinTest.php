<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\CurlPin;

// A CurlPin is the one-shot `http_api_curl` callback web_fetch hooks around a single request.
// Core hands the callback the request's cURL handle after Requests has set it up and before it
// runs (WP 7.1 class-wp-http-requests-hooks.php:56-58, Requests/src/Transport/Curl.php:172-174).
// The option is written through an injected setter so the test can read what was set; the real
// curl_setopt() is exercised once on a real handle, since cURL offers no getter for CURLOPT_RESOLVE.

it('builds the CURLOPT_RESOLVE entry cURL expects, host:port:address, an IPv6 address unbracketed as Symfony writes it', function (): void {
    expect((new CurlPin('example.test', 443, ['93.184.216.34']))->entry)->toBe('example.test:443:93.184.216.34')
        ->and((new CurlPin('v6.test', 8080, ['2606:2800:220:1::1']))->entry)->toBe('v6.test:8080:2606:2800:220:1::1');
});

// Every address AddressPin checked goes into the one entry, comma-joined, which is the form
// cURL documents (`--resolve <[+]host:port:addr[,addr]...>`, curl 8.5.0's own man page). cURL
// then chooses among them as it would among a name's own answers, so a dual-stack host is still
// reachable from a server that can route only one family -- the whole point of handing back more
// than one. Order is AddressPin's, which is lookup()'s: A first. The libcurl-version test below
// holds the one build on which fewer than all of them go in.
it('joins every checked address into the one entry, in the order it was handed them', function (): void {
    expect((new CurlPin('dual.test', 443, ['93.184.216.34', '2606:2800:220:1::1']))->entry)->toBe('dual.test:443:93.184.216.34,2606:2800:220:1::1')
        ->and((new CurlPin('many.test', 80, ['93.184.216.34', '104.20.23.154', '2606:2800:220:1::1']))->entry)->toBe('many.test:80:93.184.216.34,104.20.23.154,2606:2800:220:1::1');
});

// A libcurl older than the comma form would read the joined entry as one malformed address, and
// an entry libcurl drops is a request that resolves the name for itself -- unpinned. So below
// 7.59.0 the entry carries the first address only, which is 0.5's shape and 0.5's cost, and the
// pin still holds. The version is injected here; the real one is read once per pin.
it('falls back to the first address alone on a libcurl older than the multiple-address form, so the pin still holds', function (): void {
    $ips = ['93.184.216.34', '2606:2800:220:1::1'];
    expect((new CurlPin('dual.test', 443, $ips, null, static fn(): int => 0x073A00))->entry)->toBe('dual.test:443:93.184.216.34')
        ->and((new CurlPin('dual.test', 443, $ips, null, static fn(): int => 0x073B00))->entry)->toBe('dual.test:443:93.184.216.34,2606:2800:220:1::1')
        ->and((new CurlPin('dual.test', 443, $ips, null, static fn(): int => 0))->entry)->toBe('dual.test:443:93.184.216.34');
});

// This build's own libcurl is new enough, so the default reader is the one under test above and
// not a branch nothing exercises. A build that failed this line would pin one address and say so.
it('reads a libcurl new enough for the comma form on the build running the suite', function (): void {
    expect(curl_version()['version_number'])->toBeGreaterThanOrEqual(0x073B00)
        ->and((new CurlPin('dual.test', 443, ['93.184.216.34', '2606:2800:220:1::1']))->entry)->toBe('dual.test:443:93.184.216.34,2606:2800:220:1::1');
});

it('sets exactly that entry as CURLOPT_RESOLVE on the handle it is handed', function (): void {
    $set = [];
    $handle = curl_init();
    (new CurlPin('dual.test', 443, ['93.184.216.34', '2606:2800:220:1::1'], static function (mixed $h, int $option, mixed $value) use (&$set): bool {
        $set[] = [$h, $option, $value];
        return true;
    }))($handle);
    expect($set)->toBe([[$handle, CURLOPT_RESOLVE, ['dual.test:443:93.184.216.34,2606:2800:220:1::1']]]);
    // The real setter accepts both shapes of entry on a real handle.
    (new CurlPin('example.test', 443, ['93.184.216.34']))(curl_init());
    (new CurlPin('dual.test', 443, ['93.184.216.34', '2606:2800:220:1::1']))(curl_init());
});

it('fails the request, as a Requests exception core turns into a WP_Error, when there is no handle or the option will not set', function (): void {
    expect(static fn() => (new CurlPin('example.test', 443, ['93.184.216.34']))('not a handle'))->toThrow(\WpOrg\Requests\Exception::class);
    expect(static fn() => (new CurlPin('example.test', 443, ['93.184.216.34'], static fn(): bool => false))(curl_init()))->toThrow(\WpOrg\Requests\Exception::class);
});
