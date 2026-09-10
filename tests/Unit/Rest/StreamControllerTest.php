<?php

declare(strict_types=1);

use AlpacaBot\Chat\Assistant;
use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Rest\ChatController;
use AlpacaBot\Rest\StreamBudget;
use AlpacaBot\Rest\StreamController;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// restRequest(), restConvertsErrors(), pipelineWith(), pipelineProvider(), actionRuns() and
// streamTicket() live in tests/Pest.php.
// Pipeline is final, so stream() runs over the real one with WordPress and the provider stubbed,
// as ChatControllerTest does. Three things are pinned: handle() redeems a ticket for its owner,
// once even when the same token arrives concurrently, for GET only, and nothing else; serve()
// takes over exactly the request handle() redeemed, whatever response core hands it, and no
// other; stream() turns what the pipeline yields, returns or throws into frames, with the same
// error policy as the JSON route, and stops when the client has gone. Sse::prepareOutput() ends
// every output buffer, this runner's included, so serve() is built with it replaced; what it
// really does to a PHP process is the wire check's (curl against the harness site) — which is
// also why the budget is checked by what serve() *passes* it rather than by set_time_limit().
// The conversation post the harness serves is 42, owned by user 3; the current user is 3.

beforeEach(function (): void {
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('current_user_can')->justReturn(false);
});

it('declares one GET /chat/{id}/stream route for editors, token required, not rate limited', function (): void {
    $h = pipelineWith(null);
    $routes = (new StreamController($h->pipeline, $h->store))->routes();
    expect($routes)->toHaveCount(1)
        ->and($routes[0]['path'])->toBe('/chat/(?P<id>\d+)/stream')
        ->and($routes[0]['methods'])->toBe('GET')
        ->and($routes[0]['capability'])->toBe('edit_posts')
        ->and($routes[0]['args'])->toBe(['token' => ['type' => 'string', 'required' => true]])
        // The turn was already counted on the POST that issued the ticket; a second hit here
        // would make a streamed turn cost two.
        ->and($routes[0])->not->toHaveKey('rate_limit')
        ->and(StreamController::routeKey($routes[0]['path']))->toBe('chat/stream');
});

it('registers its route and hooks rest_pre_serve_request to serve the stream itself', function (): void {
    Functions\expect('register_rest_route')->once()->withArgs(fn(string $ns, string $path): bool => $ns === 'alpaca-bot/v1' && $path === '/chat/(?P<id>\d+)/stream');
    $h = pipelineWith(null);
    $controller = new StreamController($h->pipeline, $h->store);
    // Last, after core's own handlers and anything else that may want to serve the request or
    // still send a header: once the first frame is out, a header() call would only warn.
    Filters\expectAdded('rest_pre_serve_request')->once()->with([$controller, 'serve'], PHP_INT_MAX, 4);
    $controller->register();
});

it('refuses a token that is missing, unknown, another user\'s, or for another conversation, and leaves the ticket alone', function (): void {
    $h = pipelineWith(null);
    $table = slotRowsIn();
    $h->transients[ChatController::STREAM_TRANSIENT . 'theirs'] = streamTicket(['user_id' => 9]);
    $h->transients[ChatController::STREAM_TRANSIENT . 'mine'] = streamTicket();
    $deleted = [];
    Functions\when('delete_transient')->alias(static function (string $key) use (&$deleted): bool {
        $deleted[] = $key;
        return true;
    });
    $controller = new StreamController($h->pipeline, $h->store);
    foreach ([
        ['id' => '42'],                                  // no token at all
        ['id' => '42', 'token' => ''],
        ['id' => '42', 'token' => 'nope'],               // no such ticket
        ['id' => '42', 'token' => 'theirs'],             // user 9's
        ['id' => '7', 'token' => 'mine'],                // mine, but not for conversation 7
        ['id' => '42', 'token' => '../../mine'],         // refused as written, not reduced to 'mine'
    ] as $params) {
        $res = $controller->handle(restRequest('GET', '/alpaca-bot/v1/chat/42/stream', $params));
        expect($res)->toBeInstanceOf(WP_Error::class, json_encode($params))
            ->and($res->get_error_code())->toBe('rest_forbidden')
            ->and($res->get_error_message())->toBe('Invalid or expired stream token.')
            ->and($res->get_error_data())->toBe(['status' => 403]);
    }
    // No ticket was deleted, and not one statement was run against a slot row: a refused request
    // spends nothing and writes nothing. The claim comes after these checks, so a bad-token
    // probe costs one transient read — claiming first would charge it an insert and a delete.
    expect($deleted)->toBe([])
        ->and($table->rows)->toBe([])
        ->and(array_filter($table->queries, static fn(string $q): bool => str_contains($q, StreamBudget::OPTION)))->toBe([]);
});

