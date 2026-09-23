<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * A server could not be reached, or did not answer as an MCP server. It extends RuntimeException,
 * so a caller that only needs to know the server gave nothing can catch that, and one that shows
 * the reason can catch this.
 *
 * @since 0.6.0
 */
final class McpUnavailable extends \RuntimeException
{
    /**
     * UnavailableClient's whole answer. It names the missing library and nothing about the server
     * that was asked for: no URL, no header, no credential.
     */
    public const NOT_YET = 'The MCP client arrives with php-agents 0.16.';
}
