<?php

declare(strict_types=1);

use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\ProviderKey;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Settings\Writer;
use Brain\Monkey\Functions;

// The one write sequence the REST route, `wp alpaca-bot settings` and a raw `wp option` write
// share: the MCP check, the provider key a move clears, then Store::replace(). Each writer only
// presents what it answers; what they present is pinned by their own tests.

/** @param array<string, mixed> $over */
function writerRow(array $over = []): array
{
    return $over + ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'trk', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []];
}

/**
 * get_option() over `$options`, update_option() recorded in `$log` as `update_option`.
 *
 * @param array<string, mixed> $options
 * @param list<string>         $log
 */
function writerOptions(array $options, array &$log): void
{
    Functions\when('get_option')->alias(static fn(string $name, mixed $default = false): mixed => $options[$name] ?? $default);
    Functions\when('update_option')->alias(static function (string $name) use (&$log): bool {
        $log[] = 'update_option:' . $name;
        return true;
    });
}

function writerPublic(): ServerSettings
{
    return new ServerSettings(static fn(string $host, string $url): array => ['93.184.216.34']);
}

it('writes a write that sends no MCP servers without running the MCP check, and names nothing cleared', function (): void {
    $log = [];
    writerOptions([], $log);
    $store = new Store([]);
    $written = (new Writer($store, new ServerSettings(static fn(string $host, string $url): array => throw new RuntimeException('no lookup was expected'))))
        ->write(['models.num_ctx' => 1024]);
    expect($written->refusal)->toBeNull()
        ->and($written->mcpCleared)->toBe([])
        ->and($written->keyCleared)->toBeFalse()
        ->and($log)->toBe(['update_option:' . Plugin::OPTION])
        ->and($store->get('models.num_ctx'))->toBe(1024);
});

it('answers the refusal and writes nothing, the hook before the store included, when the MCP check refuses', function (): void {
    $log = [];
    writerOptions([], $log);
    $store = new Store([]);
    $written = (new Writer($store, new ServerSettings(static fn(string $host, string $url): array => throw new AlpacaBot\Toolkit\AddressRefused("{$host} resolves to 10.0.0.7."))))
        ->write(['toolkits.mcp_servers' => [['url' => 'https://inside.example.com/mcp', 'prefix' => 'x']], 'models.num_ctx' => 1024], static function () use (&$log): void {
            $log[] = 'beforeStore';
        });
    expect($written->refusal?->code)->toBe('alpaca_bot_mcp_address')
        ->and($written->refusal?->message)->toContain('https://inside.example.com/mcp')
        ->and($written->mcpCleared)->toBe([])
        ->and($written->keyCleared)->toBeFalse()
        ->and($log)->toBe([])
        ->and($store->get('models.num_ctx'))->toBe(8192);
});

it('names the MCP servers and the provider key a move cleared, read off the settings held before the write', function (): void {
    $log = [];
    writerOptions([Secrets::OPTION => ['trk' => 'Bearer t'], ProviderKey::OPTION => 'sk-FAKE-held'], $log);
    $store = new Store(['provider.base_url' => 'https://a.example.com/v1', 'provider.api_key' => Schema::MASK, 'toolkits.mcp_servers' => [writerRow()]]);
    $written = (new Writer($store, writerPublic()))->write([
        'provider.base_url' => 'https://b.example.net/v1',
        'toolkits.mcp_servers' => [writerRow(['url' => 'https://other.example.com/mcp'])],
    ]);
    expect($written->refusal)->toBeNull()
        ->and($written->mcpCleared)->toBe(['trk'])
        ->and($written->keyCleared)->toBeTrue()
        ->and($store->get('provider.base_url'))->toBe('https://b.example.net/v1')
        ->and($store->get('provider.api_key'))->toBe('');
});

it('names no provider key when a move finds none held', function (): void {
    $log = [];
    writerOptions([], $log);
    $store = new Store(['provider.base_url' => 'https://a.example.com/v1', 'provider.api_key' => Schema::MASK]);
    expect((new Writer($store, writerPublic()))->write(['provider.base_url' => 'https://b.example.net/v1'])->keyCleared)->toBeFalse();
});

it('runs the hook after both checks and before the store is written', function (): void {
    $log = [];
    writerOptions([ProviderKey::OPTION => 'sk-FAKE-held'], $log);
    $store = new Store(['provider.base_url' => 'https://a.example.com/v1', 'provider.api_key' => Schema::MASK]);
    $seen = null;
    $written = (new Writer($store, writerPublic()))->write(['provider.base_url' => 'https://b.example.net/v1'], static function () use (&$log, &$seen, $store): void {
        $log[] = 'beforeStore';
        $seen = $store->get('provider.base_url');
    });
    expect($log)->toBe(['beforeStore', 'update_option:' . Plugin::OPTION])
        ->and($seen)->toBe('https://a.example.com/v1')
        ->and($written->keyCleared)->toBeTrue();
});
