<?php

declare(strict_types=1);

use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

// Brain Monkey 2.7: Functions\expect() starts from a byDefault expectation and with() attaches
// to *that*; the first chained once()/never()/andReturn() then swaps in a fresh, argument-less
// one. So the times qualifier must come before with(), or three expectations on one function
// collapse into whichever was defined first.
beforeEach(function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\when('current_time')->justReturn(1_700_000_000);
    Functions\when('wp_generate_uuid4')->justReturn('uuid');
    Functions\when('sanitize_text_field')->returnArg();
    // Core's signature: past $num words the text is cut and $more (default an ellipsis) appended.
    Functions\when('wp_trim_words')->alias(function (string $text, int $num = 55, ?string $more = null): string {
        $words = explode(' ', $text);
        return count($words) > $num ? implode(' ', array_slice($words, 0, $num)) . ($more ?? '…') : $text;
    });
    conversationStoreDb();
});

// ---------------------------------------------------------------- Message

it('converts the 0.4 message shape', function (): void {
    $m = Message::fromArray(['model' => 'llama3.2', 'message' => ['role' => 7, 'content' => 'hi']]);
    expect($m->role)->toBe('user')->and($m->content)->toBe('hi')->and($m->model)->toBe('llama3.2');
    $a = Message::fromArray(['model' => 'llama3.2', 'message' => ['role' => 'assistant', 'content' => 'hello'], 'eval_count' => 12, 'prompt_eval_count' => 5]);
    expect($a->role)->toBe('assistant')->and($a->usage)->toBe(['prompt_tokens' => 5, 'completion_tokens' => 12]);
});

// 0.4 wrote the current user's id as the role of every user turn (get_current_user_id(),
// which is 0 for a request with no user), and the raw Ollama response for every assistant
// turn. Its own readers treated any int as "user" and anything else as the assistant.
it('maps every integer 0.4 role to user, including 0, and keeps the Ollama timestamp', function (): void {
    expect(Message::fromArray(['model' => 'm', 'message' => ['role' => 0, 'content' => 'q']])->role)->toBe('user');
    $a = Message::fromArray(['model' => 'm', 'created_at' => '2024-01-01T00:00:00.123456789Z', 'message' => ['role' => 'assistant', 'content' => 'a']]);
    expect($a->created)->toBe(1704067200)->and($a->usage)->toBeNull();
    // 0.4 rendered string roles lowercased; unknown ones were sent back to the model as the assistant.
    expect(Message::fromArray(['message' => ['role' => 'System', 'content' => 'x']])->role)->toBe('system')
        ->and(Message::fromArray(['message' => ['role' => 'Llama', 'content' => 'x']])->role)->toBe('assistant')
        ->and(Message::fromArray(['message' => ['content' => 'x']])->role)->toBe('user');
});

it('round-trips the 1.0 shape through toArray and fromArray', function (): void {
    $m = new Message('assistant', 'hi', 'llama3.2', ['prompt_tokens' => 1, 'completion_tokens' => 2], 1_700_000_000, ['data:image/png;base64,x'], ['finish' => 'stop']);
    $a = $m->toArray();
    expect($a)->toEqual(['role' => 'assistant', 'content' => 'hi', 'model' => 'llama3.2', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2], 'created' => 1_700_000_000, 'images' => ['data:image/png;base64,x'], 'meta' => (object) ['finish' => 'stop']]);
    expect(Message::fromArray($a))->toEqual($m);
    expect(Message::fromArray([]))->toEqual(new Message('user', ''));
});

// ----------------------------------------------------------- Conversation

it('appends with a timestamp and exposes the last message', function (): void {
    $c = new Conversation(0, 3, '');
    expect($c->last())->toBeNull();
    $c->append(new Message('user', 'a'));
    $c->append(new Message('assistant', 'b', created: 5));
    expect($c->messages[0]->created)->toBe(1_700_000_000)
        ->and($c->last()?->content)->toBe('b')
        ->and($c->last()?->created)->toBe(5);
});