it('redeems a valid ticket once: the deletion is the claim, and the response carries nothing of the ticket', function (): void {
    $h = pipelineWith(null);
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    $deleted = [];
    Functions\when('delete_transient')->alias(static function (string $key) use (&$deleted, $h): bool {
        $deleted[] = $key;
        if (!isset($h->transients[$key])) {
            return false;
        }
        unset($h->transients[$key]);
        return true;
    });
    $controller = new StreamController($h->pipeline, $h->store);
    $res = $controller->handle(restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)
        ->and($res->get_status())->toBe(200)
        // The ticket is held for serve(), not returned: core renders this body only if nothing
        // streams, and a wrapped copy of it (?_envelope=1) is not where serve() looks.
        ->and($res->get_headers())->toBe([])
        ->and($res->get_data())->toBeNull()
        // Deleted on redemption, before anything runs: the same token is now as good as none.
        ->and($deleted)->toBe([ChatController::STREAM_TRANSIENT . 'tok'])
        ->and($controller->handle(restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok'])))->toBeInstanceOf(WP_Error::class);
});

it('refuses a token another request claimed first: a delete that removed nothing is a lost race, not a redemption', function (): void {
    // Two requests holding one token both read the ticket and both pass the ownership checks;
    // the store then reports that this delete removed no row (the other request's did). Without
    // this the same ticket would run one billed turn per concurrent request.
    $h = pipelineWith(null);
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    Functions\when('delete_transient')->justReturn(false);
    $prepared = 0;
    $controller = new StreamController($h->pipeline, $h->store, function () use (&$prepared): void {
        $prepared++;
    });
    $request = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    $res = $controller->handle($request);
    expect($res)->toBeInstanceOf(WP_Error::class)
        ->and($res->get_error_code())->toBe('rest_forbidden')
        ->and($res->get_error_data())->toBe(['status' => 403]);
    // ... and the loser is not streamed either: nothing was held for serve().
    $server = new WP_REST_Server();
    expect($controller->serve(false, new WP_REST_Response(), $request, $server))->toBeFalse()
        ->and($server->sent)->toBe([])
        ->and($prepared)->toBe(0);
});

it('refuses HEAD with 405 and Allow: GET before the ticket is read, so a probe cannot redeem it', function (): void {
    // Core routes a HEAD to the GET handler when no HEAD handler is registered, and drops the
    // body only after rest_pre_serve_request has run: taken here, a HEAD would run a billed turn
    // and write a stream onto a response that must have no body.
    $h = pipelineWith(null);
    $reads = 0;
    Functions\when('get_transient')->alias(static function () use (&$reads): array {
        $reads++;
        return streamTicket();
    });
    $deleted = [];
    Functions\when('delete_transient')->alias(static function (string $key) use (&$deleted): bool {
        $deleted[] = $key;
        return true;
    });
    restConvertsErrors();
    $controller = new StreamController($h->pipeline, $h->store);
    $request = restRequest('HEAD', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    $res = $controller->handle($request);
    expect($res)->toBeInstanceOf(WP_REST_Response::class)
        ->and($res->get_status())->toBe(405)
        ->and($res->get_headers())->toBe(['Allow' => 'GET'])
        ->and($res->get_data()['code'])->toBe('alpaca_bot_method_not_allowed')
        ->and($reads)->toBe(0)
        ->and($deleted)->toBe([]);
    $server = new WP_REST_Server();
    expect($controller->serve(false, $res, $request, $server))->toBeFalse()
        ->and($server->sent)->toBe([]);
});

it('serve() leaves alone a request nothing was redeemed for, one redeemed for a different request, and one already served', function (): void {
    $h = pipelineWith(null);
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    $prepared = 0;
    $controller = new StreamController($h->pipeline, $h->store, function () use (&$prepared): void {
        $prepared++;
    });
    $server = new WP_REST_Server();

    // A refusal: core renders the WP_Error handle() returned as its own JSON response.
    $refused = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'nope']);
    expect($controller->handle($refused))->toBeInstanceOf(WP_Error::class)
        ->and($controller->serve(false, new WP_REST_Response(['code' => 'rest_forbidden'], 403), $refused, $server))->toBeFalse();
    // Another route's response (OPTIONS, or any other plugin's), on a request this controller
    // never saw: the hook runs for every REST request and must not hijack one.
    expect($controller->serve(false, new WP_REST_Response(['other' => 'route']), restRequest('GET', '/wp/v2/posts'), $server))->toBeFalse();

    // Redeemed, but core is serving a different request object than the one handle() saw.
    $request = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    expect($controller->handle($request))->toBeInstanceOf(WP_REST_Response::class);
    $twin = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    expect($controller->serve(false, new WP_REST_Response(), $twin, $server))->toBeFalse();

    // Already served by something hooked earlier: nothing is written over it.
    expect($controller->serve(true, new WP_REST_Response(), $request, $server))->toBeTrue()
        ->and($server->sent)->toBe([])
        ->and($prepared)->toBe(0);
});

it('serve() takes over the redeemed request: SSE headers, then the output prepared, then the frames, and tells core it was served', function (): void {
    $h = pipelineWith(pipelineProvider([
        new Response('a', ProviderFinishReason::Stop),
        new Response('b', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)),
    ]));
    actionRuns('alpaca_bot/chat/started');
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    $server = new WP_REST_Server();
    $sentBeforePrepare = null;
    $controller = new StreamController($h->pipeline, $h->store, function () use (&$sentBeforePrepare, $server): void {
        $sentBeforePrepare = $server->sent;
    });
    $request = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    $response = $controller->handle($request);
    expect($response)->toBeInstanceOf(WP_REST_Response::class);

    ob_start();
    $served = $controller->serve(false, $response, $request, $server);
    $out = (string) ob_get_clean();
    expect($served)->toBeTrue()
        // The headers go out before the buffers are ended: once a frame is written they cannot.
        ->and($sentBeforePrepare)->toBe([
            ['Content-Type', 'text/event-stream; charset=utf-8'],
            ['Cache-Control', 'no-cache'],
            ['X-Accel-Buffering', 'no'],
        ])
        ->and($server->sent)->toBe($sentBeforePrepare)
        ->and($out)->toStartWith("event: start\ndata: {\"conversation_id\":42,\"model\":\"llama3.2\"}\n\nevent: delta\ndata: {\"text\":\"a\",\"reasoning\":\"\"}\n\nevent: delta\ndata: {\"text\":\"b\",\"reasoning\":\"\"}\n\nevent: done\ndata: {")
        ->and($out)->toEndWith("\n\n")
        ->and($h->meta[42]['ab_messages'][1]['content'])->toBe('ab');

    // Consumed: the same request served again streams nothing more.
    ob_start();
    expect($controller->serve(false, $response, $request, $server))->toBeFalse()
        ->and(ob_get_clean())->toBe('');
});

