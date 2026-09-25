<?php

declare(strict_types=1);

use AlpacaBot\Mcp\Egress;
use AlpacaBot\Mcp\PinnedHttpClient;
use AlpacaBot\Toolkit\AddressRefused;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\CurlHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Exception\RedirectionException;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Exception\TransportException;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\MockHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\NativeHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Response\MockResponse;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

// MockHttpClient is Symfony's own test transport, shipped in vendor-prefixed with the rest of
// the package. It runs each request through the same prepareRequest() the real clients do and
// hands the normalised options to the factory, so what it records is what CurlHttpClient would
// turn into CURLOPT_RESOLVE and CURLOPT_MAXREDIRS (CurlHttpClient.php:113-114, :188-204). What it
// cannot show is a socket honouring them; that is libcurl's, and Symfony's own suite's.

/** @param array<string, mixed>|null $seen */
function egressOver(?array &$seen, MockResponse $response, ?\Closure $resolve = null): HttpClientInterface
{
    $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen, $response): MockResponse {
        $seen = $options;
        return $response;
    });
    return (new Egress($resolve ?? static fn(string $host, string $url): array => ['93.184.216.34'], $mock))->client(mcpServerConfig());
}

it('pins the connection to the address the check passed, turns redirects off, and takes its timeouts from the server row', function (): void {
    $asked = [];
    $client = egressOver($seen, new MockResponse('{"jsonrpc":"2.0"}'), static function (string $host, string $url) use (&$asked): array {
        $asked[] = [$host, $url];
        return ['93.184.216.34'];
    });
    expect($client)->toBeInstanceOf(HttpClientInterface::class)
        ->and($client->request('POST', 'https://mcp.example.test/mcp', ['json' => ['jsonrpc' => '2.0']])->getContent())->toBe('{"jsonrpc":"2.0"}')
        ->and($asked)->toBe([['mcp.example.test', 'https://mcp.example.test/mcp']])
        ->and($seen['resolve'])->toBe(['mcp.example.test' => '93.184.216.34'])
        ->and($seen['max_redirects'])->toBe(0)
        ->and($seen['timeout'])->toBe(12.5)
        ->and($seen['max_duration'])->toBe(12.5)
        ->and($seen['no_proxy'])->toBe('*');
});

// Which addresses go into `resolve` depends on the transport under the pin. Symfony's Curl client
// writes the value into CURLOPT_RESOLVE as it is ("$host:$port:$ip", CurlHttpClient.php:198-199),
// so a comma-joined list there is libcurl's own `host:port:addr[,addr]` form; its Native client
// connects to the value as one address (NativeHttpClient.php:344, :371), so a list there would be a
// name it cannot resolve. A MockHttpClient handed in is neither, and gets what Native gets: the
// first alone.
it('pins the first of the checked addresses alone on a transport that is not Symfony\'s Curl client', function (): void {
    $client = egressOver($seen, new MockResponse('{}'), static fn(string $host, string $url): array => ['93.184.216.34', '2606:2800:220:1::1']);
    $client->request('POST', 'https://mcp.example.test/mcp')->getContent();
    expect($seen['resolve'])->toBe(['mcp.example.test' => '93.184.216.34']);
});

/**
 * What Symfony's Curl client wrote into CURLOPT_RESOLVE for `$host`, the request `$pinned` makes
 * to it. libcurl has no getter for the option, but the client records the same `$ip` it writes
 * there in its own DNS map (CurlHttpClient.php:198-200), and that map is read here, before the
 * request is cancelled: the cancel empties the map once no handle is open (CurlResponse.php:199-202,
 * CurlClientState.php:72-73). Nothing is sent: building the response only adds its handle to the
 * multi handle (CurlResponse.php:175-178), and a cancelled response is never performed, even when
 * it is destroyed (TransportResponseTrait.php:75-80, :129-136).
 */
function curlPinned(CurlHttpClient $curl, string $host, HttpClientInterface $pinned): ?string
{
    $response = $pinned->request('POST', 'https://' . $host . '/mcp');
    $written = (fn(): ?string => $this->multi->dnsCache->hostnames[$host] ?? null)->call($curl);
    $response->cancel();
    return $written;
}

