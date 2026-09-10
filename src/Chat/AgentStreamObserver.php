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
 * (a ToolCall) opens a record and queues an empty Delta, the heartbeat (below);
 * `agent.tool_result` (a ToolResult) closes the record; `agent.error` (a string) is kept as
 * error(). `agent.tool_error` is not read: the agent follows it with a ToolResult of status
 * Error for the same call, so the record is closed by the result and reading both would count
 * the failure twice.
 *
 * The heartbeat is for the consumer that has gone. The stream route keeps its process running
 * when the client disconnects (Rest\Sse) and learns of it only from a write that fails, which
 * it tries after every delta. An iteration that calls a tool with no text before it gives it
 * nothing to write, so the tool's side effect (a draft) would land with the tab closed, and a
 * chain of such iterations would run to the budget that way. An empty delta before each call
 * is a frame to write and so a moment to notice; a consumer that drops the generator then
 * unwinds the fiber before the tool runs, and the record lists that call as made and not
 * answered. Every consumer sees the empty delta; each already ignores an empty one.
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
 * against the transcript's packet budget (ConversationStore::save()), so every part of it the
 * model chose is bounded, in characters, with an ellipsis where a cut was made: the result to
 * RESULT_CHARS, the tool's name to NAME_CHARS, each string argument to ARGUMENT_CHARS, and the
 * arguments record as a whole to ARGUMENTS_CHARS over its keys and values at every depth
 * (boundedArguments()). A record is therefore never larger than the sum of those three
 * ceilings plus its marks, whatever the model put in the call — the name included, which was
 * unbounded until 0.5.0 and stored whole (a 50,000-character name measured at 51,274 chars on
 * the record). The full result was for the model and is gone with the run; the arguments are
 * what ran, and the per-string bound keeps a URL or a title whole while a drafted post's body,
 * already in wp_posts, is not stored a second time.
 *
 * @since 0.5.0
 */
final class AgentStreamObserver implements \SplObserver
{
    /** The most of a tool's result kept on the record, in characters. */
    public const RESULT_CHARS = 200;

    /**
     * The most of the tool's name kept on the record, in characters. The name is the model's
     * text, not the registry's: a call the registry has no tool for is still recorded (that is
     * what makes the record say what the model tried), so nothing upstream of here holds it to
     * the length of a real tool id. Well past any name a toolkit registers — the longest the
     * plugin ships is `draft_post`, at ten — and short enough that the ellipsis says plainly
     * that this was not one.
     */
    public const NAME_CHARS = 100;

    /** The most of one string argument kept on the record, in characters. */
    public const ARGUMENT_CHARS = 1000;

    /**
     * The most of a whole arguments record kept, in characters over its keys and values: room
     * for four full-length strings, which no shipped tool's call comes near (draft_post's title,
     * body and type are one and a bit), against a transcript budget in the megabytes.
     */
    public const ARGUMENTS_CHARS = 4000;

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

    /**
     * A paragraph break owed to the reader. Set at the start of every iteration after the first
     * and never cleared at an iteration boundary (update() only ORs it on), so an iteration that
     * calls tools and writes nothing carries it forward. It is spent by the next *non-empty*
     * text delta, whichever iteration that turns up in; the empty heartbeat delta pushed before
     * a tool call does not spend it. So the break lands where text resumes, rather than being
     * swallowed by a silent iteration and leaving two replies run together.
     */
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
                    $this->pending[] = ['id' => $data->id, 'name' => self::bounded($data->name, self::NAME_CHARS), 'arguments' => self::boundedArguments($data->arguments)];
                    // The heartbeat: an empty delta, so the pipeline yields (and a streaming
                    // transport writes) something before the tool runs. The event fires before
                    // any tool of the iteration executes, which is the moment that matters.
                    $this->push(new Delta(''));
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
     * The arguments as the record keeps them: every string held to ARGUMENT_CHARS, and the
     * record as a whole to ARGUMENTS_CHARS. The whole-record bound is the one that matters for
     * storage; a bound on each string alone leaves a tool taking a long list of short strings,
     * or a model inventing keys, storing as much as it sent.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function boundedArguments(array $arguments): array
    {
        $budget = self::ARGUMENTS_CHARS;
        /** @var array<string, mixed> */
        return self::boundedRecord($arguments, $budget);
    }

    /**
     * One level of the record against the budget it shares with every other level. Each key
     * and each scalar value spends its characters; a string is cut to what remains of the
     * budget (and never past ARGUMENT_CHARS); an array recurses on the same budget. Once the
     * budget is spent, the entries that follow at that level are dropped and an ellipsis entry
     * stands in their place, so the cut is visible where it was made, as it is for a string.
     * Entries are kept in the order sent, so the leading arguments (a title, a URL) survive a
     * cut made by a large trailing one.
     *
     * @param array<mixed> $record
     * @return array<mixed>
     */
    private static function boundedRecord(array $record, int &$budget): array
    {
        $out = [];
        foreach ($record as $key => $value) {
            if ($budget <= 0) {
                $out[is_int($key) ? $key : '…'] = '…';
                break;
            }
            $budget -= mb_strlen((string) $key);
            if (is_string($value)) {
                $value = self::bounded($value, max(0, min(self::ARGUMENT_CHARS, $budget)));
                $budget -= mb_strlen($value);
            } elseif (is_array($value)) {
                $value = self::boundedRecord($value, $budget);
            } else {
                $budget -= mb_strlen((string) wp_json_encode($value));
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private static function bounded(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? mb_substr($text, 0, $chars) . '…' : $text;
    }
}
