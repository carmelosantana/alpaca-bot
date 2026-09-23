<?php

declare(strict_types=1);

use AlpacaBot\Mcp\ClientFactory;
use AlpacaBot\Mcp\FakeClient;
use AlpacaBot\Mcp\McpUnavailable;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Mcp\UnavailableClient;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

function mcpServer(array $approved = []): ServerConfig
{
    return new ServerConfig('tracker', 'https://mcp.example.com/mcp', 'Authorization', 'Bearer t', 'trk', approved: $approved);
}

function mcpRefusal(\Closure $call): string
{
    try {
        $call();
    } catch (McpUnavailable $e) {
        return $e->getMessage();
    }
    return 'no McpUnavailable was thrown';
}

// toThrow() matches a message as a substring, so the exact sentence is asserted separately: the
// refusal is that one sentence and nothing about the server is appended to it.
it('builds the unavailable client until php-agents brings a real one, and says so in one sentence', function (): void {
    $client = (new ClientFactory())->for(mcpServer());
    expect($client)->toBeInstanceOf(UnavailableClient::class);
    expect(fn() => $client->listTools())->toThrow(McpUnavailable::class, McpUnavailable::NOT_YET);
    expect(fn() => $client->callTool('search', []))->toThrow(McpUnavailable::class, McpUnavailable::NOT_YET);
    expect(mcpRefusal(fn() => $client->listTools()))->toBe('The MCP client arrives with php-agents 0.16.')
        ->and(mcpRefusal(fn() => $client->callTool('search', [])))->toBe('The MCP client arrives with php-agents 0.16.');
});

it('builds whatever it was given instead, with the server it was asked about', function (): void {
    $seen = null;
    $fake = new FakeClient([new ToolDefinition('search', 'Search.', ['type' => 'object'])]);
    $factory = new ClientFactory(static function (ServerConfig $server) use ($fake, &$seen) {
        $seen = $server;
        return $fake;
    });
    expect($factory->for(mcpServer()))->toBe($fake)->and($seen?->prefix)->toBe('trk');
});

it('the fake lists what it was seeded with, records every call, and answers a canned result or an error', function (): void {
    $fake = new FakeClient(
        [new ToolDefinition('search', 'Search.', ['type' => 'object'])],
        ['search' => ToolResult::success('two hits'), 'boom' => new RuntimeException('down')],
    );
    expect($fake->listTools()[0]->name)->toBe('search')
        ->and($fake->listed)->toBe(1)
        ->and($fake->callTool('search', ['q' => 'x'])->content)->toBe('two hits')
        ->and($fake->calls)->toBe([['name' => 'search', 'arguments' => ['q' => 'x']]])
        ->and($fake->callTool('nothing', [])->status)->toBe(ToolResultStatus::Error);
    expect(fn() => $fake->callTool('boom', []))->toThrow(RuntimeException::class);
    expect(array_column($fake->calls, 'name'))->toBe(['search', 'nothing', 'boom']);
    $failing = new FakeClient([], [], new McpUnavailable('list failed'));
    expect(fn() => $failing->listTools())->toThrow(McpUnavailable::class);
    expect($failing->listed)->toBe(1);
});

/*
 * FakeClient ships in src/, so it is in the zip and autoloadable on every site. ClientFactory's
 * default builds an UnavailableClient, so a FakeClient exists only where some code constructs one.
 * This holds the lexical half of that: it reads alpaca-bot.php and every PHP file under src/ as
 * tokens, comments dropped, and fails on any name or string literal outside FakeClient's own file
 * that mentions the class. A class name assembled at run time is
 * not something a token scan can see.
 */
it('is named by no plugin code but its own file', function (): void {
    $root = dirname(__DIR__, 3);
    $files = [$root . '/alpaca-bot.php'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && $file->getPathname() !== $root . '/src/Mcp/FakeClient.php') {
            $files[] = $file->getPathname();
        }
    }
    $named = [];
    foreach ($files as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && str_contains($token[1], 'FakeClient')) {
                $named[] = substr($path, strlen($root) + 1) . ':' . $token[2];
            }
        }
    }
    expect(count($files))->toBeGreaterThan(1)->and($named)->toBe([]);
});
