<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * What ServerSettings::check() made of a write of `toolkits.mcp_servers`, for a writer that
 * stores nothing when it is refused: either a refusal (`$code`, the whole `$message` a writer
 * answers, and `$data`, which is `['rows' => …]` for `alpaca_bot_mcp_row` and empty for
 * `alpaca_bot_mcp_address`), or a pass carrying `$cleared`, the ids of the servers whose header
 * value the write is about to drop because their URL moved (ServerSettings::clearedByMove()).
 * Neither carries a header value.
 *
 * @since 0.6.1
 */
final readonly class WriteCheck
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $cleared
     */
    private function __construct(
        public ?string $code,
        public string $message,
        public array $data,
        public array $cleared,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function refused(string $code, string $message, array $data = []): self
    {
        return new self($code, $message, $data, []);
    }

    /** @param list<string> $cleared */
    public static function passed(array $cleared): self
    {
        return new self(null, '', [], $cleared);
    }

    /** Whether the write is refused, in which case nothing is to be stored. */
    public function isRefused(): bool
    {
        return $this->code !== null;
    }
}
