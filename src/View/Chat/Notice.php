<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\View\Component;

/**
 * A core admin notice, inline so WordPress does not move it to the top of the page. The kind
 * is held to core's four so the class attribute is never built from data; anything else reads
 * as `info`. chat.ts's `notice()` writes the same markup for client-side errors.
 */
final class Notice extends Component
{
    private const KINDS = ['success', 'error', 'warning', 'info'];

    public function __construct(private string $kind, private string $text) {}

    public function render(): string
    {
        $kind = in_array($this->kind, self::KINDS, true) ? $this->kind : 'info';
        return $this->tag('div', ['class' => 'notice notice-' . $kind . ' inline'], $this->tag('p', [], $this->e($this->text)));
    }
}
