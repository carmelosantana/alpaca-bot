<?php

declare(strict_types=1);

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Rest\ViewController;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\View\Markdown;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// restRequest() and conversationChatPost() live in tests/Pest.php. ConversationStore, ModelCatalog
// and UserPrefs are final, so the controller runs over the real ones with WordPress stubbed:
// posts 5 and 6 exist, 5 is user 3's and 6 is user 9's; the current user is 3. The stub request
// (like core's) takes route attributes as its third constructor argument, so parameters go in
// through set_param(). ViewRoutesTest (integration) runs the same routes over real core dispatch.

/** @param array<string, mixed> $settings */
function viewController(array $settings = []): ViewController
{
    $store = new Store($settings + ['models.default' => 'llama3.2']);
    return new ViewController(new ConversationStore($store), $store, new ModelCatalog(new Factory($store)), new Markdown(), new UserPrefs());
}

beforeEach(function (): void {
    foreach (['esc_html', 'esc_attr', 'esc_url', 'esc_textarea', '__', 'sanitize_text_field'] as $f) { Functions\when($f)->returnArg(); }
    Functions\when('wp_kses')->alias(fn(string $h) => $h);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'C', 'ID' => 3]);
    Functions\when('get_avatar_url')->justReturn('/u.png');
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('selected')->justReturn('');
    Functions\when('number_format_i18n')->alias(fn($n) => (string) $n);
    Functions\when('get_post')->alias(static fn(int $id): ?object => match ($id) {
        5 => conversationChatPost(5, '3'),
        6 => conversationChatPost(6, '9'),
        default => null,
    });
    Functions\when('get_post_meta')->alias(static fn(int $id, string $key): mixed => $key === 'ab_messages'
        ? [['role' => 'user', 'content' => 'q'], ['role' => 'assistant', 'content' => 'a', 'model' => 'm']]
        : '');
    Functions\when('get_user_meta')->justReturn('');
});

it('returns an HTML message list for an owned conversation and 404 otherwise', function (): void {
    $c = viewController();
    $res = $c->messages(restRequest('GET', '/x', ['id' => '5']));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)->and($res->headers['X-Alpaca-Bot-View'])->toBe('1')->and($res->get_data())->toContain('id="ab-messages"')->toContain('ab-msg--assistant');
    expect($c->messages(restRequest('GET', '/x', ['id' => '6'])))->toBeInstanceOf(WP_Error::class);
});

it('persists the default model and returns a notice', function (): void {
    Functions\expect('update_user_meta')->once()->with(3, 'alpaca_bot_default_model', 'llama3.2');
    $res = viewController()->defaultModel(restRequest('POST', '/x', ['model' => 'llama3.2']));
    expect($res->get_data())->toContain('notice-success')->toContain('Default model saved');
});

// ---------------------------------------------------------------- beyond the brief's two

it('declares the five view routes for editors, with the model list rate limited like /models', function (): void {
    $routes = viewController()->routes();
    $byMethod = [];
    foreach ($routes as $route) {
        expect($route['capability'])->toBe('edit_posts');
        $byMethod[$route['methods'] . ' ' . $route['path']] = $route;
    }
    expect(array_keys($byMethod))->toBe(['GET /view/messages/(?P<id>\d+)', 'GET /view/history', 'GET /view/models', 'POST /view/default-model', 'GET /view/bubble', 'POST /view/bubble'])
        ->and($byMethod['GET /view/models']['args'])->toBe(['refresh' => ['type' => 'boolean', 'default' => false]])
        ->and($byMethod['GET /view/history']['args']['conversation_id']['type'])->toBe('integer')
        ->and($byMethod['POST /view/default-model']['args']['model']['required'])->toBeTrue()
        ->and($byMethod['GET /view/bubble']['args']['role']['enum'])->toBe(['user', 'assistant'])
        ->and($byMethod['POST /view/bubble']['args']['role']['enum'])->toBe(['user', 'assistant'])
        ->and($byMethod['POST /view/bubble']['args']['images'])->toBe(['type' => 'array', 'items' => ['type' => 'string'], 'default' => []])
        ->and($byMethod['POST /view/bubble']['args']['tool_calls'])->toBe(['type' => 'array', 'items' => ['type' => 'object'], 'default' => []])
        // One capability filter key per fragment, the {id} segment removed as for /conversations.
        ->and(array_map(ViewController::routeKey(...), array_column($routes, 'path')))->toBe(['view/messages', 'view/history', 'view/models', 'view/default-model', 'view/bubble', 'view/bubble']);
    foreach ($byMethod as $key => $route) {
        expect($route['rate_limit'] ?? false)->toBe($key === 'GET /view/models', $key);
    }
});

