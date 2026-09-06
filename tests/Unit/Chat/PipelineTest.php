<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Delta;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\Result;
use AlpacaBot\Context\Context;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\AssistantMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// pipelineWith() and pipelineProvider() live in tests/Pest.php. Every collaborator is the real
// class; only WordPress and the provider are stubbed. Brain Monkey 2.7: once()/never() go before
// with(), or two expectations on one hook collapse into one.
//
// The vendored stream() yields text deltas and reasoning deltas that both carry finishReason
// Stop, so Stop is not an end-of-stream marker; usage arrives either on the final stop chunk or
// in a separate usage-only chunk (empty content) after it. Both shapes are exercised below.

it('streams deltas, persists both messages, records usage, and returns a Result', function (): void {
    $provider = pipelineProvider([
        new Response('Hel', ProviderFinishReason::Stop),
        new Response('lo', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)), // usage-only chunk
    ], $call);
    $h = pipelineWith($provider, ['chat.system_prompt' => 'Be brief']);
    Filters\expectApplied('alpaca_bot/message/before_send')->once()->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/message/after_receive')->once()->andReturnFirstArg();
    Actions\expectDone('alpaca_bot/chat/completed')->once()->with(Mockery::type(Result::class));
    $receipt = null;
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->whenHappen(function (array $r) use (&$receipt): void {
        $receipt = $r;
    });

    $gen = $h->pipeline->send(3, 'Hi there');
    $deltas = [];
    foreach ($gen as $d) {
        $deltas[] = $d;
    }
    $result = $gen->getReturn();

    expect($deltas)->toHaveCount(2)
        ->and($deltas[0])->toBeInstanceOf(Delta::class)
        ->and($deltas[0]->text)->toBe('Hel')
        ->and($deltas[1]->text)->toBe('lo')
        ->and($result)->toBeInstanceOf(Result::class)
        ->and($result->reply->content)->toBe('Hello')
        ->and($result->reply->role)->toBe('assistant')
        ->and($result->reply->model)->toBe('llama3.2')
        ->and($result->reply->usage)->toBe(['prompt_tokens' => 5, 'completion_tokens' => 2])
        ->and($result->reply->meta)->toBe([])
        ->and($result->conversation->id)->toBe(42)
        ->and($result->conversation->messages)->toHaveCount(2)
        ->and($result->conversation->messages[0]->content)->toBe('Hi there')
        ->and($result->conversation->messages[0]->role)->toBe('user')
        ->and($result->conversation->messages[0]->model)->toBe('llama3.2')
        ->and($result->conversation->messages[1])->toBe($result->reply)
        ->and($result->contexts)->toBe([])
        ->and($result->receipt)->toBe([
            'user_id' => 3,
            'model' => 'llama3.2',
            'prompt_tokens' => 5,
            'completion_tokens' => 2,
            'total_tokens' => 7,
            'duration_ms' => $result->receipt['duration_ms'],
            'conversation_id' => 42,
            'log_id' => 9,
            'created' => 1_725_000_000,
        ])
        ->and($result->receipt['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($receipt)->toBe($result->receipt);

    // What the provider was asked.
    expect($h->model)->toBe('llama3.2')
        ->and($call['messages'])->toHaveCount(2)
        ->and($call['messages'][0])->toBeInstanceOf(SystemMessage::class)
        ->and($call['messages'][0]->content())->toBe('Be brief')
        ->and($call['messages'][1])->toBeInstanceOf(UserMessage::class)
        ->and($call['messages'][1]->content())->toBe('Hi there')
        ->and($call['tools'])->toBe([])
        ->and($call['options'])->toBe(['temperature' => 0.7, 'num_ctx' => 8192, 'keep_alive' => '5m']);

    // What landed: the conversation post, its transcript, then the usage receipt.
    expect(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([
        ['wp_insert_post', 'chat_history'],
        ['update_post_meta', 'ab_messages'],
        ['wp_update_post', 42],
        ['wp_insert_post', 'chat_log'],
    ]);
    $transcript = $h->writes[1][2];
    expect($transcript[0]['role'])->toBe('user')
        ->and($transcript[1]['role'])->toBe('assistant')
        ->and($transcript[1]['content'])->toBe('Hello')
        ->and($h->writes[3][2]['post_author'])->toBe(3)
        ->and($h->writes[3][2]['meta_input']['total_tokens'])->toBe(7)
        ->and($h->writes[3][2]['meta_input']['conversation_id'])->toBe(42);
});

it('takes usage from the stop chunk when it arrives there and does not yield the empty chunk', function (): void {
    $provider = pipelineProvider([
        new Response('ok', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop, usage: new Usage(11, 4, 15)), // stop chunk carrying usage
    ]);
    $h = pipelineWith($provider);
    $gen = $h->pipeline->send(3, 'x');
    $deltas = iterator_to_array($gen, false);
    expect($deltas)->toHaveCount(1)
        ->and($gen->getReturn()->reply->usage)->toBe(['prompt_tokens' => 11, 'completion_tokens' => 4])
        ->and($gen->getReturn()->receipt['total_tokens'])->toBe(15);
});

it('injects context as a system block, hands the collector the authenticated user id, and honors per-model overrides', function (): void {
    $provider = pipelineProvider([new Response('ok', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith(
        $provider,
        ['chat.system_prompt' => 'Be brief', 'models.overrides' => ['llama3.2' => ['temperature' => 0.1, 'num_ctx' => 32768]]],
        [new Context('c', 'Editing: Hello', 'Body')],
    );
    // The request may say anything about who is asking; the collector is told who really is.
    $request = ['post_id' => 12, 'user_id' => 99];
    Filters\expectApplied('alpaca_bot/context/sources')->once()->with(Mockery::type('array'), 3, $request)->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/context')->once()->with(Mockery::type('array'), 3, $request)->andReturnFirstArg();

    $r = $h->pipeline->complete(3, 'Summarize', ['context' => $request]);

    expect($r->contexts)->toHaveCount(1)
        ->and($r->contexts[0]->id)->toBe('c')
        ->and($r->reply->content)->toBe('ok')
        ->and($call['messages'][0]->content())->toBe("Be brief\n\nContext:\n## Editing: Hello\nBody")
        ->and($call['options'])->toBe(['temperature' => 0.1, 'num_ctx' => 32768, 'keep_alive' => '5m']);
});

it('fires the hooks in the pinned order with the pinned arguments', function (): void {
    $provider = pipelineProvider([
        new Response('a', ProviderFinishReason::Stop),
        new Response('b', ProviderFinishReason::Stop),
    ]);
    $h = pipelineWith($provider, ['chat.system_prompt' => 'Base']);
    $order = [];
    $conversation = null;
    Filters\expectApplied('alpaca_bot/message/before_send')->once()->andReturnUsing(function (string $text, Conversation $c, array $options) use (&$order, &$conversation): string {
        $order[] = 'before_send';
        $conversation = $c;
        expect($text)->toBe('Hi')->and($options)->toBe(['context' => ['k' => 'v']])->and($c->messages)->toBe([]);
        return 'Hi (rewritten)';
    });
    // The user turn is already on the conversation, so a filter can tailor the prompt to it.
    Filters\expectApplied('alpaca_bot/system_prompt')->once()->andReturnUsing(function (string $system, Conversation $c, string $model) use (&$order, &$conversation): string {
        $order[] = 'system_prompt';
        expect($system)->toBe('Base')->and($model)->toBe('llama3.2')->and($c)->toBe($conversation)
            ->and($c->messages)->toHaveCount(1)->and($c->messages[0]->content)->toBe('Hi (rewritten)');
        return $system . ' (filtered)';
    });
    Actions\expectDone('alpaca_bot/chat/started')->once()->whenHappen(function (Conversation $c, string $model) use (&$order, &$conversation): void {
        $order[] = 'started';
        expect($c)->toBe($conversation)->and($model)->toBe('llama3.2')->and($c->messages)->toHaveCount(1);
    });
    Filters\expectApplied('alpaca_bot/message/after_receive')->once()->andReturnUsing(function (Message $reply, Conversation $c) use (&$order, &$conversation): Message {
        $order[] = 'after_receive';
        expect($reply->content)->toBe('ab')->and($c)->toBe($conversation)->and($c->messages)->toHaveCount(1);
        return new Message('assistant', 'ab (edited)', $reply->model, $reply->usage);
    });
    Actions\expectDone('alpaca_bot/chat/completed')->once()->whenHappen(function (Result $r) use (&$order, &$conversation): void {
        $order[] = 'completed';
        expect($r->conversation)->toBe($conversation)->and($r->reply->content)->toBe('ab (edited)');
    });
    Actions\expectDone('alpaca_bot/chat/failed')->never();

    $gen = $h->pipeline->send(3, 'Hi', ['context' => ['k' => 'v']]);
    foreach ($gen as $delta) {
        $order[] = 'delta:' . $delta->text;
    }
    $result = $gen->getReturn();

    expect($order)->toBe(['before_send', 'system_prompt', 'started', 'delta:a', 'delta:b', 'after_receive', 'completed'])
        ->and($result->conversation->messages[0]->content)->toBe('Hi (rewritten)')
        ->and($result->reply->content)->toBe('ab (edited)')
        ->and($result->conversation->messages[1])->toBe($result->reply);
    // The filtered system prompt is what the model saw, and the edited reply is what was stored.
    expect($h->writes[1][2][1]['content'])->toBe('ab (edited)');
});

it('does not build the provider or touch storage when the cap is exceeded', function (): void {
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    Actions\expectDone('alpaca_bot/chat/started')->never();
    Actions\expectDone('alpaca_bot/chat/failed')->never();
    expect(fn() => $h->pipeline->complete(3, 'x'))->toThrow(CapExceeded::class)
        ->and($h->writes)->toBe([]);
});

it('fires chat/failed with the original exception, then wraps it as a provider error, persisting nothing', function (): void {
    $boom = new \RuntimeException('connection refused');
    $provider = pipelineProvider([new Response('par', ProviderFinishReason::Stop), $boom]);
    $h = pipelineWith($provider);
    $failed = [];
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = [$e, $c];
    });
    Filters\expectApplied('alpaca_bot/message/after_receive')->never();
    Actions\expectDone('alpaca_bot/chat/completed')->never();
    Actions\expectDone('alpaca_bot/usage/recorded')->never();

    $gen = $h->pipeline->send(3, 'Hi');
    $deltas = [];
    $caught = null;
    try {
        foreach ($gen as $d) {
            $deltas[] = $d->text;
        }
    } catch (\RuntimeException $e) {
        $caught = $e;
    }

    expect($deltas)->toBe(['par'])
        ->and($caught)->not->toBeNull()
        ->and($caught)->not->toBe($boom)
        ->and($caught->getMessage())->toBe('Provider error: connection refused')
        ->and($caught->getPrevious())->toBe($boom)
        ->and($failed[0])->toBe($boom)
        ->and($failed[1])->toBeInstanceOf(Conversation::class)
        ->and($failed[1]->id)->toBe(42)
        ->and($failed[1]->messages)->toHaveCount(1)
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post']); // the empty conversation post only
});

it('persists the partial reply, records a receipt, and fires chat/failed when the consumer abandons the stream', function (): void {
    $provider = pipelineProvider([
        new Response('par', ProviderFinishReason::Stop),
        new Response('tial', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)),
    ]);
    $h = pipelineWith($provider);
    $failed = [];
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = [$e, $c];
    });
    $receipt = null;
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->whenHappen(function (array $r) use (&$receipt): void {
        $receipt = $r;
    });
    Filters\expectApplied('alpaca_bot/message/after_receive')->never();
    Actions\expectDone('alpaca_bot/chat/completed')->never();

    $gen = $h->pipeline->send(3, 'Hi');
    $first = $gen->current();
    expect($first->text)->toBe('par')->and($h->writes)->toHaveCount(1); // the conversation post only, so far
    unset($gen); // the consumer walks away: PHP runs the generator's finally on destruction

    expect($failed[0])->toBeInstanceOf(\RuntimeException::class)
        ->and($failed[0]->getMessage())->toContain('abandoned')
        ->and($failed[1]->id)->toBe(42)
        ->and($failed[1]->messages)->toHaveCount(2)
        ->and($failed[1]->messages[1]->role)->toBe('assistant')
        ->and($failed[1]->messages[1]->content)->toBe('par')
        ->and($failed[1]->messages[1]->meta)->toBe(['partial' => true])
        // Usage never arrived: the receipt is honest about it, and the clock is the consumer's.
        ->and($receipt['total_tokens'])->toBe(0)
        ->and($receipt['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($receipt['conversation_id'])->toBe(42)
        ->and($receipt['log_id'])->toBe(9)
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'update_post_meta', 'wp_update_post', 'wp_insert_post']);
    expect($h->writes[1][2][1]['content'])->toBe('par')
        ->and($h->writes[1][2][1]['meta'])->toBe(['partial' => true])
        ->and($h->writes[3][2]['meta_input']['total_tokens'])->toBe(0);
});

