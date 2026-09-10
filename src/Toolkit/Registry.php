<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * Every toolkit the plugin built, by id, and the subset a turn may use.
 *
 * Two gates, in order. The `toolkits.enabled` setting names the built-ins an administrator has
 * switched on; it is a checkbox list over the ids Schema knows, so it can never name a toolkit
 * the plugin did not ship (Schema::coerce() drops anything else). Then filter
 * `alpaca_bot/toolkits` (array<string, ToolkitInterface>, int $userId) runs over that subset,
 * and it is the one extension point: a site adds its own toolkit there, or takes one away for
 * one user. Registering a third-party toolkit here and expecting the setting to show it would
 * not work, and that is by design: the settings page is the plugin's, and a toolkit a site adds
 * in code is the site's to gate in code.
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

    public function __construct(private Store $store) {}

    public function register(string $id, ToolkitInterface $toolkit): void
    {
        $this->toolkits[$id] = $toolkit;
    }

    /** @return list<string> every registered id, enabled or not, in registration order */
    public function ids(): array
    {
        return array_keys($this->toolkits);
    }

    /**
     * The toolkits this user's turn may use: the registered ones the setting enables, through
     * `alpaca_bot/toolkits`.
     *
     * @return array<string, ToolkitInterface>
     */
    public function enabled(int $userId): array
    {
        $setting = $this->store->get('toolkits.enabled', []);
        $enabled = is_array($setting) ? $setting : [];
        $subset = array_filter($this->toolkits, static fn(string $id): bool => in_array($id, $enabled, true), ARRAY_FILTER_USE_KEY);
        /**
         * Filters the toolkits a user's turn may call, after the `toolkits.enabled` setting has
         * chosen among the built-ins. The one way to give the model a toolkit the plugin did not
         * ship (add `id => ToolkitInterface`), or to take one away for a user or a role. A toolkit
         * added here never appears in Settings; gating it is the site's job, in code. An entry whose
         * key is not a string or whose value is not a ToolkitInterface is dropped, and a return that
         * is not an array enables nothing.
         *
         * This filter is also, today, the only capability gate over the tools. enabled() has none
         * of its own, so a site that opens `alpaca_bot/capability/chat` to a role hands that role
         * every enabled toolkit — `web_fetch`, an outbound-request primitive, included; only
         * `draft_post` re-checks a capability. `user_can($userId, …)` here is how a site keeps a
         * tool away from the users it admitted only to converse. A floor of enabled()'s own is a
         * later 0.x release.
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
