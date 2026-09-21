<?php

declare(strict_types=1);

use AlpacaBot\Access;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// Access over a pre-seeded Store (tests/Pest.php stubs the translation functions Schema's
// labels run through), except where the read itself is the point. user_can() is the capability
// map, stubbed per test; the row filters are Brain Monkey's pass-through unless a test expects one.

it('lists the rows the access model names, with the 0.5 defaults, and the capabilities most to least restrictive', function (): void {
    expect(Access::defaults())->toBe([
        'chat' => 'edit_posts',
        'tool.web_fetch' => 'edit_posts',
        'tool.summarize' => 'edit_posts',
        'tool.draft_post' => 'edit_posts',
        'tool.abilities' => 'manage_options',
        'settings.read' => 'manage_options',
        'settings.write' => 'manage_options',
        'shortcode' => 'edit_posts',
    ])
        ->and(Access::CAPABILITIES)->toBe(['manage_options', 'edit_others_posts', 'publish_posts', 'edit_posts', 'read']);
});

it('reads a stored row, and falls back to the row default for one never saved or saved as anything off the list', function (): void {
    // Store hands back what is stored, not what Schema would make of it (a hand-edited option,
    // a row written before a capability left the list), so the list is checked here too.
    $access = new Access(new Store(['access.chat' => 'read', 'access.tool.web_fetch' => 'exist', 'access.shortcode' => 1]));
    expect($access->stored('chat'))->toBe('read')
        ->and($access->stored('tool.web_fetch'))->toBe('edit_posts')
        ->and($access->stored('shortcode'))->toBe('edit_posts')
        ->and($access->stored('settings.write'))->toBe('manage_options');
});

it('fails closed on a row it does not list: an MCP server row, or one nobody declared, is manage_options', function (): void {
    $access = new Access(new Store([]));
    expect($access->stored('mcp.github'))->toBe('manage_options')
        ->and($access->stored('tool.somebody_elses'))->toBe('manage_options');
});

// The MCP rows are named `mcp.<server id>` like every other row, but they cannot be stored one
// key each: Schema::sanitize() rebuilds the option from fields() and drops anything fields()
// does not declare, so a per-server key would never survive a save. They live in the one
// `access.mcp` map instead, and the row name is what this class reads it by.
it('reads an mcp row out of the one access.mcp map, and fails closed for a server the map does not hold', function (): void {
    $access = new Access(new Store(['access.mcp' => ['github' => 'read', 'jira' => 'not_a_capability']]));
    expect($access->stored('mcp.github'))->toBe('read')
        ->and($access->stored('mcp.jira'))->toBe('manage_options')
        ->and($access->stored('mcp.never_configured'))->toBe('manage_options');
    // A map that is not a map at all leaves every server row on the closed default.
    expect((new Access(new Store(['access.mcp' => 'github'])))->stored('mcp.github'))->toBe('manage_options');
});

it('reads the settings option once however many rows are asked', function (): void {
    // The performance baseline's target for a request is one alpaca_bot_settings read; asking
    // every row must not add one, which is why this reads through Store rather than get_option().
    Functions\expect('get_option')->once()->with(Plugin::OPTION, [])->andReturn(['access.chat' => 'publish_posts']);
    $access = new Access(new Store());
    foreach (array_keys(Access::defaults()) as $row) {
        $access->stored($row);
        $access->effective($row, ...array_fill(0, Access::expectedArgs($row), 1));
    }
    expect($access->stored('chat'))->toBe('publish_posts');
});

it('hands a row filter the stored value and the caller\'s own arguments, and the filter wins', function (): void {
    $access = new Access(new Store(['access.tool.web_fetch' => 'publish_posts']));
    Filters\expectApplied('alpaca_bot/capability/tool/web_fetch')->once()->with('publish_posts', 5)->andReturn('manage_options');
    expect($access->effective('tool.web_fetch', 5))->toBe('manage_options');
});

