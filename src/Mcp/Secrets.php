<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Settings\Schema;

/**
 * Where an MCP server's header value is kept: `alpaca_bot_mcp_secrets`, server id => value, an
 * option of its own written with autoload off.
 *
 * A second option rather than the row itself because `alpaca_bot_settings` is autoloaded.
 * Store::replace() and core's options.php, which the settings page saves through, both call
 * update_option() with no `$autoload`, so a save with no row yet (a site's first, or the first
 * after the row was deleted) takes the add_option() branch with none,
 * wp_determine_option_autoload_value() answers `auto` for an option under core's 150000-byte
 * `wp_max_autoloaded_option_size`, and the whole option is in `alloptions` on every request,
 * front end included. A header value put there would be too. The provider API key is kept out of
 * it the same way since 0.6.1, in an option of its own (Settings\ProviderKey). This option is read
 * only when something asks for a value: at most one query in a request that asks, and none in one
 * that does not.
 *
 * The settings row carries Schema::MASK when a value is kept here and '' when none is.
 * ServerSettings::beforeSave() writes both: this option from inside the settings option's
 * update_option(), before core writes the row, so a write of the row that then fails in the
 * database leaves this option one save ahead of it.
 *
 * @since 0.6.0
 */
final class Secrets
{
    public const OPTION = 'alpaca_bot_mcp_secrets';

    /**
     * Every kept value, by server id. Only a string value under a string id is read; anything
     * else in the option is not a value this class wrote.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $id => $value) {
            if (is_string($id) && is_string($value)) {
                $out[$id] = $value;
            }
        }
        return $out;
    }

    /** The value kept for server `$id`, or '' when there is none. */
    public static function get(string $id): string
    {
        return self::all()[$id] ?? '';
    }

    /**
     * Replaces every kept value with `$map`, autoload off. An empty map deletes the option, so a
     * site with no header value has no row.
     *
     * @param array<string, string> $map server id => value
     */
    public static function put(#[\SensitiveParameter] array $map): void
    {
        if ($map === []) {
            delete_option(self::OPTION);
            return;
        }
        update_option(self::OPTION, $map, false);
    }

    /**
     * A `toolkits.mcp_servers` row's header value with the mask swapped for the value kept under
     * the row's id: what a caller hands ServerConfig::fromSettings() in the row's place. '' stays
     * '', and a value the row carries itself is that value. A row carries one only when it was
     * written round Mcp\ServerSettings (add_option() on its own, a hand edit, a write while the
     * plugin was inactive) or handed to a Store built with its settings in hand; Store's memo
     * after a write holds what the filter left, the mask.
     *
     * @param array<string, mixed> $row
     */
    public static function resolve(#[\SensitiveParameter] array $row): string
    {
        $value = $row['header_value'] ?? '';
        if ($value === Schema::MASK) {
            return is_string($row['id'] ?? null) ? self::get($row['id']) : '';
        }
        return is_string($value) ? $value : '';
    }
}