it('hands Symfony\'s Curl client every checked address, comma-joined in the order the check gave them, on a libcurl that reads the form', function (): void {
    $curl = new CurlHttpClient();
    $ips = ['2606:2800:220:1::1', '93.184.216.34', '104.20.23.154'];
    $pinned = (new Egress(static fn(string $host, string $url): array => $ips, $curl, static fn(): int => 0x073B00))->client(mcpServerConfig());
    expect(curlPinned($curl, 'mcp.example.test', $pinned))->toBe('2606:2800:220:1::1,93.184.216.34,104.20.23.154');
});

// CurlPin's floor, and its reason: a libcurl older than the comma form reads the list as one
// address it cannot parse, and a dropped entry is an unpinned request.
it('hands Symfony\'s Curl client the first address alone on a libcurl older than the comma form', function (): void {
    foreach ([0x073A00, 0] as $version) {
        $curl = new CurlHttpClient();
        $pinned = (new Egress(static fn(string $host, string $url): array => ['93.184.216.34', '2606:2800:220:1::1'], $curl, static fn(): int => $version))->client(mcpServerConfig());
        expect(curlPinned($curl, 'mcp.example.test', $pinned))->toBe('93.184.216.34');
    }
});

// With no version handed in, the one libcurl reads is this build's, which is new enough: the
// default reader is exercised, not only the injected one.
it('reads the libcurl the suite runs on when no version is handed in', function (): void {
    $curl = new CurlHttpClient();
    $pinned = (new Egress(static fn(string $host, string $url): array => ['93.184.216.34', '2606:2800:220:1::1'], $curl))->client(mcpServerConfig());
    expect(curl_version()['version_number'])->toBeGreaterThanOrEqual(0x073B00)
        ->and(curlPinned($curl, 'mcp.example.test', $pinned))->toBe('93.184.216.34,2606:2800:220:1::1');
});

it('hands Symfony\'s Native client the first address alone', function (): void {
    $native = new NativeHttpClient();
    $pinned = (new Egress(static fn(string $host, string $url): array => ['93.184.216.34', '2606:2800:220:1::1'], $native, static fn(): int => 0x080500))->client(mcpServerConfig());
    // Read before the cancel, as curlPinned() does; NativeHttpClient.php:191-192 fills the map in
    // request() itself, and the response connects only when it is first read.
    $response = $pinned->request('POST', 'https://mcp.example.test/mcp');
    $written = (fn(): array => $this->multi->dnsCache)->call($native);
    $response->cancel();
    expect($written)->toBe(['mcp.example.test' => '93.184.216.34']);
});

/** The transport a pinned client lays its pin over (PinnedHttpClient::$client). */
function pinnedTransport(HttpClientInterface $pinned): HttpClientInterface
{
    return (fn(): HttpClientInterface => $this->client)->call($pinned);
}

// R28-9: the pin is laid only over a transport Egress can vouch for. HttpClient::create() answers
// with Amp's client where the unprefixed amphp/http-client is loaded, and Amp's resolver lets a
// lookup past the map (AmpResolver.php:42-43, :58-59), so anything create() answers other than the
// Curl or the Native client is replaced with a Native client, which is handed the first address.
it('replaces a default transport it cannot vouch for with Symfony\'s Native client, pinned to the first address', function (): void {
    $pinned = (new Egress(static fn(string $host, string $url): array => ['93.184.216.34', '2606:2800:220:1::1'], null, static fn(): int => 0x080500, static fn(): HttpClientInterface => new MockHttpClient()))->client(mcpServerConfig());
    $native = pinnedTransport($pinned);
    expect($native)->toBeInstanceOf(NativeHttpClient::class);
    /** @var NativeHttpClient $native */
    $response = $pinned->request('POST', 'https://mcp.example.test/mcp');
    $written = (fn(): array => $this->multi->dnsCache)->call($native);
    $response->cancel();
    expect($written)->toBe(['mcp.example.test' => '93.184.216.34']);
});

