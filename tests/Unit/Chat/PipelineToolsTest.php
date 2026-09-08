<?php

declare(strict_types=1);

use AlpacaBot\Chat\AgentStreamObserver;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Delta;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\Result;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Exception\TerminationException;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\AssistantMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

// pipelineWith(), agentProvider(), echoToolkit() and registryWith() live in tests/Pest.php.
//
// A tool turn runs the vendored agent loop, which streams once per iteration: the mock
// provider here answers each stream() call with the next entry of its turn list. The model's
// tool call arrives as a chunk carrying `toolCalls`; the agent runs the tool, adds the result to
// its conversation, and streams again. What the pipeline must show is the same as a plain
// turn's contract with two additions: text reaches the consumer while the run is still going,
// and every tool call is on the stored reply.

/** A tool-capable model in the cached catalog, as ModelCatalog stores one Ollama's /api/show flagged. */
const TOOL_MODEL = [['id' => 'llama3.2', 'tools' => true]];

/** The options a plain turn sends (PipelineTest pins the same literal), for the parity assertions below. */
const PLAIN_OPTIONS = ['temperature' => 0.7, 'num_ctx' => 8192, 'keep_alive' => '5m'];

it('runs the agent loop when a toolkit is enabled and the model supports tools: text streams before the tool runs, the reply carries every call, and the meter sees the whole run', function (): void {
    $seen = [];
    $ranWith = null;
    $toolkit = echoToolkit('echo_tool', 'Use echo_tool to echo.', static function (array $a) use (&$seen, &$ranWith): ToolResult {
        // What the consumer had already received when the tool ran: the first iteration's
        // text, which the fiber let out before the loop went on to execute the call.
        $ranWith = array_map(static fn(Delta $d): array => [$d->text, $d->reasoning], $seen);
        return ToolResult::success('echo:' . $a['text']);
    });
    $provider = agentProvider([
        [
            new Response('', ProviderFinishReason::Stop, reasoning: 'thinking'),
            new Response('Let me check', ProviderFinishReason::Stop),
            new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'echo_tool', ['text' => 'ping'])], usage: new Usage(3, 1, 4)),
        ],
        [
            new Response('pong ', ProviderFinishReason::Stop),
            new Response('received', ProviderFinishReason::Stop),
            new Response('', ProviderFinishReason::Stop, usage: new Usage(4, 3, 7)),
        ],
    ], $calls);
    $h = pipelineWith($provider, ['chat.system_prompt' => 'Be brief'], [], TOOL_MODEL, null, registryWith(['echo' => $toolkit]));
    Filters\expectApplied('alpaca_bot/toolkits')->once()->with(Mockery::type('array'), 3)->andReturnFirstArg();
    Actions\expectDone('alpaca_bot/chat/completed')->once()->with(Mockery::type(Result::class));
    Actions\expectDone('alpaca_bot/chat/failed')->never();

    $gen = $h->pipeline->send(3, 'ping the tool');
    foreach ($gen as $d) {
        expect($d)->toBeInstanceOf(Delta::class);
        $seen[] = $d;
    }
    $r = $gen->getReturn();

    // Streamed in order, and the first iteration's text was out before the tool ran. The
    // second iteration's text is set off from the first by a paragraph break, since the model
    // sent them as two messages.
    expect(array_map(static fn(Delta $d): array => [$d->text, $d->reasoning], $seen))->toBe([['', 'thinking'], ['Let me check', ''], ["\n\npong ", ''], ['received', '']])
        ->and($ranWith)->toBe([['', 'thinking'], ['Let me check', '']]);

    $record = ['name' => 'echo_tool', 'arguments' => ['text' => 'ping'], 'result_excerpt' => 'echo:ping', 'ok' => true];
    expect($r->reply->content)->toBe("Let me check\n\npong received")
        ->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms'], 'reasoning' => 'thinking', 'tool_calls' => [$record]])
        // Output::$usage is the sum over the run (AbstractAgent::run() adds each iteration's
        // usage into one total): 3+4 prompt, 1+3 completion. Metering only the last call would
        // understate every tool turn against the monthly cap.
        ->and($r->reply->usage)->toBe(['prompt_tokens' => 7, 'completion_tokens' => 4])
        ->and($r->receipt['total_tokens'])->toBe(11)
        ->and($h->writes[3][2]['meta_input']['total_tokens'])->toBe(11);

    // Stored: the record rides on the assistant row's meta and survives the object round trip.
    $stored = $h->writes[1][2][1];
    expect($stored['meta'])->toEqual((object) ['duration_ms' => $r->receipt['duration_ms'], 'reasoning' => 'thinking', 'tool_calls' => [$record]])
        ->and(Message::fromArray(json_decode(json_encode($stored), true))->meta['tool_calls'])->toBe([$record]);

    // What the provider was asked, both times: the agent's system prompt carries the site
    // text and the toolkit's guidelines, the toolkit's tool and the agent's `done` are
    // advertised, and the site's generation options went with each call as they do on a plain
    // turn. The second call replays the tool exchange.
    expect($calls)->toHaveCount(2)
        ->and($calls[0]['messages'][0])->toBeInstanceOf(SystemMessage::class)
        ->and($calls[0]['messages'][0]->content())->toContain('Be brief')->toContain('Use echo_tool to echo.')
        ->and($calls[0]['messages'][1])->toBeInstanceOf(UserMessage::class)
        ->and($calls[0]['messages'][1]->content())->toBe('ping the tool')
        ->and(count($calls[0]['messages']))->toBe(2)
        ->and(array_map(static fn(object $t): string => $t->name(), $calls[0]['tools']))->toBe(['echo_tool', 'done'])
        ->and($calls[0]['options'])->toBe(PLAIN_OPTIONS)
        ->and($calls[1]['options'])->toBe(PLAIN_OPTIONS)
        ->and($calls[1]['messages'][2])->toBeInstanceOf(AssistantMessage::class)
        ->and($calls[1]['messages'][2]->toolCalls()[0]->name)->toBe('echo_tool')
        ->and($calls[1]['messages'][3])->toBeInstanceOf(ToolResultMessage::class)
        ->and($calls[1]['messages'][3]->content())->toBe('echo:ping');
});

