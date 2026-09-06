<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\HttpClient;

/**
 * Builds the php-agents provider the plugin talks to, from settings.
 *
 * Settings are read when make()/baseUrl() run, never at construction, so the
 * factory is safe to register on plugins_loaded.
 */
final class Factory
{
    public function __construct(private Store $store) {}

    /**
     * The OpenAI-compatible endpoint, always ending in `/v1`.
     *
     * A non-empty OLLAMA_API_URL constant wins over `provider.base_url`; an empty
     * value from either source falls back to the schema default. The providers
     * append their own routes to the `/v1` root, so a deeper path stored by mistake
     * (`…/v1/chat`) is cut back to its last `/v1` segment rather than getting a
     * second `/v1` appended. A URL that already ends in `/v1` is returned as is, so
     * a gateway mount with an earlier `/v1` in its path (`…/v1/ai/ollama/v1`) is
     * never truncated.
     */
    public function baseUrl(): string
    {
        $constant = defined('OLLAMA_API_URL') ? trim((string) constant('OLLAMA_API_URL')) : '';
        $url = $constant !== '' ? $constant : trim((string) $this->store->get('provider.base_url'));
        if ($url === '') {
            $url = (string) Schema::defaults()['provider.base_url'];
        }
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/v1')) {
            return $url;
        }
        $url = (string) preg_replace('~^(.*/v1)/.*$~', '$1', $url);

        return str_ends_with($url, '/v1') ? $url : $url . '/v1';
    }

    /**
     * @param string|null $model falls back to `models.default` when null or empty
     * @throws \UnexpectedValueException when an `alpaca_bot/provider` filter returns something else
     */
    public function make(?string $model = null): ProviderInterface
    {
        $model = ($model === null || $model === '') ? (string) $this->store->get('models.default') : $model;
        // Symfony's `timeout` is the idle timeout between chunks, which is the one that
        // matters for a streamed reply; the library default would be 300s.
        $http = HttpClient::create(['timeout' => (int) $this->store->get('provider.timeout')]);

        // OllamaProvider pins 'ollama-local' as its Bearer token on every request, so a
        // configured key is swapped in at the wire: the provider (and the tool-schema
        // sanitising in its formatTools()) stays, and the key is never dropped.
        $apiKey = (string) $this->store->get('provider.api_key');
        if ($apiKey !== '') {
            $http = new BearerHttpClient($http, $apiKey);
        }

        // `provider.kind`: 'ollama' is the only kind with an adapter. 'wp-ai' falls through
        // to it until its adapter lands (P4); the filter below is the extension point for
        // swapping in another provider.
        $provider = new OllamaProvider(
            model: $model,
            baseUrl: $this->baseUrl(),
            httpClient: $http,
            numCtx: (int) $this->store->get('models.num_ctx'),
        );

        $filtered = apply_filters('alpaca_bot/provider', $provider, $model, $this->store);
        if (!$filtered instanceof ProviderInterface) {
            throw new \UnexpectedValueException('alpaca_bot/provider must return a ' . ProviderInterface::class . ', got ' . get_debug_type($filtered));
        }
        return $filtered;
    }
}
