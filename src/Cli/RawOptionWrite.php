<?php

declare(strict_types=1);

namespace AlpacaBot\Cli;

use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\ProviderKey;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Settings\Writer;

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
 * it. register() arms it on `before_invoke:<command>` of each of the three (`option set` is
 * `option update` by another name), whatever option the command's words name: the name can come
 * from a `--prompt` answer or a wp-cli.yml default instead of the command line, so the words are
 * not asked. It is disarmed again as soon as it has taken a write over, refused one, or left one
 * to core; on the command's `after_invoke:` for a command that ended otherwise (a write of what
 * is stored); and at the next `before_run_command`. A command that WP-CLI itself ends with an
 * error inside `WP_CLI::runcommand()` (a patch path that is not there, say) runs no
 * `after_invoke:` and leaves it armed until then.
 *
 * Armed, it listens on `sanitize_option_alpaca_bot_settings`, which each of the three runs on
 * the value it was handed before it writes: `update` and `patch` call sanitize_option()
 * themselves, `add` through add_option(), and update_option() and add_option() run it again.
 * A value is taken over only while WP-CLI's Option_Command update(), patch() or add()
 * (whichever it was armed for) is on the call stack writing this option (inCommand(), which reads
 * the arguments that call was handed, after --prompt and wp-cli.yml are merged in). WP-CLI hands
 * the name over as typed and core trims it, so the name is compared trimmed: under a padded name
 * WP-CLI's own sanitize_option() call names another filter, and the takeover happens in
 * update_option()'s or add_option()'s. A write from anywhere else, a plain update_option()
 * included, and a write of this option while the command writes another one (a hook on that
 * write, say), go through as they are and disarm it. `wp alpaca-bot settings` (which writes
 * through Store itself) never arms it.
 *
 * Any other name the options table takes for this one (other letters, `ALPACA_BOT_SETTINGS`;
 * or what its option_name column's collation equates, an accent, a zero-width space, a trailing
 * no-break space or a fullwidth letter under utf8mb4_unicode_520_ci) never reaches that filter,
 * or ProviderKey's and ServerSettings' `pre_update_option_alpaca_bot_settings`: hook names are
 * compared byte for byte. The column is not, so core would find the row and rewrite it as
 * handed, or add one that get_option('alpaca_bot_settings') then finds. Taking that write over
 * would mean writing the row through Store while core goes on to write it again under the other
 * name, so it is refused instead (refuseSpelling()): armed, this also listens on core's generic
 * `pre_update_option` filter and `add_option` action, which update_option() and add_option()
 * reach with the trimmed name before they write. When WP-CLI's command is writing such a name,
 * the command fails with nothing written and this disarms. "Such a name" is the database's
 * answer (databaseTakes()), asked only while armed, inside WP-CLI's command, for a name that is
 * not this option byte for byte, once per name it answers, in up to three steps:
 * 1. the name and this option compared in the option_name column's own character set and
 *    collation, read from information_schema once;
 * 2. when those cannot be read (or the comparison fails), the name compared with this option's
 *    stored row, `SELECT option_name = %s … WHERE option_name = 'alpaca_bot_settings'`, which is
 *    the column's own comparison and needs no information_schema;
 * 3. when there is no stored row either (an `add` over nothing), the name trimmed and compared
 *    in ASCII case.
 * A failed step-2 query refuses the write only for a name that is this option by trim and ASCII
 * case, saying the database could not be asked; any other name passes. A write of another option
 * is never refused unless the database says the name is this one's, or, where it cannot say (a
 * failed step-2 query, or step 3), the name is this one's by trim and ASCII case. An unanswered
 * question is not remembered, so the next write under that name asks again. The one gap left:
 * with information_schema unreadable and no row of this option yet, a name the collation equates
 * beyond ASCII case (an accent, say) is not caught.
 *
 * `--autoload` as that call has it (from the command line or wp-cli.yml) is never ignored: the
 * plugin keeps the row autoloaded, so anything but on, yes or true fails the command with nothing
 * written (an `add` over a row that exists is left to add_option() before that, below), and those
 * three set the row autoloaded (wp_set_option_autoload()) once the write is done, an unchanged
 * one included, since `wp option set-autoload alpaca_bot_settings off`, which this does not
 * intercept, can have taken it off. `update` and `patch` also run the stored row through the
 * filter, to compare; a value identical to the stored row is handed back untouched, so that pass,
 * and a write of exactly what is stored, go on as WP-CLI has them, except under an `--autoload`
 * of on, yes or true, where WP-CLI would hand the row to update_option() again and report "Could
 * not update option" when it answers false, and this sets the row autoloaded and reports it
 * unchanged itself. Any other value is taken over.
 * `update` and `patch` hand over a whole row, so first a key whose value is identical to the
 * stored row's is left out, as Store::set() leaves every other key out: an unchanged secret, a
 * plaintext key on a row ProviderKey::migrate() has not lifted included, then reads as "keep",
 * and "keep" does not survive a move of its URL. In `patch` mode a key the stored row has and the
 * value does not is one `wp option patch delete` removed, and is written as its default, as the
 * delete did before (Store would otherwise keep it); in `update` mode a key left out keeps its
 * value, as it does over REST. Then: a refused MCP row fails the command with the message REST
 * gives and nothing written; otherwise the value is written through Store::replace(), the
 * warnings are printed, the row is set autoloaded under an `--autoload` of on, yes or true, then
 * the line WP-CLI would print, and the command ends there with exit code 0. That line is
 * WP-CLI's "unchanged" one when the row Store wrote, the provider key and the MCP header values
 * are all as they were (a value the schema cleans to what is stored, say), whatever the autoload
 * flag did, and its "Updated" one otherwise, a write that changed only a secret included. It has
 * to end there: when a write changed only a secret, Settings\ProviderKey or Mcp\ServerSettings
 * lifts it out of the row, the row equals the stored one, update_option() answers false, and
 * WP-CLI would report "Could not update option" for a write that happened (#4696).
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

    /** @var \Closure(string): (array{0: list<mixed>, 1: array<array-key, mixed>}|null) */
    private \Closure $inCommand;

    /** @var callable(string): void */
    private $success;

    /** @var callable(string): void */
    private $warn;

    /** @var callable(string): void */
    private $fail;

    /** @var callable(int): void */
    private $halt;

    /** @var \Closure(string): ?bool */
    private \Closure $databaseTakes;

    /** @var array{0: string, 1: string}|false|null the option_name column's character set and collation; false when they could not be read, null until asked */
    private array|false|null $column = null;

    /** @var array<string, bool> databaseTakes()'s answers so far, by name */
    private array $taken = [];

    private Writer $writer;

    /**
     * @param callable(string): void|null $success prints the success line
     * @param callable(string): void|null $warn    prints a warning and goes on
     * @param callable(string): void|null $fail    reports an error and, under WP-CLI, ends the command with exit code 1
     * @param callable(int): void|null    $halt    ends the command with the given exit code
     * @param null|\Closure(string): (array{0: list<mixed>, 1: array<array-key, mixed>}|null) $inCommand what WP-CLI's option command for a mode was invoked with, while it runs; inCommand() by default
     * @param null|\Closure(string): ?bool $databaseTakes whether the options table takes a name for this option, null when it cannot say; databaseTakes() by default
     */
    public function __construct(
        private Store $store,
        ServerSettings $servers,
        ?callable $success = null,
        ?callable $warn = null,
        ?callable $fail = null,
        ?callable $halt = null,
        ?\Closure $inCommand = null,
        ?\Closure $databaseTakes = null,
    ) {
        $this->writer = new Writer($store, $servers);
        $this->inCommand = $inCommand ?? static fn(string $mode): ?array => self::inCommand($mode);
        $this->databaseTakes = $databaseTakes ?? fn(string $name): ?bool => $this->databaseTakes($name);
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
        \WP_CLI::add_hook('before_run_command', function (): void {
            $this->disarm();
        });
        foreach (self::COMMANDS as $command => $mode) {
            \WP_CLI::add_hook('before_invoke:' . $command, function () use ($mode): void {
                $this->arm($mode);
            });
            \WP_CLI::add_hook('after_invoke:' . $command, function (): void {
                $this->disarm();
            });
        }
    }

    /**
     * Listens on the option's sanitize filter, for the command `$mode` names, and on core's
     * generic `pre_update_option` filter and `add_option` action, for another name the options
     * table takes for this one
     * (refuseSpelling()).
     */
    public function arm(string $mode): void
    {
        $this->mode = $mode;
        add_filter('sanitize_option_' . Plugin::OPTION, [$this, 'sanitize']);
        add_filter('pre_update_option', [$this, 'refuseSpelling'], 10, 3);
        add_action('add_option', [$this, 'refuseSpellingOnAdd'], 10, 2);
    }

    /** Stops listening. */
    public function disarm(): void
    {
        $this->mode = null;
        remove_filter('sanitize_option_' . Plugin::OPTION, [$this, 'sanitize']);
        remove_filter('pre_update_option', [$this, 'refuseSpelling'], 10);
        remove_action('add_option', [$this, 'refuseSpellingOnAdd'], 10);
    }

    /**
     * On core's `pre_update_option`, which update_option() applies to every option after it has
     * trimmed the name and before it writes: when `$option` is another name the options table
     * takes for this one and WP-CLI's option command is writing such a name, fails the command
     * with nothing written (the class docblock) and hands back the stored value, so a fail() that
     * returned would still leave the row as it was. Any other value is handed back as it is.
     */
    public function refuseSpelling(#[\SensitiveParameter] mixed $value, mixed $option = null, #[\SensitiveParameter] mixed $old = null): mixed
    {
        return $this->refusesSpelling($option) ? $old : $value;
    }

    /** The same on core's `add_option` action, which add_option() fires before it inserts the row. */
    public function refuseSpellingOnAdd(mixed $option, #[\SensitiveParameter] mixed $value = null): void
    {
        $this->refusesSpelling($option);
    }

    /**
     * refuseSpelling()'s test, and the refusal when it holds. The database is asked only while
     * armed, only inside WP-CLI's command, only for a name that is not this option byte for byte,
     * and once per name it answers. It cannot answer (null) only for a name that is this option
     * by trim and ASCII case, and that write is refused; a failed query for any other name
     * answers false, and that write passes (the class docblock).
     */
    private function refusesSpelling(mixed $option): bool
    {
        if ($this->mode === null || !is_string($option) || $option === Plugin::OPTION) {
            return false;
        }
        $name = self::nameIn(($this->inCommand)($this->mode), $this->mode);
        if ($name === null) {
            return false;
        }
        $typed = trim($name) === Plugin::OPTION ? true : $this->takes(trim($name));
        $same = $typed === false ? false : $this->takes(trim($option));
        if ($typed === false || $same === false) {
            return false;
        }
        $this->disarm();
        ($this->fail)($typed === null || $same === null
            ? sprintf("Alpaca Bot could not ask the database whether '%s' is %s, which WP-CLI would write round Alpaca Bot's checks, so nothing was written.", $name, Plugin::OPTION)
            : sprintf("'%s' is %s to the database, which WP-CLI would write round Alpaca Bot's checks, so nothing was written. Write it as %s.", $name, Plugin::OPTION, Plugin::OPTION));
        return true;
    }

    /** databaseTakes, remembered per name; an unanswered question is asked again. */
    private function takes(string $name): ?bool
    {
        if ($name === Plugin::OPTION) {
            return true;
        }
        if (!array_key_exists($name, $this->taken)) {
            $answer = ($this->databaseTakes)($name);
            if ($answer === null) {
                return null;
            }
            $this->taken[$name] = $answer;
        }
        return $this->taken[$name];
    }

    /**
     * Whether the options table takes `$name` for this option, asked in three steps (the class
     * docblock): the two names compared in the option_name column's character set and collation,
     * read from information_schema once; failing that, `$name` compared with this option's stored
     * row in the column's own comparison; with no such row, trim plus ASCII case. Null (refuse,
     * saying the database could not be asked) only when the row query fails and `$name` is this
     * option by trim and ASCII case; a failed query for any other name answers false.
     */
    private function databaseTakes(string $name): ?bool
    {
        global $wpdb;
        $ascii = strcasecmp(trim($name), Plugin::OPTION) === 0;
        if ($this->column === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read once per process and kept in $column; no API reports a column's collation.
            $row = $wpdb->get_row($wpdb->prepare('SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $wpdb->options, 'option_name'), \ARRAY_N);
            $this->column = is_array($row) && count($row) === 2 && preg_match('/^[A-Za-z0-9_]+$/D', (string) $row[0]) === 1 && preg_match('/^[A-Za-z0-9_]+$/D', (string) $row[1]) === 1
                ? [(string) $row[0], (string) $row[1]]
                : false;
        }
        if ($this->column !== false) {
            [$charset, $collation] = $this->column;
            // Both identifiers are held to [A-Za-z0-9_]+ above, with D so no trailing newline gets
            // through `$`; the two names go through prepare().
            $sql = "SELECT CONVERT(%s USING {$charset}) COLLATE {$collation} = CONVERT(%s USING {$charset}) COLLATE {$collation}";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A comparison, not a table read; the answer is kept per name in $taken; $sql carries only the two identifiers checked above, and the names go through prepare().
            $same = self::bit($wpdb->get_var($wpdb->prepare($sql, $name, Plugin::OPTION)));
            if ($same !== null) {
                return $same === '1';
            }
        }
        // The column's own comparison, against this option's stored row: no information_schema.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A comparison against one row; the answer is kept per name in $taken.
        $answer = $wpdb->get_var($wpdb->prepare("SELECT option_name = %s FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name, Plugin::OPTION));
        $same = self::bit($answer);
        if ($same !== null) {
            return $same === '1';
        }
        if ($answer === null && (string) $wpdb->last_error === '') {
            return $ascii;
        }
        return $ascii ? null : false;
    }

    /** A comparison's answer as '1' or '0' (a driver may hand back an int), or null for anything else. */
    private static function bit(mixed $answer): ?string
    {
        $answer = is_int($answer) || is_string($answer) ? (string) $answer : null;
        return $answer === '1' || $answer === '0' ? $answer : null;
    }

    /**
     * The option name in WP-CLI's option command's arguments (inCommand()) as typed: the first
     * positional, the second for `patch`, whose first is its action; null when there is none.
     *
     * @param array{0: list<mixed>, 1: array<array-key, mixed>}|null $cli
     */
    private static function nameIn(?array $cli, string $mode): ?string
    {
        $name = $cli[0][$mode === 'patch' ? 1 : 0] ?? null;
        return is_string($name) ? $name : null;
    }

    /**
     * The positional and associative arguments WP-CLI's Option_Command::`$mode`() was invoked with,
     * read off the call stack while it runs; null when it is not on the stack. They are the ones the
     * command itself reads: after --prompt's answers and wp-cli.yml's defaults are merged in.
     *
     * @return array{0: list<mixed>, 1: array<array-key, mixed>}|null
     */
    private static function inCommand(string $mode): ?array
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Not debug output: the one way to read which option WP-CLI's option command is writing, and with what flags (the class docblock); runs only while armed, under WP-CLI.
        foreach (debug_backtrace(0) as $frame) {
            if (($frame['class'] ?? '') . '::' . $frame['function'] === 'Option_Command::' . $mode) {
                $args = $frame['args'] ?? [];
                return [is_array($args[0] ?? null) ? array_values($args[0]) : [], is_array($args[1] ?? null) ? $args[1] : []];
            }
        }
        return null;
    }

    /**
     * What `--autoload` asks of the write: null when it was not passed, true for on, yes or true
     * (the row is set autoloaded once the write is done), false for anything else.
     *
     * @param array<array-key, mixed> $assoc
     */
    private static function autoload(array $assoc): ?bool
    {
        $autoload = $assoc['autoload'] ?? null;
        return $autoload === null ? null : in_array($autoload, ['on', 'yes', 'true', true], true);
    }

    /** The takeover (the class docblock). */
    public function sanitize(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($this->mode === null) {
            return $value;
        }
        $mode = $this->mode;
        $cli = ($this->inCommand)($mode);
        // Trimmed, as update_option() and add_option() trim it before they run this filter.
        $name = self::nameIn($cli, $mode);
        if ($cli === null || $name === null || trim($name) !== Plugin::OPTION) {
            $this->disarm();
            return $value;
        }
        $row = get_option(Plugin::OPTION);
        if ($mode === 'add' && $row !== false) {
            $this->disarm();
            return $value;
        }
        $autoload = self::autoload($cli[1]);
        if ($autoload === false) {
            $this->disarm();
            // Named as it was written: a string is the flag's value; anything else comes from
            // wp-cli.yml, since WP-CLI refuses --autoload=false and --no-autoload itself, and
            // YAML reads an unquoted off, no or false there as false.
            $shown = $cli[1]['autoload'];
            ($this->fail)(Plugin::OPTION . ' stays autoloaded, so ' . match (true) {
                is_string($shown) => "--autoload={$shown} is refused and nothing was written. Leave --autoload out, or pass --autoload=on.",
                $shown === false => 'autoload: off in wp-cli.yml (off, no or false there) is refused and nothing was written. Take autoload out of wp-cli.yml, or set it to on.',
                default => 'autoload: ' . get_debug_type($shown) . ' in wp-cli.yml is refused and nothing was written. Take autoload out of wp-cli.yml, or set it to on.',
            });
            return $value;
        }
        if ($value === $row) {
            if ($autoload === null) {
                return $value;
            }
            $this->disarm();
            wp_set_option_autoload(Plugin::OPTION, true);
            ($this->success)(sprintf("Value passed for '%s' option is unchanged.", Plugin::OPTION));
            ($this->halt)(0);
            return $value;
        }
        if (!is_array($value)) {
            $this->disarm();
            ($this->fail)(sprintf('%s takes a JSON object of settings: pass it with --format=json.', Plugin::OPTION));
            return $value;
        }
        /** @var array<string, mixed> $value */
        $input = self::changed($value, $row, $mode === 'patch');
        $before = null;
        $written = $this->writer->write($input, function () use ($row, &$before): void {
            $before = [$row, get_option(ProviderKey::OPTION), get_option(Secrets::OPTION)];
            // Disarmed before the write: Store::replace()'s update_option() runs sanitize_option()
            // on the value again, and that pass is this write, not a second raw one.
            $this->disarm();
        });
        if ($written->refusal !== null) {
            $this->disarm();
            ($this->fail)($written->refusal->message);
            return $value;
        }
        foreach (ClearedWarnings::lines($written->mcpCleared, $written->keyCleared) as $line) {
            ($this->warn)($line);
        }
        $unchanged = [$this->store->all(), get_option(ProviderKey::OPTION), get_option(Secrets::OPTION)] === $before;
        if ($autoload === true) {
            wp_set_option_autoload(Plugin::OPTION, true);
        }
        ($this->success)(sprintf(match (true) {
            $mode === 'add' => "Added '%s' option.",
            $unchanged => "Value passed for '%s' option is unchanged.",
            default => "Updated '%s' option.",
        }, Plugin::OPTION));
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
