<?php

declare(strict_types=1);

use AlpacaBot\Chat\AgentStreamObserver;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Delta;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\Result;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Agent\Output;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
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

    // Streamed in order, and the first iteration's text was out before the tool ran, followed
    // by the empty delta that precedes every tool call (the heartbeat a streaming transport
    // writes before the side effect). The second iteration's text is set off from the first by
    // a paragraph break, since the model sent them as two messages.
    expect(array_map(static fn(Delta $d): array => [$d->text, $d->reasoning], $seen))->toBe([['', 'thinking'], ['Let me check', ''], ['', ''], ["\n\npong ", ''], ['received', '']])
        ->and($ranWith)->toBe([['', 'thinking'], ['Let me check', ''], ['', '']]);

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

it('keeps the turn when anything else throws after a tool ran, the same way: the partial reply stored with the call, the run billed, the failure raised', function (): void {
    // Final review F3: the rule above held on one exit only, the failure the agent announced.
    // A Throwable that escapes the fiber instead (the LogicException after it, a third-party
    // toolkit blowing up outside the vendored catch regions, a consumer throwing into the
    // generator) landed in the same catch, which stored nothing and let the conversation be
    // deleted, with the draft already in the user's posts. The consumer's throw is the lever
    // here: it reaches the catch at the yield, as any of them does.
    $toolkit = echoToolkit('draft_post', 'Use it.', static fn(array $a): ToolResult => ToolResult::success('{"id": 225}'));
    $provider = agentProvider([
        [new Response('Drafting.', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'draft_post', ['text' => 'Hello'])], usage: new Usage(3, 1, 4))],
        [new Response('Done.', ProviderFinishReason::Stop, usage: new Usage(2, 1, 3))],
    ]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['draft' => $toolkit]));
    $failed = null;
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = [$e, $c];
    });
    Actions\expectDone('alpaca_bot/chat/completed')->never();

    $turn = $h->pipeline->send(3, 'draft it');
    $seen = '';
    foreach ($turn as $delta) {
        $seen .= $delta->text;
        if (str_contains($seen, 'Done.')) {
            break;
        }
    }
    // The tool has run and the second stream is under way when the throw arrives.
    expect($seen)->toContain('Drafting.')->toContain('Done.');
    expect(fn() => $turn->throw(new \LogicException('boom')))->toThrow(\RuntimeException::class, 'Provider error: boom');
    expect($failed[0])->toBeInstanceOf(\LogicException::class);

    $record = ['name' => 'draft_post', 'arguments' => ['text' => 'Hello'], 'result_excerpt' => '{"id": 225}', 'ok' => true];
    expect($failed[1]->messages)->toHaveCount(2)
        ->and($failed[1]->messages[1]->content)->toContain('Drafting.')->toContain('Done.')
        ->and($failed[1]->messages[1]->meta['partial'])->toBeTrue()
        ->and($failed[1]->messages[1]->meta['tool_calls'])->toBe([$record])
        // Billed for what had arrived, read before each yield: both calls' usage, since the
        // second stream's chunk carried its usage with the text.
        ->and($failed[1]->messages[1]->usage)->toBe(['prompt_tokens' => 5, 'completion_tokens' => 2])
        ->and(array_map(static fn(array $w): string => $w[0], $h->writes))->toBe(['wp_insert_post', 'update_post_meta', 'wp_update_post', 'wp_insert_post'])
        ->and($h->writes[1][2][1]['meta']->tool_calls)->toBe([$record])
        ->and($h->writes[3][2]['meta_input']['total_tokens'])->toBe(7);
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
    expect($gen->current()->text)->toBe('');  // the heartbeat before the tool runs
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
    $gen->next();  // the heartbeat before the tool runs
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

