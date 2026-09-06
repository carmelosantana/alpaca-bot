<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapExceeded;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// UsageMeter is final, so it is not mocked: the policy is driven through a real meter whose
// month cache is stubbed (get_transient), which also proves the two read the same keys.
// capPolicyWith() and capPolicyUsageCache() live in tests/Pest.php.
beforeEach(function (): void {
    Functions\when('current_time')->justReturn(1_725_000_000); // 2024-08-30 UTC
});

it('allows without reading usage or applying the filter when both caps are 0', function (): void {
    Functions\expect('get_transient')->never();
    Functions\expect('get_posts')->never();
    Filters\expectApplied('alpaca_bot/cap/allowed')->never();
    capPolicyWith([])->assertAllowed(3);
    expect(true)->toBeTrue();
});

it('allows under both caps and offers each verdict to the filter with its scope, limit and usage', function (): void {
    capPolicyUsageCache(50, 500);
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->with(true, 3, 'user', 100, 50)->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->with(true, 3, 'site', 1000, 500)->andReturnFirstArg();
    capPolicyWith(['governance.user_monthly_tokens' => 100, 'governance.site_monthly_tokens' => 1000])->assertAllowed(3);
    expect(true)->toBeTrue();
});

it('throws for the user cap before the site cap is even read', function (): void {
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(['tokens' => 150, 'requests' => 2]);
    Functions\expect('get_transient')->never()->with('alpaca_bot_usage_site_2024-08');
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->with(false, 3, 'user', 100, 150)->andReturnFirstArg();
    $policy = capPolicyWith(['governance.user_monthly_tokens' => 100, 'governance.site_monthly_tokens' => 1000]);
    try {
        $policy->assertAllowed(3);
        $this->fail('CapExceeded was not thrown');
    } catch (CapExceeded $e) {
        expect($e->scope)->toBe('user')
            ->and($e->limit)->toBe(100)
            ->and($e->used)->toBe(150)
            ->and($e->getMessage())->toBe('Your monthly token cap has been reached (150 of 100 tokens).');
    }
});

// A cap is a ceiling: usage equal to the limit is "reached", and the next request is refused.
it('throws for the site cap when the user is unlimited and usage exactly meets the limit', function (): void {
    capPolicyUsageCache(999_999, 1000);
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->with(false, 3, 'site', 1000, 1000)->andReturnFirstArg();
    $policy = capPolicyWith(['governance.site_monthly_tokens' => 1000]);
    try {
        $policy->assertAllowed(3);
        $this->fail('CapExceeded was not thrown');
    } catch (CapExceeded $e) {
        expect($e->scope)->toBe('site')
            ->and($e->limit)->toBe(1000)
            ->and($e->used)->toBe(1000)
            // The requester is told the site is capped, not what the whole site spent.
            ->and($e->getMessage())->toBe('The site\'s monthly token cap has been reached.');
    }
});

it('lets the filter override a block', function (): void {
    capPolicyUsageCache(0, 999);
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->andReturn(true);
    capPolicyWith(['governance.site_monthly_tokens' => 10])->assertAllowed(3);
    expect(true)->toBeTrue();
});

it('lets the filter impose a block on a request that is under the cap', function (): void {
    capPolicyUsageCache(1, 1);
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->with(true, 3, 'user', 100, 1)->andReturn(false);
    expect(fn() => capPolicyWith(['governance.user_monthly_tokens' => 100, 'governance.site_monthly_tokens' => 1000])->assertAllowed(3))
        ->toThrow(CapExceeded::class, 'Your monthly token cap has been reached (1 of 100 tokens).');
});

// The per-user cap cannot attach to nobody (the meter reports 0 for user 0 rather than counting
// everyone); the site cap is what bounds anonymous usage.
it('bounds an anonymous request by the site cap alone', function (): void {
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_site_2024-08')->andReturn(['tokens' => 10, 'requests' => 1]);
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->with(true, 0, 'user', 100, 0)->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->with(false, 0, 'site', 10, 10)->andReturnFirstArg();
    expect(fn() => capPolicyWith(['governance.user_monthly_tokens' => 100, 'governance.site_monthly_tokens' => 10])->assertAllowed(0))
        ->toThrow(CapExceeded::class);
});
