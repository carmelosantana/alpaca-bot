<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * Says, as a tool turn's text streams, which bytes are inside a faked tool call's markup, so a
 * client can keep them out of sight while the call is open (Kanboard #4329).
 *
 * It marks and never withholds. Every byte fed in comes back out, in order, in pieces flagged
 * held or not, because three things downstream need the deltas to be the whole reply:
 * Pipeline::send() accumulates the stored content from them, FakedToolCall::recovered() then
 * works on that content byte for byte, and docs/api.md publishes that a turn's `text` deltas
 * concatenate to what the model wrote. A client that ignores the flag sees what 0.5 showed.
 *
 * Held runs from an opening marker through its closing one, and nothing else. A block whose
 * opening marker the template consumed — the recorded leak, tests/fixtures/
 * faked-tool-call-qwen3-vl-2b.txt, a bare call followed by a stray closing marker — is not held:
 * knowing it was a call means parsing it, and parsing it means holding every byte of every reply
 * until the turn ends, which is the "buffer the whole stream" this ticket rejected. That case
 * streams as it did in 0.5 and the `done` frame's re-render is what corrects it.
 *
 * A marker may arrive split across two deltas, so a tail that could be the start of the marker
 * being looked for is kept back until the next delta says whether it was: at most
 * strlen(FakedToolCall::CLOSE) - 1 bytes, which flush() hands over when the run ends, so nothing
 * waits past the turn and no byte is ever dropped.
 *
 * @since 0.6.0
 */
final class FakedToolCallStream
{
    /** Bytes kept back because they could be the beginning of the marker being looked for. */
    private string $tail = '';

    private bool $open = false;

    /**
     * `$text` as pieces ready to send, each `[text, held]`. Adjacent pieces of one call never
     * share a flag; pieces of separate calls may, and a consumer that cares joins them.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public function feed(string $text): array
    {
        $buffer = $this->tail . $text;
        $this->tail = '';
        $out = [];
        while ($buffer !== '') {
            $marker = $this->open ? FakedToolCall::CLOSE : FakedToolCall::OPEN;
            $at = strpos($buffer, $marker);
            if ($at === false) {
                $keep = self::partial($buffer, $marker);
                self::add($out, substr($buffer, 0, strlen($buffer) - $keep), $this->open);
                $this->tail = $keep === 0 ? '' : substr($buffer, -$keep);
                break;
            }
            $end = $at + strlen($marker);
            if ($this->open) {
                // The closing marker is the block's own last bytes, so it is held with it.
                self::add($out, substr($buffer, 0, $end), true);
            } else {
                self::add($out, substr($buffer, 0, $at), false);
                self::add($out, $marker, true);
            }
            $this->open = !$this->open;
            $buffer = substr($buffer, $end);
        }
        return $out;
    }

    /**
     * Whatever feed() kept back, for the end of the run, flagged as the state it was kept in.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public function flush(): array
    {
        $tail = $this->tail;
        $this->tail = '';
        return $tail === '' ? [] : [[$tail, $this->open]];
    }

    /** Whether a block is open: an opening marker has arrived and its closing one has not. */
    public function open(): bool
    {
        return $this->open;
    }

    /**
     * A complete `$text` as the pieces a streamed one would have become: one scanner's feed()
     * and flush(), joined where the two meet.
     *
     * Pipeline::agentTurn()'s tail is the caller and the reason this exists. That Delta is the
     * one text a streamed agent turn can emit that no live scanner has seen — an answer the
     * agent gave through its `done` tool, or any iteration whose content never arrived as
     * `agent.text_delta` — so without this it would reach the client unflagged, which is the
     * raw JSON this whole class exists to keep off the screen.
     *
     * A *fresh* scanner, not the observer's, and that is the point: the tail is the Output's own
     * content, a separate message from the one that streamed, so inheriting an open block from
     * text it does not continue would flag its opening bytes for a marker that was never in it.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function pieces(string $text): array
    {
        $stream = new self();
        $out = $stream->feed($text);
        foreach ($stream->flush() as [$piece, $held]) {
            self::add($out, $piece, $held);
        }
        return $out;
    }

    /** The length of the longest end of `$buffer` that is a proper beginning of `$marker`. */
    private static function partial(string $buffer, string $marker): int
    {
        for ($n = min(strlen($buffer), strlen($marker) - 1); $n > 0; $n--) {
            if (str_starts_with($marker, substr($buffer, -$n))) {
                return $n;
            }
        }
        return 0;
    }

    /**
     * @param list<array{0: string, 1: bool}> $out
     * @param-out list<array{0: string, 1: bool}> $out
     */
    private static function add(array &$out, string $text, bool $held): void
    {
        if ($text === '') {
            return;
        }
        $last = count($out) - 1;
        if ($last >= 0 && $out[$last][1] === $held) {
            $out[$last][0] .= $text;
            return;
        }
        $out[] = [$text, $held];
    }
}
