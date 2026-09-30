<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Cli\RawOptionWrite;
use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\ProviderKey;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * Kanboard #4695 and #4696 over real core: a raw `wp option update|patch|add alpaca_bot_settings`
 * as those commands drive it, in-process. Each runs sanitize_option() on the value it was handed
 * before it writes (`add` through add_option()); this test calls the same, with RawOptionWrite
 * armed as WP-CLI's `before_invoke:` hook arms it, and the plugin's own Store, ServerSettings and
 * ProviderKey filters in place. The exit WP-CLI would make is an exception here, so nothing runs
 * after it, as nothing would.
 *
 * The real-WP-CLI runs of the same four cases are in the Task 1 report of the 0.6.2 wave-2 plan.
 *
 * @group cli
 */
final class RawOptionWriteTest extends TestCase
{
    /** @var list<string> */
    private array $said = [];

    /** @param bool $inCommand true stands in for WP-CLI's Option_Command writing this option with no flags; false leaves the real call-stack check */
    private function armed(string $mode, bool $inCommand = true): RawOptionWrite
    {
        $raw = new RawOptionWrite(
            Plugin::instance()->get(Store::class),
            Plugin::instance()->get(ServerSettings::class),
            success: function (string $m): void {
                $this->said[] = 'Success: ' . $m;
            },
            warn: function (string $m): void {
                $this->said[] = 'Warning: ' . $m;
            },
            fail: static function (string $m): void {
                throw new \RuntimeException('Error: ' . $m);
            },
            halt: static function (int $code): void {
                throw new \OverflowException('halt ' . $code);
            },
            inCommand: $inCommand ? static fn(string $mode): ?array => [$mode === 'patch' ? ['update', Plugin::OPTION] : [Plugin::OPTION], []] : null,
        );
        $raw->arm($mode);
        return $raw;
    }

    /** Runs what the command runs on the value, and answers how the command ended. */
    private function write(string $mode, mixed $value, ?RawOptionWrite &$raw = null): string
    {
        $raw = $this->armed($mode);
        try {
            sanitize_option(Plugin::OPTION, $value);
            return 'returned';
        } catch (\OverflowException | \RuntimeException $e) {
            return $e->getMessage();
        }
    }

    private function isArmed(RawOptionWrite $raw): bool
    {
        return has_filter('sanitize_option_' . Plugin::OPTION, [$raw, 'sanitize']) !== false;
    }

