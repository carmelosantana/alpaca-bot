<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;

/**
 * The daily usage-receipt cleanup over real rows: only chat_log posts older than the window
 * go, a conversation of the same age stays, 0 keeps everything, and the cron event exists.
 *
 * @group cron
 */
final class UsageRetentionTest extends TestCase
{
    private function row(string $type, int $daysAgo): int
    {
        $stamp = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400);
        return self::factory()->post->create(['post_type' => $type, 'post_status' => 'private', 'post_date' => $stamp, 'post_date_gmt' => $stamp, 'post_title' => "$type $daysAgo"]);
    }

    public function test_cleanup_deletes_old_receipts_only(): void
    {
        $oldLog = $this->row(UsageMeter::POST_TYPE, 100);
        $recentLog = $this->row(UsageMeter::POST_TYPE, 1);
        $oldConversation = $this->row(ConversationStore::POST_TYPE, 100);
        $meter = Plugin::instance()->get(UsageMeter::class);

        Plugin::instance()->get(Store::class)->set('privacy.usage_retention_days', 0);
        $this->assertSame(0, $meter->cleanup());
        $this->assertNotNull(get_post($oldLog));

        Plugin::instance()->get(Store::class)->set('privacy.usage_retention_days', 90);
        $this->assertSame(1, $meter->cleanup());
        $this->assertNull(get_post($oldLog));
        $this->assertNotNull(get_post($recentLog));
        $this->assertNotNull(get_post($oldConversation));
        $this->assertSame(0, $meter->cleanup());
    }

    public function test_the_daily_event_is_scheduled_on_init_and_has_a_listener(): void
    {
        $meter = Plugin::instance()->get(UsageMeter::class);
        $this->assertNotFalse(has_action(UsageMeter::CLEANUP_HOOK));
        wp_clear_scheduled_hook(UsageMeter::CLEANUP_HOOK);
        $this->assertFalse(wp_next_scheduled(UsageMeter::CLEANUP_HOOK));
        do_action('init');
        $next = wp_next_scheduled(UsageMeter::CLEANUP_HOOK);
        $this->assertNotFalse($next);
        $this->assertSame('daily', wp_get_schedule(UsageMeter::CLEANUP_HOOK));
        // Idempotent: a second init leaves the one event alone.
        do_action('init');
        $this->assertSame($next, wp_next_scheduled(UsageMeter::CLEANUP_HOOK));
        UsageMeter::unscheduleCleanup();
        $this->assertFalse(wp_next_scheduled(UsageMeter::CLEANUP_HOOK));
    }
}