it('keeps a default transport that is Symfony\'s Curl or Native client as it was created', function (): void {
    foreach ([new CurlHttpClient(), new NativeHttpClient()] as $created) {
        $pinned = (new Egress(static fn(string $host, string $url): array => ['93.184.216.34'], null, null, static fn(): HttpClientInterface => $created))->client(mcpServerConfig());
        expect(pinnedTransport($pinned))->toBe($created);
    }
});

// PinnedHttpClient writes what it is handed, joined; Symfony's option normalisation
// (HttpClientTrait.php:209-219) leaves a joined value as it is, because it unbrackets only a value
// that opens with `[` and closes with `]`, and AddressPin hands every IPv6 address unbracketed.
// It turns an empty value into null, which the Curl client writes as `-host:port`, a removal
// (CurlHttpClient.php:199): an unpinned request. So an empty list is refused when the client is built.
it('writes every address it was handed into resolve, comma-joined, and Symfony hands the transport that value unchanged', function (): void {
    $seen = null;
    $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
        $seen = $options;
        return new MockResponse('{}');
    });
    (new PinnedHttpClient($mock, 'mcp.example.test', ['2606:2800:220:1::1', '93.184.216.34'], 12.5, 1024))->request('POST', 'https://mcp.example.test/mcp')->getContent();
    expect($seen['resolve'])->toBe(['mcp.example.test' => '2606:2800:220:1::1,93.184.216.34']);
    expect(static fn() => new PinnedHttpClient($mock, 'mcp.example.test', [], 12.5, 1024))->toThrow(InvalidArgumentException::class)
        ->and(static fn() => new PinnedHttpClient($mock, 'mcp.example.test', [''], 12.5, 1024))->toThrow(InvalidArgumentException::class);
});

it('does not let the caller loosen it: its own resolve, redirects, timeouts and proxy rule are overwritten', function (): void {
    $client = egressOver($seen, new MockResponse('{}'));
    $client->request('POST', 'https://mcp.example.test/mcp', ['resolve' => ['mcp.example.test' => '127.0.0.1'], 'max_redirects' => 5, 'timeout' => 600, 'max_duration' => 0, 'no_proxy' => ''])->getContent();
    expect($seen['resolve'])->toBe(['mcp.example.test' => '93.184.216.34'])
        ->and($seen['max_redirects'])->toBe(0)
        ->and($seen['timeout'])->toBe(12.5)
        ->and($seen['max_duration'])->toBe(12.5)
        ->and($seen['no_proxy'])->toBe('*');
});

it('refuses a request to any host but the one it was pinned for, or over http, before anything is sent', function (): void {
    $seen = null;
    $client = egressOver($seen, new MockResponse('{}'));
    foreach (['https://elsewhere.test/mcp', 'http://mcp.example.test/mcp', 'https://mcp.example.test.evil.test/'] as $url) {
        expect(static fn() => $client->request('POST', $url))->toThrow(TransportException::class, 'mcp.example.test');
    }
    expect($seen)->toBeNull();
});

