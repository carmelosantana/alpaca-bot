<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\Assets;
use AlpacaBot\Admin\ChatScreen;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Plugin;
use AlpacaBot\Rest\ViewController;
use AlpacaBot\Settings\Store;

/**
 * The `/view/*` fragment routes over real core dispatch, the chat screen over the real menu
 * renderer, and the asset enqueue over real wp_enqueue_*. What dispatch() cannot run is
 * serve(), the rest_pre_serve_request side that turns the string into a text/html body; the
 * unit suite covers that, and the wire is the real check's (curl with ?rest_route= against
 * the harness site).
 *
 * @group rest
 */
final class ViewRoutesTest extends TestCase
{
    public function test_view_routes_return_html_fragments(): void
    {
        $this->asAdmin();
        $res = $this->rest('GET', '/view/history');
        $this->assertSame(200, $res->get_status());
        $this->assertStringContainsString('<select', $res->get_data());
        $this->assertSame('1', $res->get_headers()['X-Alpaca-Bot-View']);
        $res = $this->rest('GET', '/view/bubble', ['role' => 'assistant', 'streaming' => '1']);
        $this->assertStringContainsString('data-streaming="1"', $res->get_data());
    }

    public function test_default_model_is_saved_in_user_meta(): void
    {
        $uid = $this->asAdmin();
        $this->rest('POST', '/view/default-model', ['model' => 'qwen3:8b']);
        $this->assertSame('qwen3:8b', get_user_meta($uid, 'alpaca_bot_default_model', true));
    }

    public function test_default_model_is_refused_while_users_may_not_change_the_model(): void
    {
        $uid = $this->asAdmin();
        Plugin::instance()->get(Store::class)->set('chat.user_can_change_model', false);
        $res = $this->rest('POST', '/view/default-model', ['model' => 'qwen3:8b']);
        $this->assertSame(403, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame('rest_forbidden', $res->get_data()['code']);
        $this->assertSame('', get_user_meta($uid, 'alpaca_bot_default_model', true));
    }

    public function test_the_routes_are_registered_and_the_controller_serves_the_html_itself(): void
    {
        $routes = rest_get_server()->get_routes();
        foreach (['/alpaca-bot/v1/view/messages/(?P<id>\d+)', '/alpaca-bot/v1/view/history', '/alpaca-bot/v1/view/models', '/alpaca-bot/v1/view/default-model', '/alpaca-bot/v1/view/bubble'] as $route) {
            $this->assertArrayHasKey($route, $routes);
        }
        $hooked = [];
        foreach ($GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[PHP_INT_MAX] ?? [] as $hook) {
            if (is_array($hook['function']) && $hook['function'][0] instanceof ViewController) {
                $hooked[] = $hook['function'][1];
            }
        }
        $this->assertSame(['serve'], $hooked);
    }

    public function test_messages_render_the_owners_transcript_and_404_for_anyone_else(): void
    {
        $owner = $this->asAdmin();
        $this->fakeProvider();
        $chat = $this->rest('POST', '/chat', ['message' => 'Reply **boldly**']);
        $this->assertSame(200, $chat->get_status(), print_r($chat->get_data(), true));
        $id = $chat->get_data()['conversation_id'];

        $res = $this->rest('GET', "/view/messages/{$id}");
        $this->assertSame(200, $res->get_status());
        $html = $res->get_data();
        $this->assertStringContainsString('id="ab-messages"', $html);
        $this->assertStringContainsString('data-conversation="' . $id . '"', $html);
        $this->assertStringContainsString('ab-msg--user', $html);
        $this->assertStringContainsString('ab-msg--assistant', $html);
        $this->assertStringContainsString('fake reply', $html);
        // The reply's receipt shows the tokens the fake provider reported.
        $this->assertStringContainsString('5 tokens', $html);

        $this->asAdmin();
        $res = $this->rest('GET', "/view/messages/{$id}");
        $this->assertSame(404, $res->get_status());
        $this->assertSame('alpaca_bot_not_found', $res->get_data()['code']);
        wp_set_current_user($owner);
    }

    public function test_history_marks_the_open_conversation_and_names_one_the_capped_list_left_out(): void
    {
        $uid = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        $older = $store->create($uid, 'Older one');
        $newer = $store->create($uid, 'Newer one');
        // Both were made this second, and the list orders by date: make the older one older.
        wp_update_post(['ID' => $older->id, 'post_date' => gmdate('Y-m-d H:i:s', time() - 60), 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() - 60)]);
        Plugin::instance()->get(Store::class)->set('chat.history_limit', 1);

        $html = $this->rest('GET', '/view/history', ['conversation_id' => (string) $newer->id])->get_data();
        $this->assertStringContainsString('<option value="' . $newer->id . '" data-id="' . $newer->id . '" selected>Newer one</option>', $html);
        $this->assertStringNotContainsString('Older one', $html);

        // The open conversation is the one the limit cut: it gets its own option, with its title.
        $html = $this->rest('GET', '/view/history', ['conversation_id' => (string) $older->id])->get_data();
        $this->assertStringContainsString('<option value="' . $older->id . '" data-id="' . $older->id . '" selected>Older one</option>', $html);
        $this->assertStringNotContainsString('Untitled', $html);

        // Somebody else's conversation renders as a new chat, not as an error.
        $this->asAdmin();
        $res = $this->rest('GET', '/view/history', ['conversation_id' => (string) $older->id]);
        $this->assertSame(200, $res->get_status());
        $this->assertStringContainsString('<option value="0" data-id="0" selected>', $res->get_data());
        $this->assertStringNotContainsString('Older one', $res->get_data());
    }

