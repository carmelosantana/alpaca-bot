<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * One turn of a conversation, as stored under ConversationStore::META_MESSAGES.
 */
final class Message
{
    private const ROLES = ['user', 'assistant', 'system', 'tool'];

    /**
     * @param array<string, int>|null $usage  prompt_tokens / completion_tokens when the provider reported them
     * @param list<string>            $images data URLs or paths attached to a user turn
     * @param array<string, mixed>    $meta   provider extras (finish reason, tool calls, ...)
     */
    public function __construct(
        public string $role,
        public string $content,
        public string $model = '',
        public ?array $usage = null,
        public int $created = 0,
        public array $images = [],
        public array $meta = [],
    ) {}

    /**
     * Builds a message from its stored array — the 1.0 shape written by toArray(), or the 0.4
     * shape `{model, message: {role, content}, ...}`, which was the raw Ollama chat response.
     *
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        if (is_array($a['message'] ?? null)) {
            return self::fromLegacy($a);
        }
        return new self(
            (string) ($a['role'] ?? 'user'),
            (string) ($a['content'] ?? ''),
            (string) ($a['model'] ?? ''),
            is_array($a['usage'] ?? null) ? $a['usage'] : null,
            (int) ($a['created'] ?? 0),
            is_array($a['images'] ?? null) ? array_values($a['images']) : [],
            self::meta($a['meta'] ?? null),
        );
    }

    /**
     * The wire and storage shape. `meta` goes out as an object so an empty one serialises as
     * `{}`, the same JSON type as a populated one; fromArray() reads that object back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
            'model' => $this->model,
            'usage' => $this->usage,
            'created' => $this->created,
            'images' => $this->images,
            'meta' => (object) $this->meta,
        ];
    }

    /**
     * `meta` as stored: the object toArray() now writes, the array it wrote before, or nothing.
     *
     * @return array<string, mixed>
     */
    private static function meta(mixed $meta): array
    {
        if (is_object($meta)) {
            $meta = get_object_vars($meta);
        }
        return is_array($meta) ? $meta : [];
    }

    /**
     * 0.4 stored a user turn as `{model, message: {role: <user id>, content}}` — the role was
     * get_current_user_id(), so an int, 0 included — and an assistant turn as Ollama's whole
     * response: `{model, created_at, message: {role: 'assistant', content}, prompt_eval_count,
     * eval_count, ...}`. Its readers treated any int role as the user and rendered string roles
     * lowercased; when replaying to the model, anything that was not an int was the assistant.
     *
     * @param array<string, mixed> $a
     */
    private static function fromLegacy(array $a): self
    {
        /** @var array<string, mixed> $message */
        $message = $a['message'];
        $role = $message['role'] ?? null;
        if (is_int($role) || $role === null) {
            $role = 'user';
        } else {
            $role = strtolower((string) $role);
            $role = in_array($role, self::ROLES, true) ? $role : 'assistant';
        }
        $usage = isset($a['eval_count']) || isset($a['prompt_eval_count'])
            ? ['prompt_tokens' => (int) ($a['prompt_eval_count'] ?? 0), 'completion_tokens' => (int) ($a['eval_count'] ?? 0)]
            : null;
        $created = is_string($a['created_at'] ?? null) ? strtotime($a['created_at']) : false;
        return new self(
            $role,
            (string) ($message['content'] ?? ''),
            (string) ($a['model'] ?? ''),
            $usage,
            $created === false ? 0 : $created,
            is_array($message['images'] ?? null) ? array_values($message['images']) : [],
        );
    }
}
