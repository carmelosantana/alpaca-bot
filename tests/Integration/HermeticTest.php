<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Plugin;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Settings\Store;

/**
 * The two guards bootstrap.php installs (Kanboard #4322), pinned here so a later edit to that
 * file cannot quietly drop one: WP_Http, which a test opens with its own `pre_http_request` stub,
 * and the configured model provider, which a test opens with fakeProvider(). Those are the two
 * doors the plugin's outbound calls go through today, which is what lets the suite pass with no
 * provider listening and no DNS -- but they are guards on two doors, not on the process: code
 * that builds its own Symfony HttpClient, cURL handle or stream outside Provider\Factory is
 * reached by neither, and a test for it has to inject the client.
 */
final class HermeticTest extends TestCase
{
    public function test_a_request_no_test_stubbed_is_refused_before_it_leaves_the_process(): void
    {
        $response = wp_remote_get('https://example.com/');

        $this->assertWPError($response);
        $this->assertSame('alpaca_bot_tests_offline', $response->get_error_code());
        $this->assertStringContainsString('https://example.com/', $response->get_error_message());
    }

    public function test_a_stub_the_test_adds_still_answers(): void
    {
        add_filter('pre_http_request', static fn(): array => [
            'headers' => [],
            'body' => 'stubbed',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ]);

        $this->assertSame('stubbed', wp_remote_retrieve_body(wp_remote_get('https://example.com/')));
    }

    public function test_the_configured_provider_is_offline_until_the_test_fakes_one(): void
    {
        Plugin::instance()->get(Store::class)->set('provider.base_url', 'http://ollama.invalid:11434/v1');
        $provider = Plugin::instance()->get(Factory::class)->make('any-model');
        $this->assertInstanceOf(OfflineProvider::class, $provider);
        try {
            $provider->models();
            $this->fail('the offline provider answered');
        } catch (\RuntimeException $e) {
            $this->assertSame(OfflineProvider::MESSAGE, $e->getMessage());
        }

        $this->fakeProvider();
        $this->assertSame('fake-model', Plugin::instance()->get(Factory::class)->make('any-model')->getModel());
    }
}
