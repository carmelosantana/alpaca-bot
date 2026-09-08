<?php

declare(strict_types=1);

use AlpacaBot\View\Hx;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('rest_url')->alias(fn(string $p) => 'https://alpacabot.wp.test/wp-json/' . $p);
    Functions\when('esc_attr')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    Functions\when('esc_url')->returnArg();
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('wp_create_nonce')->justReturn('n0nce');
});

it('renders hx attributes with the view URL, escaping, and JSON vals', function (): void {
    $s = Hx::attrs(['get' => '/history', 'target' => '#ab-history', 'swap' => 'outerHTML', 'trigger' => 'load, ab:refresh from:body', 'vals' => ['limit' => 5], 'headers' => Hx::formHeaders()]);
    expect($s)->toBe(' hx-get="https://alpacabot.wp.test/wp-json/alpaca-bot/v1/view/history" hx-target="#ab-history" hx-swap="outerHTML" hx-trigger="load, ab:refresh from:body" hx-vals="{&quot;limit&quot;:5}" hx-headers="{&quot;X-WP-Nonce&quot;:&quot;n0nce&quot;}"');
});

it('rejects unknown attributes', function (): void {
    expect(fn() => Hx::attrs(['onclick' => 'x']))->toThrow(InvalidArgumentException::class);
});
