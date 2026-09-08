<?php

declare(strict_types=1);

use AlpacaBot\View\Markdown;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('converts GFM and keeps language classes while stripping scripts', function (): void {
    Functions\when('wp_kses')->alias(fn(string $html, array $allowed) => strip_tags($html, array_map(fn($t) => "<$t>", array_keys($allowed))));
    Filters\expectApplied('alpaca_bot/render/allowed_tags')->once()->andReturnFirstArg();
    // The <script> sits in its own paragraph: a line that starts with <script> is a CommonMark
    // HTML block that runs to the line holding </script>, so anything after it on that line
    // would be raw HTML (stripped), never inline markdown.
    $html = (new Markdown())->toHtml("# Hi\n\n```php\necho 1;\n```\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n<script>alert(1)</script>\n\n~~x~~ https://example.test");
    expect($html)->toContain('<h1>Hi</h1>')
        ->toContain('<code class="language-php">')
        ->toContain('<table>')
        ->toContain('<del>x</del>')
        ->toContain('<a href="https://example.test">')
        ->not->toContain('<script>');
});
