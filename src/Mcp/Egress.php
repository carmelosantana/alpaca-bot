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
     * @param null|\Closure(string, string): list<string> $resolve   the address check, host and URL in, every checked address out, AddressRefused when refused; AddressPin::resolve() by default
     * @param HttpClientInterface|null                    $transport the client the pin is laid over; HttpClient::create() by default, a test hands in Symfony's MockHttpClient
     */
    public function __construct(private ?\Closure $resolve = null, private ?HttpClientInterface $transport = null) {}

    /**
     * @throws AddressRefused when the URL is not https or its host fails the address check
     */
    public function client(ServerConfig $server): HttpClientInterface
    {
        $scheme = strtolower((string) wp_parse_url($server->url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) wp_parse_url($server->url, PHP_URL_HOST), '.'));
        if ($scheme !== 'https') {
            throw new AddressRefused(__('An MCP server has to be reached over https.', 'alpaca-bot'));
        }
        // Separately, and with AddressPin's own sentence: `https://./mcp` parses as https with a
        // host of '.', which trims to nothing. Telling that admin to use https would name the one
        // thing they got right.
        if ($host === '') {
            throw new AddressRefused(__('The address has no host name.', 'alpaca-bot'));
        }
        // The first of the checked addresses, and only the first, where web_fetch pins every one
        // of them: Symfony's `resolve` maps a host to one address and writes it into a string
        // ("$resolveHost:$port:$ip", CurlHttpClient.php:198-204), so a list there would pin the
        // connection to the word "Array". What that costs is what AddressPin says handing back
        // only the first cost web_fetch, and no longer does: the first address is an A record
        // whenever the name has one, so an IPv6-only server cannot reach a dual-stack MCP
        // server, where before the pin its own transport would have chosen the AAAA. Taking a
        // list would mean a `resolve` option Symfony does not have; the alternative would be
        // writing CURLOPT_RESOLVE under Symfony's client, which only its Curl transport has and
        // which would be a pin the Native transport silently does not carry.
        $ip = ($this->resolve ?? AddressPin::resolve(...))($host, $server->url)[0];
        return new PinnedHttpClient($this->transport ?? HttpClient::create(), $host, $ip, $server->timeout, $server->maxBytes);
    }
}
