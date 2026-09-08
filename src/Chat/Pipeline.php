<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Admin\Assets;
use AlpacaBot\Context\Collector;
use AlpacaBot\Context\Context;
use AlpacaBot\Provider\BoundOptionsProvider;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\Model;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Agent\Output;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\MessageInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\AssistantMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\Conversation as AgentConversation;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;

/**
 * Turns one user message into a streamed model reply: enforces the caps, resolves the model and
 * the system prompt, folds in site context, streams the provider, then persists the conversation
 * and the usage receipt. Every consumer (CLI, REST, abilities) goes through here.
 *
 * Hooks, in firing order: filter `alpaca_bot/message/before_send` (string text, Conversation,
 * options) -> filter `alpaca_bot/system_prompt` (string, Conversation, model) -> action
 * `alpaca_bot/chat/started` (Conversation, model) -> the deltas -> filter
 * `alpaca_bot/message/after_receive` (Message reply, Conversation) -> action
 * `alpaca_bot/chat/completed` (Result).
 *
 * That order differs from the plan's one-line hook list, which put `system_prompt` first: here
 * `before_send` runs first and the (filtered) user turn is appended to the conversation before
 * `system_prompt` fires, so a system-prompt filter sees the message it is prompting for. Under
 * the plan's order it would have seen a conversation with no turn yet.
 *
 * A provider failure fires action `alpaca_bot/chat/failed` (Throwable, Conversation) instead of
 * the last two, and nothing is persisted: the post a new conversation was given before the
 * provider was called is taken back once the action has fired, so a failed first turn leaves no
 * empty "New chat" in the history. A conversation the caller passed in is never deleted, and
 * neither is one a `chat/failed` listener has meanwhile saved a turn onto (the check is on what
 * is stored: ConversationStore::deleteIfEmpty()). The same applies to a turn refused after the
 * post exists (`before_send` blanking the message), which throws without `chat/failed`. A
 * consumer that stops iterating before the stream ends (a client disconnecting mid-stream)
 * also fires `chat/failed`, with a RuntimeException saying so, but only after the partial
 * reply has been stored (meta `partial: true`) and a receipt recorded for the time spent and
 * whatever usage had arrived; that conversation is not empty and stays. `after_receive` and
 * `completed` are for finished turns and do not fire.
 *
 * `send()` is a generator, so nothing at all runs until the caller first advances it: the cap
 * check, the conversation lookup and every hook happen on the first iteration, not on the call.
 *
 * An `ephemeral` turn (Toolkit\SummarizeToolkit's inner call) runs the same path over a
 * conversation that exists only in memory: no post is created, no transcript or partial reply
 * is written, and nothing is taken back on failure because nothing was made. The usage receipt
 * is still recorded, with conversation 0 (UsageMeter::record() already allows that), and every
 * hook fires as for any turn: the tokens were spent, the caps count them, and a listener that
 * meters or logs sees the turn. Not a pipeline of its own, and not `privacy.save_history`
 * off: that setting is the user's choice about their history, and it still updates a
 * conversation that already has a post; ephemeral is the caller's statement that this turn is
 * not part of anyone's history at all, whatever the setting says.
 *
 * A tool turn: when the registry enables a toolkit for the user and the catalogue says the
 * model can call tools, the reply is produced by the Assistant agent (the vendored tool loop)
 * instead of one provider stream, and the branch is exactly that: everything else on the way
 * in and out (the caps, the hooks, the receipt, what is stored, what an abandoned or failed
 * turn leaves behind) is the same code. The model gets the same generation options a plain
 * turn sends (Provider\BoundOptionsProvider), the text streams as it is produced (agentTurn()
 * says how), and the stored reply carries `meta['tool_calls']`, one `{name, arguments,
 * result_excerpt, ok}` per call. The usage metered is the whole run: Output::$usage is the sum
 * over every provider call the agent made, so a turn that took three calls is billed three
 * calls' tokens against the cap. An ephemeral turn runs plainly, tools or no tools: its one
 * caller is a tool wanting a text condensed, and an agent inside a tool inside an agent would
 * be a recursion with no bound but each level's iteration budget.
 */
