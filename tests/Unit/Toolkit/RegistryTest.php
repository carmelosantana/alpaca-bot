<?php

declare(strict_types=1);

use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// The registry knows every toolkit the plugin built; the `toolkits.enabled` setting says which of
// them a turn may use; `alpaca_bot/toolkits` runs over that subset, with the user id, and is
// where a site adds its own toolkit or takes one away for one user. The setting never sees a
// third-party id (Schema::coerce() keeps only the built-ins' ids), so the filter is the one
// extension point and it runs after the setting on purpose.

// enabled() now asks user_can() per toolkit, so the tests that are about the setting and the
// filter need a capability map. when(), not expect(): a test about the floor stubs it again with
// its own map, and Brain Monkey takes the later when().
beforeEach(function (): void {
    Functions\when('user_can')->justReturn(true);
});

it('lists every registered id in registration order and enables only the ones the setting names, through the filter with the user id', function (): void {
    $a = Mockery::mock(ToolkitInterface::class);
    $b = Mockery::mock(ToolkitInterface::class);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->with(['a' => $a], 3)->andReturnFirstArg();
    $r = new Registry(new Store(['toolkits.enabled' => ['a']]));
    $r->register('b', $b);
    $r->register('a', $a);
    expect($r->ids())->toBe(['b', 'a'])
        ->and($r->enabled(3))->toBe(['a' => $a]);
});

it('holds the filter to its contract: a toolkit it adds is in, an entry that is not a toolkit or has no string id is dropped', function (): void {
    $a = Mockery::mock(ToolkitInterface::class);
    $c = Mockery::mock(ToolkitInterface::class);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->andReturnUsing(static fn(array $t): array => $t + ['c' => $c, 'junk' => 'x', 7 => $a]);
    $r = new Registry(new Store(['toolkits.enabled' => ['a']]));
    $r->register('a', $a);
    expect($r->enabled(3))->toBe(['a' => $a, 'c' => $c]);
});

it('enables nothing when the filter returns something that is not an array', function (): void {
    $a = Mockery::mock(ToolkitInterface::class);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->andReturn(null);
    $r = new Registry(new Store(['toolkits.enabled' => ['a']]));
    $r->register('a', $a);
    expect($r->enabled(3))->toBe([]);
});

// Store::get() hands back what is stored, not what Schema::sanitize() would make of it, and a
// corrupt option row must not switch every tool on: a value that is not a list enables nothing.
it('enables nothing when the stored setting is not a list', function (): void {
    $a = Mockery::mock(ToolkitInterface::class);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->with([], 3)->andReturnFirstArg();
    $r = new Registry(new Store(['toolkits.enabled' => 'a']));
    $r->register('a', $a);
    expect($r->enabled(3))->toBe([]);
});

it('replaces a toolkit registered again under the same id, keeping its place', function (): void {
    $a = Mockery::mock(ToolkitInterface::class);
    $a2 = Mockery::mock(ToolkitInterface::class);
    $b = Mockery::mock(ToolkitInterface::class);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->andReturnFirstArg();
    $r = new Registry(new Store(['toolkits.enabled' => ['a', 'b']]));
    $r->register('a', $a);
    $r->register('b', $b);
    $r->register('a', $a2);
    expect($r->ids())->toBe(['a', 'b'])
        ->and($r->enabled(3))->toBe(['a' => $a2, 'b' => $b]);
});

// The floor, between the setting and the filter: a toolkit is offered only to a user who passes
// its Settings > Access row (Access::allows(), `tool.{id}` unless register() was given another).

it('drops a toolkit whose Access row the turn\'s user fails, before the filter runs, and asks it of that user and not the logged-in one', function (): void {
    $fetch = Mockery::mock(ToolkitInterface::class);
    $draft = Mockery::mock(ToolkitInterface::class);
    $asked = [];
    Functions\when('user_can')->alias(static function (int $user, string $cap) use (&$asked): bool {
        $asked[] = [$user, $cap];
        return $cap === 'edit_posts';
    });
    Functions\expect('current_user_can')->never();
    // web_fetch is raised to administrators; the user has edit_posts only.
    $r = new Registry(new Store(['toolkits.enabled' => ['web_fetch', 'draft_post'], 'access.tool.web_fetch' => 'manage_options']));
    $r->register('web_fetch', $fetch);
    $r->register('draft_post', $draft);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->with(['draft_post' => $draft], 5)->andReturnFirstArg();
    expect($r->enabled(5))->toBe(['draft_post' => $draft])
        ->and($asked)->toBe([[5, 'manage_options'], [5, 'edit_posts']]);
});

