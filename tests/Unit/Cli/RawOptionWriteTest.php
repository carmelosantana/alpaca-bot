<?php

declare(strict_types=1);

use AlpacaBot\Cli\RawOptionWrite;
use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

// Kanboard #4695 and #4696: a raw `wp option update|patch|add alpaca_bot_settings` behaves like
// `wp alpaca-bot settings`. RawOptionWrite is armed for those three commands and takes the write
// over from the option's sanitize_option filter: the schema over the stored settings, the MCP
// row checks with REST's message, the cleared warnings, and a success line once the write is
// done, whatever update_option() answered.

/**
 * A RawOptionWrite over `$stored` (the settings row as the Store and get_option() see it) and
 * `$secrets` (Mcp\Secrets' option), armed for `$command`. What it reports lands in the returned
 * object instead of going through WP_CLI; `halted` is the exit code halt() was handed.
 *
 * @param array<string, mixed>  $stored
 * @param array<string, string> $secrets
 */
function rawWrite(array $stored, string $command = 'update', ?ServerSettings $servers = null, array $secrets = [], mixed $row = null): object
{
    $c = new class {
        public RawOptionWrite $subject;
        public Store $store;
        /** @var list<string> */
        public array $success = [];
        /** @var list<string> */
        public array $warnings = [];
        /** @var list<string> */
        public array $errors = [];
        public ?int $halted = null;
    };
    $row ??= array_merge(Schema::defaults(), $stored);
    Functions\when('get_option')->alias(static fn(string $name, mixed $default = false): mixed => match ($name) {
        Plugin::OPTION => $row,
        Secrets::OPTION => $secrets,
        default => $default,
    });
    $c->store = new Store($stored);
    $c->subject = new RawOptionWrite(
        $c->store,
        $servers ?? new ServerSettings(static fn(string $host, string $url): array => ['93.184.216.34']),
        success: static function (string $m) use ($c): void {
            $c->success[] = $m;
        },
        warn: static function (string $m) use ($c): void {
            $c->warnings[] = $m;
        },
        fail: static function (string $m) use ($c): void {
            $c->errors[] = $m;
        },
        halt: static function (int $code) use ($c): void {
            $c->halted = $code;
        },
    );
    $c->subject->arm($command);
    return $c;
}

/** @return list<array<string, mixed>> */
function rawMcpStored(): array
{
    return [
        ['id' => 'aa', 'url' => 'https://aa.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'aa', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []],
    ];
}

it('arms itself for option update, patch and add only, on the option\'s sanitize filter', function (): void {
    $c = rawWrite([]);

    expect(RawOptionWrite::COMMANDS)->toBe(['option update' => 'update', 'option patch' => 'patch', 'option add' => 'add'])
        ->and(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBe(10);
});

it('hands the stored row back untouched, which is WP-CLI sanitizing it to compare', function (): void {
    Functions\expect('update_option')->never();
    $c = rawWrite(['models.temperature' => 0.3]);
    $row = get_option(Plugin::OPTION);

    expect($c->subject->sanitize($row))->toBe($row)
        ->and($c->success)->toBe([])
        ->and($c->halted)->toBeNull();
});

it('writes through the schema over the stored settings, says so, and ends the command', function (): void {
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['models.temperature'] === 1.5 && $v['models.num_ctx'] === 4096 && !array_key_exists('nope', $v)))->andReturn(true);
    $c = rawWrite(['models.num_ctx' => 4096]);

    $c->subject->sanitize(['models.temperature' => '1.5', 'nope' => 'x']);

    expect($c->errors)->toBe([])
        ->and($c->warnings)->toBe([])
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."])
        ->and($c->halted)->toBe(0)
        ->and($c->store->get('models.temperature'))->toBe(1.5);
});

// #4696: ProviderKey lifts the key out of the row, the row then equals the stored one, and core's
// update_option() answers false. The write happened; the command says so and exits 0.
it('reports success for a write that changed only a secret, although update_option() answered false', function (): void {
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['provider.api_key'] === 'sk-FAKE-new'))->andReturn(false);
    $c = rawWrite(['provider.api_key' => Schema::MASK]);

    $c->subject->sanitize(['provider.api_key' => 'sk-FAKE-new'] + get_option(Plugin::OPTION));

    expect($c->errors)->toBe([])
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."])
        ->and($c->halted)->toBe(0);
});

// #4539 over a raw write: the stored row carries MASK for the kept key, so a patch of the base URL
// hands the whole row back with MASK in it, which reads as "keep", and keep does not survive a move.
it('clears the provider key when the write moves the base URL, and warns as wp alpaca-bot settings does', function (): void {
    $stored = ['provider.base_url' => 'https://openrouter.ai/api/v1', 'provider.api_key' => Schema::MASK];
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['provider.api_key'] === '' && $v['provider.base_url'] === 'https://steal.example.net/v1'))->andReturn(true);
    $c = rawWrite($stored, 'patch');

    $c->subject->sanitize(['provider.base_url' => 'https://steal.example.net/v1'] + get_option(Plugin::OPTION));

    expect($c->errors)->toBe([])
        ->and($c->warnings)->toBe(['provider.api_key was cleared, because provider.base_url moved to another host, port or scheme. Set it again: wp alpaca-bot settings provider.api_key <key>'])
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."])
        ->and($c->halted)->toBe(0);
});

