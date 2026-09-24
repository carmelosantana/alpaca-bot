<?php

declare(strict_types=1);

use AlpacaBot\Access;
use AlpacaBot\Mcp\ClientFactory;
use AlpacaBot\Mcp\McpToolkit;
use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Mcp\Toolkits;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Tests\Integration\FakeClient;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

// Each stored MCP server with something approved, for a user who passes its own Settings ›
// Access row, becomes one McpToolkit keyed `mcp.<id>`. A server that is skipped costs nothing
// beyond the settings the request already read: no client is built and no credential is read.

beforeEach(function (): void {
    Functions\when('wp_strip_all_tags')->alias(static fn(string $s): string => strip_tags($s));
});

/**
 * A Toolkits over one stored server row (merged over a working `trk` row), whose factory
 * records every ServerConfig it is handed into `$built` and answers a FakeClient listing `$tools`.
 *
 * @param array<string, mixed>  $row      merged over the default row
 * @param array<string, mixed>  $settings merged over the Store's settings
 * @param list<ServerConfig>    $built
 * @param list<array{0: int, 1: string}> $asked every user_can() as [user, capability]
 * @param list<string>          $secrets  every option name get_option() was asked for
 */
function mcpToolkits(array $row = [], array $settings = [], ?array &$built = null, ?array &$asked = null, ?array &$secrets = null, bool $can = true, array $tools = []): Toolkits
{
    $built = [];
    $asked = [];
    $secrets = [];
    Functions\when('user_can')->alias(static function (int $user, string $cap) use (&$asked, $can): bool {
        $asked[] = [$user, $cap];
        return $can;
    });
    Functions\when('get_option')->alias(static function (string $name, mixed $default = false) use (&$secrets): mixed {
        $secrets[] = $name;
        return $name === Secrets::OPTION ? ['trk' => 'Bearer t'] : $default;
    });
    $store = new Store($settings + ['toolkits.mcp_servers' => [array_replace([
        'id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization',
        'header_value' => Schema::MASK, 'prefix' => 'trk', 'timeout' => 30.0, 'max_bytes' => 1048576,
        'approved' => ['search' => str_repeat('a', 64)],
    ], $row)]]);
    return new Toolkits($store, new Access($store), new ClientFactory(static function (ServerConfig $server) use (&$built, $tools): FakeClient {
        $built[] = $server;
        return new FakeClient($tools, ['search' => ToolResult::success('hit')]);
    }));
}

it('builds one toolkit keyed mcp.<id> for a user who passes the server\'s row, administrators only by default', function (): void {
    $kits = mcpToolkits([], [], $built, $asked);
    expect(array_keys($kits->for(5)))->toBe(['mcp.trk'])
        ->and($kits->for(5)['mcp.trk'])->toBeInstanceOf(McpToolkit::class)
        ->and($asked[0])->toBe([5, 'manage_options'])
        ->and($built[0]->id)->toBe('trk');
});

it('asks the row as the settings narrow it', function (): void {
    $kits = mcpToolkits([], ['access.mcp' => ['trk' => 'edit_posts']], $built, $asked);
    $kits->for(5);
    expect($asked)->toBe([[5, 'edit_posts']]);
});

it('builds nothing and reads no credential for a user who fails the server\'s row', function (): void {
    $kits = mcpToolkits([], [], $built, $asked, $secrets, false);
    expect($kits->for(5))->toBe([])
        ->and($asked)->toBe([[5, 'manage_options']])
        ->and($built)->toBe([])
        ->and($secrets)->not->toContain(Secrets::OPTION);
});

it('does not build a server with nothing approved, or ask anyone about it', function (): void {
    $kits = mcpToolkits(['approved' => []], [], $built, $asked, $secrets);
    expect($kits->for(5))->toBe([])
        ->and($asked)->toBe([])
        ->and($built)->toBe([])
        ->and($secrets)->not->toContain(Secrets::OPTION);
});

it('skips a row whose id is not a server id, since its Access row could be another\'s', function (string|int|null $id): void {
    $kits = mcpToolkits(['id' => $id], [], $built, $asked);
    expect($kits->for(5))->toBe([])->and($asked)->toBe([])->and($built)->toBe([]);
})->with([[''], ['Bad.Id'], [null], [7]]);

it('hands the factory the server with the header value Secrets keeps in place of the mask', function (): void {
    $kits = mcpToolkits([], [], $built);
    $kits->for(5);
    expect($built[0]->headerValue)->toBe('Bearer t')
        ->and($built[0]->headerName)->toBe('Authorization')
        ->and($built[0]->approved)->toBe(['search' => str_repeat('a', 64)]);
});

it('announces a call as the user the row was asked for, and records drift in the server\'s marker', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $kits = mcpToolkits(['approved' => ['search' => $search->fingerprint(), 'gone' => str_repeat('b', 64)]], [], $built, $asked, $secrets, true, [$search, new ToolDefinition('gone', 'Changed.', ['type' => 'object'])]);
    Functions\expect('set_transient')->once()->with('alpaca_bot_mcp_drift_trk', ['gone'], 604800)->andReturn(true);
    Actions\expectDone('alpaca_bot/mcp/called')->once()->with('trk', 'search', [], 5, Mockery::type(ToolResult::class));
    expect($kits->for(5)['mcp.trk']->tools()[0]->execute([])->content)->toBe('hit');
});