it('wraps a factory failure the same way, before anything is streamed', function (): void {
    // The provider filter is the seam a broken third-party plugin would break through.
    $h = pipelineWith('not a provider');
    Actions\expectDone('alpaca_bot/chat/failed')->once()->with(Mockery::type(\UnexpectedValueException::class), Mockery::type(Conversation::class));
    expect(fn() => $h->pipeline->complete(3, 'Hi'))->toThrow(\RuntimeException::class, 'Provider error: alpaca_bot/provider must return a');
});

it('persists the conversation and records usage for a caller that only iterates', function (): void {
    $provider = pipelineProvider([new Response('ok', ProviderFinishReason::Stop, usage: new Usage(1, 1, 2))]);
    $h = pipelineWith($provider);
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(fn(array $r): bool => $r['log_id'] === 9 && $r['total_tokens'] === 2);
    Actions\expectDone('alpaca_bot/chat/completed')->once();
    foreach ($h->pipeline->send(3, 'Hi') as $_) {
    }
    expect(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'update_post_meta', 'wp_update_post', 'wp_insert_post']);
});

it('complete() drains the stream and returns the Result', function (): void {
    $provider = pipelineProvider([new Response('a', ProviderFinishReason::Stop), new Response('b', ProviderFinishReason::Stop)]);
    $h = pipelineWith($provider);
    $r = $h->pipeline->complete(3, 'Hi');
    expect($r)->toBeInstanceOf(Result::class)->and($r->reply->content)->toBe('ab');
});

