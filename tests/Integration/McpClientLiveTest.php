<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Mcp\McpUnavailable;
use AlpacaBot\Mcp\PhpAgentsClient;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Mcp\TransientSessions;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpClient;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpRpcException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpServer;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\HttpClient;

/**
 * One round trip against a real MCP server, through the plugin's PhpAgentsClient over php-agents'
 * McpClient: the server lists at least one tool, each listed definition hashes the same on a
 * second listing, a tool the server marks `readOnlyHint: true` answers a call, and a tool name the
 * server does not have comes back as McpUnavailable with a JSON-RPC error behind it.
 *
 * Opt-in. It skips unless ALPACA_BOT_MCP_URL names the server; ALPACA_BOT_MCP_HEADER, as
 * `Name: value`, is the one header sent with every request (`Authorization: Basic …` for the
 * WordPress MCP Adapter). A plain run of the suite sets neither, so it stays off the network.
 *
 * The client is built here, not through Mcp\ClientFactory, and its HTTP client is Symfony's own,
 * not Mcp\Egress's: the server this was written against is the MCP Adapter in wp-env's development
 * WordPress, which serves plain http, and Egress refuses anything but https. The bootstrap's guard
 * on the container's ClientFactory is untouched by this test; Egress's pinning is covered by its
 * unit tests. The sessions are the plugin's TransientSessions, and the second listing and both
 * calls go through a client built afresh over them, as the next PHP request's would be: McpClient
 * reads the store once, on its first use, and keeps what it holds after that in memory, so a
 * second listing through the first client would not read the transient at all.
 *
 * bin/test-integration.sh does not pass these two variables: `wp-env run` takes no environment,
 * so a value would have to go on the container's command line, where `ps` shows it. Run it with
 * `docker compose exec`, which reads a bare `-e NAME` from the calling shell, from the checkout:
 *
 *     export ALPACA_BOT_MCP_URL=http://wordpress/wp-json/mcp/mcp-adapter-default-server
 *     export ALPACA_BOT_MCP_HEADER="Authorization: Basic $(printf %s "admin:$APP_PASSWORD" | base64 -w0)"
 *     docker compose -f "$(pnpm exec wp-env status --json | jq -r .installPath)/docker-compose.yml" \
 *         exec -T -e ALPACA_BOT_MCP_URL -e ALPACA_BOT_MCP_HEADER \
 *         -e WP_TESTS_DB_NAME=tests-wordpress -e WP_TESTS_DOMAIN=localhost \
 *         -w "/var/www/html/wp-content/plugins/$(basename "$PWD")" --user "$(id -u):$(id -g)" \
 *         tests-cli php tools/integration/vendor/bin/phpunit -c phpunit.integration.xml \
 *         --filter McpClientLiveTest
 *
 * `wordpress` is the development WordPress's service on wp-env's compose network, which the
 * tests-cli container shares.
 *
 * @group mcp
 */
final class McpClientLiveTest extends TestCase
{
    private McpServer $server;

    public function set_up(): void
    {
        parent::set_up();
        $url = (string) getenv('ALPACA_BOT_MCP_URL');
        if ($url === '') {
            $this->markTestSkipped('set ALPACA_BOT_MCP_URL (and ALPACA_BOT_MCP_HEADER, "Name: value", if the server needs one) to run the live MCP test');
        }
        $headers = [];
        $header = (string) getenv('ALPACA_BOT_MCP_HEADER');
        if ($header !== '') {
            $colon = strpos($header, ':');
            $this->assertNotFalse($colon, 'ALPACA_BOT_MCP_HEADER is not "Name: value"');
            $headers[trim(substr($header, 0, $colon))] = trim(substr($header, $colon + 1));
        }
        $this->server = new McpServer(url: $url, headers: $headers);
    }

    public function test_a_real_server_lists_calls_and_refuses_as_the_seam_says(): void
    {
        $first = $this->client()->listTools();
        $this->assertNotEmpty($first, 'the server listed no tools');
        // What the first listing learnt (the protocol version, and a 2025-11-25 server's session)
        // is in the transient, and the next client starts from it.
        $stored = get_transient('alpaca_bot_mcp_session_' . md5($this->server->sessionKey()));
        $this->assertIsArray($stored, 'the first listing stored no session');
        $this->assertContains($stored['protocolVersion'] ?? null, [McpServer::PROTOCOL_2026, McpServer::PROTOCOL_2025]);
        $client = $this->client();
        $second = $client->listTools();
        $this->assertSame(self::fingerprints($first), self::fingerprints($second), 'a tool hashed differently on the second listing');

        $readOnly = array_values(array_filter($first, static fn(ToolDefinition $d): bool => ($d->annotations['readOnlyHint'] ?? null) === true));
        $this->assertNotEmpty($readOnly, 'the server lists no tool marked readOnlyHint: true');
        $result = $client->callTool($readOnly[0]->name, []);
        $this->assertSame(ToolResultStatus::Success, $result->status, $readOnly[0]->name . ' answered an error: ' . $result->content);
        $this->assertNotSame('', $result->content);

        $thrown = null;
        try {
            $client->callTool('alpaca_bot_live_test_no_such_tool', []);
        } catch (McpUnavailable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'an unknown tool did not surface as McpUnavailable');
        $this->assertInstanceOf(McpRpcException::class, $thrown->getPrevious());
        $this->assertSame(sprintf('The MCP server answered with JSON-RPC error %d.', $thrown->getPrevious()->rpcCode), $thrown->getMessage());
    }

    private function client(): PhpAgentsClient
    {
        return new PhpAgentsClient(new McpClient($this->server, HttpClient::create(), new TransientSessions()));
    }

    /**
     * @param list<ToolDefinition> $definitions
     * @return array<string, string>
     */
    private static function fingerprints(array $definitions): array
    {
        $out = [];
        foreach ($definitions as $d) {
            $out[$d->name] = $d->fingerprint();
        }
        return $out;
    }
}
