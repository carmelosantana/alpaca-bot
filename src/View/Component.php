<?php

declare(strict_types=1);

namespace AlpacaBot\View;

/**
 * A server-rendered fragment of the admin screen. A component is a value that knows how to
 * render itself; casting one to string renders it, so a parent can drop a child straight into
 * its own markup. The one-letter helpers are the escaping vocabulary: a template written with
 * them reads as HTML, and the short names keep the escape call cheaper to write than to skip.
 */
abstract class Component
{
    /** Elements that take no closing tag. */
    private const VOID = ['area', 'br', 'col', 'hr', 'img', 'input', 'source', 'wbr'];

    abstract public function render(): string;

    public function __toString(): string
    {
        return $this->render();
    }

    protected function e(string $s): string { return esc_html($s); }
    protected function a(string $s): string { return esc_attr($s); }
    protected function u(string $s): string { return esc_url($s); }

    /**
     * An element with attribute-escaped values. A null value drops the attribute, so a
     * conditional attribute (`'disabled' => $busy ? '' : null`) needs no branching at the
     * call site. `$inner` is used as given: the caller has already escaped it, or it is
     * another component's output. Only attribute *values* are escaped: `$name` and the
     * attribute names are written raw, so they must be literals in the calling code, never data.
     *
     * @param array<string, string|null> $attrs
     */
    protected function tag(string $name, array $attrs, string $inner = ''): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            if ($v === null) {
                continue;
            }
            $a .= sprintf(' %s="%s"', $k, $this->a($v));
        }
        return in_array($name, self::VOID, true) ? "<{$name}{$a}>" : "<{$name}{$a}>{$inner}</{$name}>";
    }
}