    /**
     * Loads WP-CLI's own Option_Command, and the parts of WP-CLI it calls, from the WP-CLI phar the
     * container runs `wp` with (WP_CLI_PHAR names another), and gives WP_CLI a logger that records
     * what WP-CLI itself prints in `$this->said`, prefixed `WP-CLI: `. Skips only where there is no
     * phar at all and the suite is not running under bin/test-integration.sh or CI; a phar without
     * Option_Command where it was fails. The class is required, not copied, so a WP-CLI that
     * renames or moves it, or its update(), patch() or add(), fails the tests that call it.
     *
     * Both places bin/test-integration.sh runs the suite have the phar there, so these run rather
     * than skip: the harness's `cli` service runs the `wordpress:cli-php8.4` image, and wp-env's
     * `tests-cli` (CI) is built FROM `wordpress:cli-php8.4` too (@wordpress/env's
     * cliDockerFileContents(), with .wp-env.json's phpVersion), which ships WP-CLI at
     * /usr/local/bin/wp. A skip means the suite ran somewhere else.
     */
    private function wpCli(): void
    {
        $phar = getenv('WP_CLI_PHAR') ?: '/usr/local/bin/wp';
        $root = 'phar://' . $phar . '/vendor/wp-cli/';
        if (!is_file($phar)) {
            // Under bin/test-integration.sh (either mode sets WPH_MODE) or CI the phar is always
            // there (the docblock), so its absence there is a failure, never a silent skip.
            if ((string) getenv('WPH_MODE') !== '' || (string) getenv('CI') !== '') {
                $this->fail("No WP-CLI phar at {$phar}, where bin/test-integration.sh's containers ship it; set WP_CLI_PHAR.");
            }
            $this->markTestSkipped("No WP-CLI phar at {$phar} to load Option_Command from; set WP_CLI_PHAR.");
        }
        // `wp` has no .phar extension, so PHP opens phar:// paths into it only once it is loaded.
        if (!is_file($root . 'wp-cli/php/class-wp-cli.php')) {
            \Phar::loadPhar($phar);
        }
        // A phar that loads without Option_Command where it was is a WP-CLI that moved or renamed
        // it: a failure, since the takeover's call-stack check names that class.
        $this->assertFileExists($root . 'entity-command/src/Option_Command.php', 'WP-CLI no longer has Option_Command where RawOptionWrite::inCommand() expects it.');
        // WP-CLI's own classes (patch() builds its RecursiveDataStructureTraverser) from where its
        // autoloader finds them, php/WP_CLI/ for the WP_CLI namespace.
        if (!class_exists(\WP_CLI\Traverser\RecursiveDataStructureTraverser::class)) {
            spl_autoload_register(static function (string $class) use ($root): void {
                $file = $root . 'wp-cli/php/' . str_replace('\\', '/', $class) . '.php';
                if (str_starts_with($class, 'WP_CLI\\') && is_file($file)) {
                    require $file;
                }
            });
        }
        require_once $root . 'wp-cli/php/utils.php';
        require_once $root . 'wp-cli/php/class-wp-cli.php';
        require_once $root . 'wp-cli/php/class-wp-cli-command.php';
        require_once $root . 'entity-command/src/Option_Command.php';
        $this->assertTrue(class_exists(\Option_Command::class, false), 'WP-CLI\'s Option_Command.php no longer declares Option_Command.');
        $said = &$this->said;
        \WP_CLI::set_logger(new class ($said) {
            /** @param list<string> $said */
            public function __construct(private array &$said)
            {
            }

            public function success(string $m): void
            {
                $this->said[] = 'WP-CLI: Success: ' . $m;
            }

            public function warning(string $m): void
            {
                $this->said[] = 'WP-CLI: Warning: ' . $m;
            }

            public function error(string $m): void
            {
                $this->said[] = 'WP-CLI: Error: ' . $m;
            }

            public function info(string $m): void
            {
            }

            public function debug(string $m, mixed $group = false): void
            {
            }
        });
        self::resetWpCliHooks();
    }

    /**
     * WP-CLI keeps its hooks, the ones it has fired, and whether an error throws or exits, in
     * statics. A test starts and ends with no hooks, and with WP_CLI::error() throwing its
     * ExitException, as it does inside WP_CLI::runcommand(), rather than ending PHPUnit's process.
     */
    private static function resetWpCliHooks(): void
    {
        if (class_exists('WP_CLI', false)) {
            (new \ReflectionProperty(\WP_CLI::class, 'hooks'))->setValue(null, []);
            (new \ReflectionProperty(\WP_CLI::class, 'hooks_passed'))->setValue(null, []);
            (new \ReflectionProperty(\WP_CLI::class, 'capture_exit'))->setValue(null, true);
        }
    }

    /** Runs WP-CLI's own Option_Command::`$mode`() and answers how it ended. */
    private function command(string $mode, array $args, array $assoc = []): string
    {
        try {
            (new \Option_Command())->$mode($args, $assoc);
            return 'returned';
        } catch (\OverflowException | \RuntimeException $e) {
            return $e->getMessage();
        } catch (\WP_CLI\ExitException $e) {
            return 'WP-CLI exit ' . $e->getCode();
        }
    }

    public function tear_down(): void
    {
        // capture_exit stays set, so a stray WP_CLI::error() in a later test throws instead of
        // ending PHPUnit's process.
        self::resetWpCliHooks();
        delete_option(ProviderKey::OPTION);
        delete_option(Secrets::OPTION);
        $this->said = [];
        parent::tear_down();
    }

