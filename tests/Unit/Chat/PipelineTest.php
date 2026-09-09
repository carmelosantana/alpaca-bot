<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Delta;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\Result;
use AlpacaBot\Chat\UserPrefs;
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
        // The reply carries the turn's wall time, so a reloaded transcript can show the same
        // receipt a live turn does (MessageBubble reads meta.duration_ms).
        ->and($result->reply->meta)->toBe(['duration_ms' => $result->receipt['duration_ms']])
        ->and($result->reply->meta['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
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
        ->and($transcript[1]['meta'])->toEqual((object) ['duration_ms' => $result->receipt['duration_ms']])
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
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed, $h): void {
        // The listener runs before the empty post is taken back, so it can still look the conversation up.
        $failed = [$e, $c, array_map(static fn(array $w): string => $w[0], $h->writes)];
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
        ->and($failed[2])->toBe(['wp_insert_post']) // the empty conversation post only, still there for the listener
        // ... and gone once the turn has failed: no transcript was ever written, so nothing is lost.
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'wp_delete_post'])
        ->and($h->writes[1])->toBe(['wp_delete_post', 42, true]);
});

// A failed turn must not leave an empty conversation behind: P1's real-world run found that a
// provider timeout left a private "New chat" post with no messages and no usage row, which the
// history list then showed. The post is taken back only when this turn made it and it still
// holds no stored transcript; a conversation the caller named, or one something has since saved
// a turn onto, is never touched.
it('keeps the conversation the caller passed in when the provider fails', function (): void {
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]));
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'First question', 'created' => 1], ['role' => 'assistant', 'content' => 'First answer', 'created' => 2]]
        : '');
    Actions\expectDone('alpaca_bot/chat/failed')->once();
    expect(fn() => $h->pipeline->complete(3, 'Second question', ['conversation_id' => 42]))->toThrow(\RuntimeException::class, 'Provider error')
        ->and($h->writes)->toBe([]);
});

it('keeps a conversation the caller passed in even when nothing is stored on it yet', function (): void {
    // The guard is on who named the conversation, not on what it holds: a 0.4 row whose legacy
    // transcript is [] or a row a listener created is the caller's, and a failed turn must not
    // take it. pipelineWith() serves no transcript for 42 under either key, so deleteIfEmpty()
    // would find it empty; only the `$requested` check keeps it from running at all.
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]));
    Actions\expectDone('alpaca_bot/chat/failed')->once();
    expect(fn() => $h->pipeline->complete(3, 'Hi again', ['conversation_id' => 42]))->toThrow(\RuntimeException::class, 'Provider error')
        ->and($h->writes)->toBe([]);
});

it('still reports the provider failure when a chat/failed listener throws, keeping the listener\'s exception behind it', function (): void {
    // A caller maps what the pipeline throws by class (ChatController: InvalidArgumentException
    // is the user's mistake, 400), so a listener's exception must not replace the wrapper. It
    // is chained after the provider's, and the empty post is still taken back.
    $boom = new \RuntimeException('connection refused');
    $listener = new \InvalidArgumentException('listener bug');
    $h = pipelineWith(pipelineProvider([$boom]));
    Actions\expectDone('alpaca_bot/chat/failed')->once()->andThrow($listener);
    $caught = null;
    try {
        $h->pipeline->complete(3, 'Hi');
    } catch (\Throwable $e) {
        $caught = $e;
    }
    expect($caught)->toBeInstanceOf(\RuntimeException::class)
        ->and($caught->getMessage())->toBe('Provider error: connection refused')
        ->and($caught->getPrevious())->toBe($boom)
        ->and($boom->getPrevious())->toBe($listener)
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'wp_delete_post']);
});

