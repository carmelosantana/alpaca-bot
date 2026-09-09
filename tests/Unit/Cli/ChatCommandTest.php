<?php

declare(strict_types=1);

use AlpacaBot\Chat\Result;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

// cliCommand() and cliUsers() live in tests/Pest.php, next to the pipelineWith()/pipelineProvider()
// harness they build on. Every collaborator is the real, final class; the command's stdout and
// its failure reports are captured by the two injected callables, so WP-CLI is never loaded.

// ------------------------------------------------------------------------------------- chat

it('streams deltas to the writer and prints a receipt', function (): void {
    $h = pipelineWith(pipelineProvider([
        new Response('Hi ', ProviderFinishReason::Stop),
        new Response('you', ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)),
    ]));
    cliUsers();
    $receipt = null;
    Actions\expectDone('alpaca_bot/chat/completed')->once()->whenHappen(function (Result $r) use (&$receipt): void {
        $receipt = $r->receipt;
    });
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3']);

    expect($c->errors)->toBe([])
        ->and($receipt['user_id'])->toBe(3)
        ->and($c->out)->toBe("Hi you\n\n[llama3.2 · 7 tokens · {$receipt['duration_ms']} ms · conversation 42 · as user 3]\n");
});

it('prints the whole turn as JSON with --json, and no deltas', function (): void {
    $h = pipelineWith(pipelineProvider([
        new Response('Hi ', ProviderFinishReason::Stop),
        new Response('you', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)),
    ]));
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3', 'json' => true]);

    $decoded = json_decode($c->out, true);
    expect($c->errors)->toBe([])
        ->and($c->out)->toEndWith("\n")
        ->and($decoded)->toBeArray()
        ->and($decoded['conversation_id'])->toBe(42)
        ->and($decoded['reply']['role'])->toBe('assistant')
        ->and($decoded['reply']['content'])->toBe('Hi you')
        ->and($decoded['receipt']['total_tokens'])->toBe(7)
        ->and($decoded['receipt']['model'])->toBe('llama3.2')
        ->and($decoded['receipt']['user_id'])->toBe(3);
});

it('treats --format=json the same as --json, because WP-CLI 2.12 rewrites --json into --format=json before the command sees it', function (): void {
    $h = pipelineWith(pipelineProvider([
        new Response('Hi ', ProviderFinishReason::Stop),
        new Response('you', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7)),
    ]));
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3', 'format' => 'json']);

    $decoded = json_decode($c->out, true);
    expect($c->errors)->toBe([])
        ->and($decoded)->toBeArray()
        ->and($decoded['reply']['content'])->toBe('Hi you')
        ->and($c->out)->not->toStartWith('Hi ');
});

it('says when the conversation was not saved instead of naming conversation 0', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]), ['privacy.save_history' => false]);
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3']);

    expect($c->errors)->toBe([])
        ->and($c->out)->toMatch('/^ok\n\n\[llama3\.2 · 0 tokens · \d+ ms · not saved · as user 3\]\n$/');
});

// ---------------------------------------------------------------------------- chat: the user

it('runs as the current user when WP-CLI has set one (the global --user flag) and no id is passed', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]));
    cliUsers([3], 3);
    Functions\expect('get_users')->never();
    $c = cliCommand($h);

    $c->command->chat(['hello'], []);

    expect($c->errors)->toBe([])
        ->and($h->writes[0][0])->toBe('wp_insert_post')
        ->and($h->writes[0][2]['post_author'])->toBe(3);
});

it('falls back to the first administrator when nobody is set, and says so in the receipt', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('ok', ProviderFinishReason::Stop)]));
    cliUsers([3], 0);
    Functions\expect('get_users')->once()->with(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC'])->andReturn([3]);
    $c = cliCommand($h);

    $c->command->chat(['hello'], []);

    expect($c->errors)->toBe([])
        ->and($h->writes[0][2]['post_author'])->toBe(3)
        // The fallback is silent by design; the receipt is where the operator learns who was charged.
        ->and($c->out)->toEndWith(" · conversation 42 · as user 3]\n");
});

