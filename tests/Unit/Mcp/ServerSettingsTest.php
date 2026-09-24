<?php

declare(strict_types=1);

use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Toolkit\AddressPin;
use AlpacaBot\Toolkit\AddressRefused;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// The split runs on pre_update_option_alpaca_bot_settings, which core applies to every writer of
// the option before the add_option() branch a site's first save takes, so the settings page, the
// REST route, WP-CLI and a filter all land here. What core does with it is asserted against a
// real WordPress in tests/Integration/McpSettingsTest.php.

/** @param array<string, mixed> $over */
function mcpRow(array $over = []): array
{
    return $over + ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'trk', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []];
}

/** get_option(), update_option() and delete_option() over `$stored`, with the autoload each write was handed kept as `__autoload__<name>`. */
function mcpSecretsIn(array &$stored): void
{
    Functions\when('get_option')->alias(static function (string $name, mixed $default = false) use (&$stored): mixed {
        return $stored[$name] ?? $default;
    });
    Functions\when('update_option')->alias(static function (string $name, mixed $value, mixed $autoload = null) use (&$stored): bool {
        $stored[$name] = $value;
        $stored['__autoload__' . $name] = $autoload;
        $stored['__writes__'] = ($stored['__writes__'] ?? 0) + 1;
        return true;
    });
    Functions\when('delete_option')->alias(static function (string $name) use (&$stored): bool {
        unset($stored[$name]);
        $stored['__writes__'] = ($stored['__writes__'] ?? 0) + 1;
        return true;
    });
}

it('hooks the split onto every write of the settings option, with the old value', function (): void {
    $settings = new ServerSettings();
    Filters\expectAdded('pre_update_option_' . Plugin::OPTION)->once()->with([$settings, 'beforeSave'], 10, 2);
    $settings->register();
});

it('moves a new header value into its own option, never autoloaded, and leaves the mask in the row', function (): void {
    $stored = [];
    mcpSecretsIn($stored);
    $value = (new ServerSettings())->beforeSave(['toolkits.mcp_servers' => [mcpRow(['header_value' => 'Bearer t'])]], ['toolkits.mcp_servers' => [mcpRow()]]);
    expect($value['toolkits.mcp_servers'][0]['header_value'])->toBe(Schema::MASK)
        ->and($stored[Secrets::OPTION])->toBe(['trk' => 'Bearer t'])
        ->and($stored['__autoload__' . Secrets::OPTION])->toBeFalse()
        ->and(serialize($value))->not->toContain('Bearer t');
});

it('keeps the stored value for a masked write, clears it for an empty one, and forgets a server that was removed', function (): void {
    $stored = [Secrets::OPTION => ['trk' => 'Bearer t', 'gh' => 'Bearer g']];
    mcpSecretsIn($stored);
    $settings = new ServerSettings();
    $old = ['toolkits.mcp_servers' => [mcpRow(), mcpRow(['id' => 'gh', 'prefix' => 'gh'])]];
    $kept = $settings->beforeSave(['toolkits.mcp_servers' => [mcpRow()]], $old);
    expect($stored[Secrets::OPTION])->toBe(['trk' => 'Bearer t'])
        ->and($kept['toolkits.mcp_servers'][0]['header_value'])->toBe(Schema::MASK);
    $cleared = $settings->beforeSave(['toolkits.mcp_servers' => [mcpRow(['header_value' => ''])]], ['toolkits.mcp_servers' => [mcpRow()]]);
    expect($stored)->not->toHaveKey(Secrets::OPTION)
        ->and($cleared['toolkits.mcp_servers'][0]['header_value'])->toBe('');
});

// The row carries MASK exactly when a value is kept, so the page, the REST read and the carry-over
// never claim a header value that is not there.
it('writes the mask into a row only when a value is kept for it', function (): void {
    $stored = [];
    mcpSecretsIn($stored);
    $value = (new ServerSettings())->beforeSave(['toolkits.mcp_servers' => [mcpRow()]], ['toolkits.mcp_servers' => [mcpRow()]]);
    expect($value['toolkits.mcp_servers'][0]['header_value'])->toBe('')
        ->and($stored)->not->toHaveKey(Secrets::OPTION);
});

