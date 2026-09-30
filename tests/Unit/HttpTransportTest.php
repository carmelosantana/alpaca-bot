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

// Symfony's Curl client runs on the multi and share handles and never calls curl_exec, so web_fetch's
// question (curl_init and curl_exec) saying yes is not enough: a host that disables any one function
// the client calls gets the Native client, although web_fetch's own answer there is still yes.
it('builds Symfony\'s Native client when any one function Symfony\'s Curl client calls is missing, though web_fetch\'s question says yes', function (): void {
    foreach (HttpTransport::CURL_FUNCTIONS as $missing) {
        $client = HttpTransport::create([], static fn(bool $https): bool => true, static fn(string $function): bool => $function !== $missing);
        expect($client)->toBeInstanceOf(NativeHttpClient::class, $missing);
    }
    $client = HttpTransport::create([], static fn(bool $https): bool => true, static fn(string $function): bool => true);
    expect($client)->toBeInstanceOf(CurlHttpClient::class);
});

it('asks about curl_share_init_persistent() only on PHP 8.5 and later, the only PHP that calls it', function (): void {
    $client = HttpTransport::create([], static fn(bool $https): bool => true, static fn(string $function): bool => $function !== 'curl_share_init_persistent');
    expect($client)->toBeInstanceOf(PHP_VERSION_ID >= 80500 ? NativeHttpClient::class : CurlHttpClient::class);
});

// The list is held to the package: every curl_* function called anywhere in vendor-prefixed
// symfony/http-client, so a Symfony update that calls one more is a failure here, not an Error on
// a host that disables it.
it('names every cURL function vendor-prefixed symfony/http-client calls, and no other', function (): void {
    $called = [];
    $dir = dirname(__DIR__, 2) . '/vendor-prefixed/symfony/http-client';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            preg_match_all('~(?<![\w>:$])\\\\?(curl_[a-z_]+)\s*\(~', (string) file_get_contents($file->getPathname()), $m);
            $called = [...$called, ...$m[1]];
        }
    }
    $called = array_values(array_unique($called));
    sort($called);
    $named = [...HttpTransport::CURL_FUNCTIONS, 'curl_share_init_persistent'];
    sort($named);
    expect($called)->toBe($named);
});