it('records a tool that fails, and one the model names that does not exist, as not ok and lets the run go on to its answer', function (): void {
    $toolkit = echoToolkit('echo_tool', 'Use it.', static fn(array $a): ToolResult => ToolResult::error('nope: ' . $a['text']));
    $provider = agentProvider([
        [new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'echo_tool', ['text' => 'ping']), new ToolCall('c2', 'no_such_tool', ['x' => 1])])],
        [new Response('Neither worked.', ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))],
    ], $calls);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => $toolkit]));
    Actions\expectDone('alpaca_bot/chat/failed')->never();

    $r = $h->pipeline->complete(3, 'try both');

    expect($r->reply->content)->toBe('Neither worked.')
        ->and($r->reply->meta['tool_calls'])->toBe([
            ['name' => 'echo_tool', 'arguments' => ['text' => 'ping'], 'result_excerpt' => 'nope: ping', 'ok' => false],
            ['name' => 'no_such_tool', 'arguments' => ['x' => 1], 'result_excerpt' => 'Unknown tool: no_such_tool', 'ok' => false],
        ])
        ->and($calls)->toHaveCount(2)
        ->and($r->receipt['total_tokens'])->toBe(4);
});

it('yields the answer a model gives through the done tool, which it never streamed, after the run', function (): void {
    $provider = agentProvider([
        [new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'done', ['response' => 'Final answer'])], usage: new Usage(2, 1, 3))],
    ]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    $gen = $h->pipeline->send(3, 'answer');
    $deltas = [];
    foreach ($gen as $d) {
        $deltas[] = $d->text;
    }
    $r = $gen->getReturn();
    expect($deltas)->toBe(['Final answer'])
        ->and($r->reply->content)->toBe('Final answer')
        // `done` is the agent's own, not a toolkit tool: nothing to record.
        ->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms']])
        ->and($r->receipt['total_tokens'])->toBe(3);
});

it('ends a run that reaches its iteration budget with a translated line, keeping what streamed and every call made', function (): void {
    $turn = [new Response('step ', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c', 'echo_tool', ['text' => 'x'])], usage: new Usage(1, 1, 2))];
    $provider = agentProvider(array_fill(0, 6, $turn));
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));
    Actions\expectDone('alpaca_bot/chat/failed')->never();

    $r = $h->pipeline->complete(3, 'loop');

    expect($r->reply->content)->toStartWith('step ')
        ->and(substr_count($r->reply->content, 'step '))->toBe(6)
        ->and($r->reply->content)->toEndWith('The assistant ran out of tool steps before it finished.')
        ->and($r->reply->meta['tool_calls'])->toHaveCount(6)
        ->and($r->receipt['total_tokens'])->toBe(12);
});

