<?php

declare(strict_types=1);

use AlpacaBot\Settings\Schema;

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
