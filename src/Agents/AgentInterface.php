<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Agents;

interface AgentInterface
{
    public function getArg(array $args, string $name, $default);

    public function init();

    public function job(array $args, string $content): string;

    public function schema(array $agents): array;
}