it('names each row\'s hook with its dots as slashes', function (): void {
    $access = new Access(new Store([]));
    $hooks = ['tool.summarize' => 'tool/summarize', 'tool.draft_post' => 'tool/draft_post', 'tool.abilities' => 'tool/abilities', 'mcp.github' => 'mcp/github', 'shortcode' => 'shortcode', 'settings.read' => 'settings/read', 'settings.write' => 'settings/write'];
    foreach ($hooks as $row => $hook) {
        Filters\expectApplied("alpaca_bot/capability/{$hook}")->once()->andReturn('read');
        expect($access->effective($row, ...array_fill(0, Access::expectedArgs($row), 1)))->toBe('read', $row);
    }
});

it('gives the Chat row no filter of its own: its hooks are the menu\'s and each chat route\'s', function (): void {
    Filters\expectApplied('alpaca_bot/capability/chat')->never();
    $access = new Access(new Store(['access.chat' => 'read']));
    expect($access->effective('chat'))->toBe('read')
        ->and($access->overridden('chat'))->toBeFalse();
});

it('ignores a row filter that answers anything but a capability name, as Capability::filtered() does everywhere', function (): void {
    // `__return_true` is the idiom a site reaches for when a surface will not appear, and
    // `(string) true` is '1', which core reads as the legacy level_1 check.
    $access = new Access(new Store([]));
    foreach ([true, false, '1', 0, 7, '', null, ['manage_options']] as $bad) {
        Filters\expectApplied('alpaca_bot/capability/shortcode')->once()->andReturn($bad);
        expect($access->effective('shortcode', 7, 'alpacabot'))->toBe('edit_posts', var_export($bad, true));
    }
});

it('says a row is overridden only when its filter moved it off the stored value', function (): void {
    $access = new Access(new Store(['access.tool.summarize' => 'publish_posts']));
    Filters\expectApplied('alpaca_bot/capability/tool/summarize')->once()->andReturnFirstArg();
    expect($access->overridden('tool.summarize', 5))->toBeFalse();
    Filters\expectApplied('alpaca_bot/capability/tool/summarize')->once()->andReturn('manage_options');
    expect($access->overridden('tool.summarize', 5))->toBeTrue();
    // A guarded answer is no opinion, so it overrides nothing.
    Filters\expectApplied('alpaca_bot/capability/tool/summarize')->once()->andReturn(true);
    expect($access->overridden('tool.summarize', 5))->toBeFalse();
});

it('asks user_can() of the user it is given, never the logged-in one', function (): void {
    $asked = [];
    Functions\when('user_can')->alias(static function (int $user, string $cap) use (&$asked): bool {
        $asked[] = [$user, $cap];
        return $user === 5;
    });
    Functions\expect('current_user_can')->never();
    $access = new Access(new Store(['access.tool.draft_post' => 'publish_posts']));
    expect($access->allows(5, 'tool.draft_post', 5))->toBeTrue()
        ->and($access->allows(6, 'tool.draft_post', 6))->toBeFalse()
        ->and($asked)->toBe([[5, 'publish_posts'], [6, 'publish_posts']]);
});

// The half of a row's contract that crashes when it is wrong: core slices a listener's argument
// list to its `accepted_args` and calls it with what it has, so a callback declaring more
// parameters than the hook fires with raises an ArgumentCountError. Access fixes the count so a
// listener can be registered against it and no caller has to re-derive it.
it('fixes how many arguments each row fires with, every row said out loud and an unlisted one requiring nothing', function (): void {
    expect(array_map(Access::expectedArgs(...), array_keys(Access::defaults())))
        ->toBe([0, 1, 1, 1, 1, 1, 1, 2])
        ->and(Access::expectedArgs('shortcode'))->toBe(2)
        ->and(Access::expectedArgs('chat'))->toBe(0)
        ->and(Access::expectedArgs('mcp.github'))->toBe(1)
        ->and(Access::expectedArgs('mcp.some.dotted.id'))->toBe(1)
        ->and(Access::expectedArgs('tool.somebody_elses'))->toBe(0);
});

