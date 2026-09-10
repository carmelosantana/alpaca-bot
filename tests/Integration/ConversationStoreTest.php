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
 * The fixture adjusts itself to the site it runs on: image turns at imageBytes() -- the site's
 * own allowance, held to half the packet, for the reason on that method -- are appended until
 * the serialised rows cross the packet the server reports, so the premise is measured, not
 * assumed. On alpaca10 (post_max_size 8M, packet 16 MiB) the allowance is the smaller of the two
 * and that is three images: two at the allowance come to 16,646,144 base64 bytes, 128 KiB short
 * of the packet. Under wp-env, where php.ini is far more generous, the half-packet bound takes
 * over and it is two.
 *
 * The second pair is the same A/B for text: `max_allowed_packet` is measured on the statement
 * wpdb sends, and the driver doubles every quote, backslash, NUL, newline, carriage return and
 * ^Z on the way, a cost base64 never pays. A transcript of quotes that serialises to well under
 * the budget is twice that on the wire; through a bare update_post_meta() it fails exactly as
 * the images did, and save() fits it by what the database will receive.
 *
 * The last pair is the backslash round trip (Kanboard #4110). Every write below unslashes what
 * it is handed, so a transcript, a title and a converted 0.4 transcript all have to be slashed
 * on the way in to come back out as they went in.
 */
final class ConversationStoreTest extends TestCase
{
    /**
     * A string that costs a backslash at every level WordPress touches: a Windows path, a regular
     * expression, the two-character `\n` sequence a code fence carries, a LaTeX fragment, and a
     * doubled pair. WordPress's post and metadata APIs wp_unslash() what they are given, so an
     * unslashed write stores this one level of backslashes lighter — `C:\Users\x` comes back
     * `C:Usersx` and `\d+` comes back `d+`.
     */
    private const BACKSLASHED = 'C:\Users\x, a regex \d+, a literal \n, LaTeX \frac{1}{2}, a doubled \\\\ pair';

    /**
     * The size of one fixture image: the site's own allowance, but never more than half the
     * packet. The allowance is php.ini's (Assets::maxImageBytes() reads post_max_size and
     * upload_max_filesize), and on a PHP configured generously -- wp-env's container reports
     * 768M -- one image at the allowance is a gigabyte of base64 by itself. That is minutes of
     * work and gigabytes of memory to build, and the server answers it by closing the connection
     * ("MySQL server has gone away") instead of with the packet error this fixture exists to
     * produce. Half the packet keeps the premise intact -- images that together cross it, oldest
     * evicted first -- and puts the arithmetic back where the test's subject is, the database.
     * On alpaca10, whose allowance is under that, nothing changes.
     */
    private static function imageBytes(int $packet): int
    {
        return min(Assets::maxImageBytes(), intdiv($packet, 2));
    }

    /** A PNG data URL carrying `$bytes` decoded bytes (4 base64 characters per 3 bytes), `$fill` repeated so two are told apart. */
    private static function image(int $bytes, string $fill): string
    {
        return 'data:image/png;base64,' . str_repeat($fill, intdiv($bytes, 3) * 4);
    }

    /** @return array{0: Conversation, 1: int, 2: int} the conversation, the packet, and the bytes each image carries */
    private function transcriptPastThePacket(int $uid, ConversationStore $store): array
    {
        global $wpdb;
        $packet = (int) $wpdb->get_var('SELECT @@max_allowed_packet');
        $this->assertGreaterThan(0, $packet);
        $cap = self::imageBytes($packet);
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
        return [$c, $packet, $cap];
    }

    public function test_a_transcript_past_max_allowed_packet_is_stored_with_its_oldest_images_evicted_and_reads_back(): void
    {
        $uid = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        [$c, $packet, $cap] = $this->transcriptPastThePacket($uid, $store);
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
        $this->assertSame(self::image($cap, chr(ord('A') + $images - 1)), $newest['images'][0]);
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
    }

    /**
     * A text-only conversation of `"` turns whose serialised rows are under the budget but past the packet once escaped.
     *
     * @return array{0: Conversation, 1: int} the conversation and the packet
     */
    private function transcriptPastThePacketOnceEscaped(int $uid, ConversationStore $store): array
    {
        global $wpdb;
        $packet = (int) $wpdb->get_var('SELECT @@max_allowed_packet');
        $this->assertGreaterThan(0, $packet);
        $c = $store->create($uid);
        $this->assertGreaterThan(0, $c->id);
        $raw = 0;
        $wire = 0;
        for ($i = 0; $i < 12 && $wire <= $packet; $i++) {
            $c->append(new Message($i % 2 === 0 ? 'user' : 'assistant', str_repeat('"', 2_000_000), 'fake-model'));
            $serialised = maybe_serialize(array_map(static fn(Message $m): array => $m->toArray(), $c->messages));
            $raw = strlen($serialised);
            $wire = strlen($wpdb->remove_placeholder_escape($wpdb->_real_escape($serialised)));
        }
        $this->assertLessThan(ConversationStore::storageBudget(), $raw, 'measured raw, the transcript fits the budget');
        $this->assertGreaterThan($packet, $wire, 'escaped as the driver escapes it, the transcript is past the packet');
        return [$c, $packet];
    }

    public function test_the_driver_doubles_exactly_the_seven_bytes_the_store_counts_and_puts_the_percent_placeholder_back(): void
    {
        global $wpdb;
        // NUL, newline, carriage return, ^Z, both quotes and the backslash: each becomes two bytes; `%` is swapped for a placeholder and swapped back before the query is sent.
        $sample = "\0\n\r\x1a\"'\\%";
        $this->assertSame(strlen($sample) + 7, strlen($wpdb->remove_placeholder_escape($wpdb->_real_escape($sample))));
        // The rest of the printable range and multibyte text cost nothing.
        $plain = implode('', array_map('chr', array_diff(range(32, 126), [34, 39, 92, 37]))) . 'héllo — 世界';
        $this->assertSame(strlen($plain), strlen($wpdb->_real_escape($plain)));
    }

    public function test_a_text_transcript_past_max_allowed_packet_once_escaped_is_stored_fitted_and_reads_back(): void
    {
        $uid = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        [$c, $packet] = $this->transcriptPastThePacketOnceEscaped($uid, $store);
        $turns = count($c->messages);
        $newest = $c->last();
        $this->assertNotNull($newest);

        $store->save($c);

        wp_cache_delete($c->id, 'post_meta');
        $stored = get_post_meta($c->id, ConversationStore::META_MESSAGES, true);
        $this->assertIsArray($stored, 'the transcript is there');
        $this->assertLessThan($turns, count($stored), 'the oldest turns went: there were no images to let go first');
        $this->assertGreaterThan(0, count($stored));
        $this->assertSame($newest->content, $stored[count($stored) - 1]['content'], 'what remains ends with the newest turn');
        $this->assertCount(count($stored), $c->messages, 'the conversation in memory agrees');
        global $wpdb;
        $wire = strlen($wpdb->remove_placeholder_escape($wpdb->_real_escape(maybe_serialize($stored))));
        $this->assertLessThanOrEqual(ConversationStore::storageBudget(), $wire);
        $this->assertLessThan($packet, $wire);
    }

    /** The before, for text: the same rows through a bare update_post_meta() fail at the server although they measure as fitting. */
    public function test_the_same_text_rows_through_a_bare_update_post_meta_fail_against_this_database_although_they_fit_raw(): void
    {
        global $wpdb;
        $uid = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        [$c] = $this->transcriptPastThePacketOnceEscaped($uid, $store);
        $rows = array_map(static fn(Message $m): array => $m->toArray(), $c->messages);

        $wpdb->suppress_errors(true);
        $result = update_post_meta($c->id, ConversationStore::META_MESSAGES, $rows);
        $error = $wpdb->last_error;
        $wpdb->suppress_errors(false);

        $this->assertFalse($result);
        $this->assertStringContainsString('max_allowed_packet', $error);
        wp_cache_delete($c->id, 'post_meta');
        $this->assertSame('', get_post_meta($c->id, ConversationStore::META_MESSAGES, true), 'nothing was stored');
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

        $this->assertFalse($result);
        $this->assertStringContainsString('max_allowed_packet', $error);
        wp_cache_delete($c->id, 'post_meta');
        $this->assertSame('', get_post_meta($c->id, ConversationStore::META_MESSAGES, true), 'nothing was stored');
    }

    /**
     * The transcript, the title and the excerpt through save() and back through load(), byte for
     * byte. Three writes are on this path and every one of them unslashes: the update_post_meta()
     * that stores the rows, the wp_insert_post() create() gave the title to, and the
     * wp_update_post() save() writes the title and excerpt with.
     */
    public function test_a_backslash_heavy_conversation_round_trips_byte_for_byte_through_save_and_load(): void
    {
        $uid = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        $c = $store->create($uid, self::BACKSLASHED);
        $this->assertGreaterThan(0, $c->id);
        // Asserted here as well as after save(): save() writes the title again from the copy in
        // memory, so it would cover for an unslashed insert if this were only checked at the end.
        $inserted = get_post($c->id);
        $this->assertNotNull($inserted);
        $this->assertSame(self::BACKSLASHED, $inserted->post_title, 'the title create() inserted');
        $c->append(new Message('user', self::BACKSLASHED, 'fake-model'));
        $c->append(new Message('assistant', 'reply: ' . self::BACKSLASHED, 'fake-model'));

        $store->save($c);

        wp_cache_delete($c->id, 'post_meta');
        clean_post_cache($c->id);
        $stored = get_post_meta($c->id, ConversationStore::META_MESSAGES, true);
        $this->assertIsArray($stored, 'the transcript is there');
        $this->assertSame(self::BACKSLASHED, $stored[0]['content']);
        $this->assertSame('reply: ' . self::BACKSLASHED, $stored[1]['content']);

        $loaded = $store->load($c->id, $uid);
        $this->assertNotNull($loaded);
        $this->assertSame(self::BACKSLASHED, $loaded->messages[0]->content);
        $this->assertSame('reply: ' . self::BACKSLASHED, $loaded->messages[1]->content);
        $this->assertSame(self::BACKSLASHED, $loaded->title, 'the title create() inserted and save() wrote back');
        $post = get_post($c->id);
        $this->assertNotNull($post);
        $this->assertSame(self::BACKSLASHED, $post->post_title);
        $this->assertSame('reply: ' . self::BACKSLASHED, $post->post_excerpt, 'the excerpt is the newest turn, short enough to be it whole');
    }

    /**
     * The 0.4 migration's own write: the first load() of a row that has only 0.4's `messages` key
     * converts it and stores the result under META_MESSAGES, and that write unslashes too. The
     * legacy row is seeded the way WordPress wants it — slashed — so what this measures is the
     * plugin's write, not the fixture's.
     */
    public function test_the_0_4_transcript_converted_on_first_load_is_stored_with_its_backslashes(): void
    {
        $uid = $this->asAdmin();
        $store = Plugin::instance()->get(ConversationStore::class);
        $id = self::factory()->post->create([
            'post_type' => ConversationStore::POST_TYPE,
            'post_author' => $uid,
            'post_status' => 'private',
            'post_title' => 'a 0.4 conversation',
        ]);
        update_post_meta($id, 'messages', wp_slash([['model' => 'legacy-model', 'message' => ['role' => $uid, 'content' => self::BACKSLASHED]]]));
        $this->assertSame(self::BACKSLASHED, get_post_meta($id, 'messages', true)[0]['message']['content'], 'the legacy row is seeded intact');

        $loaded = $store->load($id, $uid);
        $this->assertNotNull($loaded);
        $this->assertSame(self::BACKSLASHED, $loaded->messages[0]->content, 'what this load answered');

        wp_cache_delete($id, 'post_meta');
        $converted = get_post_meta($id, ConversationStore::META_MESSAGES, true);
        $this->assertIsArray($converted, 'the conversion was written');
        $this->assertSame(self::BACKSLASHED, $converted[0]['content'], 'and stored as it was read, so every later load answers the same');
    }
}
