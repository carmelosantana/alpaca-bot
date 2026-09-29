<?php

declare(strict_types=1);

use AlpacaBot\Mcp\ClientFactory;
use AlpacaBot\Mcp\Discovery;
use AlpacaBot\Mcp\McpUnavailable;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Tests\Integration\FakeClient;
use Brain\Monkey\Functions;

// Discovery is what an administrator sees before approving, so it says three things about each
// tool: whether it is already approved, whether it is approved but no longer the definition
// that was approved, or whether it is new -- and, for a new one, whether the server calls it
// destructive, which is a claim the specification says to treat as untrusted.

/** @param array<string, mixed>|null $drift filled with what set_transient()/delete_transient() were handed, by key; null for a delete */
function mcpDiscovery(array $tools, array $approved = [], ?Throwable $listError = null, ?array &$drift = null, ?ServerConfig &$asked = null): Discovery
{
    $drift = [];
    Functions\when('set_transient')->alias(static function (string $key, mixed $value) use (&$drift): bool {
        $drift[$key] = $value;
        return true;
    });
    Functions\when('delete_transient')->alias(static function (string $key) use (&$drift): bool {
        $drift[$key] = null;
        return true;
    });
    Functions\when('get_option')->justReturn(['trk' => 'Bearer t']);
    $store = new Store(['toolkits.mcp_servers' => [
        ['id' => 'other', 'url' => 'https://other.example.com/mcp', 'header_value' => '', 'prefix' => 'oth'],
        [
            'id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization',
            'header_value' => Schema::MASK, 'prefix' => 'trk', 'timeout' => 30.0, 'max_bytes' => 1048576,
            'approved' => $approved,
        ],
    ]]);
    return new Discovery($store, new ClientFactory(static function (ServerConfig $server) use ($tools, $listError, &$asked): FakeClient {
        $asked = $server;
        return new FakeClient($tools, [], $listError);
    }));
}

it('reads the stored server and merges in the header value the option does not carry', function (): void {
    $server = mcpDiscovery([])->server('trk');
    expect($server?->id)->toBe('trk')
        ->and($server?->prefix)->toBe('trk')
        ->and($server?->url)->toBe('https://mcp.example.com/mcp')
        ->and($server?->headerValue)->toBe('Bearer t')
        ->and(mcpDiscovery([])->server('nope'))->toBeNull()
        // A row with no header value keeps none: the mask is what reads the secrets option.
        ->and(mcpDiscovery([])->server('other')?->headerValue)->toBe('')
        ->and(mcpDiscovery([])->server('')?->id)->toBeNull();
});

// M7 (R101): a row written round the schema (a hand edit, `wp option update`) is not a server here
// unless its id is one Schema::isMcpId() admits, and one ServerConfig::fromSettings() cannot read
// (an object where a string belongs) is no server either, rather than an Error out of the route.
it('finds no server for an id the schema would not admit, or for a row it cannot read', function (): void {
    Functions\when('get_option')->justReturn([]);
    $store = new Store(['toolkits.mcp_servers' => [
        ['id' => '1trk', 'url' => 'https://mcp.example.com/mcp', 'header_value' => '', 'prefix' => 'one'],
        ['id' => 'Trk', 'url' => 'https://mcp.example.com/mcp', 'header_value' => '', 'prefix' => 'up'],
        ['id' => 'obj', 'url' => new stdClass(), 'header_value' => '', 'prefix' => 'obj'],
        ['id' => 'ok', 'url' => 'https://mcp.example.com/mcp', 'header_value' => '', 'prefix' => 'ok'],
        // Secrets::resolve() runs before the catch, and reads a value that is not a string as none.
        ['id' => 'hv', 'url' => 'https://mcp.example.com/mcp', 'header_value' => new stdClass(), 'prefix' => 'hv'],
    ]]);
    $discovery = new Discovery($store, new ClientFactory());
    expect($discovery->server('1trk'))->toBeNull()
        ->and($discovery->server('Trk'))->toBeNull()
        ->and($discovery->server('obj'))->toBeNull()
        ->and($discovery->server('ok')?->id)->toBe('ok')
        ->and($discovery->server('hv')?->headerValue)->toBe('');
});

