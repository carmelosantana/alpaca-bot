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
     * `$userId` is the user the context is being gathered *for* — the one whose turn this is —
     * which is not always the current user (WP-CLI with --user, cron, an admin acting as
     * someone). A source must answer capability questions about that user (`user_can($userId,
     * ...)`), never about the current one.
     *
     * `$request` is whatever the client sent alongside the message (e.g. `['screen' => 'post',
     * 'post_id' => 12]`): untrusted input. A source decides for itself what the user may see.
     *
     * @param array<string, mixed> $request
     * @return Context[]
     */
    public function collect(int $userId, array $request): array;
}