// ------------------------------------------------------ create() / save()

it('creates a post and saves messages under ab_messages', function (): void {
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p): bool => $p['post_type'] === 'chat_history' && $p['post_author'] === 3 && $p['post_status'] === 'private')->andReturn(42);
    Functions\expect('update_post_meta')->once()->withArgs(fn(int $id, string $k, array $v): bool => $id === 42 && $k === ConversationStore::META_MESSAGES && $v[0]['role'] === 'user' && $v[1]['role'] === 'assistant');
    Functions\expect('wp_update_post')->once()->withArgs(fn(array $p): bool => $p['ID'] === 42 && $p['post_title'] === 'What is WordPress' && $p['post_excerpt'] === 'WordPress is a CMS.')->andReturn(42);
    Functions\when('get_post')->justReturn(conversationChatPost());
    $s = new ConversationStore(new Store());
    $c = $s->create(3);
    expect($c->id)->toBe(42);
    $c->append(new Message('user', 'What is WordPress?'));
    $c->append(new Message('assistant', 'WordPress is a CMS.', 'llama3.2'));
    $s->save($c);
    expect($c->title)->toBe('What is WordPress');
});

it('truncates a long first message into the title and a long reply into an ellipsised excerpt', function (): void {
    Functions\when('wp_insert_post')->justReturn(42);
    Functions\when('update_post_meta')->justReturn(true);
    Functions\when('get_post')->justReturn(conversationChatPost());
    Functions\expect('wp_update_post')->once()->withArgs(fn(array $p): bool => $p['post_title'] === conversationWords(8, 'q') && $p['post_excerpt'] === conversationWords(30, 'a') . '…')->andReturn(42);
    $s = new ConversationStore(new Store());
    $c = $s->create(3);
    $c->append(new Message('user', conversationWords(10, 'q') . '?'));
    $c->append(new Message('assistant', conversationWords(35, 'a')));
    $s->save($c);
    expect($c->title)->toBe(conversationWords(8, 'q'));
});

it('keeps a title the caller chose instead of deriving one', function (): void {
    Functions\when('wp_insert_post')->justReturn(42);
    Functions\when('get_post')->justReturn(conversationChatPost());
    Functions\when('update_post_meta')->justReturn(true);
    Functions\expect('wp_update_post')->once()->withArgs(fn(array $p): bool => $p['post_title'] === 'Mine')->andReturn(42);
    $s = new ConversationStore(new Store());
    $c = $s->create(3, 'Mine');
    $c->append(new Message('user', 'Something else'));
    $s->save($c);
});

