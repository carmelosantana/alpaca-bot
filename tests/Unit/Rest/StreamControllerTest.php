<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Rest\ChatController;
use AlpacaBot\Rest\StreamController;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// restRequest(), pipelineWith(), pipelineProvider(), actionRuns() and streamTicket() live in
// tests/Pest.php.
// Pipeline is final, so stream() runs over the real one with WordPress and the provider stubbed,
// as ChatControllerTest does. Two things are pinned: handle() redeems a ticket for its owner,
// once, and nothing else; stream() turns what the pipeline yields, returns or throws into
// frames, with the same error policy as the JSON route. serve() (the rest_pre_serve_request
// side) ends every output buffer, this runner's included, so only the real check covers it.
// The conversation post the harness serves is 42, owned by user 3; the current user is 3.

beforeEach(function (): void {
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('current_user_can')->justReturn(false);
});

it('declares one GET /chat/{id}/stream route for editors, token required, not rate limited', function (): void {
    $routes = (new StreamController(pipelineWith(null)->pipeline))->routes();
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
    $controller = new StreamController(pipelineWith(null)->pipeline);
    // Last, after core's own handlers and anything else that may want to serve the request or
    // still send a header: once the first frame is out, a header() call would only warn.
    Filters\expectAdded('rest_pre_serve_request')->once()->with([$controller, 'serve'], PHP_INT_MAX, 4);
    $controller->register();
});

it('refuses a token that is missing, unknown, another user\'s, or for another conversation, and leaves the ticket alone', function (): void {
    $h = pipelineWith(null);
    $h->transients[ChatController::STREAM_TRANSIENT . 'theirs'] = streamTicket(['user_id' => 9]);
    $h->transients[ChatController::STREAM_TRANSIENT . 'mine'] = streamTicket();
    $deleted = [];
    Functions\when('delete_transient')->alias(static function (string $key) use (&$deleted): bool {
        $deleted[] = $key;
        return true;
    });
    $controller = new StreamController($h->pipeline);
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
    expect($deleted)->toBe([]);
});

it('redeems a valid ticket once, marking the response for serve() with the ticket as its data', function (): void {
    $h = pipelineWith(null);
    $h->transients[ChatController::STREAM_TRANSIENT . 'tok'] = streamTicket();
    $deleted = [];
    Functions\when('delete_transient')->alias(static function (string $key) use (&$deleted, $h): bool {
        $deleted[] = $key;
        unset($h->transients[$key]);
        return true;
    });
    $controller = new StreamController($h->pipeline);
    $res = $controller->handle(restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok']));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)
        ->and($res->get_status())->toBe(200)
        ->and($res->get_headers())->toBe([StreamController::MARKER => '1'])
        ->and($res->get_data())->toBe(streamTicket())
        // Deleted on redemption, before anything runs: the same token is now as good as none.
        ->and($deleted)->toBe([ChatController::STREAM_TRANSIENT . 'tok'])
        ->and($controller->handle(restRequest('GET', '/alpaca-bot/v1/chat/42/stream', ['id' => '42', 'token' => 'tok'])))->toBeInstanceOf(WP_Error::class);
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
    (new StreamController($h->pipeline))->stream(streamTicket(['conversation_id' => 0, 'options' => ['conversation_id' => 0, 'model' => 'qwen3:8b']]), function (string $f) use (&$frames): void {
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
        ->and($done['message'])->toMatchArray(['role' => 'assistant', 'content' => 'ab', 'model' => 'qwen3:8b', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2], 'meta' => ['reasoning' => 'thinking']])
        ->and($done['receipt'])->toMatchArray(['user_id' => 3, 'model' => 'qwen3:8b', 'total_tokens' => 7, 'conversation_id' => 42, 'log_id' => 9])
        ->and($done['contexts'])->toBe([])
        // The stored transcript is what was streamed.
        ->and($h->meta[42]['ab_messages'][1]['content'])->toBe('ab');
});

it('writes one error frame, in the JSON route\'s error shape, when the pipeline refuses the turn', function (): void {
    // Over the cap: 402's code and data, before any provider call and with no start frame.
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    Actions\expectDone('alpaca_bot/chat/started')->never();
    $frames = [];
    (new StreamController($h->pipeline))->stream(streamTicket(), function (string $f) use (&$frames): void {
        $frames[] = $f;
    });
    expect($frames)->toBe(["event: error\ndata: {\"code\":\"alpaca_bot_cap_exceeded\",\"message\":\"Your monthly token cap has been reached (12 of 10 tokens).\",\"data\":{\"status\":402,\"scope\":\"user\",\"limit\":10,\"used\":12}}\n\n"]);

    // A conversation that is not the caller's: the pipeline's own words, as a 400 would carry them.
    $h = pipelineWith(null);
    $h->post = conversationChatPost(42, '9');
    $frames = [];
    (new StreamController($h->pipeline))->stream(streamTicket(), function (string $f) use (&$frames): void {
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
    (new StreamController($h->pipeline))->stream(streamTicket(), function (string $f) use (&$frames): void {
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
    (new StreamController($h->pipeline))->stream(streamTicket(), function (string $f) use (&$frames): void {
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
