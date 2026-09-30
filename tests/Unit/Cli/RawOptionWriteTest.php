<?php

declare(strict_types=1);

use AlpacaBot\Cli\RawOptionWrite;
use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\ProviderKey;
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
 * `$secrets` (Mcp\Secrets' option) and `$held` (Settings\ProviderKey's option, false for none), armed for `$command`. What it reports lands in the returned
 * object instead of going through WP_CLI; `halted` is the exit code halt() was handed. Its call-stack
 * check answers as WP-CLI's Option_Command would for `wp option <command> <$name> … <$assoc>`
 * (patch's option name comes after its action), or null when `$inCommand` is false.
 *
 * @param array<string, mixed>  $stored
 * @param array<string, string> $secrets
 * @param array<string, mixed>  $assoc
 */
function rawWrite(array $stored, string $command = 'update', ?ServerSettings $servers = null, array $secrets = [], mixed $row = null, bool $inCommand = true, string|false $held = false, array $assoc = [], string $name = Plugin::OPTION): object
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
        ProviderKey::OPTION => $held === false ? $default : $held,
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
        inCommand: static fn(string $mode): ?array => $inCommand ? [$mode === 'patch' ? ['update', $name] : [$name], $assoc] : null,
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
// The warning names the key only while one is held (#4699): with none, the move clears nothing.
it('clears the provider key when the write moves the base URL, and warns as wp alpaca-bot settings does', function (): void {
    $stored = ['provider.base_url' => 'https://openrouter.ai/api/v1', 'provider.api_key' => Schema::MASK];
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['provider.api_key'] === '' && $v['provider.base_url'] === 'https://steal.example.net/v1'))->andReturn(true);
    $c = rawWrite($stored, 'patch', held: 'sk-FAKE-held');

    $c->subject->sanitize(['provider.base_url' => 'https://steal.example.net/v1'] + get_option(Plugin::OPTION));

    expect($c->errors)->toBe([])
        ->and($c->warnings)->toBe(['provider.api_key was cleared, because provider.base_url moved to another host, port or scheme. Set it again: wp alpaca-bot settings provider.api_key <key>'])
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."])
        ->and($c->halted)->toBe(0);
});

it('names no cleared provider key when the row carries MASK but no key is held', function (): void {
    $stored = ['provider.base_url' => 'https://openrouter.ai/api/v1', 'provider.api_key' => Schema::MASK];
    Functions\expect('update_option')->once()->andReturn(true);
    $c = rawWrite($stored, 'patch');

    $c->subject->sanitize(['provider.base_url' => 'https://steal.example.net/v1'] + get_option(Plugin::OPTION));

    expect($c->errors)->toBe([])
        ->and($c->warnings)->toBe([])
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."]);
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
        ->and($c->halted)->toBeNull()
        ->and(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
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

// Store::replace() calls update_option(), which runs sanitize_option() again on its way in. The
// takeover disarms before it writes, so that pass finds it disarmed and its own write passes
// untouched; armed, the pass would be taken over as a second raw write and update_option() called
// twice.
it('is disarmed before its own write, so its own write passes untouched', function (): void {
    $c = null;
    $inner = null;
    $armedAtWrite = null;
    $calls = 0;
    Functions\expect('update_option')->once()->andReturnUsing(static function (string $name, array $value) use (&$c, &$inner, &$armedAtWrite, &$calls): bool {
        if (++$calls > 1) {
            throw new RuntimeException('its own write was taken over as a second raw write');
        }
        $armedAtWrite = has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']);
        $inner = $c->subject->sanitize($value);
        return true;
    });
    $c = rawWrite([]);

    $c->subject->sanitize(['models.temperature' => 0.4]);

    expect($armedAtWrite)->toBeFalse()
        ->and($inner['models.temperature'])->toBe(0.4)
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."]);
});

// Review round 1, item 1: `wp option patch delete alpaca_bot_settings <key>` hands over the row
// without that key. Store keeps a key left out, so the patch mode reads a stored key the value no
// longer carries as a reset to its default, which is what the delete did before (the key read back
// as the default).
it('resets a key a patch deleted to its default', function (): void {
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['models.temperature'] === 0.7 && $v['models.num_ctx'] === 4096))->andReturn(true);
    $c = rawWrite(['models.temperature' => 0.3, 'models.num_ctx' => 4096], 'patch');
    $row = get_option(Plugin::OPTION);
    unset($row['models.temperature']);

    $c->subject->sanitize($row);

    expect($c->store->get('models.temperature'))->toBe(0.7)
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."]);
});

it('keeps a key an update leaves out, as Store and REST do', function (): void {
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['models.temperature'] === 0.3 && $v['models.num_ctx'] === 2048))->andReturn(true);
    $c = rawWrite(['models.temperature' => 0.3], 'update');

    $c->subject->sanitize(['models.num_ctx' => 2048]);

    expect($c->halted)->toBe(0);
});

