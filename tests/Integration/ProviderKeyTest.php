<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Cli\ChatCommand;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\BearerHttpClient;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\ProviderKey;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\AbstractProvider;

/**
 * The provider key against a real options table (Kanboard #4384): kept in
 * `alpaca_bot_provider_key` with autoload off, never in the autoloaded settings row, and moved
 * there from a 0.6.0 row by the migration on `init`.
 *
 * "Autoloaded" is read as core reads it, as Migrate04AutoloadTest does: the raw `autoload`
 * column against wp_autoload_values_to_autoload().
 *
 * @group migration
 */
final class ProviderKeyTest extends TestCase
{
    private function autoloadOf(string $option): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
        return $value === null ? null : (string) $value;
    }

    /** The settings row as it sits in the table, read past every cache. */
    private function rawRow(): mixed
    {
        global $wpdb;
        return maybe_unserialize((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Plugin::OPTION)));
    }

    /**
     * Writes a 0.6.0-shaped row, the key in plaintext, straight into the table: no
     * `pre_update_option_*` filter runs, as none did under 0.6.0. The caches and the Store's memo
     * are dropped so the next read sees it.
     */
    private function seed060Row(string $key): void
    {
        global $wpdb;
        delete_option(ProviderKey::OPTION);
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize(['provider.api_key' => $key] + Schema::defaults())], ['option_name' => Plugin::OPTION]);
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete(Plugin::OPTION, 'options');
        (new \ReflectionProperty(Store::class, 'cache'))->setValue(Plugin::instance()->get(Store::class), null);
    }

    /**
     * Runs what Plugin::register() hooked on `init` at 20, the migrations, and nothing else on
     * `init`: a whole do_action('init') would register the plugin's post types a second time, and
     * the second registration no longer carries Plugin::POST_TYPE_MARK, which uninstall.php reads
     * (UninstallTest, later in the run, then finds its posts left behind).
     */
    private function runInitMigrations(): void
    {
        global $wp_filter;
        $ran = 0;
        foreach ($wp_filter['init']->callbacks[20] ?? [] as $hooked) {
            if ($hooked['function'] instanceof \Closure && str_ends_with((string) (new \ReflectionFunction($hooked['function']))->getFileName(), '/src/Plugin.php')) {
                ($hooked['function'])();
                ++$ran;
            }
        }
        $this->assertSame(1, $ran, 'Plugin::register() hooks one closure on init at 20');
    }

    private function assertKeyKeptOutOfTheRow(string $key): void
    {
        $this->assertSame(Schema::MASK, get_option(Plugin::OPTION)['provider.api_key']);
        $this->assertStringNotContainsString($key, serialize($this->rawRow()));
        $this->assertSame($key, get_option(ProviderKey::OPTION));
        $this->assertNotNull($this->autoloadOf(ProviderKey::OPTION));
        $this->assertNotContains($this->autoloadOf(ProviderKey::OPTION), wp_autoload_values_to_autoload());
        wp_cache_delete('alloptions', 'options');
        $alloptions = wp_load_alloptions(true);
        $this->assertArrayNotHasKey(ProviderKey::OPTION, $alloptions);
        $this->assertStringNotContainsString($key, serialize($alloptions));
    }

    public function test_a_060_row_keeps_its_key_is_migrated_on_init_and_a_second_run_changes_nothing(): void
    {
        $this->seed060Row('sk-FAKE-060');
        $this->assertTrue(ProviderKey::pending(get_option(Plugin::OPTION)));
        // Before the migration has run, the key is still read from the row: by the sender, and by
        // the reveal.
        $this->assertSame('sk-FAKE-060', Plugin::instance()->get(Store::class)->get('provider.api_key'));
        $this->assertSame('sk-FAKE-060', ProviderKey::resolve(Plugin::instance()->get(Store::class)->get('provider.api_key')));
        $this->asAdmin();
        $this->assertSame('sk-FAKE-060', $this->rest('GET', '/settings', ['reveal' => 1])->get_data()['provider.api_key']);
        $this->assertSame(Schema::MASK, $this->rest('GET', '/settings')->get_data()['provider.api_key']);

        $this->runInitMigrations();

        $this->assertKeyKeptOutOfTheRow('sk-FAKE-060');
        $this->assertFalse(ProviderKey::pending(get_option(Plugin::OPTION)));
        $row = $this->rawRow();
        $autoload = $this->autoloadOf(ProviderKey::OPTION);

        $writes = 0;
        $count = static function () use (&$writes): void {
            ++$writes;
        };
        add_action('updated_option', $count);
        add_action('added_option', $count);
        add_action('deleted_option', $count);
        $this->runInitMigrations();
        // Detection reads the row from alloptions, already loaded: no query at all.
        global $wpdb;
        $queries = $wpdb->num_queries;
        ProviderKey::migrate();
        $this->assertSame($queries, $wpdb->num_queries);

        $this->assertSame(0, $writes);
        $this->assertSame($row, $this->rawRow());
        $this->assertSame('sk-FAKE-060', get_option(ProviderKey::OPTION));
        $this->assertSame($autoload, $this->autoloadOf(ProviderKey::OPTION));
    }

    // add_option() called from code runs no pre_update_option_* filter: the key stays in the row until the next
    // request's migration lifts it.
    public function test_a_key_added_round_the_filter_stays_in_the_row_until_the_migration(): void
    {
        delete_option(Plugin::OPTION);
        delete_option(ProviderKey::OPTION);
        add_option(Plugin::OPTION, ['provider.api_key' => 'sk-FAKE-added'] + Schema::defaults());
        $this->assertSame('sk-FAKE-added', get_option(Plugin::OPTION)['provider.api_key']);
        $this->assertFalse(get_option(ProviderKey::OPTION));
        $this->runInitMigrations();
        $this->assertKeyKeptOutOfTheRow('sk-FAKE-added');
    }

    // The key option is written from inside the row's update_option(), before core writes the row.
    public function test_the_key_option_is_written_before_the_row(): void
    {
        $seen = null;
        $spy = static function (mixed $value) use (&$seen): mixed {
            global $wpdb;
            $row = maybe_unserialize((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Plugin::OPTION)));
            $seen = ['held' => get_option(ProviderKey::OPTION), 'row' => is_array($row) ? $row['provider.api_key'] : null];
            return $value;
        };
        add_filter('pre_update_option_' . Plugin::OPTION, $spy, PHP_INT_MAX);
        Plugin::instance()->get(Store::class)->set('provider.api_key', 'sk-FAKE-order');
        remove_filter('pre_update_option_' . Plugin::OPTION, $spy, PHP_INT_MAX);
        $this->assertSame(['held' => 'sk-FAKE-order', 'row' => ''], $seen);
        $this->assertSame(Schema::MASK, get_option(Plugin::OPTION)['provider.api_key']);
    }

    public function test_every_write_keeps_the_key_out_of_the_row_and_the_key_still_reaches_the_provider(): void
    {
        $store = Plugin::instance()->get(Store::class);
        // The URL first: moving it after the key would clear the key (Schema::providerKeyClearedByMove()).
        $store->replace(['provider.base_url' => 'http://ollama:11434', 'models.default' => 'llama3.2', 'provider.kind' => 'ollama']);
        $store->set('provider.api_key', 'sk-FAKE-store');
        $this->assertKeyKeptOutOfTheRow('sk-FAKE-store');
        // The Store's memo after a write is what the filters left: the mask.
        $this->assertSame(Schema::MASK, $store->get('provider.api_key'));
        $store->replace(['provider.api_key' => Schema::MASK, 'models.num_ctx' => 2048]);
        $this->assertKeyKeptOutOfTheRow('sk-FAKE-store');
        // A raw update_option() from other code goes through the same filter.
        update_option(Plugin::OPTION, ['provider.api_key' => 'sk-FAKE-raw'] + $store->all());
        $this->assertKeyKeptOutOfTheRow('sk-FAKE-raw');

        // The provider as Factory built it, before the suite's offline guard swaps it on the
        // `alpaca_bot/provider` filter (bootstrap.php): what would have gone on the wire.
        $provider = (new \ReflectionMethod(Factory::class, 'ollama'))->invoke(Plugin::instance()->get(Factory::class), 'llama3.2');
        $client = (new \ReflectionProperty(AbstractProvider::class, 'httpClient'))->getValue($provider);
        $this->assertInstanceOf(BearerHttpClient::class, $client);
        $this->assertSame('sk-FAKE-raw', (new \ReflectionProperty(BearerHttpClient::class, 'token'))->getValue($client));

        $store->set('provider.api_key', '');
        $this->assertSame('', get_option(Plugin::OPTION)['provider.api_key']);
        $this->assertFalse(get_option(ProviderKey::OPTION));
        $this->assertNull($this->autoloadOf(ProviderKey::OPTION));
    }

    // The row reads MASK before and after a change of key, so the row's own update_option() may
    // change nothing and fire no `update_option_alpaca_bot_settings` at all; the model list of the
    // old key must still go.
    public function test_a_change_of_key_alone_drops_the_cached_model_list(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->set('provider.api_key', 'sk-FAKE-one');
        set_transient(ModelCatalog::TRANSIENT, ['stale'], 300);
        $store->set('provider.api_key', 'sk-FAKE-two');
        $this->assertFalse(get_transient(ModelCatalog::TRANSIENT));
        $this->assertSame('sk-FAKE-two', get_option(ProviderKey::OPTION));
        set_transient(ModelCatalog::TRANSIENT, ['stale'], 300);
        $store->set('provider.api_key', '');
        $this->assertFalse(get_transient(ModelCatalog::TRANSIENT));
    }

    // The other side of the bust: a save that changes none of the provider's kind, base URL or key
    // keeps the cached list. The row is really rewritten (its update hook fires, the temperature
    // changes) and the key is still held afterwards, so the list surviving is the row hook's
    // comparison at work, not a write that never happened. Two writers: Store, and a whole row
    // posted with MASK for the key, as the settings page posts it.
    public function test_a_save_that_keeps_the_key_keeps_the_cached_model_list(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->set('provider.api_key', 'sk-FAKE-keep');
        $rowUpdates = 0;
        $count = static function () use (&$rowUpdates): void {
            ++$rowUpdates;
        };
        add_action('update_option_' . Plugin::OPTION, $count);

        set_transient(ModelCatalog::TRANSIENT, ['kept'], 300);
        $store->set('models.temperature', 1.2);
        $this->assertSame(1, $rowUpdates);
        $this->assertSame(1.2, get_option(Plugin::OPTION)['models.temperature']);
        $this->assertSame(['kept'], get_transient(ModelCatalog::TRANSIENT));

        update_option(Plugin::OPTION, array_replace(get_option(Plugin::OPTION), ['models.temperature' => 0.3, 'provider.api_key' => Schema::MASK]));
        remove_action('update_option_' . Plugin::OPTION, $count);
        $this->assertSame(2, $rowUpdates);
        $this->assertSame('sk-FAKE-keep', ProviderKey::held());
        $this->assertSame(['kept'], get_transient(ModelCatalog::TRANSIENT));
    }

    // Task 4's rule over the new shape: the row holds MASK, which the move still reads as a key to
    // clear, and "cleared" is '' in the row and no key option.
    public function test_moving_the_base_url_clears_the_held_key_as_well_as_the_row(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->replace(['provider.base_url' => 'https://openrouter.ai/api/v1', 'provider.api_key' => 'sk-FAKE-move']);
        $this->assertTrue(Schema::providerKeyClearedByMove(['provider.base_url' => 'https://steal.example.net/v1'], $store->all()));
        $store->set('provider.base_url', 'https://steal.example.net/v1');
        $this->assertSame('', get_option(Plugin::OPTION)['provider.api_key']);
        $this->assertFalse(get_option(ProviderKey::OPTION));
    }
    /**
     * The row says MASK, but `alpaca_bot_provider_key` is gone: deleted outside the plugin, or
     * never written by a raw edit of the row. Nothing is sent (ProviderKey::resolve() answers '').
     */
    private function maskWithNoKeyHeld(): Store
    {
        $store = Plugin::instance()->get(Store::class);
        $store->replace(['provider.base_url' => 'https://openrouter.ai/api/v1', 'provider.api_key' => 'sk-FAKE-gone']);
        delete_option(ProviderKey::OPTION);
        $this->assertSame(Schema::MASK, get_option(Plugin::OPTION)['provider.api_key']);
        $this->assertSame('', ProviderKey::resolve($store->get('provider.api_key')));
        return $store;
    }

    /** @param list<string> $out @param list<string> $warned */
    private function command(array &$out, array &$warned): ChatCommand
    {
        $plugin = Plugin::instance();
        return new ChatCommand(
            $plugin->get(Pipeline::class),
            $plugin->get(ModelCatalog::class),
            $plugin->get(UsageMeter::class),
            $plugin->get(Store::class),
            write: static function (string $s) use (&$out): void {
                $out[] = $s;
            },
            fail: static function (string $m): void {
                throw new \RuntimeException($m);
            },
            servers: $plugin->get(ServerSettings::class),
            warn: static function (string $m) use (&$warned): void {
                $warned[] = $m;
            },
        );
    }

    // #4699: the REST read and the CLI dump show no key when none is held, whatever the row says,
    // and still show the mask when one is.
    public function test_a_row_that_says_mask_with_no_key_held_reads_as_no_key_over_rest_and_the_cli(): void
    {
        $store = $this->maskWithNoKeyHeld();
        $this->asAdmin();
        $this->assertSame('', $this->rest('GET', '/settings')->get_data()['provider.api_key']);
        $out = [];
        $warned = [];
        $this->command($out, $warned)->settings([], []);
        $this->assertSame('', json_decode(implode('', $out), true)['provider.api_key']);

        $store->set('provider.api_key', 'sk-FAKE-back');
        $this->assertSame(Schema::MASK, $this->rest('GET', '/settings')->get_data()['provider.api_key']);
        $out = [];
        $this->command($out, $warned)->settings([], []);
        $this->assertSame(Schema::MASK, json_decode(implode('', $out), true)['provider.api_key']);
    }

    // #4699 and #4539: a move over a row that says MASK with no key held clears nothing, so neither
    // the REST header nor the CLI warning names a cleared key.
    public function test_a_move_names_no_cleared_key_when_the_row_says_mask_but_none_is_held(): void
    {
        $this->maskWithNoKeyHeld();
        $this->asAdmin();
        $response = $this->rest('PUT', '/settings', ['provider.base_url' => 'https://steal.example.net/v1']);
        $this->assertSame(200, $response->get_status());
        $this->assertArrayNotHasKey('X-Alpaca-Bot-Cleared', $response->get_headers());
        $this->assertSame('', $response->get_data()['provider.api_key']);

        $this->maskWithNoKeyHeld();
        $out = [];
        $warned = [];
        $this->command($out, $warned)->settings(['provider.base_url', 'https://steal.example.net/v2'], []);
        $this->assertSame([], $warned);
        $this->assertSame('https://steal.example.net/v2', get_option(Plugin::OPTION)['provider.base_url']);
    }

    // #4700: saving '' over a row still carrying a plaintext key, with no key option yet, changes
    // the key the site uses (the plaintext one, to none) and writes no key option, since nothing
    // was held to delete. The model list answered with the old key goes all the same.
    public function test_clearing_a_plaintext_key_no_option_holds_yet_drops_the_cached_model_list(): void
    {
        $this->seed060Row('sk-FAKE-plain');
        $this->assertFalse(get_option(ProviderKey::OPTION));
        set_transient(ModelCatalog::TRANSIENT, ['stale'], 300);
        $keyWrites = 0;
        $count = static function (string $option) use (&$keyWrites): void {
            $keyWrites += $option === ProviderKey::OPTION ? 1 : 0;
        };
        foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
            add_action($hook, $count);
        }
        Plugin::instance()->get(Store::class)->set('provider.api_key', '');
        $this->assertSame(0, $keyWrites);
        $this->assertSame('', $this->rawRow()['provider.api_key']);
        $this->assertFalse(get_transient(ModelCatalog::TRANSIENT));
    }
    // #4700: deleting the settings row changes the key the site uses to none (a row read back as
    // the defaults holds '', whatever the key option still holds), and the list goes with it.
    public function test_deleting_the_settings_row_drops_the_cached_model_list(): void
    {
        Plugin::instance()->get(Store::class)->set('provider.api_key', 'sk-FAKE-row');
        set_transient(ModelCatalog::TRANSIENT, ['stale'], 300);
        delete_option(Plugin::OPTION);
        $this->assertSame('', ProviderKey::resolve(get_option(Plugin::OPTION, Schema::defaults())['provider.api_key']));
        $this->assertFalse(get_transient(ModelCatalog::TRANSIENT));
    }

    // Plugin.php's account of the move: a plaintext key lifted out of the row, unchanged, busts the
    // list twice, once on the key option's add and once on the row's plaintext going to MASK.
    public function test_the_migration_of_an_unchanged_plaintext_key_busts_the_list_twice(): void
    {
        $this->seed060Row('sk-FAKE-twice');
        $busts = 0;
        $count = static function () use (&$busts): void {
            ++$busts;
        };
        add_action('delete_transient_' . ModelCatalog::TRANSIENT, $count);
        $this->runInitMigrations();
        remove_action('delete_transient_' . ModelCatalog::TRANSIENT, $count);
        $this->assertKeyKeptOutOfTheRow('sk-FAKE-twice');
        $this->assertSame(2, $busts);
    }

    // #4699's cost: the three screens that show the key read its option once each over a row that
    // says MASK, and the front end, which shows none of them, reads it no more than before. Its one
    // read is the provider built for the model list (Factory, the sender), so the list is fetched
    // first, as a cached one would already be, and what is left of the page must read nothing.
    public function test_showing_the_key_costs_one_option_read_on_rest_and_the_cli_and_none_on_the_front_end(): void
    {
        Plugin::instance()->get(Store::class)->set('provider.api_key', 'sk-FAKE-cost');
        Plugin::instance()->get(ModelCatalog::class)->all();
        $reads = 0;
        $count = static function (mixed $pre) use (&$reads): mixed {
            ++$reads;
            return $pre;
        };
        add_filter('pre_option_' . ProviderKey::OPTION, $count);
        $this->asAdmin();

        $html = do_shortcode('[alpacabot]');
        $this->assertNotSame('', $html);
        $this->assertSame(0, $reads, 'the front end');

        $this->assertSame(Schema::MASK, $this->rest('GET', '/settings')->get_data()['provider.api_key']);
        $this->assertSame(1, $reads, 'the REST read');

        $out = [];
        $warned = [];
        $this->command($out, $warned)->settings([], []);
        $this->assertSame(2, $reads, 'the CLI dump');
    }
}
