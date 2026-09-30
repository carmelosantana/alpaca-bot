<?php

declare(strict_types=1);

use AlpacaBot\Access;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Toolkit\AbilitiesToolkit;
use AlpacaBot\Toolkit\ToolName;
use Brain\Monkey\Functions;

it('has defaults for every field and a section for each', function (): void {
    $fields = Schema::fields();
    $sections = Schema::sections();
    expect($fields)->toHaveKeys(['provider.kind', 'provider.base_url', 'models.default', 'models.temperature', 'models.num_ctx', 'models.keep_alive', 'models.overrides', 'chat.system_prompt', 'chat.history_limit', 'privacy.save_history', 'privacy.usage_log', 'governance.site_monthly_tokens', 'governance.user_monthly_tokens', 'toolkits.user_agent', 'toolkits.enabled']);
    foreach ($fields as $key => $f) {
        expect($f)->toHaveKeys(['type', 'default', 'section', 'label'], $key);
        expect($sections)->toHaveKey($f['section'], message: $key);
    }
    expect(Schema::defaults()['provider.base_url'])->toBe('http://localhost:11434/v1')
        ->and(Schema::defaults()['models.temperature'])->toBe(0.7);
});

// The toolkit switches are one field, a list of ids, so a toolkit is enabled by name and the
// page can render one checkbox per built-in. What is stored is always the checked subset of
// the field's options, in option order: an unknown id (a toolkit that was removed, a typo in a
// PUT) is dropped rather than kept for a registry to trip on, and a duplicate is one entry.
it('stores toolkits.enabled as the checked subset of its options, in option order, every built-in but abilities by default', function (): void {
    $f = Schema::fields()['toolkits.enabled'];
    expect($f['type'])->toBe('checkbox-list')
        ->and($f['section'])->toBe('toolkits')
        ->and($f['default'])->toBe(['web_fetch', 'summarize', 'draft_post'])
        ->and(array_keys($f['options'] ?? []))->toBe(['web_fetch', 'summarize', 'draft_post', 'abilities']);
    expect(Schema::sanitize(['toolkits.enabled' => ['draft_post', 'nope', '', 'web_fetch', 'web_fetch']], [])['toolkits.enabled'])->toBe(['web_fetch', 'draft_post']);
    // The page posts a hidden '' ahead of the boxes (Fields::render()), so a save with every box
    // unchecked arrives as [''] and is heard as "none", not as "absent, keep what is stored".
    expect(Schema::sanitize(['toolkits.enabled' => ['']], ['toolkits.enabled' => ['summarize']])['toolkits.enabled'])->toBe([]);
    // Not a list at all (a PUT of a bare string): fail closed. `array` falls back to its default
    // here, but this default switches tools on, and a malformed write must not do that.
    expect(Schema::sanitize(['toolkits.enabled' => 'web_fetch'], ['toolkits.enabled' => ['summarize']])['toolkits.enabled'])->toBe([]);
    // Left out of the write: keeps what is stored, as every field does.
    expect(Schema::sanitize([], ['toolkits.enabled' => ['summarize']])['toolkits.enabled'])->toBe(['summarize']);
});

// One row per Access row, as a select over the fixed capability list: the schema is the single
// place that knows what a row may be set to, and Access::defaults() the single place that knows
// what it starts as, so the two cannot drift.
it('declares one Settings › Access select per row, each defaulting to Access::defaults() and offering exactly Access::CAPABILITIES', function (): void {
    $fields = Schema::fields();
    expect(Schema::sections())->toHaveKey('access');
    foreach (Access::defaults() as $row => $capability) {
        $f = $fields['access.' . $row] ?? null;
        expect($f)->toBeArray($row);
        expect($f['type'])->toBe('select', $row)
            ->and($f['section'])->toBe('access', $row)
            ->and($f['default'])->toBe($capability, $row)
            ->and(array_keys($f['options'] ?? []))->toBe(Access::CAPABILITIES, $row)
            ->and($f['label'])->toBeString()->not->toBe('');
    }
    // Every row of the section, in Access::defaults() order, and then the MCP map: the section
    // holds the declared rows and nothing else, so a row added to one and not the other shows up
    // here rather than as a select nobody can reach.
    expect(array_keys(array_filter($fields, static fn(array $f): bool => $f['section'] === 'access')))
        ->toBe([...array_map(static fn(string $row): string => 'access.' . $row, array_keys(Access::defaults())), 'access.mcp']);
});

it('stores an access row only as one of the listed capabilities, and anything else as that row\'s default', function (): void {
    $out = Schema::sanitize(['access.chat' => 'read', 'access.tool.web_fetch' => 'exist', 'access.settings.write' => '1', 'access.shortcode' => ['publish_posts']], []);
    expect($out['access.chat'])->toBe('read')
        ->and($out['access.tool.web_fetch'])->toBe('edit_posts')
        ->and($out['access.settings.write'])->toBe('manage_options')
        ->and($out['access.shortcode'])->toBe('edit_posts');
});

// The MCP rows are one map field and not a key per server, because sanitize() rebuilds the whole
// option from fields() and drops every key it does not declare: a per-server key could never be
// saved. Access::stored('mcp.<id>') reads this map, so the row names are unchanged.
it('keeps every MCP server row in the one access.mcp map, dropping an id or a capability it cannot use', function (): void {
    $f = Schema::fields()['access.mcp'];
    expect($f['type'])->toBe('array')
        ->and($f['section'])->toBe('access')
        ->and($f['default'])->toBe([]);
    $out = Schema::sanitize(['access.mcp' => [
        'github' => 'read',
        'jira' => 'not_a_capability',
        'confluence' => ['read'],
        '' => 'read',
        7 => 'read',
        'filesystem' => 'manage_options',
    ]], []);
    expect($out['access.mcp'])->toBe(['github' => 'read', 'filesystem' => 'manage_options']);
    // Not a map at all: nothing is stored, rather than a default that would open every server.
    expect(Schema::sanitize(['access.mcp' => 'github'], [])['access.mcp'])->toBe([]);
    // Left out of the write: keeps what is stored, as every field does.
    expect(Schema::sanitize([], ['access.mcp' => ['github' => 'read']])['access.mcp'])->toBe(['github' => 'read']);
});

