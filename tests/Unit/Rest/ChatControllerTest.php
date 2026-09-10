<?php

declare(strict_types=1);

use AlpacaBot\Context\Context;
use AlpacaBot\Rest\ChatController;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// restRequest(), pipelineWith() and pipelineProvider() live in tests/Pest.php. Pipeline is
// final, so the controller runs over the real one (with WordPress and the provider stubbed, as
// PipelineTest does) rather than a mock; PipelineTest covers what a turn does, this file covers
// how a request becomes a turn and a Result becomes a response. The conversation post the
// harness serves is 42, owned by user 3; the current user is 3.

beforeEach(function (): void {
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('rest_url')->alias(static fn(string $path): string => 'https://alpaca10.wp.test/wp-json/' . $path);
    // Core's add_query_arg on a URL with no query string yet: the one shape the ticket needs.
    Functions\when('add_query_arg')->alias(static fn(string $key, string $value, string $url): string => $url . '?' . $key . '=' . $value);
    Functions\when('wp_generate_password')->justReturn('tok');
});

it('declares one rate-limited POST /chat route for editors', function (): void {
    $routes = (new ChatController(pipelineWith(null)->pipeline))->routes();
    expect($routes)->toHaveCount(1)
        ->and($routes[0]['path'])->toBe('/chat')
        ->and($routes[0]['methods'])->toBe('POST')
        ->and($routes[0]['capability'])->toBe('edit_posts')
        ->and($routes[0]['rate_limit'])->toBeTrue()
        // Not required: an images-only turn has no message, and core would refuse the request
        // before the callback ran (rest_missing_callback_param) if the schema said otherwise.
        ->and($routes[0]['args']['message'])->toBe(['type' => 'string', 'required' => false, 'default' => ''])
        ->and($routes[0]['args']['images'])->toBe(['type' => 'array', 'items' => ['type' => 'string'], 'default' => []])
        ->and(array_keys($routes[0]['args']))->toBe(['message', 'conversation_id', 'model', 'images', 'context', 'stream']);
});

it('completes a chat and returns conversation, message, receipt and contexts', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('yo', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7))], $call), [], [new Context('c', 'Editing: Hello', 'Body', ['post_id' => 9])]);
    // The request's context block is what the collector is asked for, on behalf of the current user.
    Filters\expectApplied('alpaca_bot/context')->once()->with(Mockery::type('array'), 3, ['post_id' => 9])->andReturnFirstArg();
    $res = (new ChatController($h->pipeline))->create(restRequest('POST', '/alpaca-bot/v1/chat', ['message' => 'hi', 'context' => ['post_id' => 9]]));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)
        ->and($res->get_status())->toBe(200);
    $data = $res->get_data();
    expect($data['conversation_id'])->toBe(42)
        ->and($data['message']['role'])->toBe('assistant')
        ->and($data['message']['content'])->toBe('yo')
        ->and($data['message']['model'])->toBe('llama3.2')
        ->and($data['message']['usage'])->toBe(['prompt_tokens' => 5, 'completion_tokens' => 2])
        ->and($data['receipt'])->toMatchArray(['user_id' => 3, 'model' => 'llama3.2', 'total_tokens' => 7, 'conversation_id' => 42, 'log_id' => 9])
        ->and($data['contexts'])->toBe([['id' => 'c', 'label' => 'Editing: Hello', 'text' => 'Body', 'meta' => ['post_id' => 9]]])
        ->and($call['messages'][0]->content())->toContain('## Editing: Hello');
});

it('hands the model, images and conversation to the pipeline, trimming the message and keeping only string images', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('a cat', ProviderFinishReason::Stop)], $call), [], [], ['llama3.2', 'qwen3:8b']);
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'Earlier', 'created' => 1], ['role' => 'assistant', 'content' => 'Before', 'created' => 2]]
        : '');
    $res = (new ChatController($h->pipeline))->create(restRequest('POST', '/alpaca-bot/v1/chat', [
        'message' => ' look ',
        'conversation_id' => '42',
        'model' => 'qwen3:8b',
        'images' => ['data:image/png;base64,AAAA', 42, null],
    ]));
    expect($res->get_status())->toBe(200)
        ->and($res->get_data()['conversation_id'])->toBe(42)
        ->and($res->get_data()['message']['model'])->toBe('qwen3:8b')
        ->and($h->model)->toBe('qwen3:8b')
        // The existing transcript was replayed, then the new turn with its one image.
        ->and($call['messages'])->toHaveCount(3)
        ->and($call['messages'][2]->content())->toBe([
            ['type' => 'text', 'text' => 'look'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
        ]);
});

