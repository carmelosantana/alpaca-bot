<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Mcp\ClientInterface;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * A client with no server behind it, for tests. listTools() answers with the definitions it was
 * seeded with, and callTool() with the result seeded under that tool's name, or an error
 * ToolResult when there is none.
 *
 * Both suites autoload it: the unit suite through the root composer.json's `autoload-dev`
 * (`AlpacaBot\Tests\` to tests/), and the integration suite through tools/integration/composer.json
 * (`AlpacaBot\Tests\Integration\` to tests/Integration/). `.distignore` drops `tests` from the
 * zip, so the class is not in the plugin a site installs. It holds no credentials and opens no
 * connection.
 *
 * It steps outside ClientInterface's contract in two ways, so a test can reach a caller's edge
 * cases: it throws whatever Throwable it was seeded with, McpUnavailable or not, and it answers an
 * unseeded name with an error ToolResult that no server sent.
 *
 * `$calls` records every callTool() in order, and `$listed` counts every listTools(), each
 * including a call that then throws.
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
