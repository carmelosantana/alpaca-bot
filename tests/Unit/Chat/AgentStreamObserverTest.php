<?php

declare(strict_types=1);

use AlpacaBot\Chat\AgentStreamObserver;
use AlpacaBot\Chat\Delta;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

// The vendored AbstractAgent::notify() calls update($this) with one argument and leaves the
// event name and payload on the subject (lastEvent(), lastEventData()), so these tests drive
// the observer through a real agent's notify(): the shape it will see in production.

// agentSubject() lives in tests/Pest.php.

it('queues text and reasoning deltas in order and hands them over once', function (): void {
    $agent = agentSubject();
    $observer = new AgentStreamObserver();
    $agent->attach($observer);
    $agent->notify('agent.iteration', 1);
    $agent->notify('agent.reasoning', 'think');
    $agent->notify('agent.text_delta', 'Hel');
    $agent->notify('agent.text_delta', 'lo');
    $agent->notify('agent.done', ['response' => 'Hello']);

    $deltas = $observer->drain();
    expect(array_map(static fn(Delta $d): array => [$d->text, $d->reasoning], $deltas))->toBe([['', 'think'], ['Hel', ''], ['lo', '']])
        ->and($observer->drain())->toBe([])
        ->and($observer->toolCalls())->toBe([])
        ->and($observer->error())->toBeNull();
});

it('records each tool call with its result, matched by call id, bounded excerpts, and ok from the result status', function (): void {
    $agent = agentSubject();
    $observer = new AgentStreamObserver();
    $agent->attach($observer);
    $long = str_repeat('é', AgentStreamObserver::RESULT_CHARS + 5);
    $longArg = str_repeat('x', AgentStreamObserver::ARGUMENT_CHARS + 1);
    // Two calls announced first, results after, out of order: the pairing is by id, not position.
    $agent->notify('agent.tool_call', new ToolCall('c1', 'web_fetch', ['url' => 'https://example.test/', 'nested' => ['note' => $longArg, 'n' => 3]]));
    $agent->notify('agent.tool_call', new ToolCall('c2', 'summarize', ['text' => 'abc']));
    $agent->notify('agent.tool_result', ToolResult::error('boom')->withCallId('c2'));
    $agent->notify('agent.tool_result', ToolResult::success($long)->withCallId('c1'));
    $agent->notify('agent.tool_call', new ToolCall('c3', 'draft_post', ['title' => 'T']));
    $agent->notify('agent.tool_result', (new ToolResult(ToolResultStatus::Timeout, 'slow'))->withCallId('c3'));

    $calls = $observer->toolCalls();
    expect($calls)->toHaveCount(3)
        ->and($calls[0])->toBe(['name' => 'summarize', 'arguments' => ['text' => 'abc'], 'result_excerpt' => 'boom', 'ok' => false])
        ->and($calls[1]['name'])->toBe('web_fetch')
        ->and($calls[1]['ok'])->toBeTrue()
        // The excerpt is cut in characters, not bytes, and says it was cut.
        ->and(mb_strlen($calls[1]['result_excerpt']))->toBe(AgentStreamObserver::RESULT_CHARS + 1)
        ->and(str_ends_with($calls[1]['result_excerpt'], '…'))->toBeTrue()
        ->and($calls[1]['arguments']['url'])->toBe('https://example.test/')
        ->and($calls[1]['arguments']['nested']['n'])->toBe(3)
        ->and(mb_strlen($calls[1]['arguments']['nested']['note']))->toBe(AgentStreamObserver::ARGUMENT_CHARS + 1)
        ->and($calls[2])->toBe(['name' => 'draft_post', 'arguments' => ['title' => 'T'], 'result_excerpt' => 'slow', 'ok' => false]);
});

