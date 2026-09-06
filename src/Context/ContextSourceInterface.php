<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

/**
 * Something that can ground a chat turn in the site: the post being edited, a search hit,
 * a WooCommerce order. Sources are registered on the Collector (or added through the
 * `alpaca_bot/context/sources` filter) and asked once per turn.
 */
interface ContextSourceInterface
{
    /** A short, stable, slug-like id; each Context this source returns should be prefixed by it. */
    public function id(): string;

    /**
     * `$request` is whatever the client sent alongside the message (e.g. `['screen' => 'post',
     * 'post_id' => 12]`): untrusted input. A source decides for itself what the user may see.
     *
     * @param array<string, mixed> $request
     * @return Context[]
     */
    public function collect(int $userId, array $request): array;
}
