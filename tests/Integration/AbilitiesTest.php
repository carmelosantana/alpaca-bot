<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;

/**
 * The three abilities over core's own Abilities API: registration on core's hooks, core's input
 * validation, core's permission gate, and the `wp-abilities/v1` routes that list and run them.
 * The unit tests pin what each callback does; these pin that core reaches the callbacks, and
 * refuses before it does.
 *
 * Core's registries are process singletons built on first use, so the first test here that
 * touches an ability is the one on which `wp_abilities_api_init` fires; every later test sees
 * the same registered abilities, whose callbacks read the setting and the user when called.
 */
final class AbilitiesTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('The Abilities API is not in this WordPress.');
        }
    }

    /** A `wp-abilities/v1` request: a JSON body under `input` for a run, query parameters for a listing. */
    private function abilities(string $method, string $path, ?array $input = null): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/wp-abilities/v1' . $path);
        if ($input !== null && $method === 'POST') {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode(['input' => $input]));
        } elseif ($input !== null) {
            $request->set_query_params($input);
        }
        return rest_get_server()->dispatch($request);
    }

    /** @return list<\WP_Post> */
    private function posts(string $type, int $author): array
    {
        return get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'author' => $author]);
    }

    /** A provider that fails the test if a turn reaches it. */
    private function noProvider(): void
    {
        add_filter('alpaca_bot/provider', function (): never {
            $this->fail('a turn reached the provider');
        });
    }

    public function test_the_category_and_the_three_abilities_are_registered_with_core_and_shown_to_rest_and_mcp(): void
    {
        $category = wp_get_ability_category('alpaca-bot');
        $this->assertNotNull($category);
        $this->assertSame('Alpaca Bot', $category->get_label());
        $this->assertSame(['alpaca-bot/chat', 'alpaca-bot/summarize', 'alpaca-bot/draft-post'], array_keys(wp_get_abilities(['namespace' => 'alpaca-bot'])));
        foreach (['alpaca-bot/chat', 'alpaca-bot/summarize', 'alpaca-bot/draft-post'] as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, $name);
            $this->assertSame('alpaca-bot', $ability->get_category());
            $this->assertTrue($ability->get_meta_item('show_in_rest'), $name);
            // What the MCP Adapter keys on (McpAbilityExposure::is_public): without it the ability is registered and invisible to MCP.
            $this->assertTrue($ability->get_meta_item('public'), $name);
            $this->assertSame(['readonly' => false, 'destructive' => false, 'idempotent' => false], $ability->get_meta_item('annotations'), $name);
        }
    }

    public function test_chat_runs_a_stored_turn_as_the_current_user_and_continues_it(): void
    {
        $admin = $this->asAdmin();
        $this->fakeProvider();
        $ability = wp_get_ability('alpaca-bot/chat');
        $this->assertNotNull($ability);
        $out = $ability->execute(['message' => 'hi']);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame(['conversation_id', 'reply', 'receipt'], array_keys($out));
        $this->assertSame('fake reply', $out['reply']);
        $this->assertGreaterThan(0, $out['conversation_id']);
        $this->assertSame($admin, $out['receipt']['user_id']);
        $this->assertSame(5, $out['receipt']['total_tokens']);
        $post = get_post($out['conversation_id']);
        $this->assertNotNull($post);
        $this->assertSame(ConversationStore::POST_TYPE, $post->post_type);
        $this->assertSame((string) $admin, $post->post_author);

        $again = $ability->execute(['message' => 'and again', 'conversation_id' => $out['conversation_id']]);
        $this->assertIsArray($again);
        $this->assertSame($out['conversation_id'], $again['conversation_id']);
        $this->assertCount(4, get_post_meta($out['conversation_id'], ConversationStore::META_MESSAGES, true));
    }

    public function test_an_input_the_schema_refuses_never_reaches_the_pipeline(): void
    {
        $admin = $this->asAdmin();
        $this->noProvider();
        $ability = wp_get_ability('alpaca-bot/chat');
        $this->assertNotNull($ability);
        foreach ([
            'no message' => [],
            'empty message' => ['message' => ''],
            'conversation_id not an integer' => ['message' => 'hi', 'conversation_id' => 'abc'],
            'negative conversation_id' => ['message' => 'hi', 'conversation_id' => -1],
            'a pipeline option smuggled in' => ['message' => 'hi', 'ephemeral' => true],
            'not an object' => 'hi',
        ] as $case => $input) {
            $out = $ability->execute($input);
            $this->assertWPError($out, $case);
            $this->assertSame('ability_invalid_input', $out->get_error_code(), $case);
        }
        $this->assertSame([], $this->posts(ConversationStore::POST_TYPE, $admin));
        $this->assertSame([], $this->posts(UsageMeter::POST_TYPE, $admin));

        // Over REST the same refusal is a 400, before the permission check.
        $response = $this->abilities('POST', '/abilities/alpaca-bot/chat/run', ['message' => 'hi', 'ephemeral' => true]);
        $this->assertSame(400, $response->get_status(), (string) wp_json_encode($response->get_data()));
    }

    public function test_chat_refuses_a_user_without_edit_posts_and_a_visitor_before_anything_runs(): void
    {
        $this->fakeProvider();
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $ability = wp_get_ability('alpaca-bot/chat');
        $this->assertNotNull($ability);
        $out = $ability->execute(['message' => 'hi']);
        $this->assertWPError($out);
        $this->assertSame('ability_invalid_permissions', $out->get_error_code());
        $this->assertSame([], $this->posts(ConversationStore::POST_TYPE, $subscriber));
        $this->assertSame([], $this->posts(UsageMeter::POST_TYPE, $subscriber));
        $response = $this->abilities('POST', '/abilities/alpaca-bot/chat/run', ['message' => 'hi']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('rest_ability_cannot_execute', $response->get_data()['code']);

        wp_set_current_user(0);
        $out = $ability->execute(['message' => 'hi']);
        $this->assertWPError($out);
        $this->assertSame('ability_invalid_permissions', $out->get_error_code());
        $this->assertSame(401, $this->abilities('POST', '/abilities/alpaca-bot/chat/run', ['message' => 'hi'])->get_status());
    }

    public function test_summarize_over_rest_is_one_ephemeral_turn_and_is_refused_once_its_toolkit_is_switched_off(): void
    {
        $admin = $this->asAdmin();
        $this->fakeProvider();
        $response = $this->abilities('POST', '/abilities/alpaca-bot/summarize/run', ['text' => 'A long text.', 'length' => 'short']);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame(['summary' => 'fake reply'], $response->get_data());
        // Ephemeral: a receipt for the admin, and no conversation.
        $this->assertCount(1, $this->posts(UsageMeter::POST_TYPE, $admin));
        $this->assertSame([], $this->posts(ConversationStore::POST_TYPE, $admin));

        Plugin::instance()->get(Store::class)->replace(['toolkits.enabled' => ['web_fetch', 'draft_post']]);
        $response = $this->abilities('POST', '/abilities/alpaca-bot/summarize/run', ['text' => 'A long text.']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('alpaca_bot_toolkit_disabled', $response->get_data()['code']);
        // Direct execution: core folds a permission callback's WP_Error into its own refusal, and
        // logs the reason through _doing_it_wrong(), which is what this expects.
        $this->setExpectedIncorrectUsage('WP_Ability::execute');
        $out = wp_get_ability('alpaca-bot/summarize')->execute(['text' => 'A long text.']);
        $this->assertWPError($out);
        $this->assertSame('ability_invalid_permissions', $out->get_error_code());
        $this->assertCount(1, $this->posts(UsageMeter::POST_TYPE, $admin));
        // Still registered and still listed: the switch is the administrator's to flip back, and a client is told why, not shown a 404.
        $this->assertNotNull(wp_get_ability('alpaca-bot/summarize'));
    }

    public function test_draft_post_creates_a_draft_for_the_current_user_and_a_page_needs_edit_pages(): void
    {
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);
        $this->noProvider();
        $response = $this->abilities('POST', '/abilities/alpaca-bot/draft-post/run', ['title' => 'From an ability', 'content' => '<p>Body</p><script>alert(1)</script>']);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $data = $response->get_data();
        $this->assertSame(['id', 'edit_url'], array_keys($data));
        $post = get_post($data['id']);
        $this->assertNotNull($post);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('post', $post->post_type);
        $this->assertSame((string) $contributor, $post->post_author);
        $this->assertSame('<p>Body</p>alert(1)', $post->post_content);
        $this->assertStringContainsString('post=' . $data['id'], $data['edit_url']);

        // A contributor may draft posts but not pages: refused at the permission gate, nothing inserted.
        $response = $this->abilities('POST', '/abilities/alpaca-bot/draft-post/run', ['title' => 'A page', 'content' => 'Body', 'post_type' => 'page']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->posts('page', $contributor));
        $this->assertSame(400, $this->abilities('POST', '/abilities/alpaca-bot/draft-post/run', ['title' => 'x', 'content' => 'y', 'post_type' => 'attachment'])->get_status());

        Plugin::instance()->get(Store::class)->replace(['toolkits.enabled' => ['web_fetch', 'summarize']]);
        $response = $this->abilities('POST', '/abilities/alpaca-bot/draft-post/run', ['title' => 'Second', 'content' => 'Body']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('alpaca_bot_toolkit_disabled', $response->get_data()['code']);
        $this->assertCount(1, $this->posts('post', $contributor));
    }

    public function test_the_rest_listing_shows_the_three_abilities_and_the_category_to_a_logged_in_user_only(): void
    {
        $this->asAdmin();
        $response = $this->abilities('GET', '/abilities', ['namespace' => 'alpaca-bot']);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['alpaca-bot/chat', 'alpaca-bot/summarize', 'alpaca-bot/draft-post'], array_column($response->get_data(), 'name'));
        $this->assertSame(['alpaca-bot', 'alpaca-bot', 'alpaca-bot'], array_column($response->get_data(), 'category'));
        $this->assertSame(200, $this->abilities('GET', '/categories/alpaca-bot')->get_status());

        wp_set_current_user(0);
        $this->assertSame(401, $this->abilities('GET', '/abilities', ['namespace' => 'alpaca-bot'])->get_status());
    }

    public function test_chat_and_summarize_share_the_rest_routes_rate_limit_and_its_filter(): void
    {
        $admin = $this->asAdmin();
        $this->fakeProvider();
        add_filter('alpaca_bot/rate_limit', static fn(): int => 1);
        $response = $this->abilities('POST', '/abilities/alpaca-bot/summarize/run', ['text' => 'A long text.']);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $response = $this->abilities('POST', '/abilities/alpaca-bot/chat/run', ['message' => 'hi']);
        $this->assertSame(429, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame('alpaca_bot_rate_limited', $response->get_data()['code']);
        $this->assertGreaterThan(0, $response->get_data()['data']['retry_after']);
        // One bucket for the person, not one per surface: the plugin's own route is spent by the ability's hit.
        $response = $this->rest('POST', '/chat', ['message' => 'hi']);
        $this->assertSame(429, $response->get_status(), (string) wp_json_encode($response->get_data()));
        // Direct execution refuses the same way, and only the one summary was billed.
        $out = wp_get_ability('alpaca-bot/chat')->execute(['message' => 'hi']);
        $this->assertWPError($out);
        $this->assertSame('alpaca_bot_rate_limited', $out->get_error_code());
        $this->assertCount(1, $this->posts(UsageMeter::POST_TYPE, $admin));
        $this->assertSame([], $this->posts(ConversationStore::POST_TYPE, $admin));
    }
}
