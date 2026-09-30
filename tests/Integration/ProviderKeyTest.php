<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

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
        // Before the migration has run, the key is still read from the row.
        $this->assertSame('sk-FAKE-060', ProviderKey::resolve(Plugin::instance()->get(Store::class)->get('provider.api_key')));

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
        ProviderKey::migrate();

        $this->assertSame(0, $writes);
        $this->assertSame($row, $this->rawRow());
        $this->assertSame('sk-FAKE-060', get_option(ProviderKey::OPTION));
        $this->assertSame($autoload, $this->autoloadOf(ProviderKey::OPTION));
    }

    public function test_every_write_keeps_the_key_out_of_the_row_and_the_key_still_reaches_the_provider(): void
    {
        $store = Plugin::instance()->get(Store::class);
        // The URL first: moving it after the key would clear the key (Schema::providerKeyClearedByMove()).
        $store->replace(['provider.base_url' => 'http://ollama:11434', 'models.default' => 'llama3.2', 'provider.kind' => 'ollama']);
        $store->set('provider.api_key', 'sk-FAKE-store');
        $this->assertKeyKeptOutOfTheRow('sk-FAKE-store');
        $store->replace(['provider.api_key' => Schema::MASK, 'models.num_ctx' => 2048]);
        $this->assertKeyKeptOutOfTheRow('sk-FAKE-store');
        // A raw update_option(), as `wp option update` makes, goes through the same filter.
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
}
