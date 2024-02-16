<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Agents;

abstract class Agent implements AgentInterface
{
    private string $name;

    public function getArg($args, $name, $default = '')
    {
        return $args[$name] ?? $this->schema()[$this->name]['default'] ?? $default;
    }

    public function init()
    {
        add_filter('alpaca_bot_core_agents', [$this, 'schema']);
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
