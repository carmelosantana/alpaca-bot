<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\Assets;
use AlpacaBot\Admin\ChatScreen;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Context\CurrentScreenSource;
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

    public function test_drawer_state_is_stored_in_user_meta_one_parameter_at_a_time_over_core_validation(): void
    {
        $uid = $this->asAdmin();
        $res = $this->rest('POST', '/view/drawer', ['open' => 'true', 'conversation_id' => '7']);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame('', $res->get_data());
        $this->assertSame('1', get_user_meta($uid, 'alpaca_bot_drawer_open', true));
        $this->assertSame('7', get_user_meta($uid, 'alpaca_bot_drawer_conversation', true));

        // Only the open state named: the conversation is left as it was.
        $this->rest('POST', '/view/drawer', ['open' => 'false']);
        $this->assertSame('0', get_user_meta($uid, 'alpaca_bot_drawer_open', true));
        $this->assertSame('7', get_user_meta($uid, 'alpaca_bot_drawer_conversation', true));

        // Core's schema refuses a negative id before the callback runs.
        $res = $this->rest('POST', '/view/drawer', ['conversation_id' => '-1']);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('7', get_user_meta($uid, 'alpaca_bot_drawer_conversation', true));
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
        foreach (['/alpaca-bot/v1/view/messages/(?P<id>\d+)', '/alpaca-bot/v1/view/history', '/alpaca-bot/v1/view/models', '/alpaca-bot/v1/view/default-model', '/alpaca-bot/v1/view/bubble', '/alpaca-bot/v1/view/panel', '/alpaca-bot/v1/view/drawer'] as $route) {
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

        // A tool turn's receipt ends with the badge, through core's own _n().
        $call = ['name' => 'web_fetch', 'arguments' => ['url' => 'https://example.test/'], 'result_excerpt' => 'Example', 'ok' => true];
        $res = $this->rest('POST', '/view/bubble', ['role' => 'assistant', 'content' => 'Fetched.', 'model' => 'fake-model', 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2], 'duration_ms' => 1500, 'tool_calls' => [$call]]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertStringContainsString('fake-model · 5 tokens · 1.5 s · 1 tool</footer>', $res->get_data());

        $res = $this->rest('POST', '/view/bubble', ['role' => 'system', 'content' => 'x']);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('rest_invalid_param', $res->get_data()['code']);
    }

    public function test_every_view_route_needs_edit_posts(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach ([['GET', '/view/history'], ['GET', '/view/models'], ['GET', '/view/bubble'], ['POST', '/view/bubble', ['role' => 'user']], ['POST', '/view/default-model', ['model' => 'x']], ['GET', '/view/messages/1'], ['GET', '/view/panel'], ['POST', '/view/drawer', ['open' => true]]] as $call) {
            $res = $this->rest($call[0], $call[1], $call[2] ?? []);
            $this->assertSame(403, $res->get_status(), $call[1]);
        }
    }

    public function test_panel_renders_the_drawer_chat_on_the_users_own_conversation(): void
    {
        $uid = $this->asAdmin();
        $conversation = Plugin::instance()->get(ConversationStore::class)->create($uid, 'Drawer thread');

        $res = $this->rest('GET', '/view/panel', ['conversation_id' => (string) $conversation->id]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame('1', $res->get_headers()['X-Alpaca-Bot-View']);
        $html = $res->get_data();
        $this->assertStringStartsWith('<div class="ab-drawer__panel">', $html);
        $this->assertStringContainsString('<div class="ab-wrap ab-wrap--drawer">', $html);
        $this->assertStringContainsString('<div id="ab-chat" data-conversation="' . $conversation->id . '">', $html);
        $this->assertStringContainsString('id="ab-form"', $html);
    }

    public function test_panel_renders_the_post_and_the_screen_as_chips_and_the_post_only_for_a_user_who_may_edit_it(): void
    {
        $author = $this->asAdmin();
        // Private, with an apostrophe: get_the_title() would texturize the one (&#8217;) and,
        // this not being an admin request, prefix "Private: " for the other. The chip is named by
        // the stored title, the one CurrentScreenSource gives the model.
        $postId = self::factory()->post->create(['post_author' => $author, 'post_title' => "Carmelo's <em>draft</em>", 'post_status' => 'private']);
        $query = ['post_id' => (string) $postId, 'screen_id' => 'post', 'screen_title' => "Edit\n## Post"];

        $html = $this->rest('GET', '/view/panel', $query)->get_data();
        $this->assertStringContainsString('<input type="hidden" name="context[post_id]" value="' . $postId . '"><span class="ab-chip__label">Editing: Carmelo&#039;s draft</span>', $html);
        $this->assertStringContainsString('<input type="hidden" name="context[screen][id]" value="post"><input type="hidden" name="context[screen][title]" value="Edit ## Post"><span class="ab-chip__label">On: Edit ## Post</span>', $html);

        // A contributor has edit_posts, the Chat row's default, so may open the chat, but may not
        // edit an administrator's post: no post chip, and no id on the turn; the screen chip stays.
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $res = $this->rest('GET', '/view/panel', $query);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $html = $res->get_data();
        $this->assertStringNotContainsString('context[post_id]', $html);
        $this->assertStringNotContainsString('Carmelo', $html);
        $this->assertStringContainsString('data-chip="screen"', $html);
    }

    public function test_an_auto_draft_is_no_post_to_the_chip_or_to_the_model_and_the_screen_chip_stays(): void
    {
        $uid = $this->asAdmin();
        // What post-new.php makes before it shows the classic editor's form.
        $draft = get_default_post_to_edit('post', true);
        $this->assertSame('auto-draft', get_post($draft->ID)->post_status);
        $this->assertSame('Auto Draft', get_post($draft->ID)->post_title);

        $html = $this->rest('GET', '/view/panel', ['post_id' => (string) $draft->ID, 'screen_id' => 'post', 'screen_title' => 'Add Post'])->get_data();
        $this->assertStringNotContainsString('context[post_id]', $html);
        $this->assertStringNotContainsString('Auto Draft', $html);
        $this->assertStringContainsString('<span class="ab-chip__label">On: Add Post</span>', $html);

        $contexts = (new CurrentScreenSource())->collect($uid, ['post_id' => $draft->ID, 'screen' => ['id' => 'post', 'title' => 'Add Post']]);
        $this->assertSame(['screen:post'], array_map(static fn($c): string => $c->id, $contexts));

        // Saved as a draft, it is a post being edited.
        wp_update_post(['ID' => $draft->ID, 'post_title' => 'Now started', 'post_status' => 'draft']);
        $html = $this->rest('GET', '/view/panel', ['post_id' => (string) $draft->ID])->get_data();
        $this->assertStringContainsString('<span class="ab-chip__label">Editing: Now started</span>', $html);
    }

    public function test_panel_opens_another_users_conversation_as_a_new_chat_with_nothing_of_theirs(): void
    {
        $owner = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        $conversation = $store->create($owner, 'Owner private thread');
        $conversation->append(new Message('user', 'owner question'));
        $conversation->append(new Message('assistant', 'owner answer', 'fake-model'));
        $store->save($conversation);
        $this->assertSame('owner answer', $store->load($conversation->id, $owner)?->last()?->content);

        // A second administrator: same capability, not the owner.
        $this->asAdmin();
        $res = $this->rest('GET', '/view/panel', ['conversation_id' => (string) $conversation->id]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $html = $res->get_data();
        foreach (['Owner private thread', 'owner question', 'owner answer'] as $theirs) {
            $this->assertStringNotContainsString($theirs, $html);
        }
        $this->assertStringContainsString('<div id="ab-chat" data-conversation="0">', $html);
        $this->assertStringNotContainsString('data-conversation="' . $conversation->id . '"', $html);
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

    /**
     * The drawer's loader knows a page's own htmx by the id core prints on the handle's tag, which
     * Assets::mount() hands it; this is core printing that tag, so the two cannot drift apart.
     * The settings screen enqueues htmx, and a `script_loader_src` filter that strips `?ver=`
     * changes the URL and leaves the id.
     */
    public function test_the_htmx_id_the_loader_is_handed_is_the_one_core_prints_on_the_settings_screen(): void
    {
        $this->asAdmin();
        // A queue of its own: another test's enqueue of the chat screen would still be in the global one.
        $GLOBALS['wp_scripts'] = new \WP_Scripts();
        $strip = static fn(string $src): string => (string) remove_query_arg('ver', $src);
        add_filter('script_loader_src', $strip);
        // admin_enqueue_scripts fires with the screen set: core's own listeners read it.
        set_current_screen(\AlpacaBot\Admin\SettingsPage::screen());
        do_action('admin_enqueue_scripts', \AlpacaBot\Admin\SettingsPage::screen());
        $this->assertTrue(wp_script_is('alpaca-bot-htmx', 'enqueued'));
        $this->assertFalse(wp_script_is('alpaca-bot-chat', 'enqueued'));
        ob_start();
        wp_scripts()->do_items(['alpaca-bot-htmx']);
        $tag = (string) ob_get_clean();
        remove_filter('script_loader_src', $strip);
        $mount = (new Assets())->mount();
        $this->assertStringContainsString('id="' . $mount['htmxId'] . '"', $tag);
        $this->assertStringNotContainsString('ver=', $tag);
        $this->assertStringNotContainsString(esc_url($mount['htmx']), $tag);
        $GLOBALS['wp_scripts'] = null;
    }
}
