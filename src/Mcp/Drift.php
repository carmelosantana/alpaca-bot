<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * Which of a server's approved tools were last found to no longer match the definition they were
 * approved as: a transient per server, `alpaca_bot_mcp_drift_{id}`, holding the tools' names.
 *
 * The settings screen has to be able to say "changed since approval: review" without opening a
 * connection while a page renders, so the finding is recorded where it is made and read from the
 * marker when the row is drawn. Discovery::tools() records it each time it lists a server for an
 * administrator, rewriting the server's marker or clearing it; a listing that fails leaves the
 * marker as it was. set() is public so that whatever else lists a server records what it finds
 * the same way.
 *
 * A week rather than forever because the marker is a hint about a remote server, not a fact
 * about this site.
 *
 * afterSave() takes names out of it: a save that re-pins a drifted tool (its box ticked again, so
 * the form posted the fingerprint of the definition now shown) or drops its approval has answered
 * the marker for that tool, and the name is taken out. Without it the page would say "changed
 * since approval: review" about a tool the administrator had just approved again.
 *
 * @since 0.6.0
 */
final class Drift
{
    private const PREFIX = 'alpaca_bot_mcp_drift_';

    private const TTL = 604800;

    /**
     * The marker's names for server `$id`, or [] when there is none. Only its strings are read.
     *
     * @return list<string>
     */
    public static function get(string $id): array
    {
        $stored = get_transient(self::PREFIX . $id);
        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /** @param list<string> $names the approved tools whose definition no longer matches; [] clears the marker */
    public static function set(string $id, array $names): void
    {
        if ($names === []) {
            delete_transient(self::PREFIX . $id);
            return;
        }
        set_transient(self::PREFIX . $id, $names, self::TTL);
    }

    /**
     * `update_option_alpaca_bot_settings`: for each server `$old` lists, takes out of its marker
     * every name whose pin `$value` does not hold unchanged, and so clears the marker of a server
     * `$value` no longer lists. It reads one marker per server `$old` lists, and writes one only
     * when a name comes out. A value that is not the settings array lists no servers.
     */
    public static function afterSave(mixed $old, mixed $value): void
    {
        $after = self::pins($value);
        foreach (self::pins($old) as $id => $before) {
            $names = self::get($id);
            $kept = array_values(array_filter(
                $names,
                static fn(string $name): bool => isset($after[$id][$name], $before[$name]) && $after[$id][$name] === $before[$name],
            ));
            if ($kept !== $names) {
                self::set($id, $kept);
            }
        }
    }

    /**
     * Server id => its `approved` map, for each row of the settings array's `toolkits.mcp_servers`
     * that has a string id.
     *
     * @return array<string, array<array-key, mixed>>
     */
    private static function pins(mixed $settings): array
    {
        $rows = is_array($settings) ? ($settings['toolkits.mcp_servers'] ?? null) : null;
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $out[$row['id']] = is_array($row['approved'] ?? null) ? $row['approved'] : [];
            }
        }
        return $out;
    }
}
