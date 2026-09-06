<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

/**
 * POST /chat and the /conversations routes over real core: real permission callbacks, real
 * chat_history posts, the real rate-limit transient. Only the model provider is faked, through
 * `alpaca_bot/provider`, the seam Factory documents (TestCase::fakeProvider()).
 *
 * @group rest
 */
final class ChatRoutesTest extends TestCase
{
    public function test_chat_requires_login(): void
    {
        $res = $this->rest('POST', '/chat', ['message' => 'hi']);
        $this->assertSame(401, $res->get_status());
        $this->assertSame('rest_forbidden', $res->get_data()['code']);
    }

    public function test_chat_creates_a_private_conversation_and_lists_it(): void
    {
        $this->fakeProvider();
        $this->asAdmin();
        $res = $this->rest('POST', '/chat', ['message' => 'hello there']);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $data = $res->get_data();
        $this->assertSame('fake reply', $data['message']['content']);
        $this->assertSame('assistant', $data['message']['role']);
        $this->assertSame(5, $data['receipt']['total_tokens']);
        $this->assertSame($data['conversation_id'], $data['receipt']['conversation_id']);
        $this->assertSame([], $data['contexts']);

        $list = $this->rest('GET', '/conversations')->get_data();
        $this->assertCount(1, $list);
        $this->assertSame($data['conversation_id'], $list[0]['id']);
        $this->assertSame('hello there', $list[0]['title']);
        $this->assertSame($list, $this->rest('GET', '/conversations', ['limit' => 200])->get_data());
        // Out of the declared range is core's schema refusal, not a clamp.
        foreach ([201, -1] as $limit) {
            $refused = $this->rest('GET', '/conversations', ['limit' => $limit]);
            $this->assertSame(400, $refused->get_status());
            $this->assertSame('rest_invalid_param', $refused->get_data()['code']);
        }

        $one = $this->rest('GET', '/conversations/' . $data['conversation_id'])->get_data();
        $this->assertCount(2, $one['messages']);
        $this->assertSame('hello there', $one['messages'][0]['content']);
        $this->assertSame('fake reply', $one['messages'][1]['content']);
        $this->assertSame('private', get_post_status($data['conversation_id']));

        $this->assertTrue($this->rest('DELETE', '/conversations/' . $data['conversation_id'])->get_data()['deleted']);
        $this->assertNull(get_post($data['conversation_id']));
        $this->assertSame(404, $this->rest('GET', '/conversations/' . $data['conversation_id'])->get_status());
    }

    public function test_delete_collection_removes_every_conversation_of_the_user_only(): void
    {
        $this->fakeProvider();
        $other = $this->asAdmin();
        $theirs = $this->rest('POST', '/chat', ['message' => 'theirs'])->get_data()['conversation_id'];
        $this->asAdmin();
        $this->rest('POST', '/chat', ['message' => 'mine one']);
        $this->rest('POST', '/chat', ['message' => 'mine two']);
        $this->assertCount(2, $this->rest('GET', '/conversations')->get_data());

        $this->assertSame(['deleted' => 2], $this->rest('DELETE', '/conversations')->get_data());
        $this->assertSame([], $this->rest('GET', '/conversations')->get_data());
        $this->assertSame('private', get_post_status($theirs));
        wp_set_current_user($other);
        $this->assertCount(1, $this->rest('GET', '/conversations')->get_data());
    }