it('bounds the transcript sent to the model with chat.context_messages, 20 by default, 0 allowed for everything', function (): void {
    expect(Schema::defaults()['chat.context_messages'])->toBe(20)
        ->and(Schema::fields()['chat.context_messages']['section'])->toBe('chat')
        ->and(Schema::sanitize(['chat.context_messages' => '0'], [])['chat.context_messages'])->toBe(0)
        ->and(Schema::sanitize(['chat.context_messages' => '-3'], [])['chat.context_messages'])->toBe(0)
        ->and(Schema::sanitize(['chat.context_messages' => 'x'], [])['chat.context_messages'])->toBe(20);
});

it('sanitizes by type, clamps ranges, and drops unknown keys', function (): void {
    $out = Schema::sanitize([
        'provider.base_url' => ' http://ollama:11434/v1/ ',
        'models.temperature' => '9',
        'models.num_ctx' => '-5',
        'chat.user_can_change_model' => '0',
        'provider.kind' => 'bogus',
        'models.overrides' => ['llama3.2' => ['temperature' => '0.2', 'junk' => 1]],
        'nope' => 'x',
    ], []);
    expect($out['provider.base_url'])->toBe('http://ollama:11434/v1')
        ->and($out['models.temperature'])->toBe(2.0)
        ->and($out['models.num_ctx'])->toBe(512)
        ->and($out['chat.user_can_change_model'])->toBeFalse()
        ->and($out['provider.kind'])->toBe('ollama')
        ->and($out['models.overrides'])->toBe(['llama3.2' => ['temperature' => 0.2]])
        ->and($out)->not->toHaveKey('nope')
        ->and($out['chat.history_limit'])->toBe(20);
});

it('sanitizing an empty input over an empty store yields exactly the defaults', function (): void {
    expect(Schema::sanitize([], []))->toBe(Schema::defaults());
});

// PHP's max_input_vars drops the tail of a large POST and tells userland nothing. On the Models
// tab that tail is the hidden carry-over of every other tab, the API key and the base URL first
// among them. A key the input does not name keeps what is stored, so a cut-short post can lose
// at most what it did not carry, never what it never mentioned; only a store holding nothing
// falls back to the default.
it('keeps the stored value for a key the input leaves out, and takes the default only when nothing is stored', function (): void {
    $current = ['provider.api_key' => 'sk-stored', 'provider.base_url' => 'https://openrouter.ai/api/v1', 'models.temperature' => 1.3, 'chat.spellcheck' => false];
    $out = Schema::sanitize(['models.default' => 'gpt-4o'], $current);
    expect($out['models.default'])->toBe('gpt-4o')
        ->and($out['provider.api_key'])->toBe('sk-stored')
        ->and($out['provider.base_url'])->toBe('https://openrouter.ai/api/v1')
        ->and($out['models.temperature'])->toBe(1.3)
        ->and($out['chat.spellcheck'])->toBeFalse()
        ->and($out['chat.welcome'])->toBe('How can I help?');
    // What is kept is still put through the schema: a stored value outside it is corrected, not carried.
    $kept = Schema::sanitize([], ['models.num_ctx' => 5, 'provider.kind' => 'bogus', 'nope' => 'x']);
    expect($kept['models.num_ctx'])->toBe(512)->and($kept['provider.kind'])->toBe('ollama')->and($kept)->not->toHaveKey('nope');
});

// A textarea posts CRLF and a JSON client posts LF, and a hidden carry-over of either goes
// through the browser's own newline handling on its way back. One stored spelling, LF, makes
// the value the same whichever path wrote it, so saving an unrelated tab cannot rewrite it.
it('stores one line ending: CRLF and a lone CR become LF, on the prompt and on an override', function (): void {
    $out = Schema::sanitize(['chat.system_prompt' => "one\r\ntwo\rthree\r\n", 'models.overrides' => ['m' => ['system' => "a\r\nb"]]], []);
    expect($out['chat.system_prompt'])->toBe("one\ntwo\nthree")
        ->and($out['models.overrides']['m']['system'])->toBe("a\nb");
});

it('names the secret fields and the mask that stands in for them', function (): void {
    expect(Schema::SECRETS)->toBe(['provider.api_key'])
        ->and(Schema::MASK)->toBe('••••')
        ->and(Schema::fields())->toHaveKey('provider.api_key');
});

it('keeps a stored secret when handed the mask, clears it on an empty string, replaces it on any other string', function (): void {
    $stored = ['provider.api_key' => 'sk-stored'];
    expect(Schema::sanitize(['provider.api_key' => Schema::MASK], $stored)['provider.api_key'])->toBe('sk-stored')
        ->and(Schema::sanitize(['provider.api_key' => ''], $stored)['provider.api_key'])->toBe('')
        ->and(Schema::sanitize(['provider.api_key' => ' sk-new '], $stored)['provider.api_key'])->toBe('sk-new')
        // Absent keeps what is stored, as for any field: only '' clears.
        ->and(Schema::sanitize([], $stored)['provider.api_key'])->toBe('sk-stored')
        // The mask over nothing stored is nothing stored, never the literal mask.
        ->and(Schema::sanitize(['provider.api_key' => Schema::MASK], [])['provider.api_key'])->toBe('');
});

it('keeps a stored secret when handed anything that is not a string, rather than clearing it', function (): void {
    $stored = ['provider.api_key' => 'sk-stored'];
    foreach ([null, [], ['x'], 0, false, true, 1.5] as $raw) {
        expect(Schema::sanitize(['provider.api_key' => $raw], $stored)['provider.api_key'])->toBe('sk-stored', var_export($raw, true));
    }
    // With nothing stored there is nothing to keep, and a non-string is still not a key.
    expect(Schema::sanitize(['provider.api_key' => null], [])['provider.api_key'])->toBe('')
        ->and(Schema::sanitize(['provider.api_key' => null], ['provider.api_key' => 7])['provider.api_key'])->toBe('');
});