it('refuses to run with no user and no administrator to fall back to', function (): void {
    $h = pipelineWith(null);
    cliUsers([], 0);
    Functions\when('get_users')->justReturn([]);
    Actions\expectDone('alpaca_bot/chat/started')->never();
    $c = cliCommand($h);

    $c->command->chat(['hello'], []);

    expect($c->errors)->toBe(['No user to run as: pass --user=<id> (no administrator was found).'])
        ->and($c->out)->toBe('');
});

it('refuses a user id that does not exist', function (): void {
    $h = pipelineWith(null);
    cliUsers([3]);
    Actions\expectDone('alpaca_bot/chat/started')->never();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '99']);

    expect($c->errors)->toBe(['User 99 does not exist.'])
        ->and($c->out)->toBe('');
});

it('refuses a user that is not a number rather than reading it as 0 and falling back', function (): void {
    $h = pipelineWith(null);
    cliUsers();
    Functions\expect('get_users')->never();
    Actions\expectDone('alpaca_bot/chat/started')->never();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => 'abc']);

    expect($c->errors)->toBe(['--user takes a user id (a number).'])
        ->and($c->out)->toBe('');
});

// ------------------------------------------------------------------------ chat: error paths

it('reports an empty message instead of throwing', function (): void {
    $h = pipelineWith(null);
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat([''], ['user' => '3']);

    expect($c->errors)->toBe(['The message is empty.'])
        ->and($c->out)->toBe('');
});

it('reports the monthly cap with its figures for the user scope', function (): void {
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3']);

    expect($c->errors)->toBe(['Your monthly token cap has been reached (12 of 10 tokens).'])
        ->and($c->out)->toBe('');
});

it('reports the site-wide cap in its figure-less wording', function (): void {
    $h = pipelineWith(null, ['governance.site_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_site_2024-08'] = ['tokens' => 12, 'requests' => 1];
    cliUsers();
    Actions\expectDone('alpaca_bot/chat/started')->never();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3']);

    expect($c->errors)->toBe(["The site's monthly token cap has been reached."])
        ->and($c->out)->toBe('');
});

it('reports a model the catalog does not list', function (): void {
    $h = pipelineWith(null);
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3', 'model' => 'nope']);

    expect($c->errors)->toBe(['Model "nope" is not available.'])
        ->and($c->out)->toBe('');
});

it('says so when --model would be silently ignored because users may not change model', function (): void {
    $h = pipelineWith(null, ['chat.user_can_change_model' => false]);
    cliUsers();
    Actions\expectDone('alpaca_bot/chat/started')->never();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3', 'model' => 'llama3.2']);

    expect($c->errors)->toBe(['--model is ignored while chat.user_can_change_model is off. Turn it on with: wp alpaca-bot settings chat.user_can_change_model 1'])
        ->and($c->out)->toBe('');
});

it('reports a conversation the user does not own', function (): void {
    $h = pipelineWith(null);
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3', 'conversation' => '7']);

    expect($c->errors)->toBe(['Conversation 7 was not found.'])
        ->and($c->out)->toBe('');
});

it('refuses a conversation id that is not a number rather than starting a new conversation', function (): void {
    $h = pipelineWith(null);
    cliUsers();
    Actions\expectDone('alpaca_bot/chat/started')->never();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3', 'conversation' => 'latest']);

    expect($c->errors)->toBe(['--conversation takes a conversation id (a number).'])
        ->and($c->out)->toBe('');
});

it('reports a provider failure after whatever was streamed, on its own line', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('par', ProviderFinishReason::Stop), new \RuntimeException('connection refused')]));
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3']);

    expect($c->out)->toBe("par\n")
        ->and($c->errors)->toBe(['Provider error: connection refused']);
});

it('reports that no model is configured', function (): void {
    $h = pipelineWith(null, ['models.default' => ''], [], []);
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3']);

    expect($c->errors)->toBe(['No model is configured and the provider lists none.'])
        ->and($c->out)->toBe('');
});

