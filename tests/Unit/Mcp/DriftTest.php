<?php

declare(strict_types=1);

use AlpacaBot\Mcp\Drift;
use Brain\Monkey\Functions;

// Drift is the marker the settings screen reads to say "changed since approval: review" without
// asking the server while the page renders: a transient per server, a week long.

/** @param array<string, mixed> $transients the store the three transient functions share, by key */
function mcpDriftTransients(array &$transients): void
{
    Functions\when('get_transient')->alias(static function (string $key) use (&$transients): mixed {
        return $transients[$key] ?? false;
    });
    Functions\when('set_transient')->alias(static function (string $key, mixed $value, int $ttl) use (&$transients): bool {
        $transients[$key] = $value;
        $transients['ttl:' . $key] = $ttl;
        return true;
    });
    Functions\when('delete_transient')->alias(static function (string $key) use (&$transients): bool {
        unset($transients[$key]);
        return true;
    });
}

it('keeps the names under a transient per server for a week, and an empty list deletes it', function (): void {
    $t = [];
    mcpDriftTransients($t);
    Drift::set('trk', ['report', 'write']);
    expect($t['alpaca_bot_mcp_drift_trk'])->toBe(['report', 'write'])
        ->and($t['ttl:alpaca_bot_mcp_drift_trk'])->toBe(604800)
        ->and(Drift::get('trk'))->toBe(['report', 'write'])
        ->and(Drift::get('gh'))->toBe([]);
    Drift::set('trk', []);
    expect($t)->not->toHaveKey('alpaca_bot_mcp_drift_trk')
        ->and(Drift::get('trk'))->toBe([]);
});

it('reads only the strings of a marker something else wrote', function (): void {
    $t = ['alpaca_bot_mcp_drift_trk' => ['a' => 'report', 3, null, 'write'], 'alpaca_bot_mcp_drift_gh' => 'report'];
    mcpDriftTransients($t);
    expect(Drift::get('trk'))->toBe(['report', 'write'])
        ->and(Drift::get('gh'))->toBe([]);
});

// A save that re-pins a drifted tool (ticked again, so the form posted its new fingerprint) or
// drops its approval has answered the marker for that tool; a save that leaves the pin as it was
// has not. A server the save removes takes its marker with it.
it('forgets a drifted tool once a save changes or drops its pin, and a removed server\'s marker', function (): void {
    $t = ['alpaca_bot_mcp_drift_trk' => ['report', 'write', 'search'], 'alpaca_bot_mcp_drift_gh' => ['issues']];
    mcpDriftTransients($t);
    $a = str_repeat('a', 64);
    $b = str_repeat('b', 64);
    $old = ['toolkits.mcp_servers' => [
        ['id' => 'trk', 'approved' => ['report' => $a, 'write' => $a, 'search' => $a]],
        ['id' => 'gh', 'approved' => ['issues' => $a]],
    ]];
    $new = ['toolkits.mcp_servers' => [
        // report re-pinned, write unticked, search untouched.
        ['id' => 'trk', 'approved' => ['report' => $b, 'search' => $a]],
    ]];
    Drift::afterSave($old, $new);
    expect($t['alpaca_bot_mcp_drift_trk'])->toBe(['search'])
        ->and($t)->not->toHaveKey('alpaca_bot_mcp_drift_gh');
});

it('writes nothing when a save leaves every drifted pin as it was', function (): void {
    $t = ['alpaca_bot_mcp_drift_trk' => ['report']];
    mcpDriftTransients($t);
    $rows = ['toolkits.mcp_servers' => [['id' => 'trk', 'approved' => ['report' => str_repeat('a', 64)]]]];
    Drift::afterSave($rows, $rows + ['chat.welcome' => 'Hi']);
    // An old value that is not the settings array holds no server, so no marker is read.
    Drift::afterSave('not an array', $rows);
    // A set_transient() would have left its `ttl:` key behind.
    expect($t)->toBe(['alpaca_bot_mcp_drift_trk' => ['report']]);
});

it('reads a server list that is missing or malformed as no servers', function (): void {
    $t = ['alpaca_bot_mcp_drift_trk' => ['report']];
    mcpDriftTransients($t);
    Drift::afterSave(['toolkits.mcp_servers' => [['id' => 'trk', 'approved' => ['report' => 'x']], 'junk', ['approved' => []]]], ['toolkits.mcp_servers' => 'junk']);
    expect($t)->not->toHaveKey('alpaca_bot_mcp_drift_trk');
});
