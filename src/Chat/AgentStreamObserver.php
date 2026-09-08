<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * What the pipeline hears from an agent run: the text and reasoning as they arrive, one record
 * per tool call, and the error an aborted run ended on.
 *
 * The vendored AbstractAgent reports through SplObserver, and its notify() calls update($this)
 * with one argument, leaving the event name and payload on the agent (lastEvent(),
 * lastEventData()); both live on AbstractAgent, not on SplSubject, so anything else is ignored.
 * Events read: `agent.text_delta` and `agent.reasoning` (a string each) become Deltas;
 * `agent.iteration` (an int) marks where a paragraph break goes (separated()); `agent.tool_call`
 * (a ToolCall) opens a record, `agent.tool_result` (a ToolResult) closes it; `agent.error` (a
 * string) is kept as error(). `agent.tool_error` is not read: the agent
 * follows it with a ToolResult of status Error for the same call, so the record is closed by
 * the result and reading both would count the failure twice.
 *
 * Deltas are queued, not delivered. Pipeline::send() is a generator and the agent's loop is
 * not: it calls back into update() from deep inside run(), and a generator cannot yield from
 * inside a callee. So the pipeline runs the agent in a Fiber and drains the queue between the
 * fiber's suspensions (Pipeline::agentTurn() says how), and this observer is where the fiber
 * suspends: on every delta, when the current fiber is the one streamThrough() named. Only that
 * one. A delta raised in some other fiber, or in none (the agent run plainly, as the tests do),
 * is queued and left for drain() after run() returns: suspending a fiber the pipeline is not
 * resuming would leave it hanging, and suspending outside any fiber is a FiberError.
 *
 * A tool call record is `{name, arguments, result_excerpt, ok}`, paired to its result by the
 * result's callId, else to the oldest unanswered call (a provider that sends no ids, or the
 * same id twice, still gets one record per call). `ok` is Success; Error and Timeout are not.
 * Every record is stored on the assistant turn for the life of the conversation and counts
 * against the transcript's packet budget (ConversationStore::save()), so what is stored is
 * bounded: the result to RESULT_CHARS and each string argument to ARGUMENT_CHARS, in
 * characters, with an ellipsis where a cut was made. The full result was for the model and is
 * gone with the run; the arguments are what ran, and the longer bound keeps a URL or a title
 * whole while a drafted post's body, already in wp_posts, is not stored a second time.
 *
 * @since 0.5.0
 */
final class AgentStreamObserver implements \SplObserver
{
    /** The most of a tool's result kept on the record, in characters. */
    public const RESULT_CHARS = 200;

    /** The most of one string argument kept on the record, in characters. */
    public const ARGUMENT_CHARS = 1000;

    /** @var \SplQueue<Delta> */
    private \SplQueue $deltas;

    /**
     * Held weakly: the fiber's callback reaches the agent, the agent holds this observer, and a
     * strong reference back to the fiber would close a cycle that only the cycle collector
     * breaks. The weak reference is one half of what makes a consumer's abandonment release
     * the fiber at once; the other half is push(), which keeps no local of the fiber alive in
     * the fiber's own frames across its suspension. With both, the pipeline's local is the only
     * strong reference, and dropping the generator unwinds the fiber in that same destruction;
     * with either missing, the fiber (and the agent, its conversation and the provider's open
     * stream) lives until the collector next runs. PipelineToolsTest pins the release without
     * gc_collect_cycles().
     *
     * @var \WeakReference<\Fiber<mixed, mixed, mixed, mixed>>|null
     */
    private ?\WeakReference $fiber = null;

    /** The text streamed so far, for the paragraph break between iterations. */
    private string $streamedText = '';

    /** Set at the start of every iteration after the first, spent by that iteration's first text delta. */
    private bool $breakPending = false;

    /** @var list<array{id: string, name: string, arguments: array<string, mixed>}> calls announced and not yet answered, oldest first */
    private array $pending = [];

    /** @var list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool}> */
    private array $toolCalls = [];

    private ?string $error = null;

    public function __construct()
    {
        $this->deltas = new \SplQueue();
    }

