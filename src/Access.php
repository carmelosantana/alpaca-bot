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
 * where hook() turns the row's dots into slashes (`tool.web_fetch` is `tool/web_fetch`). The two
 * settings rows run one more, first: `alpaca_bot/capability/settings`, which in 0.5 was the one
 * key over both verbs of `/settings`, applied to the stored row so that a site which filtered it
 * keeps what it set and the row's own key still gets the last word. No other row runs a second
 * hook here. What follows the capability in a row filter's signature is the *caller's*, not
 * this class's: each
 * surface passes what it knows, and those differ row by row (a REST request, a user id, a post id
 * and a shortcode tag, or nothing at all). A listener must therefore declare — and register
 * `accepted_args` for — the arguments its own row fires with: core slices the argument list to
 * `accepted_args` and calls the listener with what it has, so a callback that expects more
 * arguments than its row passes raises an ArgumentCountError when the hook fires.
 *
 * That is the half of the contract that crashes, so this class owns it too: ARGS fixes how many
 * arguments each row fires with, expectedArgs() reads it, effective() refuses a caller that
 * passes fewer, and overridden() — the question a screen asks — answers rather than fatals when
 * a listener cannot be called. The *values* are the asking surface's; the *count* is this
 * class's, and a site registering a listener can rely on it.
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

    /**
     * The row-name prefix whose rows run 0.5's `alpaca_bot/capability/settings` before their own
     * key: `settings.read` and `settings.write`. Private because it is not a name any caller
     * needs — it marks which rows carry that one extra hook, and effective() is the only place
     * that has to know.
     */
    private const SETTINGS_PREFIX = 'settings.';

    /**
     * Row => how many arguments its filter is called with after the capability, and so how many
     * a caller of effective() must pass. Every row is listed, `0` included, because a row that
     * fires with nothing is a decision and not an omission.
     *
     * What those arguments are: the four tool rows the turn's user id; an `mcp.<server id>` row
     * the same (MCP_ARGS); the two settings rows the WP_REST_Request being authorised; the
     * shortcode row the post id it is rendering in and the shortcode tag. `chat` is `0` because
     * it has no filter here at all.
     *
     * A row this class does not list requires nothing, which is the only honest answer for a row
     * whose contract nobody declared; such a row also resolves to UNLISTED, so it is not a path
     * anything is meant to enforce on.
     *
     * @var array<string, int>
     */
    private const ARGS = [
        'chat' => 0,
        'tool.web_fetch' => 1,
        'tool.summarize' => 1,
        'tool.draft_post' => 1,
        'tool.abilities' => 1,
        'settings.read' => 1,
        'settings.write' => 1,
        'shortcode' => 2,
    ];

    /** What an `mcp.<server id>` row's filter is called with: the turn's user id, as the tool rows are. */
    private const MCP_ARGS = 1;

    /** What a row defaults() does not list resolves to. */
    private const UNLISTED = 'manage_options';

    public function __construct(private Store $store) {}

    /**
     * Row => the capability it starts at. These reproduce 0.5 exactly where 0.5 had an answer:
     * chat is the menu's and the chat routes' `edit_posts`, and the shortcode is the
     * `edit_posts` Shortcodes\Chat asked of a viewer before this row existed, so a site that
     * never opens this tab sees no change. Both settings rows are the `manage_options` those
     * routes already declare, and the abilities toolkit is new in 0.6 and starts closed at
     * `manage_options`.
     *
     * The rows of the three tools 0.5 shipped (web_fetch, summarize, draft_post) are new gates,
     * not new defaults: 0.5 had nothing between "may chat" and "may call the enabled tools"
     * (`alpaca_bot/toolkits` was the only lever), so they start at chat's own `edit_posts`. A
     * site whose chat is still at that capability therefore sees no change. One that opened the
     * chat lower — a lowered Chat row, or
     * `alpaca_bot/capability/chat` in code — keeps the chat and loses the tools until it lowers a
     * tool row to match: that is Toolkit\Registry::enabled()'s floor, and it is the one behaviour
     * change these defaults carry.
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
     * How many arguments follow the capability when `$row`'s filter fires: what a caller of
     * effective() has to pass, and what a listener on that row may be registered for.
     *
     * An enforcement caller does not need this. It knows its own row and its own arguments, so
     * it passes them and lets effective() be the one that checks; branching on this number in a
     * path that has the arguments only adds a second place to be wrong about the count. It is
     * here for the opposite case — a surface that may not be able to supply a row's arguments
     * and has to know that before it asks, which is what overridden() does with it.
     */
    public static function expectedArgs(string $row): int
    {
        return str_starts_with($row, self::MCP_PREFIX) ? self::MCP_ARGS : (self::ARGS[$row] ?? 0);
    }

    /**
     * The capability `$row` resolves to for this call, code included: the stored row through its
     * filter. `$args` are passed on to the filter, so a surface hands it what it knows (the turn's
     * user id, the post and tag a shortcode is rendering in, the REST request being authorised).
     * What a row passes is that row's own contract, not a shape shared by all of them; ARGS is
     * how many, and the class docblock says what follows from that for a listener.
     *
     * A caller that passes fewer than expectedArgs() throws rather than being tolerated. This is
     * the enforcement path: an enforcement caller is about to decide whether somebody may do
     * something, and it knows its own arguments, so a short call is a programming
     * error and nothing else. Falling back to the stored value instead would throw away whatever
     * the site's filter had to say — including a filter that *tightens* the row — and hand back
     * a looser capability than the site asked for, with no symptom. A fatal in development is
     * the cheap end of that trade; overridden() is where the loud version is not wanted, and it
     * is guarded there and only there. Extra arguments are passed on untouched, so a row may
     * grow one without breaking its callers.
     *
     * @param mixed ...$args extra arguments the row's filter receives after the capability
     * @throws \InvalidArgumentException when fewer than expectedArgs() arguments are passed
     */
    public function effective(string $row, mixed ...$args): string
    {
        $stored = $this->stored($row);
        if ($row === 'chat') {
            // No hook here on purpose: the class docblock says which two hooks carry this row.
            return $stored;
        }
        $expected = self::expectedArgs($row);
        if (count($args) < $expected) {
            throw new \InvalidArgumentException(sprintf(
                'The %s access row fires with %d argument(s) after the capability and was given %d: a listener registered for that row would be called with fewer than it declared and raise an ArgumentCountError.',
                $row,
                $expected,
                count($args),
            ));
        }
        if (str_starts_with($row, self::SETTINGS_PREFIX)) {
            /**
             * Filters the capability both settings rows start from: 0.5's one key for the settings
             * routes, kept as the default the newer keys receive so a site that filtered it keeps
             * what it set. It runs over the stored row, and `alpaca_bot/capability/settings/read` or
             * `…/settings/write` runs over what it returns, so the newer key wins where both are
             * set. Since 0.6 it covers `GET /settings/schema` too, which 0.5 filtered under its own
             * `alpaca_bot/capability/settings/schema` key; that key is no longer applied. One key
             * over both verbs is why the rows exist: tighten the write with `…/settings/write`
             * rather than here, or off the WP_REST_Request's `get_method()`.
             *
             * Settings › Access fires it too, to show whether code has moved either row, with a
             * `GET` or a `PUT /settings` request it builds for the question. That request
             * authorises nothing and no client sent it.
             *
             * @since 0.5.0
             * @param string $capability the `settings.read` or `settings.write` row, `manage_options` by default
             * @param mixed  ...$args    the WP_REST_Request being authorised, or one Settings › Access built to ask
             */
            $stored = Capability::filtered('alpaca_bot/capability/settings', $stored, ...$args);
        }
        $hook = self::hook($row);
        /**
         * Filters the capability one row of Settings › Access resolves to. `{hook}` is the row with
         * its dots as slashes: `tool/web_fetch`, `tool/summarize`, `tool/draft_post`,
         * `tool/abilities`, `mcp/{server id}`, `settings/read`, `settings/write` and `shortcode`.
         * The value handed in is what the site saved for that row, so a filter here beats the
         * screen — with one exception: the two settings rows are handed what
         * `alpaca_bot/capability/settings`, 0.5's one key for those routes, made of the stored row,
         * so this key runs last there and wins over that one too. Only a non-empty, non-numeric
         * string is honoured (Capability::filtered()):
         * `__return_true` or a number would become a legacy user-level check, so it is ignored and
         * the stored row stands. The Chat row has no filter here; the menu's
         * `alpaca_bot/admin/menu_capability` and each chat route's `alpaca_bot/capability/{route}`
         * are what filter it, separately.
         *
         * `$args` differs by row rather than being one signature shared by all of them, so a
         * listener has to be registered for the arguments its own row fires with: core slices the
         * argument list to the callback's `accepted_args`, so one that asks for more than its row
         * passes raises an ArgumentCountError when the hook fires. `Access::expectedArgs($row)` is
         * how many each row fires with, and it is fixed: the tool rows and an `mcp/{server id}`
         * row pass the turn's user id, the settings rows the WP_REST_Request, `shortcode` the
         * post id and the shortcode tag. A caller that passes fewer is refused before the filter
         * runs, so a listener registered for its row's arguments is always called with them.
         * Settings › Access also fires each of these rows it shows, to say which code has moved, with
         * arguments of the same shape built for that screen: the administrator viewing it as the
         * user, a request built for the route it authorises, post id 0 and the `alpacabot` tag.
         *
         * @since 0.6.0
         * @param string $capability the row as the site saved it, its default when it never was
         * @param mixed  ...$args    what the asking surface passes: Access::expectedArgs() of them, the row's own contract, and none at all for a row that declares none
         */
        return Capability::filtered("alpaca_bot/capability/{$hook}", $stored, ...$args);
    }

    /**
     * Whether code moved this row off what the site saved.
     *
     * True means the screen is not the whole story about this row, which is what the question is
     * for. Three ways it gets there: a listener changed the value; a listener exists that this
     * call site cannot supply the arguments for; or resolving the row threw, which the catch
     * below is about. False says only that nothing moved the row *here*, which for a row with a
     * filter of its own is the whole story.
     *
     * `chat` is the row it is not. It has no filter here (effective() returns the stored value
     * before any hook), so this comparison is always false for it — on a site that names a
     * capability in code as much as on one that does not. What filters that capability is the
     * menu's `alpaca_bot/admin/menu_capability` and each chat route's
     * `alpaca_bot/capability/{route}`, separately, so a screen wanting to know whether the Chat
     * row is set in code has to ask those surfaces, one at a time. This method cannot answer it
     * and does not pretend to.
     *
     * The second case is why this is the guarded one, and the only one. It is the question a
     * screen asks, and a screen must not be fatal — but a screen also may not have a row's
     * arguments (one with no post in hand has no post id for the shortcode row), and effective()
     * refuses a short argument list before any filter runs. Asking has_filter() first is what
     * keeps that from becoming a blanket answer: with no listener registered nothing can have
     * moved the row, so it is false, and the label does not appear on every site in the world;
     * with one registered the honest answer is that code has a say here and this call cannot
     * find out what it is. A settings row has two hooks — `alpaca_bot/capability/settings` runs
     * before its own — and both are asked, or a site still filtering 0.5's key would be told its
     * settings rows are the screen's alone. Counting a listener that leaves the row where it was
     * is the cost —
     * one that returns the stored value, and equally one whose return Capability::filtered()
     * discards (a bool, a number, '', null, an array), which lands on the stored value too. That
     * is the price of not running the filter, and it is particular to this branch: nothing can
     * tell a filter that changes nothing from an absent one without calling it, and this is the
     * one path that cannot call it. Given the arguments, both read false, correctly.
     *
     * The catch is the rest: a listener that throws, one registered with more `accepted_args`
     * than its row fires with (core raises an ArgumentCountError), and anything else thrown
     * under this call, `stored()`'s own read of the option included — a site's
     * `pre_option_alpaca_bot_settings` listener can throw, and then every row would read as
     * overridden with no other symptom. So it is `\Throwable`, deliberately wide, and it leaves
     * a line in the debug log behind WP_DEBUG: a security-relevant label that flips because of
     * an unrelated plugin's exception should not do it silently. True rather than a third state,
     * so the return stays a plain bool no caller can forget to unpack.
     */
    public function overridden(string $row, mixed ...$args): bool
    {
        try {
            if (count($args) < self::expectedArgs($row)) {
                // The prefix is spelled again rather than shared with effective()'s: a hook name
                // handed to Capability::filtered() has to be a string literal or bin/hooks-doc.php
                // fails the run, so that one cannot be built from a constant, and a constant used
                // only here would be the odd half of a pair.
                //
                // Both of a settings row's hooks are asked, because either can move it and this
                // branch is the one a screen takes for those rows: the settings rows fire with a
                // WP_REST_Request, which no admin screen has.
                return (bool) has_filter('alpaca_bot/capability/' . self::hook($row))
                    || (str_starts_with($row, self::SETTINGS_PREFIX) && (bool) has_filter('alpaca_bot/capability/settings'));
            }
            return $this->effective($row, ...$args) !== $this->stored($row);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate diagnostic, gated on WP_DEBUG as core's own logging is; this is the only record left, since the answer returned to the screen is a bool with nowhere to carry a reason.
                error_log(sprintf('[alpaca-bot] resolving the %s access row threw, so it is reported as set in code: %s', $row, $e->getMessage()));
            }
            return true;
        }
    }

    /**
     * Whether `$userId` passes `$row`. user_can() of that id rather than current_user_can(), for
     * the reason DraftPostToolkit gives: the id that is checked is the id the work is done as (the
     * turn's user, a shortcode's viewer), so the check and the act cannot disagree about who is
     * asking. An enforcement path, so it inherits effective()'s refusal of a short argument list
     * rather than guarding it.
     *
     * @throws \InvalidArgumentException when fewer than expectedArgs() arguments are passed
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
