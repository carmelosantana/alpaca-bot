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

    /** 0.4 flagged generate-mode transcripts with this meta; 1.0 reads it, never writes it. */
    private const META_LEGACY_GENERATE = 'chat_mode_generate';

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
     */
    public function save(Conversation $c): void
    {
        if ($c->id === 0 || $this->owned($c->id, $c->userId) === null) {
            return;
        }
        update_post_meta($c->id, self::META_MESSAGES, array_map(static fn(Message $m): array => $m->toArray(), $c->messages));
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
