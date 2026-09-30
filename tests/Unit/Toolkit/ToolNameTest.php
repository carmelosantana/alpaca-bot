<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\ToolName;

// The names come from elsewhere (an ability's namespace/name, a remote MCP server's tool names).
// fit() holds each one to at most 64 characters of [A-Za-z0-9_-], the first a letter or an
// underscore, and marks every name it had to change.

it('passes a name that already fits through untouched', function (): void {
    expect(ToolName::fit('ability__core__get-site-info'))->toBe('ability__core__get-site-info')
        ->and(ToolName::fit('gh__search_issues'))->toBe('gh__search_issues');
});

it('replaces characters outside the portable set and marks the change with a hash, so two names the replacement would merge stay apart', function (): void {
    $dotted = ToolName::fit('gh__repo.search');
    expect($dotted)->toMatch('/^gh__repo_search_[0-9a-f]{8}$/')
        ->and(ToolName::fit('gh__repo_search'))->toBe('gh__repo_search')
        ->and($dotted)->not->toBe(ToolName::fit('gh__repo_search'))
        ->and($dotted)->not->toBe(ToolName::fit('gh__repo:search'));
});

it('cuts a long name to 64 characters and ends it with a hash of the whole name, the same way every time', function (): void {
    $long = 'ability__' . str_repeat('a', 40) . '__' . str_repeat('b', 40);
    $fit = ToolName::fit($long);
    expect(strlen($fit))->toBe(64)
        ->and($fit)->toBe(substr($long, 0, 55) . '_' . substr(hash('sha256', $long), 0, 8))
        ->and(ToolName::fit($long))->toBe($fit)
        ->and(ToolName::fit($long . 'x'))->not->toBe($fit);
});

it('puts an underscore in front of a name that starts with anything but a letter or an underscore, and marks it', function (): void {
    expect(ToolName::fit('1abc__x'))->toMatch('/^_1abc__x_[0-9a-f]{8}$/');
});

it('answers inside the portable set whatever it is handed', function (): void {
    foreach (['', '-', "a\nb", 'é/ü', str_repeat('x', 200), '### heading', "tab\there", '__proto__'] as $raw) {
        expect(ToolName::fit($raw))->toMatch('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $raw);
    }
});

// A name of 64 characters cannot be a one-to-one function of every longer name, and the mark is
// eight hex characters of a hash the caller can compute: anyone who can choose a name can choose
// one whose mark is a name somebody else fits unchanged. fit() does not pretend otherwise; its
// callers are what refuse to offer two tools under one name (AbilitiesToolkitTest).
it('can give two different names the same answer, which its callers have to notice', function (): void {
    $ns = str_repeat('n', 45);
    $long = 'ability__' . $ns . '__' . str_repeat('l', 20);
    $mark = substr(hash('sha256', $long), 0, 8);
    $short = 'ability__' . $ns . '__' . $mark;
    expect($short)->not->toBe($long)
        ->and(ToolName::fit($short))->toBe($short)
        ->and(ToolName::fit($long))->toBe($short);
});
