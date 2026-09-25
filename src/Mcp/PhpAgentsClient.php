<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpAuthException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpClient;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpClientInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpProtocolException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpRedirectException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpRpcException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpServer;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpSessionStore;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpToolDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpTransportException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpUnsupportedVersionException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * The plugin's ClientInterface over php-agents' McpClient. It is thin on purpose: the seam let the
 * plugin's half be built before the library had a client, and now that it has one this translates
 * two things and nothing else.
 *
 * - The library's tool definition becomes ToolDefinition, with the name, description, input
 *   schema, annotations and title passed through as the library decoded them, annotations
 *   untouched, since `annotations.title` is part of the hash. The pins an administrator approved
 *   were computed by ToolDefinition::fingerprint(), and they are checked with it, not with the
 *   library's.
 * - The library's failures become McpUnavailable, so McpToolkit and Discovery keep reading any
 *   thrown RuntimeException as "no answer from this server". The message is the plugin's own
 *   sentence for the failure's class (the credentials refused, a redirect, no shared protocol
 *   version, a JSON-RPC error, an answer that is not MCP, the transport), carrying at most the
 *   HTTP status or the JSON-RPC code. None of the library's text goes into it: a transport's
 *   message can quote the URL, a query-string token with it, and a JSON-RPC error carries the
 *   server's own words and its `data`. The library's exception is kept as `previous`, for a
 *   debugger in the same process; nothing in the plugin prints or logs it.
 * A tool that reports `isError` is a result for the model, not unavailability: the library's
 * ToolResult comes back as it was answered, an error one included.
 *
 * Everything else is the library's: which protocol version a server speaks, the session, how
 * content blocks become a result, a retry. over() builds its objects from a ServerConfig, through
 * Egress for the HTTP client, so every request the library makes is held to the addresses Egress
 * checked and pinned.
 *
 * @since 0.6.0
 */
final class PhpAgentsClient implements ClientInterface
{
    /**
     * The library's client for `$server`. The one header the row names is sent only when both its
     * name and its value are set. A name PHP would make an int array key (a whole number written
     * plainly: `123`, `-1`, not `0123`) is refused here, before anything is built: Symfony's client
     * reads an int-keyed header as a whole `Name: value` line, so the value would be sent as the
     * header's name.
     *
     * @throws McpUnavailable when the header name is a whole number
     * @throws \AlpacaBot\Toolkit\AddressRefused when Egress refuses the address
     */
    public static function over(#[\SensitiveParameter] ServerConfig $server, Egress $egress, ?McpSessionStore $sessions = null): self
    {
        $headers = [];
        if ($server->headerName !== '' && $server->headerValue !== '') {
            // Exactly the strings PHP turns into an int key: "123" and "-1", not "0123" or "-0".
            if ((string) (int) $server->headerName === $server->headerName) {
                /* translators: %s: the header name an administrator gave an MCP server, e.g. 123 */
                throw new McpUnavailable(sprintf(__('This server was not contacted: its header name, %s, is a whole number, and the MCP client would send the header\'s value in the name\'s place. Give the header a name with a letter in it.', 'alpaca-bot'), $server->headerName));
            }
            $headers[$server->headerName] = $server->headerValue;
        }
        // Named arguments: the library only ever adds trailing optional parameters, and
        // protocolVersion already sits between maxResponseBytes and maxResultBytes.
        $library = new McpServer(
            url: $server->url,
            headers: $headers,
            timeout: $server->timeout,
            maxResponseBytes: $server->maxBytes,
        );
        return new self(new McpClient($library, $egress->client($server), $sessions));
    }

    public function __construct(private McpClientInterface $client) {}

    public function listTools(): array
    {
        try {
            $listed = $this->client->listTools();
        } catch (\RuntimeException $e) {
            throw self::unavailable($e);
        }
        return array_map(
            static fn(McpToolDefinition $d): ToolDefinition => new ToolDefinition($d->name, $d->description, $d->inputSchema, $d->annotations, $d->title),
            $listed,
        );
    }

    public function callTool(string $name, array $arguments): ToolResult
    {
        try {
            return $this->client->callTool($name, $arguments);
        } catch (\RuntimeException $e) {
            throw self::unavailable($e);
        }
    }

    /** The plugin's sentence for `$e`'s class of failure (the class docblock says why none of `$e`'s text). */
    private static function unavailable(\RuntimeException $e): McpUnavailable
    {
        $message = match (true) {
            /* translators: %d: an HTTP status, 401 or 403 */
            $e instanceof McpAuthException => sprintf(__('The MCP server refused the credentials it was sent (HTTP %d).', 'alpaca-bot'), $e->status),
            /* translators: %d: an HTTP status, e.g. 302 */
            $e instanceof McpRedirectException => sprintf(__('The MCP server answered with a redirect (HTTP %d), which is not followed.', 'alpaca-bot'), $e->status),
            $e instanceof McpTransportException => __('The MCP server could not be reached, or gave no usable HTTP answer: a network error, a timeout, an answer over the byte cap or an HTTP error status.', 'alpaca-bot'),
            $e instanceof McpUnsupportedVersionException => __('The MCP server speaks no protocol version this client does.', 'alpaca-bot'),
            /* translators: %d: a JSON-RPC error code, e.g. -32601 */
            $e instanceof McpRpcException => sprintf(__('The MCP server answered with JSON-RPC error %d.', 'alpaca-bot'), $e->rpcCode),
            $e instanceof McpProtocolException => __('The MCP server answered, but not in a way this client can use.', 'alpaca-bot'),
            default => __('The MCP request failed.', 'alpaca-bot'),
        };
        return new McpUnavailable($message, 0, $e);
    }
}