final class Pipeline
{
    public function __construct(
        private Store $store,
        private Factory $factory,
        private ModelCatalog $catalog,
        private ConversationStore $conversations,
        private UsageMeter $meter,
        private CapPolicy $caps,
        private Collector $collector,
        private ?UserPrefs $prefs = null,
        private ?Registry $toolkits = null,
    ) {}

    /**
     * send() drained: the Result once the whole reply is in.
     *
     * @param array{conversation_id?: int, model?: string, images?: string[], context?: array<string, mixed>, system?: string, ephemeral?: bool} $options see send()
     * @throws CapExceeded|\InvalidArgumentException|\RuntimeException as send()
     */
    public function complete(int $userId, string $text, array $options = []): Result
    {
        $gen = $this->send($userId, $text, $options);
        foreach ($gen as $_) {
        }
        return $gen->getReturn();
    }

    /**
     * Streams the reply to `$text` as Deltas; the generator's return value is the Result.
     *
     * `$userId` must be an authenticated user's id and is the only identity used anywhere: for
     * the cap, for conversation ownership, and for what the context sources may read on the
     * user's behalf. Nothing in `$options` can stand in for it. Authentication and capability
     * checks (may this user chat at all?) belong to the caller; the pipeline trusts the id it
     * is given and does not consult the current user.
     *
     * The caller owns draining: iterate to exhaustion, then read `getReturn()`. Dropping the
     * generator early is handled (see the class docblock) but is a failed turn, not a finished
     * one, and a partial reply is what gets stored.
     *
     * Options: `conversation_id` continues a conversation the user owns (an id that is missing
     * or not theirs is refused, not silently replaced by a new one); `model` is honoured only
     * while `chat.user_can_change_model` is on, else the default applies, and a model the
     * catalog lists other models but not this one is refused rather than swapped for the
     * default (an empty catalog, which is also what an unreachable provider reads as, refuses
     * nothing: the provider call then fails loudly instead); `images` are base64 image data
     * URLs (ImageData::isValid()) attached to the user turn and are sent verbatim: anything
     * else is refused, since a fetchable URL would be retrieved by the model host and rendered
     * later by a history screen, the pipeline never reads the filesystem on a caller's behalf,
     * and a data URL of any other type would be stored and replayed to the provider on every
     * later turn without ever being shown; their decoded bytes together may not exceed
     * Admin\Assets::maxImageBytes() (images()); `context` is the request the context sources
     * see (a post id, a screen); `system` replaces the configured system prompt for this turn;
     * `ephemeral` keeps no conversation (the class docblock), and is refused together with a
     * `conversation_id`, since a turn cannot both continue a conversation and leave no trace.
     *
     * The cap check is check-then-act with no reservation: concurrent requests from one user can
     * overshoot a cap by roughly their number. It is a monthly budget, not a hard ceiling.
     *
     * @param array{conversation_id?: int, model?: string, images?: string[], context?: array<string, mixed>, system?: string, ephemeral?: bool} $options
     * @return \Generator<int, Delta, mixed, Result>
     * @throws \InvalidArgumentException for an empty message (also one `before_send` blanked), an image that is not a base64 image data URL or a set of them past the site's allowance, a requested model a non-empty catalog does not list, a conversation the user does not own, or `ephemeral` with a `conversation_id`
     * @throws CapExceeded before any provider call
     * @throws \RuntimeException when no model can be resolved, or wrapping a provider failure as 'Provider error: ...'
     */
    public function send(int $userId, string $text, array $options = []): \Generator
    {
        $text = trim($text);
        $images = self::images((array) ($options['images'] ?? []));
        if ($text === '' && $images === []) {
            throw new \InvalidArgumentException(__('The message is empty.', 'alpaca-bot'));
        }
        $requested = (int) ($options['conversation_id'] ?? 0);
        $ephemeral = (bool) ($options['ephemeral'] ?? false);
        if ($ephemeral && $requested > 0) {
            throw new \InvalidArgumentException(__('An ephemeral turn cannot continue a conversation.', 'alpaca-bot'));
        }
        $this->caps->assertAllowed($userId);
        $model = $this->model($userId, $options);
        $conversation = $ephemeral ? $this->ephemeral($userId) : $this->conversation($userId, $requested);
        // From here the conversation has its post (when saving is on). Nothing is written to it
        // until the turn finishes, or the consumer abandons the stream (settle(), which does not
        // throw), so any exception out of this block is a turn that stored nothing: the post
        // made for it, if this turn made it, is taken back on the way out.
        try {
            $text = trim((string) apply_filters('alpaca_bot/message/before_send', $text, $conversation, $options));
            if ($text === '' && $images === []) {
                throw new \InvalidArgumentException(__('The message is empty.', 'alpaca-bot'));
            }
            $conversation->append(new Message('user', $text, $model, null, 0, $images));
            $contexts = $this->collector->collect($userId, (array) ($options['context'] ?? []));
            $messages = $this->buildMessages($conversation, $model, $contexts, isset($options['system']) ? (string) $options['system'] : null);
            $generation = $this->store->modelOverrides($model);
            $providerOptions = [
                'temperature' => (float) $generation['temperature'],
                'num_ctx' => (int) $generation['num_ctx'],
                'keep_alive' => (string) $generation['keep_alive'],
            ];
            $toolkits = $ephemeral ? [] : $this->toolkitsFor($userId, $model);
            $observer = null;

            do_action('alpaca_bot/chat/started', $conversation, $model);
            $started = microtime(true);
            $content = '';
            $reasoning = '';
            $prompt = 0;
            $completion = 0;
            // Set once the provider stream has ended, by exhaustion or by throwing. Still false in
            // the finally means the generator was destroyed while suspended at a yield: the
            // consumer stopped iterating, and PHP runs the finally on destruction.
            $streamEnded = false;
            try {
                $provider = $this->factory->make($model);
                if ($toolkits !== []) {
                    $observer = new AgentStreamObserver();
                    $turn = $this->agentTurn(new BoundOptionsProvider($provider, $providerOptions), $toolkits, $messages, $observer);
                    foreach ($turn as $delta) {
                        $content .= $delta->text;
                        $reasoning .= $delta->reasoning;
                        yield $delta;
                    }
                    $usage = $turn->getReturn()->usage;
                    $prompt = $usage === null ? 0 : $usage->promptTokens;
                    $completion = $usage === null ? 0 : $usage->completionTokens;
                } else {
                    // The vendored stream() marks text and reasoning deltas with finishReason Stop
                    // too, so Stop says nothing about the end of the stream: the generator is
                    // drained to exhaustion. Usage rides on the final stop chunk or on a usage-only
                    // chunk after it (both with empty content); whichever chunk carries it, it is
                    // taken from there.
                    foreach ($provider->stream($messages, [], $providerOptions) as $chunk) {
                        if ($chunk->usage !== null) {
                            $prompt = max($prompt, $chunk->usage->promptTokens);
                            $completion = max($completion, $chunk->usage->completionTokens);
                        }
                        if ($chunk->content === '' && $chunk->reasoning === '') {
                            continue;
                        }
                        $content .= $chunk->content;
                        $reasoning .= $chunk->reasoning;
                        yield new Delta($chunk->content, $chunk->reasoning);
                    }
                }
                $streamEnded = true;
            } catch (\Throwable $e) {
                $streamEnded = true;
                try {
                    do_action('alpaca_bot/chat/failed', $e, $conversation);
                } finally {
                    // Thrown from the finally so a listener that throws cannot replace the
                    // provider failure with its own exception: a caller maps what arrives by
                    // class (ChatController reads an InvalidArgumentException as the user's
                    // mistake, 400). PHP chains a pending exception behind the one thrown here,
                    // so a listener's is kept, after $e.
                    throw new \RuntimeException('Provider error: ' . $e->getMessage(), 0, $e);
                }
            } finally {
                $this->settle($streamEnded, $ephemeral, $userId, $conversation, $model, $content, $reasoning, $prompt, $completion, $started, $observer?->toolCalls() ?? []);
            }
        } catch (\Throwable $e) {
            // After chat/failed (the inner catch), so a listener saw the conversation and had
            // the chance to save it; deleteIfEmpty() then leaves a saved one alone. A
            // conversation the caller named was theirs before this turn and stays theirs.
            if ($requested <= 0 && $conversation->id > 0) {
                $this->conversations->deleteIfEmpty($conversation->id, $userId);
            }
            throw $e;
        }
        $durationMs = self::elapsedMs($started);

        // The duration rides on the stored reply as well as the receipt, so a reloaded
        // transcript shows the same `model · tokens · seconds` line a live turn did.
        $reply = new Message(
            'assistant',
            $content,
            $model,
            ['prompt_tokens' => $prompt, 'completion_tokens' => $completion],
            0,
            [],
            ['duration_ms' => $durationMs] + ($reasoning !== '' ? ['reasoning' => $reasoning] : []) + self::toolCallsMeta($observer?->toolCalls() ?? []),
        );
        $filtered = apply_filters('alpaca_bot/message/after_receive', $reply, $conversation);
        if ($filtered instanceof Message) {
            $reply = $filtered;
        }
        $conversation->append($reply);
        if (!$ephemeral) {
            $this->conversations->save($conversation);
        }
        $logId = $this->meter->record($userId, $model, $prompt, $completion, $durationMs, $conversation->id);
        $result = new Result($conversation, $reply, [
            'user_id' => $userId,
            'model' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'duration_ms' => $durationMs,
            'conversation_id' => $conversation->id,
            'log_id' => $logId,
            'created' => $reply->created,
        ], $contexts);
        do_action('alpaca_bot/chat/completed', $result);
        return $result;
    }

