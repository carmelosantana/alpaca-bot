<?php

declare(strict_types=1);

use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

it('maps 0.4 options into the new schema and appends /v1 to the base url', function (): void {
    $legacy = [
        'alpaca_bot_api_url' => 'http://host.docker.internal:11434',
        'alpaca_bot_default_model' => 'llama3.2',
        'alpaca_bot_default_system' => 'You are helpful.',
        'alpaca_bot_default_temperature' => '0.3',
        'alpaca_bot_default_num_ctx' => '4096',
        'alpaca_bot_chat_history_save' => '1',
        'alpaca_bot_chat_response_log' => '',
        'alpaca_bot_chat_history_limit' => '10',
        'alpaca_bot_user_can_change_model' => '1',
        'alpaca_bot_user_agent' => 'Custom UA',
        'alpaca_bot_default_assistant_welcome_message' => 'Hi!',
    ];
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $legacy[$k] ?? ($k === 'alpaca_bot_settings' ? [] : $d));
    $written = null;
    Functions\when('update_option')->alias(function (string $k, mixed $v) use (&$written): bool { if ($k === 'alpaca_bot_settings') { $written = $v; } return true; });
    $m = new Migrate04(new Store());
    expect($m->needed())->toBeTrue();
    $out = $m->run();
    expect($out['provider.base_url'])->toBe('http://host.docker.internal:11434/v1')
        ->and($out['models.default'])->toBe('llama3.2')
        ->and($out['chat.system_prompt'])->toBe('You are helpful.')
        ->and($out['models.temperature'])->toBe(0.3)
        ->and($out['models.num_ctx'])->toBe(4096)
        ->and($out['privacy.save_history'])->toBeTrue()
        ->and($out['privacy.usage_log'])->toBeFalse()
        ->and($out['chat.history_limit'])->toBe(10)
        ->and($out['toolkits.user_agent'])->toBe('Custom UA')
        ->and($out['chat.welcome'])->toBe('Hi!')
        ->and($written)->toBe($out);
});

it('is not needed when the flag is set or no legacy option exists', function (): void {
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $k === Migrate04::FLAG ? '1' : $d);
    expect((new Migrate04(new Store()))->needed())->toBeFalse();
});

it('is not needed on a fresh install with no 0.4 options at all', function (): void {
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $k === 'alpaca_bot_settings' ? [] : $d);
    expect((new Migrate04(new Store()))->needed())->toBeFalse();
});

// 0.4 saved every field of a settings tab on submit, so blank inputs were stored
// as '' and "No" radios as ''. Options::get() treated '' as "unset, use default" —
// which for the boolean radios meant false. The migration mirrors that.
it('treats blank 0.4 values as unset, except booleans which are false, and sets the flag', function (): void {
    $legacy = [
        'alpaca_bot_api_url' => 'http://localhost:11434/v1/',
        'alpaca_bot_ollama_timeout' => '',
        'alpaca_bot_default_temperature' => '',
        'alpaca_bot_default_num_ctx' => '',
        'alpaca_bot_chat_history_limit' => '',
        'alpaca_bot_default_assistant_welcome_message' => '',
        'alpaca_bot_user_agent' => '',
        'alpaca_bot_chat_history_save' => '',
        'alpaca_bot_spellcheck' => 'false',
        'alpaca_bot_user_can_change_model' => 'true',
    ];
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $legacy[$k] ?? ($k === 'alpaca_bot_settings' ? [] : $d));
    $writes = [];
    Functions\when('update_option')->alias(function (string $k, mixed $v) use (&$writes): bool { $writes[$k] = $v; return true; });
    $out = (new Migrate04(new Store()))->run();
    $defaults = Schema::defaults();
    expect($out['provider.base_url'])->toBe('http://localhost:11434/v1')
        ->and($out['provider.timeout'])->toBe($defaults['provider.timeout'])
        ->and($out['models.temperature'])->toBe($defaults['models.temperature'])
        ->and($out['models.num_ctx'])->toBe($defaults['models.num_ctx'])
        ->and($out['chat.history_limit'])->toBe($defaults['chat.history_limit'])
        ->and($out['chat.welcome'])->toBe($defaults['chat.welcome'])
        ->and($out['toolkits.user_agent'])->toBe($defaults['toolkits.user_agent'])
        ->and($out['privacy.save_history'])->toBeFalse()
        ->and($out['chat.spellcheck'])->toBeFalse()
        ->and($out['chat.user_can_change_model'])->toBeTrue()
        ->and($writes)->toHaveKey(Migrate04::FLAG)
        ->and($writes[Migrate04::FLAG])->toBe('1');
});
