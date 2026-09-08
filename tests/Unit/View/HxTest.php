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

it('renders a valid on: event key', function (): void {
    expect(Hx::attrs(['on:click' => 'this.remove()']))->toBe(' hx-on:click="this.remove()"');
});

it('rejects an on: key whose event name is not a plain token', function (): void {
    // The key is interpolated into the attribute *name*, so a quote in it would break out of
    // the attribute; only the suffix's characters keep it in.
    expect(fn() => Hx::attrs(['on:click" onmouseover="alert(document.cookie)' => 'foo']))->toThrow(InvalidArgumentException::class);
    expect(fn() => Hx::attrs(['on:' => 'x']))->toThrow(InvalidArgumentException::class);
});

it('throws when an array value cannot be JSON-encoded instead of emitting an empty payload', function (): void {
    Functions\when('wp_json_encode')->justReturn(false);
    expect(fn() => Hx::attrs(['vals' => ['a' => "\xB1\x31"]]))->toThrow(InvalidArgumentException::class, 'hx-vals');
});
