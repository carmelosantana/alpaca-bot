<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * One streamed fragment of a reply: text, reasoning (thinking models), or both. Chunks that
 * carry neither, such as the stop and usage-only chunks, are never surfaced as deltas.
 */
final class Delta
{
    public function __construct(public string $text, public string $reasoning = '') {}
}
