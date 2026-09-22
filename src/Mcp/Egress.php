<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Toolkit\AddressPin;
use AlpacaBot\Toolkit\AddressRefused;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\HttpClient;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the HTTP client the MCP client is given for one server: https only, and pinned to an
 * address the plugin's own check has passed. It is the MCP side of "one rule, whichever
 * transport asks it": AddressPin is the rule, web_fetch applies it through cURL
 * (Toolkit\CurlPin), this applies it through Symfony HttpClient's `resolve` option.
 *
 * The check runs when a client is built, not on each request it makes, so the address it
 * returns is the only one that client connects to for as long as it is held: a name that
 * changes its answer afterwards reaches this server at the old address or not at all. How
 * often a client is built, and how long it is kept, is the caller's. PinnedHttpClient says
 * what else it holds the MCP client to.
 *
 * The client under the pin is HttpClient::create(): Curl where ext-curl is loaded, Native
 * otherwise (vendor-prefixed HttpClient.php:31-66), and both honour `resolve` (CurlHttpClient.php:188-204,
 * NativeHttpClient.php:191-192). The site's own host has no exemption here, unlike web_fetch: an
 * MCP server is an address an administrator typed, and an admin who means a private one opts it
 * in through core's `http_request_host_is_external`, as for any plugin.
 *
 * @since 0.6.0
 */
final class Egress
{
    /**
     * @param null|\Closure(string, string): string $resolve   the address check, host and URL in, address out, AddressRefused when refused; AddressPin::resolve() by default
     * @param HttpClientInterface|null              $transport the client the pin is laid over; HttpClient::create() by default, a test hands in Symfony's MockHttpClient
     */
    public function __construct(private ?\Closure $resolve = null, private ?HttpClientInterface $transport = null) {}

    /**
     * @throws AddressRefused when the URL is not https or its host fails the address check
     */
    public function client(ServerConfig $server): HttpClientInterface
    {
        $scheme = strtolower((string) wp_parse_url($server->url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) wp_parse_url($server->url, PHP_URL_HOST), '.'));
        if ($scheme !== 'https' || $host === '') {
            throw new AddressRefused(__('An MCP server has to be reached over https.', 'alpaca-bot'));
        }
        $ip = ($this->resolve ?? AddressPin::resolve(...))($host, $server->url);
        return new PinnedHttpClient($this->transport ?? HttpClient::create(), $host, $ip, $server->timeout, $server->maxBytes);
    }
}
