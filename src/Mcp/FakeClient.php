<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * A client with no server behind it, for tests. listTools() answers with the definitions it was
 * seeded with, and callTool() with the result seeded under that tool's name, or an error
 * ToolResult when there is none.
 *
 * It is in src/ by the 0.6 plan's drafting decision 9, and so it ships in the zip with the rest of
 * src/. It holds no credentials and opens no connection. ClientFactory's default does not build
 * it: a FakeClient exists only where some code constructs one, and ClientFactoryTest fails if code
 * in alpaca-bot.php or src/, outside this file, names the class.
 *
 * It throws whatever Throwable it was seeded with, including one that is not McpUnavailable, so a
 * test can hand a caller an exception outside the interface's contract.
 *
 * `$calls` records every callTool() in order, and `$listed` counts every listTools(), each
 * including a call that then throws.
 *
 * @since 0.6.0
 */
final class FakeClient implements ClientInterface
{
    /** @var list<array{name: string, arguments: array<string, mixed>}> */
    public array $calls = [];

    public int $listed = 0;

    /**
     * @param list<ToolDefinition>                 $tools     what listTools() answers
     * @param array<string, ToolResult|\Throwable> $results   by tool name; a Throwable is thrown instead of answered
     * @param \Throwable|null                      $listError thrown by listTools() instead of answering
     */
    public function __construct(private array $tools = [], private array $results = [], private ?\Throwable $listError = null) {}

    public function listTools(): array
    {
        ++$this->listed;
        if ($this->listError !== null) {
            throw $this->listError;
        }
        return $this->tools;
    }

    public function callTool(string $name, array $arguments): ToolResult
    {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments];
        $result = $this->results[$name] ?? null;
        if ($result instanceof \Throwable) {
            throw $result;
        }
        return $result ?? ToolResult::error(sprintf('FakeClient has no canned result for %s.', $name));
    }
}