it('registers its routes and hooks rest_pre_serve_request to write the HTML itself', function (): void {
    $c = viewController();
    Functions\expect('register_rest_route')->times(6)->withArgs(fn(string $ns, string $path): bool => $ns === 'alpaca-bot/v1' && str_starts_with($path, '/view/'));
    Filters\expectAdded('rest_pre_serve_request')->once()->with([$c, 'serve'], PHP_INT_MAX, 4);
    $c->register();
});

it('refuses to save a default model while the site does not let users change the model, and never touches the preference', function (): void {
    // The select is disabled then, but a disabled select is markup: one attribute deleted in
    // devtools and the post goes out. The route is the guard (ModelSelect's docblock says so).
    Functions\expect('update_user_meta')->never();
    $res = viewController(['chat.user_can_change_model' => false])->defaultModel(restRequest('POST', '/x', ['model' => 'llama3.2']));
    expect($res)->toBeInstanceOf(WP_Error::class)
        ->and($res->get_error_code())->toBe('rest_forbidden')
        ->and($res->get_error_data()['status'])->toBe(403);
});

it('caps the stored default model at 200 characters, so a user cannot write an unbounded string into their own usermeta', function (): void {
    // sanitize_text_field() strips markup and caps nothing; a model id is a short token, and
    // the preference is per user, so the cap is the guard.
    $long = str_repeat('m', 300);
    Functions\expect('update_user_meta')->once()->with(3, 'alpaca_bot_default_model', str_repeat('m', 200));
    viewController()->defaultModel(restRequest('POST', '/x', ['model' => $long]));
    // Multibyte: the cap counts characters, not bytes, so it never splits one.
    $wide = str_repeat('é', 250);
    Functions\expect('update_user_meta')->once()->with(3, 'alpaca_bot_default_model', str_repeat('é', 200));
    viewController()->defaultModel(restRequest('POST', '/x', ['model' => $wide]));
});

it('refuses an empty default model as a bad request', function (): void {
    Functions\expect('update_user_meta')->never();
    $c = viewController();
    foreach ([[], ['model' => ''], ['model' => '   ']] as $params) {
        $res = $c->defaultModel(restRequest('POST', '/x', $params));
        expect($res)->toBeInstanceOf(WP_Error::class)->and($res->get_error_data()['status'])->toBe(400);
    }
});

it('renders the history select for the user, marking the open conversation and naming one the capped list left out', function (): void {
    $asked = [];
    Functions\when('get_posts')->alias(static function (array $query) use (&$asked): array {
        $asked[] = [$query['author'], $query['numberposts']];
        return [
            (object) ['ID' => 9, 'post_title' => 'Second', 'post_date_gmt' => '2024-01-02 00:00:00'],
            (object) ['ID' => 8, 'post_title' => 'First', 'post_date_gmt' => '2024-01-01 00:00:00'],
        ];
    });
    $c = viewController(['chat.history_limit' => 2]);

    // In the list: selected, no extra option, no load.
    $html = $c->history(restRequest('GET', '/x', ['conversation_id' => 9]))->get_data();
    expect($html)->toContain('id="ab-history"')->toContain('<option value="9" data-id="9" selected>Second</option>')->and(substr_count($html, 'data-id="9"'))->toBe(1);
    // Out of the list: its own option, with its title (the HistorySelect hand-off), loaded as user 3.
    $html = $c->history(restRequest('GET', '/x', ['conversation_id' => 5]))->get_data();
    expect($html)->toContain('<option value="5" data-id="5" selected>T</option>')->not->toContain('Untitled');
    // Not the user's (post 6 is user 9's): rendered as a new chat rather than refused; the select must always render.
    $res = $c->history(restRequest('GET', '/x', ['conversation_id' => 6]));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)->and($res->get_data())->toContain('<option value="0" data-id="0" selected>')->not->toContain('data-id="6"');
    // No id at all: a new chat. Every listing was the user's, chat.history_limit deep.
    expect($c->history(restRequest('GET', '/x'))->get_data())->toContain('<option value="0" data-id="0" selected>')
        ->and($asked)->toBe([[3, 2], [3, 2], [3, 2], [3, 2]]);
});

