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
        ->and(Schema::sanitize(['chat.context_messages' => '0'])['chat.context_messages'])->toBe(0)
        ->and(Schema::sanitize(['chat.context_messages' => '-3'])['chat.context_messages'])->toBe(0)
        ->and(Schema::sanitize(['chat.context_messages' => 'x'])['chat.context_messages'])->toBe(20);
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
    ]);
    expect($out['provider.base_url'])->toBe('http://ollama:11434/v1')
        ->and($out['models.temperature'])->toBe(2.0)
        ->and($out['models.num_ctx'])->toBe(512)
        ->and($out['chat.user_can_change_model'])->toBeFalse()
        ->and($out['provider.kind'])->toBe('ollama')
        ->and($out['models.overrides'])->toBe(['llama3.2' => ['temperature' => 0.2]])
        ->and($out)->not->toHaveKey('nope')
        ->and($out['chat.history_limit'])->toBe(20);
});

it('sanitizing an empty input yields exactly the defaults', function (): void {
    expect(Schema::sanitize([]))->toBe(Schema::defaults());
});

it('clamps per-model overrides to the same bounds as the global fields', function (): void {
    $fields = Schema::fields();
    $out = Schema::sanitize(['models.overrides' => [
        'hot' => ['temperature' => '99', 'num_ctx' => '999999999999'],
        'cold' => ['temperature' => '-3', 'num_ctx' => '1', 'keep_alive' => ' 1h ', 'system' => ' be terse '],
        'junk' => ['temperature' => 'warm', 'num_ctx' => 'lots'],
    ]]);
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
    ]);
    expect($out['provider.base_url'])->toBe(Schema::defaults()['provider.base_url'])
        ->and($out['chat.assistant_avatar'])->toBe('');

    $ok = Schema::sanitize(['provider.base_url' => ' https://ollama.example/v1/ ', 'chat.assistant_avatar' => 'https://example.com/a.png']);
    expect($ok['provider.base_url'])->toBe('https://ollama.example/v1')
        ->and($ok['chat.assistant_avatar'])->toBe('https://example.com/a.png');
});