    /**
     * The provider messages for a conversation: the system prompt (resolved here, through
     * `alpaca_bot/system_prompt`, with the context block appended) followed by every turn of
     * the transcript. send() builds its messages through this, once per turn.
     *
     * @param Context[] $contexts
     * @return MessageInterface[]
     */
    public function buildMessages(Conversation $c, string $model, array $contexts, ?string $systemOverride = null): array
    {
        return $this->assemble($c, $contexts, $this->systemPrompt($c, $model, $systemOverride));
    }

    /**
     * Runs from send()'s finally. When the stream ended (drained, or the provider threw) there
     * is nothing to do: the normal path or the catch owns the outcome. When it did not, the
     * generator was destroyed while suspended at a yield, which means the consumer walked away
     * mid-stream: store what the user already saw, marked partial, bill the time and whatever
     * usage had arrived (usually none: it comes last), and say so on `chat/failed`.
     *
     * The branch lives here rather than in the finally itself because static analysis does not
     * model generator destruction and reads the flag as always true at that point.
     *
     * An ephemeral turn stores no partial reply either (there is no post to store it on), but
     * the receipt and `chat/failed` are the same: the time was spent whether or not anyone
     * keeps the transcript.
     *
     * A tool turn's partial reply carries the calls made before the consumer left
     * (`meta['tool_calls']`): a draft the run created exists whether or not the reply was
     * finished, and the record is how the transcript says so.
     *
     * @param list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool}> $toolCalls
     */
    private function settle(bool $streamEnded, bool $ephemeral, int $userId, Conversation $conversation, string $model, string $content, string $reasoning, int $prompt, int $completion, float $started, array $toolCalls = []): void
    {
        if ($streamEnded) {
            return;
        }
        $durationMs = self::elapsedMs($started);
        $conversation->append(new Message(
            'assistant',
            $content,
            $model,
            ['prompt_tokens' => $prompt, 'completion_tokens' => $completion],
            0,
            [],
            // duration_ms as the finished path stores it: the usage is non-null, so a reloaded
            // transcript shows a receipt for this turn too, and that receipt reads the seconds.
            ['partial' => true, 'duration_ms' => $durationMs] + ($reasoning !== '' ? ['reasoning' => $reasoning] : []) + self::toolCallsMeta($toolCalls),
        ));
        if (!$ephemeral) {
            $this->conversations->save($conversation);
        }
        $this->meter->record($userId, $model, $prompt, $completion, $durationMs, $conversation->id);
        do_action(
            'alpaca_bot/chat/failed',
            new \RuntimeException('The stream was abandoned by the consumer before the reply finished.'),
            $conversation,
        );
    }

