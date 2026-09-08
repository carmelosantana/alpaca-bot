<?php

declare(strict_types=1);

use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Message;
use AlpacaBot\Provider\Model;
use AlpacaBot\View\Chat\Composer;
use AlpacaBot\View\Chat\Header;
use AlpacaBot\View\Chat\HistorySelect;
use AlpacaBot\View\Chat\MessageBubble;
use AlpacaBot\View\Chat\MessageList;
use AlpacaBot\View\Chat\ModelSelect;
use AlpacaBot\View\Chat\Notice;
use AlpacaBot\View\Chat\Receipt;
use AlpacaBot\View\Markdown;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    foreach (['esc_html', 'esc_attr', 'esc_url', 'esc_textarea', '__', 'wp_kses_post'] as $f) { Functions\when($f)->returnArg(); }
    Functions\when('wp_kses')->alias(fn(string $h) => $h);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('get_option')->justReturn([]);
    Functions\when('selected')->alias(fn($a, $b, $e = true) => $a == $b ? ' selected' : '');
    Functions\when('number_format_i18n')->alias(fn($n) => (string) $n);
});

it('renders an assistant bubble with markdown, copy button, and receipt', function (): void {
    // The reply as Pipeline::send() builds it (usage from the provider, meta holding the turn's
    // duration and any reasoning), read back through the storage shape: this is a reloaded
    // transcript, and its receipt must show the seconds a live turn showed.
    $stored = (new Message('assistant', "Hello **you**", 'llama3.2', ['prompt_tokens' => 5, 'completion_tokens' => 7], 0, [], ['duration_ms' => 1234, 'reasoning' => 'hmm']))->toArray();
    $html = (new MessageBubble(Message::fromArray($stored), new Markdown(), 'Carmelo', '/u.png', '/a.png'))->render();
    expect($html)->toContain('class="ab-msg ab-msg--assistant"')->toContain('<strong>you</strong>')->toContain('data-action="copy"')->toContain('aria-label=')
        ->toContain('<footer class="ab-receipt">llama3.2 · 12 tokens · 1.2 s</footer>')->not->toContain('hmm');
});

it('renders an assistant bubble without a receipt when the provider reported no usage', function (): void {
    $html = (new MessageBubble(new Message('assistant', 'a', 'llama3.2'), new Markdown(), 'C', '/u.png', '/a.png'))->render();
    expect($html)->toContain('ab-msg--assistant')->not->toContain('ab-receipt');
});

it('renders a user bubble escaped with edit action', function (): void {
    $m = new Message('user', "<b>x</b>\nline2");
    Functions\when('esc_html')->alias(fn(string $s) => htmlspecialchars($s));
    $html = (new MessageBubble($m, new Markdown(), 'Carmelo', '/u.png', '/a.png'))->render();
    expect($html)->toContain('&lt;b&gt;x&lt;/b&gt;<br>')->toContain('data-action="edit"')->not->toContain('<b>x</b>');
});

it('renders a streaming placeholder', function (): void {
    $html = (new MessageBubble(new Message('assistant', 'ignored while streaming'), new Markdown(), 'C', '/u.png', '/a.png', true))->render();
    expect($html)->toContain('data-streaming="1"')->toContain('<div class="ab-msg__content" aria-live="polite"></div>')->not->toContain('ignored while streaming');
});

it('model select disables when users cannot change and marks selection', function (): void {
    $html = (new ModelSelect([new Model('a', 'a'), new Model('b', 'b', vision: true)], 'b', false))->render();
    expect($html)->toContain('<select')->toContain('disabled')->toContain('<option value="b" selected')->toContain('hx-post="/wp-json/alpaca-bot/v1/view/default-model"');
});

it('message list shows the welcome block when empty', function (): void {
    Functions\when('get_option')->justReturn(['chat.welcome' => 'Hey there']);
    $html = (new MessageList([], new Markdown(), new Store(), 'C', '/u.png', '/a.png'))->render();
    expect($html)->toContain('id="ab-messages"')->toContain('Hey there')->toContain('ab-welcome');
});