it('keeps the conversation it created when a chat/failed listener has saved a turn onto it', function (): void {
    // A listener that stores the user's turn for a retry gets to keep it: the check is on what
    // is stored when the turn fails, not on who created the post.
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]));
    $store = new \AlpacaBot\Chat\ConversationStore($h->store);
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(static function (\Throwable $e, Conversation $c) use ($store): void {
        $store->save($c);
    });
    expect(fn() => $h->pipeline->complete(3, 'Hi'))->toThrow(\RuntimeException::class, 'Provider error')
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'update_post_meta', 'wp_update_post']);
});

it('takes back the conversation it created when the turn fails before the provider is reached', function (): void {
    // before_send blanking the message is refused after the post exists (the filter sees the
    // conversation), so it is the same empty post as a provider failure leaves.
    $h = pipelineWith(null);
    Filters\expectApplied('alpaca_bot/message/before_send')->once()->andReturn('   ');
    Actions\expectDone('alpaca_bot/chat/started')->never();
    Actions\expectDone('alpaca_bot/chat/failed')->never();
    expect(fn() => $h->pipeline->complete(3, 'Hi'))->toThrow(\InvalidArgumentException::class)
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'wp_delete_post']);
});

it('has nothing to take back when history saving is off and the provider fails', function (): void {
    // The harness records a wp_delete_post as a write, so an empty write list is the whole assertion.
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]), ['privacy.save_history' => false]);
    Actions\expectDone('alpaca_bot/chat/failed')->once();
    expect(fn() => $h->pipeline->complete(3, 'Hi'))->toThrow(\RuntimeException::class, 'Provider error')
        ->and($h->writes)->toBe([]);
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
        // Marked partial, and carrying the seconds spent: the stored reply has a non-null usage,
        // so MessageBubble shows a receipt for it on reload, and that receipt reads duration_ms.
        ->and($failed[1]->messages[1]->meta)->toBe(['partial' => true, 'duration_ms' => $receipt['duration_ms']])
        // Usage never arrived: the receipt is honest about it, and the clock is the consumer's.
        ->and($receipt['total_tokens'])->toBe(0)
        ->and($receipt['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($receipt['conversation_id'])->toBe(42)
        ->and($receipt['log_id'])->toBe(9)
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'update_post_meta', 'wp_update_post', 'wp_insert_post']);
    expect($h->writes[1][2][1]['content'])->toBe('par')
        ->and($h->writes[1][2][1]['meta'])->toEqual((object) ['partial' => true, 'duration_ms' => $receipt['duration_ms']])
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
        ->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms'], 'reasoning' => 'thinking…'])
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

it('runs on the user\'s stored default model when the request names none, and only while the site lets users choose', function (): void {
    // Kanboard #565: the model chosen in the header's select persists as user meta
    // (UserPrefs), and a turn that names no model runs on it. The catalog still applies.
    Functions\when('get_user_meta')->alias(static fn(int $id): string => $id === 3 ? 'qwen3:8b' : '');
    $prefs = new UserPrefs();

    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), [], [], ['llama3.2', 'qwen3:8b'], $prefs);
    $r = $h->pipeline->complete(3, 'Hi');
    expect($h->model)->toBe('qwen3:8b')->and($r->reply->model)->toBe('qwen3:8b');

    // A model named on the request still wins over the stored default.
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), [], [], ['llama3.2', 'qwen3:8b'], $prefs);
    $h->pipeline->complete(3, 'Hi', ['model' => 'llama3.2']);
    expect($h->model)->toBe('llama3.2');

    // Users may not change the model: the stored default is a user's choice and does not apply.
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), ['chat.user_can_change_model' => false], [], ['llama3.2', 'qwen3:8b'], $prefs);
    $h->pipeline->complete(3, 'Hi');
    expect($h->model)->toBe('llama3.2');

    // A stored default the catalog no longer lists: the site default, not a 400 on every turn.
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), [], [], ['llama3.2', 'gemma3:4b'], $prefs);
    $h->pipeline->complete(3, 'Hi');
    expect($h->model)->toBe('llama3.2');

    // Another user, nothing stored: the site default. Without preferences at all (the CLI), likewise.
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), [], [], ['llama3.2', 'qwen3:8b'], $prefs);
    $h->pipeline->complete(4, 'Hi');
    expect($h->model)->toBe('llama3.2');
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), [], [], ['llama3.2', 'qwen3:8b']);
    $h->pipeline->complete(3, 'Hi');
    expect($h->model)->toBe('llama3.2');
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