it('serve() streams a redeemed request whose response core has re-wrapped (?_envelope=1), which keeps nothing handle() returned', function (): void {
    // WP_REST_Server::envelope_response() hands the filter a new response: status 200, no
    // headers, the original as `body`. What serve() streams is the ticket handle() held for
    // this request, so the wrapping changes nothing; a marker on the response would be gone.
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    $controller = new StreamController($h->pipeline, $h->store, static function (): void {});
    $request = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    $response = $controller->handle($request);
    $enveloped = new WP_REST_Response(['body' => $response->get_data(), 'status' => $response->get_status(), 'headers' => $response->get_headers()], 200);
    $server = new WP_REST_Server();
    ob_start();
    $served = $controller->serve(false, $enveloped, $request, $server);
    $out = (string) ob_get_clean();
    expect($served)->toBeTrue()
        ->and(array_column($server->sent, 0))->toBe(['Content-Type', 'Cache-Control', 'X-Accel-Buffering'])
        ->and($out)->toStartWith("event: error\ndata: {\"code\":\"alpaca_bot_cap_exceeded\"");
});

it('writes a start frame with the real conversation id, a delta per chunk, then a done frame shaped like POST /chat\'s 200', function (): void {
    $h = pipelineWith(pipelineProvider([
        new Response('a', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop, reasoning: 'thinking'),
        new Response('b', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)),
    ], $call), [], [], ['llama3.2', 'qwen3:8b']);
    actionRuns('alpaca_bot/chat/started');
    // The listener is this turn's only, and is gone once the turn is.
    Actions\expectRemoved('alpaca_bot/chat/started')->once();
    $frames = [];
    // A new conversation: the ticket says 0, the pipeline creates post 42 on its first advance
    // and `start` is where the client first learns the id, before any text arrives.
    (new StreamController($h->pipeline, $h->store))->stream(streamTicket(['conversation_id' => 0, 'options' => ['conversation_id' => 0, 'model' => 'qwen3:8b']]), function (string $f) use (&$frames): void {
        $frames[] = $f;
    });
    expect($frames)->toHaveCount(5)
        ->and($frames[0])->toBe("event: start\ndata: {\"conversation_id\":42,\"model\":\"qwen3:8b\"}\n\n")
        ->and($frames[1])->toBe("event: delta\ndata: {\"text\":\"a\",\"reasoning\":\"\"}\n\n")
        ->and($frames[2])->toBe("event: delta\ndata: {\"text\":\"\",\"reasoning\":\"thinking\"}\n\n")
        ->and($frames[3])->toBe("event: delta\ndata: {\"text\":\"b\",\"reasoning\":\"\"}\n\n")
        ->and($frames[4])->toStartWith("event: done\ndata: ")
        ->and($h->model)->toBe('qwen3:8b');
    $done = json_decode(substr($frames[4], strlen("event: done\ndata: ")), true);
    expect($done)->toHaveKeys(['conversation_id', 'message', 'receipt', 'contexts'])
        ->and($done['conversation_id'])->toBe(42)
        // message.meta carries the turn's duration on the wire too: the same number as receipt.duration_ms.
        ->and($done['message'])->toMatchArray(['role' => 'assistant', 'content' => 'ab', 'model' => 'qwen3:8b', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2], 'meta' => ['duration_ms' => $done['receipt']['duration_ms'], 'reasoning' => 'thinking']])
        ->and($done['receipt'])->toMatchArray(['user_id' => 3, 'model' => 'qwen3:8b', 'total_tokens' => 7, 'conversation_id' => 42, 'log_id' => 9])
        ->and($done['contexts'])->toBe([])
        // The stored transcript is what was streamed.
        ->and($h->meta[42]['ab_messages'][1]['content'])->toBe('ab');
});

