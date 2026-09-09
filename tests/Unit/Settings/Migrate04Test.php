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
        'alpaca_bot_default_assistant_prompt_placeholder' => 'Ask me',
        'alpaca_bot_default_avatar' => 'https://example.com/bot.png',
        'alpaca_bot_spellcheck' => '1',
        // 0.4 sent this as HTTP Basic next to api_username; 0.5 sends api_key as Bearer. Never carried over.
        'alpaca_bot_api_password' => 'app-password',
    ];
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $legacy[$k] ?? ($k === 'alpaca_bot_settings' ? [] : $d));
    $written = null;
    Functions\when('update_option')->alias(function (string $k, mixed $v) use (&$written): bool { if ($k === 'alpaca_bot_settings') { $written = $v; } return true; });
    Functions\when('get_posts')->justReturn([]);
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
        // 0.4's chat_history_limit was the number of messages sent to the model: that is chat.context_messages.
        ->and($out['chat.context_messages'])->toBe(10)
        ->and($out['chat.history_limit'])->toBe(20)
        ->and($out['toolkits.user_agent'])->toBe('Custom UA')
        ->and($out['chat.welcome'])->toBe('Hi!')
        ->and($out['chat.placeholder'])->toBe('Ask me')
        ->and($out['chat.assistant_avatar'])->toBe('https://example.com/bot.png')
        ->and($out['chat.spellcheck'])->toBeTrue()
        ->and($out['provider.api_key'])->toBe('')
        ->and($written)->toBe($out);
});

it('is not needed when all three flags are set, and writes nothing', function (): void {
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => in_array($k, [Migrate04::FLAG, Migrate04::FLAG_RETENTION, Migrate04::FLAG_CONVERSATIONS], true) ? '1' : $d);
    Functions\expect('update_option')->never();
    Functions\expect('get_posts')->never();
    expect((new Migrate04(new Store()))->needed())->toBeFalse();
});

// Without the flags a 0.5-only site would re-run the detection get_option() calls (all
// non-autoloaded, so uncached misses) and the conversation query on every admin request forever.
it('on a fresh install moves no options, runs one empty conversation batch, and flags all three steps so detection runs only once', function (): void {
    $stored = [];
    migrate04Options($stored);
    // One query for receipts (none: a fresh site keeps the retention default), one conversation batch.
    Functions\expect('get_posts')->twice()->andReturn([]);
    Functions\expect('wp_update_post')->never();
    $m = new Migrate04(new Store());
    expect($m->needed())->toBeTrue()
        ->and($stored)->toBe([Migrate04::FLAG => '1']);
    $m->run();
    expect($stored)->toBe([Migrate04::FLAG => '1', Migrate04::FLAG_RETENTION => '1', Migrate04::FLAG_CONVERSATIONS => '1'])
        ->and($m->needed())->toBeFalse()
        ->and((new Store())->get('privacy.usage_retention_days'))->toBe(90);
});

// `privacy.usage_retention_days` arrived after sites were already recording receipts. Its
// default would have the daily cleanup delete an upgraded site's whole usage history a day
// after the upgrade, unasked; a site with anything the field could act on gets 0 written
// explicitly, once, so nothing goes until an admin chooses a window. Only a fresh install,
// with neither a settings row nor a receipt, takes the 90-day default.
it('writes retention 0 on a site whose settings row predates the field, and leaves a row that has it alone', function (): void {
    $stored = [Migrate04::FLAG => '1', Migrate04::FLAG_CONVERSATIONS => '1', 'alpaca_bot_settings' => ['models.default' => 'qwen3-vl:2b', 'provider.base_url' => 'http://ollama:11434/v1']];
    migrate04Options($stored);
    Functions\expect('get_posts')->never();
    $m = new Migrate04(new Store());
    expect($m->needed())->toBeTrue();
    $out = $m->run();
    expect($out['privacy.usage_retention_days'])->toBe(0)
        ->and($out['models.default'])->toBe('qwen3-vl:2b')
        ->and($out['provider.base_url'])->toBe('http://ollama:11434/v1')
        ->and($stored['alpaca_bot_settings']['privacy.usage_retention_days'])->toBe(0)
        ->and($stored[Migrate04::FLAG_RETENTION])->toBe('1')
        ->and($m->needed())->toBeFalse();

    // A row that already carries the field was saved by an admin who saw it: their number stands.
    $stored = [Migrate04::FLAG => '1', Migrate04::FLAG_CONVERSATIONS => '1', 'alpaca_bot_settings' => ['privacy.usage_retention_days' => 45]];
    migrate04Options($stored);
    (new Migrate04(new Store()))->run();
    expect($stored['alpaca_bot_settings']['privacy.usage_retention_days'])->toBe(45)
        ->and($stored[Migrate04::FLAG_RETENTION])->toBe('1');
});