it('warns, naming the server, when a URL move clears an MCP header value', function (): void {
    Functions\expect('update_option')->once()->andReturn(true);
    $c = rawWrite(['toolkits.mcp_servers' => rawMcpStored()], secrets: ['aa' => 'Bearer FAKE-a']);
    $rows = rawMcpStored();
    $rows[0]['url'] = 'https://elsewhere.example.net/mcp';

    $c->subject->sanitize(['toolkits.mcp_servers' => $rows] + get_option(Plugin::OPTION));

    expect($c->errors)->toBe([])
        ->and($c->warnings)->toBe(['The header value of MCP server aa was cleared, because its address moved to another host or port. Send it again.'])
        ->and($c->halted)->toBe(0);
});

it('refuses an MCP row the schema cannot keep with the message REST gives, and writes nothing', function (): void {
    Functions\expect('update_option')->never();
    $noLookup = static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected');
    $rows = [['url' => 'http://insecure.example.com/mcp', 'prefix' => 'bad']];
    $rest = (new AlpacaBot\Rest\SettingsController(new Store([]), new ServerSettings($noLookup)))
        ->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => $rows]));
    $c = rawWrite([], 'patch', new ServerSettings($noLookup));

    $c->subject->sanitize(['toolkits.mcp_servers' => $rows] + get_option(Plugin::OPTION));

    expect($rest->get_error_code())->toBe('alpaca_bot_mcp_row')
        ->and($c->errors)->toBe([$rest->get_error_message()])
        ->and($c->errors[0])->toContain('http://insecure.example.com/mcp: ')
        ->and($c->success)->toBe([])
        ->and($c->halted)->toBeNull();
});

it('refuses an MCP address the egress check refuses with the message REST gives, and writes nothing', function (): void {
    Functions\expect('update_option')->never();
    $refuse = static fn(string $host, string $url): array => throw new AlpacaBot\Toolkit\AddressRefused("{$host} resolves to 127.0.0.1, a private, local or other special-purpose address.");
    $rows = [['url' => 'https://internal.example.com/mcp', 'prefix' => 'in', 'header_value' => 'Bearer do-not-echo']];
    $rest = (new AlpacaBot\Rest\SettingsController(new Store([]), new ServerSettings($refuse)))
        ->update(restRequest('PUT', '/settings', ['toolkits.mcp_servers' => $rows]));
    $c = rawWrite([], 'update', new ServerSettings($refuse));

    $c->subject->sanitize(['toolkits.mcp_servers' => $rows]);

    expect($rest->get_error_code())->toBe('alpaca_bot_mcp_address')
        ->and($c->errors)->toBe([$rest->get_error_message()])
        ->and($c->errors[0])->not->toContain('do-not-echo')
        ->and($c->halted)->toBeNull();
});

it('refuses a value that is not a settings object, and writes nothing', function (): void {
    Functions\expect('update_option')->never();
    $c = rawWrite([]);

    $c->subject->sanitize('not an object');

    expect($c->errors)->toBe(["alpaca_bot_settings takes a JSON object of settings: pass it with --format=json."])
        ->and($c->halted)->toBeNull();
});

it('leaves option add alone when the row exists, so add_option() refuses as it always has', function (): void {
    Functions\expect('update_option')->never();
    $c = rawWrite([], 'add');

    expect($c->subject->sanitize(['models.temperature' => 0.2]))->toBe(['models.temperature' => 0.2])
        ->and($c->halted)->toBeNull();
});

it('takes over option add when there is no row yet, and says it added it', function (): void {
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['models.temperature'] === 0.2))->andReturn(true);
    $c = rawWrite([], 'add', row: false);

    $c->subject->sanitize(['models.temperature' => '0.2']);

    expect($c->success)->toBe(["Added 'alpaca_bot_settings' option."])
        ->and($c->halted)->toBe(0);
});

// Store::replace() calls update_option(), which runs sanitize_option() again on its way in: that
// pass is the plugin's own write, not a second raw one, and goes through as it is.
it('lets its own write pass the sanitize filter untouched', function (): void {
    $c = null;
    $inner = null;
    $calls = 0;
    Functions\expect('update_option')->once()->andReturnUsing(static function (string $name, array $value) use (&$c, &$inner, &$calls): bool {
        if (++$calls > 1) {
            throw new RuntimeException('its own write was taken over as a second raw write');
        }
        $inner = $c->subject->sanitize($value);
        return true;
    });
    $c = rawWrite([]);

    $c->subject->sanitize(['models.temperature' => 0.4]);

    expect($inner['models.temperature'])->toBe(0.4)
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."]);
});
