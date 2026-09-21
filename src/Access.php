<?php

declare(strict_types=1);

namespace AlpacaBot;

use AlpacaBot\Settings\Store;

/**
 * Who may use what, per site: the rows of Settings › Access, each one capability name, and what a
 * row resolves to once code has had its say.
 *
 * A row's stored value is the *default its filter receives*, never a verdict of its own, so a site
 * that names a capability in code keeps it whatever the screen says. No role data is written and
 * no capability is invented: a row names one of CAPABILITIES, which core already hands out, and
 * user_can() answers it as it answers every other check. That is the whole of the access model —
 * there is no role × capability matrix and nothing is granted to a role.
 *
 * This class answers; it does not enforce. A row gates nothing until a surface asks for it and
 * acts on the answer, so reading a row here is never the check — allows(), or the caller's own
 * user_can(), is.
 *
 * Rows and hooks. Every row but `chat` has a filter of its own, `alpaca_bot/capability/{hook}`,
 * where hook() turns the row's dots into slashes (`tool.web_fetch` is `tool/web_fetch`). What
 * follows the capability in that filter's signature is the *caller's*, not this class's: each
 * surface passes what it knows, and those differ row by row (a REST request, a user id, a post id
 * and a shortcode tag, or nothing at all). A listener must therefore declare — and register
 * `accepted_args` for — the arguments its own row fires with: core slices the argument list to
 * `accepted_args` and calls the listener with what it has, so a callback that expects more
 * arguments than its row passes raises an ArgumentCountError when the hook fires. What a row
 * passes is decided at the surface that asks for it, not here.
 *
 * `chat` has no filter here, and must not: that capability is already filtered by the two hooks
 * that were there before this tab — the menu's `alpaca_bot/admin/menu_capability` and each chat
 * route's `alpaca_bot/capability/{route}` — and they are filtered separately on purpose, so that a
 * site can open the screen to a role and not the API, or the reverse (Admin\Menu says why). A
 * third hook over the same capability would only be a third place to disagree. effective('chat')
 * is therefore the stored value itself, for the surface to put through its own filter.
 *
 * Two kinds of row are not in defaults(): `mcp.<server id>`, which exists once a server does, and
 * any row nobody declared (a typo, a toolkit a site registered in code). Both read as
 * `manage_options`, so a row this class has never heard of fails closed rather than open. The MCP
 * rows are stored together in the one `access.mcp` map rather than a key each, because
 * Schema::sanitize() rebuilds the whole option from Schema::fields() and drops every key it does
 * not declare: a per-server key could not be saved at all. The row names are unaffected; only
 * where stored() looks for them is.
 *
 * Everything is read through Store, which reads `alpaca_bot_settings` at most once per request and
 * memoises it, so a request that asks every row costs no read beyond the one the settings already
 * cost (docs/reviews/2026-09-09-performance-baseline.md's one-read target).
 *
 * @since 0.6.0
 */
final class Access
{
    /**
     * The capabilities a row may name, most to least restrictive. A fixed list rather than
     * anything a site can extend: the point of a dropdown is that an operator cannot mistype a
     * capability into a surface that spends money, and a site that wants a capability of its own
     * names it in that row's filter, where Capability::filtered() still guards the answer.
     */
    public const CAPABILITIES = ['manage_options', 'edit_others_posts', 'publish_posts', 'edit_posts', 'read'];

    /** The row-name prefix whose rows live in the `access.mcp` map: `mcp.github` is that map's `github`. */
    public const MCP_PREFIX = 'mcp.';

    /** What a row defaults() does not list resolves to. */
    private const UNLISTED = 'manage_options';

    public function __construct(private Store $store) {}