it('renders the model select on the user\'s effective model, asking the provider again on refresh', function (): void {
    Functions\when('set_transient')->justReturn(true);
    Filters\expectApplied('alpaca_bot/models')->zeroOrMoreTimes()->andReturnFirstArg();
    Functions\expect('get_transient')->once()->with(ModelCatalog::TRANSIENT)->andReturn([['id' => 'llama3.2', 'label' => 'llama3.2'], ['id' => 'llava:7b', 'label' => 'llava']]);
    Functions\when('get_user_meta')->justReturn('llava:7b');
    Functions\when('selected')->alias(fn($a, $b, $e = true) => $a == $b ? ' selected' : '');
    $c = viewController();
    $res = $c->models(restRequest('GET', '/x'));
    expect($res->headers['X-Alpaca-Bot-View'])->toBe('1')
        ->and($res->get_data())->toContain('id="ab-model"')->toContain('<option value="llava:7b" selected>')->toContain('<option value="llama3.2">')->not->toContain('disabled');

    // refresh=1 skips the transient: the provider is asked, through the filter Factory documents.
    // The stored preference is no longer listed, so the select falls to the site default.
    Functions\expect('get_transient')->never();
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn([new ModelDefinition(id: 'qwen3:8b', name: 'qwen3', provider: 'ollama'), new ModelDefinition(id: 'llama3.2', name: 'llama', provider: 'ollama')]);
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturn($provider);
    expect($c->models(restRequest('GET', '/x', ['refresh' => true]))->get_data())->toContain('<option value="llama3.2" selected>')->toContain('<option value="qwen3:8b">')->not->toContain('llava');

    // Users may not change the model: the select is disabled, on the site default.
    Functions\expect('get_transient')->once()->andReturn([['id' => 'llama3.2', 'label' => 'llama3.2'], ['id' => 'llava:7b', 'label' => 'llava']]);
    expect(viewController(['chat.user_can_change_model' => false])->models(restRequest('GET', '/x'))->get_data())->toContain(' disabled')->toContain('<option value="llama3.2" selected>');
});

