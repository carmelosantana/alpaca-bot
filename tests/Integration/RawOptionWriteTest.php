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

    /** @param bool $inCommand what the takeover's call-stack check answers; true stands in for WP-CLI's Option_Command */
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
            inCommand: $inCommand ? static fn(): bool => true : null,
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

    public function tear_down(): void
    {
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
}
