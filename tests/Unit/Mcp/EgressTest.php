<?php

declare(strict_types=1);

use AlpacaBot\Mcp\Egress;
use AlpacaBot\Toolkit\AddressRefused;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Exception\RedirectionException;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Exception\TransportException;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\MockHttpClient;
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
    return (new Egress($resolve ?? static fn(string $host, string $url): string => '93.184.216.34', $mock))->client(mcpServerConfig());
}

it('pins the connection to the address the check passed, turns redirects off, and takes its timeouts from the server row', function (): void {
    $asked = [];
    $client = egressOver($seen, new MockResponse('{"jsonrpc":"2.0"}'), static function (string $host, string $url) use (&$asked): string {
        $asked[] = [$host, $url];
        return '93.184.216.34';
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
    $never = static fn(string $host, string $url): string => throw new LogicException('no lookup expected');
    expect(static fn() => (new Egress($never, new MockHttpClient()))->client(mcpServerConfig('http://mcp.example.test/mcp')))->toThrow(AddressRefused::class, 'https');
    // `https://./mcp` parses as an https URL whose host trims to nothing, so it reaches the
    // second refusal and has to be told the thing that is actually wrong with it.
    expect(static fn() => (new Egress($never, new MockHttpClient()))->client(mcpServerConfig('https://./mcp')))->toThrow(AddressRefused::class, 'host name');
    // The default check is AddressPin's own; a literal needs no DNS, so this runs it for real.
    foreach (['https://127.0.0.1/mcp', 'https://[::1]/mcp', 'https://169.254.169.254/'] as $url) {
        expect(static fn() => (new Egress(null, new MockHttpClient()))->client(mcpServerConfig($url)))->toThrow(AddressRefused::class);
    }
    $refusing = static fn(string $host, string $url): string => throw new AddressRefused('mcp.example.test resolves to 10.0.0.7');
    expect(static fn() => (new Egress($refusing, new MockHttpClient()))->client(mcpServerConfig()))->toThrow(AddressRefused::class, '10.0.0.7');
});