it('yields reasoning deltas, stores the reasoning on the reply, and copes with a stream that ends without usage', function (): void {
    $provider = pipelineProvider([
        new Response('', ProviderFinishReason::Stop, reasoning: 'thinking'),
        new Response(' hard', ProviderFinishReason::Stop, reasoning: '…'),
        new Response('Answer', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop), // stop, no usage anywhere
    ]);
    $h = pipelineWith($provider);
    $gen = $h->pipeline->send(3, 'Hi');
    $deltas = iterator_to_array($gen, false);
    $r = $gen->getReturn();
    expect(array_map(static fn(Delta $d): array => [$d->text, $d->reasoning], $deltas))->toBe([['', 'thinking'], [' hard', '…'], ['Answer', '']])
        ->and($r->reply->content)->toBe(' hardAnswer')
        ->and($r->reply->meta)->toBe(['reasoning' => 'thinking…'])
        ->and($r->reply->usage)->toBe(['prompt_tokens' => 0, 'completion_tokens' => 0])
        ->and($r->receipt['total_tokens'])->toBe(0)
        ->and($h->writes[3][2]['meta_input']['total_tokens'])->toBe(0);
});

it('records an empty reply when the provider yields nothing', function (): void {
    $provider = pipelineProvider([]);
    $h = pipelineWith($provider);
    Actions\expectDone('alpaca_bot/chat/completed')->once();
    $gen = $h->pipeline->send(3, 'Hi');
    expect(iterator_to_array($gen, false))->toBe([])
        ->and($gen->getReturn()->reply->content)->toBe('')
        ->and($gen->getReturn()->conversation->messages)->toHaveCount(2)
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'update_post_meta', 'wp_update_post', 'wp_insert_post']);
});

