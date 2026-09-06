<?php

declare(strict_types=1);

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Rest\ConversationsController;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

// restRequest() and conversationChatPost() live in tests/Pest.php. ConversationStore is final,
// so the controller runs over the real one with WordPress stubbed: what is pinned here is that
// every route asks the store as the current user (3) and nothing else, which is the ownership
// boundary. ChatRoutesTest (integration) runs the same routes over real posts.

beforeEach(function (): void {
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    $this->controller = new ConversationsController(new ConversationStore(new Store()), new Store(['chat.history_limit' => 20]));
});

it('declares the collection and item routes for editors, none rate limited', function (): void {
    $routes = $this->controller->routes();
    expect(array_map(static fn(array $r): array => [$r['path'], $r['methods']], $routes))->toBe([
        ['/conversations', 'GET'],
        ['/conversations', 'DELETE'],
        ['/conversations/(?P<id>\d+)', 'GET'],
        ['/conversations/(?P<id>\d+)', 'DELETE'],
    ])
        ->and(array_unique(array_column($routes, 'capability')))->toBe(['edit_posts'])
        ->and(array_filter($routes, static fn(array $r): bool => !empty($r['rate_limit'])))->toBe([]);
});

it('lists the current user\'s conversations, chat.history_limit deep unless the request says otherwise, clamped to 200', function (): void {
    $asked = [];
    Functions\when('get_posts')->alias(static function (array $query) use (&$asked): array {
        $asked[] = [$query['author'], $query['numberposts']];
        return [(object) ['ID' => 2, 'post_title' => 'B', 'post_date_gmt' => '2024-01-02 00:00:00']];
    });
    expect($this->controller->index(restRequest('GET', '/alpaca-bot/v1/conversations'))->get_data())->toBe([['id' => 2, 'title' => 'B', 'created' => 1704153600]]);
    $this->controller->index(restRequest('GET', '/alpaca-bot/v1/conversations', ['limit' => '5']));
    $this->controller->index(restRequest('GET', '/alpaca-bot/v1/conversations', ['limit' => 5000]));
    $this->controller->index(restRequest('GET', '/alpaca-bot/v1/conversations', ['limit' => -1]));
    expect($asked)->toBe([[3, 20], [3, 5], [3, 200], [3, 20]]);
});

it('shows a conversation with its transcript, and 404s one that is not the user\'s', function (): void {
    Functions\when('get_post')->alias(static fn(int $id): ?object => match ($id) {
        7 => conversationChatPost(7, '3'),
        8 => conversationChatPost(8, '9'),
        default => null,
    });
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'Hi', 'model' => 'm', 'created' => 1], ['role' => 'assistant', 'content' => 'Yo', 'model' => 'm', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1], 'created' => 2]]
        : '');
    $res = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/conversations/7', ['id' => '7']));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)
        ->and($res->get_data())->toBe([
            'id' => 7,
            'title' => 'T',
            'created' => 1704067200,
            'mode' => 'chat',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi', 'model' => 'm', 'usage' => null, 'created' => 1, 'images' => [], 'meta' => []],
                ['role' => 'assistant', 'content' => 'Yo', 'model' => 'm', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1], 'created' => 2, 'images' => [], 'meta' => []],
            ],
        ]);
    foreach (['8', '9'] as $id) {
        $missing = $this->controller->show(restRequest('GET', '/alpaca-bot/v1/conversations/' . $id, ['id' => $id]));
        expect($missing)->toBeInstanceOf(WP_Error::class)
            ->and($missing->get_error_code())->toBe('alpaca_bot_not_found')
            ->and($missing->get_error_data()['status'])->toBe(404);
    }
});

it('deletes one conversation as the user, 404 when it is not theirs', function (): void {
    Functions\when('get_post')->alias(static fn(int $id): ?object => $id === 7 ? conversationChatPost(7, '3') : conversationChatPost(8, '9'));
    Functions\expect('wp_delete_post')->once()->with(7, true)->andReturn(conversationChatPost(7, '3'));
    expect($this->controller->destroy(restRequest('DELETE', '/alpaca-bot/v1/conversations/7', ['id' => '7']))->get_data())->toBe(['deleted' => true]);
    $missing = $this->controller->destroy(restRequest('DELETE', '/alpaca-bot/v1/conversations/8', ['id' => '8']));
    expect($missing)->toBeInstanceOf(WP_Error::class)->and($missing->get_error_data()['status'])->toBe(404);
});

it('deletes every conversation of the user and counts what went', function (): void {
    Functions\expect('get_posts')->once()->withArgs(static fn(array $q): bool => $q['author'] === 3 && $q['numberposts'] >= 1000)
        ->andReturn([(object) ['ID' => 2, 'post_title' => 'B', 'post_date_gmt' => '2024-01-02 00:00:00'], (object) ['ID' => 1, 'post_title' => 'A', 'post_date_gmt' => '2024-01-01 00:00:00']]);
    Functions\when('get_post')->alias(static fn(int $id): object => conversationChatPost($id, '3'));
    $deleted = [];
    Functions\when('wp_delete_post')->alias(static function (int $id, bool $force) use (&$deleted): object {
        $deleted[] = $id;
        return conversationChatPost($id, '3');
    });
    expect($this->controller->destroyAll(restRequest('DELETE', '/alpaca-bot/v1/conversations'))->get_data())->toBe(['deleted' => 2])
        ->and($deleted)->toBe([2, 1]);
});
