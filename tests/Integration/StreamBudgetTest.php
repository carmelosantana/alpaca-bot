<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Plugin;
use AlpacaBot\Rest\StreamBudget;
use AlpacaBot\Settings\Store;

/**
 * The concurrency cap against a real database, which is where its whole argument lives: the unit
 * suite runs the claim over a double that models the UNIQUE index on `option_name`, and this is
 * the check that the index, `INSERT IGNORE` and the affected-row count behave as that double
 * says on the server the plugin actually runs against.
 *
 * A read-modify-write over a transient — what this cap was first built as — cannot be tested
 * this way, because there is no operation in it that a second caller can lose.
 *
 * @group rest
 */
final class StreamBudgetTest extends TestCase
{
    private function budget(): StreamBudget
    {
        return new StreamBudget(Plugin::instance()->get(Store::class));
    }

    /** @return list<string> the slot rows this person holds, by option_name */
    private function rows(int $userId): array
    {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like(StreamBudget::OPTION . $userId . '_') . '%'));
        return array_map('strval', $names);
    }

    public function test_thirty_redemptions_from_one_account_hold_LIMIT_slots_and_no_more(): void
    {
        $budget = $this->budget();
        $held = [];
        $refusals = 0;
        foreach (range(1, 30) as $n) {
            $claim = $budget->claim(7);
            if ($claim['slot'] === null) {
                $refusals++;
                // The wait is a ceiling, and never longer than one turn's whole budget.
                $this->assertGreaterThan(0, $claim['retry_after']);
                $this->assertLessThanOrEqual($budget->seconds(), $claim['retry_after']);
                continue;
            }
            $held[] = $claim['slot'];
        }
        $this->assertCount(StreamBudget::LIMIT, $held);
        $this->assertSame(30 - StreamBudget::LIMIT, $refusals);
        $this->assertSame([
            StreamBudget::OPTION . '7_0',
            StreamBudget::OPTION . '7_1',
            StreamBudget::OPTION . '7_2',
        ], $this->rows(7));

        // Given back, the row goes with it, and the next redemption gets in.
        $budget->release(7, $held[1]);
        $this->assertSame([StreamBudget::OPTION . '7_0', StreamBudget::OPTION . '7_2'], $this->rows(7));
        $this->assertIsString($budget->claim(7)['slot']);
    }

    public function test_the_claim_is_the_insert_the_unique_key_decides_and_the_row_is_not_autoloaded(): void
    {
        global $wpdb;
        $this->assertIsString($this->budget()->claim(7)['slot']);
        $name = StreamBudget::OPTION . '7_0';
        $before = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));

        // The same statement the claim runs, for a slot that is taken. MySQL answers 0 affected
        // rows because the index refuses the duplicate — not because anything here read first,
        // which is the property a transient counter cannot have — and the holder's value stands.
        $again = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off')", $name, '9999999999:someone_else'));
        $this->assertSame(0, $again);
        $this->assertSame($before, $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)));
        $this->assertSame(1, $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off')", $name . '_free', 'x')));

        // Autoload off, so however many slot rows a busy site holds, none of them is loaded on
        // every request. Core's alloptions query takes only the on/auto values.
        $this->assertSame('off', $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name)));
        $this->assertArrayNotHasKey($name, wp_load_alloptions(true));
    }

    public function test_a_lapsed_lease_is_taken_over_and_a_release_leaves_its_successor_alone(): void
    {
        global $wpdb;
        $budget = $this->budget();
        $mine = (string) $budget->claim(7)['slot'];
        $name = StreamBudget::OPTION . '7_0';
        // The process that held it was killed: no release() ran, and the lease has lapsed.
        $wpdb->query($wpdb->prepare("UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s", '1:dead', $name));

        $next = (string) $this->budget()->claim(7)['slot'];
        $this->assertStringStartsWith('0:', $next);
        $this->assertSame(substr($next, 2), $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)));

        // The first holder's release names its own value, so it takes nothing from the stream
        // that has the row now.
        $budget->release(7, $mine);
        $this->assertSame(substr($next, 2), $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)));
        $this->budget()->release(7, $next);
        $this->assertSame([], $this->rows(7));
    }
}
