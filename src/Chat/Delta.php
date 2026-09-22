<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * One streamed fragment of a reply: text, reasoning (thinking models), or both.
 *
 * A wholly empty Delta is not a dropped fragment. On the plain streaming path Pipeline's chunk
 * loop skips a provider chunk whose content and reasoning are both empty — the stop and
 * usage-only chunks — so none of those becomes a Delta. One is written on purpose instead:
 * AgentStreamObserver pushes an empty Delta for every `agent.tool_call`, which the agent emits
 * for all of an iteration's calls before it runs any of them, and StreamController frames every
 * delta it is handed with no content test, so `{"text":"","reasoning":"","held":false}` reaches
 * the wire (`held` is `true` on a heartbeat raised inside an open faked block, below) and the
 * abort check right after it can find a client that has gone. docs/api.md
 * publishes that frame as part of the SSE contract; a consumer appends the empty strings and
 * renders nothing. (It is not the only way to get one: an `agent.reasoning` carrying an empty
 * string, or a payload that is not a string at all, is coerced to the same empty pair. That is a
 * malformed event rather than a fragment, and it renders the same nothing.)
 *
 * `held` says the text is inside a faked tool call's markup (FakedToolCallStream): it is still
 * the reply's text and still accumulated and stored, and a consumer that renders it is not
 * wrong — it is what 0.5 did. The chat screen keeps it out of sight while the block is open and
 * shows the finished reply, with the markup taken out, when the turn ends. The empty heartbeat
 * delta carries whatever the state is: `true` when it is raised inside an open block.
 */
final class Delta
{
    public function __construct(public string $text, public string $reasoning = '', public bool $held = false) {}
}