it('composer carries hidden fields, spellcheck, and no hx attributes', function (): void {
    Functions\when('get_option')->justReturn(['chat.spellcheck' => false, 'chat.placeholder' => 'Ask']);
    $html = (new Composer(new Store(), 0, 'llama3.2', 12))->render();
    expect($html)->toContain('id="ab-form"')->toContain('spellcheck="false"')->toContain('placeholder="Ask"')->toContain('name="conversation_id" value="0"')->toContain('name="context[post_id]" value="12"')->toContain('data-action="send"')->not->toContain('hx-')
        // The nonce rides as the X-WP-Nonce header from the localised settings; a hidden field would be a second copy nothing reads.
        ->and($html)->not->toContain('_wpnonce');
});

it('receipt formats tokens and seconds', function (): void {
    expect((new Receipt(['model' => 'm', 'total_tokens' => 1234, 'duration_ms' => 2345]))->render())->toContain('m')->toContain('1234 tokens')->toContain('2.3 s');
});

// ---------------------------------------------------------------- beyond the brief's seven

it('user bubble names the user, carries no receipt, and every icon button is labelled', function (): void {
    $html = (new MessageBubble(new Message('user', 'hi'), new Markdown(), 'Carmelo', '/u.png', '/a.png'))->render();
    expect($html)->toContain('ab-msg--user')->toContain('data-role="user"')->toContain('Carmelo')->toContain('src="/u.png"')
        ->not->toContain('ab-receipt')->not->toContain('data-streaming');
    // Every button holds an aria-label: an icon-only button is otherwise nameless.
    preg_match_all('/<button\b[^>]*>/', $html, $buttons);
    expect($buttons[0])->not->toBeEmpty();
    foreach ($buttons[0] as $button) {
        expect($button)->toContain('aria-label="');
    }
});

it('message list renders user and assistant turns in order, skips other roles, and carries the conversation id', function (): void {
    $messages = [new Message('system', 'be terse'), new Message('user', 'q'), new Message('assistant', 'a', 'm')];
    $html = (new MessageList($messages, new Markdown(), new Store(), 'C', '/u.png', '/a.png', 7))->render();
    expect($html)->toContain('data-conversation="7"')->not->toContain('be terse')->not->toContain('ab-welcome')
        ->and(strpos($html, 'ab-msg--user'))->toBeLessThan((int) strpos($html, 'ab-msg--assistant'));
});

it('history select lists conversations with data-id, marks the current one, and refreshes on ab:refresh', function (): void {
    $html = (new HistorySelect([['id' => 5, 'title' => 'First', 'created' => 1], ['id' => 6, 'title' => 'Second <b>', 'created' => 2]], 6))->render();
    // The select asks for /messages/0; chat.ts swaps the 0 for the chosen option's data-id in
    // htmx:configRequest, so no hx-* attribute is ever written from TypeScript.
    expect($html)->toContain('id="ab-history"')->toContain('hx-get="/wp-json/alpaca-bot/v1/view/messages/0"')->toContain('hx-target="#ab-messages"')->toContain('hx-trigger="change"')
        ->toContain('<option value="6" data-id="6" selected')->toContain('<option value="5" data-id="5">First')->toContain('<option value="0" data-id="0">')
        ->toContain('hx-get="/wp-json/alpaca-bot/v1/view/history"')->toContain('ab:refresh from:body')->toContain('screen-reader-text');
});

it('header renders the heading, the new-chat action, and both selects in WP admin chrome', function (): void {
    Functions\when('admin_url')->alias(fn(string $p) => '/wp-admin/' . $p);
    $html = (new Header('Alpaca Bot', new ModelSelect([new Model('a', 'a')], 'a', true), new HistorySelect([], 0)))->render();
    expect($html)->toContain('<h1 class="wp-heading-inline">Alpaca Bot</h1>')->toContain('class="page-title-action"')->toContain('href="/wp-admin/admin.php?page=alpaca-bot"')
        ->toContain('id="ab-model"')->toContain('id="ab-history"')->toContain('<hr class="wp-header-end">');
});

it('notice renders WP admin notice markup and refuses an unknown kind', function (): void {
    expect((new Notice('success', 'Default model saved'))->render())->toBe('<div class="notice notice-success inline"><p>Default model saved</p></div>')
        ->and((new Notice('bogus" onload="x', 'x'))->render())->toContain('notice-info');
});

