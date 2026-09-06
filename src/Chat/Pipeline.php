<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Context\Collector;
use AlpacaBot\Context\Context;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\MessageInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\AssistantMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;

/**
 * Turns one user message into a streamed model reply: enforces the caps, resolves the model and
 * the system prompt, folds in site context, streams the provider, then persists the conversation
 * and the usage receipt. Every consumer (CLI, REST, abilities) goes through here.
 *
 * Hooks, in firing order: filter `alpaca_bot/system_prompt` (string, Conversation, model) ->
 * filter `alpaca_bot/message/before_send` (string text, Conversation, options) -> action
 * `alpaca_bot/chat/started` (Conversation, model) -> the deltas -> filter
 * `alpaca_bot/message/after_receive` (Message reply, Conversation) -> action
 * `alpaca_bot/chat/completed` (Result). A provider failure fires action `alpaca_bot/chat/failed`
 * (Throwable, Conversation) instead of the last two.
 *
 * `send()` is a generator, so nothing at all runs until the caller first advances it: the cap
 * check, the conversation lookup and every hook happen on the first iteration, not on the call.
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
    ) {}

    /**
     * send() drained: the Result once the whole reply is in.
     *
     * @param array{conversation_id?: int, model?: string, images?: string[], context?: array<string, mixed>, system?: string} $options see send()
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
     * `$userId` is the authenticated user and the only identity used anywhere: for the cap, for
     * conversation ownership, and for what the context sources may read on the user's behalf.
     * Nothing in `$options` can stand in for it.
     *
     * Options: `conversation_id` continues a conversation the user owns (an id that is missing
     * or not theirs is refused, not silently replaced by a new one); `model` is honoured only
     * while `chat.user_can_change_model` is on, else the default applies; `images` are data URLs
     * (or URLs the provider can fetch) attached to the user turn, and are sent verbatim: the
     * pipeline never reads the filesystem on a caller's behalf; `context` is the request the
     * context sources see (a post id, a screen); `system` replaces the configured system prompt
     * for this turn.
     *
     * The cap check is check-then-act with no reservation: concurrent requests from one user can
     * overshoot a cap by roughly their number. It is a monthly budget, not a hard ceiling.
     *
     * @param array{conversation_id?: int, model?: string, images?: string[], context?: array<string, mixed>, system?: string} $options
     * @return \Generator<int, Delta, mixed, Result>
     * @throws \InvalidArgumentException for an empty message or a conversation the user does not own
     * @throws CapExceeded before any provider call
     * @throws \RuntimeException when no model can be resolved, or wrapping a provider failure as 'Provider error: ...'
     */
    public function send(int $userId, string $text, array $options = []): \Generator
    {
        $text = trim($text);
        $images = array_values(array_filter((array) ($options['images'] ?? []), 'is_string'));
        if ($text === '' && $images === []) {
            throw new \InvalidArgumentException(__('The message is empty.', 'alpaca-bot'));
        }
        $this->caps->assertAllowed($userId);
        $conversation = $this->conversation($userId, (int) ($options['conversation_id'] ?? 0));
        $model = $this->model($options);
        $system = $this->systemPrompt($conversation, $model, isset($options['system']) ? (string) $options['system'] : null);
        $text = (string) apply_filters('alpaca_bot/message/before_send', $text, $conversation, $options);
        $conversation->append(new Message('user', $text, $model, null, 0, $images));
        $contexts = $this->collector->collect($userId, (array) ($options['context'] ?? []));
        $messages = $this->assemble($conversation, $contexts, $system);
        $generation = $this->store->modelOverrides($model);
        $providerOptions = [
            'temperature' => (float) $generation['temperature'],
            'num_ctx' => (int) $generation['num_ctx'],
            'keep_alive' => (string) $generation['keep_alive'],
        ];

        do_action('alpaca_bot/chat/started', $conversation, $model);
        $started = microtime(true);
        $content = '';
        $reasoning = '';
        $prompt = 0;
        $completion = 0;
        try {
            // The vendored stream() marks text and reasoning deltas with finishReason Stop too,
            // so Stop says nothing about the end of the stream: the generator is drained to
            // exhaustion. Usage rides on the final stop chunk or on a usage-only chunk after it
            // (both with empty content); whichever chunk carries it, it is taken from there.
            foreach ($this->factory->make($model)->stream($messages, [], $providerOptions) as $chunk) {
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
        } catch (\Throwable $e) {
            do_action('alpaca_bot/chat/failed', $e, $conversation);
            throw new \RuntimeException('Provider error: ' . $e->getMessage(), 0, $e);
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $reply = new Message(
            'assistant',
            $content,
            $model,
            ['prompt_tokens' => $prompt, 'completion_tokens' => $completion],
            0,
            [],
            $reasoning !== '' ? ['reasoning' => $reasoning] : [],
        );
        $filtered = apply_filters('alpaca_bot/message/after_receive', $reply, $conversation);
        if ($filtered instanceof Message) {
            $reply = $filtered;
        }
        $conversation->append($reply);
        $this->conversations->save($conversation);
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
     * The provider messages for a conversation: the system prompt (resolved and filtered here,
     * with the context block appended) followed by every turn of the transcript.
     *
     * @param Context[] $contexts
     * @return MessageInterface[]
     */
    public function buildMessages(Conversation $c, string $model, array $contexts, ?string $systemOverride = null): array
    {
        return $this->assemble($c, $contexts, $this->systemPrompt($c, $model, $systemOverride));
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
     * The model for this turn: the caller's choice while users may change model, else the
     * catalog's default (which is never '' as long as the provider lists anything).
     *
     * @param array<string, mixed> $options
     * @throws \RuntimeException when no model is configured and the provider lists none
     */
    private function model(array $options): string
    {
        $requested = trim((string) ($options['model'] ?? ''));
        $model = $requested !== '' && (bool) $this->store->get('chat.user_can_change_model')
            ? $requested
            : $this->catalog->defaultId($this->store);
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
     * The system text plus the context block, then the transcript. Stored roles other than
     * user/assistant/system (a `tool` turn, which nothing writes yet) are left out rather than
     * sent as the wrong role.
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
        foreach ($c->messages as $m) {
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
     * attached (`UserMessage::withImages()` builds the same parts, from files); plain text otherwise.
     */
    private static function userMessage(Message $m): UserMessage
    {
        if ($m->images === []) {
            return new UserMessage($m->content);
        }
        $parts = [['type' => 'text', 'text' => $m->content]];
        foreach ($m->images as $url) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }
        return new UserMessage($parts);
    }
}
