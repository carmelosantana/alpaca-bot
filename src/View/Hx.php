<?php

declare(strict_types=1);

namespace AlpacaBot\View;

/**
 * The one place an `hx-*` attribute is written. Every view component asks here, so the set of
 * htmx behaviours the plugin uses is a closed list (anything else is a typo or an injection
 * vector and is refused), every request URL goes through the view route, and every value is
 * attribute-escaped on the way out. A grep for `hx-` outside this file in src/ is meant to
 * come back empty.
 */
final class Hx
{
    private const ALLOWED = ['get', 'post', 'put', 'delete', 'target', 'swap', 'trigger', 'vals', 'headers', 'indicator', 'include', 'select', 'sync', 'disabled-elt', 'push-url', 'on:', 'swap-oob'];

    /** The REST URL for a view path: `/history` becomes `…/wp-json/alpaca-bot/v1/view/history`. */
    public static function url(string $viewPath): string
    {
        return rest_url('alpaca-bot/v1/view' . $viewPath);
    }

    /**
     * What a form (or any htmx request) has to send for core's REST cookie authentication to
     * trust it; hand the array to `attrs()` as `headers` and it is JSON-encoded in place.
     *
     * @return array<string, string>
     */
    public static function formHeaders(): array
    {
        return ['X-WP-Nonce' => wp_create_nonce('wp_rest')];
    }

    /**
     * The attribute string for a tag, leading space included, so it drops straight into
     * `<div{$attrs}>`. The four verb keys take a view path and come out as a full escaped URL;
     * `vals` and `headers` take arrays and come out as JSON; `on:*` keys pass through with
     * their event name (htmx's `hx-on:click`).
     *
     * @param array<string, string|array<string, mixed>> $attrs
     * @throws \InvalidArgumentException for a key that is not on the allowed list
     */
    public static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            $base = str_starts_with($k, 'on:') ? 'on:' : $k;
            if (!in_array($base, self::ALLOWED, true)) {
                throw new \InvalidArgumentException("Unknown htmx attribute: {$k}");
            }
            if (in_array($k, ['get', 'post', 'put', 'delete'], true)) {
                $v = esc_url(self::url((string) $v));
            } elseif (is_array($v)) {
                $v = wp_json_encode($v);
            }
            $out .= sprintf(' hx-%s="%s"', $k, esc_attr((string) $v));
        }
        return $out;
    }
}