it('stops after the frame the client did not read: no done frame, the listener removed, the partial reply stored and chat/failed fired', function (): void {
    // connection_aborted() only learns of a closed connection from a failed write, which is why
    // stream() asks after each frame; here the probe answers for it. The generator is left
    // undrained, which the pipeline treats as an abandoned turn.
    $h = pipelineWith(pipelineProvider([
        new Response('a', ProviderFinishReason::Stop),
        new Response('b', ProviderFinishReason::Stop),
    ]));
    actionRuns('alpaca_bot/chat/started');
    Actions\expectRemoved('alpaca_bot/chat/started')->once();
    Actions\expectDone('alpaca_bot/chat/failed')->once()->with(Mockery::type(\RuntimeException::class), Mockery::type(Conversation::class));
    $frames = [];
    (new StreamController($h->pipeline, $h->store))->stream(
        streamTicket(),
        function (string $f) use (&$frames): void {
            $frames[] = $f;
        },
        static function () use (&$frames): bool {
            return count($frames) >= 2;
        },
    );
    expect(array_map(static fn(string $f): string => strtok($f, "\n"), $frames))->toBe(['event: start', 'event: delta'])
        ->and($h->meta[42]['ab_messages'][1])->toMatchArray(['role' => 'assistant', 'content' => 'a'])
        ->and($h->meta[42]['ab_messages'][1]['meta']->partial)->toBeTrue()
        ->and($h->meta[42]['ab_messages'][1]['meta']->duration_ms)->toBeInt(); // the partial reply's receipt shows its seconds on reload
});

