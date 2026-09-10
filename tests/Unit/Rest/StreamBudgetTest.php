<?php

declare(strict_types=1);

use AlpacaBot\Chat\Assistant;
use AlpacaBot\Rest\StreamBudget;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// The clock is 1_725_000_000 throughout, as pipelineWith() sets it, so a slot's stamp and a
// retry_after are exact numbers rather than ranges. Store is final, so the budget runs over the
// real one with get_option() stubbed. What StreamControllerTest pins is that the stream route
// applies these; what is pinned here is the arithmetic and the slot bookkeeping themselves.

beforeEach(function (): void {
    Functions\when('current_time')->justReturn(1_725_000_000);
    $this->transients = [];
    Functions\when('get_transient')->alias(fn(string $key): mixed => $this->transients[$key] ?? false);
    Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl = 0): bool {
        $this->transients[$key] = $value;
        $this->ttl[$key] = $ttl;
        return true;
    });
    Functions\when('delete_transient')->alias(function (string $key): bool {
        unset($this->transients[$key]);
        return true;
    });
    $this->ttl = [];
    $this->budget = fn(array $settings = []): StreamBudget => new StreamBudget(new Store($settings));
});

it('sizes a turn from the provider timeout and the agent iteration budget', function (): void {
    // One provider call per agent iteration, plus room for one nested tool call (summarize)
    // per iteration: provider.timeout x 6 x 2. Nothing in the code bounds the nested calls, so
    // this is a policy; StreamBudget's docblock is where that is argued.
    expect(($this->budget)()->seconds())->toBe(720)
        ->and(720)->toBe(60 * Assistant::MAX_ITERATIONS * 2)
        ->and(($this->budget)(['provider.timeout' => 5])->seconds())->toBe(60)
        ->and(($this->budget)(['provider.timeout' => 600])->seconds())->toBe(7200);
});

it('lets a filter move the budget but never below one provider timeout', function (): void {
    Filters\expectApplied('alpaca_bot/stream/budget')->once()->with(720, 60)->andReturn(90);
    expect(($this->budget)()->seconds())->toBe(90);

    // A filter that forgot to return would otherwise end every turn before its first provider
    // call: an outage dressed as a timeout.
    Filters\expectApplied('alpaca_bot/stream/budget')->once()->andReturn(null);
    expect(($this->budget)()->seconds())->toBe(60);
});

it('hands out LIMIT slots to one person and then refuses, with the wait until the last one expires', function (): void {
    $budget = ($this->budget)();
    $slots = [];
    foreach (range(1, StreamBudget::LIMIT) as $n) {
        $claim = $budget->claim(7);
        expect($claim['slot'])->toBeString()
            ->and($claim['retry_after'])->toBe(0);
        $slots[] = $claim['slot'];
    }
    expect(array_unique($slots))->toHaveCount(StreamBudget::LIMIT)
        // Slot id => the instant it expires, and the row outlives the last stamp in it.
        ->and($this->transients[StreamBudget::TRANSIENT . '7'])->toBe(array_fill_keys($slots, 1_725_000_720))
        ->and($this->ttl[StreamBudget::TRANSIENT . '7'])->toBe(720);

    $over = $budget->claim(7);
    expect($over['slot'])->toBeNull()
        ->and($over['retry_after'])->toBe(720)
        // A refusal takes nothing: the slots are the same three.
        ->and($this->transients[StreamBudget::TRANSIENT . '7'])->toHaveCount(StreamBudget::LIMIT);

    // Another person's slots are their own.
    expect($budget->claim(8)['slot'])->toBeString();
});

it('counts only slots that have not expired, and prunes the rest on the way past', function (): void {
    // A process that was killed outright (out of memory, SIGKILL) never runs release(); the
    // stamp is what bounds that leak, and the stamp is the same deadline set_time_limit() was
    // given, so a run killed by its own time limit is already uncountable here.
    $this->transients[StreamBudget::TRANSIENT . '7'] = [
        'gone' => 1_724_999_999,
        'also_gone' => 1_725_000_000,
        'live' => 1_725_000_001,
        'not_an_int' => 'x',
    ];
    $budget = ($this->budget)();
    $claim = $budget->claim(7);
    expect($claim['slot'])->toBeString()
        ->and(array_keys($this->transients[StreamBudget::TRANSIENT . '7']))->toBe(['live', $claim['slot']]);
});

it('gives a slot back, and deletes the row rather than storing an empty set', function (): void {
    $budget = ($this->budget)();
    $one = $budget->claim(7)['slot'];
    $two = $budget->claim(7)['slot'];
    $budget->release(7, (string) $one);
    expect($this->transients[StreamBudget::TRANSIENT . '7'])->toBe([$two => 1_725_000_720]);
    // Releasing a slot that is not there (already expired, or released twice) changes nothing.
    $budget->release(7, (string) $one);
    expect($this->transients[StreamBudget::TRANSIENT . '7'])->toBe([$two => 1_725_000_720]);
    $budget->release(7, (string) $two);
    expect($this->transients)->not->toHaveKey(StreamBudget::TRANSIENT . '7');
});

it('lets a filter move the cap, and keeps it at one at the least', function (): void {
    Filters\expectApplied('alpaca_bot/stream/concurrent')->twice()->with(StreamBudget::LIMIT, 7)->andReturn(1);
    $budget = ($this->budget)();
    expect($budget->claim(7)['slot'])->toBeString()
        ->and($budget->claim(7)['slot'])->toBeNull();

    // A filter that forgot to return must not close the route.
    Filters\expectApplied('alpaca_bot/stream/concurrent')->once()->andReturn(null);
    $this->transients = [];
    expect(($this->budget)()->claim(7)['slot'])->toBeString();
});

it('counts a request with no user by client address, as the rate limiter does', function (): void {
    // A site that opened the stream route to visitors through its capability filter is every
    // visitor to get_current_user_id(); one shared pool of three would let one script hold every
    // visitor's allowance.
    Functions\when('wp_hash')->alias(static fn(string $data): string => 'h:' . $data);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    expect(($this->budget)()->claim(0)['slot'])->toBeString()
        ->and(array_keys($this->transients))->toBe([StreamBudget::TRANSIENT . 'ip_h:203.0.113.9']);
    unset($_SERVER['REMOTE_ADDR']);
});
