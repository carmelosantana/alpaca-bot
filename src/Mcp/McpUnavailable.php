<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * A server could not be reached, or did not answer as an MCP server. It extends RuntimeException,
 * so a caller that only needs to know no answer came back can catch that, and one that shows the
 * reason can catch this. PhpAgentsClient writes its message in the plugin's own words, and keeps
 * the library's exception as `previous`.
 *
 * @since 0.6.0
 */
final class McpUnavailable extends \RuntimeException {}