it('writes an empty message list but leaves title and excerpt alone', function (): void {
    Functions\when('get_post')->justReturn(conversationChatPost());
    Functions\expect('update_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, []);
    Functions\expect('wp_update_post')->never();
    (new ConversationStore(new Store()))->save(new Conversation(42, 3, 'T'));
});

it('returns an unsaved conversation when the insert fails', function (): void {
    // Stands in for a WP_Error (not loaded here); the store only asks is_int() of the result.
    Functions\when('wp_insert_post')->justReturn((object) ['errors' => ['db_insert_error' => ['Could not insert post into the database.']]]);
    Functions\expect('update_post_meta')->never();
    Functions\expect('wp_update_post')->never();
    $s = new ConversationStore(new Store());
    $c = $s->create(3);
    expect($c->id)->toBe(0)->and($c->userId)->toBe(3)->and($c->created)->toBe(1_700_000_000);
    $c->append(new Message('user', 'x'));
    $s->save($c);
});

// 0.4's front-end shortcodes had no logged-in gate, so a pipeline may well call
// create(get_current_user_id()) with 0. That is "don't persist", never a fatal.
it('returns an unsaved conversation for nobody and never persists it', function (): void {
    Functions\expect('wp_insert_post')->never();
    Functions\expect('update_post_meta')->never();
    Functions\expect('wp_update_post')->never();
    Functions\expect('get_post')->never();
    $s = new ConversationStore(new Store());
    foreach ([0, -1] as $nobody) {
        $c = $s->create($nobody, 'Mine');
        expect($c->id)->toBe(0)->and($c->userId)->toBe($nobody)->and($c->title)->toBe('Mine')->and($c->created)->toBe(1_700_000_000);
        $c->append(new Message('user', 'x'));
        $s->save($c);
    }
});

it('does not persist when history saving is off and the conversation is new', function (): void {
    Functions\when('get_option')->justReturn(['privacy.save_history' => false]);
    Functions\expect('wp_insert_post')->never();
    Functions\expect('update_post_meta')->never();
    Functions\expect('wp_update_post')->never();
    $s = new ConversationStore(new Store());
    $c = $s->create(3);
    expect($c->id)->toBe(0);
    $c->append(new Message('user', 'x'));
    $s->save($c);
});

// The no-op condition is "saving off AND never persisted". A conversation that already has a
// post (loaded from before the setting was switched off) keeps being updated, so the stored
// transcript never silently diverges from what the user sees.
it('still updates an already-persisted conversation when history saving is off', function (): void {
    Functions\when('get_option')->justReturn(['privacy.save_history' => false]);
    Functions\when('get_post')->justReturn(conversationChatPost());
    Functions\expect('update_post_meta')->once()->withArgs(fn(int $id, string $k, array $v): bool => $id === 42 && $k === ConversationStore::META_MESSAGES && count($v) === 1);
    Functions\expect('wp_update_post')->once()->withArgs(fn(array $p): bool => $p['ID'] === 42 && $p['post_title'] === 'T' && $p['post_excerpt'] === 'x')->andReturn(42);
    $c = new Conversation(42, 3, 'T');
    $c->append(new Message('user', 'x'));
    (new ConversationStore(new Store()))->save($c);
});

// Conversation is a plain data object anyone can construct, so save() trusts neither its id
// nor its userId: the post must exist, be a conversation, and be owned by that user.
it('refuses to save onto a post the conversation\'s user does not own', function (): void {
    Functions\expect('update_post_meta')->never();
    Functions\expect('wp_update_post')->never();
    $s = new ConversationStore(new Store());
    $c = new Conversation(42, 9, 'T');
    $c->append(new Message('user', 'x'));
    Functions\when('get_post')->justReturn(conversationChatPost(author: '3'));
    $s->save($c);
    Functions\when('get_post')->justReturn(conversationChatPost(type: 'post', author: '9'));
    $s->save($c);
    Functions\when('get_post')->justReturn(null);
    $s->save($c);
});

it('refuses to save a conversation that names an id but no owner, without a lookup', function (): void {
    Functions\expect('get_post')->never();
    Functions\expect('update_post_meta')->never();
    Functions\expect('wp_update_post')->never();
    $c = new Conversation(42, 0, 'T');
    $c->append(new Message('user', 'x'));
    (new ConversationStore(new Store()))->save($c);
});

// ------------------------------------------- storageBudget() and the fit save() applies

// The 64 KiB margin is for the statement the meta value rides in, the one part of the write
// whose size does not depend on the transcript; the fallback is MySQL's documented default packet.
it('derives the storage budget from max_allowed_packet less the margin, never below zero', function (): void {
    expect(ConversationStore::storageBudget(16777216))->toBe(16777216 - 65536)
        ->and(ConversationStore::storageBudget(65536 + 1))->toBe(1)
        ->and(ConversationStore::storageBudget(65536))->toBe(0)
        ->and(ConversationStore::storageBudget(1))->toBe(0);
});

it('reads max_allowed_packet once per request and falls back to the documented default when the answer is empty or unparseable', function (): void {
    $db = conversationStoreDb('33554432');
    expect(ConversationStore::storageBudget())->toBe(33554432 - 65536)
        ->and(ConversationStore::storageBudget())->toBe(33554432 - 65536)
        ->and($db->queries)->toBe(['SELECT @@max_allowed_packet']);
    foreach (['', null, false, 'abc', '0', '-1'] as $raw) {
        conversationStoreDb($raw);
        expect(ConversationStore::storageBudget())->toBe(16777216 - 65536, var_export($raw, true));
    }
});

/** A PNG data URL carrying `$n` decoded bytes (4 base64 characters per 3 bytes). */
function conversationImage(int $n, string $fill = 'A'): string
{
    return 'data:image/png;base64,' . str_repeat($fill, intdiv($n, 3) * 4);
}

/**
 * A value as mysqli_real_escape_string() writes it into the query: the seven bytes it doubles.
 * Spelled out here rather than taken from the store, so the store's count is checked against
 * what the database driver does and not against itself.
 */
function conversationEscaped(string $s): string
{
    return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $s);
}

