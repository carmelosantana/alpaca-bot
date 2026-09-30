<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\HttpTransport;
use AlpacaBot\Toolkit\AddressRefused;
use AlpacaBot\Toolkit\CurlPin;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\CurlHttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\NativeHttpClient;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the HTTP client the MCP client is given for one server: https only, no user name or
 * password in the URL, and pinned to the addresses the plugin's own check has passed. It is the
 * MCP side of "one rule, whichever transport asks it": AddressPin is the rule, web_fetch applies
 * it through cURL (Toolkit\CurlPin), this applies it through Symfony HttpClient's `resolve` option.
 *
 * The check runs when a client is built, not on each request it makes, so the addresses it
 * returns are the only ones that client connects to for as long as it is held: a name that
 * changes its answer afterwards reaches this server at the old addresses or not at all. How
 * often a client is built, and how long it is kept, is the caller's. PinnedHttpClient says
 * what else it holds the MCP client to.
 *
 * How many of the checked addresses are pinned depends on the transport, because `resolve` maps
 * a host to one string and each transport reads that string its own way:
 *
 * - Symfony's Curl client writes it into CURLOPT_RESOLVE as `host:port:<string>`
 *   (CurlHttpClient.php:198-199), so a comma-joined list is libcurl's own `addr[,addr]` form, and
 *   Symfony's option normalisation leaves such a list as it is: it unbrackets only a value that
 *   opens with `[` and closes with `]` (HttpClientTrait.php:209-219), and AddressPin hands every
 *   IPv6 address over unbracketed. So the Curl client is handed every checked address,
 *   comma-joined, on a libcurl that reads that form, and the first alone below it: the floor and
 *   its reason are CurlPin::carried()'s, the same rule web_fetch's pin follows. libcurl then
 *   connects to one of them as it would among a name's own answers.
 * - Symfony's Native client connects to the string as one address (NativeHttpClient.php:344,
 *   :371), so a list there would be a host it cannot resolve, and it is handed the first alone.
 *   What that costs is what AddressPin says handing back only the first cost web_fetch: the
 *   first address is an A record whenever the name has one, so over Native an IPv6-only server
 *   cannot reach a dual-stack MCP server.
 * - Any other transport handed to the constructor (a test's MockHttpClient) is handed the first
 *   alone as well. Handing one in is a test's seam; the plugin hands none (Plugin and
 *   ClientFactory build `new Mcp\Egress()`).
 *
 * The client under the pin is AlpacaBot\HttpTransport::create(): Symfony's Native client where
 * its Curl client cannot run or web_fetch's cURL question says no (curl_init or curl_exec missing
 * or disabled, the answer web_fetch goes by, or any cURL function that client calls; HttpTransport
 * lists them), and
 * HttpClient::create() (vendor-prefixed HttpClient.php:31-66) everywhere else, which
 * answers with Curl, Native or Amp. Amp is a candidate only where the unprefixed
 * amphp/http-client classes are loaded (HttpClient.php:14, :33), which the plugin does not ship
 * and another plugin could; it is then chosen over Curl when PHP's curl lacks HTTP/2 push or its
 * libcurl HTTP/2 or 7.61 (:38-49), and over Native whenever Curl is not chosen (:60-62). Curl is
 * chosen where ext-curl is loaded, except on Windows with none of `curl.cainfo`,
 * `openssl.cafile` or `openssl.capath` set (:52-55), and Native otherwise.
 * The pin is laid only over the two it can vouch for: anything else that answers is
 * replaced with a new NativeHttpClient, pinned to the first address. Amp's
 * client is not one of them, because its resolver does not hold to the map: resolve() looks the
 * name up for real whenever the pinned address's family is not the one asked for
 * (AmpResolver.php:36-43), and query() looks it up for real whenever an address is pinned at all
 * (AmpResolver.php:52-59). The site's own host has no exemption here, unlike web_fetch: an MCP
 * server is an address an administrator typed, and an admin who means a private one opts it in
 * with a listener of the site's own on core's `http_request_host_is_external`. The check is
 * AddressCheck::resolve(), which takes core's own listeners on that filter out while it runs
 * (AddressCheck says which and why); AddressPin::resolve() alone would let them through.
 *
 * client()'s parameter is a #[\SensitiveParameter], as ClientFactory::for()'s is: a trace taken
 * with zend.exception_ignore_args off holds a SensitiveParameterValue in its frame, not the
 * server and its header value.
 *
 * @since 0.6.0
 */
final class Egress
{
    /**
     * @param null|\Closure(string, string): list<string> $resolve   the address check, host and URL in, every checked address out, AddressRefused when refused; AddressCheck::resolve() by default
     * @param HttpClientInterface|null                    $transport the client the pin is laid over; HttpTransport::create() by default, a test hands in Symfony's MockHttpClient
     * @param null|\Closure(): int                        $version   libcurl's version_number, for CurlPin::carried(); the loaded libcurl's by default, a test hands in its own
     * @param null|\Closure(): HttpClientInterface         $create    where the transport comes from when none is handed in; HttpTransport::create() by default, a test hands in its own
     */
    public function __construct(
        private ?\Closure $resolve = null,
        private ?HttpClientInterface $transport = null,
        private ?\Closure $version = null,
        private ?\Closure $create = null,
    ) {}

    /**
     * @throws AddressRefused when the URL is not https, has no host, carries a user name or a password, or its host fails the address check
     */
    public function client(#[\SensitiveParameter] ServerConfig $server): HttpClientInterface
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
        // Refused here as well as at save (Schema's `userinfo` fault), because a row written round
        // the schema reaches this as it was stored.
        if (wp_parse_url($server->url, PHP_URL_USER) !== null) {
            throw new AddressRefused(__('An MCP server\'s URL may not carry a user name or password. Put a credential in the header instead.', 'alpaca-bot'));
        }
        $transport = $this->transport ?? ($this->create ?? HttpTransport::create(...))();
        if ($this->transport === null && !$transport instanceof CurlHttpClient && !$transport instanceof NativeHttpClient) {
            $transport = new NativeHttpClient();
        }
        $ips = ($this->resolve ?? static fn(string $host, string $url): array => AddressCheck::resolve($host, $url))($host, $server->url);
        // Every checked address on Symfony's Curl client, as many as its libcurl reads; the first
        // alone on Native, and on a transport a test handed in (the class docblock says why).
        $pinned = $transport instanceof CurlHttpClient ? CurlPin::carried($ips, $this->version) : array_slice($ips, 0, 1);
        return new PinnedHttpClient($transport, $host, $pinned, $server->timeout, $server->maxBytes);
    }
}
