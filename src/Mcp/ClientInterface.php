<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * What the plugin asks of an MCP client: list a server's tools, and call one. ClientFactory
 * decides which implementation a caller gets. Sessions, protocol versions, mapping content blocks
 * and retries belong to an implementation, not to this interface.
 *
 * Both methods throw McpUnavailable when the server cannot be reached or does not answer as an MCP
 * server. A ToolResult, including one whose status is an error, means the server answered.
 *
 * @since 0.6.0
 */
interface ClientInterface
{
    /**
     * @return list<ToolDefinition>
     * @throws McpUnavailable
     */
    public function listTools(): array;

    /**
     * @param array<string, mixed> $arguments the model's arguments, as the tool's schema describes them
     * @throws McpUnavailable
     */
    public function callTool(string $name, array $arguments): ToolResult;
}