// #4539, ruling T4-a: the stored API key does not follow provider.base_url to another scheme,
// host or port. A writer the `settings.write` row admits could otherwise point the URL at their
// own host, send the key back as the mask (or leave it out) and receive it on the next turn.
// Keep (MASK, left out, not a string) does not survive a move; a new key sent with it does.
it('clears the stored key when the base URL moves to another origin and the write only keeps it', function (array $input): void {
    $stored = ['provider.api_key' => 'sk-FAKE-stored', 'provider.base_url' => 'https://openrouter.ai/api/v1'];
    expect(Schema::providerKeyClearedByMove($input, $stored))->toBeTrue()
        ->and(Schema::sanitize($input, $stored)['provider.api_key'])->toBe('');
})->with([
    'host changed, mask sent' => [['provider.base_url' => 'https://steal.example.net/api/v1', 'provider.api_key' => Schema::MASK]],
    'host changed, key left out' => [['provider.base_url' => 'https://steal.example.net/api/v1']],
    'host changed, key not a string' => [['provider.base_url' => 'https://steal.example.net/api/v1', 'provider.api_key' => null]],
    'port changed' => [['provider.base_url' => 'https://openrouter.ai:8443/api/v1', 'provider.api_key' => Schema::MASK]],
    'scheme changed' => [['provider.base_url' => 'http://openrouter.ai/api/v1', 'provider.api_key' => Schema::MASK]],
    // sanitizeUrl() stores the default for an empty URL, and the default is another origin.
    'emptied, so the default' => [['provider.base_url' => '', 'provider.api_key' => Schema::MASK]],
]);

it('keeps the stored key when the base URL keeps its origin, or is not sent', function (array $input): void {
    $stored = ['provider.api_key' => 'sk-FAKE-stored', 'provider.base_url' => 'https://openrouter.ai/api/v1'];
    expect(Schema::providerKeyClearedByMove($input, $stored))->toBeFalse()
        ->and(Schema::sanitize($input, $stored)['provider.api_key'])->toBe('sk-FAKE-stored');
})->with([
    'no URL sent, mask sent' => [['provider.api_key' => Schema::MASK]],
    'same URL, mask sent' => [['provider.base_url' => 'https://openrouter.ai/api/v1', 'provider.api_key' => Schema::MASK]],
    'only the path and query changed' => [['provider.base_url' => 'https://openrouter.ai/v2?x=1', 'provider.api_key' => Schema::MASK]],
    'default port written out, capitals, trailing slash' => [['provider.base_url' => 'https://OpenRouter.AI:443/api/v1/']],
]);

it('stores a new key sent with the move, and says nothing was cleared', function (): void {
    $stored = ['provider.api_key' => 'sk-FAKE-stored', 'provider.base_url' => 'https://openrouter.ai/api/v1'];
    $input = ['provider.base_url' => 'https://api.example.net/v1', 'provider.api_key' => 'sk-FAKE-new'];
    expect(Schema::providerKeyClearedByMove($input, $stored))->toBeFalse()
        ->and(Schema::sanitize($input, $stored))->toMatchArray(['provider.base_url' => 'https://api.example.net/v1', 'provider.api_key' => 'sk-FAKE-new']);
});

// Nothing to lose is nothing cleared: the notices name a key that was there, and '' is the
// writer's own "clear", not the move's.
it('names no clearing when no key is stored, or when the write clears the key itself', function (): void {
    $move = ['provider.base_url' => 'https://steal.example.net/v1', 'provider.api_key' => Schema::MASK];
    expect(Schema::providerKeyClearedByMove($move, ['provider.base_url' => 'https://openrouter.ai/api/v1']))->toBeFalse()
        ->and(Schema::providerKeyClearedByMove($move, ['provider.api_key' => '', 'provider.base_url' => 'https://openrouter.ai/api/v1']))->toBeFalse()
        ->and(Schema::providerKeyClearedByMove(['provider.api_key' => ''] + $move, ['provider.api_key' => 'sk-FAKE-stored', 'provider.base_url' => 'https://openrouter.ai/api/v1']))->toBeFalse();
});

// The stored URL is compared as it is held, and an array without one is on the default, as
// Store::all() and the stored row read it.
it('compares against the default URL when nothing is stored for it', function (): void {
    $stored = ['provider.api_key' => 'sk-FAKE-stored'];
    expect(Schema::providerKeyClearedByMove(['provider.base_url' => 'http://localhost:11434/other'], $stored))->toBeFalse()
        ->and(Schema::providerKeyClearedByMove(['provider.base_url' => 'http://ollama.example.net:11434/v1'], $stored))->toBeTrue()
        // The URL compared is the one sanitize() would store: '' stores the default, which is
        // where this site already is, so nothing moved.
        ->and(Schema::providerKeyClearedByMove(['provider.base_url' => ''], $stored))->toBeFalse()
        ->and(Schema::sanitize(['provider.base_url' => ''], $stored)['provider.api_key'])->toBe('sk-FAKE-stored');
});

it('clamps per-model overrides to the same bounds as the global fields', function (): void {
    $fields = Schema::fields();
    $out = Schema::sanitize(['models.overrides' => [
        'hot' => ['temperature' => '99', 'num_ctx' => '999999999999'],
        'cold' => ['temperature' => '-3', 'num_ctx' => '1', 'keep_alive' => ' 1h ', 'system' => ' be terse '],
        'junk' => ['temperature' => 'warm', 'num_ctx' => 'lots'],
    ]], []);
    expect($out['models.overrides']['hot'])->toBe(['temperature' => (float) $fields['models.temperature']['max'], 'num_ctx' => $fields['models.num_ctx']['max']])
        ->and($out['models.overrides']['cold'])->toBe(['temperature' => (float) $fields['models.temperature']['min'], 'num_ctx' => $fields['models.num_ctx']['min'], 'keep_alive' => '1h', 'system' => 'be terse'])
        ->and($out['models.overrides'])->not->toHaveKey('junk');
});

