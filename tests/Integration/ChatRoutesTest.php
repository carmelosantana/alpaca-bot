<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;

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
        $raw = 'Could not resolve host: ollama-gateway.invalid for "http://ollama-gateway.invalid:11434/v1/chat/completions".';
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

    /**
     * Kanboard #4701 over real core: a tool turn is re-checked against the cap before its second
     * provider call, with the real receipts and the real month cache behind the figure. The user
     * has a receipt of 90 tokens against a cap of 100; the turn's first call spends 15, so the
     * second is never made. The model calls a tool that does not exist, which the agent answers
     * with an error result and goes on from, so no tool has a side effect here.
     */
    public function test_a_tool_turn_the_cap_stops_mid_way_is_a_402_that_keeps_its_partial_reply(): void
    {
        $admin = $this->asAdmin();
        Plugin::instance()->get(Store::class)->replace([
            'toolkits.enabled' => ['summarize'],
            'models.overrides' => ['fake-model' => ['tools' => Schema::TOOLS_ON]],
            'governance.user_monthly_tokens' => 100,
        ]);
        Plugin::instance()->get(UsageMeter::class)->record($admin, 'fake-model', 50, 40, 1);
        $calls = 0;
        add_filter('alpaca_bot/provider', static function () use (&$calls): ProviderInterface {
            return new class ($calls) implements ProviderInterface {
                public function __construct(private int &$calls) {}

                public function chat(array $messages, array $tools = [], array $options = []): Response
                {
                    throw new \LogicException('not used');
                }

                public function stream(array $messages, array $tools = [], array $options = []): iterable
                {
                    ++$this->calls;
                    yield new Response('Checking.', ProviderFinishReason::Stop);
                    yield new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'no_such_tool', ['q' => 'x'])], usage: new Usage(5, 10, 15));
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

        $res = $this->rest('POST', '/chat', ['message' => 'Look it up.']);

        $this->assertSame(402, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame(1, $calls, 'the second provider call was never made');
        $body = $res->get_data();
        $this->assertSame('alpaca_bot_cap_exceeded', $body['code']);
        $this->assertSame('This reply stopped because your monthly token cap was reached (105 of 100 tokens).', $body['message']);
        $id = $body['data']['conversation_id'];
        $this->assertSame(['status' => 402, 'scope' => 'user', 'limit' => 100, 'used' => 105, 'stopped' => true, 'conversation_id' => $id], $body['data']);

        // What the turn produced is on the conversation the 402 names, marked partial, with the call.
        $messages = $this->rest('GET', '/conversations/' . $id)->get_data()['messages'];
        $this->assertCount(2, $messages);
        $this->assertSame('Checking.', $messages[1]['content']);
        $meta = json_decode((string) wp_json_encode($messages[1]['meta']), true);
        $this->assertTrue($meta['partial']);
        $this->assertSame('no_such_tool', $meta['tool_calls'][0]['name']);
        // And the call it made is billed: the month is now 105, which refuses the next turn outright.
        $this->assertSame(105, Plugin::instance()->get(UsageMeter::class)->monthTotal($admin));
        $next = $this->rest('POST', '/chat', ['message' => 'Again.']);
        $this->assertSame(402, $next->get_status());
        $this->assertSame('Your monthly token cap has been reached (105 of 100 tokens).', $next->get_data()['message']);
        $this->assertSame(1, $calls);
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

    public function test_the_chat_row_opens_the_chat_routes_to_a_subscriber_and_a_route_filter_still_wins(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertSame(403, $this->rest('GET', '/conversations')->get_status());

        // The container's Store is what the controllers resolve through, so the row is written
        // through it (TestCase says a bare update_option() is not seen by the memo).
        Plugin::instance()->get(Store::class)->set('access.chat', 'read');
        $this->assertSame(200, $this->rest('GET', '/conversations')->get_status());

        // Code still wins, per route: the row is only the default the filter is handed.
        add_filter('alpaca_bot/capability/conversations', static fn(): string => 'edit_posts');
        $this->assertSame(403, $this->rest('GET', '/conversations')->get_status());
    }
}
