<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Toolkit\SpecialPurposeAddress;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\Exception\TransportException;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\ResponseInterface;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The client Egress hands the MCP client: every request held to one host, one address, no
 * redirects, the server's timeouts and a byte cap, whatever options the caller passes.
 *
 * The options are forced per request rather than set as defaults, because a caller's own
 * options win over a client's defaults (HttpClientTrait::mergeDefaultOptions(), `$options +=
 * $defaultOptions`), and each of these is load-bearing:
 *
 * - `resolve` [host => the checked address]: the connection goes to the address AddressPin
 *   passed, not to whatever the name answers at connect time. The key is the request URL's own
 *   host, so Symfony normalises both the same way (HttpClientTrait.php:209-219). A host that is
 *   already an IP literal is pinned by the URL itself and gets an empty map instead, which is
 *   still an assignment: whatever `resolve` the caller passed is gone either way.
 * - `max_redirects` 0: a redirect followed here would be a second request that nothing judged --
 *   to a host the pin does not cover, or to another URL on this one -- so it is handed back as
 *   the 3xx it is (CurlResponse.php:420-422, :467) and getContent() raises RedirectionException
 *   (CommonResponseTrait.php:162-180).
 * - `no_proxy` '*': a proxy is sent the name and resolves it itself, which would make the pin
 *   inert, and with `proxy` unset Symfony picks one up from the environment
 *   (HttpClientTrait.php:841-855). `'*'` is the one value both transports read as "no proxy for
 *   any host" (CurlHttpClient.php:119, NativeHttpClient.php:470-480); `proxy: ''` is not, since
 *   Native rejects an empty proxy URL (HttpClientTrait.php:817-821). The cost: a server whose
 *   outbound traffic must go through a proxy cannot reach an MCP server in 0.6.
 * - `timeout` and `max_duration` from the server row, so a slow server holds a turn for at most
 *   its own timeout. That they are positive numbers is ServerConfig::fromSettings()'s to keep,
 *   and load-bearing here: Symfony reads a `max_duration` of zero as no limit at all, so a cap
 *   this client set from a blank settings field would be no cap.
 * - `on_progress`: no option caps a body's size, and `buffer` only chooses whether and where to
 *   buffer (HttpClientInterface.php:56-60); a throw from `on_progress` aborts the transfer
 *   (:61-64; CurlResponse.php:126-146) and surfaces from getContent()/stream() as a
 *   TransportException carrying its message (TransportResponseTrait.php:229-240,
 *   Chunk/ErrorChunk.php:46). It is checked against the declared size as well as the bytes
 *   read, so an announced oversize answer is refused before it streams. A caller's own
 *   `on_progress` still runs, after the cap.
 *
 * A request to any other host, or over http, is refused before it is sent: the pin only holds
 * for the host it was made for, and any other URL would be an unpinned request made in the
 * pinned client's name. withOptions() is no way around that either -- the clone keeps the host
 * and the address, and its request() forces the same options over whatever was set there.
 *
 * @internal Built only by Egress.
 * @since 0.6.0
 */
final class PinnedHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $client,
        private string $host,
        private string $ip,
        private float $timeout,
        private int $maxBytes,
    ) {}

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $raw = (string) wp_parse_url($url, PHP_URL_HOST);
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https' || strtolower(trim($raw, '.')) !== $this->host) {
            throw new TransportException(sprintf('This client only reaches https://%s, the MCP server it was built for.', $this->host));
        }
        $max = $this->maxBytes;
        $caller = $options['on_progress'] ?? null;
        /** @param array<string, mixed> $info */
        $options['on_progress'] = static function (int $dlNow, int $dlSize, array $info) use ($max, $caller): void {
            if ($dlNow > $max || $dlSize > $max) {
                throw new TransportException(sprintf('The MCP server\'s response is larger than its %d-byte cap.', $max));
            }
            if (is_callable($caller)) {
                $caller($dlNow, $dlSize, $info);
            }
        };
        $options['resolve'] = SpecialPurposeAddress::isAddress(trim($raw, '[]')) ? [] : [$raw => $this->ip];
        $options['max_redirects'] = 0;
        $options['timeout'] = $this->timeout;
        $options['max_duration'] = $this->timeout;
        $options['no_proxy'] = '*';
        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);
        return $clone;
    }
}
