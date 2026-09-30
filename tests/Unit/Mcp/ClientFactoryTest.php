<?php

declare(strict_types=1);

use AlpacaBot\Mcp\ClientFactory;
use AlpacaBot\Mcp\Egress;
use AlpacaBot\Mcp\McpUnavailable;
use AlpacaBot\Mcp\PhpAgentsClient;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Tests\Integration\FakeClient;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpServer;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\MockHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Response\MockResponse;
use Brain\Monkey\Functions;

function mcpServer(array $approved = []): ServerConfig
{
    return new ServerConfig('tracker', 'https://mcp.example.com/mcp', 'Authorization', 'Bearer t', 'trk', approved: $approved);
}

/*
 * With no closure the factory builds php-agents' client through PhpAgentsClient::over(), over the
 * Egress it was handed and with TransientSessions as the session store. The Egress here answers a
 * public address and talks to a MockHttpClient, so nothing is looked up or sent.
 */
it('builds php-agents\' client by default, through the Egress it was handed, keeping the session in a transient', function (): void {
    $transients = [];
    Functions\when('get_transient')->alias(static fn(string $name): mixed => $transients[$name] ?? false);
    Functions\when('set_transient')->alias(static function (string $name, mixed $value, int $ttl = 0) use (&$transients): bool {
        $transients[$name] = $value;
        return true;
    });
    $urls = [];
    $mock = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
        $urls[] = $url;
        return new MockResponse('{"jsonrpc":"2.0","id":1,"result":{"tools":[{"name":"search","inputSchema":{"type":"object"}}]}}', ['response_headers' => ['content-type' => 'application/json']]);
    });
    $lookups = [];
    $client = (new ClientFactory(null, new Egress(static function (string $host, string $url) use (&$lookups): array {
        $lookups[] = $host;
        return ['93.184.216.34'];
    }, $mock)))->for(mcpServer());
    // The build looks the server's name up (the address check) and sends the server nothing.
    expect($lookups)->toBe(['mcp.example.com'])->and($urls)->toBe([]);
    expect($client)->toBeInstanceOf(PhpAgentsClient::class)
        ->and(array_map(static fn(ToolDefinition $d): string => $d->name, $client->listTools()))->toBe(['search'])
        ->and($urls)->toBe(['https://mcp.example.com/mcp'])
        ->and(array_keys($transients))->toBe(['alpaca_bot_mcp_session_' . md5((new McpServer(url: 'https://mcp.example.com/mcp', headers: ['Authorization' => 'Bearer t']))->sessionKey())])
        ->and(array_values($transients))->toBe([['protocolVersion' => '2026-07-28', 'sessionId' => null]]);
});

it('builds whatever it was given instead, with the server it was asked about', function (): void {
    $seen = null;
    $fake = new FakeClient([new ToolDefinition('search', 'Search.', ['type' => 'object'])]);
    $factory = new ClientFactory(static function (ServerConfig $server) use ($fake, &$seen) {
        $seen = $server;
        return $fake;
    });
    expect($factory->for(mcpServer()))->toBe($fake)->and($seen?->prefix)->toBe('trk');
});

it('the fake lists what it was seeded with, records every call, and answers a canned result or an error', function (): void {
    $fake = new FakeClient(
        [new ToolDefinition('search', 'Search.', ['type' => 'object'])],
        ['search' => ToolResult::success('two hits'), 'boom' => new RuntimeException('down')],
    );
    expect($fake->listTools()[0]->name)->toBe('search')
        ->and($fake->listed)->toBe(1)
        ->and($fake->callTool('search', ['q' => 'x'])->content)->toBe('two hits')
        ->and($fake->calls)->toBe([['name' => 'search', 'arguments' => ['q' => 'x']]])
        ->and($fake->callTool('nothing', [])->status)->toBe(ToolResultStatus::Error);
    expect(fn() => $fake->callTool('boom', []))->toThrow(RuntimeException::class);
    expect(array_column($fake->calls, 'name'))->toBe(['search', 'nothing', 'boom']);
    $failing = new FakeClient([], [], new McpUnavailable('list failed'));
    expect(fn() => $failing->listTools())->toThrow(McpUnavailable::class);
    expect($failing->listed)->toBe(1);
});

// With zend.exception_ignore_args off, a trace carries every frame's arguments, so a closure that
// hands back something that is not a client would leave the ServerConfig, header value and all, in
// the TypeError for() throws.
it('keeps the server out of the trace when the closure hands back something that is not a client', function (): void {
    $before = (string) ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    $thrown = null;
    try {
        (new ClientFactory(static fn(ServerConfig $server): object => new stdClass()))->for(mcpServer());
    } catch (TypeError $e) {
        $thrown = $e;
    } finally {
        ini_set('zend.exception_ignore_args', $before);
    }
    $frames = array_values(array_filter($thrown?->getTrace() ?? [], static fn(array $frame): bool => ($frame['class'] ?? '') === ClientFactory::class && $frame['function'] === 'for'));
    expect($thrown)->toBeInstanceOf(TypeError::class)
        ->and($frames)->toHaveCount(1)
        ->and($frames[0]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);
});

// The default build's own frames hold the server too: the closure's, PhpAgentsClient::over()'s
// and Egress::client()'s. Egress refusing the address throws from under all three, so the trace
// passes through each of them.
it('keeps the server out of the default build\'s frames when building fails', function (): void {
    $before = (string) ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    $thrown = null;
    try {
        (new ClientFactory(null, new Egress(static function (string $host, string $url): array {
            throw new AlpacaBot\Toolkit\AddressRefused('refused');
        })))->for(mcpServer());
    } catch (AlpacaBot\Toolkit\AddressRefused $e) {
        $thrown = $e;
    } finally {
        ini_set('zend.exception_ignore_args', $before);
    }
    $frames = array_values(array_filter($thrown?->getTrace() ?? [], static fn(array $frame): bool => ($frame['class'] ?? '') === PhpAgentsClient::class && $frame['function'] === 'over'
        || ($frame['class'] ?? '') === Egress::class && $frame['function'] === 'client'
        || str_starts_with($frame['function'], '{closure') && ($frame['class'] ?? '') === ClientFactory::class));
    expect($thrown)->toBeInstanceOf(AlpacaBot\Toolkit\AddressRefused::class)
        ->and($frames)->toHaveCount(3)
        ->and($frames[0]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($frames[1]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($frames[2]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);
});
