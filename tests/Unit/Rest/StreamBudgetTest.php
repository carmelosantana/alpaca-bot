<?php

declare(strict_types=1);

use AlpacaBot\Chat\Assistant;
use AlpacaBot\Rest\StreamBudget;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// The clock is 1_725_000_000 throughout, as pipelineWith() sets it, so a slot's lease and a
// retry_after are exact numbers rather than ranges. Store is final, so the budget runs over the
// real one with the settings passed in. What StreamControllerTest pins is that the stream route
// applies these; what is pinned here is the arithmetic and the slot claim themselves — the claim
// against the options table's unique key, including the race that the transient version of this
// cap could not survive.

beforeEach(function (): void {
    Functions\when('current_time')->justReturn(1_725_000_000);
    $this->table = slotRowsIn();
    $this->budget = fn(array $settings = []): StreamBudget => new StreamBudget(new Store($settings));
    $this->slots = fn(string $subject = '7'): array => array_filter(
        $this->table->rows,
        static fn(string $name): bool => str_starts_with($name, StreamBudget::OPTION . $subject . '_'),
        ARRAY_FILTER_USE_KEY,
    );
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

it('hands out LIMIT slots to one person and then refuses, with the wait until the soonest frees', function (): void {
    $budget = ($this->budget)();
    $slots = [];
    foreach (range(1, StreamBudget::LIMIT) as $n) {
        $claim = $budget->claim(7);
        expect($claim['slot'])->toBeString()
            ->and($claim['retry_after'])->toBe(0);
        $slots[] = $claim['slot'];
    }
    // One row per slot, named by its index, holding the lease this claim wrote — which is what
    // the handle carries back so release() can delete that claim and no other.
    expect(array_keys(($this->slots)()))->toBe([
        StreamBudget::OPTION . '7_0',
        StreamBudget::OPTION . '7_1',
        StreamBudget::OPTION . '7_2',
    ])
        ->and($slots[1])->toStartWith('1:1725000720:')
        ->and(($this->slots)()[StreamBudget::OPTION . '7_1'])->toBe(substr($slots[1], 2));

    $over = $budget->claim(7);
    expect($over['slot'])->toBeNull()
        ->and($over['retry_after'])->toBe(720)
        // A refusal takes nothing: the rows are the same three.
        ->and(($this->slots)())->toHaveCount(StreamBudget::LIMIT);

    // Another person's slots are their own.
    expect($budget->claim(8)['slot'])->toBeString()
        ->and(($this->slots)('8'))->toHaveCount(1);
});

it('cannot be raced past the cap: thirty claims that all read the same instant take three slots', function (): void {
    // The probe that condemned the transient version of this cap. Three batches of ten
    // redemptions from one account, every worker in a batch reading the store before any of
    // them writes: read-modify-write over one transient gave 30 live streams and a recorded
    // count of 3, because each batch's writers overwrote each other and the count never
    // remembered the overshoot. The claim is now the INSERT itself, which the unique key on
    // option_name decides, so what a worker read before it cannot change the outcome.
    $budget = ($this->budget)();
    $live = 0;
    foreach (range(1, 3) as $batch) {
        $this->table->frozen = $this->table->rows;
        foreach (range(1, 10) as $worker) {
            if ($budget->claim(7)['slot'] !== null) {
                $live++;
            }
        }
        $this->table->frozen = null;
    }
    expect($live)->toBe(StreamBudget::LIMIT)
        ->and(($this->slots)())->toHaveCount(StreamBudget::LIMIT);
});

it('takes over a slot whose lease has lapsed, and only one claim can win it', function (): void {
    // A process killed outright (out of memory, SIGKILL) never runs release(); the lease is what
    // bounds that leak, and it is the same deadline set_time_limit() was given, so a run killed
    // by its own time limit has already lapsed here.
    $this->table->rows[StreamBudget::OPTION . '7_0'] = '1724999999:dead';
    Filters\expectApplied('alpaca_bot/stream/concurrent')->twice()->andReturn(1);
    $budget = ($this->budget)();
    // Both read the lapsed row at the same instant; the compare-and-set on its exact value is
    // what decides which of them holds it.
    $this->table->frozen = $this->table->rows;
    $first = $budget->claim(7);
    $second = $budget->claim(7);
    $this->table->frozen = null;
    expect($first['slot'])->toStartWith('0:1725000720:')
        ->and($second['slot'])->toBeNull()
        ->and(($this->slots)())->toHaveCount(1)
        ->and(($this->slots)()[StreamBudget::OPTION . '7_0'])->toBe(substr((string) $first['slot'], 2));
});

it('gives a slot back, and leaves a row another claim has taken over alone', function (): void {
    $budget = ($this->budget)();
    $one = (string) $budget->claim(7)['slot'];
    $two = (string) $budget->claim(7)['slot'];
    $budget->release(7, $one);
    expect(array_keys(($this->slots)()))->toBe([StreamBudget::OPTION . '7_1']);
    // Releasing a slot that is not there (already released, or lapsed and gone) changes nothing.
    $budget->release(7, $one);
    expect(array_keys(($this->slots)()))->toBe([StreamBudget::OPTION . '7_1']);

    // The row is slot 1's again, held by someone else's lease: the earlier holder's release
    // names the value as well as the row, so it takes nothing from the stream running now.
    $this->table->rows[StreamBudget::OPTION . '7_1'] = '1725000720:someone_else';
    $budget->release(7, $two);
    expect(($this->slots)()[StreamBudget::OPTION . '7_1'])->toBe('1725000720:someone_else');
});

it('lets a filter move the cap, and keeps it at one at the least', function (): void {
    Filters\expectApplied('alpaca_bot/stream/concurrent')->twice()->with(StreamBudget::LIMIT, 7)->andReturn(1);
    $budget = ($this->budget)();
    expect($budget->claim(7)['slot'])->toBeString()
        ->and($budget->claim(7)['slot'])->toBeNull();

    // A filter that forgot to return must not close the route.
    Filters\expectApplied('alpaca_bot/stream/concurrent')->once()->andReturn(null);
    $this->table->rows = [];
    expect(($this->budget)()->claim(7)['slot'])->toBeString();
});

it('counts a request with no user by client address, as the rate limiter does', function (): void {
    // A site that opened the stream route to visitors through its capability filter is every
    // visitor to get_current_user_id(); one shared pool of three would let one script hold every
    // visitor's allowance.
    Functions\when('wp_hash')->alias(static fn(string $data): string => 'h:' . $data);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    expect(($this->budget)()->claim(0)['slot'])->toBeString()
        ->and(array_keys($this->table->rows))->toBe([StreamBudget::OPTION . 'ip_h:203.0.113.9_0']);
    unset($_SERVER['REMOTE_ADDR']);
});
