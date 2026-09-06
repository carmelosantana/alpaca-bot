<?php

declare(strict_types=1);

use AlpacaBot\Provider\BearerHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\MockHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A MockHttpClient that records, per request, the Authorization header exactly as
 * Symfony prepared it for the wire (the `normalized_headers` bucket, "Name: value" lines).
 *
 * @param list<array{url: string, authorization: list<string>|null}> $wire
 */
function wireRecorder(array &$wire, string $body = '{}'): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url, array $options) use (&$wire, $body): MockResponse {
        $wire[] = ['url' => $url, 'authorization' => $options['normalized_headers']['authorization'] ?? null];
        return new MockResponse($body);
    });
}

it('replaces an Authorization header given as a name => value pair', function (): void {
    $wire = [];
    $client = new BearerHttpClient(wireRecorder($wire), 'sk-secret');
    $client->request('POST', 'http://ollama:11434/v1/chat/completions', [
        'headers' => ['Content-Type' => 'application/json', 'authorization' => 'Bearer ollama-local'],
        'json' => ['model' => 'm'],
    ])->getStatusCode();
    expect($wire)->toHaveCount(1)
        ->and($wire[0]['authorization'])->toBe(['Authorization: Bearer sk-secret']);
});

it('replaces an Authorization header given as a "Name: value" line', function (): void {
    $wire = [];
    $client = new BearerHttpClient(wireRecorder($wire), 'sk-secret');
    $client->request('GET', 'http://ollama:11434/v1/models', [
        'headers' => ['Authorization: Bearer ollama-local', 'Accept: application/json'],
    ])->getStatusCode();
    expect($wire[0]['authorization'])->toBe(['Authorization: Bearer sk-secret']);
});

it('adds the header to a request that carries no headers at all', function (): void {
    $wire = [];
    $client = new BearerHttpClient(wireRecorder($wire), 'sk-secret');
    $client->request('GET', 'http://ollama:11434/api/tags')->getStatusCode();
    expect($wire[0]['authorization'])->toBe(['Authorization: Bearer sk-secret']);
});

it('keeps the token across withOptions() and streams through the wrapped client', function (): void {
    $wire = [];
    $client = (new BearerHttpClient(wireRecorder($wire, 'chunk'), 'sk-secret'))->withOptions(['timeout' => 7]);
    $response = $client->request('GET', 'http://ollama:11434/api/tags');
    $content = '';
    foreach ($client->stream($response) as $chunk) {
        $content .= $chunk->getContent();
    }
    expect($client)->toBeInstanceOf(BearerHttpClient::class)
        ->and($wire[0]['authorization'])->toBe(['Authorization: Bearer sk-secret'])
        ->and($content)->toBe('chunk');
});
