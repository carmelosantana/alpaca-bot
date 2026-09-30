<?php

declare(strict_types=1);

use AlpacaBot\Mcp\TransientSessions;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpServer;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpSession;
use Brain\Monkey\Functions;

// The store McpClient keeps a server's protocol version and session id in between requests. The
// key is McpServer::sessionKey(), a sha256 of the URL and the headers, so the store never sees a
// credential; the session id it holds is one, so the transient carries an expiry and is not
// autoloaded.

/** @param array<string, mixed> $transients the store the three transient functions share, by name */
function mcpSessionTransients(array &$transients): void
{
    Functions\when('get_transient')->alias(static function (string $name) use (&$transients): mixed {
        return $transients[$name] ?? false;
    });
    Functions\when('set_transient')->alias(static function (string $name, mixed $value, int $ttl = 0) use (&$transients): bool {
        $transients[$name] = $value;
        $transients['ttl:' . $name] = $ttl;
        return true;
    });
    Functions\when('delete_transient')->alias(static function (string $name) use (&$transients): bool {
        unset($transients[$name], $transients['ttl:' . $name]);
        return true;
    });
}

function mcpSessionKey(): string
{
    return (new McpServer(url: 'https://mcp.example.test/mcp', headers: ['Authorization' => 'Bearer sess-secret-5a1']))->sessionKey();
}

it('gives back the session it saved, under the md5 of the key and for an hour', function (): void {
    $t = [];
    mcpSessionTransients($t);
    $store = new TransientSessions();
    $key = mcpSessionKey();
    $store->save($key, new McpSession(McpServer::PROTOCOL_2025, 'session-7c1'));
    $name = 'alpaca_bot_mcp_session_' . md5($key);
    expect($t[$name])->toBe(['protocolVersion' => '2025-11-25', 'sessionId' => 'session-7c1'])
        ->and($t['ttl:' . $name])->toBe(3600)
        ->and($store->load($key))->toEqual(new McpSession('2025-11-25', 'session-7c1'));
    $store->save($key, new McpSession(McpServer::PROTOCOL_2026));
    expect($store->load($key))->toEqual(new McpSession('2026-07-28', null));
});

it('names the transient by the md5 of the key alone, never the key itself', function (): void {
    $t = [];
    mcpSessionTransients($t);
    $key = mcpSessionKey();
    (new TransientSessions())->save($key, new McpSession(McpServer::PROTOCOL_2025, 'session-7c1'));
    $names = array_values(array_filter(array_keys($t), static fn(string $name): bool => !str_starts_with($name, 'ttl:')));
    expect($names)->toBe(['alpaca_bot_mcp_session_' . md5($key)])
        ->and(implode('', $names))->not->toContain($key)
        ->and(serialize($t))->not->toContain('sess-secret-5a1');
});

it('answers null for a key it holds nothing under', function (): void {
    $t = [];
    mcpSessionTransients($t);
    expect((new TransientSessions())->load(mcpSessionKey()))->toBeNull();
});

it('answers null for anything else stored under the name', function (mixed $stored): void {
    $t = ['alpaca_bot_mcp_session_' . md5(mcpSessionKey()) => $stored];
    mcpSessionTransients($t);
    expect((new TransientSessions())->load(mcpSessionKey()))->toBeNull();
})->with([
    'a string' => ['2025-11-25'],
    'no version' => [['sessionId' => 'session-7c1']],
    'a version that is not a string' => [['protocolVersion' => 20251125, 'sessionId' => null]],
    'no session id at all' => [['protocolVersion' => '2025-11-25']],
    'a session id that is not a string' => [['protocolVersion' => '2025-11-25', 'sessionId' => 7]],
    'an object' => [new stdClass()],
]);

it('forgets by deleting the transient', function (): void {
    $t = [];
    mcpSessionTransients($t);
    $store = new TransientSessions();
    $key = mcpSessionKey();
    $store->save($key, new McpSession(McpServer::PROTOCOL_2025, 'session-7c1'));
    $store->forget($key);
    expect($t)->toBe([])
        ->and($store->load($key))->toBeNull();
});
