<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\Shortcodes\AgentShim;
use AlpacaBot\Shortcodes\Chat;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * The two shortcodes over real WordPress: do_shortcode() finds them, the capability map
 * decides who may generate, the transient lands in the options table, the front-end bundle is
 * enqueued for the shell, and `_doing_it_wrong()` reaches core's handler. The provider is the
 * fake one; a fetch is served by `pre_http_request`, so nothing reaches the network.
 */
final class ShortcodesTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        // The handlers live on the Plugin singleton for the whole process, and each keeps a
        // per-request memo (what this request generated; whether the deprecation was said):
        // one process is one request to them, so a test starts each afresh, as Pest.php
        // resets the singleton itself.
        (new \ReflectionProperty(Chat::class, 'served'))->setValue(Plugin::instance()->get(Chat::class), []);
        (new \ReflectionProperty(AgentShim::class, 'warned'))->setValue(Plugin::instance()->get(AgentShim::class), false);
    }

    public function tear_down(): void
    {
        // The shell's render enqueues into the process-wide $wp_scripts/$wp_styles; a later test
        // asserting "nothing enqueued" must start clean.
        unset($GLOBALS['wp_scripts'], $GLOBALS['wp_styles']);
        parent::tear_down();
    }

    /**
     * How many turns ran this test: every generation builds one provider, a cached render none.
     * The catalog is discovered first, through the fake provider (install it before calling
     * this), so the one build discovery makes is not counted as a turn.
     */
    private function countProviders(): \Closure
    {
        Plugin::instance()->get(ModelCatalog::class)->all();
        $count = 0;
        add_filter('alpaca_bot/provider', static function (mixed $provider) use (&$count): mixed {
            $count++;
            return $provider;
        }, 20);
        return static function () use (&$count): int {
            return $count;
        };
    }

    public function test_both_shortcodes_are_registered(): void
    {
        $this->assertTrue(shortcode_exists('alpacabot'));
        $this->assertTrue(shortcode_exists('alpacabot_agent'));
    }

    public function test_an_admin_gets_a_rendered_answer_once_and_the_cache_after(): void
    {
        $this->asAdmin();
        $this->fakeProvider();
        // The site's default model, as an administrator sets it: the identity is that setting.
        Plugin::instance()->get(Store::class)->set('models.default', 'fake-model');
        $built = $this->countProviders();
        $post = self::factory()->post->create(['post_content' => '[alpacabot prompt="x"]']);
        $this->go_to(get_permalink($post));
        $this->assertSame($post, get_the_ID());

        $html = do_shortcode('[alpacabot prompt="x"]');
        $this->assertStringContainsString('fake reply', $html);
        $this->assertStringContainsString('class="alpaca-bot-answer"', $html);
        $this->assertSame(1, $built());
        // The receipt was written (an ephemeral turn is billed) and no conversation was.
        $this->assertCount(1, get_posts(['post_type' => 'chat_log', 'post_status' => 'any', 'numberposts' => -1]));
        $this->assertCount(0, get_posts(['post_type' => 'chat_history', 'post_status' => 'any', 'numberposts' => -1]));

        // The second render, a new handler instance or not, is the transient: no provider built.
        // (The memo would answer it too; the transient is asserted on directly, under the key
        // the unit tests pin, since a timed transient is not autoloaded.)
        $this->assertStringContainsString('fake reply', do_shortcode('[alpacabot prompt="x"]'));
        $this->assertSame(1, $built());
        // The identity is what the site resolved: its default model setting and its system prompt, with the duration.
        $this->assertSame('fake reply', get_transient(Chat::cacheKey('alpacabot', ['prompt' => 'x', 'model' => 'fake-model', 'system' => (string) Plugin::instance()->get(Store::class)->get('chat.system_prompt'), 'temperature' => null], $post, 3600)));
        $this->assertSame(1, $this->shortcodeTransients());
    }

    public function test_an_overflowing_cache_attribute_is_held_to_a_year_rather_than_fataling_the_page(): void
    {
        // Review C1, the reviewer's exact value: `cache="999999999999999d"` overflowed an int in
        // cacheSeconds(), which threw a TypeError out of attribute parsing, outside the try and
        // before the capability check, so a published page was a white screen for every visitor.
        $this->asAdmin();
        $this->fakeProvider();
        $post = self::factory()->post->create(['post_content' => '[alpacabot prompt="hi" cache="999999999999999d"]']);
        $this->go_to(get_permalink($post));
        $html = do_shortcode('[alpacabot prompt="hi" cache="999999999999999d"]');
        $this->assertStringContainsString('fake reply', $html);
        global $wpdb;
        $timeout = (int) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_" . Chat::TRANSIENT_PREFIX . "%'");
        $this->assertGreaterThan(time(), $timeout);
        $this->assertLessThanOrEqual(time() + YEAR_IN_SECONDS, $timeout);
        // And a visitor, who is the one this fatal reached, gets the notice.
        wp_set_current_user(0);
        $this->assertStringContainsString('Log in', do_shortcode('[alpacabot prompt="hi" cache="999999999999999d"]'));
    }

    public function test_a_failed_turn_shows_the_fixed_provider_message_and_not_the_endpoint(): void
    {
        // Review I1: the provider's own text quotes its endpoint, and the page went to every
        // edit_posts viewer; Rest\Errors::provider() already says that text is the log's.
        $this->asAdmin();
        $this->fakeProvider(new \RuntimeException('Could not resolve host: ollama.internal for "http://ollama.internal:11434/v1/chat/completions".'));
        $post = self::factory()->post->create(['post_content' => '[alpacabot prompt="x"]']);
        $this->go_to(get_permalink($post));
        $html = do_shortcode('[alpacabot prompt="x"]');
        $this->assertStringContainsString('class="alpaca-bot-notice"', $html);
        $this->assertStringContainsString('The model provider could not complete the request.', $html);
        $this->assertStringNotContainsString('ollama.internal', $html);
        $this->assertStringNotContainsString('11434', $html);
        $this->assertSame(0, $this->shortcodeTransients());
    }

    public function test_a_rest_collection_as_an_editor_generates_nothing_and_a_page_view_does(): void
    {
        // Review I3: content.rendered is produced for every item of a collection, so one
        // GET /wp/v2/posts?per_page=100 as an editor was up to 100 serialized turns in one
        // request. The rule that governs guests governs REST: the cache or a notice, and
        // generation happens on a page render only. The single-item case after, from the cache.
        $this->fakeProvider();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $built = $this->countProviders();
        $posts = [];
        foreach (['one', 'two', 'three'] as $n) {
            $posts[] = self::factory()->post->create(['post_content' => '[alpacabot prompt="' . $n . '"]']);
        }
        $request = new \WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params(['per_page' => 100, 'context' => 'edit']);
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $items = $response->get_data();
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertStringContainsString('class="alpaca-bot-notice"', $item['content']['rendered']);
            $this->assertStringNotContainsString('fake reply', $item['content']['rendered']);
        }
        $this->assertSame(0, $built());
        $this->assertSame(0, $this->shortcodeTransients());

        // The page render is where an editor's view generates, once.
        $this->go_to(get_permalink($posts[0]));
        $this->assertStringContainsString('fake reply', apply_filters('the_content', get_post($posts[0])->post_content));
        $this->assertSame(1, $built());

        // A single item over REST now carries that cached answer, and still generates nothing.
        $response = rest_get_server()->dispatch(new \WP_REST_Request('GET', '/wp/v2/posts/' . $posts[0]));
        $this->assertSame(200, $response->get_status());
        $this->assertStringContainsString('fake reply', $response->get_data()['content']['rendered']);
        $this->assertSame(1, $built());
        $this->assertSame(1, $this->shortcodeTransients());
    }


    public function test_a_visitor_gets_the_login_notice_and_the_cached_answer_only_when_the_filter_allows(): void
    {
        $this->asAdmin();
        $this->fakeProvider();
        $built = $this->countProviders();
        $post = self::factory()->post->create(['post_content' => '[alpacabot prompt="x"]']);
        $this->go_to(get_permalink($post));
        do_shortcode('[alpacabot prompt="x"]');
        $this->assertSame(1, $built());

        wp_set_current_user(0);
        $html = do_shortcode('[alpacabot prompt="x"]');
        $this->assertStringContainsString('class="alpaca-bot-notice"', $html);
        $this->assertStringContainsString('Log in', $html);
        $this->assertStringContainsString(wp_login_url(get_permalink($post)), $html);
        $this->assertStringNotContainsString('fake reply', $html);
        // A prompt nothing has cached: still the notice, and still no provider, filter or no filter.
        add_filter('alpaca_bot/shortcode/allow_guests', '__return_true');
        $this->assertStringContainsString('Log in', do_shortcode('[alpacabot prompt="never generated"]'));
        $this->assertSame(1, $built());
        // The cached one is served to a guest once the filter says so.
        $this->assertStringContainsString('fake reply', do_shortcode('[alpacabot prompt="x"]'));
        $this->assertSame(1, $built());

        // A subscriber is logged in but may not edit posts: the permission notice, no login link.
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        remove_filter('alpaca_bot/shortcode/allow_guests', '__return_true');
        $html = do_shortcode('[alpacabot prompt="x"]');
        $this->assertStringContainsString('class="alpaca-bot-notice"', $html);
        $this->assertStringNotContainsString('wp-login.php', $html);
        $this->assertStringNotContainsString('fake reply', $html);
        $this->assertSame(1, $built());
    }

    public function test_the_shell_renders_for_an_admin_and_enqueues_the_bundle(): void
    {
        $this->asAdmin();
        $html = do_shortcode('[alpacabot]');
        $this->assertStringContainsString('id="ab-chat"', $html);
        $this->assertStringContainsString('id="ab-form"', $html);
        $this->assertTrue(wp_script_is('alpaca-bot-chat', 'enqueued'));
        $this->assertTrue(wp_script_is('alpaca-bot-htmx', 'enqueued'));
        $this->assertTrue(wp_style_is('alpaca-bot', 'enqueued'));
        $this->assertTrue(wp_script_is('media-editor', 'enqueued'));
        $data = (string) wp_scripts()->get_data('alpaca-bot-chat', 'data');
        $this->assertStringContainsString('"rest":"' . rest_url('alpaca-bot/v1') . '"', $data);
        $this->assertStringContainsString('"nonce":"', $data);
    }

    public function test_the_shell_is_a_notice_for_a_visitor_with_nothing_enqueued(): void
    {
        wp_set_current_user(0);
        $html = do_shortcode('[alpacabot]');
        $this->assertStringContainsString('class="alpaca-bot-notice"', $html);
        $this->assertStringNotContainsString('id="ab-chat"', $html);
        $this->assertFalse(wp_script_is('alpaca-bot-chat', 'enqueued'));
        $this->assertFalse(wp_style_is('alpaca-bot', 'enqueued'));
        // The notice's few rules are its own stylesheet, not the chat screen's.
        $this->assertTrue(wp_style_is('alpaca-bot-shortcode', 'enqueued'));
    }

    public function test_the_agent_shim_summarizes_a_fetched_page_with_a_prompt_beginning_summarize_and_is_doing_it_wrong(): void
    {
        $this->setExpectedIncorrectUsage('alpacabot_agent');
        $this->asAdmin();
        $prompts = [];
        add_filter('alpaca_bot/provider', static function () use (&$prompts): ProviderInterface {
            return new class ($prompts) implements ProviderInterface {
            /** @param list<string> $prompts */
            public function __construct(private array &$prompts) {}

            public function chat(array $messages, array $tools = [], array $options = []): Response
            {
                return new Response('fake reply', ProviderFinishReason::Stop, usage: new Usage(3, 2, 5));
            }

            public function stream(array $messages, array $tools = [], array $options = []): iterable
            {
                $this->prompts[] = end($messages)->content();
                yield new Response('fake summary', ProviderFinishReason::Stop, usage: new Usage(3, 2, 5));
            }

            public function structured(array $messages, string $schema, array $options = []): mixed
            {
                return [];
            }

            public function models(): array
            {
                return [new ModelDefinition('fake-model', 'Fake model', 'fake')];
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getModel(): string
            {
                return 'fake-model';
            }

            public function withModel(string $model): static
            {
                return $this;
            }
            };
        });
        $requests = 0;
        add_filter('pre_http_request', static function () use (&$requests): array {
            $requests++;
            return ['headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => '<html><body><h1>Hi</h1><p>there</p></body></html>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        });
        // The site's own host is the one core's guard passes without DNS (ToolkitsTest says why).
        $url = home_url('/a-page/');
        $html = do_shortcode('[alpacabot_agent name="summarize" url="' . $url . '" length="one line"]');
        $this->assertStringContainsString('fake summary', $html);
        $this->assertSame(1, $requests);
        $this->assertCount(1, $prompts);
        $this->assertStringStartsWith('Summarize', $prompts[0]);
        $this->assertStringContainsString($url, $prompts[0]);
        $this->assertStringContainsString('one line', $prompts[0]);
        $this->assertStringContainsString("Hi\n\nthere", $prompts[0]);
        // Cached under its own tag: the second render fetches and generates nothing.
        $this->assertStringContainsString('fake summary', do_shortcode('[alpacabot_agent name="summarize" url="' . $url . '" length="one line"]'));
        $this->assertSame(1, $requests);
        $this->assertCount(1, $prompts);
    }

    public function test_the_agent_shim_refuses_a_private_address_through_core_and_caches_nothing(): void
    {
        $this->setExpectedIncorrectUsage('alpacabot_agent');
        $this->asAdmin();
        $this->fakeProvider();
        $built = $this->countProviders();
        $requests = 0;
        add_filter('pre_http_request', static function () use (&$requests): \WP_Error {
            $requests++;
            return new \WP_Error('unexpected', 'no request was expected');
        });
        $html = do_shortcode('[alpacabot_agent name="summarize" url="http://127.0.0.1/"]');
        $this->assertStringContainsString('class="alpaca-bot-notice"', $html);
        $this->assertStringContainsString('not allowed', $html);
        $this->assertSame(0, $requests);
        $this->assertSame(0, $built());
        $this->assertSame(0, $this->shortcodeTransients());
    }

    /** The shortcode transients in the options table (a timed transient is not autoloaded, so alloptions would not show one). */
    private function shortcodeTransients(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_" . Chat::TRANSIENT_PREFIX . "%'");
    }
}