it('writes retention 0 on a 0.4 site that has receipts and no settings row yet, alongside the migrated options', function (): void {
    $stored = ['alpaca_bot_default_model' => 'llama3.2'];
    migrate04Options($stored);
    Functions\when('get_posts')->alias(static fn(array $q): array => $q['post_type'] === 'chat_log' ? [17] : []);
    Functions\expect('wp_update_post')->never();
    $out = (new Migrate04(new Store()))->run();
    expect($out['privacy.usage_retention_days'])->toBe(0)
        ->and($out['models.default'])->toBe('llama3.2')
        ->and($stored['alpaca_bot_settings']['privacy.usage_retention_days'])->toBe(0)
        ->and($stored['alpaca_bot_settings']['models.default'])->toBe('llama3.2');
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
    Functions\when('get_posts')->justReturn([]);
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

// Options::get() went through empty() and `$value ? $value : $default`, so a
// stored '0' was just as "unset" as '' — and the 0.4 UI told admins to enter 0
// for history_limit ("Set to 0 to send all messages").
it("treats a stored '0' as unset like 0.4 did, instead of clamping it to the field minimum", function (): void {
    $legacy = [
        'alpaca_bot_api_url' => 'http://localhost:11434',
        'alpaca_bot_ollama_timeout' => '0',
        'alpaca_bot_default_temperature' => '0',
        'alpaca_bot_default_num_ctx' => '0',
        'alpaca_bot_chat_history_limit' => '0',
    ];
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $legacy[$k] ?? ($k === 'alpaca_bot_settings' ? [] : $d));
    Functions\when('update_option')->justReturn(true);
    Functions\when('get_posts')->justReturn([]);
    $out = (new Migrate04(new Store()))->run();
    $defaults = Schema::defaults();
    expect($out['provider.timeout'])->toBe($defaults['provider.timeout'])
        ->and($out['models.temperature'])->toBe($defaults['models.temperature'])
        ->and($out['models.num_ctx'])->toBe($defaults['models.num_ctx'])
        ->and($out['chat.history_limit'])->toBe($defaults['chat.history_limit']);
});

it('is idempotent: a second run over the migrated option changes nothing', function (): void {
    $legacy = [
        'alpaca_bot_api_url' => 'http://localhost:11434',
        'alpaca_bot_default_model' => 'llama3.2',
        'alpaca_bot_chat_history_limit' => '10',
    ];
    $stored = [];
    Functions\when('get_option')->alias(function (string $k, mixed $d = false) use ($legacy, &$stored): mixed {
        return $legacy[$k] ?? ($k === 'alpaca_bot_settings' ? $stored : $d);
    });
    Functions\when('update_option')->alias(function (string $k, mixed $v) use (&$stored): bool {
        if ($k === 'alpaca_bot_settings') {
            $stored = $v;
        }
        return true;
    });
    Functions\when('get_posts')->justReturn([]);
    $first = (new Migrate04(new Store()))->run();
    $second = (new Migrate04(new Store()))->run();
    expect($first['provider.base_url'])->toBe('http://localhost:11434/v1')
        ->and($second)->toBe($first);
});

// A renamed schema key would otherwise surface as an undefined-index warning
// inside run() and silently disable the blank-value guard for that key.
it('only maps onto keys that exist in the schema', function (): void {
    $map = (new ReflectionClassConstant(Migrate04::class, 'MAP'))->getValue();
    expect($map)->toBeArray()->not->toBeEmpty();
    foreach ($map as $legacy => $target) {
        expect(Schema::fields())->toHaveKey($target, message: "{$legacy} => {$target}");
    }
    expect($map)->not->toHaveKey('api_password');
});

// 0.4 stored conversations as `publish` posts and left post_author to wp_insert_post()'s default
// (the current user, so 0 for a request without one). ConversationStore::load() checks the owner
// strictly, so every legacy row is flipped to private and an unowned one takes its owner from the
// first message: 0.4 wrote the user's id as that role. A row that has an owner keeps it.
it('makes legacy chat_history rows private and recovers a missing owner from the first message role', function (): void {
    $stored = ['alpaca_bot_api_url' => 'http://localhost:11434'];
    migrate04Options($stored);
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_history' && $q['post_status'] === 'publish' && $q['numberposts'] === 100 && $q['orderby'] === 'ID' && $q['order'] === 'ASC' && !isset($q['fields']))
        ->andReturn([migrate04LegacyRow(10), migrate04LegacyRow(11), migrate04LegacyRow(12), migrate04LegacyRow(13, '3')]);
    // Only an unowned row has its transcript read.
    Functions\expect('get_post_meta')->once()->with(10, 'messages', true)->andReturn([['model' => 'm', 'message' => ['role' => 7, 'content' => 'q']], ['model' => 'm', 'message' => ['role' => 'assistant', 'content' => 'a']]]);
    Functions\expect('get_post_meta')->once()->with(11, 'messages', true)->andReturn([['model' => 'm', 'message' => ['role' => 0, 'content' => 'q']]]);
    Functions\expect('get_post_meta')->once()->with(12, 'messages', true)->andReturn('');
    Functions\expect('get_post_meta')->never()->with(13, 'messages', true);
    Functions\expect('wp_update_post')->once()->with(['ID' => 10, 'post_status' => 'private', 'post_author' => 7])->andReturn(10);
    Functions\expect('wp_update_post')->once()->with(['ID' => 11, 'post_status' => 'private'])->andReturn(11);
    Functions\expect('wp_update_post')->once()->with(['ID' => 12, 'post_status' => 'private'])->andReturn(12);
    Functions\expect('wp_update_post')->once()->with(['ID' => 13, 'post_status' => 'private'])->andReturn(13);
    (new Migrate04(new Store()))->run();
    expect($stored[Migrate04::FLAG])->toBe('1')->and($stored[Migrate04::FLAG_CONVERSATIONS])->toBe('1');
});

// The pass is bounded per request and resumable: a full batch leaves the conversation flag unset,
// the rows it flipped to private drop out of the next query, and the options move (already
// flagged) is not repeated, so settings the admin has changed since are not overwritten.
it('migrates conversations in bounded batches across requests without re-running the options move', function (): void {
    $stored = ['alpaca_bot_api_url' => 'http://localhost:11434', 'alpaca_bot_default_model' => 'llama3.2'];
    migrate04Options($stored);
    Functions\when('get_post_meta')->justReturn('');
    Functions\expect('get_posts')->twice()->withArgs(fn(array $q): bool => $q['post_status'] === 'publish' && $q['numberposts'] === 100)
        ->andReturn(array_map(fn(int $id): object => migrate04LegacyRow($id, '3'), range(1, 100)), [migrate04LegacyRow(101, '3'), migrate04LegacyRow(102, '3')]);
    Functions\expect('wp_update_post')->times(102)->withArgs(fn(array $p): bool => $p['post_status'] === 'private')->andReturn(1);

    // Request 1: options moved and flagged at once; a full batch, so the conversation pass is still pending.
    $m = new Migrate04(new Store());
    expect($m->needed())->toBeTrue();
    $m->run();
    expect($stored[Migrate04::FLAG])->toBe('1')
        ->and($stored)->not->toHaveKey(Migrate04::FLAG_CONVERSATIONS)
        ->and($stored['alpaca_bot_settings']['models.default'])->toBe('llama3.2');

    // The admin changes a setting in between.
    $stored['alpaca_bot_settings']['models.default'] = 'mistral';

    // Request 2: a short batch completes the pass; the settings are left alone.
    $m = new Migrate04(new Store());
    expect($m->needed())->toBeTrue();
    $m->run();
    expect($stored[Migrate04::FLAG_CONVERSATIONS])->toBe('1')
        ->and($stored['alpaca_bot_settings']['models.default'])->toBe('mistral')
        ->and((new Migrate04(new Store()))->needed())->toBeFalse();
});
