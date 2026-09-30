<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolCallParser;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\DoneTool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * A faked tool call: what a model whose deployed template cannot really call tools writes into
 * its reply instead of calling one, and the reply with it taken back out. recovered() is the
 * entry point, and its docblock is the argument; markup(), excisions(), answer() and calls() are
 * the steps it takes that are public, so each can be tested on its own and so a stream can use
 * the same knowledge a finished reply does. pieces() and unfenced() stay private: they are two
 * steps of the search markup() runs, and nothing outside it has any use for them. Every method
 * is a function of its arguments: no state, and no WordPress.
 *
 * Extracted from Chat\Pipeline's private statics in 0.6 with no change in behaviour.
 *
 * @since 0.6.0
 */
final class FakedToolCall
{
    /** The marker a template emits before a call. */
    public const OPEN = '<tool_call>';

    /** The marker a template emits after one. */
    public const CLOSE = '</tool_call>';

    /**
     * The reply with a faked tool call taken back out of it, and the answer a faked `done`
     * buried in its `response` argument given back as the reply.
     *
     * Ollama advertises `tools` for a model whose deployed template is a bare `{{ .Prompt }}`
     * passthrough: no roles, no `.Tools`, nothing that parses a call back out. Offered tools,
     * such a model writes the call it was asked for as prose; the OpenAI-compatible endpoint
     * returns it as ordinary assistant content, and it arrives here as text. When the faked
     * call is the agent's own `done`, the user's entire answer is inside its `response` and
     * never reaches the bubble. Detection cannot close that: the capability the provider
     * reports is the thing that is wrong, and what fails is the deployed template, not the
     * parameter count (a 2B with a proper template works; a 27B with `{{ .Prompt }}` fails
     * identically), so this runs on the content whatever the catalogue said about the model.
     *
     * The marker is the trigger and the only one: content carrying neither `<tool_call>` nor
     * `</tool_call>` is returned untouched, and a plain answer never goes near a JSON parser.
     *
     * What the markers hold is not one payload. The recorded leak (qwen3-vl:2b, kept verbatim
     * as tests/fixtures/faked-tool-call-qwen3-vl-2b.txt) has no *opening* marker — the template
     * consumed it for a first call that did run properly through the API, and the raw text
     * leaked as well — a stray closing one, a second call with no markers at all, and two JSON
     * objects separated by a blank line, so json_decode() over the whole string is null. The
     * content is therefore searched for the byte ranges that are markup (markup(), which says
     * how) and only those ranges are taken out of it; everything else is copied across
     * verbatim, byte for byte, including the model's own indentation, its single newlines and
     * the spacing between its paragraphs. This never rebuilds the reply out of trimmed pieces:
     * a fix whose first rule is never losing text may not re-flow the text it keeps.
     *
     * A faked `done` gives up its `response`, in the markup's place. Every other faked call is
     * dropped and never executed: recovering text is one thing, running a tool the provider
     * never authorised through its own API is another. Nor is a recovered call added to
     * `meta['tool_calls']`, which stays the record of what the agent actually ran
     * (AgentStreamObserver): the leaked call in the fixture is a copy of one already on that
     * record, so listing it again would tell a reader of the transcript that a fetch was
     * attempted twice, and the record's `{result_excerpt, ok}` has no way to say "written, not
     * run" — it would read as a call made and unanswered, which is a different event.
     *
     * Three ways out return `$content` byte for byte, since losing text is the one outcome this
     * must never have: nothing in it was markup at all (prose that merely mentions the marker —
     * the guard against mangling an answer *about* tool calls); a recovered `done` whose
     * `response` is missing or blank (nothing to put in its place, so nothing is taken away);
     * and a recovery that came to nothing (a faked call and no other text, where the raw JSON
     * at least shows the user what happened and an empty bubble shows them nothing).
     *
     * The only whitespace this writes is at a seam. Markup taken out of the middle of a reply
     * leaves the blank line that preceded it against whatever followed it, so what followed
     * goes with it: the rest of the markup's own line and every blank line after it, stopping
     * at the first line with anything on it. That line's own indentation is never touched — it
     * is the model's, and shaving it off the first line of a four-space code block while the
     * rest of the block keeps its four does not merely lose indentation, it stops the block
     * being a block. Blank lines a removal leaves at the very top, along with trailing space,
     * go at the end.
     *
     * The deltas already streamed still carry the markup, flagged `held` from the opening marker
     * onward (FakedToolCallStream), so the screen keeps it out of sight while it arrives rather
     * than showing raw JSON. The front end replaces the assistant bubble wholesale when the turn
     * ends (`POST /view/bubble` with the stored reply), so what this returns is what the user is
     * left reading.
     *
     * @since 0.5.0 as Pipeline::recovered(), moved here unchanged in 0.6.0.
     */
    public static function recovered(string $content): string
    {
        if (!str_contains($content, self::OPEN) && !str_contains($content, self::CLOSE)) {
            return $content;
        }
        $edits = self::excisions($content, self::markup($content));
        if ($edits === null || $edits === []) {
            return $content;
        }
        $reply = '';
        $cursor = 0;
        foreach ($edits as [$start, $end, $answer]) {
            if ($start > $cursor) {
                $reply .= substr($content, $cursor, $start - $cursor);
            }
            $cursor = max($cursor, $end);
            if ($answer !== '') {
                $reply .= $answer;
                continue;
            }
            // The blank line before the hole belongs to the text that is staying; the blank
            // lines after it went with the markup, to the first line with anything on it, whose
            // own indentation is not whitespace this may write and is left alone. Only where a
            // line had already ended, though — never after an answer just written in the
            // markup's place, and never inside a line of prose.
            if (($reply === '' || preg_match('~\R[^\S\r\n]*\z~', $reply) === 1)
                && preg_match('~\A[^\S\r\n]*\R(?:[^\S\r\n]*\R)*~', substr($content, $cursor), $seam) === 1
            ) {
                $cursor += strlen($seam[0]);
            }
        }
        $reply .= substr($content, $cursor);
        $reply = rtrim((string) preg_replace('~\A(?:[^\S\r\n]*\R)+~', '', $reply));
        return $reply === '' ? $content : $reply;
    }

