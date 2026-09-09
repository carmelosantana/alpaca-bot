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
 *
 * `provider.kind` picks between two providers. `ollama` is OllamaProvider over the site's
 * base URL, key and timeout. `wp-ai` is WpAiClientProvider over WordPress's own AI client
 * (WordPress 7.0+), which routes the turn to whatever provider plugin the site registered with
 * core; it takes none of the Ollama settings, since the core provider plugin holds its own.
 * When `wp-ai` is selected on a WordPress without the client, make() builds Ollama instead and
 * fallbackNotice() says so, for Plugin to print on admin_notices: a stored setting must not
 * fatal, and a site whose WordPress went backwards should chat, but not without being told which
 * provider is answering. The probe is the client's presence alone, not whether a core provider
 * is configured: that check lists every provider's models (an HTTP call each on a site with no
 * persistent object cache), and make() runs on every turn. A selected `wp-ai` with core present
 * but no provider configured is a loud failure at the turn instead (WpAiClientProvider::chat()
 * throws, the catalog is empty), never a silent switch to Ollama.
 */
final class Factory
{
    /**
     * @param WpAi\Client $wpAi core's AI client, probed when `provider.kind` is `wp-ai`; the real one unless a test hands in another
     */
    public function __construct(private Store $store, private WpAi\Client $wpAi = new WpAi\CoreClient()) {}

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

        // Both kinds go through the same filter, the extension point for swapping in another
        // provider: a filter that wraps or replaces the provider sees whichever kind is selected.
        $provider = $this->usesWpAi() ? new WpAiClientProvider($model, $this->wpAi) : $this->ollama($model);

        $filtered = apply_filters('alpaca_bot/provider', $provider, $model, $this->store);
        if (!$filtered instanceof ProviderInterface) {
            throw new \UnexpectedValueException('alpaca_bot/provider must return a ' . ProviderInterface::class . ', got ' . get_debug_type($filtered));
        }
        return $filtered;
    }

    /**
     * The message for the administrator when `wp-ai` is selected but make() is building Ollama,
     * null otherwise. The verdict lives here, next to the decision it reports, and Plugin prints
     * it on admin_notices; the factory itself prints nothing, since it also runs under REST and
     * WP-CLI.
     */
    public function fallbackNotice(): ?string
    {
        if ($this->store->get('provider.kind') !== 'wp-ai' || $this->wpAi->available()) {
            return null;
        }
        return sprintf(
            /* translators: %s: the Ollama base URL replies are coming from */
            __('Alpaca Bot is set to the WordPress AI provider, but this WordPress has no AI client (it arrived in WordPress 7.0), so replies are coming from Ollama at %s instead. Choose Ollama on the Alpaca Bot settings page to make that the setting, or update WordPress.', 'alpaca-bot'),
            $this->baseUrl(),
        );
    }

    private function usesWpAi(): bool
    {
        return $this->store->get('provider.kind') === 'wp-ai' && $this->wpAi->available();
    }

    private function ollama(string $model): OllamaProvider
    {
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

        return new OllamaProvider(
            model: $model,
            baseUrl: $this->baseUrl(),
            httpClient: $http,
            numCtx: (int) $this->store->get('models.num_ctx'),
        );
    }
}
