<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\Assets;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Plugin;

/**
 * The stored transcript against the real database's packet limit (Kanboard #2977, carried from
 * #2976): a transcript whose images together cross `max_allowed_packet` is written with its
 * oldest images evicted, and read back whole. The companion probe shows the failure the fit
 * exists for, against the same database: the same rows through one bare update_post_meta(),
 * as save() wrote them before, return false and store nothing.
 *
 * The fixture adjusts itself to the site it runs on: image turns at the site's own allowance
 * (Assets::maxImageBytes()) are appended until the serialised rows cross the packet the server
 * reports, so the premise is measured, not assumed. On alpaca10 (post_max_size 8M, packet 16
 * MiB) that is three images: two at the allowance come to 16,646,144 base64 bytes, 128 KiB
 * short of the packet.
 */
final class ConversationStoreTest extends TestCase
{
    /** A PNG data URL carrying `$bytes` decoded bytes (4 base64 characters per 3 bytes), `$fill` repeated so two are told apart. */
    private static function image(int $bytes, string $fill): string
    {
        return 'data:image/png;base64,' . str_repeat($fill, intdiv($bytes, 3) * 4);
    }

    /** @return array{0: Conversation, 1: int, 2: int} the conversation, the packet, and the serialised size that crosses it */
    private function transcriptPastThePacket(int $uid, ConversationStore $store): array
    {
        global $wpdb;
        $packet = (int) $wpdb->get_var('SELECT @@max_allowed_packet');
        $this->assertGreaterThan(0, $packet);
        $cap = Assets::maxImageBytes();
        $this->assertGreaterThan(0, $cap, 'the site reports an image allowance');
        $c = $store->create($uid);
        $this->assertGreaterThan(0, $c->id);
        $size = 0;
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $fill) {
            $c->append(new Message('user', "turn {$i}", 'fake-model', null, 0, [self::image($cap, $fill)]));
            $c->append(new Message('assistant', "reply {$i}", 'fake-model'));
            $size = strlen(maybe_serialize(array_map(static fn(Message $m): array => $m->toArray(), $c->messages)));
            if ($size > $packet) {
                break;
            }
        }
        $this->assertGreaterThan($packet, $size, 'the fixture crosses max_allowed_packet on this database');
        fwrite(STDERR, sprintf("\n[#2977] max_allowed_packet=%d image cap=%d images=%d serialised=%d\n", $packet, $cap, count($c->messages) / 2, $size));
        return [$c, $packet, $size];
    }

    public function test_a_transcript_past_max_allowed_packet_is_stored_with_its_oldest_images_evicted_and_reads_back(): void
    {
        $uid = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        [$c, $packet] = $this->transcriptPastThePacket($uid, $store);
        $images = count($c->messages) / 2;

        $store->save($c);

        wp_cache_delete($c->id, 'post_meta');
        $stored = get_post_meta($c->id, ConversationStore::META_MESSAGES, true);
        $this->assertIsArray($stored, 'the transcript is there');
        $this->assertCount(2 * $images, $stored, 'no message was dropped: images went first and made room');
        $this->assertLessThanOrEqual(ConversationStore::storageBudget(), strlen(maybe_serialize($stored)));
        $this->assertLessThan($packet, strlen(maybe_serialize($stored)));

        // Oldest first: the first turn lost its image and says so; the newest still has its own.
        $this->assertSame([], $stored[0]['images']);
        $this->assertSame(1, ((array) $stored[0]['meta'])['images_evicted'] ?? null);
        $newest = $stored[2 * $images - 2];
        $this->assertCount(1, $newest['images']);
        $this->assertSame(self::image(Assets::maxImageBytes(), chr(ord('A') + $images - 1)), $newest['images'][0]);
        // Everything evicted is accounted for, and what remains is a suffix of the turns.
        $evicted = 0;
        $kept = 0;
        $seenKept = false;
        foreach ($stored as $row) {
            $meta = (array) $row['meta'];
            $evicted += (int) ($meta['images_evicted'] ?? 0);
            $kept += count($row['images']);
            if ($row['images'] !== []) {
                $seenKept = true;
            } elseif ($seenKept && $row['role'] === 'user') {
                $this->fail('an older turn kept its image while a newer one lost its own');
            }
        }
        $this->assertSame($images, $evicted + $kept);
        $this->assertGreaterThan(0, $evicted);
        $this->assertGreaterThan(0, $kept);

        // The in-memory conversation agrees with what was stored, and load() reads the marker back through Message.
        $this->assertSame([], $c->messages[0]->images);
        $this->assertSame(1, $c->messages[0]->meta['images_evicted']);
        $loaded = $store->load($c->id, $uid);
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->messages[0]->meta['images_evicted']);
        $this->assertCount(1, $loaded->messages[2 * $images - 2]->images);
        fwrite(STDERR, sprintf("[#2977] after save(): storageBudget=%d stored=%d bytes, rows=%d, images kept=%d, evicted=%d\n", ConversationStore::storageBudget(), strlen(maybe_serialize($stored)), count($stored), $kept, $evicted));
    }

    /**
     * The before: the rows save() would have written unfitted, through the one call it made,
     * update_post_meta(). The write fails at the server, the false goes back unchecked (as it
     * did), and the row has no transcript. The server also closes the connection on that error
     * (core reconnects on the next query), which under wp-phpunit takes the test's transaction
     * with it, post and all: so this test ends at the failed write, and the fitted write is the
     * other test's proof. Under autocommit, as a site runs, the post stays and only the
     * transcript is missing; the report's scratch run against alpaca10's own database shows that.
     */
    public function test_the_same_rows_through_a_bare_update_post_meta_fail_against_this_database_and_store_nothing(): void
    {
        global $wpdb;
        $uid = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        [$c] = $this->transcriptPastThePacket($uid, $store);
        $rows = array_map(static fn(Message $m): array => $m->toArray(), $c->messages);

        $wpdb->suppress_errors(true);
        $result = update_post_meta($c->id, ConversationStore::META_MESSAGES, $rows);
        $error = $wpdb->last_error;
        $wpdb->suppress_errors(false);
        fwrite(STDERR, sprintf("[#2977] pre-fix update_post_meta() returned %s; wpdb last_error: %s\n", var_export($result, true), $error));

        $this->assertFalse($result);
        $this->assertStringContainsString('max_allowed_packet', $error);
        wp_cache_delete($c->id, 'post_meta');
        $this->assertSame('', get_post_meta($c->id, ConversationStore::META_MESSAGES, true), 'nothing was stored');
    }
}
