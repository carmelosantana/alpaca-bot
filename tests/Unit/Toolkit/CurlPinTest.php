<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\CurlPin;

// A CurlPin is the one-shot `http_api_curl` callback web_fetch hooks around a single request.
// Core hands the callback the request's cURL handle after Requests has set it up and before it
// runs (WP 7.1 class-wp-http-requests-hooks.php:56-58, Requests/src/Transport/Curl.php:172-174).
// The option is written through an injected setter so the test can read what was set; the real
// curl_setopt() is exercised once on a real handle, since cURL offers no getter for CURLOPT_RESOLVE.

it('builds the CURLOPT_RESOLVE entry cURL expects, host:port:address, an IPv6 address unbracketed as Symfony writes it', function (): void {
    expect((new CurlPin('example.test', 443, '93.184.216.34'))->entry)->toBe('example.test:443:93.184.216.34')
        ->and((new CurlPin('v6.test', 8080, '2606:2800:220:1::1'))->entry)->toBe('v6.test:8080:2606:2800:220:1::1');
});

it('sets exactly that entry as CURLOPT_RESOLVE on the handle it is handed', function (): void {
    $set = [];
    $handle = curl_init();
    (new CurlPin('example.test', 443, '93.184.216.34', static function (mixed $h, int $option, mixed $value) use (&$set): bool {
        $set[] = [$h, $option, $value];
        return true;
    }))($handle);
    expect($set)->toBe([[$handle, CURLOPT_RESOLVE, ['example.test:443:93.184.216.34']]]);
    // The real setter accepts the entry on a real handle.
    (new CurlPin('example.test', 443, '93.184.216.34'))(curl_init());
});

it('fails the request, as a Requests exception core turns into a WP_Error, when there is no handle or the option will not set', function (): void {
    expect(static fn() => (new CurlPin('example.test', 443, '93.184.216.34'))('not a handle'))->toThrow(\WpOrg\Requests\Exception::class);
    expect(static fn() => (new CurlPin('example.test', 443, '93.184.216.34', static fn(): bool => false))(curl_init()))->toThrow(\WpOrg\Requests\Exception::class);
});
