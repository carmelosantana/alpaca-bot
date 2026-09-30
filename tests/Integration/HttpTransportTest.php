<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Mcp\Egress;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\CurlHttpClient;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Where cURL is usable, HttpTransport leaves the choice to Symfony's HttpClient::create(), which
 * in both containers (Linux, amphp not loaded) is the Curl client: the provider Factory builds and
 * the MCP egress transport get it exactly as before Kanboard #4690. The other side is
 * NoCurlClientTest's; these skip there.
 *
 * @requires function curl_init
 * @requires function curl_exec
 */
final class HttpTransportTest extends TestCase
{
    public function test_the_provider_factory_builds_talks_through_symfony_curl_client(): void
    {
        remove_all_filters('alpaca_bot/provider'); // the suite's OfflineProvider swap; nothing is requested here
        $provider = (new Factory(new Store(['provider.kind' => 'ollama', 'provider.base_url' => 'http://127.0.0.1:1/v1'])))->make('llama3');
        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $client = (fn(): HttpClientInterface => $this->httpClient)->call($provider);
        $this->assertInstanceOf(CurlHttpClient::class, $client);
    }

    public function test_the_mcp_egress_transport_is_symfony_curl_client(): void
    {
        $server = ServerConfig::fromSettings(['id' => 'example', 'url' => 'https://mcp.example.test/mcp', 'header_name' => 'Authorization', 'header_value' => 'Bearer FAKE', 'prefix' => 'ex', 'timeout' => 5, 'max_bytes' => 1024, 'approved' => []]);
        $pinned = (new Egress(static fn(string $host, string $url): array => ['192.0.2.1']))->client($server);
        $this->assertInstanceOf(CurlHttpClient::class, (fn(): HttpClientInterface => $this->client)->call($pinned));
    }
}
