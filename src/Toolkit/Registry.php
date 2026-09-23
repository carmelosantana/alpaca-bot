<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Access;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * Every toolkit the plugin built, by id, and the subset a turn may use.
 *
 * Three gates, in order. The `toolkits.enabled` setting names the built-ins an administrator has
 * switched on; it is a checkbox list over the ids Schema knows, so it can never name a toolkit
 * the plugin did not ship (Schema::coerce() drops anything else). Then the floor: a toolkit is
 * kept only for a user who passes its Settings › Access row (Access::allows(), `tool.{id}` unless
 * register() was given another), asked with user_can() of the id the turn runs as rather than
 * current_user_can(), for the reason DraftPostToolkit gives — the id that is checked is the id
 * the tool would act as, so the check and the act cannot disagree about who is asking. Then
 * filter `alpaca_bot/toolkits` (array<string, ToolkitInterface>, int $userId) runs over what is
 * left, and it is the one extension point: a site adds its own toolkit there, or takes one away
 * for one user. Registering a third-party toolkit here and expecting the setting to show it would
 * not work, and that is by design: the settings page is the plugin's, and a toolkit a site adds
 * in code is the site's to gate in code. The floor runs before the filter on purpose: the filter
 * can still hand a toolkit back for a user, which is how a site gives a tool to a role no row
 * covers, and it can still take one away.
 *
 * The filter's return is held to the contract the way Plugin::controllers() holds its filter:
 * an entry that is not a toolkit, or whose key is not a string id, is dropped rather than left
 * for the agent to fatal on, and a return that is not an array enables nothing. A stored setting
 * that is not a list enables nothing either: Store hands back what is stored, not what the
 * schema would make of it, and a corrupt row must fail closed, not switch every tool on.
 *
 * Nothing here is resolved at boot: register() only records, and enabled() reads the option
 * when a turn asks, so a setting saved during the request is seen by the next call.
 *
 * tool() is the one way a surface other than the model runs a toolkit's tool directly (the
 * abilities, the `[alpacabot_agent]` shim): by name, out of whatever enabled() handed back
 * under the toolkit's id, so what runs is the instance the model would have run, filter
 * included. The refusal when the tool is missing is each caller's own to word.
 *
 * @since 0.5.0
 */
final class Registry
{
    /** @var array<string, ToolkitInterface> in registration order; a second register() of an id replaces in place */
    private array $toolkits = [];

    /** @var array<string, string> id => the Settings › Access row that gates it */
    private array $rows = [];

    private Access $access;

    public function __construct(private Store $store, ?Access $access = null)
    {
        $this->access = $access ?? new Access($store);
    }

    /**
     * `$row` is the Settings › Access row enabled() holds this toolkit to, `tool.{id}` unless a
     * caller names another (the MCP toolkits are `mcp.{id}`). It is the registrar's, not the
     * id's, so a toolkit registered under a display id is still gated by the row an administrator
     * sees. A row Access::defaults() does not list reads as `manage_options`, so a toolkit
     * registered without one fails closed.
     */
    public function register(string $id, ToolkitInterface $toolkit, ?string $row = null): void
    {
        $this->toolkits[$id] = $toolkit;
        $this->rows[$id] = $row ?? 'tool.' . $id;
    }

    /** @return list<string> every registered id, enabled or not, in registration order */
    public function ids(): array
    {
        return array_keys($this->toolkits);
    }

    /**
     * The toolkits this user's turn may use: the registered ones the setting enables and this
     * user's Access rows allow, through `alpaca_bot/toolkits`.
     *
     * The setting is tested first, so a switched-off toolkit costs no capability lookup, and the
     * whole floor runs before the filter.
     *
     * @return array<string, ToolkitInterface>
     */
    public function enabled(int $userId): array
    {
        $setting = $this->store->get('toolkits.enabled', []);
        $enabled = is_array($setting) ? $setting : [];
        $subset = array_filter(
            $this->toolkits,
            fn(string $id): bool => in_array($id, $enabled, true) && $this->access->allows($userId, $this->rows[$id], $userId),
            ARRAY_FILTER_USE_KEY,
        );
        /**
         * Filters the toolkits a user's turn may call, after the `toolkits.enabled` setting has
         * chosen among the built-ins. The one way to give the model a toolkit the plugin did not
         * ship (add `id => ToolkitInterface`), or to take one away for a user or a role. A toolkit
         * added here never appears in Settings; gating it is the site's job, in code. An entry whose
         * key is not a string or whose value is not a ToolkitInterface is dropped, and a return that
         * is not an array enables nothing.
         *
         * It is no longer the only capability gate over the tools: enabled() has already dropped
         * every toolkit whose Settings › Access row this user fails
         * (`alpaca_bot/capability/tool/{id}`: `edit_posts` by default for `web_fetch`,
         * `summarize` and `draft_post`, `manage_options` for `abilities`), so a site that opens
         * `alpaca_bot/capability/chat` to a role hands that role the chat and no tools until a
         * row admits them. What this filter is, is the last word: a toolkit added here
         * has no row and no Settings entry, so `user_can($userId, …)` here is the site's own gate
         * over it, and a toolkit taken away here is gone whatever its row says.
         *
         * @since 0.5.0
         * @param array<string, ToolkitInterface> $toolkits the enabled built-ins, by id
         * @param int                             $userId   the user whose turn it is
         * @var mixed $filtered what the filter returned, checked before it is trusted
         */
        $filtered = apply_filters('alpaca_bot/toolkits', $subset, $userId);
        $out = [];
        foreach (is_array($filtered) ? $filtered : [] as $id => $toolkit) {
            if (is_string($id) && $toolkit instanceof ToolkitInterface) {
                $out[$id] = $toolkit;
            }
        }
        return $out;
    }

    /**
     * The tool named `$name` in `$toolkit`, or null when it has none. By name rather than
     * position, so a toolkit that grows a second tool keeps working; the last one of that
     * name wins, as it does in the agent's own index.
     */
    public static function tool(ToolkitInterface $toolkit, string $name): ?ToolInterface
    {
        $found = null;
        foreach ($toolkit->tools() as $tool) {
            if ($tool->name() === $name) {
                $found = $tool;
            }
        }
        return $found;
    }
}
