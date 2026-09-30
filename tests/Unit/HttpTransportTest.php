<?php

declare(strict_types=1);

use AlpacaBot\HttpTransport;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\CurlHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\NativeHttpClient;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

// The cURL question is handed in, as WebFetchToolkit's and SiteHealth's are (#4484): the real
// answer, WebFetchToolkit::curlCarries(), is Requests' Transport\Curl::test(), which the unit
// suite does not load. What a disabled curl_init or curl_exec makes of that answer is
// NoCurlClientTest's and NoCurlTest's, run with the functions really disabled.

/** The default options a Symfony client was built with. */
function transportDefaults(HttpClientInterface $client): array
{
    return (fn(): array => $this->defaultOptions)->call($client);
}

it('builds Symfony\'s Native client with the options it was given and create()\'s connection limit when cURL is not usable, asking the question without SSL', function (): void {
    $asked = [];
    $client = HttpTransport::create(['timeout' => 7], static function (bool $https) use (&$asked): bool {
        $asked[] = $https;
        return false;
    });
    expect($client)->toBeInstanceOf(NativeHttpClient::class)
        ->and(transportDefaults($client)['timeout'])->toEqual(7)
        ->and((fn(): int => $this->multi->maxHostConnections)->call($client))->toBe(6)
        ->and($asked)->toBe([false]);
});

// Where cURL is usable the client is whatever Symfony's own HttpClient::create() answers, which on
// this suite's PHP (not Windows, amphp not loaded) is its Curl client. The 50 pending pushes are
// what marks it as create()'s: create() passes 50 (HttpClient.php:31, :54), and a Curl client
// built directly defaults to 0 (CurlHttpClient.php:69).
it('leaves the choice to Symfony\'s HttpClient::create() when cURL is usable, with the options it was given', function (): void {
    $client = HttpTransport::create(['timeout' => 7], static fn(bool $https): bool => true);
    expect($client)->toBeInstanceOf(CurlHttpClient::class)
        ->and(transportDefaults($client)['timeout'])->toEqual(7)
        ->and((fn(): int => (fn(): int => $this->maxPendingPushes)->call($this->multi))->call($client))->toBe(50);
});
