<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * The toolkits a tool turn hands the agent, with every tool whose name an earlier toolkit already
 * offers left out, so a name belongs to the first toolkit that offered it.
 *
 * The agent indexes tools by name and the last one to claim a name gets its calls
 * (AbstractAgent::collectAllToolsIndexed()). Registry::enabled() hands the built-ins over first,
 * in the order they were registered, then the MCP servers, then whatever `alpaca_bot/toolkits`
 * adds; left to the agent, a later toolkit could take a built-in tool's name, and the model's
 * calls to it, arguments and all. Chat\Pipeline::agentTurn() runs its toolkits through over(),
 * so the earlier one keeps the name and the later one's tool is not offered. A filter that
 * reorders what it returns decides the order this goes by.
 *
 * Within one toolkit nothing is left out: two tools of one toolkit under one name are that
 * toolkit's to settle, and Registry::tool() and the agent both take the last of them.
 *
 * over() asks each toolkit for its tools once, and the wrapper answers with that list from then
 * on. A toolkit left with no tools has no guidelines either, so the system prompt says nothing
 * about tools the model was not given.
 *
 * @since 0.6.0
 */
final class FirstWins implements ToolkitInterface
{
    /** @param list<ToolInterface> $tools */
    private function __construct(private ToolkitInterface $inner, private array $tools) {}

    /**
     * @param array<array-key, ToolkitInterface> $toolkits in the order the agent is to be handed them
     * @return list<self> one per toolkit, in the same order
     */
    public static function over(array $toolkits): array
    {
        $taken = [];
        $out = [];
        foreach ($toolkits as $toolkit) {
            $kept = [];
            $names = [];
            foreach ($toolkit->tools() as $tool) {
                if (!isset($taken[$tool->name()])) {
                    $kept[] = $tool;
                    $names[$tool->name()] = true;
                }
            }
            $taken += $names;
            $out[] = new self($toolkit, $kept);
        }
        return $out;
    }

    /** @return list<ToolInterface> */
    public function tools(): array
    {
        return $this->tools;
    }

    public function guidelines(): string
    {
        return $this->tools === [] ? '' : $this->inner->guidelines();
    }
}
