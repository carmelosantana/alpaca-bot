<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * One streamed fragment of a reply: text, reasoning (thinking models), or both.
 *
 * A wholly empty Delta is not a dropped fragment. On the plain streaming path Pipeline's chunk
 * loop skips a provider chunk whose content and reasoning are both empty — the stop and
 * usage-only chunks — so none of those becomes a Delta. One empty Delta is written deliberately
 * instead: AgentStreamObserver pushes `new Delta('')` for every `agent.tool_call`, which the
 * agent emits for all of an iteration's calls before it runs any of them, and StreamController
 * frames every delta it is handed with no content test, so `{"text":"","reasoning":""}` reaches
 * the wire and the abort check right after it can find a client that has gone. docs/api.md
 * publishes that frame as part of the SSE contract; a consumer appends the empty strings and
 * renders nothing.
 */
final class Delta
{
    public function __construct(public string $text, public string $reasoning = '') {}
}