// Every record is stored on the assistant turn for the life of the conversation, against the
// packet budget ConversationStore::save() fits the transcript to. A bound on each string does
// not bound the record: a tool taking a large list of short strings, or a model inventing keys,
// would store as much as it sent. The record as a whole has a ceiling, counted in characters
// over its keys and values at every depth, and the cut is marked the way a cut string is.
it('holds the whole arguments record to ARGUMENTS_CHARS, at any depth, marking the cut, and leaves an ordinary record alone', function (): void {
    $agent = agentSubject();
    $observer = new AgentStreamObserver();
    $agent->attach($observer);
    $many = [];
    for ($i = 0; $i < 200; $i++) {
        $many['key' . $i] = str_repeat('v', 90);
    }
    $agent->notify('agent.tool_call', new ToolCall('c1', 'bulk', ['first' => 'kept', 'items' => $many, 'after' => 'dropped']));
    $agent->notify('agent.tool_result', ToolResult::success('ok')->withCallId('c1'));
    $agent->notify('agent.tool_call', new ToolCall('c2', 'draft_post', ['title' => 'Hello Alpaca', 'content' => str_repeat('p', 500), 'post_type' => 'post', 'tags' => ['a', 'b']]));
    $agent->notify('agent.tool_result', ToolResult::success('ok')->withCallId('c2'));

    [$bulk, $draft] = $observer->toolCalls();
    $size = static function (mixed $value) use (&$size): int {
        if (is_array($value)) {
            $n = 0;
            foreach ($value as $k => $v) {
                $n += mb_strlen((string) $k) + $size($v);
            }
            return $n;
        }
        return mb_strlen(is_string($value) ? $value : json_encode($value));
    };
    // 200 x 90 characters went in; what is stored is held to the ceiling (plus the marks),
    // keeps the entries in order up to it, and says where it stopped at each level it cut.
    expect($size($bulk['arguments']))->toBeLessThanOrEqual(AgentStreamObserver::ARGUMENTS_CHARS + 10)
        ->and($bulk['arguments']['first'])->toBe('kept')
        ->and(array_key_first($bulk['arguments']['items']))->toBe('key0')
        ->and(array_key_last($bulk['arguments']['items']))->toBe('…')
        ->and(count($bulk['arguments']['items']))->toBeLessThan(200)
        ->and($bulk['arguments'])->not->toHaveKey('after')
        ->and(array_key_last($bulk['arguments']))->toBe('…')
        // A record within the ceiling is stored exactly as the model sent it.
        ->and($draft['arguments'])->toBe(['title' => 'Hello Alpaca', 'content' => str_repeat('p', 500), 'post_type' => 'post', 'tags' => ['a', 'b']]);
});

it('pairs a result carrying no id with the oldest unanswered call, and reports a call never answered as not ok', function (): void {
    $agent = agentSubject();
    $observer = new AgentStreamObserver();
    $agent->attach($observer);
    $agent->notify('agent.tool_call', new ToolCall('', 'a', []));
    $agent->notify('agent.tool_call', new ToolCall('', 'b', []));
    $agent->notify('agent.tool_result', ToolResult::success('ra'));
    expect($observer->toolCalls())->toBe([
        ['name' => 'a', 'arguments' => [], 'result_excerpt' => 'ra', 'ok' => true],
        ['name' => 'b', 'arguments' => [], 'result_excerpt' => '', 'ok' => false],
    ]);
});

it('keeps the last agent.error message and ignores a subject that is not an agent', function (): void {
    $agent = agentSubject();
    $observer = new AgentStreamObserver();
    $agent->attach($observer);
    $agent->notify('agent.error', 'connection refused');
    $other = new class implements \SplSubject {
        public function attach(\SplObserver $observer): void {}
        public function detach(\SplObserver $observer): void {}
        public function notify(): void {}
    };
    $observer->update($other);
    expect($observer->error())->toBe('connection refused')->and($observer->drain())->toBe([]);
});