it('marks each tool approved, changed or new, and leaves a destructive new tool unticked', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $write = new ToolDefinition('write', 'Write.', ['type' => 'object'], ['destructiveHint' => true]);
    $report = new ToolDefinition('report', 'Report, differently now.', ['type' => 'object']);
    $discovery = mcpDiscovery([$search, $write, $report], ['search' => $search->fingerprint(), 'report' => str_repeat('0', 64)], drift: $drift, asked: $asked);
    $tools = $discovery->tools($discovery->server('trk'));
    expect(array_column($tools, 'state'))->toBe(['approved', 'new', 'changed'])
        ->and(array_column($tools, 'ticked'))->toBe([true, false, false])
        ->and(array_column($tools, 'definition'))->toBe([$search, $write, $report])
        ->and($tools[0]['fingerprint'])->toBe($search->fingerprint())
        ->and($tools[1]['fingerprint'])->toBe($write->fingerprint())
        ->and($tools[2]['fingerprint'])->toBe($report->fingerprint())
        ->and($drift['alpaca_bot_mcp_drift_trk'])->toBe(['report'])
        // The factory is asked about the server that was read, header value and all.
        ->and($asked?->headerValue)->toBe('Bearer t');
});

// R83: only a tool whose destructiveHint is exactly true starts unticked. A new tool with no
// annotation, or with any other value there, starts ticked.
it('ticks a new tool unless its destructiveHint is exactly true', function (): void {
    $plain = new ToolDefinition('plain', 'Plain.', ['type' => 'object']);
    $readOnly = new ToolDefinition('peek', 'Peek.', ['type' => 'object'], ['readOnlyHint' => true]);
    $stringy = new ToolDefinition('maybe', 'Maybe.', ['type' => 'object'], ['destructiveHint' => 'true']);
    $discovery = mcpDiscovery([$plain, $readOnly, $stringy]);
    $tools = $discovery->tools($discovery->server('trk'));
    expect(array_column($tools, 'state'))->toBe(['new', 'new', 'new'])
        ->and(array_column($tools, 'ticked'))->toBe([true, true, true]);
});

// An approved tool stays ticked whatever the server says about it now: its approval was a
// person's, of this very definition, and the annotation is part of what was fingerprinted.
it('keeps an approved destructive tool ticked', function (): void {
    $write = new ToolDefinition('write', 'Write.', ['type' => 'object'], ['destructiveHint' => true]);
    $discovery = mcpDiscovery([$write], ['write' => $write->fingerprint()]);
    $tools = $discovery->tools($discovery->server('trk'));
    expect($tools[0]['state'])->toBe('approved')->and($tools[0]['ticked'])->toBeTrue();
});

it('clears the drift marker when nothing is drifting any more', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $discovery = mcpDiscovery([$search], ['search' => $search->fingerprint()], drift: $drift);
    $discovery->tools($discovery->server('trk'));
    expect($drift)->toHaveKey('alpaca_bot_mcp_drift_trk')
        ->and($drift['alpaca_bot_mcp_drift_trk'])->toBeNull();
});

it('lets the reason a server could not be listed reach the caller, and leaves the drift marker as it was', function (): void {
    $discovery = mcpDiscovery([], [], new McpUnavailable('The MCP server answered with JSON-RPC error -32601.'), drift: $drift);
    expect(fn() => $discovery->tools($discovery->server('trk')))->toThrow(McpUnavailable::class, 'The MCP server answered with JSON-RPC error -32601.');
    expect($drift)->toBe([]);
});

// Building the client can fail before anything is listed (PhpAgentsClient::over() refuses a header
// name that is a whole number); that reaches the caller the same way, and nothing is recorded.
it('lets a client the factory refuses to build reach the caller, and leaves the drift marker as it was', function (): void {
    $discovery = mcpDiscovery([], drift: $drift);
    $refusing = new Discovery(new Store(['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]]), new ClientFactory(static function (ServerConfig $server): never {
        throw new McpUnavailable('This server was not contacted.');
    }));
    expect(fn() => $refusing->tools($refusing->server('trk')))->toThrow(McpUnavailable::class, 'This server was not contacted.');
    expect($drift)->toBe([]);
});

