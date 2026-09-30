<?php

declare(strict_types=1);

namespace AlpacaBot\Cli;

use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * A raw `wp option update|patch|add alpaca_bot_settings` written as `wp alpaca-bot settings`
 * writes (Kanboard #4695, #4696): through Store, so Schema::sanitize() cleans the value over the
 * stored settings (#4539's rule that a `provider.base_url` move clears the key included), with
 * ServerSettings::check() refusing a `toolkits.mcp_servers` row it cannot keep, and a warning when
 * a move cleared a secret (ClearedWarnings).
 *
 * The option's sanitize callback is registered on `admin_init` only (Admin\SettingsPage), which
 * WP-CLI never fires, so without this those three commands store whatever they are handed.
 * Plugin::register() registers this under WP-CLI only; register() arms it through WP-CLI's
 * `before_invoke:<command>` hook for those three commands and no others, so
 * `wp alpaca-bot settings` (which writes through Store itself), the settings page and REST never
 * meet it.
 *
 * Armed, it listens on `sanitize_option_alpaca_bot_settings`, which each of the three runs on
 * the value it was handed before it writes: `update` and `patch` call sanitize_option()
 * themselves, `add` through add_option(). Both of the first two also run the stored row through
 * it, to compare; a value identical to the stored row is handed back untouched, so that pass,
 * and a write of exactly what is stored, go on as WP-CLI has them. Any other value is taken over:
 * a refused MCP row fails the command with the message REST gives and nothing written; otherwise
 * the value is written through Store::replace(), the warnings are printed, then the success line
 * WP-CLI would print, and the command ends there with exit code 0. It has to end there: when a
 * write changed only a secret, Settings\ProviderKey or Mcp\ServerSettings lifts it out of the
 * row, the row equals the stored one, update_option() answers false, and WP-CLI would report
 * "Could not update option" for a write that happened (#4696).
 *
 * `option add` over a row that exists is left alone, so add_option() refuses it as it always
 * has; over none, the write goes through Store as well, and so through the `pre_update_option_*`
 * filters that keep the provider key and the MCP header values out of the row.
 *
 * Nothing here touches WP-CLI itself: success, warning, failure and the exit go through
 * injectable callables, whose defaults call WP_CLI.
 *
 * @since 0.6.2
 */
final class RawOptionWrite
{
    /** The WP-CLI commands this arms for, command => mode. */
    public const COMMANDS = ['option update' => 'update', 'option patch' => 'patch', 'option add' => 'add'];

    /** The mode it is armed for, one of COMMANDS' values; null until armed. */
    private ?string $mode = null;

    /** True while its own Store::replace() is writing, whose update_option() runs the filter again. */
    private bool $writing = false;

    /** @var callable(string): void */
    private $success;

    /** @var callable(string): void */
    private $warn;

    /** @var callable(string): void */
    private $fail;

    /** @var callable(int): void */
    private $halt;

    /**
     * @param callable(string): void|null $success prints the success line
     * @param callable(string): void|null $warn    prints a warning and goes on
     * @param callable(string): void|null $fail    reports an error and, under WP-CLI, ends the command with exit code 1
     * @param callable(int): void|null    $halt    ends the command with the given exit code
     */
    public function __construct(
        private Store $store,
        private ServerSettings $servers,
        ?callable $success = null,
        ?callable $warn = null,
        ?callable $fail = null,
        ?callable $halt = null,
    ) {
        $this->success = $success ?? static function (string $message): void {
            \WP_CLI::success($message);
        };
        $this->warn = $warn ?? static function (string $message): void {
            \WP_CLI::warning($message);
        };
        $this->fail = $fail ?? static function (string $message): void {
            \WP_CLI::error($message);
        };
        $this->halt = $halt ?? static function (int $code): void {
            \WP_CLI::halt($code);
        };
    }

    /** Arms arm() on WP-CLI's `before_invoke:` hook of each of COMMANDS. */
    public function register(): void
    {
        foreach (self::COMMANDS as $command => $mode) {
            \WP_CLI::add_hook('before_invoke:' . $command, function () use ($mode): void {
                $this->arm($mode);
            });
        }
    }

    /** Listens on the option's sanitize filter, for the command `$mode` names. */
    public function arm(string $mode): void
    {
        $this->mode = $mode;
        add_filter('sanitize_option_' . Plugin::OPTION, [$this, 'sanitize']);
    }

    /** The takeover (the class docblock). */
    public function sanitize(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($this->writing || $this->mode === null) {
            return $value;
        }
        $row = get_option(Plugin::OPTION);
        if ($value === $row || ($this->mode === 'add' && $row !== false)) {
            return $value;
        }
        if (!is_array($value)) {
            ($this->fail)(sprintf('%s takes a JSON object of settings: pass it with --format=json.', Plugin::OPTION));
            return $value;
        }
        /** @var array<string, mixed> $value */
        $cleared = [];
        if (array_key_exists('toolkits.mcp_servers', $value)) {
            $check = $this->servers->check($value['toolkits.mcp_servers'], $this->store->get('toolkits.mcp_servers'));
            if ($check->isRefused()) {
                ($this->fail)($check->message);
                return $value;
            }
            $cleared = $check->cleared;
        }
        $keyCleared = Schema::providerKeyClearedByMove($value, $this->store->all());
        $this->writing = true;
        try {
            $this->store->replace($value);
        } finally {
            $this->writing = false;
        }
        foreach (ClearedWarnings::lines($cleared, $keyCleared) as $line) {
            ($this->warn)($line);
        }
        ($this->success)(sprintf($this->mode === 'add' ? "Added '%s' option." : "Updated '%s' option.", Plugin::OPTION));
        ($this->halt)(0);
        return $value;
    }
}