// The contract Symfony states for a 3xx a client did not follow is RedirectionExceptionInterface,
// but toThrow() resolves a class name through class_exists(), which is false for an interface and
// makes Pest read the name as a message to look for instead -- an assertion that passes on
// nothing. RedirectionException is the one class in the package implementing it.
it('hands a redirect back as the 3xx it is instead of following it', function (): void {
    $response = egressOver($seen, new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://elsewhere.test/']]))->request('GET', 'https://mcp.example.test/mcp');
    expect($response->getStatusCode())->toBe(302)
        ->and($seen['max_redirects'])->toBe(0)
        ->and(static fn() => $response->getContent())->toThrow(RedirectionException::class);
});

it('cuts a response off once it passes the server\'s byte cap, while it streams, and lets one at the cap through', function (): void {
    $over = egressOver($seen, new MockResponse((static function (): \Generator {
        yield str_repeat('x', 600);
        yield str_repeat('x', 600);
    })()))->request('POST', 'https://mcp.example.test/mcp');
    expect(static fn() => $over->getContent())->toThrow(TransportException::class, '1024');
    $at = egressOver($seen, new MockResponse(str_repeat('x', 1024)))->request('POST', 'https://mcp.example.test/mcp');
    expect(strlen($at->getContent()))->toBe(1024);
});

// The cap is checked against the size the headers announce as well as the bytes read, so this
// one is refused with none of the body streamed. Without that half, MockResponse ends the
// transfer with "Transfer closed with 99999 bytes remaining to read" -- a TransportException
// too, which is why the assertion is on the message and not just the class.
it('refuses an answer that announces an oversize body before any of it streams', function (): void {
    $response = egressOver($seen, new MockResponse('', ['response_headers' => ['content-length' => '99999']]))->request('POST', 'https://mcp.example.test/mcp');
    expect(static fn() => $response->getContent())->toThrow(TransportException::class, '1024');
});

it('chains a caller\'s own on_progress after the cap', function (): void {
    $calls = 0;
    egressOver($seen, new MockResponse('{}'))->request('POST', 'https://mcp.example.test/mcp', ['on_progress' => static function () use (&$calls): void {
        $calls++;
    }])->getContent();
    expect($calls)->toBeGreaterThan(0);
});

it('refuses a server that is not https and one with no host name, both before any lookup, and one whose address AddressPin refuses, building nothing', function (): void {
    $never = static fn(string $host, string $url): array => throw new LogicException('no lookup expected');
    expect(static fn() => (new Egress($never, new MockHttpClient()))->client(mcpServerConfig('http://mcp.example.test/mcp')))->toThrow(AddressRefused::class, 'https');
    // `https://./mcp` parses as an https URL whose host trims to nothing, so it reaches the
    // second refusal and has to be told the thing that is actually wrong with it.
    expect(static fn() => (new Egress($never, new MockHttpClient()))->client(mcpServerConfig('https://./mcp')))->toThrow(AddressRefused::class, 'host name');
    // The default check is AddressPin's own; a literal needs no DNS, so this runs it for real.
    foreach (['https://127.0.0.1/mcp', 'https://[::1]/mcp', 'https://169.254.169.254/'] as $url) {
        expect(static fn() => (new Egress(null, new MockHttpClient()))->client(mcpServerConfig($url)))->toThrow(AddressRefused::class);
    }
    $refusing = static fn(string $host, string $url): array => throw new AddressRefused('mcp.example.test resolves to 10.0.0.7');
    expect(static fn() => (new Egress($refusing, new MockHttpClient()))->client(mcpServerConfig()))->toThrow(AddressRefused::class, '10.0.0.7');
});

// Settings refuse a URL with a user name or a password at save (Schema's `userinfo` fault), but a
// row written round the schema reaches this as it was stored. A credential there would be sent
// as Basic auth by the transport, and quoted by any message that quotes the URL.
it('refuses a URL carrying a user name or a password before any lookup, and does not repeat it', function (string $url): void {
    $never = static fn(string $host, string $url): array => throw new LogicException('no lookup expected');
    $thrown = null;
    try {
        (new Egress($never, new MockHttpClient()))->client(mcpServerConfig($url));
    } catch (AddressRefused $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(AddressRefused::class)
        ->and($thrown?->getMessage())->toContain('user name or password')->toContain('header')
        ->not->toContain('s3cret')->not->toContain('tok3n');
})->with([
    'a user and a password' => ['https://alice:s3cret@mcp.example.test/mcp'],
    'a token for a user name' => ['https://tok3n@mcp.example.test/mcp'],
    'a password with no user name' => ['https://:s3cret@mcp.example.test/mcp'],
    'an empty user name' => ['https://@mcp.example.test/mcp'],
]);

// With zend.exception_ignore_args off, a trace carries every frame's arguments, and client()'s
// is the ServerConfig, header value and all.
it('keeps the server out of its own frame in a trace', function (): void {
    $before = (string) ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    $thrown = null;
    try {
        (new Egress(static fn(string $host, string $url): array => throw new AddressRefused('refused'), new MockHttpClient()))->client(mcpServerConfig());
    } catch (AddressRefused $e) {
        $thrown = $e;
    } finally {
        ini_set('zend.exception_ignore_args', $before);
    }
    $frames = array_values(array_filter($thrown?->getTrace() ?? [], static fn(array $frame): bool => ($frame['class'] ?? '') === Egress::class && $frame['function'] === 'client'));
    expect($frames)->toHaveCount(1)
        ->and($frames[0]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);
});
