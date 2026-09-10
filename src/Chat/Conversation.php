<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * A user's conversation: the in-memory form of one `chat_history` post.
 *
 * `id` is 0 until ConversationStore has persisted it (and stays 0 when saving is off).
 */
final class Conversation
{
    /** @param Message[] $messages */
    public function __construct(
        public int $id,
        public int $userId,
        public string $title,
        public array $messages = [],
        public string $mode = 'chat',
        public int $created = 0,
    ) {}

    public function append(Message $m): void
    {
        if ($m->created === 0) {
            $m->created = (int) current_time('timestamp', true);
        }
        $this->messages[] = $m;
    }

    public function last(): ?Message
    {
        $key = array_key_last($this->messages);
        return $key === null ? null : $this->messages[$key];
    }
}
