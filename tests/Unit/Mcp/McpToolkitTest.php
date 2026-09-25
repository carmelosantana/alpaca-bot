<?php

declare(strict_types=1);

use AlpacaBot\Mcp\ClientFactory;
use AlpacaBot\Mcp\McpToolkit;
use AlpacaBot\Mcp\McpUnavailable;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Tests\Integration\FakeClient;
use AlpacaBot\Toolkit\AddressRefused;
use AlpacaBot\Toolkit\SchemaTool;
use AlpacaBot\Toolkit\ToolName;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

// One remote MCP server as a toolkit: only what an administrator approved, only while the live
// definition still hashes to the pin, named `{prefix}__{name}`, and every call announced on
// `alpaca_bot/mcp/called`. FakeClient (tests/Integration/FakeClient.php) stands in for the
// server; nothing here opens a connection.

beforeEach(function (): void {
    // SchemaTool::describe()'s first step, as McpToolsTest stands it in.
    Functions\when('wp_strip_all_tags')->alias(static fn(string $s): string => strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $s)));
});

/**
 * A toolkit over a factory that answers `$client`, or throws `$client` when it is a Throwable;
 * `$built` counts the builds.
 *
 * @param array<string, string> $approved tool name => pinned fingerprint
 */
function mcpToolkit(FakeClient|\Throwable $client, array $approved, ?array &$drift = null, ?int &$built = null): McpToolkit
{
    $built = 0;
    $server = new ServerConfig('trk', 'https://mcp.example.com/mcp', 'Authorization', 'Bearer t', 'trk', approved: $approved);
    $factory = new ClientFactory(static function (ServerConfig $s) use ($client, &$built): FakeClient {
        ++$built;
        return $client instanceof \Throwable ? throw $client : $client;
    });
    return new McpToolkit($factory, $server, 3, static function (string $id, array $names) use (&$drift): void {
        $drift = [$id, $names];
    });
}

it('exposes only an approved tool whose definition still hashes the same, named {prefix}__{name}, with the description as the Tools tab shows it', function (): void {
    $search = new ToolDefinition('search', "Search <b>the</b>\n\nindex.", ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]);
    $report = new ToolDefinition('report', 'Report, differently now.', ['type' => 'object']);
    $secret = new ToolDefinition('secret', 'Never approved.', ['type' => 'object']);
    $kit = mcpToolkit(new FakeClient([$search, $report, $secret]), ['search' => $search->fingerprint(), 'report' => str_repeat('0', 64)], $drift);
    expect(array_map(static fn($t): string => $t->name(), $kit->tools()))->toBe(['trk__search'])
        ->and($kit->tools()[0]->toFunctionSchema()['function']['parameters'])->toBe($search->inputSchema)
        ->and($kit->tools()[0]->description())->toBe(SchemaTool::describe($search->description))
        ->and($kit->tools()[0]->description())->toBe('Search the index.')
        ->and($drift)->toBe(['trk', ['report']]);
});

it('reports no drift, which clears the marker, when every approved tool still matches', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    mcpToolkit(new FakeClient([$search]), ['search' => $search->fingerprint()], $drift)->tools();
    expect($drift)->toBe(['trk', []]);
});

// Building a client can fail on its own (a real builder checks the address, and its refusal names
// the host), so it is built when the tools are first asked for, inside the same catch as the
// listing: a server whose client cannot be built is a turn with no remote tools, and a caller
// that never asks for tools builds nothing.
it('builds the client only when its tools are asked for, once, and calls through the client that listed them', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $client = new FakeClient([$search], ['search' => ToolResult::success('hit')]);
    $kit = mcpToolkit($client, ['search' => $search->fingerprint()], $drift, $built);
    expect($built)->toBe(0);
    $kit->tools()[0]->execute(['q' => 'x']);
    $kit->guidelines();
    expect($built)->toBe(1)
        ->and($client->listed)->toBe(1)
        ->and($client->calls)->toBe([['name' => 'search', 'arguments' => ['q' => 'x']]]);
});