/** The rows save() stores for `$messages`. */
function conversationRows(array $messages): array
{
    return array_map(static fn(Message $m): array => $m->toArray(), $messages);
}

/** The bytes `$rows` occupy in the UPDATE the database receives: serialised, then escaped as the driver escapes it. The unit the budget is in. */
function conversationWire(array $rows): int
{
    return strlen(conversationEscaped(serialize($rows)));
}

/** save() over a post the conversation's user owns, with the one meta write captured; the budget is `$budget` bytes (the packet is that plus the 64 KiB margin). */
function conversationSaveWithin(int $budget, Conversation $c): mixed
{
    conversationStoreDb((string) (65536 + $budget));
    Functions\when('get_post')->justReturn(conversationChatPost());
    Functions\when('wp_update_post')->justReturn(42);
    $written = null;
    Functions\expect('update_post_meta')->once()->withArgs(function (int $id, string $k, array $v) use (&$written): bool {
        $written = $v;
        return $id === 42 && $k === ConversationStore::META_MESSAGES;
    })->andReturn(true);
    (new ConversationStore(new Store()))->save($c);
    return $written;
}

/** The four-turn transcript the fit tests share: the oldest and the newest user turns carry images. */
function conversationWithImages(): Conversation
{
    $c = new Conversation(42, 3, 'T');
    $c->append(new Message('user', 'first', 'm', null, 1, [conversationImage(300, 'A')], ['duration_ms' => 5]));
    $c->append(new Message('assistant', 'one', 'm', null, 2));
    $c->append(new Message('user', 'third', 'm', null, 3, [conversationImage(300, 'B'), conversationImage(300, 'C')]));
    $c->append(new Message('assistant', 'two', 'm', null, 4));
    return $c;
}

it('writes a transcript that fits the budget unchanged', function (): void {
    $c = conversationWithImages();
    $rows = conversationRows($c->messages);
    $written = conversationSaveWithin(conversationWire($rows), $c);
    expect($written)->toEqual($rows)
        ->and($c->messages[0]->images)->toHaveCount(1)
        ->and($c->messages[0]->meta)->toBe(['duration_ms' => 5])
        ->and($c->messages[2]->images)->toHaveCount(2);
});

it('evicts the oldest turn\'s images first, records the count on that turn, and leaves the newest turn\'s images intact', function (): void {
    $c = conversationWithImages();
    $budget = conversationWire(conversationRows($c->messages)) - 1;
    $written = conversationSaveWithin($budget, $c);
    // The in-memory conversation is what was stored: the oldest turn lost its one image and says so, in its meta beside what was there.
    expect($c->messages)->toHaveCount(4)
        ->and($c->messages[0]->images)->toBe([])
        ->and($c->messages[0]->meta)->toBe(['duration_ms' => 5, 'images_evicted' => 1])
        ->and($c->messages[0]->content)->toBe('first')
        ->and($c->messages[2]->images)->toBe([conversationImage(300, 'B'), conversationImage(300, 'C')])
        ->and($written)->toEqual(conversationRows($c->messages))
        ->and(conversationWire($written))->toBeLessThanOrEqual($budget)
        // The marker survives the storage shape: meta goes out as an object and comes back as an array.
        ->and(Message::fromArray($written[0])->meta['images_evicted'])->toBe(1);
});

