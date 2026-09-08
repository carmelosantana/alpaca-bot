<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Chat\Pipeline;
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
 * The pipeline refuses an empty text and throws for a cap or a provider failure; Tool::execute()
 * turns any throw into an error result, so the agent loop sees a tool error, not an exception
 * out of the turn it was running.
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
            fn(array $args): ToolResult => $this->summarize((string) $args['text'], (string) ($args['length'] ?? 'medium')),
        )];
    }

    public function guidelines(): string
    {
        return 'Use summarize when the user asks for the gist of something long, or when a page you fetched is too long to reason about in full. Pass the text itself, not a description of it.';
    }

    private function summarize(string $text, string $length): ToolResult
    {
        $result = $this->pipeline->complete(($this->userId)(), $text, [
            'system' => 'Summarize the text the user sends ' . (self::LENGTHS[$length] ?? self::LENGTHS['medium']) . '. Return only the summary: no preamble, no commentary, and nothing that is not in the text.',
            'context' => [],
            'ephemeral' => true,
        ]);
        return ToolResult::success(trim($result->reply->content));
    }
}
