<?php

declare(strict_types=1);

namespace AlpacaBot\Agents;

use AlpacaBot\Utils\Options;

abstract class Agent implements AgentInterface
{
    private string $name;

    public function getArg($args, $name, $default = '')
    {
        return $args[$name] ?? $this->schema()[$this->name]['default'] ?? $default;
    }

    public function init()
    {
        add_filter(Options::appendPrefix('core_agents'), [$this, 'schema']);
    }

    public function job($args = [], $content = ''): string
    {
        return 'Error: No job defined for this agent.';
    }

    public function schema($agents = []): array
    {
        return $agents;
    }
}
