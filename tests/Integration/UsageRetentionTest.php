<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;

/**
 * The daily usage-receipt cleanup over real rows: only chat_log posts older than the window
 * go, a conversation of the same age stays, 0 keeps everything, and the cron event exists.
 *
 * @group cron
 */
final class UsageRetentionTest extends TestCase
{
    private function row(string $type, int $daysAgo, string $status = 'private'): int
    {
        $stamp = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400);
        return self::factory()->post->create(['post_type' => $type, 'post_status' => $status, 'post_date' => $stamp, 'post_date_gmt' => $stamp, 'post_title' => "$type $daysAgo"]);
    }

    // 'any' leaves the trash out, and a trashed receipt is still a row of per-user numbers.
    public function test_cleanup_removes_a_trashed_old_receipt_too(): void
    {
        $trashed = $this->row(UsageMeter::POST_TYPE, 100, 'trash');
        $legacyPublished = $this->row(UsageMeter::POST_TYPE, 100, 'publish');
        Plugin::instance()->get(Store::class)->set('privacy.usage_retention_days', 90);
        $this->assertSame(2, Plugin::instance()->get(UsageMeter::class)->cleanup());
        $this->assertNull(get_post($trashed));
        $this->assertNull(get_post($legacyPublished));
    }

    /**
     * The field arrived after sites were recording receipts: a site with a settings row from
     * before it, or with receipts and no row yet (0.4), gets 0 written so its history is not
     * deleted a day after the upgrade; only a fresh install takes the default. The rows here
     * are written without the sanitize callback (this test class never registers the setting),
     * which is how a row from before the field looks.
     */
    public function test_the_retention_field_arrives_as_0_where_there_is_history_and_keeps_its_default_on_a_fresh_install(): void
    {
        // Fresh: no settings row, no receipts. Nothing is written but the flag.
        delete_option(Plugin::OPTION);
        delete_option(Migrate04::FLAG_RETENTION);
        $migration = new Migrate04(new Store());
        $this->assertTrue($migration->needed());
        $migration->run();
        $this->assertNull(get_option(Plugin::OPTION, null));
        $this->assertSame(90, (new Store())->get('privacy.usage_retention_days'));
        $this->assertSame('1', get_option(Migrate04::FLAG_RETENTION));
        $this->assertFalse((new Migrate04(new Store()))->needed());

        // A 1.0 row from before the field: 0, and the rest of the row as it was.
        delete_option(Migrate04::FLAG_RETENTION);
        update_option(Plugin::OPTION, ['models.default' => 'qwen3-vl:2b', 'provider.base_url' => 'http://ollama.internal:11434/v1']);
        $this->assertArrayNotHasKey('privacy.usage_retention_days', get_option(Plugin::OPTION));
        (new Migrate04(new Store()))->run();
        $row = get_option(Plugin::OPTION);
        $this->assertSame(0, $row['privacy.usage_retention_days']);
        $this->assertSame('qwen3-vl:2b', $row['models.default']);
        $this->assertSame('http://ollama.internal:11434/v1', $row['provider.base_url']);

        // A row that carries the field was saved by someone who saw it.
        delete_option(Migrate04::FLAG_RETENTION);
        $row['privacy.usage_retention_days'] = 45;
        update_option(Plugin::OPTION, $row);
        (new Migrate04(new Store()))->run();
        $this->assertSame(45, get_option(Plugin::OPTION)['privacy.usage_retention_days']);

        // 0.4: receipts (published, as 0.4 wrote them) and no settings row.
        delete_option(Plugin::OPTION);
        delete_option(Migrate04::FLAG_RETENTION);
        $this->row(UsageMeter::POST_TYPE, 3, 'publish');
        (new Migrate04(new Store()))->run();
        $this->assertSame(0, get_option(Plugin::OPTION)['privacy.usage_retention_days']);
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
