<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

use AlpacaBot\Mcp\WriteCheck;

/**
 * What Writer::write() made of one write of the settings: either `$refusal`, the refused
 * Mcp\WriteCheck, in which case nothing was stored, or null with what the write that was stored
 * cleared: `$mcpCleared`, the ids of the MCP servers whose header value it dropped because their
 * URL moved (Mcp\ServerSettings::clearedByMove()), and `$keyCleared`, whether it cleared a held
 * provider key by moving `provider.base_url` (ProviderKey::clearedByMove()). A refusal carries
 * neither: both are [] and false.
 *
 * @since 0.6.2
 */
final readonly class Written
{
    /** @param list<string> $mcpCleared */
    private function __construct(
        public ?WriteCheck $refusal,
        public array $mcpCleared,
        public bool $keyCleared,
    ) {
    }

    public static function refused(WriteCheck $refusal): self
    {
        return new self($refusal, [], false);
    }

    /** @param list<string> $mcpCleared */
    public static function stored(array $mcpCleared, bool $keyCleared): self
    {
        return new self(null, $mcpCleared, $keyCleared);
    }
}
