<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Mcp\Egress;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\NativeHttpClient;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The plugin's two Symfony HttpClient callers, the provider Factory builds and the MCP egress
 * transport, on a PHP with curl_init and curl_exec in disable_functions (Kanboard #4690). There
 * extension_loaded('curl') is still true, so Symfony's own HttpClient::create() answers with its
 * Curl client, and the first request calls curl_init() and dies with an Error. The plugin has to
 * hand both callers Symfony's Native client there instead, as web_fetch refuses there (NoCurlTest).
 *
 * The run is NoCurlTest's: `WP_NO_CURL=1 bash bin/test-integration.sh --group no-curl
 * --fail-on-skipped`. Every test here skips where both cURL functions exist.
 *
 * @group no-curl
 */
final class NoCurlClientTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $this->markTestSkipped('cURL is usable here; run with WP_NO_CURL=1 (bin/test-integration.sh).');
        }
    }

    /**
     * A chat turn through the provider Factory::make() builds, over the client it really builds.
     * The suite swaps a built OllamaProvider for OfflineProvider (bootstrap.php), so that guard is
     * taken off for this test (WP_UnitTestCase puts the hooks back after it); what stands in for
     * it is the address: port 1 on the loopback, where nothing listens, so the request is refused
     * on the spot and nothing leaves the container. A refused connection is a failure with a
     * message; what must not happen is a PHP Error, which is what the Curl client throws here.
     */
    public function test_a_provider_call_fails_with_a_message_not_an_error(): void
    {
        remove_all_filters('alpaca_bot/provider');
        $provider = (new Factory(new Store(['provider.kind' => 'ollama', 'provider.base_url' => 'http://127.0.0.1:1/v1', 'provider.timeout' => 5])))->make('llama3');
        $this->assertInstanceOf(OllamaProvider::class, $provider);

        $caught = null;
        try {
            $provider->chat([new UserMessage('Hello?')]);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'nothing listens on 127.0.0.1:1, so the call cannot succeed');
        $this->assertNotInstanceOf(\Error::class, $caught, get_class($caught) . ': ' . $caught->getMessage());
        $this->assertNotSame('', $caught->getMessage());
    }

    /**
     * The MCP egress transport as Egress builds it by default (no transport handed in), read off
     * the pinned client it returns. The address check is handed in, so nothing is looked up, and
     * nothing is requested: building the client connects to nothing.
     */
    public function test_the_mcp_egress_transport_is_symfony_native_client(): void
    {
        $server = ServerConfig::fromSettings(['id' => 'example', 'url' => 'https://mcp.example.test/mcp', 'header_name' => 'Authorization', 'header_value' => 'Bearer FAKE', 'prefix' => 'ex', 'timeout' => 5, 'max_bytes' => 1024, 'approved' => []]);
        $pinned = (new Egress(static fn(string $host, string $url): array => ['192.0.2.1']))->client($server);
        $transport = (fn(): HttpClientInterface => $this->client)->call($pinned);
        $this->assertInstanceOf(NativeHttpClient::class, $transport);
    }
}
