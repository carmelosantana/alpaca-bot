<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * What one completed turn produced: the conversation with both new messages appended, the
 * assistant reply (after `alpaca_bot/message/after_receive`), the usage receipt as
 * UsageMeter recorded it, and the contexts that were folded into the system prompt.
 */
final class Result
{
    /**
     * @param array{user_id: int, model: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, duration_ms: int, conversation_id: int, log_id: int, created: int} $receipt
     * @param \AlpacaBot\Context\Context[] $contexts
     */
    public function __construct(public Conversation $conversation, public Message $reply, public array $receipt, public array $contexts) {}
}
