<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;

/**
 * The migration's completion flags against a real options table.
 *
 * The unit suite mocks update_option() and can only assert the argument it was handed, which
 * says nothing about the row: not what autoload column WordPress actually wrote, and not what
 * happens when the row is already there under the old value — the case that turned out to
 * matter. Both are asserted here, on rows core rolls back with the test's transaction.
 *
 * "Autoloaded" is read as core reads it: the raw `autoload` column against
 * wp_autoload_values_to_autoload(), the set of values wp_load_alloptions() collects (WordPress
 * 6.6 renamed the values from yes/no to on/off and kept both readable, so the string itself is
 * not the assertion).
 *
 * @group migration
 */
final class Migrate04AutoloadTest extends TestCase
{
    private const FLAGS = [Migrate04::FLAG, Migrate04::FLAG_RETENTION, Migrate04::FLAG_CONVERSATIONS];

    /** The row's autoload column, or null when there is no row. */
    private function autoloadOf(string $option): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));

        return $value === null ? null : (string) $value;
    }

    private function assertAutoloaded(string $option): void
    {
        $this->assertContains($this->autoloadOf($option), wp_autoload_values_to_autoload(), "$option is not autoloaded");
    }

    private function assertNotAutoloaded(string $option): void
    {
        $this->assertNotContains($this->autoloadOf($option), wp_autoload_values_to_autoload(), "$option is autoloaded");
    }

    private function forget(): void
    {
        foreach ([...self::FLAGS, Migrate04::FLAG_AUTOLOAD] as $flag) {
            delete_option($flag);
        }
    }

    /**
     * needed() runs on `init` on every request the site serves, so once migration is done its
     * three flag reads must cost no query: written autoloaded they ride the single alloptions
     * read WordPress already does. This is the effect, not the argument — the column WordPress
     * put in the row, and the key's presence in wp_load_alloptions().
     */
    public function test_the_completion_flags_land_autoloaded_on_a_fresh_install(): void
    {
        $this->forget();

        $migration = new Migrate04(new Store());
        $this->assertTrue($migration->needed());
        $migration->run();

        foreach (self::FLAGS as $flag) {
            $this->assertSame('1', get_option($flag));
            $this->assertAutoloaded($flag);
        }
        $this->assertAutoloaded(Migrate04::FLAG_AUTOLOAD);

        wp_cache_delete('alloptions', 'options');
        $alloptions = wp_load_alloptions(true);
        foreach ([...self::FLAGS, Migrate04::FLAG_AUTOLOAD] as $flag) {
            $this->assertArrayHasKey($flag, $alloptions);
        }
        $this->assertFalse((new Migrate04(new Store()))->needed());
    }

    /**
     * A site that completed the migration under a pre-release 0.5 holds the three flags
     * non-autoloaded, and nothing rewrites a flag once it is set: every *Pending() returns false
     * the moment its row exists, so run()'s autoloaded writes are unreachable there. The
     * one-time repair under FLAG_AUTOLOAD is what reaches it. Without that repair this test
     * fails on the first assertAutoloaded() below.
     */
    public function test_a_pre_release_site_with_non_autoloaded_flags_is_repaired_once(): void
    {
        $this->forget();
        foreach (self::FLAGS as $flag) {
            add_option($flag, '1', '', false);
            $this->assertNotAutoloaded($flag);
        }

        $migration = new Migrate04(new Store());
        $this->assertTrue($migration->needed(), 'the repair is what makes the migration needed here');
        $migration->run();

        foreach (self::FLAGS as $flag) {
            $this->assertSame('1', get_option($flag), 'the repair moves the row, it does not rewrite the value');
            $this->assertAutoloaded($flag);
        }

        // Once, and not again: the second request finds nothing pending.
        $this->assertSame('1', get_option(Migrate04::FLAG_AUTOLOAD));
        $this->assertFalse((new Migrate04(new Store()))->needed());
    }
}