it('fails the turn as a provider error, once, persisting nothing, when the provider fails before any tool ran', function (): void {
    $provider = agentProvider([[new Response('Let me', ProviderFinishReason::Stop), new \RuntimeException('connection refused')]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));
    $failed = null;
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = $e;
    });
    Actions\expectDone('alpaca_bot/chat/completed')->never();

    expect(fn() => $h->pipeline->complete(3, 'ping'))->toThrow(\RuntimeException::class, 'Provider error: connection refused');
    expect($failed)->toBeInstanceOf(\RuntimeException::class)
        ->and($failed->getMessage())->toBe('connection refused')
        // The agent swallows the throw and reports it as an Error finish; the pipeline turns
        // that back into the failure a plain turn raises, with the message once, not twice,
        // and, nothing having run, leaves what a failed plain turn leaves: no transcript, and
        // the post made for this turn taken back.
        ->and(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([['wp_insert_post', 'chat_history'], ['wp_delete_post', 42]]);
});

it('keeps the turn when the provider fails after a tool ran: the partial reply is stored with the calls made, the run billed, the failure raised', function (): void {
    $toolkit = echoToolkit('draft_post', 'Use it.', static fn(array $a): ToolResult => ToolResult::success('{"id": 225}'));
    $provider = agentProvider([
        [new Response('Drafting.', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'draft_post', ['text' => 'Hello'])], usage: new Usage(3, 1, 4))],
        [new \RuntimeException('connection refused')],
    ]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['draft' => $toolkit]));
    $failed = null;
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = [$e, $c];
    });
    Actions\expectDone('alpaca_bot/chat/completed')->never();

    // The user is still told the turn failed, the same way, ...
    expect(fn() => $h->pipeline->complete(3, 'draft it'))->toThrow(\RuntimeException::class, 'Provider error: connection refused');
    expect($failed[0]->getMessage())->toBe('connection refused');

    // ... but a draft exists in their Posts list, and the transcript is how they learn of it:
    // the reply is stored as the abandoned path stores one, partial, with what streamed and
    // the record of the call; the receipt bills the call that completed; the post stays.
    $record = ['name' => 'draft_post', 'arguments' => ['text' => 'Hello'], 'result_excerpt' => '{"id": 225}', 'ok' => true];
    expect($failed[1]->messages[1]->content)->toBe('Drafting.')
        ->and($failed[1]->messages[1]->meta['partial'])->toBeTrue()
        ->and($failed[1]->messages[1]->meta['tool_calls'])->toBe([$record])
        ->and($failed[1]->messages[1]->usage)->toBe(['prompt_tokens' => 3, 'completion_tokens' => 1])
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'update_post_meta', 'wp_update_post', 'wp_insert_post'])
        ->and($h->writes[1][2][1]['meta']->tool_calls)->toBe([$record])
        ->and($h->writes[3][2]['meta_input']['total_tokens'])->toBe(4);
});

it('finishes the turn on a tool\'s own word when a toolkit ends the run with a TerminationException, not as a provider error', function (): void {
    // The vendored Tool class catches everything its callback throws, so a termination can
    // only come from a toolkit that implements ToolInterface itself: a third-party toolkit
    // registered through `alpaca_bot/toolkits`, which is the reachable case.
    $tool = new class implements ToolInterface {
        public function name(): string
        {
            return 'stop_here';
        }

        public function description(): string
        {
            return 'ends the run';
        }

        public function parameters(): array
        {
            return [];
        }

        public function execute(array $input): ToolResult
        {
            throw new TerminationException('Stopped: ' . $input['text']);
        }

        public function toFunctionSchema(): array
        {
            return ['type' => 'function', 'function' => ['name' => 'stop_here', 'description' => 'ends the run', 'parameters' => ['type' => 'object', 'properties' => new \stdClass()]]];
        }
    };
    $toolkit = new class ($tool) implements ToolkitInterface {
        public function __construct(private ToolInterface $tool) {}

        public function tools(): array
        {
            return [$this->tool];
        }

        public function guidelines(): string
        {
            return 'Use it.';
        }
    };
    $provider = agentProvider([
        [new Response('One moment.', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'stop_here', ['text' => 'enough'])], usage: new Usage(2, 1, 3))],
    ]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['stop' => $toolkit]));
    Actions\expectDone('alpaca_bot/chat/failed')->never();
    Actions\expectDone('alpaca_bot/chat/completed')->once();

    $r = $h->pipeline->complete(3, 'go');

    // The library reports a termination as an Error finish with no error announced: nothing
    // failed, a tool asked the run to stop, and its message is the run's last word.
    expect($r->reply->content)->toBe("One moment.\n\nStopped: enough")
        ->and($r->reply->meta['tool_calls'])->toBe([['name' => 'stop_here', 'arguments' => ['text' => 'enough'], 'result_excerpt' => 'Stopped: enough', 'ok' => true]])
        ->and($r->receipt['total_tokens'])->toBe(3);
});