it('returns a stream ticket when stream=true, without running the pipeline', function (): void {
    // The ticket is everything the stream route needs to run the turn as this user: Task 4 reads
    // it back by token, checks user_id against the caller, and deletes it. 120 s covers a client
    // that opens the stream right after the POST answers; it is not a session.
    $h = pipelineWith(null); // the provider filter must never fire
    $ticket = null;
    Functions\when('set_transient')->alias(static function (string $key, mixed $value, int $ttl) use (&$ticket): bool {
        $ticket = [$key, $value, $ttl];
        return true;
    });
    $res = (new ChatController($h->pipeline))->create(restRequest('POST', '/alpaca-bot/v1/chat', ['message' => 'hi', 'stream' => true, 'conversation_id' => 5, 'context' => ['screen' => 'post']]));
    expect($res->get_status())->toBe(202)
        ->and($res->get_data())->toBe(['conversation_id' => 5, 'token' => 'tok', 'stream_url' => 'https://alpaca10.wp.test/wp-json/alpaca-bot/v1/chat/5/stream?token=tok'])
        ->and($ticket)->toBe(['alpaca_bot_stream_tok', [
            'user_id' => 3,
            'conversation_id' => 5,
            'message' => 'hi',
            'options' => ['conversation_id' => 5, 'model' => '', 'images' => [], 'context' => ['screen' => 'post']],
        ], 120])
        ->and($h->writes)->toBe([]); // nothing was created: the stream route makes the turn
});

it('issues a ticket for a new conversation as conversation 0: the stream route creates it', function (): void {
    $h = pipelineWith(null);
    $res = (new ChatController($h->pipeline))->create(restRequest('POST', '/alpaca-bot/v1/chat', ['message' => 'hi', 'stream' => true]));
    expect($res->get_status())->toBe(202)
        ->and($res->get_data()['conversation_id'])->toBe(0)
        ->and($res->get_data()['stream_url'])->toBe('https://alpaca10.wp.test/wp-json/alpaca-bot/v1/chat/0/stream?token=tok');
});

it('maps CapExceeded to 402 and rejects an empty message with 400 before the pipeline runs', function (): void {
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    $controller = new ChatController($h->pipeline);
    $err = $controller->create(restRequest('POST', '/alpaca-bot/v1/chat', ['message' => 'hi']));
    expect($err)->toBeInstanceOf(WP_Error::class)
        ->and($err->get_error_code())->toBe('alpaca_bot_cap_exceeded')
        ->and($err->get_error_data())->toBe(['status' => 402, 'scope' => 'user', 'limit' => 10, 'used' => 12]);

    // Refused by the controller before the pipeline runs: nothing is written.
    $bad = $controller->create(restRequest('POST', '/alpaca-bot/v1/chat', ['message' => '   ']));
    expect($bad)->toBeInstanceOf(WP_Error::class)
        ->and($bad->get_error_code())->toBe('alpaca_bot_bad_request')
        ->and($bad->get_error_data()['status'])->toBe(400)
        ->and($h->writes)->toBe([]);
});

it('accepts an images-only turn with no message parameter at all, as the pipeline does', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('a cat', ProviderFinishReason::Stop)], $call));
    $res = (new ChatController($h->pipeline))->create(restRequest('POST', '/alpaca-bot/v1/chat', ['images' => ['data:image/png;base64,AAAA']]));
    expect($res->get_status())->toBe(200)
        ->and($call['messages'][0]->content())->toBe([['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']]]);
});

it('maps the pipeline\'s InvalidArgumentException to 400 and a provider failure to a 502', function (): void {
    // A conversation that is not the caller's, an image that is not a data URL, a model the
    // catalog does not list: the pipeline refuses these as client errors and says why in words
    // meant for the caller, so they must not surface as the provider's fault.
    $h = pipelineWith(null);
    $h->post = conversationChatPost(42, '9');
    $bad = (new ChatController($h->pipeline))->create(restRequest('POST', '/alpaca-bot/v1/chat', ['message' => 'hi', 'conversation_id' => 42]));
    expect($bad)->toBeInstanceOf(WP_Error::class)
        ->and($bad->get_error_code())->toBe('alpaca_bot_bad_request')
        ->and($bad->get_error_message())->toBe('Conversation 42 was not found.')
        ->and($bad->get_error_data())->toBe(['status' => 400]);

    // The provider's words (which quote its URL) stay out of the 502 for an editor...
    Functions\when('current_user_can')->justReturn(false);
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]));
    $down = (new ChatController($h->pipeline))->create(restRequest('POST', '/alpaca-bot/v1/chat', ['message' => 'hi']));
    expect($down)->toBeInstanceOf(WP_Error::class)
        ->and($down->get_error_code())->toBe('alpaca_bot_provider_error')
        ->and($down->get_error_message())->toBe('The model provider could not complete the request.')
        ->and($down->get_error_data())->toBe(['status' => 502]);

    // ... and reach an administrator as data.detail, wrapped as the pipeline wraps them.
    Functions\when('current_user_can')->justReturn(true);
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]));
    $admin = (new ChatController($h->pipeline))->create(restRequest('POST', '/alpaca-bot/v1/chat', ['message' => 'hi']));
    expect($admin->get_error_message())->toBe('The model provider could not complete the request.')
        ->and($admin->get_error_data())->toBe(['status' => 502, 'detail' => 'Provider error: connection refused']);
});