// Loud, not tolerant. Every caller of effective() is about to decide whether somebody may do
// something and knows its own arguments, so a short call is a bug; falling back to the stored
// value would discard a filter that *tightens* the row and hand back a looser capability with no
// symptom at all.
it('refuses a caller that passes fewer arguments than the row fires with, naming the row and the count', function (): void {
    $access = new Access(new Store([]));
    expect(fn(): string => $access->effective('shortcode'))
        ->toThrow(InvalidArgumentException::class, 'The shortcode access row fires with 2 argument(s) after the capability and was given 0');
    expect(fn(): string => $access->effective('shortcode', 7))->toThrow(InvalidArgumentException::class);
    expect(fn(): string => $access->effective('tool.web_fetch'))->toThrow(InvalidArgumentException::class);
    expect(fn(): string => $access->effective('settings.write'))->toThrow(InvalidArgumentException::class);
    expect(fn(): string => $access->effective('mcp.github'))->toThrow(InvalidArgumentException::class);
    expect(fn(): bool => $access->allows(5, 'shortcode', 7))->toThrow(InvalidArgumentException::class);
    // A row that declares none is not refused, and neither is a caller with more than enough:
    // a row may grow an argument without breaking what already calls it.
    Functions\when('user_can')->justReturn(true);
    expect($access->effective('chat'))->toBe('edit_posts')
        ->and($access->effective('tool.summarize', 5, 'spare'))->toBe('edit_posts')
        ->and($access->allows(5, 'shortcode', 7, 'alpacabot'))->toBeTrue();
});

// overridden() is the screen's question, and a screen must not be fatal. The settings page has no
// post id for the shortcode row, so effective() would refuse it before any filter ran; answering
// true regardless would label that row "set in code" on every site in the world. has_filter() is
// what keeps the answer honest: no listener, nothing can have moved the row.
it('asks whether a listener exists at all when the caller cannot supply the row\'s arguments', function (): void {
    $access = new Access(new Store(['access.shortcode' => 'read']));
    expect($access->overridden('shortcode'))->toBeFalse();
    expect($access->overridden('settings.write'))->toBeFalse()
        ->and($access->overridden('mcp.github'))->toBeFalse();
    // With a listener registered the row is out of the operator's hands, and this call cannot
    // find out what it did: true is the honest answer, and it is what stops the check above
    // from being a blanket false.
    add_filter('alpaca_bot/capability/shortcode', '__return_true');
    expect($access->overridden('shortcode'))->toBeTrue();
    // Unaffected: a caller that has the row's arguments never reaches this branch.
    Filters\expectApplied('alpaca_bot/capability/shortcode')->once()->andReturnFirstArg();
    expect($access->overridden('shortcode', 7, 'alpacabot'))->toBeFalse();
});

// error_log() is a PHP internal, so the WP_DEBUG line this leaves cannot be asserted from here;
// what is asserted is the answer, and that it is the same answer whatever threw.
it('reports a row as set in code when resolving it throws, whether the throw came from a listener or from the option read', function (): void {
    $access = new Access(new Store(['access.tool.draft_post' => 'read']));
    // A listener that throws for reasons of its own, called with everything it asked for.
    Filters\expectApplied('alpaca_bot/capability/tool/draft_post')->once()->andReturnUsing(static function (): string {
        throw new RuntimeException('a listener of the site\'s own');
    });
    expect($access->overridden('tool.draft_post', 5))->toBeTrue();
    // Not only a listener: `stored()` reads the option inside the same guard, and a site's
    // pre_option_alpaca_bot_settings listener can throw. Every row would otherwise read as
    // overridden with nothing at all to show for it.
    Functions\when('get_option')->alias(static function (): mixed {
        throw new RuntimeException('another plugin filtered the option and threw');
    });
    expect((new Access(new Store()))->overridden('tool.draft_post', 5))->toBeTrue();
    // And it is still the plain comparison when the row does resolve.
    Filters\expectApplied('alpaca_bot/capability/tool/draft_post')->once()->andReturnFirstArg();
    expect($access->overridden('tool.draft_post', 5))->toBeFalse();
});
