<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;

/**
 * What a cold monthly summary costs in database queries, over real rows.
 *
 * This runs on every provider call a capped site makes, synchronously ahead of the model
 * (CapPolicy::assertAllowed()), whenever the hourly transient has expired. `get_posts()`
 * with `fields => 'ids'` returns before WP_Query primes the post caches, so without a prime of
 * its own each get_post_meta() of the loop is its own query and the cost grows with the number
 * of receipts in the month. The assertion is on the shape, not on a constant: the query count
 * for twenty receipts must equal the count for four, which is only true while the meta read is
 * primed in one query for the whole set.
 *
 * get_num_queries() rather than a wpdb spy: it is core's own counter, and it counts every query
 * the call makes, including any a filter on the way adds.
 *
 * @group performance
 */
final class UsageSummaryQueriesTest extends TestCase
{
    /** `$n` receipts for `$userId`, stamped now, each with its own total_tokens meta row. */
    private function receipts(int $userId, int $n): void
    {
        $stamp = gmdate('Y-m-d H:i:s');
        for ($i = 0; $i < $n; $i++) {
            $id = self::factory()->post->create([
                'post_type' => UsageMeter::POST_TYPE,
                'post_status' => 'private',
                'post_author' => $userId,
                'post_date' => $stamp,
                'post_date_gmt' => $stamp,
                'post_title' => 'receipt ' . $i,
            ]);
            update_post_meta($id, 'total_tokens', 10);
        }
    }

    /** A cold summary for `$userId`: the transient dropped first, so the query walk actually runs. */
    private function coldSummaryQueries(UsageMeter $meter, int $userId, int &$tokens): int
    {
        delete_transient('alpaca_bot_usage_' . $userId . '_' . gmdate('Y-m', (int) current_time('timestamp', true)));
        wp_cache_flush();
        $before = get_num_queries();
        $tokens = $meter->monthSummary($userId)['tokens'];
        return get_num_queries() - $before;
    }

    public function test_a_cold_month_summary_costs_the_same_number_of_queries_however_many_receipts_the_month_holds(): void
    {
        $meter = Plugin::instance()->get(UsageMeter::class);
        $small = self::factory()->user->create(['role' => 'author']);
        $large = self::factory()->user->create(['role' => 'author']);
        $this->receipts($small, 4);
        $this->receipts($large, 20);

        $smallTokens = 0;
        $largeTokens = 0;
        $smallQueries = $this->coldSummaryQueries($meter, $small, $smallTokens);
        $largeQueries = $this->coldSummaryQueries($meter, $large, $largeTokens);

        // The totals prove the walk read every receipt's meta, so the query count is a count of
        // the same work done, not of work skipped.
        $this->assertSame(40, $smallTokens);
        $this->assertSame(200, $largeTokens);
        $this->assertSame(
            $smallQueries,
            $largeQueries,
            "4 receipts cost {$smallQueries} queries and 20 cost {$largeQueries}: the meta read is one query per receipt.",
        );
    }
}
