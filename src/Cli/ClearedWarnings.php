<?php

declare(strict_types=1);

namespace AlpacaBot\Cli;

/**
 * What a command-line write says when it cleared a secret by moving its URL: one line naming the
 * MCP servers whose header value went (Mcp\WriteCheck's `$cleared`), and one for the provider key
 * (Settings\ProviderKey::clearedByMove()). `wp alpaca-bot settings` (ChatCommand::settings()) and a
 * raw `wp option update|patch|add alpaca_bot_settings` (RawOptionWrite) both print these, so the
 * two say the same thing.
 *
 * @since 0.6.2
 */
final class ClearedWarnings
{
    /**
     * @param list<string> $servers the ids of the MCP servers whose header value was cleared
     * @param bool         $key     whether the provider key was cleared
     * @return list<string>
     */
    public static function lines(array $servers, bool $key): array
    {
        $lines = [];
        if ($servers !== []) {
            $lines[] = sprintf(
                count($servers) === 1
                    ? 'The header value of MCP server %s was cleared, because its address moved to another host or port. Send it again.'
                    : 'The header values of MCP servers %s were cleared, because their addresses moved to another host or port. Send them again.',
                implode(', ', $servers),
            );
        }
        if ($key) {
            $lines[] = 'provider.api_key was cleared, because provider.base_url moved to another host, port or scheme. Set it again: wp alpaca-bot settings provider.api_key <key>';
        }
        return $lines;
    }
}