it('shell composes the page: sprite once, header, status, message list, composer', function (): void {
    $sprite = sys_get_temp_dir() . '/ab-icons-' . getmypid() . '.svg';
    file_put_contents($sprite, '<svg xmlns="http://www.w3.org/2000/svg" style="display:none"><symbol id="lucide-copy"></symbol></svg>');
    try {
        $html = chatShell(new Conversation(5, 3, 'T', [new Message('user', 'q'), new Message('assistant', 'a', 'llama3.2')]), [['id' => 5, 'title' => 'T', 'created' => 1]], $sprite, 12)->render();
    } finally {
        unlink($sprite);
    }
    expect($html)->toStartWith('<div class="wrap ab-wrap">')
        ->and(substr_count($html, '<symbol id="lucide-copy">'))->toBe(1)
        ->and($html)->toContain('<h1 class="wp-heading-inline">Alpaca Bot</h1>')
        // The conversation id is the one figure chat.ts reads from the markup; the REST root is localised, not an attribute.
        ->toContain('<div id="ab-chat" data-conversation="5">')->not->toContain('data-rest')->not->toContain('data-history-limit')
        ->toContain('id="ab-status"')->toContain('id="ab-messages"')->toContain('ab-msg--assistant')->toContain('id="ab-form"')
        ->toContain('name="conversation_id" value="5"')->toContain('name="context[post_id]" value="12"')->toContain('name="model" value="llama3.2"')
        ->toContain('<option value="5" data-id="5" selected')->toContain('src="/plugins/alpaca-bot/assets/img/icon-80.png"');
    // The status region and the message list sit inside #ab-chat, ahead of the composer.
    expect(strpos($html, 'id="ab-status"'))->toBeLessThan((int) strpos($html, 'id="ab-messages"'))
        ->and(strpos($html, 'id="ab-messages"'))->toBeLessThan((int) strpos($html, 'id="ab-form"'));
});

it('shell renders without the sprite, warning-free, when the assets are not built', function (): void {
    set_error_handler(static function (int $no, string $msg): never {
        throw new ErrorException($msg, $no);
    });
    try {
        $html = chatShell(null, [], sys_get_temp_dir() . '/ab-missing-' . getmypid() . '.svg')->render();
    } finally {
        restore_error_handler();
    }
    expect($html)->not->toContain('<symbol')->toContain('data-conversation="0"')->toContain('ab-welcome')->toContain('<option value="0" data-id="0" selected');
});

// ---------------------------------------------------------------- review fixes

it('history select lists the open conversation even when the capped history left it out', function (): void {
    // chat.history_limit caps $history; an older conversation opened by id is not in it. Without
    // its own option the browser would select "New chat" while the transcript shows conversation 3.
    $html = (new HistorySelect([['id' => 5, 'title' => 'First', 'created' => 1], ['id' => 6, 'title' => 'Second', 'created' => 2]], 3, 'Older one'))->render();
    expect($html)->toContain('<option value="3" data-id="3" selected>Older one</option>')
        ->and(substr_count($html, ' selected'))->toBe(1)
        ->and(strpos($html, 'data-id="0"'))->toBeLessThan((int) strpos($html, 'data-id="3"'))
        ->and(strpos($html, 'data-id="3"'))->toBeLessThan((int) strpos($html, 'data-id="6"'));
    // Present in the list: no duplicate option is added.
    $html = (new HistorySelect([['id' => 5, 'title' => 'First', 'created' => 1]], 5, 'First'))->render();
    expect(substr_count($html, 'data-id="5"'))->toBe(1)->and(substr_count($html, ' selected'))->toBe(1);
});

it('shell selects the open conversation in the history when the list does not carry it', function (): void {
    $html = chatShell(new Conversation(3, 3, 'Older one', [new Message('user', 'q')]), [['id' => 5, 'title' => 'T', 'created' => 1]], sys_get_temp_dir() . '/ab-missing-' . getmypid() . '.svg')->render();
    expect($html)->toContain('<option value="3" data-id="3" selected>Older one</option>')->toContain('data-conversation="3"')->toContain('name="conversation_id" value="3"');
});