// The stream route's process keeps running when the client goes (Rest\Sse: ignore_user_abort)
// and learns of the disconnect only from a write that fails, which StreamController asks after
// every frame. An iteration that calls a tool with no text before it (routine: a model's first
// move is often the call alone) gives the transport nothing to write, so nothing tells it the
// user has gone before the tool's side effect lands, and a chain of such iterations runs to
// the budget with the tab closed. The pipeline yields an empty delta before each tool runs: a
// frame to write, and so a moment to notice the disconnect and drop the run, before the effect.
it('yields an empty delta before each tool runs, so a consumer that leaves then stops the tool from running at all', function (): void {
    $ran = 0;
    $toolkit = echoToolkit('draft_post', 'Use it.', static function (array $a) use (&$ran): ToolResult {
        $ran++;
        return ToolResult::success('{"id": 225}');
    });
    // Two silent iterations: a call and nothing else, twice. The consumer leaves at the
    // second call's heartbeat, so the provider is asked exactly twice and no answer is needed.
    $provider = agentProvider([
        [new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'draft_post', ['text' => 'One'])])],
        [new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c2', 'draft_post', ['text' => 'Two'])])],
    ]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['draft' => $toolkit]));
    $failed = null;
    Actions\expectDone('alpaca_bot/chat/failed')->once()->whenHappen(function (\Throwable $e, Conversation $c) use (&$failed): void {
        $failed = $c;
    });

    $gen = $h->pipeline->send(3, 'draft two');
    // The first thing out is the heartbeat for the first call, and the tool has not run yet.
    expect([$gen->current()->text, $gen->current()->reasoning])->toBe(['', ''])->and($ran)->toBe(0);
    $gen->next();
    // After it, the first tool ran and the second call's heartbeat is out; the second has not.
    expect([$gen->current()->text, $gen->current()->reasoning])->toBe(['', ''])->and($ran)->toBe(1);
    unset($gen);

    // Leaving at that moment is what a transport does on a failed write: the second tool
    // never runs, and the record says its call was made and not answered.
    expect($ran)->toBe(1)
        ->and($failed->messages[1]->meta['tool_calls'])->toBe([
            ['name' => 'draft_post', 'arguments' => ['text' => 'One'], 'result_excerpt' => '{"id": 225}', 'ok' => true],
            ['name' => 'draft_post', 'arguments' => ['text' => 'Two'], 'result_excerpt' => '', 'ok' => false],
        ]);
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

// A model whose deployed Ollama template cannot really call tools is still offered them (the
// provider advertises `tools` for a template that is a bare `{{ .Prompt }}` passthrough), so it
// writes the call it was asked for as prose. The endpoint returns that as ordinary assistant
// content, it streams as text, and when the faked call is the agent's own `done` the user's
// whole answer is inside its `response` argument and never reaches the bubble. The pipeline
// recovers it after the run, before anything is stored. Capability data cannot discriminate,
// so recovery is not optional; the live deltas still carry the markup (a separate item) and
// the front end replaces the bubble wholesale on `done`, so the stored reply is what shows.

/** The verbatim leak recorded on alpaca10.wp.test from qwen3-vl:2b, 2026-09-09. */
function fakedToolCallLeak(): string
{
    return (string) file_get_contents(__DIR__ . '/../../fixtures/faked-tool-call-qwen3-vl-2b.txt');
}