it('stores the partial reply with the calls made so far, and lets the fiber go the moment the consumer abandons the run, closing the provider stream', function (): void {
    $weak = null;
    $toolkit = echoToolkit('echo_tool', 'Use it.', static function (array $a) use (&$weak): ToolResult {
        // The tool runs inside the pipeline's fiber: hold it weakly, to see it released.
        $weak = \WeakReference::create(\Fiber::getCurrent());
        return ToolResult::success('echo:' . $a['text']);
    });
    // The second stream is left open when the consumer leaves: its finally runs only when the
    // fiber it is suspended in is unwound, which is the release this test is about.
    $closed = false;
    $streams = 0;
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('stream')->twice()->andReturnUsing(static function () use (&$streams, &$closed): \Generator {
        if (++$streams === 1) {
            yield new Response('first', ProviderFinishReason::Stop);
            yield new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'echo_tool', ['text' => 'ping'])]);
            return;
        }
        try {
            yield new Response('second', ProviderFinishReason::Stop);
            yield new Response('never seen', ProviderFinishReason::Stop);
        } finally {
            $closed = true;
        }
    });
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => $toolkit]));
    $failed = null;
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = [$e, $c];
    });
    Actions\expectDone('alpaca_bot/chat/completed')->never();

    $gen = $h->pipeline->send(3, 'ping');
    expect($gen->current()->text)->toBe('first');
    $gen->next();
    expect($gen->current()->text)->toBe("\n\nsecond")->and($weak)->not->toBeNull()->and($weak->get())->not->toBeNull()->and($closed)->toBeFalse();
    unset($gen);

    // No gc_collect_cycles() here, on purpose: the claim is that dropping the generator drops
    // the last reference to the fiber, so PHP unwinds it in that same destruction. A strong
    // reference anywhere on the cycle (the observer, the agent, a local alive in the fiber's
    // own frames across its suspension) would leave the fiber to the cycle collector, and this
    // assertion would see it alive and its stream still open.
    expect($weak->get())->toBeNull()
        ->and($closed)->toBeTrue()
        ->and($failed[0]->getMessage())->toContain('abandoned')
        ->and($failed[1]->messages[1]->content)->toBe("first\n\nsecond")
        ->and($failed[1]->messages[1]->meta['partial'])->toBeTrue()
        ->and($failed[1]->messages[1]->meta['tool_calls'])->toBe([['name' => 'echo_tool', 'arguments' => ['text' => 'ping'], 'result_excerpt' => 'echo:ping', 'ok' => true]])
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'update_post_meta', 'wp_update_post', 'wp_insert_post']);
});

it('bills an abandoned tool turn for the calls the run had already made, from the usage that had arrived', function (): void {
    $provider = agentProvider([
        // The first call's usage rides on its last chunk, the tool call; the agent sums it into
        // the Output the consumer never sees.
        [new Response('first', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'echo_tool', ['text' => 'ping'])], usage: new Usage(900, 40, 940))],
        [new Response('second', ProviderFinishReason::Stop), new Response('never seen', ProviderFinishReason::Stop, usage: new Usage(950, 60, 1010))],
    ]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));
    $failed = null;
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = $c;
    });

    $gen = $h->pipeline->send(3, 'ping');
    $gen->current();
    $gen->next();
    expect($gen->current()->text)->toBe("\n\nsecond");
    unset($gen);

    // The second call's usage never arrived (its chunk is after the point of abandonment) and
    // is not billed; the first call's is, on the partial reply and on the chat_log row the
    // monthly cap reads. Before this the row said 0.
    expect($failed->messages[1]->usage)->toBe(['prompt_tokens' => 900, 'completion_tokens' => 40])
        ->and($h->writes[3][1])->toBe('chat_log')
        ->and($h->writes[3][2]['meta_input']['prompt_tokens'])->toBe(900)
        ->and($h->writes[3][2]['meta_input']['completion_tokens'])->toBe(40)
        ->and($h->writes[3][2]['meta_input']['total_tokens'])->toBe(940);
});