it('writes one error frame, in the JSON route\'s error shape, when the pipeline refuses the turn', function (): void {
    // Over the cap: 402's code and data, before any provider call and with no start frame.
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    Actions\expectDone('alpaca_bot/chat/started')->never();
    $frames = [];
    (new StreamController($h->pipeline, $h->store))->stream(streamTicket(), function (string $f) use (&$frames): void {
        $frames[] = $f;
    });
    expect($frames)->toBe(["event: error\ndata: {\"code\":\"alpaca_bot_cap_exceeded\",\"message\":\"Your monthly token cap has been reached (12 of 10 tokens).\",\"data\":{\"status\":402,\"scope\":\"user\",\"limit\":10,\"used\":12}}\n\n"]);

    // A conversation that is not the caller's: the pipeline's own words, as a 400 would carry them.
    $h = pipelineWith(null);
    $h->post = conversationChatPost(42, '9');
    $frames = [];
    (new StreamController($h->pipeline, $h->store))->stream(streamTicket(), function (string $f) use (&$frames): void {
        $frames[] = $f;
    });
    expect($frames)->toBe(["event: error\ndata: {\"code\":\"alpaca_bot_bad_request\",\"message\":\"Conversation 42 was not found.\",\"data\":{\"status\":400}}\n\n"]);
});

it('keeps the provider\'s words out of an editor\'s error frame and hands them to an administrator as data.detail', function (): void {
    // What the vendored client throws names the gateway. The frame is written to whoever holds
    // the ticket, and anyone who may chat can make the provider throw, so the policy is
    // Errors::provider()'s: fixed message, the raw text only for manage_options.
    $raw = 'Could not resolve host: ollama-gateway.internal for "http://ollama-gateway.internal:11434/v1/chat/completions".';
    $h = pipelineWith(pipelineProvider([new Response('par', ProviderFinishReason::Stop), new \RuntimeException($raw)]));
    $frames = [];
    (new StreamController($h->pipeline, $h->store))->stream(streamTicket(), function (string $f) use (&$frames): void {
        $frames[] = $f;
    });
    // The text that had arrived was streamed; the failure follows it, then nothing more.
    expect($frames)->toHaveCount(2)
        ->and($frames[0])->toBe("event: delta\ndata: {\"text\":\"par\",\"reasoning\":\"\"}\n\n")
        ->and($frames[1])->toBe("event: error\ndata: {\"code\":\"alpaca_bot_provider_error\",\"message\":\"The model provider could not complete the request.\",\"data\":{\"status\":502}}\n\n")
        ->and(implode('', $frames))->not->toContain('ollama-gateway');

    Functions\when('current_user_can')->justReturn(true);
    $h = pipelineWith(pipelineProvider([new \RuntimeException($raw)]));
    $frames = [];
    (new StreamController($h->pipeline, $h->store))->stream(streamTicket(), function (string $f) use (&$frames): void {
        $frames[] = $f;
    });
    expect($frames)->toHaveCount(1)
        ->and($frames[0])->toStartWith("event: error\ndata: ");
    $error = json_decode(substr($frames[0], strlen("event: error\ndata: ")), true);
    expect($error)->toBe([
        'code' => 'alpaca_bot_provider_error',
        'message' => 'The model provider could not complete the request.',
        'data' => ['status' => 502, 'detail' => 'Provider error: ' . $raw],
    ]);
});