it('sends the requested model when users may change it, and the default when they may not', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), [], [], ['llama3.2', 'qwen3:8b']);
    $r = $h->pipeline->complete(3, 'Hi', ['model' => 'qwen3:8b']);
    expect($h->model)->toBe('qwen3:8b')->and($r->reply->model)->toBe('qwen3:8b')->and($r->conversation->messages[0]->model)->toBe('qwen3:8b');

    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), ['chat.user_can_change_model' => false], [], ['llama3.2', 'qwen3:8b']);
    $r = $h->pipeline->complete(3, 'Hi', ['model' => 'qwen3:8b']);
    expect($h->model)->toBe('llama3.2')->and($r->reply->model)->toBe('llama3.2');
});

it('refuses a requested model the catalog does not list rather than substituting the default', function (): void {
    // The catalog is the allow-list (alpaca_bot/models, no embedding models); naming a model
    // directly must not walk past it, and quietly answering with another model would be worse.
    $h = pipelineWith(null, [], [], ['llama3.2']);
    Actions\expectDone('alpaca_bot/chat/started')->never();
    Actions\expectDone('alpaca_bot/chat/failed')->never();
    expect(fn() => $h->pipeline->complete(3, 'Hi', ['model' => 'nomic-embed-text']))
        ->toThrow(\InvalidArgumentException::class, 'nomic-embed-text')
        ->and($h->writes)->toBe([]); // the model is settled before a conversation post is made
});

