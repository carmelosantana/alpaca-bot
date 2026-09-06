<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\ResponseInterface;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Sends every request with `Authorization: Bearer <key>`, whatever the caller put there.
 *
 * OllamaProvider pins its token to 'ollama-local' and AbstractProvider::headers() sets that
 * `Authorization` on each request. In Symfony's HttpClientTrait::mergeDefaultOptions() the
 * request's headers win over the client's default headers, and `auth_bearer` only fills in
 * an *absent* Authorization -- so a client-level default cannot carry a configured
 * `provider.api_key` (Ollama behind an auth proxy). This decorator rewrites the header on
 * the way into the wrapped client instead, which keeps OllamaProvider and its
 * tool-schema sanitising formatTools() in place. Requests that carry no headers at all
 * (OllamaProvider's native /api/* calls) get the header added.
 */
final class BearerHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $client, private string $token) {}

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = [];
        $given = $options['headers'] ?? [];
        foreach (is_iterable($given) ? $given : [] as $name => $value) {
            if (!self::isAuthorization($name, $value)) {
                $headers[$name] = $value;
            }
        }
        $headers['Authorization'] = 'Bearer ' . $this->token;
        $options['headers'] = $headers;

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

    /** Both shapes Symfony's normalizeHeaders() accepts: `['Authorization' => '…']` and `['Authorization: …']`. */
    private static function isAuthorization(int|string $name, mixed $value): bool
    {
        if (is_int($name)) {
            return is_string($value) && strncasecmp(ltrim($value), 'authorization:', 14) === 0;
        }

        return strcasecmp($name, 'authorization') === 0;
    }
}