    /**
     * The toolkits this turn runs with: what the registry enables for the user, provided the
     * catalogue lists the model as able to call tools; none when either says no. The capability
     * gate is on the catalogue's word (Provider\Model::$tools: the name heuristic raised by what
     * `/api/show` reported), not the provider's: sending tools to a model that cannot take them
     * is a provider error on every turn, and a model the catalogue does not list at all (an
     * unreachable provider reads as an empty catalogue) is not given tools, so its turn fails
     * or succeeds the way a plain turn would in the same outage. The registry is asked on every
     * turn, never at boot, so a setting saved this request applies to the next turn.
     *
     * @return array<string, ToolkitInterface>
     */
    private function toolkitsFor(int $userId, string $model): array
    {
        $toolkits = $this->toolkits?->enabled($userId) ?? [];
        if ($toolkits === []) {
            return [];
        }
        $listed = $this->catalog->find($model);
        return $listed !== null && $listed->tools ? $toolkits : [];
    }

    /**
     * One tool turn: the Assistant run over the enabled toolkits, its text and reasoning
     * yielded as Deltas while it runs, the Output returned when it is done.
     *
     * The agent's loop (AbstractAgent::run()) is a plain method that reports through an
     * observer callback, and this method is a generator: a generator cannot yield from inside a
     * callback, so the run happens inside a Fiber and the observer (AgentStreamObserver) is the
     * bridge. Each delta the agent announces is queued there and suspends the fiber; control
     * comes back here, the queue is drained and yielded, and the fiber is resumed to produce the
     * next. The order is what matters: drain after every return from start()/resume() and
     * before the next resume(). Draining only once the fiber has terminated would deliver the
     * whole reply after the last tool ran, which is the non-streaming reply the design rejects;
     * resuming before draining would run the next iteration, tool calls included, while the
     * text the user should already be reading sits in the queue, so a tool's side effect (a
     * draft created) would land before the sentence announcing it was shown. The drain after
     * termination is for deltas announced with no suspension (a delta raised outside the
     * fiber), so nothing queued is ever dropped.
     *
     * Rejected: reimplementing the loop as a generator in this plugin (a copy of the vendored
     * loop, drifting from its tool pairing repair, its batching and its empty-reply handling as
     * the library moves), and running the agent to completion before yielding (no streaming).
     * The fiber's cost is one stack per tool turn, and one rule: a consumer that abandons the
     * generator while the fiber is suspended must release it. It does, deterministically: the
     * fiber is a local here, the observer holds it weakly, so destroying the generator drops
     * the last reference and PHP unwinds the fiber, running the run's finally blocks and
     * closing the provider's stream. settle() has already stored the partial reply by then.
     *
     * What the agent cannot stream is yielded here after the run: an answer given through its
     * `done` tool, reasoning surfaced as the answer when a thinking model wrote no text, and a
     * translated line for a run that ended on its iteration budget or on silence. An Error
     * finish (the provider threw; the agent swallows that and says so in the Output) is raised
     * again as the RuntimeException send() expects, carrying the provider's own message, so a
     * failed tool turn fires `chat/failed` and persists nothing, as a failed plain turn does.
     *
     * @param array<string, ToolkitInterface> $toolkits
     * @param MessageInterface[] $messages as buildMessages() built them: the system message, if any, then the turns, the one being sent last
     * @return \Generator<int, Delta, mixed, Output>
     * @throws \RuntimeException when the run ended on a provider failure
     */
    private function agentTurn(ProviderInterface $provider, array $toolkits, array $messages, AgentStreamObserver $observer): \Generator
    {
        $system = '';
        if (($messages[0] ?? null) instanceof SystemMessage) {
            $system = $messages[0]->content();
            array_shift($messages);
        }
        $input = array_pop($messages) ?? new UserMessage('');
        $history = new AgentConversation();
        foreach ($messages as $message) {
            $history->add($message);
        }
        $agent = new Assistant($provider, $system);
        foreach ($toolkits as $toolkit) {
            $agent->addToolkit($toolkit);
        }
        $agent->attach($observer);

        /** @var \Fiber<mixed, mixed, mixed, mixed> $fiber */
        $fiber = new \Fiber(static fn(): Output => $agent->run($input, $history));
        $observer->streamThrough($fiber);
        $streamed = '';
        $fiber->start();
        while (true) {
            foreach ($observer->drain() as $delta) {
                $streamed .= $delta->text;
                yield $delta;
            }
            if ($fiber->isTerminated()) {
                break;
            }
            $fiber->resume();
        }
        $output = $fiber->getReturn();
        if (!$output instanceof Output) {
            throw new \LogicException('The agent run returned no Output.');
        }
        $tail = match ($output->finishReason) {
            AgentFinishReason::Error => throw new \RuntimeException($observer->error() ?? $output->content),
            AgentFinishReason::Stop, AgentFinishReason::Done => $output->content !== '' && !str_ends_with($streamed, $output->content) ? $output->content : '',
            AgentFinishReason::MaxIterations, AgentFinishReason::BudgetExhausted => __('The assistant ran out of tool steps before it finished.', 'alpaca-bot'),
            AgentFinishReason::EmptyResponse => __('The model gave no answer.', 'alpaca-bot'),
        };
        if ($tail !== '') {
            yield new Delta(AgentStreamObserver::separated($streamed, $tail));
        }
        return $output;
    }