it('lets a requested model through when the catalog is unreachable, so the outage surfaces as a provider error', function (): void {
    // The catalog cannot tell "not listed" from "could not be listed": ModelCatalog swallows a
    // downed provider and returns an empty, uncached list. Refusing the model then would report
    // an outage as a client error naming their model, with no chat/failed. An empty catalog is
    // not an allow-list of nothing: the turn goes to the provider, which fails loudly, exactly
    // as the default-model path does in the same outage.
    $down = new \RuntimeException('connection refused');
    $provider = pipelineProvider([$down]);
    $provider->shouldReceive('models')->once()->andThrow($down);
    $h = pipelineWith($provider, [], [], null);
    $failed = null;
    Actions\expectDone('alpaca_bot/chat/started')->once();
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = $e;
    });
    Actions\expectDone('alpaca_bot/chat/completed')->never();

    // The requested id differs from the harness's models.default ('llama3.2') on purpose: were
    // the pipeline to fall through to the default on an empty catalog, this would see it.
    expect(fn() => $h->pipeline->complete(3, 'Hi', ['model' => 'qwen3:8b']))
        ->toThrow(\RuntimeException::class, 'Provider error: connection refused')
        ->and($failed)->toBe($down)
        ->and($h->model)->toBe('qwen3:8b');
});

it('falls back to the first catalogued model when models.default is unset', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), ['models.default' => ''], [], ['gemma3:4b']);
    $h->pipeline->complete(3, 'Hi');
    expect($h->model)->toBe('gemma3:4b');
});

it('refuses to run with no model configured and none listed', function (): void {
    $h = pipelineWith(null, ['models.default' => ''], [], []);
    Actions\expectDone('alpaca_bot/chat/started')->never();
    Actions\expectDone('alpaca_bot/chat/failed')->never();
    expect(fn() => $h->pipeline->complete(3, 'Hi'))->toThrow(\RuntimeException::class, 'No model');
});

it('resolves the system prompt from the option, else the per-model override, else the setting', function (): void {
    $settings = ['chat.system_prompt' => 'Global', 'models.overrides' => ['llama3.2' => ['system' => 'Per model']]];

    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)], $call), $settings);
    $h->pipeline->complete(3, 'Hi', ['system' => 'From the caller']);
    expect($call['messages'][0]->content())->toBe('From the caller');

    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)], $call), $settings);
    $h->pipeline->complete(3, 'Hi');
    expect($call['messages'][0]->content())->toBe('Per model');

    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)], $call), ['chat.system_prompt' => 'Global']);
    $h->pipeline->complete(3, 'Hi');
    expect($call['messages'][0]->content())->toBe('Global');

    // No prompt from anywhere and no context: no system message at all.
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)], $call));
    $h->pipeline->complete(3, 'Hi');
    expect($call['messages'])->toHaveCount(1)->and($call['messages'][0])->toBeInstanceOf(UserMessage::class);
});

