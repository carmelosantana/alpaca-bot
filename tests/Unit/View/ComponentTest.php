<?php

declare(strict_types=1);

use AlpacaBot\View\Component;
use AlpacaBot\View\Icon;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('esc_html')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    Functions\when('esc_attr')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
});

it('tag() escapes attributes and skips nulls', function (): void {
    $c = new class extends Component { public function render(): string { return $this->tag('button', ['class' => 'x', 'aria-label' => 'a"b', 'disabled' => null], $this->e('<hi>')); } };
    expect((string) $c)->toBe('<button class="x" aria-label="a&quot;b">&lt;hi&gt;</button>');
});

it('Icon renders a sprite reference', function (): void {
    expect(Icon::svg('copy', 'ab-icon--sm'))->toBe('<svg class="ab-icon ab-icon--sm" aria-hidden="true" focusable="false"><use href="#lucide-copy"></use></svg>');
});