    /**
     * The fiber a delta suspends: the one the pipeline resumes after draining.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public function streamThrough(\Fiber $fiber): void
    {
        $this->fiber = \WeakReference::create($fiber);
    }

    public function update(\SplSubject $subject): void
    {
        if (!$subject instanceof AbstractAgent) {
            return;
        }
        $data = $subject->lastEventData();
        switch ($subject->lastEvent()) {
            case 'agent.iteration':
                $this->breakPending = $this->breakPending || (is_int($data) && $data > 1);
                break;
            case 'agent.text_delta':
                $text = is_string($data) ? $data : '';
                if ($this->breakPending && $text !== '') {
                    $text = self::separated($this->streamedText, $text);
                    $this->breakPending = false;
                }
                $this->streamedText .= $text;
                $this->push(new Delta($text));
                break;
            case 'agent.reasoning':
                $this->push(new Delta('', is_string($data) ? $data : ''));
                break;
            case 'agent.tool_call':
                if ($data instanceof ToolCall) {
                    $this->pending[] = ['id' => $data->id, 'name' => $data->name, 'arguments' => self::boundedArguments($data->arguments)];
                }
                break;
            case 'agent.tool_result':
                if ($data instanceof ToolResult) {
                    $this->answer($data);
                }
                break;
            case 'agent.error':
                $this->error = is_string($data) ? $data : null;
                break;
        }
    }

    /**
     * The deltas queued since the last drain, in arrival order.
     *
     * @return list<Delta>
     */
    public function drain(): array
    {
        $out = [];
        while (!$this->deltas->isEmpty()) {
            $out[] = $this->deltas->dequeue();
        }
        return $out;
    }

    /**
     * Every tool call heard, in the order their results arrived; a call still unanswered (the
     * run was cut off, or cancelled, before its result) is listed last as not ok with nothing
     * to show, so the record still says it was made.
     *
     * @return list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool}>
     */
    public function toolCalls(): array
    {
        $out = $this->toolCalls;
        foreach ($this->pending as $call) {
            $out[] = ['name' => $call['name'], 'arguments' => $call['arguments'], 'result_excerpt' => '', 'ok' => false];
        }
        return $out;
    }

    /** The message of the last `agent.error`, which is what an Error finish ended on; null when none fired. */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * `$text` as the continuation of `$streamed` when the two came from different model
     * messages: a paragraph break between them unless `$streamed` is empty or already ends a
     * line. The agent's iterations are separate assistant messages in its conversation ("Let
     * me check." with a tool call, then the answer), and the stored reply is one message, so
     * the join is where the boundary is kept; without it the two run together mid-word.
     */
    public static function separated(string $streamed, string $text): string
    {
        return $streamed === '' || str_ends_with($streamed, "\n") ? $text : "\n\n" . $text;
    }

    /**
     * Queues the delta and, inside the streamed fiber, suspends. The identity check is a call of
     * its own so that no local holding the fiber is alive in this frame across the suspension:
     * a suspended fiber keeps its frames, and a strong reference to itself in one of them is a
     * cycle (the fiber holds its stack, the stack holds the fiber) that only the cycle collector
     * breaks. With the check's frame gone before suspend() runs, the pipeline's own local is the
     * last strong reference, and dropping the generator unwinds the fiber then and there.
     */
    private function push(Delta $delta): void
    {
        $this->deltas->enqueue($delta);
        if ($this->insideStreamedFiber()) {
            \Fiber::suspend();
        }
    }

    /** Whether the running fiber is the one streamThrough() named; false outside any fiber. */
    private function insideStreamedFiber(): bool
    {
        $fiber = $this->fiber?->get();
        return $fiber !== null && \Fiber::getCurrent() === $fiber;
    }

    private function answer(ToolResult $result): void
    {
        $index = 0;
        if ($result->callId !== null) {
            foreach ($this->pending as $i => $call) {
                if ($call['id'] === $result->callId) {
                    $index = $i;
                    break;
                }
            }
        }
        $call = $this->pending[$index] ?? null;
        if ($call === null) {
            return;
        }
        array_splice($this->pending, $index, 1);
        $this->toolCalls[] = [
            'name' => $call['name'],
            'arguments' => $call['arguments'],
            'result_excerpt' => self::bounded($result->content, self::RESULT_CHARS),
            'ok' => $result->status === ToolResultStatus::Success,
        ];
    }

    /**
     * The arguments with every string, at any depth, held to ARGUMENT_CHARS; anything else as is.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function boundedArguments(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (is_string($value)) {
                $arguments[$key] = self::bounded($value, self::ARGUMENT_CHARS);
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $arguments[$key] = self::boundedArguments($value);
            }
        }
        return $arguments;
    }

    private static function bounded(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? mb_substr($text, 0, $chars) . '…' : $text;
    }
}
