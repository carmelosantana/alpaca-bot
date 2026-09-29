<?php

declare(strict_types=1);

use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Toolkit\SchemaTool;
use AlpacaBot\View\Settings\McpTools;
use Brain\Monkey\Functions;

// The approval list Discovery's rows become, swapped by htmx into a server's row of the settings
// form. It posts under that row's own index, so ticking a box and saving the form is the
// approval, and what a box posts is the fingerprint of the definition shown beside it.
// stubEscapeFunctions() (tests/Pest.php) makes esc_html()/esc_attr() htmlspecialchars().

beforeEach(function (): void {
    Functions\when('wp_strip_all_tags')->alias(static fn(string $s): string => strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $s)));
});

/** @return array{definition: ToolDefinition, fingerprint: string, state: string, ticked: bool} */
function mcpToolRow(ToolDefinition $definition, string $state, bool $ticked): array
{
    return ['definition' => $definition, 'fingerprint' => $definition->fingerprint(), 'state' => $state, 'ticked' => $ticked];
}

it('names each box under the row\'s own index and the tool, with the fingerprint as its value, checked as the row says', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object'], [], 'Search the tracker');
    $write = new ToolDefinition('write', 'Write.', ['type' => 'object'], ['destructiveHint' => true]);
    $html = (new McpTools(2, [mcpToolRow($search, 'approved', true), mcpToolRow($write, 'new', false)]))->render();
    $n = 'alpaca_bot_settings[toolkits.mcp_servers][2][approved]';
    expect($html)->toContain('<input type="checkbox" name="' . $n . '[search]" value="' . $search->fingerprint() . '" checked="checked">')
        ->toContain('<input type="checkbox" name="' . $n . '[write]" value="' . $write->fingerprint() . '">')
        ->toContain('<code>search</code>')
        ->toContain('<strong>Search the tracker</strong>')
        ->and(substr_count($html, 'type="checkbox"'))->toBe(2)
        ->and(substr_count($html, 'checked="checked"'))->toBe(1)
        ->and(substr_count($html, '<li>'))->toBe(2)
        ->and($html)->not->toContain('[1][approved]')
        ->not->toContain('type="hidden"');
});

it('says a changed tool has changed since approval, and leaves a tool that has not changed unmarked', function (): void {
    $report = new ToolDefinition('report', 'Report, differently now.', ['type' => 'object']);
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $changed = (new McpTools(0, [mcpToolRow($report, 'changed', false)]))->render();
    $same = (new McpTools(0, [mcpToolRow($search, 'approved', true), mcpToolRow($report, 'new', true)]))->render();
    expect($changed)->toContain('changed since approval: review')
        ->and($same)->not->toContain('changed since approval');
});

it('says a destructive tool\'s warning is the server\'s own claim, and warns about no other tool', function (): void {
    $write = new ToolDefinition('write', 'Write.', ['type' => 'object'], ['destructiveHint' => true]);
    $plain = new ToolDefinition('plain', 'Plain.', ['type' => 'object'], ['destructiveHint' => 'yes']);
    expect((new McpTools(0, [mcpToolRow($write, 'new', false)]))->render())->toContain('Destructive, by the server&#039;s own account: a claim this site cannot check.')
        ->and((new McpTools(0, [mcpToolRow($plain, 'new', true)]))->render())->not->toContain('Destructive');
});

// describe() strips tags, so the escape is seen on what survives it: `&`, `"` and a bare `<`.
it('shows the description through SchemaTool::describe(), and escapes what describe() leaves', function (): void {
    $long = str_repeat('word ', 100);
    $tool = new ToolDefinition('search', "# Heading\n<b>Bold</b>\n\nline two <img src=x onerror=alert(1)>", ['type' => 'object']);
    $bare = new ToolDefinition('compare', 'Tom & "Jerry" when a < b', ['type' => 'object']);
    expect((new McpTools(0, [mcpToolRow($bare, 'new', true)]))->render())->toContain('<span class="description">Tom &amp; &quot;Jerry&quot; when a &lt; b</span>');
    $cut = new ToolDefinition('long', $long, ['type' => 'object']);
    $html = (new McpTools(0, [mcpToolRow($tool, 'new', true), mcpToolRow($cut, 'new', true)]))->render();
    expect($html)->toContain('<span class="description">' . SchemaTool::describe($tool->description) . '</span>')
        ->toContain('<span class="description">Heading Bold line two</span>')
        ->toContain('<span class="description">' . SchemaTool::describe($long) . '</span>')
        ->toContain('…</span>')
        ->not->toContain('<img')
        ->not->toContain('<b>');
});

