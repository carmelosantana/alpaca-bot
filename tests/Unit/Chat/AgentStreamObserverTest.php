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
    // The tool call event queued nothing and so suspended nothing: the fiber ran on to 'b'.
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
