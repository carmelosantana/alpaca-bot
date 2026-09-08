<?php

declare(strict_types=1);

use AlpacaBot\View\Markdown;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('converts GFM and keeps language classes while stripping scripts', function (): void {
    // strip_tags removes tags only: it keeps a stripped tag's inner text and never touches an
    // attribute. So the assertions below on `alert(1)` and `javascript:` can only be satisfied
    // by the converter itself (html_input => strip, allow_unsafe_links => false), not by this stub.
    Functions\when('wp_kses')->alias(fn(string $html, array $allowed) => strip_tags($html, array_map(fn($t) => "<$t>", array_keys($allowed))));
    Filters\expectApplied('alpaca_bot/render/allowed_tags')->once()->andReturnFirstArg();
    // The <script> sits in its own paragraph: a line that starts with <script> is a CommonMark
    // HTML block that runs to the line holding </script>, so anything after it on that line
    // would be raw HTML (stripped), never inline markdown.
    $html = (new Markdown())->toHtml("# Hi\n\n```php\necho 1;\n```\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n<script>alert(1)</script>\n\n[x](javascript:alert(3))\n\n~~x~~ https://example.test");
    expect($html)->toContain('<h1>Hi</h1>')
        ->toContain('<code class="language-php">')
        ->toContain('<table>')
        ->toContain('<del>x</del>')
        ->toContain('<a href="https://example.test">')
        ->not->toContain('<script>')
        ->not->toContain('alert(1)')
        ->not->toContain('javascript:');
});

it('strips raw HTML, block and inline, and unsafe links before wp_kses ever sees them', function (): void {
    // wp_kses is a pass-through here so the assertion is on the converter alone. Block raw HTML
    // (HtmlBlockRenderer) and inline raw HTML (HtmlInlineRenderer) are separate paths; the inline
    // form is the one a model is likelier to produce. Inline stripping removes the two tags as
    // separate nodes, so the text between them survives as plain text, which is the point.
    Functions\when('wp_kses')->returnArg();
    Filters\expectApplied('alpaca_bot/render/allowed_tags')->once()->andReturnFirstArg();
    $html = (new Markdown())->toHtml("<script>alert(1)</script>\n\nsee <script>alert(2)</script> here\n\n[x](javascript:alert(3))");
    // The stripped block leaves its trailing newline behind, hence the leading "\n".
    expect($html)->toBe("\n<p>see alert(2) here</p>\n<p><a>x</a></p>\n");
});