    /**
     * `tool_calls` for a reply's meta: present only when a call was made, so a plain turn's
     * meta is exactly what it was before tools existed.
     *
     * @param list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool}> $toolCalls
     * @return array{tool_calls?: list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool}>}
     */
    private static function toolCallsMeta(array $toolCalls): array
    {
        return $toolCalls === [] ? [] : ['tool_calls' => $toolCalls];
    }

    /**
     * Wall-clock milliseconds since `$started`, as seen by this process: for a streaming
     * consumer that includes whatever it spent between deltas (client backpressure, flushing),
     * not just the provider's own time.
     */
    private static function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * The image attachments, held to two things at intake. Every entry must be a base64 image
     * data URL (ImageData::isValid(): PNG, JPEG, GIF or WebP, base64 alphabet, nothing after
     * it). A bare `data:` prefix was the whole check before this, and the render side's
     * stricter pattern was the only thing that kept a `data:text/html,...` off the screen; it
     * was still stored and sent to the provider on every later turn. And their decoded bytes
     * together may not exceed Admin\Assets::maxImageBytes(), the same figure image.ts holds
     * one `Blob.size` to, so the server and the browser count in the same units.
     *
     * The allowance is a per-message total, not a per-image one, and that is deliberate: it is
     * what keeps this cap coherent with the storage budget ConversationStore::save() applies,
     * since the transcript is cumulative and the largest turn is what the budget has to absorb.
     * One image at exactly the cap is still accepted, so the total can never be tighter than
     * the single-image cap the browser already enforces. A `maxImageBytes()` of 0 is "no
     * limit" (post_max_size=0, or one the body allowance alone exhausts) and the total check
     * is skipped: there is no figure to hold the total to. The allowance is only read when
     * there is an image to hold to it, so a text turn costs no ini lookup.
     *
     * @param array<mixed> $images
     * @return list<string>
     * @throws \InvalidArgumentException for anything that is not a base64 image data URL, or a set of them past the allowance
     */
    private static function images(array $images): array
    {
        $out = [];
        $total = 0;
        foreach ($images as $image) {
            if (!is_string($image) || !ImageData::isValid($image)) {
                throw new \InvalidArgumentException(__('Images must be base64 PNG, JPEG, GIF, or WebP data URLs.', 'alpaca-bot'));
            }
            $total += ImageData::decodedBytes($image);
            $out[] = $image;
        }
        if ($out === []) {
            return $out;
        }
        $max = Assets::maxImageBytes();
        if ($max > 0 && $total > $max) {
            throw new \InvalidArgumentException(sprintf(
                /* translators: 1: the decoded size of the attached images together, in bytes; 2: the most this site takes per message, in bytes */
                __('Those images total %1$s bytes; this site takes up to %2$s bytes per message. Attach fewer or smaller images.', 'alpaca-bot'),
                number_format_i18n($total),
                number_format_i18n($max),
            ));
        }
        return $out;
    }

