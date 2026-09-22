<?php

declare(strict_types=1);

use AlpacaBot\Admin\SiteHealth;
use AlpacaBot\Settings\Store;

// Site Health runs a direct test's callback and renders the array it returns (WP 7.1
// class-wp-site-health.php:182-204; the shape is the one core's own tests return, e.g.
// get_test_https_status() at :1481-1500). The two server facts are injected: whether
// WordPress would send a request through cURL, and whether the HTTP API is set to use a proxy.

it('adds one direct test under its own prefixed id, keeping every test already there', function (): void {
    $tests = (new SiteHealth(new Store([])))->register(['direct' => ['php_version' => ['label' => 'PHP', 'test' => 'php_version']], 'async' => []]);
    // The id itself, not just that it is the constant: core asks a plugin to prefix its test
    // ids with its slug, and an assertion written in terms of the constant alone cannot see it.
    expect(SiteHealth::TEST)->toStartWith('alpaca_bot_')
        ->and(array_keys($tests['direct']))->toBe(['php_version', SiteHealth::TEST])
        ->and($tests['direct'][SiteHealth::TEST]['test'])->toBeCallable()
        ->and($tests['direct'][SiteHealth::TEST]['label'])->toContain('web_fetch')
        ->and($tests['async'])->toBe([]);
});

it('answers in the shape core renders, good when web_fetch is off or can run pinned, recommended when it cannot', function (): void {
    foreach ([
        'off' => [[], true, false, 'good', 'switched off'],
        'pinned' => [['web_fetch'], true, false, 'good', 'address it checked'],
        'no curl' => [['web_fetch'], false, false, 'recommended', 'cURL'],
        'proxy' => [['web_fetch'], true, true, 'recommended', 'WP_PROXY_HOST'],
    ] as $case => [$enabled, $curl, $proxied, $status, $says]) {
        $result = (new SiteHealth(new Store(['toolkits.enabled' => $enabled]), static fn(bool $https): bool => $curl, static fn(): bool => $proxied))->webFetch();
        expect(array_keys($result))->toBe(['label', 'status', 'badge', 'description', 'actions', 'test'], $case)
            ->and(array_keys($result['badge']))->toBe(['label', 'color'], $case)
            ->and($result['status'])->toBe($status, $case)
            ->and($result['test'])->toBe(SiteHealth::TEST, $case)
            ->and($result['description'])->toStartWith('<p>', $case)
            // toContain() is variadic, so its second argument would be a second needle rather
            // than the case label; the containment is asserted as a bool to keep the label.
            ->and(str_contains($result['label'] . $result['description'], $says))->toBeTrue($case . ': ' . $says);
    }
});

it('asks the cURL question for https, the stricter of the two', function (): void {
    $asked = [];
    (new SiteHealth(new Store(['toolkits.enabled' => ['web_fetch']]), static function (bool $https) use (&$asked): bool {
        $asked[] = $https;
        return true;
    }, static fn(): bool => false))->webFetch();
    expect($asked)->toBe([true]);
});