it('recovers the answer a faked done call buried in its own JSON, from the recorded leak', function (): void {
    $leak = fakedToolCallLeak();
    // The recorded shape, which is why a parser handed the whole string gets null: the opening
    // marker was eaten by the template (the first call did run, through the API), only the
    // stray closing one leaked, the second block carries no markers at all, and there are two
    // JSON objects in the one string.
    expect($leak)->toContain('</tool_call>')
        ->and(str_contains($leak, '<tool_call>'))->toBeFalse()
        ->and(json_decode($leak, true))->toBeNull();

    $toolkit = echoToolkit('web_fetch', 'Use it.', static fn(array $a): ToolResult => ToolResult::success('Alpaca - Wikipedia'));
    $provider = agentProvider([
        // The call the provider really made, through its own API: it runs, and it is the one
        // record on the reply.
        [new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'web_fetch', ['text' => 'https://en.wikipedia.org/wiki/Alpaca'])], usage: new Usage(3, 1, 4))],
        // The next turn, where the same model wrote its calls as text instead.
        [new Response($leak, ProviderFinishReason::Stop, usage: new Usage(4, 2, 6))],
    ]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['fetch' => $toolkit]));
    Actions\expectDone('alpaca_bot/chat/failed')->never();

    $r = $h->pipeline->complete(3, 'In one sentence, what is an alpaca?');

    expect($r->reply->content)->toBe('An alpaca (Lama pacos) is a domesticated species of South American camelid, traditionally bred for their valuable fiber in the textile industry.')
        ->not->toContain('tool_call')
        ->not->toContain('{"name"')
        ->not->toContain('web_fetch')
        // The record says what actually ran, once: the API call the agent made. The leaked
        // web_fetch is the same call written out as prose, and listing it again would tell the
        // reader of the transcript that a fetch was attempted twice.
        ->and($r->reply->meta['tool_calls'])->toBe([['name' => 'web_fetch', 'arguments' => ['text' => 'https://en.wikipedia.org/wiki/Alpaca'], 'result_excerpt' => 'Alpaca - Wikipedia', 'ok' => true]])
        ->and($h->writes[1][2][1]['content'])->toBe($r->reply->content);
});

it('recovers a well-formed faked done call, markers and all, and drops the markup around it', function (): void {
    $leak = "Let me answer that.\n\n<tool_call>\n{\"name\": \"done\", \"arguments\": {\"response\": \"An alpaca is a camelid.\"}}\n</tool_call>";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    $r = $h->pipeline->complete(3, 'what is an alpaca?');

    // The prose the model wrote before the faked call is its own and is kept; the call is not.
    expect($r->reply->content)->toBe("Let me answer that.\n\nAn alpaca is a camelid.")
        ->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms']]);
});

it('strips a faked call that is not done from the reply and never executes it', function (): void {
    $ran = 0;
    $toolkit = echoToolkit('draft_post', 'Use it.', static function (array $a) use (&$ran): ToolResult {
        $ran++;
        return ToolResult::success('{"id": 225}');
    });
    $leak = "I will draft that for you.\n\n<tool_call>{\"name\": \"draft_post\", \"arguments\": {\"text\": \"Hello\"}}</tool_call>\n\nDone.";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['draft' => $toolkit]));

    $r = $h->pipeline->complete(3, 'draft it');

    // Recovering text is one thing; running a tool the provider never authorised through its
    // own API is another. The markup goes, the tool does not run, and nothing on the reply
    // claims it did.
    expect($r->reply->content)->toBe("I will draft that for you.\n\nDone.")
        ->and($ran)->toBe(0)
        ->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms']]);
});

