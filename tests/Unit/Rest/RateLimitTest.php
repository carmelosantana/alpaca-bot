<?php

declare(strict_types=1);

use AlpacaBot\Rest\RateLimit;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('counts hits per user per minute and blocks past the limit', function (): void {
    Functions\when('current_time')->justReturn(1_725_000_000);
    Filters\expectApplied('alpaca_bot/rate_limit')->times(3)->andReturn(2);
    $count = 0;
    Functions\when('get_transient')->alias(function () use (&$count) {
        return $count ?: false;
    });
    Functions\when('set_transient')->alias(function (string $k, int $v) use (&$count): bool {
        $count = $v;
        return true;
    });
    $rl = new RateLimit();
    expect($rl->hit(3)['allowed'])->toBeTrue()->and($rl->hit(3))->toMatchArray(['allowed' => true, 'remaining' => 0]);
    $third = $rl->hit(3);
    expect($third['allowed'])->toBeFalse()->and($third['retry_after'])->toBeGreaterThan(0)->and($third['retry_after'])->toBeLessThanOrEqual(60);
});

it('keys the transient by bucket, user and UTC minute so a new minute starts a fresh count', function (): void {
    // 1_725_000_000 is 2024-08-30 06:40:00 UTC; the second call is the next minute's first hit.
    $clock = 1_725_000_000;
    Functions\when('current_time')->alias(function () use (&$clock): int {
        return $clock;
    });
    Filters\expectApplied('alpaca_bot/rate_limit')->times(2)->with(30, 3, 'chat')->andReturn(1);
    $keys = [];
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->alias(function (string $k, int $v, int $ttl) use (&$keys): bool {
        $keys[] = [$k, $v, $ttl];
        return true;
    });
    $rl = new RateLimit();
    $first = $rl->hit(3);
    $clock += 60;
    $second = $rl->hit(3);
    expect($first)->toMatchArray(['allowed' => true, 'remaining' => 0, 'retry_after' => 0])
        ->and($second['allowed'])->toBeTrue()
        ->and($keys[0][0])->toBe('alpaca_bot_rl_chat_3_202408300640')
        ->and($keys[1][0])->toBe('alpaca_bot_rl_chat_3_202408300641')
        ->and($keys[0][2])->toBeGreaterThanOrEqual(60);
});

it('never lets the filter disable the limiter: a non-positive limit becomes one request a minute', function (): void {
    Functions\when('current_time')->justReturn(1_725_000_015);
    Filters\expectApplied('alpaca_bot/rate_limit')->twice()->andReturn(0);
    $count = 0;
    Functions\when('get_transient')->alias(function () use (&$count) {
        return $count ?: false;
    });
    Functions\when('set_transient')->alias(function (string $k, int $v) use (&$count): bool {
        $count = $v;
        return true;
    });
    $rl = new RateLimit();
    // The minute's first hit: with the limit clamped to one it is allowed; unclamped, 1 > 0 and
    // every request would be refused, which is what the clamp exists to prevent.
    expect($rl->hit(3))->toMatchArray(['allowed' => true, 'remaining' => 0, 'retry_after' => 0])
        ->and($rl->hit(3))->toMatchArray(['allowed' => false, 'remaining' => 0, 'retry_after' => 45]);
});

it('keys an anonymous hit by the salted hash of REMOTE_ADDR, so clients have their own buckets and no raw address is stored', function (): void {
    Functions\when('current_time')->justReturn(1_725_000_000);
    Filters\expectApplied('alpaca_bot/rate_limit')->times(3)->with(30, 0, 'chat')->andReturn(1);
    Functions\when('wp_hash')->alias(static fn(string $data): string => 'h' . md5($data));
    $store = [];
    Functions\when('get_transient')->alias(function (string $k) use (&$store): mixed {
        return $store[$k] ?? false;
    });
    Functions\when('set_transient')->alias(function (string $k, int $v) use (&$store): bool {
        $store[$k] = $v;
        return true;
    });
    $saved = [$_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null];
    try {
        $rl = new RateLimit();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        expect($rl->hit(0)['allowed'])->toBeTrue();
        // A second client: its own bucket, so its first hit is allowed although the limit is one.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        expect($rl->hit(0)['allowed'])->toBeTrue();
        // The first client back, claiming through a forwarding header to be the second: the
        // header is client-supplied and ignored, so this lands in the first bucket and is refused.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
        expect($rl->hit(0)['allowed'])->toBeFalse();
    } finally {
        [$_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']] = $saved;
    }
    $keys = array_keys($store);
    expect($keys)->toBe([
        'alpaca_bot_rl_chat_ip_h' . md5('203.0.113.5') . '_202408300640',
        'alpaca_bot_rl_chat_ip_h' . md5('198.51.100.9') . '_202408300640',
    ])->and(implode(' ', $keys))->not->toContain('203.0.113')->not->toContain('_0_');
});

it('falls back to one shared anonymous bucket only when there is no valid REMOTE_ADDR at all', function (): void {
    Functions\when('current_time')->justReturn(1_725_000_000);
    Filters\expectApplied('alpaca_bot/rate_limit')->once()->andReturn(30);
    Functions\expect('wp_hash')->never();
    Functions\when('get_transient')->justReturn(false);
    $key = null;
    Functions\when('set_transient')->alias(function (string $k) use (&$key): bool {
        $key = $k;
        return true;
    });
    $saved = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        $_SERVER['REMOTE_ADDR'] = 'not-an-address';
        (new RateLimit())->hit(0);
    } finally {
        $_SERVER['REMOTE_ADDR'] = $saved;
    }
    expect($key)->toBe('alpaca_bot_rl_chat_ip_unknown_202408300640');
});