it('validates URL fields through esc_url_raw and falls back to the default when it rejects the value', function (): void {
    // Minimal stand-in for WordPress: keep http(s), reject everything else.
    Functions\when('esc_url_raw')->alias(fn(string $url): string => preg_match('#^https?://#i', $url) === 1 ? $url : '');
    $out = Schema::sanitize([
        'provider.base_url' => 'gopher://ollama:11434/v1/',
        'chat.assistant_avatar' => 'javascript:alert(1)',
    ], []);
    expect($out['provider.base_url'])->toBe(Schema::defaults()['provider.base_url'])
        ->and($out['chat.assistant_avatar'])->toBe('');

    $ok = Schema::sanitize(['provider.base_url' => ' https://ollama.example/v1/ ', 'chat.assistant_avatar' => 'https://example.com/a.png'], []);
    expect($ok['provider.base_url'])->toBe('https://ollama.example/v1')
        ->and($ok['chat.assistant_avatar'])->toBe('https://example.com/a.png');
});

it('keeps usage receipts for 90 days by default, 0 meaning forever, and never a negative number', function (): void {
    $f = Schema::fields()['privacy.usage_retention_days'];
    expect($f['type'])->toBe('integer')->and($f['default'])->toBe(90)->and($f['section'])->toBe('privacy')->and($f['min'])->toBe(0);
    expect(Schema::sanitize(['privacy.usage_retention_days' => '0'], [])['privacy.usage_retention_days'])->toBe(0)
        ->and(Schema::sanitize(['privacy.usage_retention_days' => '-7'], [])['privacy.usage_retention_days'])->toBe(0)
        ->and(Schema::sanitize(['privacy.usage_retention_days' => '400'], [])['privacy.usage_retention_days'])->toBe(400)
        ->and(Schema::sanitize(['privacy.usage_retention_days' => 'x'], [])['privacy.usage_retention_days'])->toBe(90)
        ->and(Schema::sanitize(['privacy.usage_retention_days' => '999999'], [])['privacy.usage_retention_days'])->toBe($f['max']);
    // The clamp is in the copy: a value above the ceiling is stored as the ceiling, silently otherwise.
    expect($f['description'] ?? '')->toContain((string) $f['max']);
});

// The copy that P1's real runs asked for. Pinned by keyword so it cannot quietly vanish.
it('tells the admin what the timeout, the usage receipts and the caps really do', function (): void {
    $fields = Schema::fields();
    $sections = Schema::sections();
    expect($fields['provider.timeout']['description'] ?? '')->toContain('cold')
        ->and($fields['privacy.usage_log']['description'] ?? '')->toContain('always')->toContain('never')
        ->and($fields['privacy.usage_retention_days']['description'] ?? '')->toContain('0 ')
        // The reasoning's wire key is `message` (Rest\ChatController::body()), not the Result's `reply` property.
        ->and($sections['governance']['description'])->toContain('message.meta.reasoning')->not->toContain('reply.meta')
        ->and($fields['models.default']['description'] ?? '')->toContain('message.meta.reasoning')->not->toContain('reply.meta');
});

// The overrides table posts every cell of every row, blank ones included: a blank is "no
// override", never an empty string that would shadow the global keep_alive or system prompt.
it('drops blank override cells so a row of blanks is no override at all', function (): void {
    $out = Schema::sanitize(['models.overrides' => [
        'a' => ['temperature' => '', 'num_ctx' => '', 'keep_alive' => '', 'system' => ''],
        'b' => ['temperature' => '', 'num_ctx' => '', 'keep_alive' => ' 1h ', 'system' => '  '],
    ]], []);
    expect($out['models.overrides'])->toBe(['b' => ['keep_alive' => '1h']]);
});

// The per-model `tools` override is tri-state, and unlike every other override it has no global
// field to borrow a type from: what it overrides is the catalogue, which is not a setting. Only
// the two literals mean anything; a blank cell is the third state, inherit, and stores nothing.
it('round-trips the three states of the per-model tools override and stores nothing for inherit', function (): void {
    $out = Schema::sanitize(['models.overrides' => [
        'forced-on' => ['tools' => Schema::TOOLS_ON],
        'forced-off' => ['tools' => Schema::TOOLS_OFF],
        'inherit-blank' => ['tools' => '', 'keep_alive' => '1h'],
        'inherit-absent' => ['keep_alive' => '2h'],
        'inherit-junk' => ['tools' => 'maybe'],
        'inherit-not-scalar' => ['tools' => ['on']],
    ]], []);
    expect($out['models.overrides'])->toBe([
        'forced-on' => ['tools' => 'on'],
        'forced-off' => ['tools' => 'off'],
        'inherit-blank' => ['keep_alive' => '1h'],
        'inherit-absent' => ['keep_alive' => '2h'],
    ])
        ->and(Schema::TOOLS_ON)->toBe('on')
        ->and(Schema::TOOLS_OFF)->toBe('off');
    // Stored and read back unchanged: a second save over the first keeps all three states.
    expect(Schema::sanitize([], $out)['models.overrides'])->toBe($out['models.overrides']);
});

// A site upgrading from a release without this key: every stored row is an inherit row for
// tools, and the next save carries the rest of the row through untouched.
it('reads an overrides row stored before the tools key as inherit and drops nothing from it', function (): void {
    $stored = ['models.overrides' => ['llama3.2' => ['temperature' => 0.2, 'num_ctx' => 4096, 'keep_alive' => '1h', 'system' => 'be terse']]];
    $out = Schema::sanitize([], $stored);
    expect($out['models.overrides'])->toBe($stored['models.overrides'])
        ->and($out['models.overrides']['llama3.2'])->not->toHaveKey('tools');
});

it('offers the abilities toolkit as an option of toolkits.enabled without switching it on', function (): void {
    $f = Schema::fields()['toolkits.enabled'];
    expect($f['options'])->toHaveKey('abilities')
        ->and($f['default'])->not->toContain('abilities')
        ->and(Schema::sanitize(['toolkits.enabled' => ['abilities', 'web_fetch']], [])['toolkits.enabled'])->toBe(['web_fetch', 'abilities']);
});