it('puts the error on stdout as JSON too when --json was asked for', function (): void {
    $h = pipelineWith(null);
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat([''], ['user' => '3', 'json' => true]);

    expect($c->out)->toBe("{\n    \"error\": \"The message is empty.\"\n}\n")
        ->and($c->errors)->toBe(['The message is empty.']);
});

it('keeps stdout one parseable JSON object when the provider fails mid-stream under --json', function (): void {
    $h = pipelineWith(pipelineProvider([new Response('par', ProviderFinishReason::Stop), new \RuntimeException('connection refused')]));
    cliUsers();
    $c = cliCommand($h);

    $c->command->chat(['hello'], ['user' => '3', 'json' => true]);

    // No "par", no closing newline for it: nothing may precede the object.
    expect($c->out)->toBe("{\n    \"error\": \"Provider error: connection refused\"\n}\n")
        ->and(json_decode($c->out, true))->toBe(['error' => 'Provider error: connection refused'])
        ->and($c->errors)->toBe(['Provider error: connection refused']);
});

// ----------------------------------------------------------------------------------- models

it('lists the models the provider reports right now, with their capability flags', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([
        new ModelDefinition(id: 'llama3.2:latest', name: 'llama3.2', provider: 'ollama'),
        // As Ollama describes it: vision and no tools. Nothing here is flagged by the name any more.
        new ModelDefinition(id: 'llava:7b', name: 'llava', provider: 'ollama', vision: true),
        new ModelDefinition(id: 'qwen3:8b', name: 'qwen3', provider: 'ollama'),
        new ModelDefinition(id: 'nomic-embed-text', name: 'nomic', provider: 'ollama'),
    ]);
    $h = pipelineWith($provider);
    $c = cliCommand($h);

    $c->command->models([], []);

    expect($c->errors)->toBe([])
        ->and($c->out)->toBe(
            sprintf("%-40s %s\n", 'llama3.2:latest', 'tools')
            . sprintf("%-40s %s\n", 'llava:7b', 'vision')
            . sprintf("%-40s %s\n", 'qwen3:8b', 'tools thinking'),
        );
});

// The same honesty the REST listing owes: `wp alpaca-bot models` is where an operator checks what
// a model will be offered, and a model they forced tools off on must not print `tools`.
it('prints the operator\'s per-model tools override rather than the catalogue\'s word', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([
        new ModelDefinition(id: 'llama3.2:latest', name: 'llama3.2', provider: 'ollama'),
        new ModelDefinition(id: 'llava:7b', name: 'llava', provider: 'ollama', vision: true),
    ]);
    $h = pipelineWith($provider, ['models.overrides' => [
        'llama3.2:latest' => ['tools' => Schema::TOOLS_OFF],
        'llava:7b' => ['tools' => Schema::TOOLS_ON],
    ]]);
    $c = cliCommand($h);

    $c->command->models([], []);

    expect($c->errors)->toBe([])
        ->and($c->out)->toBe(
            sprintf("%-40s %s\n", 'llama3.2:latest', '')
            . sprintf("%-40s %s\n", 'llava:7b', 'tools vision'),
        );
});

it('fails legibly when the provider lists nothing or cannot be reached', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andThrow(new \RuntimeException('connection refused'));
    $h = pipelineWith($provider);
    $c = cliCommand($h);

    $c->command->models([], []);

    expect($c->out)->toBe('')
        ->and($c->errors)->toBe(['No models were listed by http://localhost:11434/v1. Is the provider running, and is provider.base_url right?']);
});

// ------------------------------------------------------------------------------------ usage

it('prints the site-wide month summary by default', function (): void {
    $h = pipelineWith(null);
    $h->transients['alpaca_bot_usage_site_2024-08'] = ['tokens' => 70, 'requests' => 3];
    cliUsers([3], 0);
    $c = cliCommand($h);

    $c->command->usage([], []);

    expect($c->errors)->toBe([])
        ->and($c->out)->toBe("2024-08: 70 tokens over 3 requests (site-wide)\n");
});