// --- StreamBudget, as the stream route uses it --------------------------------------------
// StreamBudgetTest covers the arithmetic and the slot bookkeeping on their own; what is pinned
// here is that this route actually applies them: the budget reaches the process time limit and
// the in-band deadline, the cap is claimed once the ticket has been checked and before it is
// spent, and the slot comes back.

it('hands prepareOutput a wall-clock budget derived from provider.timeout and the iteration budget, never 0', function (): void {
    // The default 60 s provider timeout, six agent iterations, and room for one nested tool
    // call (summarize) per iteration: 60 x 6 x 2 = 720. `set_time_limit(0)` was what made one
    // redeemed ticket able to hold a PHP worker with no end.
    $h = pipelineWith(pipelineProvider([new Response('a', ProviderFinishReason::Stop)]));
    actionRuns('alpaca_bot/chat/started');
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    $seconds = 0;
    // A default, so the failure when nothing is passed is the number and not an ArgumentCountError.
    $controller = new StreamController($h->pipeline, $h->store, function (int $limit = 0) use (&$seconds): void {
        $seconds = $limit;
    });
    $request = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    $controller->handle($request);
    ob_start();
    $controller->serve(false, new WP_REST_Response(), $request, new WP_REST_Server());
    ob_get_clean();
    expect($seconds)->toBe(720)
        ->and($seconds)->toBe(60 * Assistant::MAX_ITERATIONS * 2);

    // It follows the setting: a site that fails a provider call after 5 s gives a turn 60 s.
    $h = pipelineWith(pipelineProvider([new Response('a', ProviderFinishReason::Stop)]), ['provider.timeout' => 5]);
    actionRuns('alpaca_bot/chat/started');
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    $seconds = 0;
    $controller = new StreamController($h->pipeline, $h->store, function (int $limit = 0) use (&$seconds): void {
        $seconds = $limit;
    });
    $request = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    $controller->handle($request);
    ob_start();
    $controller->serve(false, new WP_REST_Response(), $request, new WP_REST_Server());
    ob_get_clean();
    expect($seconds)->toBe(60);
});