// Review round 1, item 3: update and patch hand over the whole row. On a row the migration has not
// lifted, its plaintext key would read as a new key and survive a base URL move; a key whose value
// is the stored row's is left out instead, as Store::set() leaves it, so it reads as "keep", and
// keep does not survive a move.
it('clears a plaintext key an unmigrated row still carries when a raw patch moves the base URL', function (): void {
    $stored = ['provider.base_url' => 'https://openrouter.ai/api/v1', 'provider.api_key' => 'sk-FAKE-plain'];
    Functions\expect('update_option')->once()->with(Plugin::OPTION, Mockery::on(static fn(array $v): bool => $v['provider.api_key'] === '' && $v['provider.base_url'] === 'https://steal.example.net/v1'))->andReturn(true);
    $c = rawWrite($stored, 'patch');

    $c->subject->sanitize(['provider.base_url' => 'https://steal.example.net/v1'] + get_option(Plugin::OPTION));

    expect($c->warnings)->toBe(['provider.api_key was cleared, because provider.base_url moved to another host, port or scheme. Set it again: wp alpaca-bot settings provider.api_key <key>']);
});

// Review round 1, item 2: armed for one command, and for no longer than that command.
it('disarms once it has taken a write over', function (): void {
    Functions\when('update_option')->justReturn(true);
    $c = rawWrite([]);

    $c->subject->sanitize(['models.temperature' => 0.4]);

    expect(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
});

it('disarms once it has refused a write', function (): void {
    Functions\expect('update_option')->never();
    $c = rawWrite([]);

    $c->subject->sanitize('not an object');

    expect($c->errors)->toHaveCount(1)
        ->and(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
});

it('disarms when it leaves an add over an existing row to add_option()', function (): void {
    $c = rawWrite([], 'add');

    $c->subject->sanitize(['models.temperature' => 0.2]);

    expect(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
});

it('stays armed over the comparison pass, which patch makes before the write, until disarm()', function (): void {
    $c = rawWrite([], 'patch');

    $c->subject->sanitize(get_option(Plugin::OPTION));
    $armed = has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']);
    $c->subject->disarm();

    expect($armed)->toBe(10)
        ->and(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
});

it('takes nothing over outside the option command that armed it, and disarms', function (): void {
    Functions\expect('update_option')->never();
    $c = rawWrite([], inCommand: false);

    expect($c->subject->sanitize(['models.temperature' => 0.4]))->toBe(['models.temperature' => 0.4])
        ->and($c->halted)->toBeNull()
        ->and(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
});

// 0.6.2 wave 3: armed for every `wp option update|patch|add`, whatever its words, so an option name
// read from --prompt or wp-cli.yml cannot slip past. The takeover reads the name from WP-CLI's own
// call, and a write of this option while that call writes another one is not a raw write of it.
it('takes nothing over while WP-CLI\'s option command writes another option, and disarms', function (string $mode): void {
    Functions\expect('update_option')->never();
    $c = rawWrite([], $mode, row: $mode === 'add' ? false : null, name: 'blogname');

    expect($c->subject->sanitize(['models.temperature' => 0.4]))->toBe(['models.temperature' => 0.4])
        ->and($c->success)->toBe([])
        ->and($c->halted)->toBeNull()
        ->and(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
})->with(['update', 'patch', 'add']);

it('refuses --autoload set to anything but on, naming it as it was written, writes nothing, and disarms', function (mixed $autoload, string $message): void {
    Functions\expect('update_option')->never();
    $c = rawWrite([], assoc: ['autoload' => $autoload]);

    $c->subject->sanitize(['models.temperature' => 0.4]);

    expect($c->errors)->toBe([$message])
        ->and($c->success)->toBe([])
        ->and($c->halted)->toBeNull()
        ->and(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
})->with([
    'off' => ['off', 'alpaca_bot_settings stays autoloaded, so --autoload=off is refused and nothing was written. Leave --autoload out, or pass --autoload=on.'],
    'no' => ['no', 'alpaca_bot_settings stays autoloaded, so --autoload=no is refused and nothing was written. Leave --autoload out, or pass --autoload=on.'],
    'something else' => ['auto', 'alpaca_bot_settings stays autoloaded, so --autoload=auto is refused and nothing was written. Leave --autoload out, or pass --autoload=on.'],
    // WP-CLI refuses --autoload=false and --no-autoload itself; a bool reaches here only from
    // wp-cli.yml, whose YAML reads an unquoted off, no or false as false.
    'autoload: off in wp-cli.yml' => [false, 'alpaca_bot_settings stays autoloaded, so autoload: off in wp-cli.yml (off, no or false there) is refused and nothing was written. Take autoload out of wp-cli.yml, or set it to on.'],
    'a list in wp-cli.yml' => [['off'], 'alpaca_bot_settings stays autoloaded, so autoload: array in wp-cli.yml is refused and nothing was written. Take autoload out of wp-cli.yml, or set it to on.'],
]);

// Refused before the stored row passes untouched: that pass would leave WP-CLI to call
// update_option() with the autoload value, which moves the row off autoload.
it('refuses --autoload=off on a write of exactly what is stored', function (): void {
    $c = rawWrite([], assoc: ['autoload' => 'off']);

    $c->subject->sanitize(get_option(Plugin::OPTION));

    expect($c->errors)->toHaveCount(1)
        ->and($c->halted)->toBeNull();
});

it('refuses --autoload=off on an add over no row', function (): void {
    Functions\expect('update_option')->never();
    $c = rawWrite([], 'add', row: false, assoc: ['autoload' => 'no']);

    $c->subject->sanitize(['models.temperature' => 0.2]);

    expect($c->errors)->toHaveCount(1)
        ->and($c->halted)->toBeNull();
});

it('takes --autoload=on, yes or true as the no-op it is, and writes as without it', function (mixed $autoload): void {
    Functions\expect('update_option')->once()->withArgs(static fn(mixed ...$a): bool => count($a) === 2 && $a[1]['models.temperature'] === 0.4)->andReturn(true);
    $c = rawWrite([], assoc: ['autoload' => $autoload]);

    $c->subject->sanitize(['models.temperature' => 0.4]);

    expect($c->errors)->toBe([])
        ->and($c->success)->toBe(["Updated 'alpaca_bot_settings' option."])
        ->and($c->halted)->toBe(0);
})->with(['on' => 'on', 'yes' => 'yes', 'true' => 'true', 'bare --autoload' => true]);

// WP-CLI hands a write of what is stored with --autoload to update_option() instead of reporting it
// unchanged; the row stays autoloaded, so there is nothing to write, and it says so as WP-CLI does.
it('reports a write of what is stored with --autoload=on as unchanged, and writes nothing', function (): void {
    Functions\expect('update_option')->never();
    $c = rawWrite([], assoc: ['autoload' => 'on']);

    $c->subject->sanitize(get_option(Plugin::OPTION));

    expect($c->success)->toBe(["Value passed for 'alpaca_bot_settings' option is unchanged."])
        ->and($c->halted)->toBe(0)
        ->and(has_filter('sanitize_option_' . Plugin::OPTION, [$c->subject, 'sanitize']))->toBeFalse();
});

it('reports a write the schema cleans to what is stored as unchanged, as WP-CLI would', function (string $mode): void {
    Functions\expect('update_option')->once()->andReturn(false);
    $c = rawWrite(['models.temperature' => 0.3], $mode);

    $c->subject->sanitize(['models.temperature' => '0.30'] + get_option(Plugin::OPTION));

    expect($c->errors)->toBe([])
        ->and($c->success)->toBe(["Value passed for 'alpaca_bot_settings' option is unchanged."])
        ->and($c->halted)->toBe(0);
})->with(['update', 'patch']);