it('adds an eviction to a count an earlier save recorded', function (): void {
    $c = new Conversation(42, 3, 'T');
    $c->append(new Message('user', 'again', 'm', null, 1, [conversationImage(300), conversationImage(300)], ['images_evicted' => 2]));
    $c->append(new Message('assistant', 'reply', 'm', null, 2));
    $budget = conversationWire(conversationRows($c->messages)) - 1;
    conversationSaveWithin($budget, $c);
    expect($c->messages[0]->images)->toBe([])->and($c->messages[0]->meta)->toBe(['images_evicted' => 4]);
});

it('drops the oldest whole messages only once every image is gone, and the newest survives with its marker', function (): void {
    $c = conversationWithImages();
    // Room for exactly the two newest turns, the user turn stripped of its images and marked.
    $kept = [
        new Message('user', 'third', 'm', null, 3, [], ['images_evicted' => 2]),
        new Message('assistant', 'two', 'm', null, 4),
    ];
    $budget = conversationWire(conversationRows($kept));
    $written = conversationSaveWithin($budget, $c);
    expect($c->messages)->toEqual($kept)
        ->and($written)->toEqual(conversationRows($kept))
        ->and(conversationWire($written))->toBeLessThanOrEqual($budget);
});

it('drops oldest whole messages from a transcript over budget on text alone, and stores what is left', function (): void {
    $c = new Conversation(42, 3, 'T');
    foreach (['one', 'two', 'three', 'four'] as $i => $word) {
        $c->append(new Message($i % 2 === 0 ? 'user' : 'assistant', str_repeat($word . ' ', 40), 'm', null, $i + 1));
    }
    $kept = array_slice($c->messages, 2);
    $budget = conversationWire(conversationRows($kept));
    $written = conversationSaveWithin($budget, $c);
    expect($c->messages)->toEqual($kept)
        ->and($written)->toEqual(conversationRows($kept))
        ->and($written[0]['content'])->toStartWith('three ')
        ->and(conversationWire($written))->toBeLessThanOrEqual($budget);
});

// max_allowed_packet bounds the query the database receives, and wpdb escapes the value on the
// way: every quote, backslash, NUL, newline, carriage return and ^Z is two bytes on the wire.
// That cost is a share of the text, not a constant, so a fixed margin cannot cover it; the store
// measures it. Base64 carries none of those bytes, which is why the image fixtures never showed
// this: pasted JSON or code does.
it('fits the transcript by its size on the wire, so escape-dense text that fits raw but not escaped is still fitted', function (): void {
    $c = new Conversation(42, 3, 'T');
    $c->append(new Message('user', str_repeat('"\\', 200), 'm', null, 1));
    $c->append(new Message('assistant', 'plain', 'm', null, 2));
    $rows = conversationRows($c->messages);
    $raw = strlen(serialize($rows));
    $wire = conversationWire($rows);
    // The budget is the raw size exactly: measured raw the transcript fits and is written whole; on
    // the wire it is 444 bytes over (400 from the content, 44 from the quotes serialize() frames
    // its 22 strings in, which is why even a plain transcript escapes to more than its length).
    $written = conversationSaveWithin($raw, $c);
    expect($wire)->toBe($raw + 444)
        ->and($c->messages)->toHaveCount(1)
        ->and($c->messages[0]->content)->toBe('plain')
        ->and($written)->toEqual(conversationRows($c->messages))
        ->and(conversationWire($written))->toBeLessThanOrEqual($raw);
});

// ------------------------------------------------------------------ load()

