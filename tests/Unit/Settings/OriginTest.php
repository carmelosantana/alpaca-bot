<?php

declare(strict_types=1);

use AlpacaBot\Settings\Origin;

// The one origin comparison behind both rules that keep a stored secret from following its URL
// to another server: an MCP header value (Mcp\ServerSettings, R76) and the provider API key
// (Schema::providerKeyClearedByMove(), #4539). Scheme, host and port; a path or query is not
// part of it.
it('reads a URL as moved only when its scheme, host or port differ', function (string $to, bool $moved): void {
    expect(Origin::moved('https://mcp.example.com/mcp', $to))->toBe($moved);
})->with([
    'the host changed' => ['https://steal.example.net/mcp', true],
    'the port changed' => ['https://mcp.example.com:8443/mcp', true],
    'the scheme changed' => ['http://mcp.example.com/mcp', true],
    'the scheme changed, the port as it was' => ['http://mcp.example.com:443/mcp', true],
    'a subdomain' => ['https://evil.mcp.example.com/mcp', true],
    'nothing that parses' => ['not a url', true],
    'only the path changed' => ['https://mcp.example.com/v2/mcp?x=1', false],
    'the default port written out' => ['https://mcp.example.com:443/mcp', false],
    'the host in capitals' => ['https://MCP.Example.COM/mcp', false],
    'the host with its final dot' => ['https://mcp.example.com./mcp', false],
]);

it('takes a left-out port as the scheme\'s own for http too', function (): void {
    expect(Origin::moved('http://localhost/v1', 'http://localhost:80/v1'))->toBeFalse()
        ->and(Origin::moved('http://localhost:11434/v1', 'http://localhost/v1'))->toBeTrue();
});

it('reads a URL moved from one that has no origin, even to the same string', function (): void {
    expect(Origin::moved('', ''))->toBeTrue()
        ->and(Origin::moved('/v1', '/v1'))->toBeTrue();
});
