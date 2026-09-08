<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;

/**
 * The chat assistant as a php-agents agent: the site's system prompt as its instructions, the
 * enabled toolkits as its tools, and the vendored tool loop (AbstractAgent::run()) to drive
 * them. Pipeline::send() builds one per tool turn.
 *
 * What the vendored source settles, read before this was written, so nothing here repeats it:
 *
 * - run() streams. Each iteration is `provider->stream($messages, $tools)`, never chat(), and
 *   every chunk's text and reasoning is announced as it arrives (`agent.text_delta`,
 *   `agent.reasoning`) before the iteration's tool calls run. The options argument is never
 *   passed: Provider\BoundOptionsProvider is how the site's settings reach those calls.
 * - The system prompt is the agent's own. run() builds it from instructions() through
 *   Prompt\SystemPrompt: identity, the iteration budget, and the guidelines() of every toolkit
 *   under a "TOOL USAGE RULES" heading; a system message in the history is skipped. So
 *   instructions() returns the site text alone and the guidelines are not appended here: they
 *   would appear twice.
 * - A `done` tool is always advertised alongside the toolkit tools, and a model that calls it
 *   ends the run with its `response` argument as the content, streamed to no one; the pipeline
 *   yields that content itself once the run is over.
 * - Output::$usage is cumulative: run() sums each iteration's usage into one total (its
 *   $totalUsage), so what the pipeline meters for a tool turn is every call the run made.
 *
 * Six iterations by default, against the library's twenty-five: a chat turn is one question,
 * and a model that has not answered after six round trips is looping, not working; each
 * iteration is a provider call the monthly cap pays for. The budget is told to the model in
 * the prompt (SystemPrompt::withIterationBudget()), so it can wrap up rather than be cut off.
 *
 * @since 0.5.0
 */
final class Assistant extends AbstractAgent
{
    public function __construct(ProviderInterface $provider, private string $instructionsText, int $maxIter = 6)
    {
        parent::__construct($provider, $maxIter);
    }

    public function instructions(): string
    {
        return $this->instructionsText;
    }

    public function name(): string
    {
        return 'Alpaca Bot';
    }
}