it('never asks a row for a toolkit the setting has switched off', function (): void {
    $fetch = Mockery::mock(ToolkitInterface::class);
    $asked = [];
    Functions\when('user_can')->alias(static function (int $user, string $cap) use (&$asked): bool {
        $asked[] = $cap;
        return true;
    });
    $r = new Registry(new Store(['toolkits.enabled' => []]));
    $r->register('web_fetch', $fetch);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->with([], 5)->andReturnFirstArg();
    expect($r->enabled(5))->toBe([])->and($asked)->toBe([]);
});

it('hands the row\'s filter the turn\'s user id, and a filter on the row opens the tool', function (): void {
    $fetch = Mockery::mock(ToolkitInterface::class);
    Functions\when('user_can')->alias(static fn(int $user, string $cap): bool => $cap === 'read');
    Filters\expectApplied('alpaca_bot/capability/tool/web_fetch')->once()->with('edit_posts', 5)->andReturn('read');
    Filters\expectApplied('alpaca_bot/toolkits')->once()->andReturnFirstArg();
    $r = new Registry(new Store(['toolkits.enabled' => ['web_fetch']]));
    $r->register('web_fetch', $fetch);
    expect($r->enabled(5))->toBe(['web_fetch' => $fetch]);
});

it('lets the alpaca_bot/toolkits filter add back a toolkit the floor dropped, since it runs after', function (): void {
    // The floor is a floor for the built-ins, not a veto over the extension point: a site that
    // wants a tool for a role no row covers still has the filter, with the user id in hand.
    $fetch = Mockery::mock(ToolkitInterface::class);
    Functions\when('user_can')->justReturn(false);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->with([], 5)->andReturn(['web_fetch' => $fetch]);
    $r = new Registry(new Store(['toolkits.enabled' => ['web_fetch']]));
    $r->register('web_fetch', $fetch);
    expect($r->enabled(5))->toBe(['web_fetch' => $fetch]);
});

it('takes an explicit row at registration, for a toolkit whose row is not tool.{id}', function (): void {
    // Task 27 registers each MCP server's toolkit under its own `mcp.{id}` row.
    $server = Mockery::mock(ToolkitInterface::class);
    $asked = [];
    Functions\when('user_can')->alias(static function (int $user, string $cap) use (&$asked): bool {
        $asked[] = $cap;
        return true;
    });
    Filters\expectApplied('alpaca_bot/toolkits')->once()->andReturnFirstArg();
    $r = new Registry(new Store(['toolkits.enabled' => ['acme']]));
    $r->register('acme', $server, 'mcp.acme');
    expect($r->enabled(5))->toBe(['acme' => $server])->and($asked)->toBe(['manage_options']);
});

it('finds a tool in a toolkit by name, the last of that name, and none when the toolkit has no such tool', function (): void {
    // Final review F6: the abilities and the [alpacabot_agent] shim each walked a toolkit's
    // tools() for one name; this is that walk, once.
    $kit = new class implements ToolkitInterface {
        public function tools(): array
        {
            return [new Tool('first', 'd', [], static fn(array $a): ToolResult => ToolResult::success('1')), new Tool('web_fetch', 'd', [], static fn(array $a): ToolResult => ToolResult::success('old')), new Tool('web_fetch', 'd', [], static fn(array $a): ToolResult => ToolResult::success('new'))];
        }

        public function guidelines(): string
        {
            return '';
        }
    };
    expect(Registry::tool($kit, 'web_fetch')?->execute([])->content)->toBe('new')
        ->and(Registry::tool($kit, 'first')?->name())->toBe('first')
        ->and(Registry::tool($kit, 'summarize'))->toBeNull();
});