it('model select escapes a hostile model id and label', function (): void {
    Functions\when('esc_attr')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    $html = (new ModelSelect([new Model('a" onfocus="alert(1)', '<b>bold</b>')], 'a" onfocus="alert(1)', true))->render();
    expect($html)->toContain('<option value="a&quot; onfocus=&quot;alert(1)" selected>&lt;b&gt;bold&lt;/b&gt;</option>')
        ->not->toContain('onfocus="alert')->not->toContain('<b>');
});

it('history select escapes a hostile conversation title', function (): void {
    Functions\when('esc_attr')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    $html = (new HistorySelect([['id' => 6, 'title' => '</option><script>x()</script>"', 'created' => 2]], 9, '"><img src=x onerror=y>'))->render();
    expect($html)->toContain('<option value="6" data-id="6">&lt;/option&gt;&lt;script&gt;x()&lt;/script&gt;&quot;</option>')
        ->toContain('<option value="9" data-id="9" selected>&quot;&gt;&lt;img src=x onerror=y&gt;</option>')
        ->not->toContain('<script>')->not->toContain('<img');
});

it('passes every URL-valued attribute through esc_url', function (): void {
    Functions\when('esc_url')->alias(fn(string $u) => 'URL(' . $u . ')');
    Functions\when('admin_url')->alias(fn(string $p) => '/wp-admin/' . $p);
    Functions\when('get_option')->justReturn(['chat.welcome' => 'Hey']);
    expect((new MessageBubble(new Message('user', 'hi'), new Markdown(), 'C', '/u.png', '/a.png'))->render())->toContain('src="URL(/u.png)"')
        ->and((new MessageBubble(new Message('assistant', 'hi'), new Markdown(), 'C', '/u.png', '/a.png'))->render())->toContain('src="URL(/a.png)"')
        ->and((new MessageList([], new Markdown(), new Store(), 'C', '/u.png', '/a.png'))->render())->toContain('src="URL(/a.png)"')
        ->and((new Header('T', new ModelSelect([], 'a', true), new HistorySelect([], 0)))->render())->toContain('href="URL(/wp-admin/admin.php?page=alpaca-bot)"')
        ->and(chatShell(null, [], sys_get_temp_dir() . '/ab-missing-' . getmypid() . '.svg')->render())->toContain('href="URL(/wp-admin/admin.php?page=alpaca-bot)"')->toContain('src="URL(/plugins/alpaca-bot/assets/img/icon-80.png)"');
});

// ---------------------------------------------------------------- Task 5: a user turn's images

it('renders a user turn\'s attached image from its data URL and drops anything that is not one', function (): void {
    // esc_url() strips data: (not in wp_allowed_protocols), so the src is validated as a
    // base64 image data URL and attribute-escaped instead; anything else is not rendered.
    Functions\when('esc_attr')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    $png = 'data:image/png;base64,iVBORw0KGgo=';
    // The trailing-newline case: `$` alone would match before a final "\n" (PCRE without D), so the
    // pattern ends in \z and a value with any whitespace at all is dropped, as its docblock says.
    $html = (new MessageBubble(new Message('user', 'look', '', null, 0, [$png, 'javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'https://example.com/x.png', 'data:image/png;base64,abc" onerror="x', '', "data:image/png;base64,QUJD\n"]), new Markdown(), 'C', '/u.png', '/a.png'))->render();
    expect($html)->toContain('<div class="ab-msg__images"><img class="ab-msg__image" src="' . $png . '" alt="Attached image"></div>')
        ->and(substr_count($html, '<img class="ab-msg__image"'))->toBe(1)
        ->and($html)->not->toContain('javascript:')->not->toContain('text/html')->not->toContain('example.com')->not->toContain('onerror')->not->toContain('QUJD');
    // No images, or an assistant turn: no image block at all.
    expect((new MessageBubble(new Message('user', 'hi'), new Markdown(), 'C', '/u.png', '/a.png'))->render())->not->toContain('ab-msg__images')
        ->and((new MessageBubble(new Message('assistant', 'hi', 'm', null, 0, [$png]), new Markdown(), 'C', '/u.png', '/a.png'))->render())->not->toContain('ab-msg__images');
});

