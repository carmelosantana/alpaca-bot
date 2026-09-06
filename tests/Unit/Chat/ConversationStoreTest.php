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
    Functions\when('wp_trim_words')->alias(fn(string $s, int $n = 8) => implode(' ', array_slice(explode(' ', $s), 0, $n)));
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
    expect($a)->toBe(['role' => 'assistant', 'content' => 'hi', 'model' => 'llama3.2', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2], 'created' => 1_700_000_000, 'images' => ['data:image/png;base64,x'], 'meta' => ['finish' => 'stop']]);
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
    $s = new ConversationStore(new Store());
    $c = $s->create(3);
    expect($c->id)->toBe(42);
    $c->append(new Message('user', 'What is WordPress?'));
    $c->append(new Message('assistant', 'WordPress is a CMS.', 'llama3.2'));
    $s->save($c);
    expect($c->title)->toBe('What is WordPress');
});

it('keeps a title the caller chose instead of deriving one', function (): void {
    Functions\when('wp_insert_post')->justReturn(42);
    Functions\when('update_post_meta')->justReturn(true);
    Functions\expect('wp_update_post')->once()->withArgs(fn(array $p): bool => $p['post_title'] === 'Mine')->andReturn(42);
    $s = new ConversationStore(new Store());
    $c = $s->create(3, 'Mine');
    $c->append(new Message('user', 'Something else'));
    $s->save($c);
});

it('writes an empty message list but leaves title and excerpt alone', function (): void {
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

it('refuses to create a conversation for nobody', function (): void {
    Functions\expect('wp_insert_post')->never();
    (new ConversationStore(new Store()))->create(0);
})->throws(InvalidArgumentException::class);

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
    Functions\expect('update_post_meta')->once()->withArgs(fn(int $id, string $k, array $v): bool => $id === 42 && $k === ConversationStore::META_MESSAGES && count($v) === 1);
    Functions\expect('wp_update_post')->once()->withArgs(fn(array $p): bool => $p['ID'] === 42 && $p['post_title'] === 'T' && $p['post_excerpt'] === 'x')->andReturn(42);
    $c = new Conversation(42, 3, 'T');
    $c->append(new Message('user', 'x'));
    (new ConversationStore(new Store()))->save($c);
});

// ------------------------------------------------------------------ load()

it('loads only the owner\'s conversation and reads legacy meta once', function (): void {
    $post = (object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-01 00:00:00'];
    Functions\when('get_post')->justReturn($post);
    Functions\expect('get_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, true)->andReturn('');
    Functions\expect('get_post_meta')->once()->with(42, 'messages', true)->andReturn([['model' => 'm', 'message' => ['role' => 3, 'content' => 'q']], ['model' => 'm', 'message' => ['role' => 'assistant', 'content' => 'a']]]);
    Functions\expect('get_post_meta')->once()->with(42, 'chat_mode_generate', true)->andReturn('');
    Functions\expect('update_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, Mockery::type('array'));
    Functions\expect('delete_post_meta')->once()->with(42, 'messages');
    $s = new ConversationStore(new Store());
    $c = $s->load(42, 3);
    expect($c)->toBeInstanceOf(Conversation::class)->and($c->messages)->toHaveCount(2)->and($c->messages[0]->role)->toBe('user');
    expect($s->load(42, 9))->toBeNull();
});

it('reads ab_messages directly once converted and never touches the legacy key again', function (): void {
    $post = (object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-02 00:00:00'];
    Functions\when('get_post')->justReturn($post);
    Functions\expect('get_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, true)->andReturn([['role' => 'user', 'content' => 'q', 'model' => '', 'usage' => null, 'created' => 1, 'images' => [], 'meta' => []]]);
    Functions\expect('get_post_meta')->never()->with(42, 'messages', true);
    Functions\expect('get_post_meta')->once()->with(42, 'chat_mode_generate', true)->andReturn('1');
    Functions\expect('update_post_meta')->never();
    Functions\expect('delete_post_meta')->never();
    $c = (new ConversationStore(new Store()))->load(42, 3);
    expect($c?->messages)->toHaveCount(1)
        ->and($c?->mode)->toBe('generate')
        ->and($c?->title)->toBe('T')
        ->and($c?->userId)->toBe(3)
        ->and($c?->created)->toBe(1704153600);
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
    Functions\expect('delete_post_meta')->once()->with(42, 'messages');
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

it('lists the user\'s conversations newest first with a limit', function (): void {
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_history' && $q['author'] === 3 && $q['numberposts'] === 5 && $q['orderby'] === 'date' && $q['order'] === 'DESC' && $q['post_status'] === 'private')
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