    /**
     * Every byte range of the reply that might be markup rather than the model's own words, in
     * written order, each with the faked calls it holds or null when it is a marker.
     *
     * Two passes. The content is cut at every marker first and each segment offered whole: the
     * recorded leak's two calls are one segment each, since the blank line before the second is
     * only leading whitespace to the parser. A segment that is not itself a payload is cut again
     * at every blank line, which is what finds a call the template left no marker around at all
     * — the shape the second half of the leak would have had if the first had not been marked
     * either, and the shape a leak takes once AgentStreamObserver::separated() has joined the
     * prose of one iteration to it. Only the pieces that parse are named here, and a name is a
     * byte range: the rest of the segment is never read out and never rewritten, so cutting a
     * segment that holds a call costs the answer around it nothing — not the indentation of a
     * code block in it, not the blank line inside that block.
     *
     * A marker is named only when a call stands against it: the whole segment on one side of it
     * is a call, or the nearest call the second pass found in that segment has nothing but
     * whitespace between itself and the marker. Both halves are needed. Without the first, the
     * markers around a marked call stay in the reply; without the second, the recorded leak's
     * own shape — a call with no opening marker and the stray closing one its template left
     * behind — is refused outright by excisions(), because the run it offers ends against a `<`
     * rather than a line break, and the user reads the raw JSON. A marker with prose between it
     * and the nearest call, or none near it at all, is a marker the model wrote into its prose
     * and stays in it.
     *
     * A fenced block outside the markers is refused: the vendored parser strips a Markdown code
     * fence and `arguments` is optional, so any fenced JSON object with a name would otherwise
     * read as a call, and an answer explaining tool-call syntax would lose its own example. A
     * fence is how a model *shows* a call; only a marker says it is making one. "Outside" is
     * every second-pass piece, and the whole of the head and tail segments too — an answer
     * opening on a fenced example with a stray marker further down is the head segment entire,
     * and the first pass would otherwise read it as a call and delete the example. Between an
     * opening and a closing marker a fence is the call's own formatting and is read as one.
     *
     * @return list<array{0: int, 1: int, 2: list<ToolCall>|null}>
     */
    public static function markup(string $content): array
    {
        // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- The idiomatic guard for preg_split()'s false return; ?: reads better here than repeating the whole call in a full ternary.
        $segments = preg_split('~' . preg_quote(self::OPEN, '~') . '|' . preg_quote(self::CLOSE, '~') . '~', $content, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $last = count($segments) - 1;
        $calls = [];
        $pieces = [];
        $against = [];
        foreach ($segments as $i => [$text, $offset]) {
            $end = $offset + strlen($text);
            $whole = trim($text);
            // Between two markers a fence is the call's own formatting; head and tail are
            // outside them, where a fence is an example the answer is entitled to keep.
            $calls[$i] = $i > 0 && $i < $last ? self::calls($whole) : self::unfenced($whole);
            $pieces[$i] = $calls[$i] === null ? self::pieces($text, $offset) : [];
            $first = $pieces[$i][0] ?? null;
            $final = $pieces[$i] === [] ? null : $pieces[$i][count($pieces[$i]) - 1];
            $against[$i] = [
                $calls[$i] !== null || ($first !== null && trim(substr($content, $offset, $first[0] - $offset)) === ''),
                $calls[$i] !== null || ($final !== null && trim(substr($content, $final[1], $end - $final[1])) === ''),
            ];
        }
        $found = [];
        $after = 0;
        foreach ($segments as $i => [$text, $offset]) {
            if ($i > 0 && ($against[$i - 1][1] || $against[$i][0])) {
                $found[] = [$after, $offset, null];
            }
            $after = $offset + strlen($text);
            $whole = $calls[$i];
            if ($whole !== null) {
                $found[] = [$offset, $after, $whole];
                continue;
            }
            foreach ($pieces[$i] as $piece) {
                $found[] = $piece;
            }
        }
        return $found;
    }

    /**
     * The ranges that really are markup, each with what goes in its place — a faked `done`'s
     * answer, or nothing at all for a faked call, which is dropped and never run. Null when a
     * recovered `done` carried no answer, which calls the whole recovery off.
     *
     * Candidates are taken a run at a time: a call and the markers around it are one range as
     * far as the reply is concerned, and the run has to stand on its own lines to be markup at
     * all. A template leaking a call emits it between newlines; a call written inside a line of
     * prose is a model writing *about* calls, and the line it sits in is the user's answer.
     * Missing an inline leak costs the user nothing — the answer is still there to read, in the
     * raw JSON that was not touched — where rewriting an inline mention costs them the sentence.
     *
     * @param list<array{0: int, 1: int, 2: list<ToolCall>|null}> $found
     * @return list<array{0: int, 1: int, 2: string}>|null
     */
    public static function excisions(string $content, array $found): ?array
    {
        $edits = [];
        $count = count($found);
        $end = 0;
        for ($i = 0; $i < $count; $i = $end) {
            // One run: a call and the markers around it touch, byte to byte.
            $end = $i + 1;
            while ($end < $count && $found[$end][0] === $found[$end - 1][1]) {
                ++$end;
            }
            if (preg_match('~(?:\A|\R)[^\S\r\n]*\z~', substr($content, 0, $found[$i][0])) !== 1
                || preg_match('~\A[^\S\r\n]*(?:\R|\z)~', substr($content, $found[$end - 1][1])) !== 1
            ) {
                continue;
            }
            for ($at = $i; $at < $end; $at++) {
                [$start, $stop, $calls] = $found[$at];
                $answer = $calls === null ? '' : self::answer($calls);
                if ($answer === null) {
                    return null;
                }
                $edits[] = [$start, $stop, $answer];
            }
        }
        return $edits;
    }

    /**
     * What a block of faked calls leaves behind: the answers the `done` calls in it carry, and
     * the empty string when it carries none. Null when a `done` came with no answer at all —
     * there is nothing to put in the block's place, so the recovery is off and the content
     * stands as the model wrote it.
     *
     * Trailing whitespace goes with the markup; leading whitespace does not. A `done` whose
     * answer is itself an indented block keeps the indentation of its first line.
     *
     * @param list<ToolCall> $calls
     */
    public static function answer(array $calls): ?string
    {
        $answers = [];
        foreach ($calls as $call) {
            if ($call->name !== DoneTool::NAME) {
                continue;
            }
            $response = $call->arguments['response'] ?? null;
            if (!is_string($response) || trim($response) === '') {
                return null;
            }
            $answers[] = rtrim($response);
        }
        return implode("\n\n", $answers);
    }

    /**
     * The faked tool calls one candidate block holds, or null when it is not a tool-call payload
     * at all and so is the model's own prose. Every way the vendored parser can refuse a block
     * reads the same way here — an empty payload, text that is not JSON, JSON that is not a
     * call, a call with no name or with arguments that are not an object — because each of them
     * says the same thing about the block: it is not a call, and it is not this method's to
     * take out of the reply. A payload the parser reads but finds no call in — `{"tool_calls":
     * []}` — says it too: nothing was faked there, so there is nothing to take out.
     *
     * @return non-empty-list<ToolCall>|null
     */
    public static function calls(string $block): ?array
    {
        try {
            $calls = array_values((new LlamaCppToolCallParser())->parse($block, 'json'));
        } catch (\Throwable) {
            return null;
        }
        return $calls === [] ? null : $calls;
    }

    /**
     * The faked calls in each blank-line-separated piece of one segment that is not itself a
     * call, as byte ranges of the whole reply. The cut is what finds a call the template left
     * no marker around; only the pieces that parse are named, so the answer around them is
     * never read out and never rewritten.
     *
     * @return list<array{0: int, 1: int, 2: list<ToolCall>}>
     */
    private static function pieces(string $text, int $offset): array
    {
        $found = [];
        // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- The idiomatic guard for preg_split()'s false return; ?: reads better here than repeating the whole call in a full ternary.
        foreach (preg_split('~\R[ \t]*\R~', $text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$piece, $at]) {
            $inside = self::unfenced(trim($piece));
            if ($inside !== null) {
                $found[] = [$offset + $at, $offset + $at + strlen($piece), $inside];
            }
        }
        return $found;
    }

    /**
     * The faked calls a block holds when it is not a Markdown code fence, and null when it is.
     * A fence is how a model shows a call rather than makes one, and outside the markers this
     * refusal is what keeps an answer explaining tool-call syntax in possession of its example.
     *
     * @return non-empty-list<ToolCall>|null
     */
    private static function unfenced(string $block): ?array
    {
        return preg_match('~^```(?:json)?\s*.*?\s*```$~si', $block) === 1 ? null : self::calls($block);
    }
}
