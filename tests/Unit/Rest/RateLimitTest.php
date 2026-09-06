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
    $rl = new RateLimit(30);
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
    Filters\expectApplied('alpaca_bot/rate_limit')->once()->andReturn(0);
    Functions\when('get_transient')->justReturn(1);
    Functions\when('set_transient')->justReturn(true);
    $hit = (new RateLimit())->hit(3);
    expect($hit)->toMatchArray(['allowed' => false, 'remaining' => 0, 'retry_after' => 45]);
});