// The 0.4 -> 1.0 conversion is lossy (Message::fromLegacy() keeps role, content, model, timestamp,
// token counts and images; 0.4's total_duration, load_duration, done, context and the authoring
// user id it stored as the role are gone), so the legacy key is left in place as the record of
// what 0.4 stored. P3 sweeps it once the migration story is closed.
it('loads only the owner\'s conversation, converts legacy meta once and keeps it', function (): void {
    $post = (object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-01 00:00:00'];
    Functions\when('get_post')->justReturn($post);
    Functions\expect('get_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, true)->andReturn('');
    Functions\expect('get_post_meta')->once()->with(42, 'messages', true)->andReturn([['model' => 'm', 'message' => ['role' => 3, 'content' => 'q']], ['model' => 'm', 'message' => ['role' => 'assistant', 'content' => 'a']]]);
    Functions\expect('get_post_meta')->once()->with(42, 'chat_mode_generate', true)->andReturn('');
    Functions\expect('update_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, Mockery::type('array'));
    Functions\expect('delete_post_meta')->never();
    $s = new ConversationStore(new Store());
    $c = $s->load(42, 3);
    expect($c)->toBeInstanceOf(Conversation::class)->and($c->messages)->toHaveCount(2)->and($c->messages[0]->role)->toBe('user');
    expect($s->load(42, 9))->toBeNull();
});

it('reads ab_messages directly once converted and never looks at the legacy key again', function (): void {
    $post = (object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-02 00:00:00'];
    Functions\when('get_post')->justReturn($post);
    Functions\expect('get_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, true)->andReturn([['role' => 'user', 'content' => 'q', 'model' => '', 'usage' => null, 'created' => 1, 'images' => [], 'meta' => []]]);
    Functions\expect('get_post_meta')->never()->with(42, 'messages', true);
    Functions\expect('get_post_meta')->once()->with(42, 'chat_mode_generate', true)->andReturn('1');
    Functions\expect('metadata_exists')->never();
    Functions\expect('update_post_meta')->never();
    Functions\expect('delete_post_meta')->never();
    $c = (new ConversationStore(new Store()))->load(42, 3);
    expect($c?->messages)->toHaveCount(1)
        ->and($c?->mode)->toBe('generate')
        ->and($c?->title)->toBe('T')
        ->and($c?->userId)->toBe(3)
        ->and($c?->created)->toBe(1704153600);
});

it('leaves a legacy blob sitting beside ab_messages alone: not read, not converted, not deleted', function (): void {
    Functions\when('get_post')->justReturn(conversationChatPost());
    Functions\expect('get_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, true)->andReturn([['role' => 'user', 'content' => 'q']]);
    Functions\expect('get_post_meta')->never()->with(42, 'messages', true);
    Functions\expect('get_post_meta')->once()->with(42, 'chat_mode_generate', true)->andReturn('');
    Functions\expect('metadata_exists')->never();
    Functions\expect('update_post_meta')->never();
    Functions\expect('delete_post_meta')->never();
    $c = (new ConversationStore(new Store()))->load(42, 3);
    expect($c?->messages)->toHaveCount(1)->and($c?->messages[0]->content)->toBe('q');
});

// The conversion is proven end to end: what the first load writes under ab_messages is exactly
// what a later load, reading only that key, hands back.
it('round-trips a legacy transcript through convert, write and re-read', function (): void {
    Functions\when('get_post')->justReturn(conversationChatPost());
    $legacy = [
        ['model' => 'llama3.2', 'message' => ['role' => 3, 'content' => 'q']],
        ['model' => 'llama3.2', 'created_at' => '2024-01-01T00:00:00.5Z', 'message' => ['role' => 'assistant', 'content' => 'a'], 'done' => true, 'eval_count' => 12, 'prompt_eval_count' => 5],
    ];
    $written = null;
    $writes = 0;
    $deletes = 0;
    Functions\when('get_post_meta')->alias(fn(int $id, string $k) => $k === 'messages' ? $legacy : '');
    Functions\when('update_post_meta')->alias(function (int $id, string $k, mixed $v) use (&$written, &$writes): bool {
        $written = $k === ConversationStore::META_MESSAGES && $id === 42 ? $v : $written;
        $writes++;
        return true;
    });
    Functions\when('delete_post_meta')->alias(function () use (&$deletes): bool { $deletes++; return true; });
    $s = new ConversationStore(new Store());
    $first = $s->load(42, 3);
    expect($writes)->toBe(1)->and($deletes)->toBe(0)->and($written)->toBeArray()->toHaveCount(2);

    // The row now carries ab_messages beside the untouched legacy key; only ab_messages is read.
    Functions\when('get_post_meta')->alias(fn(int $id, string $k) => $k === ConversationStore::META_MESSAGES ? $written : ($k === 'messages' ? $legacy : ''));
    $second = $s->load(42, 3);
    expect($writes)->toBe(1)->and($deletes)->toBe(0)
        ->and($second?->messages)->toEqual($first?->messages)
        ->and($second?->messages)->toEqual([
            new Message('user', 'q', 'llama3.2'),
            new Message('assistant', 'a', 'llama3.2', ['prompt_tokens' => 5, 'completion_tokens' => 12], 1704067200),
        ]);
});

it('leaves a corrupt legacy meta in place rather than converting or deleting it', function (): void {
    Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-01 00:00:00']);
    Functions\when('get_post_meta')->alias(fn(int $id, string $k) => $k === 'messages' ? 'not-an-array' : '');
    Functions\expect('update_post_meta')->never();
    Functions\expect('delete_post_meta')->never();
    expect((new ConversationStore(new Store()))->load(42, 3)?->messages)->toBe([]);
});

it('skips non-array legacy entries while converting, as 0.4 did', function (): void {
    Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-01 00:00:00']);
    Functions\when('get_post_meta')->alias(fn(int $id, string $k) => $k === 'messages' ? ['garbage', ['model' => 'm', 'message' => ['role' => 3, 'content' => 'q']]] : '');
    Functions\expect('update_post_meta')->once()->withArgs(fn(int $id, string $k, array $v): bool => $id === 42 && $k === ConversationStore::META_MESSAGES && count($v) === 1 && $v[0]['content'] === 'q');
    Functions\expect('delete_post_meta')->never();
    expect((new ConversationStore(new Store()))->load(42, 3)?->messages)->toHaveCount(1);
});

it('returns null for a missing post or a foreign post type', function (): void {
    Functions\when('get_post')->justReturn(null);
    Functions\expect('get_post_meta')->never();
    $s = new ConversationStore(new Store());
    expect($s->load(42, 3))->toBeNull();
    Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'post', 'post_date_gmt' => '2024-01-01 00:00:00']);
    expect($s->load(42, 3))->toBeNull();
});

// A row with no owner recorded (post_author 0) belongs to no one: not to a real user, and
// not to "user 0" either, since that is what an unauthenticated request resolves to.
it('never hands out a conversation with no owner recorded', function (): void {
    Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_author' => '0', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-01 00:00:00']);
    Functions\expect('get_post_meta')->never();
    Functions\expect('wp_delete_post')->never();
    $s = new ConversationStore(new Store());
    expect($s->load(42, 3))->toBeNull()
        ->and($s->load(42, 0))->toBeNull()
        ->and($s->delete(42, 0))->toBeFalse();
});

// --------------------------------------------------------------- listFor()

// 0.4 rows are `publish` until Migrate04's batched pass reaches them (and a 0.4 site that never
// saved its settings has rows but no options to key the pass on), so both statuses are listed.
// The author clause is what scopes the list; user 0 never reaches the query (below).
//
// The meta cache is left cold on purpose: get_posts() primes it by default, which reads every
// listed row's transcript (tens or hundreds of KB each) to answer with three scalars.
it('lists the user\'s conversations newest first with a limit, private or not yet migrated, without loading their transcripts', function (): void {
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_history' && $q['author'] === 3 && $q['numberposts'] === 5 && $q['orderby'] === 'date' && $q['order'] === 'DESC' && $q['post_status'] === ['private', 'publish']
        && $q['update_post_meta_cache'] === false && $q['update_post_term_cache'] === false)
        ->andReturn([(object) ['ID' => 2, 'post_title' => 'B', 'post_date_gmt' => '2024-01-02 00:00:00'], (object) ['ID' => 1, 'post_title' => 'A', 'post_date_gmt' => '2024-01-01 00:00:00']]);
    expect((new ConversationStore(new Store()))->listFor(3, 5))->toBe([['id' => 2, 'title' => 'B', 'created' => 1704153600], ['id' => 1, 'title' => 'A', 'created' => 1704067200]]);
});

// WP_Query ignores author => 0 (it would list every user's history) and turns numberposts => 0
// into the blog's posts_per_page default, so neither ever reaches the query.
it('lists nothing for a limit below one or for nobody, without querying', function (): void {
    Functions\expect('get_posts')->never();
    $s = new ConversationStore(new Store());
    expect($s->listFor(3, 0))->toBe([])
        ->and($s->listFor(3, -1))->toBe([])
        ->and($s->listFor(0, 5))->toBe([]);
});

// ---------------------------------------------------------------- delete()

it('deletes only the owner\'s conversation, permanently, and reports a second delete as false', function (): void {
    $post = (object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-01 00:00:00'];
    Functions\when('get_post')->justReturn($post);
    // Ownership is checked without loading (or converting) the transcript.
    Functions\expect('get_post_meta')->never();
    Functions\expect('wp_delete_post')->once()->with(42, true)->andReturn($post);
    $s = new ConversationStore(new Store());
    expect($s->delete(42, 9))->toBeFalse()
        ->and($s->delete(42, 3))->toBeTrue();
    Functions\when('get_post')->justReturn(null);
    expect($s->delete(42, 3))->toBeFalse();
});

// ---------------------------------------------------------- deleteIfEmpty()

// Pipeline takes back the post it made for a turn that then failed. "Empty" is judged on what is
// stored, not on the in-memory Conversation: a row with a 1.0 transcript, or a 0.4 one that has
// not been converted yet, is a conversation somebody can still open and stays.
it('deletes the owner\'s conversation only while nothing is stored on it, checking both transcript keys', function (): void {
    $post = (object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-01 00:00:00'];
    Functions\when('get_post')->justReturn($post);
    $meta = [];
    Functions\when('get_post_meta')->alias(static function (int $id, string $key) use (&$meta): mixed {
        return $meta[$key] ?? '';
    });
    Functions\expect('update_post_meta')->never(); // no legacy conversion on the way out
    $deleted = 0;
    Functions\when('wp_delete_post')->alias(static function (int $id, bool $force) use (&$deleted, $post): object {
        $deleted += $force ? 1 : 0;
        return $post;
    });
    $s = new ConversationStore(new Store());

    expect($s->deleteIfEmpty(42, 9))->toBeFalse()->and($deleted)->toBe(0); // not the owner
    expect($s->deleteIfEmpty(42, 3))->toBeTrue()->and($deleted)->toBe(1); // no meta at all
    $meta = ['ab_messages' => []];
    expect($s->deleteIfEmpty(42, 3))->toBeTrue()->and($deleted)->toBe(2); // an empty transcript is still empty
    $meta = ['ab_messages' => [['role' => 'user', 'content' => 'Hi']]];
    expect($s->deleteIfEmpty(42, 3))->toBeFalse()->and($deleted)->toBe(2);
    $meta = ['messages' => [['model' => 'm', 'message' => ['role' => 0, 'content' => 'q']]]];
    expect($s->deleteIfEmpty(42, 3))->toBeFalse()->and($deleted)->toBe(2);
});

// ------------------------------------------------------- registerPostType()

it('registers a private, non-searchable chat_history type that dies with its user and cannot be created from the UI', function (): void {
    Functions\expect('register_post_type')->once()->withArgs(function (string $type, array $args): bool {
        return $type === ConversationStore::POST_TYPE
            && $args['public'] === false
            && $args['show_ui'] === false
            && $args['show_in_rest'] === false
            && $args['exclude_from_search'] === true
            && $args['delete_with_user'] === true
            && $args['supports'] === ['title', 'excerpt', 'author']
            && $args['capabilities'] === ['create_posts' => 'do_not_allow']
            && $args['map_meta_cap'] === true;
    });
    (new ConversationStore(new Store()))->registerPostType();
});