it('offers nothing, and says nothing of the host, when the client cannot be built', function (): void {
    $refused = new AddressRefused('mcp.example.com does not resolve, or its lookup failed.');
    $drift = ['untouched'];
    $kit = mcpToolkit($refused, ['search' => str_repeat('a', 64)], $drift, $built);
    expect($kit->tools())->toBe([])
        ->and($kit->guidelines())->toBe('')
        ->and($built)->toBe(1)
        ->and($drift)->toBe(['untouched']);
});

it('lists the server once however often its tools are asked for', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $client = new FakeClient([$search]);
    $kit = mcpToolkit($client, ['search' => $search->fingerprint()]);
    $kit->tools();
    $kit->tools();
    $kit->guidelines();
    expect($client->listed)->toBe(1);
});

it('calls the server under the name the server knows, and announces the call with its arguments, the turn\'s user and what came back', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $client = new FakeClient([$search], ['search' => ToolResult::success('two hits')]);
    Actions\expectDone('alpaca_bot/mcp/called')->once()->with('trk', 'search', ['q' => 'x'], 3, Mockery::type(ToolResult::class));
    expect(mcpToolkit($client, ['search' => $search->fingerprint()])->tools()[0]->execute(['q' => 'x'])->content)->toBe('two hits')
        ->and($client->calls)->toBe([['name' => 'search', 'arguments' => ['q' => 'x']]]);
});

it('hands the action the result as the client answered it, before SchemaTool cuts it for the model', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $long = str_repeat('a', SchemaTool::RESULT_CHARS + 10);
    $client = new FakeClient([$search], ['search' => ToolResult::success($long)]);
    $heard = null;
    Actions\expectDone('alpaca_bot/mcp/called')->once()->whenHappen(static function (string $id, string $tool, array $arguments, int $user, ToolResult $result) use (&$heard): void {
        $heard = $result->content;
    });
    $given = mcpToolkit($client, ['search' => $search->fingerprint()])->tools()[0]->execute([])->content;
    expect($heard)->toBe($long)
        ->and($given)->toBe(str_repeat('a', SchemaTool::RESULT_CHARS) . SchemaTool::CUT_MARKER);
});

it('offers nothing when the server cannot be listed, and leaves the drift marker alone', function (): void {
    $drift = ['untouched'];
    expect(mcpToolkit(new FakeClient([], [], new McpUnavailable('The MCP server could not be reached.')), ['search' => 'x'], $drift)->tools())->toBe([])
        ->and($drift)->toBe(['untouched']);
    // FakeClient may throw what ClientInterface does not promise; a down server is still no
    // remote tools, never a failed turn.
    expect(mcpToolkit(new FakeClient([], [], new \Error('bad frame')), ['search' => 'x'], $drift)->tools())->toBe([])
        ->and($drift)->toBe(['untouched']);
});

it('never lists a server with nothing approved', function (): void {
    $client = new FakeClient([new ToolDefinition('search', 'Search.', ['type' => 'object'])]);
    $drift = ['untouched'];
    expect(mcpToolkit($client, [], $drift)->tools())->toBe([])
        ->and($client->listed)->toBe(0)
        ->and($drift)->toBe(['untouched']);
});

it('turns a failed call into an error that names the prefix and no host, and still announces it', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    foreach ([new McpUnavailable('POST https://mcp.example.com/mcp failed'), new \Error('https://mcp.example.com/mcp broke')] as $thrown) {
        $client = new FakeClient([$search], ['search' => $thrown]);
        $heard = null;
        Actions\expectDone('alpaca_bot/mcp/called')->once()->whenHappen(static function (string $id, string $tool, array $arguments, int $user, ToolResult $result) use (&$heard): void {
            $heard = $result;
        });
        $result = mcpToolkit($client, ['search' => $search->fingerprint()])->tools()[0]->execute([]);
        expect($result->status)->toBe(ToolResultStatus::Error)
            ->and($result->content)->not->toContain('mcp.example.com')
            ->and($result->content)->toContain('trk')
            ->and($heard)->toBe($result);
    }
});