// Intake validation (Pipeline::images()) only covers turns sent after it existed. A
// `data:text/html,...` a pre-0.5 turn stored under `images` is still in the transcript, and
// replaying it would send it to the provider on every later turn while the bubble never shows
// it: the very defect #2976 described. So the replay path holds history to the same contract.
it('replays only the stored images that meet the image contract, so a pre-validation data URL is never sent again', function (): void {
    $provider = pipelineProvider([new Response('Second answer', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith($provider);
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [
            ['role' => 'user', 'content' => 'Look', 'created' => 1, 'images' => ['data:text/html,<script>alert(1)</script>', 'data:image/png;base64,AAAA', 'https://example.com/x.png']],
            ['role' => 'assistant', 'content' => 'A cat', 'created' => 2],
            ['role' => 'user', 'content' => 'And this?', 'created' => 3, 'images' => ['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=']],
            ['role' => 'assistant', 'content' => 'Nothing', 'created' => 4],
        ]
        : '');

    $r = $h->pipeline->complete(3, 'Third question', ['conversation_id' => 42]);

    // The valid image is sent as a part; the two invalid ones are not, and a turn left with none is sent as plain text.
    expect($call['messages'][0]->content())->toBe([
        ['type' => 'text', 'text' => 'Look'],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
    ])
        ->and($call['messages'][2]->content())->toBe('And this?')
        // The stored transcript is left as it was: replay filters, it does not rewrite history.
        ->and($r->conversation->messages[0]->images)->toHaveCount(3)
        ->and($r->conversation->messages[2]->images)->toHaveCount(1);
});

// chat.context_messages bounds what is *sent*, counting the new turn, and never what is stored:
// 0.4 sliced the stored transcript to chat_history_limit the same way (its documented 0 meant
// "send all"). Without a bound every turn re-sends the whole conversation, Ollama truncates
// server-side to num_ctx (dropping the oldest context silently) and the prompt tokens billed
// against the monthly cap grow to num_ctx on every request.
it('sends only the most recent chat.context_messages turns, keeps the system prompt, and stores the whole conversation', function (): void {
    $provider = pipelineProvider([new Response('A3', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith($provider, ['chat.context_messages' => 2, 'chat.system_prompt' => 'Be brief']);
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'Q1'], ['role' => 'assistant', 'content' => 'A1'], ['role' => 'user', 'content' => 'Q2'], ['role' => 'assistant', 'content' => 'A2']]
        : '');

    $r = $h->pipeline->complete(3, 'Q3', ['conversation_id' => 42]);

    expect(array_map(static fn(object $m): array => [$m::class, $m->content()], $call['messages']))->toBe([
        [SystemMessage::class, 'Be brief'],
        [AssistantMessage::class, 'A2'],
        [UserMessage::class, 'Q3'],
    ])
        ->and($r->conversation->messages)->toHaveCount(6)
        // The stored transcript (the ab_messages write) is unbounded.
        ->and(array_column($h->writes[0][2], 'content'))->toBe(['Q1', 'A1', 'Q2', 'A2', 'Q3', 'A3']);
});

it('sends the whole transcript when chat.context_messages is 0', function (): void {
    $provider = pipelineProvider([new Response('A3', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith($provider, ['chat.context_messages' => 0]);
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'Q1'], ['role' => 'assistant', 'content' => 'A1'], ['role' => 'user', 'content' => 'Q2'], ['role' => 'assistant', 'content' => 'A2']]
        : '');

    $h->pipeline->complete(3, 'Q3', ['conversation_id' => 42]);

    expect(array_map(static fn(object $m): string => $m->content(), $call['messages']))->toBe(['Q1', 'A1', 'Q2', 'A2', 'Q3']);
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

// ---- Task 0 (P3 carry-over): images are held to ImageData's contract and to the site's allowance

// Assets::maxImageBytes() is post_max_size less the 64 KiB body allowance, times 3/4: a
// post_max_size of 64 KiB + 400 gives a cap of exactly 300 decoded bytes, small enough to build
// by hand and exact to the byte. 400 base64 characters carry 300 bytes; "AA==" carries one.
// Called after pipelineWith(), whose own 8M stub would otherwise be the later when() and win.
function pipelineImageCap(): int
{
    Functions\when('wp_convert_hr_to_bytes')->justReturn(64 * 1024 + 400);
    Functions\when('number_format_i18n')->alias(static fn(float|int $n): string => (string) $n);
    return 300;
}

it('accepts a single image at exactly the site\'s allowance', function (): void {
    $provider = pipelineProvider([new Response('A cat', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith($provider);
    $cap = pipelineImageCap();
    $atCap = 'data:image/png;base64,' . str_repeat('A', intdiv($cap, 3) * 4);
    $r = $h->pipeline->complete(3, 'What is this?', ['images' => [$atCap]]);
    expect($call['messages'][0]->content()[1]['image_url']['url'])->toBe($atCap)
        ->and($r->conversation->messages[0]->images)->toBe([$atCap]);
});

it('refuses two images whose decoded total is one byte over the allowance, naming both figures, before caps or storage', function (): void {
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $cap = pipelineImageCap();
    $atCap = 'data:image/png;base64,' . str_repeat('A', intdiv($cap, 3) * 4);
    $oneByte = 'data:image/jpeg;base64,AA==';
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    Filters\expectApplied('alpaca_bot/cap/allowed')->never();
    Actions\expectDone('alpaca_bot/chat/started')->never();
    expect(fn() => $h->pipeline->complete(3, 'Compare these', ['images' => [$atCap, $oneByte]]))
        ->toThrow(\InvalidArgumentException::class, 'Those images total 301 bytes; this site takes up to 300 bytes per message. Attach fewer or smaller images.')
        ->and($h->writes)->toBe([]);
});

it('refuses every image form that is not a base64 image data URL, saying what is accepted', function (): void {
    $h = pipelineWith(null);
    pipelineImageCap();
    Actions\expectDone('alpaca_bot/chat/started')->never();
    foreach ([
        42,
        'data:text/html,x',
        'data:application/json;base64,e30=',
        'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
        'data:image/png,%89PNG',
        "data:image/png;base64,QUJD\n",
        'https://example.com/x.png',
        // Padded past what base64 ever writes: it would count for 2250 bytes while carrying 3000.
        'data:image/png;base64,' . str_repeat('A', 4000) . str_repeat('=', 3000),
    ] as $bad) {
        expect(fn() => $h->pipeline->complete(3, 'What is this?', ['images' => ['data:image/png;base64,AAAA', $bad]]))
            ->toThrow(\InvalidArgumentException::class, 'Images must be base64 PNG, JPEG, GIF, or WebP data URLs.');
    }
    expect($h->writes)->toBe([]);
});

it('skips the total check when the site reports no allowance (maxImageBytes() is 0)', function (): void {
    // post_max_size=0 is PHP's "no limit"; a post_max_size the body allowance alone exhausts
    // answers 0 as well. Either way there is no figure to hold the total to.
    $big = 'data:image/png;base64,' . str_repeat('A', 4000);
    $provider = pipelineProvider([new Response('Two cats', ProviderFinishReason::Stop)], $call);
    $h = pipelineWith($provider);
    Functions\when('wp_convert_hr_to_bytes')->justReturn(0);
    $r = $h->pipeline->complete(3, 'Compare these', ['images' => [$big, $big]]);
    expect($r->conversation->messages[0]->images)->toBe([$big, $big]);
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

// A turn nobody asked to keep. Toolkit\SummarizeToolkit runs the model on a piece of text from
// inside another turn, and that inner call must not appear in the user's history as a
// conversation of its own. `ephemeral` skips the conversation post and the transcript write
// while the usage receipt is still recorded: the tokens were spent and the caps count them.
// Saving is on here (the default), which is what tells ephemeral apart from save_history off.
it('keeps no conversation and writes no transcript for an ephemeral turn, but still records the receipt and fires completed', function (): void {
    $provider = pipelineProvider([
        new Response('Sum', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)),
    ], $call);
    $h = pipelineWith($provider);
    Actions\expectDone('alpaca_bot/chat/completed')->once()->with(Mockery::type(Result::class));
    $result = $h->pipeline->complete(3, 'Long text', ['ephemeral' => true, 'system' => 'Summarize.']);
    expect($result->reply->content)->toBe('Sum')
        ->and($result->conversation->id)->toBe(0)
        ->and($result->conversation->userId)->toBe(3)
        ->and($result->conversation->messages)->toHaveCount(2)
        ->and($result->receipt['conversation_id'])->toBe(0)
        ->and($result->receipt['log_id'])->toBe(9)
        ->and($call['messages'][0]->content())->toBe('Summarize.')
        ->and($call['messages'][1]->content())->toBe('Long text')
        // The receipt only: no chat_history post, no transcript meta, no title update.
        ->and(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([['wp_insert_post', 'chat_log']])
        ->and($h->writes[0][2]['meta_input']['conversation_id'])->toBe(0);
});

it('refuses ephemeral together with a conversation id, before the provider is called: a turn cannot both continue a conversation and leave no trace', function (): void {
    // null: no provider may be built at all, so the refusal has to come before the model is resolved.
    $h = pipelineWith(null);
    expect(fn() => $h->pipeline->complete(3, 'Hi', ['ephemeral' => true, 'conversation_id' => 42]))->toThrow(\InvalidArgumentException::class, 'ephemeral')
        ->and($h->writes)->toBe([]);
});

// The cap is the named hazard for this option: a turn that keeps no conversation still spends
// tokens, and the receipt it writes is what the meter counts, so an ephemeral path around
// assertAllowed() would be a way around the monthly cap.
it('refuses an ephemeral turn under the cap like any other, before the provider is built', function (): void {
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    Actions\expectDone('alpaca_bot/chat/started')->never();
    expect(fn() => $h->pipeline->complete(3, 'Long text', ['ephemeral' => true, 'system' => 'Summarize.']))->toThrow(CapExceeded::class)
        ->and($h->writes)->toBe([]);
});

it('leaves nothing behind when an ephemeral turn fails at the provider', function (): void {
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]));
    Actions\expectDone('alpaca_bot/chat/failed')->once();
    expect(fn() => $h->pipeline->complete(3, 'Hi', ['ephemeral' => true]))->toThrow(\RuntimeException::class, 'Provider error')
        ->and($h->writes)->toBe([]);
});

it('sends a caller\'s temperature option to the provider over the model\'s setting, and the setting when the caller has none', function (): void {
    // The [alpacabot temperature="…"] shortcode: the attribute is per turn, and the pipeline's
    // provider options came from the store alone. The option overrides only the temperature;
    // num_ctx and keep_alive stay the model's.
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop, usage: new Usage(1, 1, 2))], $call), ['models.overrides' => ['llama3.2' => ['temperature' => 0.1]]]);
    $h->pipeline->complete(3, 'Hi', ['temperature' => 0.9, 'ephemeral' => true]);
    expect($call['options'])->toBe(['temperature' => 0.9, 'num_ctx' => 8192, 'keep_alive' => '5m']);

    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop, usage: new Usage(1, 1, 2))], $call), ['models.overrides' => ['llama3.2' => ['temperature' => 0.1]]]);
    $h->pipeline->complete(3, 'Hi', ['ephemeral' => true]);
    expect($call['options'])->toBe(['temperature' => 0.1, 'num_ctx' => 8192, 'keep_alive' => '5m']);
});