    /**
     * Row => the capability it starts at. These reproduce 0.5 exactly where 0.5 had an answer:
     * chat is the menu's and the chat routes' `edit_posts`, and the shortcode is
     * Shortcodes\Chat::CAPABILITY, also `edit_posts`, so a site that never opens this tab sees no
     * change. Both settings rows are the `manage_options` those routes already declare, and the
     * abilities toolkit is new in 0.6 and starts closed at `manage_options`.
     *
     * The three built-in tool rows are new gates, not new defaults: 0.5 had nothing between "may
     * chat" and "may call the enabled tools" (`alpaca_bot/toolkits` was the only lever), so they
     * start at chat's own `edit_posts` and change nothing until a site narrows them.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'chat' => 'edit_posts',
            'tool.web_fetch' => 'edit_posts',
            'tool.summarize' => 'edit_posts',
            'tool.draft_post' => 'edit_posts',
            'tool.abilities' => 'manage_options',
            'settings.read' => 'manage_options',
            'settings.write' => 'manage_options',
            'shortcode' => 'edit_posts',
        ];
    }

    /**
     * What the site has saved for `$row`, or the row's default. A stored value that is not one of
     * CAPABILITIES is the default too: Store hands back what is in the option, not what
     * Schema::sanitize() would make of it, so a hand-edited row, or one holding a capability that
     * has since left the list, must not become a check nobody chose.
     */
    public function stored(string $row): string
    {
        $value = str_starts_with($row, self::MCP_PREFIX)
            ? ($this->mcp()[substr($row, strlen(self::MCP_PREFIX))] ?? null)
            : $this->store->get('access.' . $row);
        return is_string($value) && in_array($value, self::CAPABILITIES, true)
            ? $value
            : (self::defaults()[$row] ?? self::UNLISTED);
    }

    /**
     * The capability `$row` resolves to for this call, code included: the stored row through its
     * filter. `$args` are passed on to the filter, so a surface hands it what it knows (the turn's
     * user id, the post and tag a shortcode is rendering in, the REST request being authorised).
     * What a row passes is that row's own contract, not a shape shared by all of them; the class
     * docblock says what follows from that for a listener.
     *
     * @param mixed ...$args extra arguments the row's filter receives after the capability
     */
    public function effective(string $row, mixed ...$args): string
    {
        $stored = $this->stored($row);
        if ($row === 'chat') {
            // No hook here on purpose: the class docblock says which two hooks carry this row.
            return $stored;
        }
        $hook = self::hook($row);
        /**
         * Filters the capability one row of Settings › Access resolves to. `{hook}` is the row with
         * its dots as slashes: `tool/web_fetch`, `tool/summarize`, `tool/draft_post`,
         * `tool/abilities`, `mcp/{server id}`, `settings/read`, `settings/write` and `shortcode`.
         * The value handed in is what the site saved for that row, so a filter here beats the
         * screen. Only a non-empty, non-numeric string is honoured (Capability::filtered()):
         * `__return_true` or a number would become a legacy user-level check, so it is ignored and
         * the stored row stands. The Chat row has no filter here; the menu's
         * `alpaca_bot/admin/menu_capability` and each chat route's `alpaca_bot/capability/{route}`
         * are what filter it, separately.
         *
         * `$args` differs by row rather than being one signature shared by all of them, so a
         * listener has to be registered for the arguments its own row fires with: core slices the
         * argument list to the callback's `accepted_args`, so one that asks for more than its row
         * passes raises an ArgumentCountError when the hook fires.
         *
         * @since 0.6.0
         * @param string $capability the row as the site saved it, its default when it never was
         * @param mixed  ...$args    what the asking surface passes, which is the row's own contract and may be nothing
         */
        return Capability::filtered("alpaca_bot/capability/{$hook}", $stored, ...$args);
    }

    /** Whether code moved this row off what the site saved, which is what the Access tab shows as "set in code". */
    public function overridden(string $row, mixed ...$args): bool
    {
        return $this->effective($row, ...$args) !== $this->stored($row);
    }

    /**
     * Whether `$userId` passes `$row`. user_can() of that id rather than current_user_can(), for
     * the reason DraftPostToolkit gives: the id that is checked is the id the work is done as (the
     * turn's user, a shortcode's viewer), so the check and the act cannot disagree about who is
     * asking.
     */
    public function allows(int $userId, string $row, mixed ...$args): bool
    {
        return (bool) user_can($userId, $this->effective($row, ...$args));
    }

    /**
     * The stored `access.mcp` map, server id => capability, or [] when there is none. Values are
     * not checked here; stored() checks every row's value the same way, whichever key it came from.
     *
     * @return array<array-key, mixed>
     */
    private function mcp(): array
    {
        $map = $this->store->get('access.mcp');
        return is_array($map) ? $map : [];
    }

    /** A row's hook segment: dots as slashes, so `tool.web_fetch` is `tool/web_fetch` and `shortcode` is itself. */
    private static function hook(string $row): string
    {
        return str_replace('.', '/', $row);
    }
}
