<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Plugin;
use AlpacaBot\Rest\StreamController;

/**
 * GET /chat/{id}/stream over real core: the ticket POST /chat issued is redeemed through the
 * real permission callback and transient store (core's HEAD-to-GET fallback included), and
 * stream() runs the real pipeline over real posts with the provider faked. What cannot run here
 * is serve(), the rest_pre_serve_request side: dispatch() stops before it, and
 * Sse::prepareOutput() would end every output buffer, PHPUnit's included; the unit suite covers
 * it with the output preparation replaced, and the wire itself is the real check's (curl
 * against the harness site, which is also the only place the concurrent claim can be watched).
 *
 * @group rest
 */
final class StreamRoutesTest extends TestCase
{
    /** @return array{0: string, 1: int} the token and the conversation id a streamed POST /chat answered with */
    private function ticket(string $message = 'later', int $conversation = 0): array
    {
        $res = $this->rest('POST', '/chat', ['message' => $message, 'stream' => true, 'conversation_id' => $conversation]);
        $this->assertSame(202, $res->get_status(), print_r($res->get_data(), true));
        return [$res->get_data()['token'], $res->get_data()['conversation_id']];
    }

    /**
     * Redeems `$token` for `$conversation` as the current user and hands back the ticket as it was
     * stored: handle() keeps the redeemed ticket for serve() rather than returning it, and serve()
     * does not run under dispatch(), so a test that streams reads the ticket before spending it.
     *
     * @return array<string, mixed>
     */
    private function redeem(string $token, int $conversation = 0): array
    {
        $ticket = get_transient('alpaca_bot_stream_' . $token);
        $this->assertIsArray($ticket);
        $res = $this->rest('GET', "/chat/{$conversation}/stream", ['token' => $token]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        return $ticket;
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> the frames stream() wrote for the ticket, as [event, data] */
    private function frames(array $ticket): array
    {
        $frames = [];
        $controller = new StreamController(Plugin::instance()->get(Pipeline::class));
        $controller->stream($ticket, static function (string $frame) use (&$frames): void {
            $frames[] = $frame;
        });
        return array_map(static function (string $frame): array {
            self::assertMatchesRegularExpression('/^event: [a-z]+\ndata: \{.*\}\n\n$/s', $frame);
            [$event, $data] = explode("\n", $frame, 2);
            return [substr($event, 7), json_decode(substr(rtrim($data, "\n"), 6), true)];
        }, $frames);
    }

    public function test_the_route_is_registered_and_the_controller_serves_the_stream_itself(): void
    {
        $server = rest_get_server();
        $this->assertArrayHasKey('/alpaca-bot/v1/chat/(?P<id>\d+)/stream', $server->get_routes());
        $hooked = [];
        foreach ($GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[PHP_INT_MAX] ?? [] as $hook) {
            $hooked[] = is_array($hook['function']) && $hook['function'][0] instanceof StreamController ? $hook['function'][1] : null;
        }
        $this->assertSame(['serve'], $hooked);
    }

    public function test_the_ticket_is_redeemed_once_by_its_owner_for_its_conversation_by_get_only(): void
    {
        $owner = $this->asAdmin();
        [$token] = $this->ticket();
        $ticket = get_transient('alpaca_bot_stream_' . $token);
        $this->assertSame($owner, $ticket['user_id']);
        $this->assertSame('later', $ticket['message']);
        $this->assertSame(0, $ticket['options']['conversation_id']);

        $refused = $this->rest('GET', '/chat/0/stream');
        $this->assertSame(400, $refused->get_status());
        $this->assertSame('rest_missing_callback_param', $refused->get_data()['code']);

        // Another editor holding the token: refused, and the ticket survives for its owner.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $theirs = $this->rest('GET', '/chat/0/stream', ['token' => $token]);
        $this->assertSame(403, $theirs->get_status());
        $this->assertSame('rest_forbidden', $theirs->get_data()['code']);
        $this->assertSame($ticket, get_transient('alpaca_bot_stream_' . $token));

        wp_set_current_user($owner);
        $this->assertSame(403, $this->rest('GET', '/chat/7/stream', ['token' => $token])->get_status());

        // A HEAD reaches handle() through core's fallback to the GET handler and is refused
        // there, before the ticket is touched: a probe must neither run the turn nor spend it.
        $head = $this->rest('HEAD', '/chat/0/stream', ['token' => $token]);
        $this->assertSame(405, $head->get_status(), print_r($head->get_data(), true));
        $this->assertSame('alpaca_bot_method_not_allowed', $head->get_data()['code']);
        $this->assertSame(['Allow' => 'GET'], $head->get_headers());
        $this->assertSame($ticket, get_transient('alpaca_bot_stream_' . $token));

        // Redeemed: an empty 200 (serve() streams the ticket handle() kept; the body is never
        // core's to render), and the stored ticket is gone.
        $res = $this->rest('GET', '/chat/0/stream', ['token' => $token]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame([], $res->get_headers());
        $this->assertNull($res->get_data());
        $this->assertFalse(get_transient('alpaca_bot_stream_' . $token));
        // ... and, the property the claim rests on, a delete of a spent ticket says it removed nothing.
        $this->assertFalse(delete_transient('alpaca_bot_stream_' . $token));

        // One-time: the same token again is as good as none.
        $this->assertSame(403, $this->rest('GET', '/chat/0/stream', ['token' => $token])->get_status());
    }

    public function test_a_visitor_is_told_to_authenticate(): void
    {
        $res = $this->rest('GET', '/chat/0/stream', ['token' => 'x']);
        $this->assertSame(401, $res->get_status());
        $this->assertSame('rest_forbidden', $res->get_data()['code']);
    }

    public function test_stream_writes_start_deltas_and_done_and_persists_the_turn(): void
    {
        $this->fakeProvider();
        $user = $this->asAdmin();
        [$token] = $this->ticket('hello there');
        $ticket = $this->redeem($token);

        $frames = $this->frames($ticket);
        $this->assertSame(['start', 'delta', 'delta', 'done'], array_column($frames, 0));
        // The conversation was created by this turn: `start` is where the client learns its id.
        $id = $frames[0][1]['conversation_id'];
        $this->assertGreaterThan(0, $id);
        $this->assertSame('fake-model', $frames[0][1]['model']);
        $this->assertSame(['text' => 'fake ', 'reasoning' => ''], $frames[1][1]);
        $this->assertSame(['text' => 'reply', 'reasoning' => ''], $frames[2][1]);
        $done = $frames[3][1];
        $this->assertSame($id, $done['conversation_id']);
        $this->assertSame('fake reply', $done['message']['content']);
        $this->assertSame(5, $done['receipt']['total_tokens']);
        $this->assertSame([], $done['contexts']);
        // ... and the done frame is the body a direct POST /chat answers with.
        $this->assertSame(['conversation_id', 'message', 'receipt', 'contexts'], array_keys($done));

        $one = $this->rest('GET', '/conversations/' . $id)->get_data();
        $this->assertSame(['hello there', 'fake reply'], array_column($one['messages'], 'content'));
        $this->assertSame($user, (int) get_post($id)->post_author);
    }

    public function test_a_provider_failure_is_an_error_frame_that_names_the_gateway_to_administrators_only(): void
    {
        $raw = 'Could not resolve host: ollama-gateway.internal for "http://ollama-gateway.internal:11434/v1/chat/completions".';
        $this->fakeProvider(new \RuntimeException($raw));
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        [$token] = $this->ticket('hello?');
        $ticket = $this->redeem($token);

        $log = (string) tempnam(sys_get_temp_dir(), 'alpaca-bot-');
        $was = ini_set('error_log', $log);
        try {
            $frames = $this->frames($ticket);
        } finally {
            ini_set('error_log', (string) $was);
        }
        $this->assertSame(['start', 'delta', 'error'], array_column($frames, 0));
        $this->assertSame('alpaca_bot_provider_error', $frames[2][1]['code']);
        $this->assertSame('The model provider could not complete the request.', $frames[2][1]['message']);
        $this->assertSame(['status' => 502], $frames[2][1]['data']);
        $this->assertStringNotContainsString('ollama-gateway', (string) wp_json_encode($frames));
        $this->assertStringContainsString($raw, (string) file_get_contents($log));
        unlink($log);
        // A failed first turn leaves no conversation behind, streamed or not.
        $this->assertSame([], $this->rest('GET', '/conversations')->get_data());

        $this->asAdmin();
        [$token] = $this->ticket('hello?');
        $ticket = $this->redeem($token);
        $was = ini_set('error_log', $log);
        try {
            $frames = $this->frames($ticket);
        } finally {
            ini_set('error_log', (string) $was);
            unlink($log);
        }
        $this->assertSame('Provider error: ' . $raw, $frames[2][1]['data']['detail']);
    }

    public function test_a_refused_turn_is_a_single_error_frame(): void
    {
        $this->fakeProvider();
        $this->asAdmin();
        $other = $this->rest('POST', '/chat', ['message' => 'theirs'])->get_data()['conversation_id'];
        $this->asAdmin();
        [$token] = $this->ticket('continue', $other);
        $ticket = $this->redeem($token, $other);
        $frames = $this->frames($ticket);
        $this->assertCount(1, $frames);
        $this->assertSame('error', $frames[0][0]);
        $this->assertSame('alpaca_bot_bad_request', $frames[0][1]['code']);
        $this->assertSame(['status' => 400], $frames[0][1]['data']);
    }
}
