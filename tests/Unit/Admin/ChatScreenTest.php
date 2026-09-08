<?php

declare(strict_types=1);

use AlpacaBot\Admin\ChatScreen;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// conversationChatPost() lives in tests/Pest.php. Every collaborator is final and runs for real
// over WordPress stubbed: a two-model catalog (from the transient), user 3 "Carmelo" whose
// stored default is llava:7b, post 5 hers with a two-turn transcript, post 6 user 9's, and a
// history of one row. The screen is captured from the output buffer, as it echoes.

function chatScreen(array $settings = []): ChatScreen
{
    foreach (['esc_html', 'esc_attr', 'esc_url', '__'] as $f) { Functions\when($f)->returnArg(); }
    Functions\when('wp_kses')->alias(fn(string $h) => $h);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('admin_url')->alias(fn(string $p) => '/wp-admin/' . $p);
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('wp_create_nonce')->justReturn('nonce');
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'Carmelo', 'ID' => 3]);
    Functions\when('get_avatar_url')->justReturn('/u.png');
    Functions\when('get_user_meta')->alias(static fn(int $id): string => $id === 3 ? 'llava:7b' : '');
    Functions\when('selected')->alias(fn($a, $b, $e = true) => $a == $b ? ' selected' : '');
    Functions\when('get_transient')->justReturn([['id' => 'llama3.2', 'label' => 'llama3.2'], ['id' => 'llava:7b', 'label' => 'llava']]);
    Filters\expectApplied('alpaca_bot/models')->zeroOrMoreTimes()->andReturnFirstArg();
    Functions\when('get_post')->alias(static fn(int $id): ?object => match ($id) {
        5 => conversationChatPost(5, '3'),
        6 => conversationChatPost(6, '9'),
        default => null,
    });
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'q'], ['role' => 'assistant', 'content' => 'a', 'model' => 'llama3.2']]
        : '');
    Functions\when('get_posts')->justReturn([(object) ['ID' => 5, 'post_title' => 'T', 'post_date_gmt' => '2024-01-01 00:00:00']]);
    $store = new Store($settings + ['models.default' => 'llama3.2', 'chat.history_limit' => 15]);
    return new ChatScreen($store, new ModelCatalog(new Factory($store)), new ConversationStore($store), new UserPrefs());
}

function chatScreenHtml(array $settings = []): string
{
    ob_start();
    chatScreen($settings)->render();
    return (string) ob_get_clean();
}

afterEach(function (): void {
    $_GET = [];
});

it('renders a new chat on the bare screen, on the user\'s effective model, with their history', function (): void {
    $html = chatScreenHtml();
    expect($html)->toStartWith('<div class="wrap ab-wrap">')
        ->toContain('<h1 class="wp-heading-inline">Alpaca Bot</h1>')->toContain('class="page-title-action"')
        ->toContain('id="ab-model"')->toContain('<option value="llava:7b" selected>')
        ->toContain('id="ab-history"')->toContain('<option value="5" data-id="5">T</option>')->toContain('<option value="0" data-id="0" selected>')
        ->toContain('data-conversation="0"')->toContain('ab-welcome')->toContain('id="ab-form"')
        ->toContain('name="model" value="llava:7b"')->toContain('name="context[post_id]" value="0"')->toContain('name="_wpnonce" value="nonce"');
});

it('opens the conversation ?conversation= names when it is the user\'s, and the post being edited rides along as context', function (): void {
    $_GET = ['conversation' => '5', 'post' => '12'];
    $html = chatScreenHtml();
    expect($html)->toContain('data-conversation="5"')->toContain('ab-msg--assistant')->not->toContain('ab-welcome')
        ->toContain('<option value="5" data-id="5" selected>')->toContain('name="conversation_id" value="5"')->toContain('name="context[post_id]" value="12"');
});

it('falls back to a new chat for a conversation that is not the user\'s, and ignores a query value that is not a number', function (): void {
    $_GET = ['conversation' => '6', 'post' => ['x']];
    $html = chatScreenHtml();
    expect($html)->toContain('data-conversation="0"')->toContain('ab-welcome')->toContain('name="context[post_id]" value="0"');
});

it('starts on the site default while users may not change the model', function (): void {
    expect(chatScreenHtml(['chat.user_can_change_model' => false]))->toContain('<option value="llama3.2" selected>')->toContain('name="model" value="llama3.2"')->toContain(' disabled');
});
