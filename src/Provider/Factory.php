<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
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

    /** The OpenAI-compatible endpoint: the OLLAMA_API_URL constant (with /v1 appended) wins over settings. */
    public function baseUrl(): string
    {
        $url = defined('OLLAMA_API_URL') ? (string) constant('OLLAMA_API_URL') : (string) $this->store->get('provider.base_url');
        $url = rtrim($url, '/');
        return str_ends_with($url, '/v1') ? $url : $url . '/v1';
    }

    /**
     * @param string|null $model falls back to `models.default` when null or empty
     * @throws \UnexpectedValueException when an `alpaca_bot/provider` filter returns something else
     */
    public function make(?string $model = null): ProviderInterface
    {
        $model = ($model === null || $model === '') ? (string) $this->store->get('models.default') : $model;
        $apiKey = (string) $this->store->get('provider.api_key');
        // Symfony's `timeout` is the idle timeout between chunks, which is the one that
        // matters for a streamed reply; the library default would be 300s.
        $http = HttpClient::create(['timeout' => (int) $this->store->get('provider.timeout')]);

        // OllamaProvider is final and pins its Bearer token to 'ollama-local', so a configured
        // key needs the parent OpenAICompatibleProvider against the same /v1 endpoint.
        // `provider.kind` = wp-ai has no adapter yet and falls through here; the filter below
        // is the extension point for swapping in another provider.
        $provider = $apiKey === ''
            ? new OllamaProvider(model: $model, baseUrl: $this->baseUrl(), httpClient: $http, numCtx: (int) $this->store->get('models.num_ctx'))
            : new OpenAICompatibleProvider(model: $model, baseUrl: $this->baseUrl(), apiKey: $apiKey, httpClient: $http);

        $filtered = apply_filters('alpaca_bot/provider', $provider, $model, $this->store);
        if (!$filtered instanceof ProviderInterface) {
            throw new \UnexpectedValueException('alpaca_bot/provider must return a ' . ProviderInterface::class . ', got ' . get_debug_type($filtered));
        }
        return $filtered;
    }
}
