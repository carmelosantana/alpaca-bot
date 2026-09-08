<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

/**
 * Persists conversations as `chat_history` posts — the same post type 0.4 used, so an
 * upgrade keeps every user's history.
 *
 * Ownership is the security boundary: load(), save() and delete() answer only for the post's
 * author, and a row with no author recorded belongs to nobody.
 */
final class ConversationStore
{
    public const POST_TYPE = 'chat_history';
    public const META_MESSAGES = 'ab_messages';

    /**
     * 0.4's key: converted into META_MESSAGES on the first load() of a row that has no
     * META_MESSAGES yet, and left in place afterwards. The conversion is lossy (see
     * Message::fromLegacy()), so the legacy blob stays as the record of what 0.4 stored, and
     * Migrate04 still reads it to recover an owner. Never written, never deleted here; P3
     * sweeps it once the 0.4 migration story is closed.
     */
    private const META_LEGACY = 'messages';

    /** 0.4 flagged generate-mode transcripts with this meta; 0.5 reads it, never writes it. */
    private const META_LEGACY_GENERATE = 'chat_mode_generate';

    /**
     * Held back from `max_allowed_packet` for the part of the UPDATE that is not the meta value:
     * the statement text, the column names, the WHERE clause and the packet's own header, a few
     * hundred bytes in all. That part is the same whatever the transcript holds, so it is a
     * constant; the other cost of the write, the escaping wpdb applies to the value, is not, and
     * is measured per transcript instead (packetBytes() says why it cannot be a figure). 64 KiB
     * is orders of magnitude more than the statement costs and 0.4% of a stock packet. Too small
     * and the write fails exactly as it did before there was a budget; too large and the
     * transcript loses room a run of maximum-size images would have filled.
     *
     * @since 0.5.0
     */
    private const PACKET_MARGIN = 64 * 1024;

    /**
     * The bytes mysqli_real_escape_string() doubles: NUL, newline, carriage return, ^Z, the two
     * quotes and the backslash. What packetBytes() counts.
     */
    private const ESCAPED_BYTES = [0, 10, 13, 26, 34, 39, 92];

    /**
     * MySQL's and MariaDB's own documented default for `max_allowed_packet`, 16 MiB: what
     * storageBudget() assumes when the server does not say (an empty or unparseable answer,
     * which a hardened `wpdb` that refuses the query would also produce). A server set lower
     * than its default and not answering is the one case this guesses wrong, and it guesses
     * in the direction of the old behaviour, the write failing.
     *
     * @since 0.5.0
     */
    private const PACKET_DEFAULT = 16777216;

    /** The server's `max_allowed_packet` once read this request; null until then (see maxAllowedPacket()). */
    private static ?int $maxAllowedPacket = null;