it('keeps an abilities allowlist of ability names only, once each, never an alpaca-bot one, and fails closed on a non-list', function (): void {
    $f = Schema::fields()['toolkits.abilities'];
    expect($f['type'])->toBe('array')->and($f['section'])->toBe('toolkits')->and($f['default'])->toBe([]);
    expect(Schema::sanitizeAbilities(['core/get-site-info', '', 'core/get-site-info', 'alpaca-bot/chat', 'Not A Name', 7, ['core/x'], 'core/', '/x', 'a/b/c', "core/x\n", 'my-plugin/do-thing']))->toBe(['core/get-site-info', 'my-plugin/do-thing'])
        ->and(Schema::sanitizeAbilities('core/get-site-info'))->toBe([])
        ->and(Schema::sanitizeAbilities(['x' => 'core/get-site-info']))->toBe(['core/get-site-info'])
        ->and(Schema::sanitize(['toolkits.abilities' => ['core/get-user-info']], [])['toolkits.abilities'])->toBe(['core/get-user-info'])
        // The page's sentinel alone: every box cleared.
        ->and(Schema::sanitize(['toolkits.abilities' => ['']], ['toolkits.abilities' => ['core/get-site-info']])['toolkits.abilities'])->toBe([])
        // Left out of the write: kept, and cleaned again on the way through.
        ->and(Schema::sanitize([], ['toolkits.abilities' => ['core/get-site-info', 'alpaca-bot/chat']])['toolkits.abilities'])->toBe(['core/get-site-info']);
});

// ---------------------------------------------------------------- MCP servers
// toolkits.mcp_servers is a list of rows, one per remote server. What sanitizeMcpServers() can
// judge without a lookup it judges here; whether the address is public is Mcp\ServerSettings's
// question on the way in and Mcp\Egress's on every connection.

it('reads an mcp server row into shape and drops one it cannot read as a server', function (): void {
    $rows = Schema::sanitizeMcpServers([
        ['url' => ' https://mcp.example.com/mcp ', 'prefix' => 'trk', 'header_name' => 'Authorization', 'header_value' => 'Bearer t', 'timeout' => '900', 'max_bytes' => '10'],
        ['url' => 'http://mcp.example.com/mcp', 'prefix' => 'plain'],                 // not https
        ['url' => 'https:///mcp', 'prefix' => 'nohost'],                              // no host
        ['url' => 'https://./mcp', 'prefix' => 'dot'],                                // a host that trims to nothing
        ['url' => 'https://other.example.com/mcp', 'prefix' => 'trk'],                // prefix already taken
        ['url' => 'https://other.example.com/mcp', 'prefix' => 'ability'],            // reserved: the abilities toolkit names its tools ability__
        ['url' => 'https://other.example.com/mcp', 'prefix' => 'NOPE'],               // uppercase
        ['url' => 'https://other.example.com/mcp', 'prefix' => '9lives'],             // a tool name may not start with a digit
        ['url' => 'https://other.example.com/mcp', 'prefix' => "tail\n"],             // `$` would match before this LF; `\z` does not
        ['url' => 'https://other.example.com/mcp', 'prefix' => str_repeat('p', 17)],  // longer than 16
        ['url' => 'https://other.example.com/mcp', 'prefix' => 'trk_'],               // ends in `_`: trk_ + x and trk + _x would both be trk___x
        ['url' => 'https://other.example.com/mcp', 'prefix' => 'ability__core'],      // holds `__`: its tools could be named as an ability's
        ['url' => 'https://other.example.com/mcp', 'prefix' => 'a__b'],               // holds `__`
        ['url' => 'https://other.example.com/mcp'],                                   // no prefix
        ['url' => '', 'prefix' => 'blank'],                                           // the form's empty "add a server" row
        ['url' => 'https://gone.example.com/mcp', 'prefix' => 'gone', 'remove' => '1'],
        'not a row',
    ]);
    expect($rows)->toBe([[
        'id' => 'trk',
        'url' => 'https://mcp.example.com/mcp',
        'header_name' => 'Authorization',
        'header_value' => 'Bearer t',
        'prefix' => 'trk',
        'timeout' => 120.0,
        'max_bytes' => 1024,
        'approved' => [],
    ]])
        ->and(Schema::sanitizeMcpServers('nope'))->toBe([])
        ->and(Schema::sanitizeMcpServers([['url' => 'https://a.example.com/mcp', 'prefix' => str_repeat('p', 16)]])[0]['prefix'])->toBe(str_repeat('p', 16))
        ->and(Schema::sanitizeMcpServers([['url' => 'https://a.example.com/mcp', 'prefix' => 'a_b_9']])[0]['prefix'])->toBe('a_b_9');
});

// A credential in the address would be stored, and answered by GET /settings, as the address is:
// in the clear. The header is where one goes, and it reads back masked. So a URL with a user name
// or a password, even an empty one, is not kept, and the fault says so apart from `url`. An `@`
// in the path or the query is not userinfo, and stays.
it('drops an mcp server whose URL carries a user name or password, and names the fault', function (): void {
    $raw = [
        ['url' => 'https://user:s3cret@mcp.example.com/mcp', 'prefix' => 'both'],
        ['url' => 'https://tok3n@mcp.example.com/mcp', 'prefix' => 'user'],
        ['url' => 'https://:s3cret@mcp.example.com/mcp', 'prefix' => 'pass'],
        ['url' => 'https://@mcp.example.com/mcp', 'prefix' => 'empty'],
        ['url' => 'http://user:s3cret@mcp.example.com/mcp', 'prefix' => 'plain'],
        ['url' => 'https://mcp.example.com/a@b/mcp?to=a@b', 'prefix' => 'kept'],
    ];
    expect(array_column(Schema::sanitizeMcpServers($raw), 'url'))->toBe(['https://mcp.example.com/a@b/mcp?to=a@b'])
        ->and(Schema::droppedMcpRows($raw))->toBe([0 => 'userinfo', 1 => 'userinfo', 2 => 'userinfo', 3 => 'userinfo', 4 => 'url']);
});