it('renders an empty streaming bubble on GET and a finished, markdown-rendered bubble with its receipt on POST', function (): void {
    $c = viewController();
    $html = $c->streamingBubble(restRequest('GET', '/x', ['role' => 'assistant', 'streaming' => true]))->get_data();
    expect($html)->toContain('ab-msg--assistant')->toContain('data-streaming="1"')->toContain('<div class="ab-msg__content" aria-live="polite"></div>')->not->toContain('ab-receipt');

    $res = $c->bubble(restRequest('POST', '/x', ['role' => 'assistant', 'content' => 'Hello **you**', 'model' => 'llama3.2', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7], 'duration_ms' => 1234]));
    expect($res->headers['X-Alpaca-Bot-View'])->toBe('1')
        ->and($res->get_data())->toContain('<strong>you</strong>')->toContain('<footer class="ab-receipt">llama3.2 · 12 tokens · 1.2 s</footer>')->not->toContain('data-streaming');

    // The tool calls the done frame carried ride on the bubble's receipt as a count; an entry
    // that is not a record is not counted.
    $call = ['name' => 'web_fetch', 'arguments' => ['url' => 'https://example.test/'], 'result_excerpt' => 'Example', 'ok' => true];
    $html = $c->bubble(restRequest('POST', '/x', ['role' => 'assistant', 'content' => 'Fetched.', 'model' => 'llama3.2', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7], 'duration_ms' => 1234, 'tool_calls' => [$call, 'junk', $call]]))->get_data();
    expect($html)->toContain('<footer class="ab-receipt">llama3.2 · 12 tokens · 1.2 s · 2 tools</footer>');

    // A user turn is the user's own text, escaped, and carries no receipt whatever was posted.
    Functions\when('esc_html')->alias(fn(string $s) => htmlspecialchars($s));
    $html = $c->bubble(restRequest('POST', '/x', ['role' => 'user', 'content' => '<b>x</b>', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7]]))->get_data();
    expect($html)->toContain('ab-msg--user')->toContain('&lt;b&gt;x&lt;/b&gt;')->not->toContain('<b>x</b>')->not->toContain('ab-receipt');

    // A user turn carries its attached images, so the optimistic bubble shows what was sent.
    Functions\when('esc_attr')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    $png = 'data:image/png;base64,iVBORw0KGgo=';
    $html = $c->bubble(restRequest('POST', '/x', ['role' => 'user', 'content' => 'look', 'images' => [$png, 42, 'https://example.com/x.png']]))->get_data();
    expect($html)->toContain('<img class="ab-msg__image" src="' . $png . '"')->not->toContain('example.com');

    // A role that is not a turn is refused (the schema refuses it first over HTTP).
    expect($c->bubble(restRequest('POST', '/x', ['role' => 'system', 'content' => 'x'])))->toBeInstanceOf(WP_Error::class);
});

it('serve() writes a view response as HTML and leaves every other response to core', function (): void {
    $c = viewController();
    $server = new WP_REST_Server();
    $view = new WP_REST_Response('<div id="ab-status"></div>');
    $view->header('X-Alpaca-Bot-View', '1');

    ob_start();
    $served = $c->serve(false, $view, restRequest('GET', '/alpaca-bot/v1/view/history'), $server);
    $out = ob_get_clean();
    expect($served)->toBeTrue()->and($out)->toBe('<div id="ab-status"></div>')
        ->and($server->sent)->toBe([['Content-Type', 'text/html; charset=utf-8']]);

    // A JSON route's response, a WP_Error rendered by core, an enveloped copy (no header): untouched.
    $server = new WP_REST_Server();
    ob_start();
    $a = $c->serve(false, new WP_REST_Response(['id' => 1]), restRequest('GET', '/alpaca-bot/v1/conversations'), $server);
    $b = $c->serve(false, new WP_REST_Response(['code' => 'rest_forbidden'], 403), restRequest('GET', '/alpaca-bot/v1/view/history'), $server);
    $envelope = new WP_REST_Response(['body' => '<div></div>', 'status' => 200, 'headers' => ['X-Alpaca-Bot-View' => '1']]);
    $d = $c->serve(false, $envelope, restRequest('GET', '/alpaca-bot/v1/view/history'), $server);
    $out = ob_get_clean();
    expect([$a, $b, $d])->toBe([false, false, false])->and($out)->toBe('')->and($server->sent)->toBe([]);

    // Already served by something hooked earlier: nothing is written over it.
    ob_start();
    expect($c->serve(true, $view, restRequest('GET', '/alpaca-bot/v1/view/history'), $server))->toBeTrue();
    expect(ob_get_clean())->toBe('')->and($server->sent)->toBe([]);

    // HEAD (core sends it to the GET handler): the type is right and the body stays empty.
    ob_start();
    expect($c->serve(false, $view, restRequest('HEAD', '/alpaca-bot/v1/view/history'), $server))->toBeTrue();
    expect(ob_get_clean())->toBe('')->and($server->sent)->toBe([['Content-Type', 'text/html; charset=utf-8']]);
});