    /**
     * A conversation to append to: the user's existing one, or a fresh one when no id is given.
     *
     * @throws \InvalidArgumentException when the id names no conversation of the user's
     */
    private function conversation(int $userId, int $id): Conversation
    {
        if ($id <= 0) {
            return $this->conversations->create($userId);
        }
        $conversation = $this->conversations->load($id, $userId);
        if ($conversation === null) {
            /* translators: %d: conversation id */
            throw new \InvalidArgumentException(sprintf(__('Conversation %d was not found.', 'alpaca-bot'), $id));
        }
        return $conversation;
    }

    /**
     * The conversation an ephemeral turn runs over: the user's, in memory only, id 0 as
     * ConversationStore::create() returns one when saving is off. Built here rather than
     * through the store so the store is never asked: create() would insert a post while
     * `privacy.save_history` is on, and that post is exactly what an ephemeral turn must not
     * leave behind.
     */
    private function ephemeral(int $userId): Conversation
    {
        return new Conversation(0, $userId, '', [], 'chat', (int) current_time('timestamp', true));
    }

    /**
     * The model for this turn: the caller's choice while users may change model, provided the
     * catalog lists it (the catalog is the allow-list: `alpaca_bot/models` has run over it and
     * embedding models are already out); else the user's stored default (UserPrefs::modelFor(),
     * under the same setting and the same catalog check, when the pipeline was given the
     * preferences: the CLI is not); else the catalog's default, which is never '' as long as the
     * provider lists anything.
     *
     * The allow-list only applies when there is one. ModelCatalog reads a provider it cannot
     * reach as an empty, uncached list, indistinguishable from a provider that lists nothing,
     * so an empty catalog cannot refuse anything: the requested model goes through and the
     * provider call fails loudly (`Provider error: ...`, `chat/failed`), the same way the
     * default-model path fails in the same outage. Refusing here would report an outage as a
     * client error naming their model. A non-empty catalog without the id is the real refusal.
     *
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException when the catalog lists models and the requested one is not among them
     * @throws \RuntimeException when no model is configured and the provider lists none
     */
    private function model(int $userId, array $options): string
    {
        $requested = trim((string) ($options['model'] ?? ''));
        if ($requested !== '' && (bool) $this->store->get('chat.user_can_change_model')) {
            $listed = array_map(static fn(Model $m): string => $m->id, $this->catalog->all());
            if ($listed !== [] && !in_array($requested, $listed, true)) {
                /* translators: %s: model id */
                throw new \InvalidArgumentException(sprintf(__('Model "%s" is not available.', 'alpaca-bot'), $requested));
            }
            return $requested;
        }
        $model = $this->prefs?->modelFor($userId, $this->catalog, $this->store) ?? $this->catalog->defaultId($this->store);
        if ($model === '') {
            throw new \RuntimeException(__('No model is configured and the provider lists none.', 'alpaca-bot'));
        }
        return $model;
    }