// "New" is an id the stored list does not have. A mask posted for one has nothing of its own to
// keep, so it keeps nothing, even where a value is held under that id: a server removed in this
// same write, or a value left over from a write this filter never saw.
it('never hands a new server another server\'s value for a posted mask', function (): void {
    $stored = [Secrets::OPTION => ['trk' => 'Bearer t', 'stale' => 'Bearer s']];
    mcpSecretsIn($stored);
    $value = (new ServerSettings())->beforeSave(
        ['toolkits.mcp_servers' => [mcpRow(['url' => 'https://evil.example.com/mcp']), mcpRow(['id' => 'stale', 'prefix' => 'stale'])]],
        ['toolkits.mcp_servers' => [mcpRow(['id' => 'old', 'prefix' => 'old'])]],
    );
    expect(array_column($value['toolkits.mcp_servers'], 'header_value'))->toBe(['', ''])
        ->and($stored)->not->toHaveKey(Secrets::OPTION);
});

it('keeps a server\'s value by its id when the row is renamed, re-prefixed or moved', function (): void {
    $stored = [Secrets::OPTION => ['trk' => 'Bearer t', 'gh' => 'Bearer g']];
    mcpSecretsIn($stored);
    $old = ['toolkits.mcp_servers' => [mcpRow(), mcpRow(['id' => 'gh', 'prefix' => 'gh', 'url' => 'https://gh.example.com/mcp'])]];
    (new ServerSettings())->beforeSave(['toolkits.mcp_servers' => [
        mcpRow(['id' => 'gh', 'prefix' => 'github', 'url' => 'https://gh.example.com/v2/mcp']),
        mcpRow(['prefix' => 'tracker']),
        mcpRow(['id' => 'new', 'prefix' => 'new', 'header_value' => 'Bearer n']),
    ]], $old);
    expect($stored[Secrets::OPTION])->toBe(['gh' => 'Bearer g', 'trk' => 'Bearer t', 'new' => 'Bearer n']);
});

it('writes the secrets option only when what it holds changes', function (): void {
    $stored = [Secrets::OPTION => ['trk' => 'Bearer t']];
    mcpSecretsIn($stored);
    (new ServerSettings())->beforeSave(['toolkits.mcp_servers' => [mcpRow()], 'chat.welcome' => 'hi'], ['toolkits.mcp_servers' => [mcpRow()]]);
    expect($stored['__writes__'] ?? 0)->toBe(0);
});

// A writer that goes round Schema::sanitize() (update_option() from code, `wp option update`)
// can hand this a row with no id. Its value has no key to be kept under, so it is dropped rather
// than left in the autoloaded row.
it('drops a header value it has no id to keep it under, rather than leave it in the row', function (): void {
    $stored = [];
    mcpSecretsIn($stored);
    $value = (new ServerSettings())->beforeSave(['toolkits.mcp_servers' => [['url' => 'https://a.example.com/mcp', 'header_value' => 'Bearer raw'], 'junk']], []);
    expect($value['toolkits.mcp_servers'][0]['header_value'])->toBe('')
        ->and($value['toolkits.mcp_servers'][1])->toBe('junk')
        ->and(serialize($value))->not->toContain('Bearer raw');
});

it('passes a value that is not the settings array through untouched', function (): void {
    expect((new ServerSettings())->beforeSave('nope', []))->toBe('nope');
});

it('drops an access entry whose server is gone, so a later server cannot inherit a looser capability', function (): void {
    $stored = [];
    mcpSecretsIn($stored);
    $value = (new ServerSettings())->beforeSave(
        ['toolkits.mcp_servers' => [mcpRow()], 'access.mcp' => ['trk' => 'edit_posts', 'gone' => 'read']],
        ['toolkits.mcp_servers' => [mcpRow(), mcpRow(['id' => 'gone', 'prefix' => 'gone'])], 'access.mcp' => ['trk' => 'edit_posts', 'gone' => 'read']],
    );
    expect($value['access.mcp'])->toBe(['trk' => 'edit_posts']);
});