it('stores a reasoning-only answer once, as the reply, when the Assistant takes the reasoning as the answer', function (): void {
    // Three streams, all reasoning and no text: the Assistant nudges twice, then takes the
    // reasoning as the answer (Output content = the trimmed reasoning). The consumer saw the
    // thoughts stream and the answer yielded after the run; the stored reply must not carry
    // the same text under content and under meta['reasoning'], or a reloaded transcript shows
    // it in the thinking area and again as the reply.
    $think = static fn(): array => [new Response('', ProviderFinishReason::Stop, reasoning: ' the thought ', usage: new Usage(1, 1, 2))];
    $provider = agentProvider([$think(), $think(), $think()]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    $gen = $h->pipeline->send(3, 'think');
    $deltas = [];
    foreach ($gen as $d) {
        $deltas[] = [$d->text, $d->reasoning];
    }
    $r = $gen->getReturn();

    expect($deltas)->toBe([['', ' the thought '], ['', ' the thought '], ['', ' the thought '], ['the thought', '']])
        ->and($r->reply->content)->toBe('the thought')
        ->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms']])
        ->and($r->receipt['total_tokens'])->toBe(6);
});

it('runs the plain path, tools and records aside, when no toolkit is enabled, when the model cannot call tools, and on an ephemeral turn', function (): void {
    $plain = static fn(): array => [new Response('ok', ProviderFinishReason::Stop, usage: new Usage(1, 1, 2))];
    $kit = ['echo' => echoToolkit('echo_tool')];

    // Nothing enabled: the registry is asked and answers nothing.
    $h = pipelineWith(pipelineProvider($plain(), $call), [], [], TOOL_MODEL, null, registryWith([]));
    $r = $h->pipeline->complete(3, 'hi');
    expect($call['tools'])->toBe([])->and($call['options'])->toBe(PLAIN_OPTIONS)->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms']]);
});

it('runs the plain path when the catalogued model cannot call tools', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)], $call), [], [], ['llama3.2'], null, registryWith(['echo' => echoToolkit('echo_tool')]));
    $r = $h->pipeline->complete(3, 'hi');
    expect($call['tools'])->toBe([])->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms']]);
});

it('runs an ephemeral turn plainly, and sends it the same options a tool turn gets', function (): void {
    $provider = agentProvider([
        [new Response('summary', ProviderFinishReason::Stop, usage: new Usage(1, 1, 2))],
        [new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'echo_tool', ['text' => 'ping'])], usage: new Usage(1, 1, 2))],
        [new Response('done now', ProviderFinishReason::Stop, usage: new Usage(1, 1, 2))],
    ], $calls);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]), 2);

    $inner = $h->pipeline->complete(3, 'Long text', ['ephemeral' => true, 'system' => 'Summarize.']);
    $outer = $h->pipeline->complete(3, 'ping');

    expect($inner->reply->content)->toBe('summary')
        ->and($inner->reply->meta)->toBe(['duration_ms' => $inner->receipt['duration_ms']])
        ->and($calls[0]['tools'])->toBe([])
        ->and($outer->reply->meta['tool_calls'])->toHaveCount(1)
        ->and($calls[1]['options'])->toBe($calls[0]['options'])
        ->and($calls[2]['options'])->toBe($calls[0]['options'])
        ->and($calls[0]['options'])->toBe(PLAIN_OPTIONS);
});

it('replays the stored history to the agent, bounded to chat.context_messages, under its own system prompt', function (): void {
    $provider = agentProvider([[new Response('Third answer', ProviderFinishReason::Stop)]], $calls);
    $h = pipelineWith($provider, ['chat.context_messages' => 3, 'chat.system_prompt' => 'Site'], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool', 'Rules.')]));
    \Brain\Monkey\Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'Q1', 'created' => 1], ['role' => 'assistant', 'content' => 'A1', 'created' => 2], ['role' => 'user', 'content' => 'Q2', 'created' => 3], ['role' => 'assistant', 'content' => 'A2', 'created' => 4]]
        : '');

    $r = $h->pipeline->complete(3, 'Q3', ['conversation_id' => 42]);

    expect($r->conversation->messages)->toHaveCount(6)
        ->and(array_map(static fn(object $m): array => [$m::class, is_string($m->content()) ? substr($m->content(), 0, 4) : ''], $calls[0]['messages']))->toBe([
            [SystemMessage::class, '# ID'],
            [UserMessage::class, 'Q2'],
            [AssistantMessage::class, 'A2'],
            [UserMessage::class, 'Q3'],
        ])
        ->and($calls[0]['messages'][0]->content())->toContain('Site')->toContain('Rules.');
});