    public function test_bubble_post_renders_markdown_and_the_receipt_and_refuses_a_role_that_is_not_a_turn(): void
    {
        $this->asAdmin();
        $res = $this->rest('POST', '/view/bubble', ['role' => 'assistant', 'content' => "Hi **you**\n\n<script>x</script>", 'model' => 'fake-model', 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2], 'duration_ms' => 1500]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $html = $res->get_data();
        $this->assertStringContainsString('<strong>you</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('fake-model · 5 tokens · 1.5 s', $html);

        $res = $this->rest('POST', '/view/bubble', ['role' => 'system', 'content' => 'x']);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('rest_invalid_param', $res->get_data()['code']);
    }

    public function test_every_view_route_needs_edit_posts(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach ([['GET', '/view/history'], ['GET', '/view/models'], ['GET', '/view/bubble'], ['POST', '/view/default-model', ['model' => 'x']], ['GET', '/view/messages/1']] as $call) {
            $res = $this->rest($call[0], $call[1], $call[2] ?? []);
            $this->assertSame(403, $res->get_status(), $call[1]);
        }
    }

    public function test_the_chat_screen_renders_the_shell_and_the_assets_enqueue_on_its_hook_only(): void
    {
        $uid = $this->asAdmin();
        update_user_meta($uid, 'alpaca_bot_default_model', 'fake-model');
        $this->fakeProvider();
        ob_start();
        Plugin::instance()->get(ChatScreen::class)->render();
        $html = (string) ob_get_clean();
        $this->assertStringStartsWith('<div class="wrap ab-wrap">', $html);
        $this->assertStringContainsString('<h1 class="wp-heading-inline">Alpaca Bot</h1>', $html);
        $this->assertStringContainsString('id="ab-model"', $html);
        $this->assertStringContainsString('<option value="fake-model" selected', $html);
        $this->assertStringContainsString('id="ab-history"', $html);
        $this->assertStringContainsString('ab-welcome', $html);
        $this->assertStringContainsString('id="ab-form"', $html);
        // Every request URL is rest_url(): with plain permalinks that is ?rest_route=, never /wp-json/.
        $this->assertStringContainsString(esc_url(rest_url('alpaca-bot/v1/view/history')), $html);
        $this->assertStringNotContainsString('/wp-json/', $html);

        // admin_enqueue_scripts fires with the screen set, and wp_enqueue_media() reads it.
        set_current_screen('index.php');
        do_action('admin_enqueue_scripts', 'index.php');
        $this->assertFalse(wp_script_is('alpaca-bot-chat', 'enqueued'));
        set_current_screen(Assets::HOOK);
        do_action('admin_enqueue_scripts', Assets::HOOK);
        $this->assertTrue(wp_script_is('alpaca-bot-htmx', 'enqueued'));
        $this->assertTrue(wp_script_is('alpaca-bot-chat', 'enqueued'));
        $this->assertTrue(wp_style_is('alpaca-bot', 'enqueued'));
        // heartbeat is for the nonce refresh (nonce.ts); api-fetch is not here, the bundle uses bare fetch().
        $this->assertSame(['alpaca-bot-htmx', 'heartbeat'], wp_scripts()->registered['alpaca-bot-chat']->deps);
        $this->assertStringContainsString('var alpacaBot = ', (string) wp_scripts()->get_data('alpaca-bot-chat', 'data'));
        // rest_url() under the site's plain permalinks: ?rest_route=, the form the client must use.
        $this->assertStringContainsString('"rest":"' . rest_url('alpaca-bot/v1') . '"', (string) wp_scripts()->get_data('alpaca-bot-chat', 'data'));
        $this->assertStringContainsString('?rest_route=/alpaca-bot/v1', rest_url('alpaca-bot/v1'));
    }
}