it('continues a conversation the user owns, replaying its history to the model', function (): void {
    $provider = pipelineProvider([new Response('Second answer', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith($provider);
    Functions\when('metadata_exists')->justReturn(false);
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'First question', 'created' => 1], ['role' => 'assistant', 'content' => 'First answer', 'created' => 2]]
        : '');

    $r = $h->pipeline->complete(3, 'Second question', ['conversation_id' => 42]);

    expect($r->conversation->id)->toBe(42)
        ->and($r->conversation->messages)->toHaveCount(4)
        ->and($r->conversation->messages[3]->content)->toBe('Second answer')
        ->and(array_map(static fn(object $m): array => [$m::class, $m->content()], $call['messages']))->toBe([
            [UserMessage::class, 'First question'],
            [AssistantMessage::class, 'First answer'],
            [UserMessage::class, 'Second question'],
        ])
        // No new post: the existing one is updated.
        ->and(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([['update_post_meta', 'ab_messages'], ['wp_update_post', 42], ['wp_insert_post', 'chat_log']]);
});

it('refuses a conversation id the user does not own rather than silently starting a new one', function (): void {
    $h = pipelineWith(null);
    $h->post = conversationChatPost(42, '9');
    Actions\expectDone('alpaca_bot/chat/started')->never();
    expect(fn() => $h->pipeline->complete(3, 'Hi', ['conversation_id' => 42]))->toThrow(\InvalidArgumentException::class)
        ->and($h->writes)->toBe([]);
});

it('sends images as image_url parts and keeps them on the stored user turn', function (): void {
    $provider = pipelineProvider([new Response('A cat', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith($provider);
    $r = $h->pipeline->complete(3, 'What is this?', ['images' => ['data:image/png;base64,AAAA']]);
    expect($call['messages'][0])->toBeInstanceOf(UserMessage::class)
        ->and($call['messages'][0]->content())->toBe([
            ['type' => 'text', 'text' => 'What is this?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
        ])
        ->and($r->conversation->messages[0]->images)->toBe(['data:image/png;base64,AAAA'])
        ->and($h->writes[1][2][0]['images'])->toBe(['data:image/png;base64,AAAA']);
});

it('sends an images-only turn without an empty text part', function (): void {
    $provider = pipelineProvider([new Response('A cat', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith($provider);
    $r = $h->pipeline->complete(3, '  ', ['images' => ['data:image/png;base64,AAAA']]);
    expect($call['messages'][0]->content())->toBe([
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
    ])->and($r->conversation->messages[0]->content)->toBe('');
});

it('refuses an image that is not a data URL before checking caps or touching storage', function (): void {
    // A URL would be fetched by the model host (SSRF from the provider) and rendered later by
    // a history screen; the pipeline accepts inline data only, so every consumer is covered.
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    Filters\expectApplied('alpaca_bot/cap/allowed')->never();
    Actions\expectDone('alpaca_bot/chat/started')->never();
    expect(fn() => $h->pipeline->complete(3, 'What is this?', ['images' => ['data:image/png;base64,AAAA', 'http://169.254.169.254/latest/meta-data']]))
        ->toThrow(\InvalidArgumentException::class)
        ->and($h->writes)->toBe([]);
});

it('refuses the turn when before_send blanks the message', function (): void {
    $h = pipelineWith(null);
    Filters\expectApplied('alpaca_bot/message/before_send')->once()->andReturn('   ');
    Filters\expectApplied('alpaca_bot/system_prompt')->never();
    Actions\expectDone('alpaca_bot/chat/started')->never();
    expect(fn() => $h->pipeline->complete(3, 'Hi'))->toThrow(\InvalidArgumentException::class);
});

it('buildMessages() fires system_prompt once and returns exactly what send() gives the provider', function (): void {
    $settings = ['chat.system_prompt' => 'Base'];
    $contexts = [new Context('c', 'Editing: Hello', 'Body')];
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)], $call), $settings, $contexts);
    Filters\expectApplied('alpaca_bot/system_prompt')->twice()->andReturnUsing(static fn(string $s): string => $s . ' (filtered)');
    $r = $h->pipeline->complete(3, 'Hi', ['system' => 'Override']);
    $sent = array_map(static fn(object $m): array => [$m::class, $m->content()], $call['messages']);

    // The same transcript (the user turn only, as it stood when send() built its messages).
    $c = new Conversation(42, 3, 'T', [$r->conversation->messages[0]]);
    $built = array_map(static fn(object $m): array => [$m::class, $m->content()], $h->pipeline->buildMessages($c, 'llama3.2', $contexts, 'Override'));

    expect($built)->toBe($sent)
        ->and($sent)->toBe([
            [SystemMessage::class, "Override (filtered)\n\nContext:\n## Editing: Hello\nBody"],
            [UserMessage::class, 'Hi'],
        ]);
});

it('rejects an empty message before checking caps or touching storage', function (): void {
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    Filters\expectApplied('alpaca_bot/cap/allowed')->never();
    expect(fn() => $h->pipeline->complete(3, "  \n "))->toThrow(\InvalidArgumentException::class)
        ->and($h->writes)->toBe([]);
});

it('runs nothing until the generator is first advanced', function (): void {
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    Filters\expectApplied('alpaca_bot/cap/allowed')->never();
    $gen = $h->pipeline->send(3, 'Hi');
    expect($gen)->toBeInstanceOf(\Generator::class)->and($h->writes)->toBe([]);
});