    public function test_it_is_disarmed_after_a_takeover_a_refusal_and_an_add_it_leaves_to_core(): void
    {
        $this->assertFalse(has_filter('sanitize_option_' . Plugin::OPTION), 'nothing is armed outside a command');

        $this->assertSame('halt 0', $this->write('update', ['models.temperature' => 0.4], $raw));
        $this->assertFalse($this->isArmed($raw), 'after a takeover');

        $this->assertStringStartsWith('Error: ', $this->write('update', 'not an object', $raw));
        $this->assertFalse($this->isArmed($raw), 'after a refusal');

        $this->assertStringStartsWith('Error: ', $this->write('patch', ['toolkits.mcp_servers' => [['url' => 'http://x.example.com/mcp', 'prefix' => 'x']]] + get_option(Plugin::OPTION), $raw));
        $this->assertFalse($this->isArmed($raw), 'after an MCP refusal');

        $this->assertSame('returned', $this->write('add', ['models.temperature' => 0.2], $raw));
        $this->assertFalse($this->isArmed($raw), 'after an add over an existing row');

        // The comparison pass stays armed (patch makes it before its write); after_invoke disarms.
        $this->assertSame('returned', $this->write('patch', get_option(Plugin::OPTION), $raw));
        $this->assertTrue($this->isArmed($raw));
        $raw->disarm();
        $this->assertFalse($this->isArmed($raw));
    }

    // Left armed (a command WP-CLI ended with an error inside WP_CLI::runcommand()), the default
    // call-stack check still lets a later write from the same process through as it is.
    public function test_a_write_outside_the_option_command_is_not_taken_over(): void
    {
        $raw = $this->armed('update', inCommand: false);
        Plugin::instance()->get(Store::class)->set('models.temperature', 0.45);

        $this->assertSame(0.45, get_option(Plugin::OPTION)['models.temperature']);
        $this->assertSame([], $this->said);
        $this->assertFalse($this->isArmed($raw));
    }

    public function test_a_patch_delete_resets_the_key_to_its_default(): void
    {
        Plugin::instance()->get(Store::class)->set('models.temperature', 0.3);
        $row = get_option(Plugin::OPTION);
        unset($row['models.temperature']);

        $this->assertSame('halt 0', $this->write('patch', $row));

        $this->assertSame(0.7, get_option(Plugin::OPTION)['models.temperature']);
    }