// The title is the server's words too, and printed with the same rule: tags stripped by
// describe(), and what survives it escaped.
it('prints a title stripped of tags and escaped', function (): void {
    $tool = new ToolDefinition('search', 'Search.', ['type' => 'object'], [], '<script>alert(1)</script>Find & "go" < now');
    $html = (new McpTools(0, [mcpToolRow($tool, 'new', true)]))->render();
    expect($html)->not->toContain('<script')->toContain('<strong>Find &amp; &quot;go&quot; &lt; now</strong>');
});

// I-1: a name is the server's text, and one that cannot be approved is still printed. It is
// escaped, not stripped, so what the server sent is what the administrator reads.
it('prints a hostile tool name as inert text', function (): void {
    $img = new ToolDefinition('<img src=x onerror=alert(1)>', 'Hostile.', ['type' => 'object']);
    $quote = new ToolDefinition('a" onmouseover="alert(2)', 'Quoted.', ['type' => 'object']);
    $html = (new McpTools(0, [mcpToolRow($img, 'new', true), mcpToolRow($quote, 'new', true)]))->render();
    expect($html)->not->toContain('<img')
        ->not->toContain('" onmouseover="')
        ->toContain('<code>&lt;img src=x onerror=alert(1)&gt;</code>')
        ->toContain('<code>a&quot; onmouseover=&quot;alert(2)</code>')
        ->and(substr_count($html, 'type="checkbox"'))->toBe(0);
});

// M-2: MCP makes a tool's name unique on its server, so a listing that repeats one is a server
// misbehaving. One box per copy would post under one name and keep whichever tick came last, so
// no copy gets a box, each says why, and every other tool is offered as usual.
it('offers no box for a name the listing repeats, on any copy, and says so on each', function (): void {
    $first = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $second = new ToolDefinition('search', 'Search, the other one.', ['type' => 'object']);
    $plain = new ToolDefinition('plain', 'Plain.', ['type' => 'object']);
    $html = (new McpTools(0, [mcpToolRow($first, 'approved', false), mcpToolRow($second, 'changed', false), mcpToolRow($plain, 'new', true)]))->render();
    expect(substr_count($html, 'type="checkbox"'))->toBe(1)
        ->and($html)->toContain('name="alpaca_bot_settings[toolkits.mcp_servers][0][approved][plain]"')
        ->not->toContain('[approved][search]')
        ->and(substr_count($html, 'The server lists this name more than once, so no copy has a box; saving drops the name&#039;s approval, if it has one.'))->toBe(2)
        // Each copy keeps its own state: the one that differs from the pin still says so.
        ->and(substr_count($html, 'changed since approval: review'))->toBe(1);
});

// Schema::sanitizeMcpServers() keeps an approval only under a name of [A-Za-z0-9_.-]{1,128} that
// PHP left a string key; a box whose tick a save would silently drop is worse than no box and a
// line saying why.
it('offers no box for a name a save could not keep, and says so', function (): void {
    $spaced = new ToolDefinition('bad name', 'Spaced.', ['type' => 'object']);
    $number = new ToolDefinition('123', 'A number.', ['type' => 'object']);
    $long = new ToolDefinition(str_repeat('a', 129), 'Long.', ['type' => 'object']);
    $zero = new ToolDefinition('0123', 'Leading zero: PHP keeps it a string.', ['type' => 'object']);
    $longest = new ToolDefinition(str_repeat('b', 128), 'The longest name kept.', ['type' => 'object']);
    $html = (new McpTools(0, [mcpToolRow($spaced, 'new', true), mcpToolRow($number, 'new', true), mcpToolRow($long, 'new', true), mcpToolRow($zero, 'new', true), mcpToolRow($longest, 'new', true)]))->render();
    expect(substr_count($html, 'type="checkbox"'))->toBe(2)
        ->and($html)->toContain('name="alpaca_bot_settings[toolkits.mcp_servers][0][approved][0123]"')
        ->and($html)->toContain('name="alpaca_bot_settings[toolkits.mcp_servers][0][approved][' . str_repeat('b', 128) . ']"')
        ->and(substr_count($html, 'This name cannot be approved, so the tool has no box.'))->toBe(3)
        ->and($html)->toContain('<code>bad name</code>')->toContain('<code>123</code>');
});

it('names an approved tool the server no longer lists, since saving drops its approval', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $html = (new McpTools(0, [mcpToolRow($search, 'approved', true)], '', ['search' => $search->fingerprint(), 'gone' => str_repeat('a', 64), 'went<x>' => str_repeat('b', 64)]))->render();
    expect($html)->toContain('No longer listed by the server, so saving drops its approval: <code>gone</code>, <code>went&lt;x&gt;</code>')
        ->and((new McpTools(0, [mcpToolRow($search, 'approved', true)], '', ['search' => $search->fingerprint()]))->render())->not->toContain('No longer listed');
});

