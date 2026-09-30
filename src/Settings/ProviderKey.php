<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

use AlpacaBot\Plugin;

/**
 * Where the provider API key is kept: `alpaca_bot_provider_key`, an option of its own written
 * with autoload off, for the reason Mcp\Secrets gives for an MCP header value. The settings row
 * is autoloaded, so a key kept in it would be in `alloptions` on every request, front end
 * included; this option is read only when something asks for the key.
 *
 * The settings row carries Schema::MASK when a key is kept here and '' when none is.
 * beforeSave() writes both, on `pre_update_option_alpaca_bot_settings`, which core applies to
 * every update_option() of the row whoever calls it (the settings page, the REST route, Store,
 * which `wp option update|patch|add` write through: Cli\RawOptionWrite) before the branch that
 * turns a site's first save into add_option(). A posted
 * MASK, or a value that is not a string, keeps the held key; '' deletes the option; any other
 * string replaces it. This option is written from inside the row's update_option(), before core
 * writes the row, as Mcp\Secrets is. add_option() called on its own (from code; `wp option add`
 * writes through Store instead) runs no `pre_update_option_*` filter, so a key written that way
 * stays in the row until migrate() lifts it on the next request.
 *
 * Everything that needs the key itself asks resolve() with what the row holds: the provider
 * factory (the one sender), the REST route's reveal and `wp alpaca-bot settings provider.api_key`.
 * A row still carrying a plaintext key, from a site migrate() has not reached yet, resolves to that
 * key, so no request between an upgrade and the migration loses it.
 *
 * @since 0.6.1
 */
final class ProviderKey
{
    public const OPTION = 'alpaca_bot_provider_key';

    public function register(): void
    {
        add_filter('pre_update_option_' . Plugin::OPTION, [$this, 'beforeSave'], 10, 2);
    }

    /**
     * The key kept in the option, or '' when there is none. A value that is not a string, or is
     * MASK, is not one this class wrote, and reads as none: resolve() never answers MASK as a key.
     */
    public static function held(): string
    {
        $key = get_option(self::OPTION, '');
        return is_string($key) && $key !== Schema::MASK ? $key : '';
    }

    /**
     * The key a row's `provider.api_key` stands for: the held key for MASK, the value itself for
     * any other string ('' included), '' for anything that is not a string.
     */
    public static function resolve(#[\SensitiveParameter] mixed $stored): string
    {
        if ($stored === Schema::MASK) {
            return self::held();
        }
        return is_string($stored) ? $stored : '';
    }

    /** Stores `$key`, autoload off; '' deletes the option, so a site with no key has no row. */
    public static function put(#[\SensitiveParameter] string $key): void
    {
        if ($key === '') {
            delete_option(self::OPTION);
            return;
        }
        update_option(self::OPTION, $key, false);
    }

    /**
     * The lift (the class docblock). `$value` is what is about to be written and `$old` what is
     * stored, as core hands them to the filter; a `$value` that is not an array is handed back as
     * it came. A row that leaves the key out says there is none, since a read merges the ''
     * default over it. "Keep" with nothing held keeps a plaintext key `$old` still carries: a raw
     * write of the mask over a row migrate() has not reached. The option is written only when
     * the key changes.
     */
    public function beforeSave(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] mixed $old): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $held = self::held();
        $posted = array_key_exists('provider.api_key', $value) ? $value['provider.api_key'] : '';
        $key = match (true) {
            $posted === Schema::MASK, !is_string($posted) => $held !== '' ? $held : (self::pending($old) ? (string) $old['provider.api_key'] : ''),
            default => $posted,
        };
        if ($key !== $held) {
            self::put($key);
        }
        $value['provider.api_key'] = $key === '' ? '' : Schema::MASK;
        return $value;
    }

    /**
     * Whether a settings row still carries its key in plaintext: its `provider.api_key` is a
     * string other than '' and MASK. The row is autoloaded, so asking costs no query.
     *
     * @phpstan-assert-if-true array{'provider.api_key': non-empty-string} $row
     */
    public static function pending(#[\SensitiveParameter] mixed $row): bool
    {
        $key = is_array($row) ? ($row['provider.api_key'] ?? '') : '';
        return is_string($key) && $key !== '' && $key !== Schema::MASK;
    }

    /**
     * The one-time move from a row written before 0.6.1: the plaintext key into the option, then
     * the row rewritten with MASK. Plugin::register() runs it on `init`; a row that is not pending
     * is left alone, so a second run writes nothing. The row is rewritten only once the option
     * holds the key, so a failed write of either leaves the plaintext in the row, which resolve()
     * still reads and the next request moves again.
     */
    public static function migrate(): void
    {
        $row = get_option(Plugin::OPTION);
        if (!self::pending($row)) {
            return;
        }
        self::put($row['provider.api_key']);
        if (self::held() !== $row['provider.api_key']) {
            return;
        }
        $row['provider.api_key'] = Schema::MASK;
        update_option(Plugin::OPTION, $row);
    }
}