// A header name with no letter in it is refused, whole, and named apart from the others. PHP makes
// an array key of a whole number written plainly an int (`123`, `-1`, `0`), and php-agents' header
// map would then send the value as the header's name; `-0`, `0123` and a number past PHP_INT_MAX
// stay string keys, but they are refused all the same, by the one rule the notice can state. A
// JSON number is refused as the string of its digits is. R28-11: a name the `[A-Za-z0-9-]{1,64}`
// rule does not admit at all (`+1`, `Bad Header`, `X_Key`, a name over 64 characters, one with a
// line break) is refused the same way, rather than read as '' and the server saved with no header.
it('drops an mcp server whose header name has no letter in it, or is outside the rule, and names the fault', function (): void {
    $names = ['123', '-1', '0', '-0', '0123', '99999999999999999999', '---', 123, -1, '+1', 'Bad Header', 'X_Key', "X-Key\n", str_repeat('a', 65), ['X-Key'], 1.5, true];
    $raw = array_map(static fn(mixed $name, int $i): array => ['url' => 'https://a' . $i . '.example.com/mcp', 'prefix' => 'p' . $i, 'header_name' => $name], $names, array_keys($names));
    $kept = [
        ['url' => 'https://k1.example.com/mcp', 'prefix' => 'k1', 'header_name' => 'X-1'],
        ['url' => 'https://k2.example.com/mcp', 'prefix' => 'k2', 'header_name' => '0123a'],
        ['url' => 'https://k3.example.com/mcp', 'prefix' => 'k3', 'header_name' => str_repeat('a', 64)],
        ['url' => 'https://k4.example.com/mcp', 'prefix' => 'k4', 'header_name' => ''],
        ['url' => 'https://k5.example.com/mcp', 'prefix' => 'k5', 'header_name' => null],
        ['url' => 'https://k6.example.com/mcp', 'prefix' => 'k6'],
    ];
    expect(Schema::droppedMcpRows($raw))->toBe(array_fill(0, count($names), 'header'))
        ->and(Schema::sanitizeMcpServers($raw))->toBe([])
        ->and(array_column(Schema::sanitizeMcpServers($kept), 'header_name'))->toBe(['X-1', '0123a', str_repeat('a', 64), '', '', ''])
        ->and(Schema::droppedMcpRows($kept))->toBe([]);
});

// R28-11: a refusal says the whole rule, so an administrator whose `X_Key` was refused learns the
// alphabet, not only that a letter is needed; the field's own text says it before any save.
it('states the header name rule, alphabet and all, and the field says it', function (): void {
    expect(Schema::mcpHeaderNameRule())->toContain('1 to 64')->toContain('A-Z')->toContain('a-z')->toContain('digits')->toContain('hyphens')->toContain('a letter among them')
        ->and(Schema::fields()['toolkits.mcp_servers']['description'])->toContain('The header name has to be ' . Schema::mcpHeaderNameRule() . '.');
});

// The model knows an MCP tool as ToolName::fit('<prefix>__<name>'). A prefix with no `__` in it and
// no `_` at its end makes the first `__` of that name the end of the prefix, so two servers,
// whose prefixes differ, can never give two tools one name; and `ability` is refused, since
// AbilitiesToolkit names every ability `ability__<namespace>__<name>`. The brute force is the
// reviewer's: every prefix over {a, b, _} up to five characters, every tool name over {a, _, .}
// up to four, two names long enough that fit() cuts and marks them, and prefixes near `ability`.
it('admits no two prefixes, and no prefix beside the abilities, that put two tools under one name', function (): void {
    $words = static function (array $alphabet, int $max): array {
        $out = [];
        $level = [''];
        for ($n = 1; $n <= $max; $n++) {
            $next = [];
            foreach ($level as $w) {
                foreach ($alphabet as $c) {
                    $next[] = $w . $c;
                }
            }
            $out = array_merge($out, $next);
            $level = $next;
        }
        return $out;
    };
    $prefixes = array_values(array_filter(
        array_merge($words(['a', 'b', '_'], 5), ['ability', 'ability_', 'ability__core', 'ability_x', 'abilit', 'abilityx', 'trk', 'trk_']),
        [Schema::class, 'isMcpPrefix'],
    ));
    $names = array_merge($words(['a', '_', '.'], 4), [str_repeat('a', 70), str_repeat('a', 69) . '_'], ['core__get-site-info', 'get-site-info', '_x', 'x']);
    $owners = [];
    foreach ($prefixes as $prefix) {
        foreach ($names as $name) {
            $owners[ToolName::fit($prefix . '__' . $name)][$prefix] = true;
        }
    }
    $clashes = array_filter($owners, static fn(array $by): bool => count($by) > 1);
    $abilities = array_map([AbilitiesToolkit::class, 'toolName'], ['core/get-site-info', 'a/b', 'ability/x', 'x__y/z']);
    expect($prefixes)->toContain('trk')->toContain('a_b')->toContain('ability_x')->toContain('abilit')
        ->not->toContain('trk_')->not->toContain('a__b')->not->toContain('ability')->not->toContain('ability__core')
        ->and(array_keys($clashes))->toBe([])
        ->and(array_values(array_intersect($abilities, array_keys($owners))))->toBe([]);
});

it('clamps a server\'s timeout and byte cap, and gives an unreadable one the default', function (): void {
    $rows = Schema::sanitizeMcpServers([
        ['url' => 'https://a.example.com/mcp', 'prefix' => 'a', 'timeout' => '0', 'max_bytes' => '99999999999'],
        ['url' => 'https://b.example.com/mcp', 'prefix' => 'b', 'timeout' => 'soon', 'max_bytes' => ['x']],
        ['url' => 'https://c.example.com/mcp', 'prefix' => 'c', 'timeout' => '12.5', 'max_bytes' => '2048'],
    ]);
    expect(array_column($rows, 'timeout'))->toBe([1.0, 30.0, 12.5])
        ->and(array_column($rows, 'max_bytes'))->toBe([8388608, 1048576, 2048]);
});