it('leaves prose that merely mentions <tool_call> exactly as the model wrote it', function (): void {
    $prose = "A model without a tools template writes <tool_call> markers into its answer.\n\nThey look like this: <tool_call>...</tool_call>, and they are not calls at all.";
    $provider = agentProvider([[new Response($prose, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    $r = $h->pipeline->complete(3, 'explain the markers');

    // Nothing between the markers decoded as a call, so nothing was recovered and not one byte
    // of the answer is rewritten.
    expect($r->reply->content)->toBe($prose);
});

it('keeps the whole leak when the faked done call carries no answer, rather than storing an empty reply', function (): void {
    foreach (['{"name": "done", "arguments": {"response": ""}}', '{"name": "done", "arguments": {}}'] as $call) {
        $leak = "<tool_call>{$call}</tool_call>";
        $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
        $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

        // There is no answer to recover, so recovery is off: whatever the model sent survives
        // verbatim and the user can at least see what happened. Losing text is the one outcome
        // this must never have.
        expect($h->pipeline->complete(3, 'answer')->reply->content)->toBe($leak);
    }
});

it('recovers the answer without reflowing the answer around it: an indented code block keeps its indentation', function (): void {
    // Recovery cuts a segment at its blank lines to find a call the template left no marker
    // around, and a piece of that cut is trimmed. An ordinary answer is separated by blank
    // lines too, so cutting one that holds no call would take the indentation off every line of
    // a code block that follows one — text lost to a fix whose whole point is not losing text.
    // The cut is kept only where it finds a call; here it finds none in the prose, and the
    // marked call it does find is a segment of its own.
    $code = "Here is the fix:\n\n    if (\$x) {\n\n        run();\n    }\n\nThat is all.";
    $leak = "{$code}\n\n<tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"Indent it by four spaces.\"}}</tool_call>";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    $r = $h->pipeline->complete(3, 'how do I indent?');

    expect($r->reply->content)->toBe("{$code}\n\nIndent it by four spaces.")
        ->and($r->reply->content)->toContain("\n\n        run();");
});

it('keeps every byte of a segment that holds a marker-less faked call beside an indented code block', function (): void {
    // The shape pass 2 exists for: prose and a call the template left no marker around, in one
    // streamed segment (AgentStreamObserver::separated() joins an iteration's text to the next
    // with a blank line). Cutting that segment at its blank lines to find the call must not cost
    // the answer around it a single byte — the indentation of a four-space code block, and the
    // blank line inside it, are the model's own and are what the user came for.
    $code = "Here is the fix:\n\n    if (\$x) {\n\n        run();\n    }\n\nThat is all.";
    $leak = "<tool_call>{\"name\": \"web_fetch\", \"arguments\": {\"url\": \"https://example.com\"}}</tool_call>\n\n"
        . "{$code}\n\n{\"name\": \"done\", \"arguments\": {\"response\": \"Indent it by four spaces.\"}}";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    $r = $h->pipeline->complete(3, 'how do I indent?');

    expect($r->reply->content)->toBe("{$code}\n\nIndent it by four spaces.")
        ->and($r->reply->content)->toContain("\n\n        run();")
        ->and($r->reply->content)->toContain("\n    if (\$x) {");
});

it('recovers a marker-less call that a stray marker stands against, and takes the marker with it', function (): void {
    // The recorded leak's own shape with a sentence in front of it: a call the template left no
    // marker around, and the stray closing marker its opening half never came with. The call
    // stands on its own line — only the marker beside it is adjacent — so a marker that touches
    // a call the second pass found is part of the same run and goes out with it. Leaving it
    // refuses the recovery outright and the user reads the raw JSON: the exact failure this
    // whole method exists to remove.
    $done = "{\"name\": \"done\", \"arguments\": {\"response\": \"An alpaca is a camelid.\"}}";
    $fetch = "{\"name\": \"web_fetch\", \"arguments\": {\"url\": \"https://example.com\"}}";
    foreach ([
        ["Let me check.\n\n{$done}\n</tool_call>", "Let me check.\n\nAn alpaca is a camelid."],
        // The marker on the call's own line, which is the shape the leak takes when the
        // template emits no newline before the half of the marker pair it did write.
        ["Let me check.\n\n{$done}</tool_call>", "Let me check.\n\nAn alpaca is a camelid."],
        // The recorded leak entire, one iteration's prose joined to it: both marker-less calls
        // are in the one segment, and every one of them is found, not merely the first.
        ["Let me check.\n\n{$fetch}\n\n{$done}\n</tool_call>", "Let me check.\n\nAn alpaca is a camelid."],
        // A "blank" line a model left spaces on is still the blank line that separates them,
        // and the spaces are on the prose's own line, so they stay there.
        ["Let me check.\n   \n{$done}\n</tool_call>", "Let me check.\n   \nAn alpaca is a camelid."],
    ] as [$leak, $expected]) {
        $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
        $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

        expect($h->pipeline->complete(3, 'what is an alpaca?')->reply->content)->toBe($expected);
    }
});

it('leaves a marker with prose between it and the nearest call in the prose it was written into', function (): void {
    // The other edge of the same rule: "stands against" is whitespace and nothing else. Prose
    // between a marker and a call is the model writing the marker into its answer, and a
    // sentence the user is reading is not markup because a call turned up two paragraphs later.
    $done = "{\"name\": \"done\", \"arguments\": {\"response\": \"An alpaca is a camelid.\"}}";
    foreach ([
        ["<tool_call>\n\nSome prose.\n\n{$done}", "<tool_call>\n\nSome prose.\n\nAn alpaca is a camelid."],
        ["{$done}\n\nSome prose.\n\n</tool_call>", "An alpaca is a camelid.\n\nSome prose.\n\n</tool_call>"],
    ] as [$leak, $expected]) {
        $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
        $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

        expect($h->pipeline->complete(3, 'what is an alpaca?')->reply->content)->toBe($expected);
    }
});

it('leaves no orphan marker in the stored reply where a recovered call sat under one', function (): void {
    // The rendered bubble strips the tags, so an orphan marker is invisible there — but the
    // stored content is also the CLI's `--json`, the export and the next turn's history, and
    // the markup is supposed to be gone from all of them. Nothing but the blank line separates
    // this marker from the call it opened, so the two are one removal.
    $done = "{\"name\": \"done\", \"arguments\": {\"response\": \"An alpaca is a camelid.\"}}";
    $leak = "<tool_call>\n\n{$done}\n\nHere is more prose.";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    $r = $h->pipeline->complete(3, 'what is an alpaca?');

    expect($r->reply->content)->toBe("An alpaca is a camelid.\n\nHere is more prose.")
        ->not->toContain('tool_call')
        ->and($h->writes[1][2][1]['content'])->toBe($r->reply->content);
});

it('leaves an answer about tool calls whole, fenced JSON example and all, on the tools branch', function (): void {
    // A reply explaining tool-call syntax carries a marker and a JSON object with a name, which
    // is every trigger this has. Nothing may be rewritten: not the sentence the marker sits in,
    // and not the fenced example. A fence is how a model *shows* a call; only a marker says it
    // is making one, so a fenced block outside the markers is never read as a call.
    $prose = "Tool calls look like <tool_call>.\n\nExample:\n\n```json\n"
        . "{\"name\": \"web_fetch\", \"arguments\": {\"url\": \"https://example.com\"}}\n```\n\nThat's it.";
    $provider = agentProvider([[new Response($prose, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    expect($h->pipeline->complete(3, 'how do tool calls work?')->reply->content)->toBe($prose);
});

it('refuses a fenced example that is the whole of a reply\'s head or tail, marker elsewhere or not', function (): void {
    // A fence is how a model *shows* a call; only a marker says it is making one. Outside the
    // markers that holds however much of the reply the fence is: an answer whose head is
    // nothing but a fenced example, with a stray marker further down, must keep the example.
    // Deleting it is the wrong direction twice over — it loses the user's text, and it loses it
    // to a rule written to protect exactly this reply.
    $fence = "```json\n{\"name\": \"done\", \"arguments\": {\"response\": \"An alpaca is a camelid.\"}}\n```";
    foreach (["{$fence}\n\n<tool_call>", "</tool_call>\n\n{$fence}"] as $prose) {
        $provider = agentProvider([[new Response($prose, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
        $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

        expect($h->pipeline->complete(3, 'how do tool calls work?')->reply->content)->toBe($prose);
    }
});

it('reads a fenced call between the markers as the call it is', function (): void {
    // The other side of the same rule, and why the refusal is not simply global: between an
    // opening and a closing marker a fence is the call's own formatting, and the answer inside
    // it is still the user's answer.
    $leak = "<tool_call>\n```json\n{\"name\": \"done\", \"arguments\": {\"response\": \"An alpaca is a camelid.\"}}\n```\n</tool_call>";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    expect($h->pipeline->complete(3, 'what is an alpaca?')->reply->content)->toBe('An alpaca is a camelid.');
});

it('leaves the plain-turn prose whole on the tools branch too', function (): void {
    // The fixture test 7 runs on the plain branch; the same string on the tools branch is the
    // hazard, and it must come back byte for byte there as well.
    $prose = "Write <tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"x\"}}</tool_call> to fake a call.";
    $provider = agentProvider([[new Response($prose, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    expect($h->pipeline->complete(3, 'how do models fake calls?')->reply->content)->toBe($prose);
});

it('gives back a done answer that is itself indented with its indentation on', function (): void {
    // Trailing whitespace goes with the markup; leading whitespace is the answer's own. A model
    // that buried a code block in a `done` response gets the code block back, not its left edge
    // shaved off.
    $answer = "    if (\$x) {\n        run();\n    }";
    $leak = "Here:\n\n<tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"    if (\$x) {\\n        run();\\n    }  \"}}</tool_call>";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    expect($h->pipeline->complete(3, 'how do I indent?')->reply->content)->toBe("Here:\n\n{$answer}");
});

it('leaves the indentation of the first line to survive a removal exactly where the model put it', function (): void {
    // Closing the hole a removal leaves takes the blank line after it and nothing else. The
    // next line's leading spaces belong to the answer: shaving them off the *first* line of a
    // four-space block while every other line keeps its four is worse than losing indentation
    // — the block stops being a block and the code renders as a paragraph.
    $block = "    if (\$x) {\n        run();\n    }";
    $fetch = "<tool_call>{\"name\": \"web_fetch\", \"arguments\": {\"url\": \"https://example.com\"}}</tool_call>";
    $done = "{\"name\": \"done\", \"arguments\": {\"response\": \"Indent it by four spaces.\"}}";
    foreach ([
        // The removal is the whole head of the reply, and the block is the first thing left.
        ["{$fetch}\n\n{$block}\n\nThat is all.", "{$block}\n\nThat is all."],
        // The removal is in the middle, and a marker-less `done` closes the reply.
        ["Here:\n\n{$fetch}\n\n{$block}\n\n{$done}", "Here:\n\n{$block}\n\nIndent it by four spaces."],
    ] as [$leak, $expected]) {
        $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
        $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

        expect($h->pipeline->complete(3, 'how do I indent?')->reply->content)->toBe($expected);
    }
});

it('leaves a payload that names no call where the model put it', function (): void {
    // `{"tool_calls": []}` decodes as a call payload and holds no call. Nothing was faked, so
    // there is nothing to take out, and a block this reads but finds nothing in is the model's
    // own text like any other.
    $leak = "Hello.\n\n<tool_call>{\"tool_calls\": []}</tool_call>";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    expect($h->pipeline->complete(3, 'say hello')->reply->content)->toBe($leak);
});

it('leaves no blank line behind where the markup was the whole of the reply', function (): void {
    // The blank lines the template wrapped the call in are the markup's, not the answer's: a
    // reply must not open or close on the hole a recovery left.
    $leak = "\n\n<tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"An alpaca is a camelid.\"}}</tool_call>\n\n";
    $provider = agentProvider([[new Response($leak, ProviderFinishReason::Stop, usage: new Usage(2, 2, 4))]]);
    $h = pipelineWith($provider, [], [], TOOL_MODEL, null, registryWith(['echo' => echoToolkit('echo_tool')]));

    expect($h->pipeline->complete(3, 'what is an alpaca?')->reply->content)->toBe('An alpaca is a camelid.');
});

it('leaves a plain turn that mentions <tool_call> untouched, tools branch or not', function (): void {
    // The regression guard: recovery lives in the tools branch, and a turn with no toolkit runs
    // the plain provider stream, byte for byte what it was before any of this.
    $prose = "Write <tool_call>{\"name\": \"done\", \"arguments\": {\"response\": \"x\"}}</tool_call> to fake a call.";
    $h = pipelineWith(pipelineProvider([new Response($prose, ProviderFinishReason::Stop, usage: new Usage(1, 1, 2))], $call), [], [], TOOL_MODEL, null, registryWith([]));

    $r = $h->pipeline->complete(3, 'how do models fake calls?');

    expect($r->reply->content)->toBe($prose)
        ->and($call['tools'])->toBe([])
        ->and($r->reply->meta)->toBe(['duration_ms' => $r->receipt['duration_ms']]);
});

// Pipeline::failure() decides whether an Error finish is a failure to raise or a tool's own
// stop to show. It is private and static, and the two Outputs that separate the cases cannot
// both be produced through a real agent run (the unannounced-failure one does not exist in
// the pinned library — it is the shape a future release could add), so these call it directly
// on the Outputs the library builds at each of its Error sites, and on the ones it might.

/** The message an unannounced failure carries, as Pipeline::failure() writes it. */
const UNREPORTED_FAILURE = 'The run ended in an error the assistant did not report.';

/** Pipeline::failure(), which is private and static. */
function pipelineFailure(Output $output, AgentStreamObserver $observer): ?string
{
    /** @var ?string */
    return (new ReflectionMethod(Pipeline::class, 'failure'))->invoke(null, $output, $observer);
}

/** An observer that heard `agent.error` with `$message`, or heard nothing when it is null. */
function observerHearing(?string $message): AgentStreamObserver
{
    $observer = new AgentStreamObserver();
    if ($message !== null) {
        $agent = agentSubject();
        $agent->attach($observer);
        $agent->notify('agent.error', $message);
    }
    return $observer;
}

it('reads an Error finish that announced nothing and terminated nothing as a failure, in the library\'s own words or its own', function (): void {
    // The regression this guards: a library release that returns an Error finish from a site
    // that does not announce `agent.error`. Read by the absence of the announcement, this is a
    // finished reply carrying 'Provider error: ...' as the assistant's answer, billed and
    // fired as a success. Read by what it is — an Error finish that is not a termination — it
    // is the failure it is.
    $unannounced = new Output(content: 'Provider error: boom', finishReason: AgentFinishReason::Error);

    expect(pipelineFailure($unannounced, observerHearing(null)))->toBe(UNREPORTED_FAILURE)
        // Never the library's raw content: send() puts what comes back in a RuntimeException as
        // the announced message, and 'Provider error: boom' is not a message anything announced.
        ->not->toContain('boom')
        // Announced or not, the finish decides; the announcement only supplies the words.
        ->and(pipelineFailure($unannounced, observerHearing('boom')))->toBe('boom');
});

it('reads each of the library\'s announced Error finishes as a failure, in the announced words', function (): void {
    // The two sites that announce: the cancellation token tripped, and the provider threw.
    expect(pipelineFailure(new Output(content: 'Task was cancelled.', finishReason: AgentFinishReason::Error), observerHearing('Task cancelled')))->toBe('Task cancelled')
        ->and(pipelineFailure(new Output(content: 'Provider error: 500 Internal Server Error', finishReason: AgentFinishReason::Error), observerHearing('500 Internal Server Error')))->toBe('500 Internal Server Error')
        // The provider site announces a Throwable's getMessage(), which can be ''. Announced
        // with no words is still announced, and still a failure — it just borrows this
        // plugin's words, the same ones an unannounced failure gets.
        ->and(pipelineFailure(new Output(content: 'Provider error: ', finishReason: AgentFinishReason::Error), observerHearing('')))->toBe(UNREPORTED_FAILURE);
});

it('reads an announced Error finish as the failure it is even when a tool result repeats its content, because the announcement is read first', function (): void {
    // Both announced sites build their Output with `toolResults: $allToolResults` — every
    // result the run accumulated, not this iteration's — so a provider failure carries whatever
    // earlier tools returned. A tool that had echoed the library's own words back (a fetch tool
    // quoting an upstream error, a memory tool replaying the last one) leaves a Success result
    // equal to the content the failure site then builds. Asking "did it terminate?" before
    // "was it announced?" reads that as a tool's own stop: a provider failure stored as a
    // finished, billed reply, fired as `alpaca_bot/chat/completed`. The announcement is read
    // first, so only an unannounced Error is ever asked whether it terminated.
    $announcedProviderError = new Output(
        content: 'Provider error: boom',
        toolResults: [ToolResult::success('echo:ping'), ToolResult::success('Provider error: boom')],
        finishReason: AgentFinishReason::Error,
    );
    $announcedCancellation = new Output(
        content: 'Task was cancelled.',
        toolResults: [ToolResult::success('Task was cancelled.')],
        finishReason: AgentFinishReason::Error,
    );

    expect(pipelineFailure($announcedProviderError, observerHearing('boom')))->toBe('boom')
        ->and(pipelineFailure($announcedCancellation, observerHearing('Task cancelled')))->toBe('Task cancelled');
});

it('reads an Error finish whose content is a successful tool result as the termination it is, not a failure', function (): void {
    // The one unannounced Error finish the pinned library has: executeToolCalls() caught a
    // TerminationException, recorded its message as that tool's successful result, and the
    // agent returned the same message as the content. Nothing failed.
    $terminated = new Output(
        content: 'Stopped: enough',
        toolResults: [ToolResult::success('echo:ping'), ToolResult::success('Stopped: enough')],
        finishReason: AgentFinishReason::Error,
    );

    expect(pipelineFailure($terminated, observerHearing(null)))->toBeNull();
});

it('reads a near miss as the failure it is: the same words as the content, but not from a tool that succeeded', function (): void {
    // A tool whose result content matches by coincidence but whose status is Error (or
    // Timeout) is not the termination path: that path records ToolResult::success().
    $failed = new Output(
        content: 'Provider error: boom',
        toolResults: [ToolResult::error('Provider error: boom'), new ToolResult(ToolResultStatus::Timeout, 'Provider error: boom')],
        finishReason: AgentFinishReason::Error,
    );

    expect(pipelineFailure($failed, observerHearing(null)))->toBe(UNREPORTED_FAILURE)
        ->and(pipelineFailure($failed, observerHearing('boom')))->toBe('boom');
});

it('reads a termination that had nothing to say as the termination it is: an empty stop is still a stop', function (): void {
    // TerminationException extends RuntimeException, so a toolkit can throw one with no
    // message. The library then records ToolResult::success('') and returns '' as the content:
    // a run a tool ended, exactly like any other, and before tools this was a finished turn
    // showing whatever had streamed. Refusing to match on empty content would make it a
    // failure instead — the reply marked partial, `alpaca_bot/chat/failed` fired, and
    // `Provider error: ...` in the user's bubble — for a stop nothing went wrong in.
    $empty = new Output(
        content: '',
        toolResults: [ToolResult::success('')],
        finishReason: AgentFinishReason::Error,
    );

    expect(pipelineFailure($empty, observerHearing(null)))->toBeNull();
});

it('leaves every finish that is not an Error alone', function (): void {
    foreach ([AgentFinishReason::Stop, AgentFinishReason::Done, AgentFinishReason::MaxIterations, AgentFinishReason::BudgetExhausted, AgentFinishReason::EmptyResponse] as $reason) {
        expect(pipelineFailure(new Output(content: 'Answer', finishReason: $reason), observerHearing('boom')))->toBeNull();
    }
});
