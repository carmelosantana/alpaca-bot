<?php

declare(strict_types=1);

namespace AlpacaBot\View;

/**
 * A reference into the Lucide sprite the build inlines once per page (scripts/icons.mjs). The
 * icon is decoration: it is hidden from assistive technology and kept out of the tab order
 * (`focusable="false"` is for older Edge/IE, which otherwise tab-stop on every SVG), so a
 * button that has nothing but an icon must carry its own aria-label.
 */
final class Icon
{
    public static function svg(string $name, string $class = ''): string
    {
        $cls = trim('ab-icon ' . $class);
        return sprintf('<svg class="%s" aria-hidden="true" focusable="false"><use href="#lucide-%s"></use></svg>', esc_attr($cls), esc_attr($name));
    }
}