// The id names the server everywhere else -- its Access entry, its filter, its header value -- so
// it is assigned once and survives an edit of the prefix or the URL. A row that brings a usable id
// keeps it before any row is given a new one, so a new row can never take an existing server's
// id, and with it that server's Access entry, by being listed first.
it('keeps a row\'s id ahead of making any, and makes one from the prefix when there is none or it is taken', function (): void {
    $rows = Schema::sanitizeMcpServers([
        ['id' => 'tracker', 'url' => 'https://a.example.com/mcp', 'prefix' => 'trk'],
        ['url' => 'https://b.example.com/mcp', 'prefix' => 'gh'],
        ['id' => 'gh', 'url' => 'https://c.example.com/mcp', 'prefix' => 'gh2'],
        ['id' => 'gh', 'url' => 'https://d.example.com/mcp', 'prefix' => 'gh3'],
    ]);
    expect(array_column($rows, 'id'))->toBe(['tracker', 'gh_2', 'gh', 'gh3']);
});

// A row that brings no id of its own is a new server, and Mcp\ServerSettings tells a new server
// from a stored one by id alone. So a made id is never one the stored list holds, even when the
// server that held it is being removed in this same write: a new row would otherwise be read as
// that server, and keep its header value and its Access entry. sanitize() hands over the stored
// ids; a row that names one of them itself is that server, and keeps it.
it('never makes an id the stored list holds, and lets a row that names one keep it', function (): void {
    $rows = Schema::sanitizeMcpServers([
        ['url' => 'https://a.example.com/mcp', 'prefix' => 'trk'],
        ['id' => 'gh', 'url' => 'https://b.example.com/mcp', 'prefix' => 'gh'],
    ], [['id' => 'trk', 'url' => 'https://gone.example.com/mcp', 'prefix' => 'trk'], ['id' => 'gh'], ['id' => 'trk_2']]);
    expect(array_column($rows, 'id'))->toBe(['trk_3', 'gh']);
    $stored = ['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://gone.example.com/mcp', 'prefix' => 'trk']]];
    expect(array_column(Schema::sanitize(['toolkits.mcp_servers' => [['url' => 'https://new.example.com/mcp', 'prefix' => 'trk']]], $stored)['toolkits.mcp_servers'], 'id'))->toBe(['trk_2'])
        ->and(array_column(Schema::sanitize(['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://moved.example.com/mcp', 'prefix' => 'trk']]], $stored)['toolkits.mcp_servers'], 'id'))->toBe(['trk'])
        // Left out of the write, the stored list is sanitized against itself and keeps its ids.
        ->and(array_column(Schema::sanitize([], $stored)['toolkits.mcp_servers'], 'id'))->toBe(['trk']);
});

// R73: an id and a prefix start with a letter and are anchored with \z. An all-digit id would be
// an int key in the access.mcp map, which gets no Access row; a digit-first prefix would make
// every tool name start with a digit, which a tool name may not.
it('makes a new id for one that is all digits, starts with a digit, ends in a newline, or is not lowercase', function (): void {
    $rows = Schema::sanitizeMcpServers([
        ['id' => '12', 'url' => 'https://a.example.com/mcp', 'prefix' => 'a'],
        ['id' => '1abc', 'url' => 'https://b.example.com/mcp', 'prefix' => 'b'],
        ['id' => "cc\n", 'url' => 'https://c.example.com/mcp', 'prefix' => 'c'],
        ['id' => 'Dee', 'url' => 'https://d.example.com/mcp', 'prefix' => 'd'],
        ['id' => str_repeat('e', 25), 'url' => 'https://e.example.com/mcp', 'prefix' => 'e'],
        ['id' => 12, 'url' => 'https://f.example.com/mcp', 'prefix' => 'f'],
        ['id' => str_repeat('g', 24), 'url' => 'https://g.example.com/mcp', 'prefix' => 'g'],
    ]);
    expect(array_column($rows, 'id'))->toBe(['a', 'b', 'c', 'd', 'e', 'f', str_repeat('g', 24)]);
});

it('applies the secret rule to a header value, and lets no value end the header early', function (): void {
    $rows = Schema::sanitizeMcpServers([
        ['url' => 'https://a.example.com/mcp', 'prefix' => 'a', 'header_value' => Schema::MASK],
        ['url' => 'https://b.example.com/mcp', 'prefix' => 'b', 'header_value' => ''],
        ['url' => 'https://c.example.com/mcp', 'prefix' => 'c', 'header_value' => null],
        ['url' => 'https://d.example.com/mcp', 'prefix' => 'd', 'header_value' => "Bearer t\r\nX-Evil: 1"],
        ['url' => 'https://e.example.com/mcp', 'prefix' => 'e', 'header_name' => 'X-Key'],
        ['url' => 'https://f.example.com/mcp', 'prefix' => 'f', 'header_value' => "Bearer\0t", 'header_name' => 'Authorization'],
        ['url' => 'https://g.example.com/mcp', 'prefix' => 'g', 'header_value' => ['x']],
    ]);
    expect(array_column($rows, 'header_value'))->toBe([Schema::MASK, '', Schema::MASK, 'Bearer tX-Evil: 1', Schema::MASK, 'Bearert', Schema::MASK])
        ->and(array_column($rows, 'header_name'))->toBe(['', '', '', '', 'X-Key', 'Authorization', '']);
});

it('keeps an approved map of tool names to fingerprints and nothing else', function (): void {
    $rows = Schema::sanitizeMcpServers([[
        'url' => 'https://a.example.com/mcp', 'prefix' => 'a',
        'approved' => ['search' => str_repeat('a', 64), 'repo.list-all_2' => str_repeat('c', 64), 'write' => 'not-a-fingerprint', 'sp ace' => str_repeat('b', 64), 'upper' => str_repeat('A', 64), 7 => str_repeat('d', 64), "nl\n" => str_repeat('e', 64)],
    ], [
        'url' => 'https://b.example.com/mcp', 'prefix' => 'b', 'approved' => 'search',
    ]]);
    expect($rows[0]['approved'])->toBe(['search' => str_repeat('a', 64), 'repo.list-all_2' => str_repeat('c', 64)])
        ->and($rows[1]['approved'])->toBe([]);
});