it('suspends the fiber it streams through on every delta, and only that fiber', function (): void {
    $agent = agentSubject();
    $observer = new AgentStreamObserver();
    $agent->attach($observer);
    $fiber = new \Fiber(static function () use ($agent): string {
        $agent->notify('agent.text_delta', 'a');
        $agent->notify('agent.tool_call', new ToolCall('c1', 't', []));
        $agent->notify('agent.text_delta', 'b');
        return 'done';
    });
    $observer->streamThrough($fiber);

    $fiber->start();
    expect($fiber->isSuspended())->toBeTrue()->and(array_map(static fn(Delta $d): string => $d->text, $observer->drain()))->toBe(['a']);
    $fiber->resume();
    // The tool call queued the heartbeat, an empty delta, and suspended on it: the pipeline
    // gets control (and a streaming transport a frame to write) before the tool runs.
    $beat = $observer->drain();
    expect($fiber->isSuspended())->toBeTrue()->and($beat)->toHaveCount(1)->and([$beat[0]->text, $beat[0]->reasoning])->toBe(['', '']);
    $fiber->resume();
    expect($fiber->isSuspended())->toBeTrue()->and(array_map(static fn(Delta $d): string => $d->text, $observer->drain()))->toBe(['b']);
    $fiber->resume();
    expect($fiber->isTerminated())->toBeTrue()->and($fiber->getReturn())->toBe('done')->and($observer->drain())->toBe([]);

    // A delta raised inside some other fiber is queued, not suspended: suspending a fiber the
    // pipeline does not resume would leave it hanging.
    $stranger = new \Fiber(static function () use ($agent): string {
        $agent->notify('agent.text_delta', 'c');
        return 'ran through';
    });
    $stranger->start();
    expect($stranger->isTerminated())->toBeTrue()->and($stranger->getReturn())->toBe('ran through')
        ->and(array_map(static fn(Delta $d): string => $d->text, $observer->drain()))->toBe(['c']);
});

// The name is the model's text too, and it is stored on the transcript beside the arguments and
// the result. Nothing upstream holds it to the length of a registered tool id: a call the
// registry has no tool for is still recorded, which is what makes the record say what the model
// tried. Unbounded it was stored whole -- a 50,000-character name measured at 51,274 characters
// on the record -- against the same packet budget the arguments are cut to fit.
it('holds a tool name the model invented to NAME_CHARS, marking the cut, and leaves a real one alone', function (): void {
    $agent = agentSubject();
    $observer = new AgentStreamObserver();
    $agent->attach($observer);
    $invented = str_repeat('n', 50_000);
    $agent->notify('agent.tool_call', new ToolCall('c1', $invented, ['text' => 'x']));
    $agent->notify('agent.tool_result', ToolResult::error('no such tool')->withCallId('c1'));
    $agent->notify('agent.tool_call', new ToolCall('c2', 'draft_post', ['title' => 'T']));
    $agent->notify('agent.tool_result', ToolResult::success('ok')->withCallId('c2'));

    $calls = $observer->toolCalls();
    expect(mb_strlen($calls[0]['name']))->toBe(AgentStreamObserver::NAME_CHARS + 1)
        ->and(str_ends_with($calls[0]['name'], '…'))->toBeTrue()
        ->and($calls[0]['name'])->toStartWith(str_repeat('n', AgentStreamObserver::NAME_CHARS))
        ->and($calls[1]['name'])->toBe('draft_post');
});

// A call that never got a result is listed too, so the bound has to be on what is *recorded*,
// not on what a result closes: an invented name with no result behind it is the same text on
// the same transcript.
it('holds an unanswered call\'s invented name to NAME_CHARS as well', function (): void {
    $agent = agentSubject();
    $observer = new AgentStreamObserver();
    $agent->attach($observer);
    $agent->notify('agent.tool_call', new ToolCall('c1', str_repeat('z', 5_000), ['text' => 'x']));

    $calls = $observer->toolCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['ok'])->toBeFalse()
        ->and(mb_strlen($calls[0]['name']))->toBe(AgentStreamObserver::NAME_CHARS + 1);
});