it('ends a turn that has outrun its budget with a stream_timeout frame, keeping what had arrived', function (): void {
    // The deadline is asked where the abort check is asked — just after a frame went out — and
    // lands where an abandoned turn lands: generator undrained, partial reply stored,
    // chat/failed fired. The difference is that this one tells the client why it stopped.
    $h = pipelineWith(pipelineProvider([
        new Response('a', ProviderFinishReason::Stop),
        new Response('b', ProviderFinishReason::Stop),
    ]));
    actionRuns('alpaca_bot/chat/started');
    Actions\expectRemoved('alpaca_bot/chat/started')->once();
    Actions\expectDone('alpaca_bot/chat/failed')->once()->with(Mockery::type(\RuntimeException::class), Mockery::type(Conversation::class));
    $frames = [];
    (new StreamController($h->pipeline, $h->store))->stream(
        streamTicket(),
        function (string $f) use (&$frames): void {
            $frames[] = $f;
        },
        static fn(): bool => false,
        microtime(true) - 1,
    );
    expect(array_map(static fn(string $f): string => strtok($f, "\n"), $frames))->toBe(['event: start', 'event: delta', 'event: error']);
    $error = json_decode(substr($frames[2], strlen("event: error\ndata: ")), true);
    expect($error['code'])->toBe('alpaca_bot_stream_timeout')
        // 504, not 408: the request arrived whole and on time; the turn behind it ran out of time.
        ->and($error['data'])->toBe(['status' => 504, 'limit' => 720])
        ->and($h->meta[42]['ab_messages'][1])->toMatchArray(['role' => 'assistant', 'content' => 'a'])
        ->and($h->meta[42]['ab_messages'][1]['meta']->partial)->toBeTrue();
});

it('refuses a redemption past the concurrent-stream limit with a 429, and leaves that ticket unspent', function (): void {
    // The stream route is not rate limited, so without this one account's 30 tickets a minute
    // redeem into 30 PHP workers held for the length of a turn each. The refusal comes before
    // the ticket is spent: a caller told to wait must still have their turn.
    $h = pipelineWith(null);
    $table = slotRowsIn();
    transientsPersistIn($h);
    restConvertsErrors();
    foreach (range(1, StreamBudget::LIMIT + 1) as $n) {
        $h->transients[ChatController::STREAM_TRANSIENT . "tok{$n}"] = streamTicket();
    }
    $controller = new StreamController($h->pipeline, $h->store);
    foreach (range(1, StreamBudget::LIMIT) as $n) {
        $res = $controller->handle(restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => "tok{$n}"]));
        expect($res->get_status())->toBe(200, "redemption {$n}");
    }
    expect($table->rows)->toHaveCount(StreamBudget::LIMIT);

    $over = $controller->handle(restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok4']));
    expect($over)->toBeInstanceOf(WP_REST_Response::class)
        ->and($over->get_status())->toBe(429)
        ->and($over->get_data()['code'])->toBe('alpaca_bot_rate_limited')
        // The soonest of this person's leases lapses then, so waiting it out gets through.
        ->and($over->get_headers())->toBe(['Retry-After' => '720'])
        ->and($over->get_data()['data']['retry_after'])->toBe(720)
        // Unspent: the ticket is still there to redeem when a slot frees.
        ->and($h->transients)->toHaveKey(ChatController::STREAM_TRANSIENT . 'tok4')
        ->and($table->rows)->toHaveCount(StreamBudget::LIMIT);
});

it('gives the slot back when the stream ends, and when something else served the request instead', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('a', ProviderFinishReason::Stop)]));
    $table = slotRowsIn();
    actionRuns('alpaca_bot/chat/started');
    transientsPersistIn($h);
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    $controller = new StreamController($h->pipeline, $h->store, static function (int $seconds): void {});
    $request = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']);
    $controller->handle($request);
    expect($table->rows)->toHaveCount(1);
    ob_start();
    $controller->serve(false, new WP_REST_Response(), $request, new WP_REST_Server());
    ob_get_clean();
    // The row is deleted, not left holding a lease no stream is behind.
    expect($table->rows)->toBe([]);

    // Served by something hooked earlier: nothing streams, the ticket is spent anyway, and
    // holding the slot until its lease lapses would be a leak for no run.
    $h->transients[ChatController::STREAM_TRANSIENT . 'two'] = streamTicket();
    $request = restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'two']);
    $controller->handle($request);
    expect($table->rows)->toHaveCount(1)
        ->and($controller->serve(true, new WP_REST_Response(), $request, new WP_REST_Server()))->toBeTrue()
        ->and($table->rows)->toBe([]);
});