it('says so when the server lists no tools', function (): void {
    expect((new McpTools(0, []))->render())->toBe('<div class="notice notice-info inline"><p>This server lists no tools.</p></div>');
});

// On an error the fragment is a notice, and what the cell held rides along: the approvals stored
// for the row as hidden inputs (the swap replaces the ones the page drew, and a save after a
// failed look must carry them as a save that never looked does), their count, and the drift note
// for the approved names the marker holds (M-5). No tool listed before the error is drawn.
it('renders an error as a core inline notice, carrying the cell as it was and nothing else', function (): void {
    $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
    $html = (new McpTools(1, [mcpToolRow($search, 'new', true)], 'The server did not answer.', ['search' => str_repeat('a', 64), 'write' => str_repeat('b', 64)], '', ['gone', 'write']))->render();
    expect($html)->toBe('<div class="notice notice-error inline"><p>The server did not answer.</p></div>'
        . '<input type="hidden" name="alpaca_bot_settings[toolkits.mcp_servers][1][approved][search]" value="' . str_repeat('a', 64) . '">'
        . '<input type="hidden" name="alpaca_bot_settings[toolkits.mcp_servers][1][approved][write]" value="' . str_repeat('b', 64) . '">'
        . '2 tools approved.<p class="description"><strong>changed since approval: review</strong> <code>write</code></p>');
});

// R81: an error message is untrusted text, a remote server's words included.
it('renders an error message that carries markup inert', function (): void {
    $html = (new McpTools(0, [], '<script>alert(1)</script><img src=x onerror=alert(2)>'))->render();
    expect($html)->not->toContain('<script')->not->toContain('<img')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

// M-7: two of a server's tools that ToolName::fit() gives one name are both withheld from the model
// while both are approved (McpToolkit), so the list says so beside each, as the abilities list does.
it('marks two tools that would reach the model under one name, naming the other, and leaves the rest alone', function (): void {
    $long = new ToolDefinition(str_repeat('x', 100), 'Long.', ['type' => 'object']);
    $short = new ToolDefinition(substr(AlpacaBot\Toolkit\ToolName::fit('trk__' . $long->name), strlen('trk__')), 'Short.', ['type' => 'object']);
    $other = new ToolDefinition('fetch', 'Fetch.', ['type' => 'object']);
    $html = (new McpTools(0, [mcpToolRow($long, 'new', true), mcpToolRow($short, 'new', true), mcpToolRow($other, 'new', true)], '', [], 'trk'))->render();
    $items = explode('<li>', $html);
    $said = 'Reaches the model under the same tool name as %s, so while both are ticked neither is offered.';
    expect($items[1])->toContain(sprintf($said, '<code>' . $short->name . '</code>'))
        ->and($items[2])->toContain(sprintf($said, '<code>' . $long->name . '</code>'))
        ->and($items[3])->not->toContain('same tool name')
        // Still a box each: the mark says what ticking both does, and nothing is left out silently.
        ->and(substr_count($html, 'type="checkbox"'))->toBe(3);
});

// A name the listing repeats is never offered, so a tool that would share its fitted name is not
// held back by it, and is not marked.
it('does not mark a tool for sharing a name with one the listing repeats', function (): void {
    $long = new ToolDefinition(str_repeat('x', 100), 'Long.', ['type' => 'object']);
    $short = new ToolDefinition(substr(AlpacaBot\Toolkit\ToolName::fit('trk__' . $long->name), strlen('trk__')), 'Short.', ['type' => 'object']);
    $html = (new McpTools(0, [mcpToolRow($long, 'new', true), mcpToolRow($long, 'new', true), mcpToolRow($short, 'new', true)], '', [], 'trk'))->render();
    expect($html)->not->toContain('same tool name');
    // Nor for one whose name cannot be approved at all (a space is outside the approval rule).
    $bad = new ToolDefinition('q q', 'Spaced.', ['type' => 'object']);
    $twin = new ToolDefinition(substr(AlpacaBot\Toolkit\ToolName::fit('trk__' . $bad->name), strlen('trk__')), 'Twin.', ['type' => 'object']);
    expect(AlpacaBot\Toolkit\ToolName::fit('trk__' . $twin->name))->toBe(AlpacaBot\Toolkit\ToolName::fit('trk__' . $bad->name))
        ->and((new McpTools(0, [mcpToolRow($bad, 'new', true), mcpToolRow($twin, 'new', true)], '', [], 'trk'))->render())->not->toContain('same tool name');
});