// Task 28.3: whatever else building or listing throws reaches the caller as McpUnavailable, so the
// Discover route has one failure to catch. AddressRefused's message is the plugin's own (Egress,
// AddressPin) and is kept; any other throwable's text is not the plugin's and is replaced.
it('turns an address the factory refuses into McpUnavailable with the refusal\'s own message, and leaves the drift marker as it was', function (): void {
    $discovery = mcpDiscovery([], drift: $drift);
    $refused = new AlpacaBot\Toolkit\AddressRefused('mcp.example.com resolves to 10.0.0.7, a private, local or other special-purpose address.');
    $refusing = new Discovery(new Store(['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]]), new ClientFactory(static function (ServerConfig $server) use ($refused): never {
        throw $refused;
    }));
    try {
        $refusing->tools($refusing->server('trk'));
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(McpUnavailable::class)
        ->and($thrown?->getMessage())->toBe($refused->getMessage())
        ->and($thrown?->getPrevious())->toBe($refused)
        ->and($drift)->toBe([]);
});

it('turns any other throwable from the build or the listing into McpUnavailable with the plugin\'s sentence, none of its own text', function (Closure $build): void {
    $discovery = mcpDiscovery([], drift: $drift);
    $failing = new Discovery(new Store(['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]]), new ClientFactory($build));
    try {
        $failing->tools($failing->server('trk'));
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(McpUnavailable::class)
        ->and($thrown?->getMessage())->toBe('The MCP client failed in a way this plugin does not recognise, so the server was not listed.')
        ->and($thrown?->getMessage())->not->toContain('LIBRARYWORDS')
        ->and($drift)->toBe([]);
})->with([
    'a RuntimeException from the build' => [static fn(ServerConfig $s): never => throw new RuntimeException('LIBRARYWORDS https://mcp.example.com/mcp?token=x')],
    'an InvalidArgumentException from the build' => [static fn(ServerConfig $s): never => throw new InvalidArgumentException('LIBRARYWORDS')],
    'a TypeError from the build' => [static fn(ServerConfig $s): never => throw new TypeError('LIBRARYWORDS')],
    'a LogicException from the listing' => [static fn(ServerConfig $s): FakeClient => new FakeClient([], [], new LogicException('LIBRARYWORDS'))],
]);

// M-2: a name the listing repeats cannot be approved (View\Settings\McpTools offers no box), so
// no copy starts ticked. Each copy keeps the state its own fingerprint gives it, and the name goes
// into the drift marker once when any copy is changed.
it('ticks no copy of a name the listing repeats, and records it in the drift marker once', function (): void {
    $v1 = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $v2 = new ToolDefinition('search', 'Search, the other one.', ['type' => 'object']);
    $v3 = new ToolDefinition('search', 'Search, a third.', ['type' => 'object']);
    $plain = new ToolDefinition('plain', 'Plain.', ['type' => 'object']);
    $discovery = mcpDiscovery([$v1, $v2, $plain, $v3], ['search' => $v1->fingerprint()], drift: $drift);
    $tools = $discovery->tools($discovery->server('trk'));
    expect(array_column($tools, 'state'))->toBe(['approved', 'changed', 'new', 'changed'])
        ->and(array_column($tools, 'ticked'))->toBe([false, false, true, false])
        ->and($drift['alpaca_bot_mcp_drift_trk'])->toBe(['search']);

    // Two copies of the pinned definition: nothing has drifted, and still neither is ticked.
    $twice = mcpDiscovery([$v1, $v1], ['search' => $v1->fingerprint()], drift: $drift);
    $tools = $twice->tools($twice->server('trk'));
    expect(array_column($tools, 'state'))->toBe(['approved', 'approved'])
        ->and(array_column($tools, 'ticked'))->toBe([false, false])
        ->and($drift['alpaca_bot_mcp_drift_trk'])->toBeNull();
});