    public function test_an_images_only_turn_may_omit_message_entirely(): void
    {
        // The documented contract: images-only turns are accepted. `message` was `required`, so
        // core refused the request at dispatch (rest_missing_callback_param) before the
        // controller's own empty-turn check could accept it.
        $this->fakeProvider();
        $this->asAdmin();
        $res = $this->rest('POST', '/chat', ['images' => ['data:image/png;base64,AAAA']]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame('fake reply', $res->get_data()['message']['content']);
        $one = $this->rest('GET', '/conversations/' . $res->get_data()['conversation_id'])->get_data();
        $this->assertSame('', $one['messages'][0]['content']);
        $this->assertSame(['data:image/png;base64,AAAA'], $one['messages'][0]['images']);

        // Nothing at all is the controller's 400, in its words, not core's schema error.
        $empty = $this->rest('POST', '/chat', []);
        $this->assertSame(400, $empty->get_status());
        $this->assertSame('alpaca_bot_bad_request', $empty->get_data()['code']);
    }

    public function test_delete_collection_drains_more_than_one_batch(): void
    {
        $user = $this->asAdmin();
        $n = \AlpacaBot\Rest\ConversationsController::BATCH + 1;
        self::factory()->post->create_many($n, ['post_type' => 'chat_history', 'post_author' => $user, 'post_status' => 'private']);
        $this->assertSame(['deleted' => $n], $this->rest('DELETE', '/conversations')->get_data());
        $this->assertSame([], get_posts(['post_type' => 'chat_history', 'author' => $user, 'post_status' => 'any', 'numberposts' => -1]));
    }

    public function test_other_users_cannot_read_or_delete_a_conversation(): void
    {
        $this->fakeProvider();
        $this->asAdmin();
        $id = $this->rest('POST', '/chat', ['message' => 'secret'])->get_data()['conversation_id'];
        $this->asAdmin();
        $this->assertSame(404, $this->rest('GET', '/conversations/' . $id)->get_status());
        $this->assertSame(404, $this->rest('DELETE', '/conversations/' . $id)->get_status());
        $this->assertSame(400, $this->rest('POST', '/chat', ['message' => 'continue', 'conversation_id' => $id])->get_status());
        $this->assertSame('private', get_post_status($id));
    }

    public function test_rate_limit_returns_429_with_retry_after(): void
    {
        $this->fakeProvider();
        $this->asAdmin();
        add_filter('alpaca_bot/rate_limit', static fn(): int => 1);
        $this->assertSame(200, $this->rest('POST', '/chat', ['message' => 'one'])->get_status());
        $blocked = $this->rest('POST', '/chat', ['message' => 'two']);
        $this->assertSame(429, $blocked->get_status());
        $this->assertSame('alpaca_bot_rate_limited', $blocked->get_data()['code']);
        $this->assertArrayHasKey('Retry-After', $blocked->get_headers());
        $this->assertSame((string) $blocked->get_data()['data']['retry_after'], $blocked->get_headers()['Retry-After']);
    }

    public function test_a_provider_failure_is_a_502_and_leaves_no_empty_conversation(): void
    {
        // What the vendored client throws for a provider that is down or misconfigured, verbatim:
        // it names the gateway. An editor may chat, so an editor must not read it back.
        $raw = 'Could not resolve host: ollama-gateway.internal for "http://ollama-gateway.internal:11434/v1/chat/completions".';
        $this->fakeProvider(new \RuntimeException($raw));
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $log = (string) tempnam(sys_get_temp_dir(), 'alpaca-bot-');
        $was = ini_set('error_log', $log);
        try {
            $res = $this->rest('POST', '/chat', ['message' => 'hello?']);
        } finally {
            ini_set('error_log', (string) $was);
        }
        $this->assertSame(502, $res->get_status());
        $this->assertSame('alpaca_bot_provider_error', $res->get_data()['code']);
        $this->assertSame('The model provider could not complete the request.', $res->get_data()['message']);
        $this->assertSame(['status' => 502], $res->get_data()['data']);
        $this->assertStringNotContainsString('ollama-gateway', (string) wp_json_encode($res->get_data()));
        // ... it went to the debug log instead (WP_DEBUG is on in the test config).
        $this->assertStringContainsString($raw, (string) file_get_contents($log));
        unlink($log);
        $this->assertSame([], $this->rest('GET', '/conversations')->get_data());
        $this->assertSame([], get_posts(['post_type' => 'chat_history', 'post_status' => 'any', 'numberposts' => -1]));

        // An administrator gets the same body plus the text, as data.detail.
        $this->asAdmin();
        $was = ini_set('error_log', $log);
        try {
            $admin = $this->rest('POST', '/chat', ['message' => 'hello?']);
        } finally {
            ini_set('error_log', (string) $was);
            unlink($log);
        }
        $this->assertSame(502, $admin->get_status());
        $this->assertSame('The model provider could not complete the request.', $admin->get_data()['message']);
        $this->assertSame('Provider error: ' . $raw, $admin->get_data()['data']['detail']);
    }

    public function test_stream_true_answers_a_ticket_without_chatting(): void
    {
        $this->asAdmin();
        $res = $this->rest('POST', '/chat', ['message' => 'later', 'stream' => true]);
        $this->assertSame(202, $res->get_status());
        $data = $res->get_data();
        $this->assertSame(0, $data['conversation_id']);
        // The test install has plain permalinks, so rest_url() already carries ?rest_route=...
        // and the token joins that query string; under pretty permalinks it starts one. Either
        // way the URL is what core would build for the route.
        $this->assertSame(add_query_arg('token', $data['token'], rest_url('alpaca-bot/v1/chat/0/stream')), $data['stream_url']);
        $this->assertSame(32, strlen($data['token']));
        $ticket = get_transient('alpaca_bot_stream_' . $data['token']);
        $this->assertSame(get_current_user_id(), $ticket['user_id']);
        $this->assertSame('later', $ticket['message']);
        $this->assertSame([], $this->rest('GET', '/conversations')->get_data());
    }
}
