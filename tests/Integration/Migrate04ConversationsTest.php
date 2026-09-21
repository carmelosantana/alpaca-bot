<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;

/**
 * The conversation pass against a real posts table, with another plugin's filter keeping every
 * legacy row in `publish` (Kanboard #4335). The unit suite can only assert the query it hands
 * get_posts(); what the meta query leaves out, and whether the pass ends, is the database's to say.
 *
 * @group migration
 */
final class Migrate04ConversationsTest extends TestCase
{
    /** Every step but the conversation pass is done, so run() is that pass and nothing else. */
    private function onlyConversationsPending(): void
    {
        foreach ([Migrate04::FLAG, Migrate04::FLAG_RETENTION, Migrate04::FLAG_AUTOLOAD] as $flag) {
            update_option($flag, '1', true);
        }
        delete_option(Migrate04::FLAG_CONVERSATIONS);
    }

    public function test_a_full_batch_another_plugin_keeps_in_publish_is_tried_max_attempts_times_and_then_left(): void
    {
        $this->onlyConversationsPending();
        $owner = self::factory()->user->create();
        // A full batch. Fewer rows than BATCH end the pass on the first request whatever became of
        // them, which is not the case the bound is for.
        $ids = self::factory()->post->create_many(100, ['post_type' => ConversationStore::POST_TYPE, 'post_status' => 'publish', 'post_author' => $owner]);
        $tries = 0;
        add_filter('wp_insert_post_data', static function (array $data, array $postarr) use (&$tries): array {
            if (($data['post_type'] ?? '') === ConversationStore::POST_TYPE && !empty($postarr['ID'])) {
                ++$tries;
                $data['post_status'] = 'publish';
            }
            return $data;
        }, 10, 2);

        for ($request = 1; $request <= Migrate04::MAX_ATTEMPTS; $request++) {
            $migration = new Migrate04(new Store());
            $this->assertTrue($migration->needed(), "request {$request} still has work");
            $migration->run();
            $this->assertSame(100 * $request, $tries, "request {$request} tried every row once");
            $this->assertFalse(get_option(Migrate04::FLAG_CONVERSATIONS), 'a full batch leaves the pass open');
        }
        foreach ($ids as $id) {
            $this->assertSame('publish', get_post_status($id));
            $this->assertSame((string) Migrate04::MAX_ATTEMPTS, get_post_meta($id, Migrate04::META_ATTEMPTS, true));
        }

        // The next request asks for none of them, writes none, and ends the pass.
        (new Migrate04(new Store()))->run();
        $this->assertSame(100 * Migrate04::MAX_ATTEMPTS, $tries);
        $this->assertSame('1', get_option(Migrate04::FLAG_CONVERSATIONS));
        $this->assertFalse((new Migrate04(new Store()))->needed());
    }

    public function test_rows_that_move_carry_no_attempt_count(): void
    {
        $this->onlyConversationsPending();
        $owner = self::factory()->user->create();
        $ids = self::factory()->post->create_many(3, ['post_type' => ConversationStore::POST_TYPE, 'post_status' => 'publish', 'post_author' => $owner]);

        (new Migrate04(new Store()))->run();

        foreach ($ids as $id) {
            $this->assertSame('private', get_post_status($id));
            $this->assertSame('', get_post_meta($id, Migrate04::META_ATTEMPTS, true));
        }
        $this->assertSame('1', get_option(Migrate04::FLAG_CONVERSATIONS));
    }
}
