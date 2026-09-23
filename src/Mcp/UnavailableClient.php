<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * The client ClientFactory builds by default while the bundled php-agents has no MCP client
 * (composer.lock pins 0.15.2, which has none). Every call is refused with McpUnavailable::NOT_YET,
 * so a caller gets a sentence that says what is missing rather than a failure that looks like a
 * broken server.
 *
 * It is handed no ServerConfig, so its refusal cannot carry a server's URL, header or secret.
 *
 * @since 0.6.0
 */
final class UnavailableClient implements ClientInterface
{
    public function listTools(): array
    {
        throw new McpUnavailable(McpUnavailable::NOT_YET);
    }

    public function callTool(string $name, array $arguments): ToolResult
    {
        throw new McpUnavailable(McpUnavailable::NOT_YET);
    }
}