// Carry 4: neither the model nor the action is handed anything a failed call chains: the result is
// the plugin's fixed sentence, whatever the McpUnavailable carries as `previous`.
it('hands the model and the action nothing of a failed call\'s chained JSON-RPC error', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $rpc = new AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Mcp\McpRpcException('tools/call', -32603, 'server words SECRETWORDS', ['leak' => 'SECRETDATA']);
    $client = new FakeClient([$search], ['search' => new McpUnavailable('The MCP server answered with JSON-RPC error -32603.', 0, $rpc)]);
    $heard = null;
    Actions\expectDone('alpaca_bot/mcp/called')->once()->whenHappen(static function (string $id, string $tool, array $arguments, int $user, ToolResult $result) use (&$heard): void {
        $heard = $result;
    });
    $result = mcpToolkit($client, ['search' => $search->fingerprint()])->tools()[0]->execute([]);
    expect(serialize([$result, $heard]))->not->toContain('SECRETWORDS')->not->toContain('SECRETDATA')->not->toContain('-32603')
        ->and($result->status)->toBe(ToolResultStatus::Error);
});

it('offers neither copy of a name the listing repeats, even under a pin a save kept without looking', function (): void {
    // View\Settings\McpTools gives a repeated name no box, but a save that never ran Discover
    // posts the stored approvals back as they were, so the pin can outlive the listing change.
    $one = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $two = new ToolDefinition('search', 'Search, the other one.', ['type' => 'object']);
    $other = new ToolDefinition('fetch', 'Fetch.', ['type' => 'object']);
    $kit = mcpToolkit(new FakeClient([$one, $two, $other]), ['search' => $one->fingerprint(), 'fetch' => $other->fingerprint()], $drift);
    expect(array_map(static fn($t): string => $t->name(), $kit->tools()))->toBe(['trk__fetch'])
        // Discovery records the name once when any copy is changed; so does this, so Discovery
        // and this toolkit agree about the same listing.
        ->and($drift)->toBe(['trk', ['search']]);
    $same = mcpToolkit(new FakeClient([$one, $one]), ['search' => $one->fingerprint()], $drift);
    expect($same->tools())->toBe([])->and($drift)->toBe(['trk', []]);
    $both = mcpToolkit(new FakeClient([$two, $two]), ['search' => $one->fingerprint()], $drift);
    expect($both->tools())->toBe([])->and($drift)->toBe(['trk', ['search']]);
});

it('offers neither of two approved tools that reach the model under one name', function (): void {
    // ToolName::fit() is not one-to-one: a long name is cut and marked with a hash, and a server
    // can name another tool what that comes to.
    $long = new ToolDefinition(str_repeat('x', 100), 'Long.', ['type' => 'object']);
    $fitted = ToolName::fit('trk__' . $long->name);
    $short = new ToolDefinition(substr($fitted, strlen('trk__')), 'Short.', ['type' => 'object']);
    $other = new ToolDefinition('fetch', 'Fetch.', ['type' => 'object']);
    expect(ToolName::fit('trk__' . $short->name))->toBe($fitted);
    $kit = mcpToolkit(new FakeClient([$long, $short, $other]), [$long->name => $long->fingerprint(), $short->name => $short->fingerprint(), 'fetch' => $other->fingerprint()]);
    expect(array_map(static fn($t): string => $t->name(), $kit->tools()))->toBe(['trk__fetch']);
});

it('tells the model what a remote tool is before it calls one', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    expect(mcpToolkit(new FakeClient([$search]), ['search' => $search->fingerprint()])->guidelines())->toContain('trk__')
        ->and(mcpToolkit(new FakeClient([]), [])->guidelines())->toBe('');
});
