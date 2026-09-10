<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Rest\Errors;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * `summarize(text, length?)`: the text condensed by the model, for the model.
 *
 * A second model call from inside a turn, and it goes through the Pipeline rather than the
 * provider directly, on purpose: that is where the monthly caps are enforced, the usage
 * receipt written and every hook fired, and a tool that talked to the provider on its own
 * would be tokens the caps never saw. The call is `ephemeral` (Pipeline's docblock), so the
 * inner turn is billed to the acting user with conversation 0 and never appears in their
 * history as a chat of its own, and it carries no `context` and its own `system`: the site's
 * prompt and the screen's context are for a conversation, and they would tell the model to
 * converse with the text instead of condensing it. The default model applies (the user's
 * choice, then the site's); the turn is the user's, so their preference is what runs it.
 *
 * The acting user is a closure resolved when the tool runs, for the reason DraftPostToolkit
 * gives. The length is an enum of three words the model can pick without being told what a
 * paragraph is; the words are mapped to an instruction here, so a change of wording never
 * changes the tool's schema.
 *
 * The pipeline refuses an empty text and throws for a cap or a provider failure, and tool()
 * turns that into an error result, so the agent loop sees a tool error and not an exception out
 * of the turn it was running. Not left to Tool::execute()'s own catch: that one reports the
 * raw message, and a provider's quotes its endpoint (tool() says what that would cost).
 *
 * The description and guidelines are English on purpose (see WebFetchToolkit); the pipeline's
 * own errors are already translated.
 *
 * @since 0.5.0
 */
final class SummarizeToolkit implements ToolkitInterface
{
    /** What each length asks for. `medium` is the default. */
    private const LENGTHS = [
        'short' => 'in one or two sentences',
        'medium' => 'in one paragraph',
        'long' => 'in three to five short paragraphs, one per main point',
    ];

    /** @param \Closure(): int $userId the acting user's id, resolved when the tool runs */
    public function __construct(private Pipeline $pipeline, private \Closure $userId) {}

    public function tools(): array
    {
        return [new Tool(
            'summarize',
            'Summarize a piece of text: an article you fetched, a long message, a draft. Returns only the summary.',
            [
                new StringParameter('text', 'The text to summarize.'),
                new EnumParameter('length', 'How long the summary should be: short (a sentence or two), medium (a paragraph, the default), or long (several paragraphs).', array_keys(self::LENGTHS), false),
            ],
            fn(array $args): ToolResult => $this->tool((string) $args['text'], (string) ($args['length'] ?? 'medium')),
        )];
    }

    /**
     * The tool as the model calls it: summarize(), with a throw turned into a tool error whose
     * words are fit to be read.
     *
     * Tool::execute() would turn the throw into a result too, but with the raw message
     * (`catch (\Throwable $e) { return ToolResult::error($e->getMessage()); }`), and what the
     * provider throws quotes its endpoint — the text Rest\Errors::provider() withholds from
     * anyone who is not an administrator. A tool result is text the model reads and may repeat,
     * and on a front-end `[alpacabot]` turn the model's output is on its way to a page. So the
     * same policy every other caller of a pipeline turn applies is applied here:
     * Errors::fromPipeline() keeps the cap's and the caller's-mistake messages, which are
     * already written for a person, replaces a provider failure with the fixed one, and puts
     * the raw text in the debug log where an operator can read it. Only the message is taken;
     * the status and the data are the REST wire contract and mean nothing to a model.
     *
     * Abilities\Register calls summarize() directly rather than going through this, because it
     * needs the throw's class to keep the refusal's code (its docblock says so).
     */
    private function tool(string $text, string $length): ToolResult
    {
        try {
            return $this->summarize($text, $length);
        } catch (\Throwable $e) {
            return ToolResult::error(Errors::fromPipeline($e)->get_error_message());
        }
    }

    public function guidelines(): string
    {
        return 'Use summarize when the user asks for the gist of something long, or when a page you fetched is too long to reason about in full. Pass the text itself, not a description of it.';
    }

    /**
     * The turn itself. Public for Abilities\Register, which calls it directly instead of through
     * the Tool above: Tool::execute() turns every throw into a text error for the model, and the
     * ability needs the throw (a spent cap, a provider failure) to keep its type so it can be
     * refused with the same code the REST route uses and without the provider's message. What
     * the model gets is unchanged. `$length` is one of LENGTHS; anything else reads as medium.
     *
     * @throws \AlpacaBot\Chat\CapExceeded|\InvalidArgumentException|\RuntimeException as Pipeline::complete()
     */
    public function summarize(string $text, string $length): ToolResult
    {
        $result = $this->pipeline->complete(($this->userId)(), $text, [
            'system' => 'Summarize the text the user sends ' . (self::LENGTHS[$length] ?? self::LENGTHS['medium']) . '. Return only the summary: no preamble, no commentary, and nothing that is not in the text.',
            'context' => [],
            'ephemeral' => true,
        ]);
        return ToolResult::success(trim($result->reply->content));
    }
}
