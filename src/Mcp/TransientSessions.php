<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpSession;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpSessionStore;

/**
 * Where php-agents' McpClient keeps what it learnt about a server between PHP requests: the
 * protocol version it detected and, for a 2025-11-25 server that issues one, the session id. A
 * transient per key, `alpaca_bot_mcp_session_{md5}`.
 *
 * The key is the library's: McpClient passes McpServer::sessionKey(), a sha256 of the server's URL
 * and its headers, names and values alike. So this store never sees a credential, a changed
 * credential or URL reads as another server and starts a session of its own, and nothing here is
 * keyed by server id or by user. The name holds md5() of the key rather than the key because a
 * transient's name is length-limited.
 *
 * The session id is a credential for that session, so every save passes an expiry (an hour):
 * core's set_transient() stores a transient that has one with autoload off, and one that has none
 * autoloaded on every request. Only the two fields are kept, and load() answers null for anything
 * else found under the name, which the client reads as nothing remembered. forget() deletes the
 * transient; the client calls it when the server says the session is gone, and sends no DELETE,
 * so there is nothing else to clean up.
 *
 * @since 0.6.0
 */
final class TransientSessions implements McpSessionStore
{
    private const PREFIX = 'alpaca_bot_mcp_session_';

    private const TTL = 3600;

    public function load(string $key): ?McpSession
    {
        $stored = get_transient(self::name($key));
        if (!is_array($stored) || !is_string($stored['protocolVersion'] ?? null) || !array_key_exists('sessionId', $stored)) {
            return null;
        }
        $id = $stored['sessionId'];
        return $id === null || is_string($id) ? new McpSession($stored['protocolVersion'], $id) : null;
    }

    public function save(string $key, McpSession $session): void
    {
        set_transient(self::name($key), ['protocolVersion' => $session->protocolVersion, 'sessionId' => $session->sessionId], self::TTL);
    }

    public function forget(string $key): void
    {
        delete_transient(self::name($key));
    }

    private static function name(string $key): string
    {
        return self::PREFIX . md5($key);
    }
}
