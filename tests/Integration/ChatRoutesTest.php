<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * POST /chat and the /conversations routes over real core: real permission callbacks, real
 * chat_history posts, the real rate-limit transient. Only the model provider is faked, through
 * `alpaca_bot/provider`, the seam Factory documents.
 *
 * @group rest
 */
final class ChatRoutesTest extends TestCase
{
    /**
     * Swaps the configured provider for one that answers "fake reply" (or throws) and lists a
     * single model, `fake-model`. The site's own `models.default` is left alone: ModelCatalog
     * falls back to the first listed model when the configured one is not in the catalog, and
     * the plugin's Store and catalog are memoised on the Plugin singleton across tests, so a
     * setting written here would outlive this test while an option write would not be seen.
     *
     * Applied at request time (Factory::make() runs inside the route), not on rest_api_init, so
     * it may be added after the server has been built.
     */
    private function fakeProvider(?\Throwable $failure = null): void
    {
        add_filter('alpaca_bot/provider', static fn(): ProviderInterface => new class ($failure) implements ProviderInterface {
            public function __construct(private ?\Throwable $failure) {}

            public function chat(array $messages, array $tools = [], array $options = []): Response
            {
                return new Response('fake reply', ProviderFinishReason::Stop, usage: new Usage(3, 2, 5));
            }

            public function stream(array $messages, array $tools = [], array $options = []): iterable
            {
                yield new Response('fake ', ProviderFinishReason::Stop);
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                yield new Response('reply', ProviderFinishReason::Stop);
                yield new Response('', ProviderFinishReason::Stop, usage: new Usage(3, 2, 5));
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
        });
    }

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
        $this->fakeProvider(new \RuntimeException('connection refused'));
        $this->asAdmin();
        $res = $this->rest('POST', '/chat', ['message' => 'hello?']);
        $this->assertSame(502, $res->get_status());
        $this->assertSame('alpaca_bot_provider_error', $res->get_data()['code']);
        $this->assertSame([], $this->rest('GET', '/conversations')->get_data());
        $this->assertSame([], get_posts(['post_type' => 'chat_history', 'post_status' => 'any', 'numberposts' => -1]));
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