// The drop above only closes the gap between two writes. In one write that removes a server and
// adds another under the same id, the old entry is still in the map (a Tools-tab save posts no
// access.mcp, so the stored map is kept) and the id is listed again. An entry for a new id that
// is the one stored is carried, not chosen, and goes; one the write chose for it stays.
it('does not let a new server inherit the entry of a server removed in the same write', function (): void {
    $stored = [];
    mcpSecretsIn($stored);
    $old = ['toolkits.mcp_servers' => [mcpRow()], 'access.mcp' => ['trk' => 'read']];
    $settings = new ServerSettings();
    $reused = $settings->beforeSave(['toolkits.mcp_servers' => [mcpRow(['url' => 'https://other.example.com/mcp'])], 'access.mcp' => ['trk' => 'read']], ['toolkits.mcp_servers' => [mcpRow(['id' => 'was'])], 'access.mcp' => ['trk' => 'read']]);
    $chosen = $settings->beforeSave(['toolkits.mcp_servers' => [mcpRow(), mcpRow(['id' => 'new', 'prefix' => 'new'])], 'access.mcp' => ['trk' => 'read', 'new' => 'edit_posts']], $old);
    expect($reused['access.mcp'])->toBe([])
        ->and($chosen['access.mcp'])->toBe(['trk' => 'read', 'new' => 'edit_posts']);
});

// The address check at save time is a courtesy, not the guard: Mcp\Egress checks again when it
// builds a client. It runs only for a URL that is new or changed, so saving another tab costs no
// lookup.
it('resolves only a new or changed url, and reports the refusal by row index', function (): void {
    $asked = [];
    $settings = new ServerSettings(static function (string $host, string $url) use (&$asked): array {
        $asked[] = [$host, $url];
        return $host === 'internal.example.com' ? throw new AddressRefused('That address is not public.') : ['93.184.216.34'];
    });
    $stored = [mcpRow(), mcpRow(['id' => 'gh', 'prefix' => 'gh', 'url' => 'https://gh.example.com/mcp'])];
    $refused = $settings->refusals([
        mcpRow(),
        mcpRow(['id' => 'gh', 'prefix' => 'gh', 'url' => 'https://internal.example.com/mcp']),
        mcpRow(['id' => 'new', 'prefix' => 'new', 'url' => 'https://new.example.com/mcp']),
    ], $stored);
    expect($asked)->toBe([['internal.example.com', 'https://internal.example.com/mcp'], ['new.example.com', 'https://new.example.com/mcp']])
        ->and($refused)->toBe([1 => 'That address is not public.']);
});

// The same rule web_fetch and Egress use (AddressPin), with only the DNS lookup stood in for.
it('refuses a private literal, localhost, an IPv6 literal and a name that resolves private', function (): void {
    $lookup = static fn(string $host): array => match ($host) {
        'localhost' => ['127.0.0.1'],
        'rebind.example.com' => ['93.184.216.34', '10.0.0.7'],
        default => ['93.184.216.34'],
    };
    $settings = new ServerSettings(static fn(string $host, string $url): array => AddressPin::resolve($host, $url, $lookup));
    $rows = Schema::sanitizeMcpServers([
        ['url' => 'https://10.1.2.3/mcp', 'prefix' => 'a'],
        ['url' => 'https://localhost/mcp', 'prefix' => 'b'],
        ['url' => 'https://[::1]/mcp', 'prefix' => 'c'],
        ['url' => 'https://rebind.example.com/mcp', 'prefix' => 'd'],
        ['url' => 'https://[fd00::1]:8443/mcp', 'prefix' => 'e'],
        ['url' => 'https://public.example.com/mcp', 'prefix' => 'f'],
        ['url' => 'https://93.184.216.34/mcp', 'prefix' => 'g'],
        // A documentation range, not a public address: a test that means "public" cannot use it.
        ['url' => 'https://203.0.113.9/mcp', 'prefix' => 'h'],
    ]);
    expect($rows)->toHaveCount(8);
    $refused = $settings->refusals($rows, []);
    expect(array_keys($refused))->toBe([0, 1, 2, 3, 4, 7])
        ->and($refused[1])->toContain('localhost')->toContain('127.0.0.1')
        ->and($refused[3])->toContain('10.0.0.7');
});

it('uses AddressPin when no resolver is handed in', function (): void {
    expect((new ServerSettings())->refusals([mcpRow(['url' => 'https://127.0.0.1/mcp'])], []))->toHaveKey(0);
});
