<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Plugin;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Settings\Store;

/**
 * The two guards bootstrap.php installs (Kanboard #4322), pinned here so a later edit to that
 * file cannot quietly drop either, or move the WP_Http one off the last priority: WP_Http, which
 * a test opens with its own `pre_http_request` stub, and the Ollama provider Factory::make()
 * builds, which a test opens with fakeProvider(). (The provider guard's own priority is not
 * pinned, because it is not what makes fakeProvider() win; bootstrap.php says what is.)
 *
 * Guarding those two is what lets the suite pass with no provider listening and no DNS, and it is
 * two doors, not every door: Factory::make() also builds WpAiClientProvider, which the second
 * guard passes through as built -- what keeps that one off the wire is the fake model
 * WpAiClientTest registers with core -- and code building its own Symfony HttpClient, cURL handle
 * or stream outside Provider\Factory is reached by neither guard and has to be handed a client by
 * its test.
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

    /**
     * The stub takes `$pre` and the test asserts what it was handed, which is the only way this
     * case pins the guard's priority rather than just its own: a stub that ignores `$pre` and
     * returns its array unconditionally answers last as happily as it answers first, so it passes
     * just the same with the guard registered ahead of it. `$seen` starts at a value core never
     * passes, so a stub that never ran fails here too.
     */
    public function test_a_stub_the_test_adds_answers_before_the_guard_does(): void
    {
        $seen = 'the stub did not run';
        add_filter('pre_http_request', static function (mixed $pre) use (&$seen): array {
            $seen = $pre;
            return [
                'headers' => [],
                'body' => 'stubbed',
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        }, 10, 3);

        $this->assertSame('stubbed', wp_remote_retrieve_body(wp_remote_get('https://example.com/')));
        $this->assertFalse($seen, 'the guard answered before the test stub, so it is not at the last priority');
    }

    public function test_the_configured_provider_is_offline_until_the_test_fakes_one(): void
    {
        Plugin::instance()->get(Store::class)->set('provider.base_url', 'http://ollama.invalid:11434/v1');
        $provider = Plugin::instance()->get(Factory::class)->make('any-model');
        $this->assertInstanceOf(OfflineProvider::class, $provider);
        // Nothing that asserts goes inside the try: PHPUnit's own AssertionFailedError descends
        // from RuntimeException in this version, so a $this->fail() there would be caught by the
        // catch meant for the provider and reported as the wrong failure.
        $thrown = null;
        try {
            $provider->models();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'the offline provider answered models() instead of throwing');
        $this->assertSame(OfflineProvider::MESSAGE, $thrown->getMessage());

        $this->fakeProvider();
        $this->assertSame('fake-model', Plugin::instance()->get(Factory::class)->make('any-model')->getModel());
    }
}
