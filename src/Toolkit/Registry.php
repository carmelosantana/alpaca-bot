<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Settings\Store;
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
        $filtered = apply_filters('alpaca_bot/toolkits', $subset, $userId);
        $out = [];
        foreach (is_array($filtered) ? $filtered : [] as $id => $toolkit) {
            if (is_string($id) && $toolkit instanceof ToolkitInterface) {
                $out[$id] = $toolkit;
            }
        }
        return $out;
    }
}
