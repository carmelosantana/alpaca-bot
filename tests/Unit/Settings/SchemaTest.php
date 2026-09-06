<?php

declare(strict_types=1);

use AlpacaBot\Settings\Schema;
use Brain\Monkey\Functions;

it('has defaults for every field and a section for each', function (): void {
    $fields = Schema::fields();
    $sections = Schema::sections();
    expect($fields)->toHaveKeys(['provider.kind', 'provider.base_url', 'models.default', 'models.temperature', 'models.num_ctx', 'models.keep_alive', 'models.overrides', 'chat.system_prompt', 'chat.history_limit', 'privacy.save_history', 'privacy.usage_log', 'governance.site_monthly_tokens', 'governance.user_monthly_tokens', 'toolkits.user_agent']);
    foreach ($fields as $key => $f) {
        expect($f)->toHaveKeys(['type', 'default', 'section', 'label'], $key);
        expect($sections)->toHaveKey($f['section'], message: $key);
    }
    expect(Schema::defaults()['provider.base_url'])->toBe('http://localhost:11434/v1')
        ->and(Schema::defaults()['models.temperature'])->toBe(0.7);
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
