<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\View\Component;

/**
 * The line under an assistant turn: `llama3.2 · 1,234 tokens · 1.2 s`, ending `· 2 tools` for
 * a turn that ran any. A part that is missing or zero is left out rather than shown as a zero,
 * so a reply whose provider reported no usage is not labelled "0 tokens", and a turn that ran
 * no tool says nothing about tools.
 */
final class Receipt extends Component
{
    /** @param array<string, mixed> $receipt `model`, `total_tokens`, `duration_ms`, as the pipeline's receipt names them, and `tool_calls`, a count (MessageBubble takes it from the reply's meta) */
    public function __construct(private array $receipt) {}

    public function render(): string
    {
        $parts = [];
        $model = (string) ($this->receipt['model'] ?? '');
        if ($model !== '') {
            $parts[] = $this->e($model);
        }
        $tokens = (int) ($this->receipt['total_tokens'] ?? 0);
        if ($tokens > 0) {
            /* translators: %s: a token count */
            $parts[] = $this->e(sprintf($this->t('%s tokens'), number_format_i18n($tokens)));
        }
        $ms = (int) ($this->receipt['duration_ms'] ?? 0);
        if ($ms > 0) {
            /* translators: %s: seconds to one decimal */
            $parts[] = $this->e(sprintf($this->t('%s s'), number_format_i18n(round($ms / 1000, 1), 1)));
        }
        $tools = (int) ($this->receipt['tool_calls'] ?? 0);
        if ($tools > 0) {
            /* translators: %s: how many tool calls the reply made */
            $parts[] = $this->e(sprintf(_n('%s tool', '%s tools', $tools, 'alpaca-bot'), number_format_i18n($tools)));
        }
        return $this->tag('footer', ['class' => 'ab-receipt'], implode(' · ', $parts));
    }
}
