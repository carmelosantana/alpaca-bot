<?php

declare(strict_types=1);

namespace AlpacaBot;

/**
 * The one guard a filtered capability name goes through, wherever the plugin lets a site name
 * one: the REST permission callbacks (`alpaca_bot/capability/{route}`) and the admin menu
 * (`alpaca_bot/admin/menu_capability`).
 *
 * Only a capability name is honoured. WP_User::has_cap() reads a numeric capability as a legacy
 * user level — '1' is level_1, which every Contributor holds, and '0' is level_0, which every
 * Subscriber holds — while `(string) true` is '1'. So a filter that returns a bool or a number
 * by mistake, and `__return_true` in particular (the idiom a site reaches for meaning "always",
 * and the first thing tried on a surface that will not show up), would cast to a level check
 * and quietly open the surface to roles nobody meant to give it to. Anything that is not a
 * non-empty, non-numeric string is treated as no opinion and the declared capability is what
 * gets checked: a filter cannot loosen a surface by accident, only by naming a capability.
 *
 * It is one function rather than two copies because both call sites guard the same thing for
 * the same reason, and a third (P3's chat screen has its own surfaces) must not have to
 * rediscover why.
 */
final class Capability
{
    /**
     * `$hook` applied to `$default`, with `$args` passed on to the filter, reduced to a
     * capability name.
     *
     * @param mixed ...$args extra arguments the filter receives after the capability
     */
    public static function filtered(string $hook, string $default, mixed ...$args): string
    {
        /** @var mixed $filtered */
        $filtered = apply_filters($hook, $default, ...$args);
        return is_string($filtered) && $filtered !== '' && !is_numeric($filtered) ? $filtered : $default;
    }
}