it('prints one user\'s month with --user, or the current user when WP-CLI set one', function (): void {
    $h = pipelineWith(null);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    $h->transients['alpaca_bot_usage_5_2024-08'] = ['tokens' => 4, 'requests' => 2];
    cliUsers([3, 5], 5);
    $c = cliCommand($h);

    $c->command->usage([], ['user' => '3']);
    $c->command->usage([], []);

    expect($c->errors)->toBe([])
        ->and($c->out)->toBe("2024-08: 12 tokens over 1 requests (user 3)\n2024-08: 4 tokens over 2 requests (user 5)\n");
});

it('refuses a --user that is not a number or does not exist, rather than reporting site-wide totals', function (): void {
    $h = pipelineWith(null);
    $h->transients['alpaca_bot_usage_site_2024-08'] = ['tokens' => 70, 'requests' => 3];
    cliUsers([3], 0);
    $c = cliCommand($h);

    $c->command->usage([], ['user' => 'abc']);
    $c->command->usage([], ['user' => '99']);

    expect($c->out)->toBe('')
        ->and($c->errors)->toBe(['--user takes a user id (a number).', 'User 99 does not exist.']);
});

// --------------------------------------------------------------------------------- settings

it('dumps every setting as JSON when no key is given (an unset API key shows as empty)', function (): void {
    $h = pipelineWith(null);
    $c = cliCommand($h);

    $c->command->settings([], []);

    expect($c->errors)->toBe([])
        ->and($c->out)->toEndWith("\n")
        ->and(json_decode($c->out, true))->toBe(array_replace(Schema::defaults(), ['models.default' => 'llama3.2']));
});

it('masks the API key in the whole dump but prints it when asked for by name', function (): void {
    $h = pipelineWith(null, ['provider.api_key' => 'sk-secret']);
    $c = cliCommand($h);

    $c->command->settings([], []);
    $dump = $c->out;
    $c->out = '';
    $c->command->settings(['provider.api_key'], []);

    expect($c->errors)->toBe([])
        ->and($dump)->not->toContain('sk-secret')
        ->and(json_decode($dump, true))->toBe(array_replace(Schema::defaults(), ['models.default' => 'llama3.2', 'provider.api_key' => '***']))
        ->and($c->out)->toBe("\"sk-secret\"\n");
});

it('reads one setting as JSON', function (): void {
    $h = pipelineWith(null);
    $c = cliCommand($h);

    $c->command->settings(['models.keep_alive'], []);

    expect($c->errors)->toBe([])
        ->and($c->out)->toBe("\"5m\"\n");
});

it('writes a setting through the schema and echoes what was stored', function (): void {
    $h = pipelineWith(null);
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['provider.timeout'] === 30))->andReturn(true);
    $c = cliCommand($h);

    $c->command->settings(['provider.timeout', '30'], []);

    expect($c->errors)->toBe([])
        ->and($c->out)->toBe("30\n");
});

it('takes an array setting as JSON', function (): void {
    $h = pipelineWith(null);
    Functions\when('update_option')->justReturn(true);
    $c = cliCommand($h);

    $c->command->settings(['models.overrides', '{"llama3.2":{"temperature":"0.2"}}'], []);
    $c->command->settings(['models.overrides', 'not json'], []);

    expect($c->out)->toBe("{\"llama3.2\":{\"temperature\":0.2}}\n")
        ->and($c->errors)->toBe(['models.overrides takes a JSON object.']);
});

it('refuses a key the schema does not know', function (): void {
    $h = pipelineWith(null);
    Functions\expect('update_option')->never();
    $c = cliCommand($h);

    $c->command->settings(['nope', '1'], []);

    expect($c->out)->toBe('')
        ->and($c->errors)->toBe(['Unknown setting "nope". Run `wp alpaca-bot settings` to list them.']);
});
