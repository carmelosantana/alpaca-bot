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
use AlpacaBot\Toolkit\FirstWins;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Agent\Output;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\MessageInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Exception\TerminationException;
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
 * An `ephemeral` turn — Toolkit\SummarizeToolkit's inner call and both shortcodes' one-shot
 * generations, the three sites that pass the flag — runs the same path over a
 * conversation that exists only in memory: no post is created, no transcript or partial reply
 * is written, and nothing is taken back on failure because nothing was made. The usage receipt
 * is still recorded, with conversation 0 (UsageMeter::record() already allows that), and every
 * hook fires as for any turn: the tokens were spent, the caps count them, and a listener that
 * meters or logs sees the turn. Not a pipeline of its own, and not `privacy.save_history`
 * off: that setting is the user's choice about their history, and it still updates a
 * conversation that already has a post; ephemeral is the caller's statement that this turn is
 * not part of anyone's history at all, whatever the setting says.
 *
 * A tool turn: when the registry enables a toolkit for the user, the catalogue says the model
 * can call tools, and at least one of those toolkits offers a tool (offered()), the reply is
 * produced by the Assistant agent (the vendored tool loop) instead of one provider stream, and the branch is exactly that: everything else on the way
 * in and out (the caps, the hooks, the receipt, what is stored, what an abandoned turn leaves
 * behind) is the same code. The model gets the same generation options a plain turn sends
 * (Provider\BoundOptionsProvider), the text streams as it is produced (agentTurn() says how),
 * and the stored reply carries `meta['tool_calls']`, one `{name, arguments, result_excerpt,
 * ok, result_bytes}` per call, and the receipt `tool_result_bytes`, the sum of their
 * `result_bytes`. The stored reply is the one thing on this branch that can differ from what
 * streamed: a model whose template cannot really call tools writes the call as prose, and when
 * the faked call is `done` the whole answer is inside it, so the reply is recovered from it
 * before anything is stored (FakedToolCall::recovered(), which says how, and why the
 * provider's capability data cannot be trusted to tell such a model apart). One departure from
 * the plain path's "a failed turn persists nothing": a provider failure after a tool has
 * already run keeps the turn, stored as an abandoned one is (the partial reply with its
 * `tool_calls`, a receipt for the calls made), and then fails it the same way (`chat/failed`,
 * `Provider error: ...`). Every tool call is recorded on the transcript (the spec's rule), and
 * a draft the run created is in the user's posts whether or not the reply finished; the record
 * is how they learn of it. A failure before any tool ran persists nothing, as a plain one
 * does. The usage metered is the whole run: Output::$usage is the sum over every provider call
 * the agent made, so a turn that took three calls is billed three calls' tokens against the
 * cap; a turn the consumer abandons is billed the calls it had completed by then (the provider
 * decorator's running tally). The cap is checked again before each of those calls after the
 * first (CapPolicy::assertMayContinue(), with that tally counted in; Kanboard #4701), so a tool
 * turn overshoots a cap by at most the one call that crossed it rather than by the rest of its
 * iteration budget. A turn stopped there is kept whether or not a tool had run, stored as a
 * failed tool turn is (the partial reply, a receipt for the calls made, `chat/failed`), and
 * CapExceeded itself is raised, `stopped` and carrying the conversation id, so every caller
 * answers it as it answers the refusal before a turn. An ephemeral turn runs plainly, tools or no tools, and that is a
 * decision about each of the three callers that pass the flag, not only the first. Toolkit\
 * SummarizeToolkit's inner call is a tool inside an agent, and an agent inside it would be a
 * recursion with no bound but each level's iteration budget. Shortcodes\Chat's `prompt=` form
 * and Shortcodes\AgentShim's `summarize` are a page rendering itself: the prompt is written by
 * whoever could write the post (a Contributor, at least) and the turn runs as whichever viewer
 * the Shortcodes row admits opened the page, so a tool turn there would let that prompt make the
 * server fetch addresses of the model's choosing (web_fetch), or author drafts under the
 * viewer's name that nobody asked for (draft_post), once per cache miss. AgentShim wants a
 * fetch and runs the web_fetch tool itself instead, on the URL its author wrote (its docblock
 * says why that loses nothing). So `ephemeral` carries two decisions — this turn is in
 * nobody's history, and it runs no tools — and a caller that wants one without the other
 * cannot ask; splitting the flag belongs to the release that has such a caller.
 */
final class Pipeline
{
    /** How many turns are running in this request, one inside another counted twice (running()). */
    private int $depth = 0;

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
     * Whether a turn is running in this request: true from the first iteration of a send()
     * (complete() and every consumer go through it) until that turn has ended, whether it was
     * drained, refused, thrown or abandoned, and counted, so a turn started inside another (the
     * summarize tool's) ending does not end the outer one. Abilities\Register asks it before it
     * starts a turn of its own, which is what keeps a turn from starting another through any
     * tool that runs abilities (Kanboard #4538); the abilities toolkit refuses `alpaca-bot/*`
     * before this is asked. It is the instance's count, and one instance
     * serves the request: Plugin::register() hands every consumer the container's.
     */
    public function running(): bool
    {
        return $this->depth > 0;
    }

    /**
     * send() drained: the Result once the whole reply is in.
     *
     * @param array{conversation_id?: int, model?: string, images?: string[], context?: array<string, mixed>, system?: string, temperature?: float, ephemeral?: bool} $options see send()
     * @throws CapExceeded|\InvalidArgumentException|\RuntimeException as send()
     */
    public function complete(int $userId, string $text, array $options = []): Result
    {
        $gen = $this->send($userId, $text, $options);
        // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedForeach -- Draining the generator is the whole point: send() does its work as it is iterated, and the Result is only available from getReturn() once it has run to completion.
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
     * `temperature` replaces the model's for this turn (the other generation options are not a
     * caller's to set; the code says why); `ephemeral` keeps no conversation (the class docblock), and is refused together with a
     * `conversation_id`, since a turn cannot both continue a conversation and leave no trace.
     *
     * The cap check is check-then-act with no reservation: concurrent requests from one user can
     * overshoot a cap by roughly their number. It is a monthly budget, not a hard ceiling.
     *
     * @param array{conversation_id?: int, model?: string, images?: string[], context?: array<string, mixed>, system?: string, temperature?: float, ephemeral?: bool} $options
     * @return \Generator<int, Delta, mixed, Result>
     * @throws \InvalidArgumentException for an empty message (also one `before_send` blanked), an image that is not a base64 image data URL or a set of them past the site's allowance, a requested model a non-empty catalog does not list, a conversation the user does not own, or `ephemeral` with a `conversation_id`
     * @throws CapExceeded before any provider call, or on a tool turn before a further one (`stopped`: the class docblock)
     * @throws \RuntimeException when no model can be resolved, or wrapping a provider failure as 'Provider error: ...' (a tool turn's failure the provider had no part in is raised in words of its own instead: raised())
     */
    public function send(int $userId, string $text, array $options = []): \Generator
    {
        // Around the whole turn, so running() holds for every turn and not only one an ability
        // starts. A finally, so a throw lets go, and so does a consumer that abandons the
        // stream: PHP runs a suspended generator's finally when it is destroyed.
        ++$this->depth;
        try {
            return yield from $this->turn($userId, $text, $options);
        } finally {
            --$this->depth;
        }
    }

    /**
     * send()'s turn, run inside its count.
     *
     * @param array{conversation_id?: int, model?: string, images?: string[], context?: array<string, mixed>, system?: string, temperature?: float, ephemeral?: bool} $options see send()
     * @return \Generator<int, Delta, mixed, Result>
     */
    private function turn(int $userId, string $text, array $options): \Generator
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
            /**
             * Filters the user's message before it joins the conversation and is sent. The first hook
             * of a turn, ahead of `alpaca_bot/system_prompt`, so a system-prompt listener later sees
             * the message it is prompting for. Return rewritten text to change what the model and the
             * transcript get. Return '' to refuse a text-only turn: that throws (InvalidArgumentException,
             * a 400 on the REST routes) without `alpaca_bot/chat/failed`, and stores nothing. Attached
             * images are not filtered here and are sent as they are.
             *
             * @since 0.5.0
             * @param string               $text         the message, trimmed
             * @param Conversation         $conversation the conversation the turn joins, with its earlier turns; a new one is empty and has no id yet
             * @param array<string, mixed> $options      the caller's turn options (`model`, `system`, `context`, `temperature`, `images`, `ephemeral`)
             */
            $text = trim((string) apply_filters('alpaca_bot/message/before_send', $text, $conversation, $options));
            if ($text === '' && $images === []) {
                throw new \InvalidArgumentException(__('The message is empty.', 'alpaca-bot'));
            }
            $conversation->append(new Message('user', $text, $model, null, 0, $images));
            $contexts = $this->collector->collect($userId, (array) ($options['context'] ?? []));
            $messages = $this->buildMessages($conversation, $model, $contexts, isset($options['system']) ? (string) $options['system'] : null);
            $generation = $this->store->modelOverrides($model);
            // `temperature` is the one generation option a caller may set per turn (the
            // `[alpacabot temperature]` attribute): it shapes this answer and nothing else. num_ctx
            // and keep_alive stay the model's: the context window is a budget the administrator
            // set against the host's memory, and keep_alive is about the host, not the turn.
            $providerOptions = [
                'temperature' => isset($options['temperature']) ? (float) $options['temperature'] : (float) $generation['temperature'],
                'num_ctx' => (int) $generation['num_ctx'],
                'keep_alive' => (string) $generation['keep_alive'],
            ];
            $toolkits = $ephemeral ? [] : $this->toolkitsFor($userId, $model);
            $observer = null;
            $bound = null;

            /**
             * Fires when the turn is committed: the message is on the conversation, the system prompt
             * and the site context are assembled, and the provider is about to be called. The stream
             * route listens here to learn a new conversation's id before the first delta; a listener can
             * start a timer or a log line against `$conversation->id`. Nothing is stored yet: the
             * transcript is saved after `alpaca_bot/message/after_receive`, and a turn that fails ends in
             * `alpaca_bot/chat/failed` instead.
             *
             * @since 0.5.0
             * @param Conversation $conversation the conversation, holding the new user turn
             * @param string       $model        the model id resolved for this turn
             */
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
                $toolkits = self::offered($toolkits);
                if ($toolkits !== []) {
                    $bound = new BoundOptionsProvider($provider, $providerOptions);
                    // Before each provider call after the first, the cap again, with the run's
                    // spend so far counted in (Kanboard #4701; the catch below says what a stop
                    // leaves behind).
                    $observer = new AgentStreamObserver(fn() => $this->caps->assertMayContinue($userId, $bound->usage()->totalTokens));
                    $turn = $this->agentTurn($bound, $toolkits, $messages, $observer);
                    foreach ($turn as $delta) {
                        $content .= $delta->text;
                        $reasoning .= $delta->reasoning;
                        // The run's usage so far, read before each yield: a consumer that does not
                        // come back from this one leaves settle() to bill the last reading, which
                        // covers every call the provider had answered by then. The agent's own
                        // total is in an Output an abandoned run never returns.
                        $tally = $bound->usage();
                        $prompt = $tally->promptTokens;
                        $completion = $tally->completionTokens;
                        yield $delta;
                    }
                    // Once the run has ended, Output::$usage is the authority: the agent's sum over
                    // every call it made.
                    $output = $turn->getReturn();
                    if ($output->usage !== null) {
                        $prompt = $output->usage->promptTokens;
                        $completion = $output->usage->completionTokens;
                    }
                    if ($output->content !== '' && $output->content === trim($output->reasoning)) {
                        // The Assistant took the reasoning as the answer (a thinking model that
                        // wrote no text, after two nudges): the same words are the reply, and
                        // storing them under `reasoning` too would show them twice on reload.
                        // The nudged attempts' thoughts go with it; the user saw them stream.
                        $reasoning = '';
                    }
                    // The run is over and the accumulated text is what will be stored, shown and
                    // built into the receipt: the last moment to take back an answer the model
                    // wrote as a tool call instead of calling one (FakedToolCall::recovered(),
                    // which says why this cannot be left to the provider's capability data).
                    // Only a turn that reaches its return comes through here: a consumer that
                    // walks away is settled from the finally below while this generator is
                    // still suspended in the foreach, so an abandoned turn stores the raw leak,
                    // as it stores whatever else had streamed. The two paths store the same
                    // bytes — the finally takes the observer's undecided tail the same way
                    // agentTurn()'s flush() does — and differ only in this call: an abandoned
                    // turn's partial reply keeps the markup it streamed. Recovering it there as
                    // well is Kanboard #4329 comment 617 §2, and is not done here.
                    $content = FakedToolCall::recovered($content);
                    $failure = self::failure($output, $observer);
                    if ($failure !== null) {
                        // The run failed, and failure() has already said whose words the failure
                        // is in. Raising it here puts it on the path a plain provider failure takes
                        // (the catch below, which keeps the partial transcript when a tool had run).
                        throw $failure;
                    }
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
                // When a tool had already run, the turn is not discarded: a draft the run created
                // is in the user's posts, and the transcript is where they learn of it. Here, on
                // the one path every failure of a tool turn takes, rather than at the failure
                // raised above alone: a Throwable that escapes the fiber instead (the
                // LogicException after it, a toolkit added through `alpaca_bot/toolkits` that
                // throws outside the agent's own catch regions, a consumer throwing into the
                // generator) lands here with the same draft made and the same duty to record it.
                // Whatever the observer was still deciding about belongs to this reply, and this
                // is the last moment it can reach the partial transcript below.
                $content .= $observer?->takeUnsent() ?? '';
                $toolCalls = $observer?->toolCalls() ?? [];
                // A tool turn the cap stopped between two provider calls (Kanboard #4701) is kept
                // whether or not a tool had run: at least one call was made and answered, so there
                // is spend to bill and, usually, text the user has already read. It is billed from
                // the tally, which the stop leaves complete for every call made, where the last
                // reading before a yield can be one chunk short of a call's usage.
                $capped = $e instanceof CapExceeded && $e->stopped;
                if ($capped && $bound !== null) {
                    $tally = $bound->usage();
                    $prompt = $tally->promptTokens;
                    $completion = $tally->completionTokens;
                }
                if ($toolCalls !== [] || $capped) {
                    $this->storePartial($ephemeral, $userId, $conversation, $model, $content, $reasoning, $prompt, $completion, self::elapsedMs($started), $toolCalls);
                }
                if ($capped) {
                    $e->conversationId = $conversation->id;
                }
                try {
                    /**
                     * Fires when the provider call (or the tool loop) failed before the reply finished, in
                     * place of `alpaca_bot/message/after_receive` and `alpaca_bot/chat/completed`. The reply is
                     * not stored (a tool turn that had already run tools keeps its partial transcript, so the
                     * draft it made is traceable, and so does a tool turn the monthly cap stopped between two
                     * provider calls), and a conversation this turn created is deleted afterwards
                     * unless a listener saved a turn onto it. A listener that throws does not replace the
                     * failure: that is rethrown as a RuntimeException, `Provider error: ` and what the
                     * provider threw, or in words of its own where the provider had no part in it, with
                     * the listener's exception chained behind it; a cap stop is rethrown as the
                     * Chat\CapExceeded it is. The same action fires from settle() when the consumer
                     * abandons the stream, with a RuntimeException saying so.
                     *
                     * @since 0.5.0
                     * @param \Throwable   $e            what the turn failed on: what the provider or the tool loop threw, the Chat\RunFailure the pipeline read out of a run that failed without announcing it, or the Chat\CapExceeded (`stopped`) that ended a tool turn at the cap
                     * @param Conversation $conversation the conversation, with the user turn appended and no finished reply
                     */
                    do_action('alpaca_bot/chat/failed', $e, $conversation);
                } finally {
                    // Thrown from the finally so a listener that throws cannot replace the
                    // failure with its own exception: a caller maps what arrives by class
                    // (ChatController reads an InvalidArgumentException as the user's mistake,
                    // 400). PHP chains a pending exception behind the one thrown here, so a
                    // listener's is kept, after $e. raised() says what the message is. A cap stop
                    // is raised as itself, so every caller answers it as it answers the refusal
                    // before a turn (Errors::fromPipeline(): the 402).
                    throw $capped ? $e : new \RuntimeException(self::raised($e), 0, $e);
                }
            } finally {
                // Empty on every path that came through the catch or through agentTurn()'s
                // flush; the bytes themselves on the one path that came through neither, the
                // consumer who stopped iterating while the generator was suspended.
                $content .= $observer?->takeUnsent() ?? '';
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

        $toolCalls = $observer?->toolCalls() ?? [];
        // The duration rides on the stored reply as well as the receipt, so a reloaded
        // transcript shows the same `model · tokens · seconds` line a live turn did.
        $reply = new Message(
            'assistant',
            $content,
            $model,
            ['prompt_tokens' => $prompt, 'completion_tokens' => $completion],
            0,
            [],
            ['duration_ms' => $durationMs] + ($reasoning !== '' ? ['reasoning' => $reasoning] : []) + self::toolCallsMeta($toolCalls),
        );
        /**
         * Filters the finished assistant reply before it is appended to the conversation, saved and
         * metered. Return a Message to replace it (content rewritten, meta added); anything else is
         * ignored and the original is kept, so a filter's mistake can never lose a reply. What is
         * returned is what is stored and what the CLI and `POST /chat` return; the deltas already
         * streamed to a client are not rewritten.
         *
         * @since 0.5.0
         * @param Message      $reply        the assistant turn: content, model, token usage, and meta (duration, reasoning, tool calls)
         * @param Conversation $conversation the conversation it will join
         * @var mixed $filtered what the filter returned, checked before it is trusted
         */
        $filtered = apply_filters('alpaca_bot/message/after_receive', $reply, $conversation);
        if ($filtered instanceof Message) {
            $reply = $filtered;
        }
        $conversation->append($reply);
        if (!$ephemeral) {
            $this->conversations->save($conversation);
        }
        $toolResultBytes = self::toolResultBytes($toolCalls);
        $logId = $this->meter->record($userId, $model, $prompt, $completion, $durationMs, $conversation->id, $toolResultBytes);
        $result = new Result($conversation, $reply, [
            'user_id' => $userId,
            'model' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'duration_ms' => $durationMs,
            'tool_result_bytes' => $toolResultBytes,
            'conversation_id' => $conversation->id,
            'log_id' => $logId,
            'created' => $reply->created,
        ], $contexts);
        /**
         * Fires when a turn has finished and everything about it is stored: the conversation is
         * saved (unless the turn was ephemeral), the usage receipt is recorded and
         * `alpaca_bot/usage/recorded` has fired. The Result carries the conversation, the reply, the
         * receipt and the context that was folded in; this is the place for analytics, a
         * notification or a webhook. Listeners run inside the request, before the consumer gets the
         * result, so anything slow belongs in a scheduled event.
         *
         * @since 0.5.0
         * @param Result $result the finished turn
         */
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
     * usage had arrived, and say so on `chat/failed`. On a plain turn that is usually none (the
     * usage comes last); on a tool turn it is every provider call the run had completed, read
     * off Provider\BoundOptionsProvider::usage() before each yield, since the agent's own total
     * is in an Output the abandoned run never returns.
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
     * @param list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool, result_bytes: int}> $toolCalls
     */
    private function settle(bool $streamEnded, bool $ephemeral, int $userId, Conversation $conversation, string $model, string $content, string $reasoning, int $prompt, int $completion, float $started, array $toolCalls = []): void
    {
        if ($streamEnded) {
            return;
        }
        $this->storePartial($ephemeral, $userId, $conversation, $model, $content, $reasoning, $prompt, $completion, self::elapsedMs($started), $toolCalls);
        try {
            /**
             * Fires when the consumer stopped reading the stream before the reply finished (a client
             * that disconnected mid-turn). Unlike the provider-failure site, what had arrived is already
             * stored: the partial reply is on the conversation (meta `partial: true`) and a usage receipt
             * covers the time and tokens spent, so the conversation stays. Nothing is thrown afterwards;
             * the generator simply ends. A listener that throws here is caught and logged behind
             * WP_DEBUG rather than allowed out (the code below says why it has nowhere to go), so
             * unlike the provider-failure site this one does not carry a listener's exception to
             * anybody.
             *
             * @since 0.5.0
             * @param \RuntimeException $e            says the stream was abandoned
             * @param Conversation      $conversation the conversation, with the partial reply appended
             */
            do_action(
                'alpaca_bot/chat/failed',
                new \RuntimeException('The stream was abandoned by the consumer before the reply finished.'),
                $conversation,
            );
        } catch (\Throwable $listener) {
            // A listener's throw has nowhere to go from here. This site runs while the generator
            // is being destroyed, and for the stream route that destruction is triggered by
            // StreamController::stream() returning — so it happens after that method's own
            // `catch (\Throwable)` and `finally` have already run, and the exception would leave
            // the controller entirely, with the response's headers long sent and no error frame
            // written. Reproduced on PHP 8.4.25. The provider-failure site above keeps a
            // listener's exception instead, by letting PHP chain it behind the RuntimeException
            // it throws from a finally; there is nothing to chain to here, so it goes to the
            // debug log, behind WP_DEBUG exactly as Errors::provider() puts the provider's
            // own text there.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate diagnostic, gated on WP_DEBUG as core's own logging is; this is the only record a listener's failure can leave, since the turn is being torn down and no caller is left to tell.
                error_log('[alpaca-bot] an alpaca_bot/chat/failed listener threw while an abandoned turn was settling: ' . $listener->getMessage());
            }
        }
    }

    /**
     * What a turn that did not finish leaves behind: the reply as far as it got, appended and
     * saved (meta `partial: true`), and a receipt for the time and usage. Two callers: settle(),
     * for the consumer that walked away, and send()'s catch, for a tool turn that failed after
     * a tool had already run, whatever threw. Neither fires `chat/failed` here; each does so in its
     * own way afterwards, with the conversation already carrying this reply, so a listener sees
     * what was stored. An ephemeral turn has no post to store on and stores nothing; the receipt
     * is recorded either way.
     *
     * @param list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool, result_bytes: int}> $toolCalls
     */
    private function storePartial(bool $ephemeral, int $userId, Conversation $conversation, string $model, string $content, string $reasoning, int $prompt, int $completion, int $durationMs, array $toolCalls): void
    {
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
        $this->meter->record($userId, $model, $prompt, $completion, $durationMs, $conversation->id, self::toolResultBytes($toolCalls));
    }

    /**
     * The exception a run that failed is raised as, or null when it did not fail. An Error
     * finish is a failure unless it is positively a termination: a tool that ended the run by
     * throwing TerminationException, which is not a failure at all. Nothing failed there; a
     * tool asked to stop, and the turn finishes on its word (agentTurn() yields it). Telling
     * the two apart is what keeps a toolkit's own "stop" from reaching the user as a failure at
     * all: read as one it would be raised out of send(), the reply marked partial and
     * `alpaca_bot/chat/failed` fired, for a turn nothing went wrong in.
     *
     * An announcement settles it first, and on its own. Every `agent.error` the library notifies
     * is followed immediately by the return it belongs to, so nothing can announce and then go
     * on to terminate: a run that announced ended on the announcement, and a termination is
     * therefore always unannounced. Reading the announcement first costs a real termination
     * nothing, and closes a hole that reading it second leaves open — both announced Error sites
     * build their Output with the results accumulated across the whole run, not the iteration
     * that failed, so a provider failure carries whatever earlier tools returned. One of those
     * repeating the library's own 'Provider error: ...' or 'Task was cancelled.' text verbatim
     * would otherwise pass the termination test below, and the failure would be stored as a
     * finished, billed reply — the direction this discriminator exists to close.
     *
     * An unannounced Error is then identified by what it leaves behind, not by what it did not
     * say. AbstractAgent::executeToolCalls() records the terminating tool's message as that
     * tool's *successful* result and the agent returns the same message as the content, so an
     * Error finish carrying content that some ToolResult of status Success repeats verbatim is
     * a termination. The comparison accepts an empty message: a tool may throw
     * TerminationException with none, and that stop is still a stop — a completed turn showing
     * whatever streamed, not a failure with the reply marked partial. Accepting it gives nothing
     * away: every failure shape it could be confused with announces, and so never reaches this
     * loop at all.
     *
     * The library's one other termination path, a TerminationException out of a batch executor,
     * leaves no *successful* result behind: `$executedResults` is never assigned there, and the
     * pre-resolved entries phase 3 still appends (denied by policy, tool not found) are every one
     * of them a ToolResult::error, so the loop finds no match and reads it as a failure. It is
     * unreachable from this plugin, which never gives the agent a tool executor and so gets the
     * serial SynchronousToolExecutor, and a termination misread as a failure is the harmless
     * direction of the two.
     *
     * The direction matters more than the test. Of the library's three Error sites, two announce
     * (the cancellation token tripped, the provider threw) and the third, the termination,
     * announces nothing; reading failure as "an Error finish that announced" therefore rested on
     * the library never adding a fourth, unannounced one — and php-agents is a dependency, so a
     * patch release decides that, not this file. (`agent.error` does fire elsewhere in that file,
     * but those two notifies return EmptyResponse and MaxIterations finishes, which never reach
     * this branch.) Read the old way, such a release would land a provider failure as a
     * *finished* assistant reply carrying the library's own 'Provider error: ...' text, fire
     * `alpaca_bot/chat/completed` and bill the user for it. Read this way, an unrecognised Error
     * finish is a failure, which is the direction a wrong guess should fail in. (composer.json
     * pins php-agents at `~0.16.0` besides, which admits a 0.16 patch release and no 0.17, so a
     * patch release can arrive unreviewed and a minor one cannot.)
     *
     * The announcement supplies the words when it had any, and the failure is the provider's: a
     * plain RuntimeException, which send() raises as `Provider error: ` and those words
     * (raised()). An announcement that is itself empty (the provider threw a Throwable whose
     * getMessage() is '') is still the provider's failure and keeps the prefix; it has no words
     * to lend, so it borrows this plugin's. Without an announcement there is nothing to quote and
     * no provider failure to report: the content is the library's own prose, never announced. The
     * failure is a RunFailure in words of this plugin's own, which translate, and which send()
     * raises without the prefix (Kanboard #4327).
     */
    private static function failure(Output $output, AgentStreamObserver $observer): ?\RuntimeException
    {
        if ($output->finishReason !== AgentFinishReason::Error) {
            return null;
        }
        $announced = $observer->error();
        if ($announced === null) {
            foreach ($output->toolResults as $result) {
                if ($result->status === ToolResultStatus::Success && $result->content === $output->content) {
                    return null;
                }
            }
            return new RunFailure(__('The run ended in an error the assistant did not report.', 'alpaca-bot'));
        }
        return new \RuntimeException($announced !== '' ? $announced : __('The provider gave no reason.', 'alpaca-bot'));
    }

    /**
     * The message send() raises a failed turn with: `Provider error: ` and what was thrown,
     * except for two failures the provider had no part in, which arrive in words of their own.
     *
     * A RunFailure is failure()'s, for a tool run whose Error finish nobody announced, and its
     * message is raised as it is. A TerminationException that reaches here never met the
     * agent's catch: offered() asks every toolkit for its tools (Toolkit\FirstWins::over())
     * before agentTurn() starts the fiber the agent runs in, so a toolkit that stops the run from there is
     * not caught by the library the way a tool that stops it from execute() is (that one is a
     * finished turn: failure()). It says what the tool said, or that a tool stopped the run when
     * it said nothing.
     *
     * Everything else keeps the prefix, as every failure did before 0.6: a provider that threw
     * on the plain path, one the agent announced, and whatever else lands in send()'s catch.
     */
    private static function raised(\Throwable $e): string
    {
        return match (true) {
            $e instanceof RunFailure => $e->getMessage(),
            $e instanceof TerminationException => $e->getMessage() !== '' ? $e->getMessage() : __('A tool stopped the run without giving a reason.', 'alpaca-bot'),
            default => 'Provider error: ' . $e->getMessage(),
        };
    }

    /**
     * The toolkits this turn may run with: what the registry enables for the user, provided the
     * model may call tools; none when either says no. offered() then keeps only those that offer
     * a tool, and nothing here asks a toolkit for its tools.
     *
     * Whether it may is the operator's to settle first (`models.overrides[<model>][tools]`,
     * Store::toolsOverride()), and only where they have not is it the catalogue's
     * (Provider\Model::$tools, which is what the provider declared, or the optimistic floor
     * where it declared nothing). The override wins in both directions on purpose: forced off
     * is the only lever there is for a model that advertises tools and then writes the call out
     * as prose, and it must beat a provider saying `tools` because that is exactly the case it
     * exists for; forced on is the same lever the other way, for a model whose provider
     * undersells it. Off also beats the catalogue not listing the model at all.
     *
     * The catalogue's word is not the provider's asked live: sending tools to a model that
     * cannot take them is a provider error on every turn, and a model the catalogue does not
     * list (an unreachable provider reads as an empty catalogue) is not given tools unless
     * forced, so its turn fails or succeeds the way a plain turn would in the same outage.
     *
     * Nothing here is read at boot, and the reads are ordered. The registry goes first, on
     * every turn, and an empty answer ends it: a user with no toolkit enabled never reaches the
     * override or the catalogue, so forced-on cannot conjure a tool the registry did not offer,
     * and a site with the toolkits off pays neither read. Past that, the override comes from
     * settings rather than from ModelCatalog's five-minute transient, so a save applies to the
     * next turn with the cached model list still in place.
     *
     * @return array<string, ToolkitInterface>
     */
    private function toolkitsFor(int $userId, string $model): array
    {
        $toolkits = $this->toolkits?->enabled($userId) ?? [];
        if ($toolkits === []) {
            return [];
        }
        $forced = $this->store->toolsOverride($model);
        if ($forced !== null) {
            return $forced ? $toolkits : [];
        }
        $listed = $this->catalog->find($model);
        return $listed !== null && $listed->tools ? $toolkits : [];
    }

    /**
     * The toolkits a tool turn hands the agent, out of the ones toolkitsFor() allowed: each
     * wrapped by Toolkit\FirstWins::over(), so a tool name stays with the first toolkit that
     * offered it, and every one left with no tools dropped. None left means the turn takes the
     * plain path, so an enabled toolkit that offers nothing (an MCP server whose client cannot be
     * had or that lists nothing approved, abilities with none ticked) does not put the turn
     * through the agent loop with `done` as its only tool (Kanboard #4541).
     *
     * Here, not in Registry::enabled(): the registry is also asked by the `[alpacabot_agent]`
     * shim and by abilities' permission checks, which list nothing (Mcp\McpToolkit's docblock
     * rests on that). And after toolkitsFor(), so a model that cannot take tools lists nothing.
     * send() calls it inside the try that fires `alpaca_bot/chat/failed`, after
     * `alpaca_bot/chat/started`, where agentTurn() used to ask: asking a toolkit for its tools can
     * reach a remote server, and a toolkit that throws from tools() is a failed turn raised as
     * raised() says. Each toolkit is asked once; the wrappers answer from that list from then on,
     * and they are what agentTurn() is handed.
     *
     * @param array<string, ToolkitInterface> $toolkits
     * @return list<FirstWins>
     */
    private static function offered(array $toolkits): array
    {
        $offered = [];
        foreach (FirstWins::over($toolkits) as $toolkit) {
            if ($toolkit->tools() !== []) {
                $offered[] = $toolkit;
            }
        }
        return $offered;
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
     * fiber), so nothing queued is ever dropped. The observer's own flush() and the drain after
     * it are for the bytes its FakedToolCallStream was still deciding about: they become the
     * run's last delta, and they do it before $streamed is compared with the Output's content
     * below, so that comparison sees the same bytes it saw in 0.5. A consumer that walks away
     * before that drain leaves those bytes to send()'s finally
     * (AgentStreamObserver::takeUnsent()), so the partial reply it stores is every byte too.
     * One of the deltas is empty: the observer queues one before every tool call, so a consumer
     * is handed control (and a streaming transport has a frame to write, and so a chance to
     * learn its client has gone) before the tool runs, not only after the next text arrives.
     *
     * Rejected: reimplementing the loop as a generator in this plugin (a copy of the vendored
     * loop, drifting from its tool pairing repair, its batching and its empty-reply handling as
     * the library moves), and running the agent to completion before yielding (no streaming).
     * The fiber's cost is one stack per tool turn, and one rule: a consumer that abandons the
     * generator while the fiber is suspended must release it. It does, in the generator's own
     * destruction, on two conditions that both hold: the fiber is a local here and the observer
     * holds it weakly, and nothing inside the fiber holds itself across a suspension
     * (AgentStreamObserver::push() decides whether to suspend in a frame that is gone before
     * suspend() runs). Then destroying the generator drops the last strong reference, and PHP
     * unwinds the fiber: the run's finally blocks run and the provider's stream is closed,
     * after settle() has stored the partial reply. A strong reference anywhere on the cycle
     * (fiber -> agent -> observer -> fiber) would defer all of that to the cycle collector,
     * which is why PipelineToolsTest pins the release with no gc_collect_cycles().
     *
     * What the agent cannot stream is yielded here after the run: an answer given through its
     * `done` tool, reasoning surfaced as the answer when a thinking model wrote no text, a
     * translated line for a run that ended on its iteration budget or on silence, and the
     * message of a tool that ended the run (TerminationException; failure() says how that is
     * told from a failure). A failure (the provider threw; the agent swallows that and says so
     * in the Output) is not raised here: the Output is returned with it, and send() decides
     * what the turn leaves behind before raising it, since that depends on whether a tool had
     * already run.
     *
     * @param list<FirstWins> $toolkits as offered() left them: each offers at least one tool, and no name is offered by two of them
     * @param MessageInterface[] $messages as buildMessages() built them: the system message, if any, then the turns, the one being sent last
     * @return \Generator<int, Delta, mixed, Output>
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
        // The bytes the observer kept back in case they began a marker are the run's last words
        // now that it has ended, and they go out before any tail below.
        $observer->flush();
        foreach ($observer->drain() as $delta) {
            $streamed .= $delta->text;
            yield $delta;
        }
        $output = $fiber->getReturn();
        if (!$output instanceof Output) {
            throw new \LogicException('The agent run returned no Output.');
        }
        $tail = match ($output->finishReason) {
            // A failure is send()'s to raise (failure(), which reads a termination apart from
            // one); a termination is not a failure, and its message is the run's last word.
            // phpcs:ignore PHPCompatibility.Operators.RemovedTernaryAssociativity.Found -- False positive: these are two separate match arms, each with one ternary, not a nested one. PHP 8 refuses to compile genuinely unparenthesised nested ternaries, and this file parses and runs on 8.4.
            AgentFinishReason::Error => self::failure($output, $observer) === null ? $output->content : '',
            AgentFinishReason::Stop, AgentFinishReason::Done => $output->content !== '' && !str_ends_with($streamed, $output->content) ? $output->content : '',
            AgentFinishReason::MaxIterations, AgentFinishReason::BudgetExhausted => __('The assistant ran out of tool steps before it finished.', 'alpaca-bot'),
            AgentFinishReason::EmptyResponse => __('The model gave no answer.', 'alpaca-bot'),
        };
        if ($tail !== '') {
            // Through the scanner like everything else. This is the one text a streamed turn
            // emits that the observer's own scanner never saw — the agent's `done` answer, or
            // any iteration whose content never arrived as a text delta — and it carries markup
            // exactly as often as streamed text does. Its own scanner, because it is a separate
            // message and must not inherit an open block from the text it does not continue.
            foreach (FakedToolCallStream::pieces(AgentStreamObserver::separated($streamed, $tail)) as [$piece, $held]) {
                yield new Delta($piece, '', $held);
            }
        }
        return $output;
    }

    /**
     * `tool_calls` for a reply's meta: present only when a call was made, so a plain turn's
     * meta is exactly what it was before tools existed.
     *
     * @param list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool, result_bytes: int}> $toolCalls
     * @return array{tool_calls?: list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool, result_bytes: int}>}
     */
    private static function toolCallsMeta(array $toolCalls): array
    {
        return $toolCalls === [] ? [] : ['tool_calls' => $toolCalls];
    }

    /**
     * The receipt's `tool_result_bytes`: the sum of the records' `result_bytes`, 0 for a turn
     * that ran no tool.
     *
     * @param list<array{name: string, arguments: array<string, mixed>, result_excerpt: string, ok: bool, result_bytes: int}> $toolCalls
     */
    private static function toolResultBytes(array $toolCalls): int
    {
        return (int) array_sum(array_column($toolCalls, 'result_bytes'));
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
        /**
         * Filters the system prompt for a turn: the caller's `system` option, else the model's
         * `system` override, else the `chat.system_prompt` setting. Fires after
         * `alpaca_bot/message/before_send`, with the user's turn already on the conversation, so a
         * listener can shape the prompt to the message, or to the user (`$conversation->userId`).
         * Return '' for no system message. Site context collected for the turn is appended after
         * this filter runs and is not affected by it; `alpaca_bot/context` is where that is edited.
         *
         * @since 0.5.0
         * @param string       $system       the prompt as configured
         * @param Conversation $conversation the conversation, its last message the new user turn
         * @param string       $model        the model id for this turn
         */
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