    public function test_a_move_clears_a_plaintext_key_an_unmigrated_row_still_carries(): void
    {
        global $wpdb;
        Plugin::instance()->get(Store::class)->replace(['provider.base_url' => 'https://openrouter.ai/api/v1']);
        $row = ['provider.api_key' => 'sk-FAKE-plain'] + get_option(Plugin::OPTION);
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($row)], ['option_name' => Plugin::OPTION]);
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete(Plugin::OPTION, 'options');
        (new \ReflectionProperty(Store::class, 'cache'))->setValue(Plugin::instance()->get(Store::class), null);
        $this->assertTrue(ProviderKey::pending(get_option(Plugin::OPTION)));

        $this->assertSame('halt 0', $this->write('patch', ['provider.base_url' => 'https://steal.example.net/v1'] + get_option(Plugin::OPTION)));

        $this->assertStoredProviderKey('', get_option(Plugin::OPTION));
        $this->assertSame('', ProviderKey::resolve(get_option(Plugin::OPTION)['provider.api_key']));
        $this->assertContains('Warning: provider.api_key was cleared, because provider.base_url moved to another host, port or scheme. Set it again: wp alpaca-bot settings provider.api_key <key>', $this->said);
    }

    public function test_a_base_url_move_clears_the_key_and_warns(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->replace(['provider.base_url' => 'https://openrouter.ai/api/v1']);
        $store->set('provider.api_key', 'sk-FAKE-held');
        $this->assertStoredProviderKey('sk-FAKE-held', get_option(Plugin::OPTION));

        // `wp option patch update alpaca_bot_settings provider.base_url …` hands over the whole row, MASK and all.
        $this->assertSame('halt 0', $this->write('patch', ['provider.base_url' => 'https://steal.example.net/v1'] + get_option(Plugin::OPTION)));

        $this->assertSame('https://steal.example.net/v1', get_option(Plugin::OPTION)['provider.base_url']);
        $this->assertStoredProviderKey('', get_option(Plugin::OPTION));
        $this->assertSame([
            'Warning: provider.api_key was cleared, because provider.base_url moved to another host, port or scheme. Set it again: wp alpaca-bot settings provider.api_key <key>',
            "Success: Updated 'alpaca_bot_settings' option.",
        ], $this->said);
    }

    public function test_a_refused_mcp_row_fails_names_the_row_and_stores_nothing(): void
    {
        $before = get_option(Plugin::OPTION);
        $this->asAdmin();
        $rest = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://127.0.0.1/mcp', 'prefix' => 'lo', 'header_value' => 'Bearer FAKE-refused']]]);
        $this->assertSame(400, $rest->get_status());

        $ended = $this->write('update', ['toolkits.mcp_servers' => [['url' => 'https://127.0.0.1/mcp', 'prefix' => 'lo', 'header_value' => 'Bearer FAKE-refused']]] + $before);

        $this->assertSame('Error: ' . $rest->get_data()['message'], $ended);
        $this->assertStringContainsString('https://127.0.0.1/mcp: ', $ended);
        $this->assertSame($before, get_option(Plugin::OPTION));
        $this->assertFalse(get_option(Secrets::OPTION));
        $this->assertSame([], $this->said);
    }

    // README's REST API and WP-CLI section: a raw `wp option update alpaca_bot_settings '{}'
    // --format=json` no longer resets the settings, since a key it leaves out keeps its value, and
    // `wp option delete alpaca_bot_settings` is the reset.
    public function test_an_empty_update_keeps_every_setting_and_a_delete_is_the_reset(): void
    {
        Plugin::instance()->get(Store::class)->set('models.temperature', 0.3);
        $row = get_option(Plugin::OPTION);

        $this->assertSame('halt 0', $this->write('update', []));
        $this->assertSame($row, get_option(Plugin::OPTION));

        // The next request's Store (this one's has the row memoized) reads every default.
        delete_option(Plugin::OPTION);
        $this->assertSame(Schema::defaults(), (new Store())->all());
    }

    // README's MCP paragraph: the raw write is one of the saves that keep no server under a
    // reserved prefix, and check its address.
    public function test_a_reserved_prefix_is_refused_as_rest_refuses_it(): void
    {
        $before = get_option(Plugin::OPTION);
        $rows = [['url' => 'https://ab.example.com/mcp', 'prefix' => Schema::RESERVED_PREFIX]];
        $this->asAdmin();
        $rest = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => $rows]);
        $this->assertSame(400, $rest->get_status());

        $ended = $this->write('update', ['toolkits.mcp_servers' => $rows] + $before);

        $this->assertSame('Error: ' . $rest->get_data()['message'], $ended);
        $this->assertSame($before, get_option(Plugin::OPTION));
    }

    public function test_a_key_only_write_succeeds_and_the_key_reaches_resolve(): void
    {
        Plugin::instance()->get(Store::class)->set('provider.api_key', 'sk-FAKE-first');
        $row = get_option(Plugin::OPTION);

        $this->assertSame('halt 0', $this->write('update', ['provider.api_key' => 'sk-FAKE-second'] + $row));

        // The row did not change (it carries the mask before and after), which is why core's
        // update_option() answered false here and WP-CLI used to report "Could not update option".
        $this->assertSame($row, get_option(Plugin::OPTION));
        $this->assertSame('sk-FAKE-second', ProviderKey::resolve(get_option(Plugin::OPTION)['provider.api_key']));
        $this->assertSame(["Success: Updated 'alpaca_bot_settings' option."], $this->said);
    }

    public function test_a_normal_write_stores_through_the_schema(): void
    {
        $this->assertSame('halt 0', $this->write('update', ['models.temperature' => '0.3', 'models.num_ctx' => 'lots'] + get_option(Plugin::OPTION)));

        $this->assertSame(0.3, get_option(Plugin::OPTION)['models.temperature']);
        $this->assertIsInt(get_option(Plugin::OPTION)['models.num_ctx']);
        $this->assertSame(["Success: Updated 'alpaca_bot_settings' option."], $this->said);
    }

    public function test_the_stored_row_passes_untouched_as_the_comparison_pass(): void
    {
        $this->assertSame('returned', $this->write('update', get_option(Plugin::OPTION)));
        $this->assertSame([], $this->said);
    }

    public function test_an_add_over_no_row_keeps_the_key_out_of_it(): void
    {
        delete_option(Plugin::OPTION);

        $raw = $this->armed('add');
        try {
            add_option(Plugin::OPTION, ['provider.api_key' => 'sk-FAKE-added', 'models.temperature' => '0.6']);
            $ended = 'returned';
        } catch (\OverflowException $e) {
            $ended = $e->getMessage();
        }

        $this->assertSame('halt 0', $ended);
        $this->assertFalse($this->isArmed($raw));
        $this->assertStoredProviderKey('sk-FAKE-added', get_option(Plugin::OPTION));
        $this->assertSame(0.6, get_option(Plugin::OPTION)['models.temperature']);
        $this->assertSame(["Success: Added 'alpaca_bot_settings' option."], $this->said);
    }

    // Item 4 of the 0.6.2 wave-3 brief: the default call-stack check against WP-CLI's real
    // Option_Command, so a rename of the class or of update() on WP-CLI's side turns this red
    // instead of letting every raw write through.
    public function test_the_call_stack_check_finds_wp_clis_own_option_command(): void
    {
        $this->wpCli();
        $raw = $this->armed('update', inCommand: false);

        $ended = $this->command('update', [Plugin::OPTION, '{"models.temperature":0.35,"nope":1}'], ['format' => 'json']);

        $this->assertSame('halt 0', $ended);
        $this->assertSame(0.35, get_option(Plugin::OPTION)['models.temperature']);
        $this->assertArrayNotHasKey('nope', get_option(Plugin::OPTION));
        $this->assertSame(["Success: Updated 'alpaca_bot_settings' option."], $this->said);
        $this->assertFalse($this->isArmed($raw));
    }

    // Fail closed: the option name read from --prompt or wp-cli.yml is not in the words
    // `before_run_command` hands over, and the takeover still happens, through the hooks
    // register() adds, fired as WP-CLI's Runner and Subcommand fire them.
    public function test_it_takes_over_a_write_whose_command_words_do_not_name_the_option(): void
    {
        $this->wpCli();
        $raw = new RawOptionWrite(
            Plugin::instance()->get(Store::class),
            Plugin::instance()->get(ServerSettings::class),
            success: function (string $m): void {
                $this->said[] = 'Success: ' . $m;
            },
            halt: static function (int $code): void {
                throw new \OverflowException('halt ' . $code);
            },
        );
        $raw->register();

        \WP_CLI::do_hook('before_run_command', ['option', 'update'], ['format' => 'json'], []);
        \WP_CLI::do_hook('before_invoke:option update', 'option update');
        $ended = $this->command('update', [Plugin::OPTION, '{"models.temperature":"hot"}'], ['format' => 'json']);

        $this->assertSame('halt 0', $ended);
        $this->assertSame(0.7, get_option(Plugin::OPTION)['models.temperature'], 'the schema kept its default over "hot"');
        $this->assertArrayHasKey('models.num_ctx', get_option(Plugin::OPTION), 'a key the value leaves out keeps its value');
        $this->assertFalse($this->isArmed($raw));
    }

    // Armed for `wp option update blogname`, a write of this option from a hook on that write is
    // plugin code, not the raw write: it goes through as it is, and disarms.
    public function test_a_write_of_this_option_while_the_command_writes_another_is_not_taken_over(): void
    {
        $this->wpCli();
        $raw = $this->armed('update', inCommand: false);
        $write = static function (): void {
            Plugin::instance()->get(Store::class)->set('models.temperature', 0.55);
        };
        add_action('update_option_blogname', $write);

        $ended = $this->command('update', ['blogname', 'Raw write test'], []);
        remove_action('update_option_blogname', $write);

        $this->assertSame('returned', $ended);
        $this->assertSame(0.55, get_option(Plugin::OPTION)['models.temperature']);
        $this->assertSame(["WP-CLI: Success: Updated 'blogname' option."], $this->said);
        $this->assertFalse($this->isArmed($raw));
    }

    private function autoloadOf(string $option): string
    {
        global $wpdb;
        wp_cache_delete('alloptions', 'options');
        return (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
    }

    public function test_autoload_off_is_refused_and_nothing_moves(): void
    {
        $this->wpCli();
        $row = get_option(Plugin::OPTION);
        $autoload = $this->autoloadOf(Plugin::OPTION);

        foreach ([['models.temperature' => 0.2] + $row, $row] as $value) {
            $raw = $this->armed('update', inCommand: false);
            $ended = $this->command('update', [Plugin::OPTION, (string) wp_json_encode($value)], ['format' => 'json', 'autoload' => 'off']);

            $this->assertSame('Error: alpaca_bot_settings stays autoloaded, so --autoload=off is refused and nothing was written. Leave --autoload out, or pass --autoload=on.', $ended);
            $this->assertSame($row, get_option(Plugin::OPTION));
            $this->assertSame($autoload, $this->autoloadOf(Plugin::OPTION));
            $this->assertFalse($this->isArmed($raw));
        }
        $this->assertSame([], $this->said);
    }

    public function test_autoload_on_writes_as_without_it_and_the_row_stays_autoloaded(): void
    {
        $this->wpCli();
        $autoload = $this->autoloadOf(Plugin::OPTION);
        $this->armed('update', inCommand: false);

        $ended = $this->command('update', [Plugin::OPTION, '{"models.temperature":0.25}'], ['format' => 'json', 'autoload' => 'on']);

        $this->assertSame('halt 0', $ended);
        $this->assertSame(0.25, get_option(Plugin::OPTION)['models.temperature']);
        $this->assertSame($autoload, $this->autoloadOf(Plugin::OPTION));
        $this->assertSame(["Success: Updated 'alpaca_bot_settings' option."], $this->said);

        $this->said = [];
        $this->armed('update', inCommand: false);
        $this->assertSame('halt 0', $this->command('update', [Plugin::OPTION, (string) wp_json_encode(get_option(Plugin::OPTION))], ['format' => 'json', 'autoload' => 'yes']));
        $this->assertSame(["Success: Value passed for 'alpaca_bot_settings' option is unchanged."], $this->said);
    }

    // Item 2: where WP-CLI would print its "unchanged" line, the takeover prints the same: here the
    // value differs from the stored row only until the schema has cleaned it.
    public function test_a_write_the_schema_cleans_to_what_is_stored_is_reported_unchanged(): void
    {
        $this->wpCli();
        $row = get_option(Plugin::OPTION);

        $this->armed('update', inCommand: false);
        $this->assertSame('halt 0', $this->command('update', [Plugin::OPTION, (string) wp_json_encode(['models.temperature' => '0.70'] + $row)], ['format' => 'json']));
        $this->armed('patch', inCommand: false);
        $this->assertSame('halt 0', $this->command('patch', ['update', Plugin::OPTION, 'models.temperature', '0.70']));
        // patch() reads a whole-number value as an int, so this one is the stored row exactly, and
        // WP-CLI reports it unchanged itself.
        $raw = $this->armed('patch', inCommand: false);
        $this->assertSame('returned', $this->command('patch', ['update', Plugin::OPTION, 'models.num_ctx', (string) $row['models.num_ctx']]));
        $raw->disarm();

        $this->assertSame($row, get_option(Plugin::OPTION));
        $this->assertSame([
            "Success: Value passed for 'alpaca_bot_settings' option is unchanged.",
            "Success: Value passed for 'alpaca_bot_settings' option is unchanged.",
            "WP-CLI: Success: Value passed for 'alpaca_bot_settings' option is unchanged.",
        ], $this->said);
    }

    // #4696 stays: a write that changes only the key leaves the row as it was, and is an update.
    public function test_a_key_only_write_through_wp_clis_command_is_reported_updated(): void
    {
        $this->wpCli();
        Plugin::instance()->get(Store::class)->set('provider.api_key', 'sk-FAKE-first');
        $row = get_option(Plugin::OPTION);
        $this->armed('patch', inCommand: false);

        $this->assertSame('halt 0', $this->command('patch', ['update', Plugin::OPTION, 'provider.api_key', 'sk-FAKE-second']));

        $this->assertSame($row, get_option(Plugin::OPTION));
        $this->assertSame('sk-FAKE-second', ProviderKey::resolve($row['provider.api_key']));
        $this->assertSame(["Success: Updated 'alpaca_bot_settings' option."], $this->said);
    }

    /** Every row the options table takes for this option: compared in the column's own collation. */
    private function rowsNamedLikeTheOption(): array
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name = %s OR LOWER(TRIM(option_name)) = %s", Plugin::OPTION, Plugin::OPTION));
    }

    // Review fix round 1 (a): WP-CLI hands the name over untrimmed; core trims it before it runs
    // the option's sanitize filter, so the name is compared trimmed.
    public function test_a_name_with_spaces_around_it_is_taken_over(): void
    {
        $this->wpCli();

        $this->armed('update', inCommand: false);
        $this->assertSame('halt 0', $this->command('update', [' alpaca_bot_settings', '{"models.temperature":"hot","models.num_ctx":4096}'], ['format' => 'json']));
        $this->assertSame(0.7, get_option(Plugin::OPTION)['models.temperature']);
        $this->assertSame(4096, get_option(Plugin::OPTION)['models.num_ctx']);

        delete_option(Plugin::OPTION);
        $this->armed('add', inCommand: false);
        $this->assertSame('halt 0', $this->command('add', ['alpaca_bot_settings ', '{"models.temperature":"hot"}'], ['format' => 'json']));
        $this->assertSame(Schema::defaults()['models.temperature'], get_option(Plugin::OPTION)['models.temperature']);
        $this->assertCount(count(Schema::defaults()), get_option(Plugin::OPTION));

        $this->assertSame([Plugin::OPTION], $this->rowsNamedLikeTheOption());
        $this->assertSame(["Success: Updated 'alpaca_bot_settings' option.", "Success: Added 'alpaca_bot_settings' option."], $this->said);
    }

    // Review fix round 1 (b): filter names are case-sensitive and the options table is not, so
    // under other letters the option's own filters never run while core writes its row. Refused.
    public function test_the_option_under_other_letters_is_refused_and_nothing_is_written(): void
    {
        $this->wpCli();
        $row = get_option(Plugin::OPTION);
        $refused = "Error: 'ALPACA_BOT_SETTINGS' is alpaca_bot_settings to the database, which WP-CLI would write round Alpaca Bot's checks, so nothing was written. Write it as alpaca_bot_settings.";

        $raw = $this->armed('update', inCommand: false);
        $this->assertSame($refused, $this->command('update', ['ALPACA_BOT_SETTINGS', '{"models.temperature":"hot"}'], ['format' => 'json']));
        $this->assertFalse($this->isArmed($raw));
        $raw = $this->armed('patch', inCommand: false);
        $this->assertSame(str_replace('ALPACA_BOT_SETTINGS', 'Alpaca_Bot_Settings', $refused), $this->command('patch', ['update', 'Alpaca_Bot_Settings', 'models.temperature', 'hot']));
        wp_cache_flush();
        $this->assertSame($row, get_option(Plugin::OPTION));

        delete_option(Plugin::OPTION);
        $this->armed('add', inCommand: false);
        $this->assertSame($refused, $this->command('add', ['ALPACA_BOT_SETTINGS', '{"models.temperature":"hot"}'], ['format' => 'json']));
        $this->assertSame([], $this->rowsNamedLikeTheOption());
        $this->assertSame([], $this->said);
    }

    // Fix round 2: the options table's collation equates more than case. Each of these finds the
    // real row there (a read-only SELECT on ab061srv, utf8mb4_unicode_520_ci), and none reaches
    // the option's own filters; each is refused, asked of the real database, with nothing written.
    public function test_every_name_the_database_takes_for_the_option_is_refused_and_nothing_is_written(): void
    {
        global $wpdb;
        $this->wpCli();
        $row = get_option(Plugin::OPTION);
        $names = ["alp\u{00E4}ca_bot_settings", "alpaca_bot\u{200B}_settings", "alpaca_bot_settings\u{00A0}", "\u{FF41}lpaca_bot_settings"];
        foreach ($names as $name) {
            $this->assertSame($row, maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name))), 'the database takes ' . json_encode($name) . ' for the option');
        }

        foreach ($names as $name) {
            $raw = $this->armed('update', inCommand: false);
            $this->assertSame("Error: '{$name}' is alpaca_bot_settings to the database, which WP-CLI would write round Alpaca Bot's checks, so nothing was written. Write it as alpaca_bot_settings.", $this->command('update', [$name, '{"models.temperature":"hot"}'], ['format' => 'json']));
            $this->assertFalse($this->isArmed($raw));
        }
        wp_cache_flush();
        $this->assertSame($row, get_option(Plugin::OPTION));
        $this->assertSame([Plugin::OPTION], $this->rowsNamedLikeTheOption());

        delete_option(Plugin::OPTION);
        foreach (["alp\u{00E4}ca_bot_settings", "alpaca_bot_settings\u{00A0}"] as $name) {
            $this->armed('add', inCommand: false);
            $this->assertStringStartsWith("Error: '{$name}' is alpaca_bot_settings to the database", $this->command('add', [$name, '{"models.temperature":"hot"}'], ['format' => 'json']));
        }
        $this->assertSame([], $this->rowsNamedLikeTheOption());
        $this->assertSame([], $this->said);
    }
}