// R28-5 and carry 5: the field says where a credential goes. The URL, query string and all, is
// stored in the clear and read back (GET /settings answers it); the header value is sent as typed
// (PhpAgentsClient::over() puts nothing in front of it), so Authorization takes its scheme and a
// custom header the bare key. The reason is the plain one: php-agents' redaction of a server's
// error text is no reason, since the plugin never shows the library's text (R28-5).
it('tells an administrator the URL is not a secret and a custom header takes the bare key', function (): void {
    $d = Schema::fields()['toolkits.mcp_servers']['description'];
    expect($d)->toContain('query string')->toContain('in the clear')->toContain('GET /settings')
        ->toContain('The header value is sent as typed, with nothing put in front of it: for Authorization type the scheme and the key (Bearer …), and for a header such as X-API-Key the bare key.')
        ->not->toContain('error text');
});

// Task 28.4: Mcp\Egress checks the address again whenever a client is built, which is every
// Discover and every turn that lists the server, so the save is not the only check. A turn's tool
// calls reuse the client its listing built, so the check is not run per request (R28-11).
it('tells an administrator the address is checked at save and again each time a connection is built', function (): void {
    expect(Schema::fields()['toolkits.mcp_servers']['description'])
        ->toContain('The address is checked when it is saved from this screen or over the REST API, and again each time Alpaca Bot builds a connection to it: on Discover tools, and on a chat turn that lists its tools.')
        ->not->toContain('each time Alpaca Bot connects');
});

it('stores toolkits.mcp_servers as a list on the Tools tab, empty by default, through sanitizeMcpServers()', function (): void {
    $f = Schema::fields()['toolkits.mcp_servers'];
    expect($f['type'])->toBe('array')->and($f['section'])->toBe('toolkits')->and($f['default'])->toBe([])
        ->and(Schema::LISTS)->toContain('toolkits.mcp_servers')
        ->and(Schema::sanitize(['toolkits.mcp_servers' => [['url' => 'https://a.example.com/mcp', 'prefix' => 'a'], ['url' => '']]], [])['toolkits.mcp_servers'])->toHaveCount(1)
        // Left out of the write: kept, and cleaned again on the way through.
        ->and(Schema::sanitize([], ['toolkits.mcp_servers' => [['url' => 'http://a.example.com/mcp', 'prefix' => 'a']]])['toolkits.mcp_servers'])->toBe([]);
});

it('keeps an access entry per server id, holding each id to the server id rule and each value to the fixed capability list', function (): void {
    expect(Schema::sanitizeAccessMcp([
        'trk' => 'edit_posts',
        'gh' => 'do_anything',
        'B A D' => 'read',
        '12' => 'read',
        '1abc' => 'read',
        "nl\n" => 'read',
        'Upper' => 'read',
        str_repeat('x', 25) => 'read',
        str_repeat('y', 24) => 'read',
    ]))->toBe(['trk' => 'edit_posts', str_repeat('y', 24) => 'read']);
});

it('names a server id by one rule, anchored at the very end', function (): void {
    expect(Schema::isMcpId('a'))->toBeTrue()
        ->and(Schema::isMcpId('a_1'))->toBeTrue()
        ->and(Schema::isMcpId(str_repeat('a', 24)))->toBeTrue()
        ->and(Schema::isMcpId(str_repeat('a', 25)))->toBeFalse()
        ->and(Schema::isMcpId("a\n"))->toBeFalse()
        ->and(Schema::isMcpId('1a'))->toBeFalse()
        ->and(Schema::isMcpId('12'))->toBeFalse()
        ->and(Schema::isMcpId('_a'))->toBeFalse()
        ->and(Schema::isMcpId('a.b'))->toBeFalse()
        ->and(Schema::isMcpId('a/b'))->toBeFalse()
        ->and(Schema::isMcpId('A'))->toBeFalse()
        ->and(Schema::isMcpId(12))->toBeFalse()
        ->and(Schema::isMcpId(''))->toBeFalse();
});

// m-4: a client that writes its servers as configuration, without ids, sends the same body every
// time. A row with no id whose URL and prefix are a stored server's, where that server's id is not
// posted by any other row, is that server, so the id stays put from one PUT to the next rather than
// flipping to `trk_2` and back (and the Access entry with it). A stored server whose id the post
// does name (the Tools tab posts it, a ticked `remove` included) is never adopted this way.
it('reads a row with no id as the stored server with the same URL and prefix, when the post names that id nowhere', function (): void {
    $stored = ['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]];
    $same = ['toolkits.mcp_servers' => [['url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]];
    $current = $stored;
    foreach ([1, 2, 3, 4] as $put) {
        $current = Schema::sanitize($same, $current);
        expect(array_column($current['toolkits.mcp_servers'], 'id'))->toBe(['trk'], "PUT {$put}");
    }
    // Another URL under that prefix is another server.
    expect(array_column(Schema::sanitize(['toolkits.mcp_servers' => [['url' => 'https://other.example.com/mcp', 'prefix' => 'trk']]], $stored)['toolkits.mcp_servers'], 'id'))->toBe(['trk_2'])
        // The post removes trk by id and adds the same URL and prefix: that is a new server.
        ->and(array_column(Schema::sanitize(['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk', 'remove' => '1'], ['url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk']]], $stored)['toolkits.mcp_servers'], 'id'))->toBe(['trk_2']);
    // An option that holds one id twice (a hand edit) gives it to one row only.
    $twice = [['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk'], ['id' => 'trk', 'url' => 'https://gh.example.com/mcp', 'prefix' => 'gh']];
    expect(array_column(Schema::sanitizeMcpServers([['url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk'], ['url' => 'https://gh.example.com/mcp', 'prefix' => 'gh']], $twice), 'id'))->toBe(['trk', 'gh']);
});
