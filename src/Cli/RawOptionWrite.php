<?php

declare(strict_types=1);

namespace AlpacaBot\Cli;

use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\ProviderKey;
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
 * Plugin::register() registers this under WP-CLI only, so the settings page and REST never meet
 * it. register() hooks WP-CLI's `before_run_command`, which hands over the command's words, to
 * note whether they are one of those three commands naming this option (namesOption()), and
 * `before_invoke:<command>` of each of the three, which arms it only when they were. It is
 * disarmed again as soon as it has taken a write over, refused one, or left an `add` to core; on
 * the command's `after_invoke:` for a command that ended otherwise (a write of what is stored);
 * and at the next `before_run_command`. A command that WP-CLI itself ends with an error inside
 * `WP_CLI::runcommand()` (a patch path that is not there, say) runs no `after_invoke:` and
 * leaves it armed until then, so the takeover also asks inCommand(): a value is taken over only
 * while WP-CLI's Option_Command update(), patch() or add() is on the call stack, and a write
 * from anywhere else goes through as it is and disarms it. `wp alpaca-bot settings` (which
 * writes through Store itself) never arms it.
 *
 * Armed, it listens on `sanitize_option_alpaca_bot_settings`, which each of the three runs on
 * the value it was handed before it writes: `update` and `patch` call sanitize_option()
 * themselves, `add` through add_option(). Both of the first two also run the stored row through
 * it, to compare; a value identical to the stored row is handed back untouched, so that pass,
 * and a write of exactly what is stored, go on as WP-CLI has them. Any other value is taken over.
 * `update` and `patch` hand over a whole row, so first a key whose value is identical to the
 * stored row's is left out, as Store::set() leaves every other key out: an unchanged secret, a
 * plaintext key on a row ProviderKey::migrate() has not lifted included, then reads as "keep",
 * and "keep" does not survive a move of its URL. In `patch` mode a key the stored row has and the
 * value does not is one `wp option patch delete` removed, and is written as its default, as the
 * delete did before (Store would otherwise keep it); in `update` mode a key left out keeps its
 * value, as it does over REST. Then: a refused MCP row fails the command with the message REST
 * gives and nothing written; otherwise the value is written through Store::replace(), the
 * warnings are printed, then the success line WP-CLI would print, and the command ends there
 * with exit code 0. It has to end there: when a write changed only a secret, Settings\ProviderKey
 * or Mcp\ServerSettings lifts it out of the row, the row equals the stored one, update_option()
 * answers false, and WP-CLI would report "Could not update option" for a write that happened
 * (#4696).
 *
 * By default the end is WP_CLI::halt(0), which throws WP-CLI's ExitException only while its
 * private `$capture_exit` is set, and calls exit() otherwise (class-wp-cli.php, halt(), 2.12).
 * `$capture_exit` is set only by `WP_CLI::runcommand()` with `launch => false` and
 * `exit_error => false`. So a takeover inside `WP_CLI::runcommand('option update
 * alpaca_bot_settings …', ['launch' => false])`, with `exit_error` left at its default of true,
 * ends the whole calling process with exit code 0, and the caller's code after that
 * runcommand() never runs. A top-level `wp option update`, and a runcommand() with
 * `launch => true` (a process of its own), end only the command.
 *
 * `option add` over a row that exists is left alone, so add_option() refuses it as it always
 * has; over none, the write goes through Store as well, and so through the `pre_update_option_*`
 * filters that keep the provider key and the MCP header values out of the row.
 *
 * Apart from register()'s calls to WP_CLI::add_hook(), nothing here calls WP-CLI directly:
 * success, warning, failure and the exit go through injectable callables, whose defaults call
 * WP_CLI.
 *
 * @since 0.6.2
 */
final class RawOptionWrite
{
    /** The WP-CLI commands this arms for, command => mode. */
    public const COMMANDS = ['option update' => 'update', 'option patch' => 'patch', 'option add' => 'add'];

    /** The mode it is armed for, one of COMMANDS' values; null while disarmed. */
    private ?string $mode = null;

    /** Whether the words of the command WP-CLI is about to run name this option (namesOption()). */
    private bool $named = false;

    /** @var \Closure(): bool */
    private \Closure $inCommand;

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
     * @param null|\Closure(): bool        $inCommand whether WP-CLI's option command is running now; inCommand() by default
     */
    public function __construct(
        private Store $store,
        private ServerSettings $servers,
        ?callable $success = null,
        ?callable $warn = null,
        ?callable $fail = null,
        ?callable $halt = null,
        ?\Closure $inCommand = null,
    ) {
        $this->inCommand = $inCommand ?? static fn(): bool => self::inCommand();
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

    /** The WP-CLI hooks (the class docblock). */
    public function register(): void
    {
        \WP_CLI::add_hook('before_run_command', function (mixed $args): void {
            $this->disarm();
            $this->named = is_array($args) && self::namesOption($args);
        });
        foreach (self::COMMANDS as $command => $mode) {
            \WP_CLI::add_hook('before_invoke:' . $command, function () use ($mode): void {
                if ($this->named) {
                    $this->arm($mode);
                }
                $this->named = false;
            });
            \WP_CLI::add_hook('after_invoke:' . $command, function (): void {
                $this->disarm();
            });
        }
    }

    /**
     * Whether a command's words (`$args` as WP-CLI's `before_run_command` hands them) are
     * `option update|set|add alpaca_bot_settings …` or `option patch <action> alpaca_bot_settings …`.
     *
     * @param array<array-key, mixed> $args
     */
    public static function namesOption(array $args): bool
    {
        $args = array_values($args);
        if (($args[0] ?? null) !== 'option') {
            return false;
        }
        $at = match ($args[1] ?? null) {
            'update', 'set', 'add' => 2,
            'patch' => 3,
            default => null,
        };
        return $at !== null && ($args[$at] ?? null) === Plugin::OPTION;
    }

    /** Listens on the option's sanitize filter, for the command `$mode` names. */
    public function arm(string $mode): void
    {
        $this->mode = $mode;
        add_filter('sanitize_option_' . Plugin::OPTION, [$this, 'sanitize']);
    }

    /** Stops listening. */
    public function disarm(): void
    {
        $this->mode = null;
        remove_filter('sanitize_option_' . Plugin::OPTION, [$this, 'sanitize']);
    }

    /** Whether WP-CLI's Option_Command update(), patch() or add() is on the call stack. */
    private static function inCommand(): bool
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Not debug output: the one way to tell WP-CLI's option command from a later write in the same process (the class docblock); runs only while armed, under WP-CLI.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (in_array(($frame['class'] ?? '') . '::' . $frame['function'], ['Option_Command::update', 'Option_Command::patch', 'Option_Command::add'], true)) {
                return true;
            }
        }
        return false;
    }

    /** The takeover (the class docblock). */
    public function sanitize(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($this->mode === null) {
            return $value;
        }
        $row = get_option(Plugin::OPTION);
        if ($value === $row) {
            return $value;
        }
        if (($this->mode === 'add' && $row !== false) || !($this->inCommand)()) {
            $this->disarm();
            return $value;
        }
        $mode = $this->mode;
        if (!is_array($value)) {
            $this->disarm();
            ($this->fail)(sprintf('%s takes a JSON object of settings: pass it with --format=json.', Plugin::OPTION));
            return $value;
        }
        /** @var array<string, mixed> $value */
        $input = self::changed($value, $row, $mode === 'patch');
        $cleared = [];
        if (array_key_exists('toolkits.mcp_servers', $input)) {
            $check = $this->servers->check($input['toolkits.mcp_servers'], $this->store->get('toolkits.mcp_servers'));
            if ($check->isRefused()) {
                $this->disarm();
                ($this->fail)($check->message);
                return $value;
            }
            $cleared = $check->cleared;
        }
        $keyCleared = ProviderKey::clearedByMove($input, $this->store->all());
        // Disarmed before the write: Store::replace()'s update_option() runs sanitize_option() on
        // the value again, and that pass is this write, not a second raw one.
        $this->disarm();
        $this->store->replace($input);
        foreach (ClearedWarnings::lines($cleared, $keyCleared) as $line) {
            ($this->warn)($line);
        }
        ($this->success)(sprintf($mode === 'add' ? "Added '%s' option." : "Updated '%s' option.", Plugin::OPTION));
        ($this->halt)(0);
        return $value;
    }

    /**
     * What the write changes (the class docblock): `$value` without each key whose value is
     * identical to the stored row's, and, when `$deletes`, each settings key the stored row has
     * and `$value` does not, at its default.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function changed(#[\SensitiveParameter] array $value, #[\SensitiveParameter] mixed $row, bool $deletes): array
    {
        $row = is_array($row) ? $row : [];
        $input = array_filter($value, static fn(mixed $v, int|string $key): bool => !array_key_exists($key, $row) || $row[$key] !== $v, ARRAY_FILTER_USE_BOTH);
        if ($deletes) {
            foreach (array_diff_key(array_intersect_key($row, Schema::fields()), $value) as $key => $_) {
                $input[$key] = Schema::fields()[$key]['default'];
            }
        }
        return $input;
    }
}