    /**
     * The system prompt for a turn, through `alpaca_bot/system_prompt`: the caller's override,
     * else the model's `system` override, else `chat.system_prompt`. An empty result means no
     * system message unless there is context to carry.
     */
    private function systemPrompt(Conversation $c, string $model, ?string $override): string
    {
        $system = $override ?? (string) ($this->store->modelOverrides($model)['system'] ?? '');
        if ($system === '') {
            $system = (string) $this->store->get('chat.system_prompt');
        }
        return (string) apply_filters('alpaca_bot/system_prompt', $system, $c, $model);
    }

    /**
     * The system text plus the context block, then the transcript, bounded to the most recent
     * `chat.context_messages` turns (the one being sent included; 0 sends everything). The
     * bound is on what goes to the model, never on what is stored: the conversation keeps every
     * turn. Without it every turn re-sends the whole transcript, the model host truncates to
     * num_ctx from the oldest end without saying so, and the prompt tokens billed against the
     * monthly cap grow to num_ctx on every request. The slice never touches the system message,
     * which is built here, not stored. Stored roles other than user/assistant/system (a `tool`
     * turn, which nothing writes yet) are left out rather than sent as the wrong role.
     *
     * The context block has no ceiling here: every source bounds its own text, and the sources
     * are code the site registered, so the site's own `alpaca_bot/context` filter is where a
     * total budget belongs; cutting the block blind would drop context nobody chose to drop.
     *
     * @param Context[] $contexts
     * @return MessageInterface[]
     */
    private function assemble(Conversation $c, array $contexts, string $system): array
    {
        $block = Collector::asSystemBlock($contexts);
        $systemText = trim($system . ($block !== '' ? "\n\n" . $block : ''));
        $out = [];
        if ($systemText !== '') {
            $out[] = new SystemMessage($systemText);
        }
        $limit = (int) $this->store->get('chat.context_messages');
        foreach ($limit > 0 ? array_slice($c->messages, -$limit) : $c->messages as $m) {
            $out[] = match ($m->role) {
                'user' => self::userMessage($m),
                'assistant' => new AssistantMessage($m->content),
                'system' => new SystemMessage($m->content),
                default => null,
            };
        }
        return array_values(array_filter($out));
    }

    /**
     * A user turn in the OpenAI content-parts shape the vendored providers send when images are
     * attached (`UserMessage::withImages()` builds the same parts, from files); plain text
     * otherwise. An images-only turn carries no text part: some providers reject an empty one.
     *
     * Only images that meet ImageData's contract are replayed. images() holds a new turn to it,
     * but a turn stored before that check existed may carry a `data:text/html,...` or a path
     * (0.4 stored media paths) under `images`, and replaying it would send it to the provider
     * on every later turn while MessageBubble never shows it: the defect the intake check was
     * added for, still there for old rows. The stored transcript is not rewritten; a turn left
     * with nothing to send is sent as its text.
     */
    private static function userMessage(Message $m): UserMessage
    {
        $images = array_filter($m->images, ImageData::isValid(...));
        if ($images === []) {
            return new UserMessage($m->content);
        }
        $parts = $m->content === '' ? [] : [['type' => 'text', 'text' => $m->content]];
        foreach ($images as $url) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }
        return new UserMessage($parts);
    }
}
