<?php

declare(strict_types=1);

use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;
use Brain\Monkey\Filters;

// The registry knows every toolkit the plugin built; the `toolkits.enabled` setting says which of
// them a turn may use; `alpaca_bot/toolkits` runs over that subset, with the user id, and is
// where a site adds its own toolkit or takes one away for one user. The setting never sees a
// third-party id (Schema::coerce() keeps only the built-ins' ids), so the filter is the one
// extension point and it runs after the setting on purpose.

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