    public function __construct(private Store $store) {}

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'delete_with_user' => true,
            'supports' => ['title', 'excerpt', 'author'],
            // Conversations are only ever created here, never from an editor screen or REST.
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
        ]);
    }

    /**
     * A new conversation for the user — persisted at once unless `privacy.save_history` is off,
     * the insert fails, or there is no user to own it (a front-end request with nobody logged
     * in), in which case it stays in memory with id 0 and save() never writes it.
     */
    public function create(int $userId, string $title = ''): Conversation
    {
        $c = new Conversation(0, $userId, $title, [], 'chat', (int) current_time('timestamp', true));
        if ($userId < 1 || !$this->store->get('privacy.save_history')) {
            return $c;
        }
        $id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_author' => $userId,
            'post_status' => 'private',
            'post_title' => $title !== '' ? $title : $this->defaultTitle(),
            'post_name' => wp_generate_uuid4(),
        ], true);
        $c->id = is_int($id) ? $id : 0;
        return $c;
    }

    /** Null when the post is missing, is not a conversation, or is not owned by $userId. */
    public function load(int $id, int $userId): ?Conversation
    {
        $post = $this->owned($id, $userId);
        if ($post === null) {
            return null;
        }
        $mode = get_post_meta($id, self::META_LEGACY_GENERATE, true) ? 'generate' : 'chat';
        return new Conversation(
            $id,
            $userId,
            (string) $post->post_title,
            array_map(Message::fromArray(...), $this->readMessages($id)),
            $mode,
            (int) strtotime($post->post_date_gmt . ' UTC'),
        );
    }

    /**
     * Writes the transcript, and a title/excerpt derived from it unless the caller chose a title.
     *
     * A no-op for an unsaved conversation (id 0): that is what create() returns when
     * `privacy.save_history` is off. A conversation that already has a post keeps being
     * updated even if saving has since been switched off, so the stored transcript never
     * silently diverges from what the user sees.
     *
     * Also a no-op when the post is not a conversation owned by the conversation's user:
     * Conversation is plain data anyone can construct, so its id is not trusted on its own.
     *
     * The whole transcript is one meta value, so one row and one packet, and the invariant
     * here is absolute: what is written always fits storageBudget(), measured as the database
     * receives it (packetBytes()), escaping included. A few images at the site's allowance are
     * past a stock 16 MiB packet, and past it the UPDATE fails, the false from
     * update_post_meta() went unchecked, and the transcript silently stopped persisting from
     * that turn on. So before the write the transcript is fitted (fit()):
     * image payloads go first, from the oldest turn that still has any, each turn keeping a
     * count of what it lost under `meta['images_evicted']`; whole messages go only once
     * every image is gone, oldest first. Text is never dropped while an image whose eviction
     * would make room remains (fit() says what an eviction that would not make room is, and
     * why it is passed over). The fitting is applied to the Conversation the caller passed, so
     * the transcript in memory and the one stored agree, and a later save() starts from what
     * was kept.
     */
    public function save(Conversation $c): void
    {
        if ($c->id === 0 || $this->owned($c->id, $c->userId) === null) {
            return;
        }
        update_post_meta($c->id, self::META_MESSAGES, $this->fit($c));
        $first = $c->messages[0] ?? null;
        $last = $c->last();
        if ($first === null || $last === null) {
            return;
        }
        if ($c->title === '' || $c->title === $this->defaultTitle()) {
            $derived = wp_trim_words(sanitize_text_field(rtrim($first->content, '?.!')), 8, '');
            $c->title = $derived !== '' ? $derived : $c->title;
        }
        wp_update_post([
            'ID' => $c->id,
            'post_title' => $c->title,
            'post_excerpt' => wp_trim_words(sanitize_text_field($last->content), 30, '…'),
        ]);
    }

    /**
     * The bytes the serialised transcript may occupy in one meta write, counted as the
     * database receives them (packetBytes()): the server's `max_allowed_packet` less
     * PACKET_MARGIN, never below zero. The figure is the live one (maxAllowedPacket()), so a
     * site that raised its packet gets the room it paid for and a site that lowered it is still
     * safe; `$maxAllowedPacket` is a parameter so a test can pass a figure, and production
     * passes nothing. A packet no larger than the margin gives 0, which fit() answers by
     * emptying the transcript: a server that small cannot take one, and the six bytes an empty
     * list serialises to are then over the budget, but not over any packet.
     *
     * @internal Public only so the tests can call it; not part of the plugin's API.
     * @since 0.5.0
     */
    public static function storageBudget(?int $maxAllowedPacket = null): int
    {
        return max(0, ($maxAllowedPacket ?? self::maxAllowedPacket()) - self::PACKET_MARGIN);
    }

    /**
     * The server's `max_allowed_packet`, read once per request and kept in a static: one round
     * trip, not one per save. Core does not expose it (wpdb reads no server variables), so it is
     * `SELECT @@max_allowed_packet`, a session-visible system variable any connection may read.
     * An answer that is empty or not a positive integer is PACKET_DEFAULT (it says why that
     * figure). The static is process-wide, which under PHP-FPM is the request and under WP-CLI
     * the command; the value is a server setting that changes with a restart, so neither
     * outlives it in a way that matters.
     */
    private static function maxAllowedPacket(): int
    {
        if (self::$maxAllowedPacket === null) {
            global $wpdb;
            $raw = $wpdb->get_var('SELECT @@max_allowed_packet');
            self::$maxAllowedPacket = is_scalar($raw) && (int) $raw > 0 ? (int) $raw : self::PACKET_DEFAULT;
        }
        return self::$maxAllowedPacket;
    }

    /**
     * The transcript as the rows save() stores, fitted to storageBudget() by mutating `$c`
     * (save() says the order and why). The size is measured as the database sees it:
     * update_post_meta() runs the value through maybe_serialize() and wpdb escapes the result
     * into the statement, so that is what is measured (packetBytes()), on the exact rows about
     * to be written, after every eviction. Measuring the whole transcript again each round
     * costs a serialisation and a scan per eviction, and each eviction takes megabytes off, so
     * the rounds are few; the alternative, arithmetic on the parts, would have to reproduce
     * serialize()'s framing to be trusted.
     *
     * An eviction is only taken when it makes room. Recording it costs a marker, 28 serialised
     * bytes for a turn's first, so an image entry shorter than that would leave the rows
     * bigger, not smaller, and the round would have cost the transcript an image and bought it
     * nothing. Nothing ImageData admits is that short (its 23-byte minimum still saves 9), but
     * 0.4 stored media paths under `images` and a filter may leave anything there. So each
     * candidate turn is measured on its own row before and after (serialize() of the list is
     * the rows' serialisations end to end, so a row's difference is the transcript's), the
     * first turn whose eviction shrinks it is taken, one that would not is put back and passed
     * over, and only when no turn's eviction makes room does the oldest message go whole, its
     * small image with it. That never loses more than evicting regardless would: a step that
     * grows the rows leaves every later step still to take.
     *
     * @return list<array<string, mixed>>
     */
    private function fit(Conversation $c): array
    {
        $budget = self::storageBudget();
        $rows = self::rows($c->messages);
        while (self::packetBytes(maybe_serialize($rows)) > $budget && $c->messages !== []) {
            $evicted = false;
            foreach ($c->messages as $m) {
                if ($m->images === []) {
                    continue;
                }
                [$images, $meta] = [$m->images, $m->meta];
                $before = self::packetBytes(maybe_serialize($m->toArray()));
                $m->meta['images_evicted'] = (int) ($m->meta['images_evicted'] ?? 0) + count($m->images);
                $m->images = [];
                if (self::packetBytes(maybe_serialize($m->toArray())) < $before) {
                    $evicted = true;
                    break;
                }
                [$m->images, $m->meta] = [$images, $meta];
            }
            if (!$evicted) {
                array_shift($c->messages);
            }
            $rows = self::rows($c->messages);
        }
        return $rows;
    }

    /**
     * The bytes the value occupies in the query the database receives, which is what
     * `max_allowed_packet` is measured against: its length, plus one for every byte
     * mysqli_real_escape_string() doubles on the way (ESCAPED_BYTES). That cost is a share of
     * the content, not a constant, which is why it is counted here and not folded into
     * PACKET_MARGIN: base64 carries none of those bytes, so a transcript of images escapes to
     * its own length and the images never showed the gap; serialize() frames every string in
     * quotes, so even plain prose costs a little; and pasted JSON or code is one such byte in
     * ten, which at a 15 MiB transcript is a megabyte and a half, more than any margin that is
     * not wasted on every other transcript. Under a fixed margin such a transcript measured as
     * fitting and the UPDATE still failed, the very bug the budget exists to close.
     *
     * One linear pass and no copy: count_chars() tallies every byte in one go, where escaping
     * the value through wpdb to measure it would allocate a second transcript. wpdb also swaps
     * `%` for a placeholder in _real_escape(), but puts it back on the `query` filter before
     * the statement is sent, so `%` costs nothing on the wire and is not counted.
     */
    private static function packetBytes(string $serialised): int
    {
        $counts = count_chars($serialised, 1);
        $escaped = 0;
        foreach (self::ESCAPED_BYTES as $byte) {
            $escaped += $counts[$byte] ?? 0;
        }
        return strlen($serialised) + $escaped;
    }

    /**
     * @param Message[] $messages
     * @return list<array<string, mixed>>
     */
    private static function rows(array $messages): array
    {
        return array_values(array_map(static fn(Message $m): array => $m->toArray(), $messages));
    }

    /**
     * The user's conversations, newest first.
     *
     * Both guards matter: WP_Query ignores `author => 0` (the list would span every user) and
     * turns a zero `numberposts` into the blog's posts_per_page default.
     *
     * `publish` is listed alongside `private` because that is how 0.4 stored every row, and
     * Migrate04 flips them in batches (and a 0.4 site that never saved its settings still has
     * them). The author clause scopes the list either way; a row is never exposed by status.
     *
     * The meta cache is left cold: get_posts() primes it by default, which reads every listed
     * row's transcript (tens or hundreds of KB each) to answer with three scalars, and
     * ConversationsController::destroyAll() lists in batches of hundreds. Nothing here reads
     * meta, and load() primes its own row when it needs it.
     *
     * @return array<int, array{id: int, title: string, created: int}>
     */
    public function listFor(int $userId, int $limit): array
    {
        if ($userId < 1 || $limit < 1) {
            return [];
        }
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'author' => $userId,
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => ['private', 'publish'],
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        return array_map(static fn(object $p): array => [
            'id' => (int) $p->ID,
            'title' => (string) $p->post_title,
            'created' => (int) strtotime($p->post_date_gmt . ' UTC'),
        ], $posts);
    }

    /** Permanently deletes the conversation; false when it is missing or not owned by $userId. */
    public function delete(int $id, int $userId): bool
    {
        if ($this->owned($id, $userId) === null) {
            return false;
        }
        return (bool) wp_delete_post($id, true);
    }

    /**
     * Permanently deletes the conversation, but only while nothing is stored on it; false when
     * it is missing, not owned by $userId, or has a transcript.
     *
     * For Pipeline, which creates the post before the provider is called and takes it back when
     * the turn then fails: a failed turn must not leave an empty "New chat" in the history list.
     * "Empty" is judged on what is stored, not on the in-memory Conversation, so a turn a
     * `chat/failed` listener has meanwhile saved is kept. Both transcript keys are checked and
     * neither is read through readMessages(): a 0.4 row that has not been converted yet is a
     * conversation someone can still open, and taking it back must not convert it either.
     */
    public function deleteIfEmpty(int $id, int $userId): bool
    {
        if ($this->owned($id, $userId) === null) {
            return false;
        }
        foreach ([self::META_MESSAGES, self::META_LEGACY] as $key) {
            $stored = get_post_meta($id, $key, true);
            if (is_array($stored) && $stored !== []) {
                return false;
            }
        }
        return (bool) wp_delete_post($id, true);
    }

    /**
     * The post when it is a conversation owned by $userId, else null. Nobody owns an authorless row.
     *
     * Ordering dependency: Migrate04::migrateConversations() recovers post_author for 0.4's
     * author-0 rows from META_LEGACY; this author check is what keeps load() (and so
     * readMessages()) off such a row until the migration has claimed it, so it must stay ahead
     * of every read and must keep rejecting author 0.
     *
     * Typed `object` natively so the store can be exercised without WP_Post loaded.
     *
     * @return \WP_Post|null
     */
    private function owned(int $id, int $userId): ?object
    {
        if ($userId < 1) {
            return null;
        }
        $post = get_post($id);
        if ($post === null || $post->post_type !== self::POST_TYPE || (int) $post->post_author !== $userId) {
            return null;
        }
        return $post;
    }

    /**
     * The stored transcript, converting 0.4's `messages` meta into META_MESSAGES on the first
     * read of a row that has none. Once META_MESSAGES exists it is the only key read.
     *
     * The conversion only adds: the legacy key is never deleted (see META_LEGACY). A legacy
     * value that is not an array converts to nothing and is likewise left alone; entries that
     * are not arrays are skipped, as 0.4's own reader did.
     *
     * @return list<array<string, mixed>>
     */
    private function readMessages(int $id): array
    {
        $raw = get_post_meta($id, self::META_MESSAGES, true);
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }
        $legacy = get_post_meta($id, self::META_LEGACY, true);
        if (!is_array($legacy)) {
            return [];
        }
        $converted = [];
        foreach ($legacy as $entry) {
            if (is_array($entry)) {
                $converted[] = Message::fromArray($entry)->toArray();
            }
        }
        update_post_meta($id, self::META_MESSAGES, $converted);
        return $converted;
    }

    private function defaultTitle(): string
    {
        return __('New chat', 'alpaca-bot');
    }
}
