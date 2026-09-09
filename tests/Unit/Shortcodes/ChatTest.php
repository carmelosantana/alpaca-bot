<?php

declare(strict_types=1);

use AlpacaBot\Admin\Assets;
use AlpacaBot\Shortcodes\Chat;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// shortcodeChat() and shortcodeViewer() live in tests/Pest.php. The pipeline is the real one
// over pipelineWith()'s harness; a test whose path must never generate builds it with
// pipelineWith(null), which pins that no provider is ever built (the alpaca_bot/provider
// filter never fires), so deleting the capability check or the cache lookup fails the test
// rather than passing it with the guard off.

/** A reply of `$text` with usage, as the provider streams one. */
function shortcodeReply(string $text): array
{
    return [new Response($text, ProviderFinishReason::Stop), new Response('', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7))];
}

it('parses the attributes with their defaults, and only the word off switches the cache off', function (): void {
    Functions\when('shortcode_atts')->alias(static fn(array $pairs, array $atts): array => array_merge($pairs, array_intersect_key($atts, $pairs)));
    expect(Chat::attributes(''))->toBe(['prompt' => '', 'model' => '', 'system' => '', 'temperature' => null, 'format' => 'markdown', 'cache' => 3600])
        ->and(Chat::attributes(['prompt' => ' Say hi ', 'model' => 'llama3.2', 'system' => 'Be terse', 'temperature' => '0.2', 'format' => 'text', 'cache' => '30m']))
        ->toBe(['prompt' => 'Say hi', 'model' => 'llama3.2', 'system' => 'Be terse', 'temperature' => 0.2, 'format' => 'text', 'cache' => 1800])
        // An attribute the shortcode does not know is dropped, a format it does not know is markdown (the kses'd path).
        ->and(Chat::attributes(['prompt' => 'x', 'format' => 'html', 'bogus' => '1']))->toBe(['prompt' => 'x', 'model' => '', 'system' => '', 'temperature' => null, 'format' => 'markdown', 'cache' => 3600])
        // A temperature that is not a number is not sent; one past the schema's range is held to it.
        ->and(Chat::attributes(['temperature' => 'warm'])['temperature'])->toBeNull()
        ->and(Chat::attributes(['temperature' => '9'])['temperature'])->toBe(2.0)
        ->and(Chat::attributes(['temperature' => '-1'])['temperature'])->toBe(0.0);
    // The cache is the spend control: a value that is not a duration keeps the default rather
    // than reading as "no cache", and "0" is not a way to switch it off.
    expect(Chat::cacheSeconds('off'))->toBe(0)
        ->and(Chat::cacheSeconds('OFF'))->toBe(0)
        ->and(Chat::cacheSeconds('1h'))->toBe(3600)
        ->and(Chat::cacheSeconds('45s'))->toBe(45)
        ->and(Chat::cacheSeconds('2d'))->toBe(172800)
        ->and(Chat::cacheSeconds('90'))->toBe(90)
        ->and(Chat::cacheSeconds(''))->toBe(3600)
        ->and(Chat::cacheSeconds('0'))->toBe(3600)
        ->and(Chat::cacheSeconds('no'))->toBe(3600)
        ->and(Chat::cacheSeconds('1 hour'))->toBe(3600)
        ->and(Chat::cacheSeconds('forever'))->toBe(3600)
        ->and(Chat::DEFAULT_CACHE)->toBe('1h');
});

it('answers an editor through an ephemeral turn with the attributes as options, renders markdown, and caches the text for the post', function (): void {
    $provider = pipelineProvider(shortcodeReply("**Hi** there\n\n<script>x()</script>"), $call);
    $h = pipelineWith($provider, ['chat.system_prompt' => 'Site prompt']);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $html = $chat->render(['prompt' => 'Say hi', 'system' => 'Be terse', 'temperature' => '0.2', 'model' => 'llama3.2'], null, 'alpacabot');
    expect($html)->toContain('<strong>Hi</strong>')->toContain('class="alpaca-bot-answer"')->not->toContain('<script>')
        ->and($h->model)->toBe('llama3.2')
        ->and($call['messages'][0])->toBeInstanceOf(SystemMessage::class)
        ->and($call['messages'][0]->content())->toBe('Be terse')
        ->and($call['messages'][1])->toBeInstanceOf(UserMessage::class)
        ->and($call['messages'][1]->content())->toBe('Say hi')
        ->and($call['options']['temperature'])->toBe(0.2);
    // Ephemeral: the turn is billed (a chat_log receipt for user 3) and no conversation post is made.
    expect(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([['wp_insert_post', 'chat_log']]);
    // Cached for the default hour under the attributes and the post.
    expect($h->stored)->toHaveCount(1)
        ->and($h->stored[0][0])->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => 'llama3.2', 'system' => 'Be terse', 'temperature' => 0.2], 7))
        ->and($h->stored[0][0])->toStartWith('alpaca_bot_shortcode_')
        ->and($h->stored[0][1])->toBe("**Hi** there\n\n<script>x()</script>")
        ->and($h->stored[0][2])->toBe(3600);
});

it('serves a cached answer to an editor without building a provider', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $h->transients[Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => '', 'system' => '', 'temperature' => null], 7)] = 'From the cache';
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('From the cache')
        ->and($h->stored)->toBe([])
        ->and($h->writes)->toBe([]);
});

it('never generates for a guest: no cache entry means the login notice, however the filter answers', function (): void {
    // The spend control P3 worried about: a forgotten page must not spend tokens on a viewer
    // nobody vouches for. The filter that lets guests see the shortcode lets them see the
    // cache and nothing else. pipelineWith(null) fails the test if a provider is ever built.
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(0);
    Filters\expectApplied('alpaca_bot/shortcode/allow_guests')->once()->with(false, 7, 'alpacabot')->andReturn(true);
    $html = $chat->render(['prompt' => 'Say hi', 'cache' => 'off'], null, 'alpacabot');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('Log in')->toContain('/wp-login.php?redirect_to=https%3A%2F%2Fsite.test%2F%3Fp%3D7')
        ->and($h->stored)->toBe([])
        ->and($h->writes)->toBe([]);
});

it('serves a guest the cached answer when the filter allows guests, still without a provider', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(0);
    Filters\expectApplied('alpaca_bot/shortcode/allow_guests')->once()->andReturn(true);
    $h->transients[Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => '', 'system' => '', 'temperature' => null], 7)] = 'From the cache';
    $html = $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($html)->toContain('From the cache')->not->toContain('alpaca-bot-notice')
        ->and($h->writes)->toBe([]);
});

it('keeps a cached answer from a guest, and from a logged-in user who cannot edit posts, while the filter is off', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    $h->transients[Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => '', 'system' => '', 'temperature' => null], 7)] = 'From the cache';
    shortcodeViewer(0);
    Filters\expectApplied('alpaca_bot/shortcode/allow_guests')->once()->andReturn(false);
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('Log in')->not->toContain('From the cache');
    // A subscriber: logged in, so no login link; the notice says who the answer is for.
    shortcodeViewer(5, ['read']);
    Filters\expectApplied('alpaca_bot/shortcode/allow_guests')->once()->andReturn(false);
    $html = $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('edit posts')->not->toContain('wp-login.php')->not->toContain('From the cache')
        ->and($h->writes)->toBe([]);
});

it('cache="off" reads no transient and writes none, and generates on every render', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('stream')->twice()->andReturnUsing(static function (): \Generator {
        yield from shortcodeReply('Fresh');
    });
    $h = pipelineWith($provider, turns: 2);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $first = $chat->render(['prompt' => 'Say hi', 'cache' => 'off'], null, 'alpacabot');
    $chat = shortcodeChat($h, 7);
    $second = $chat->render(['prompt' => 'Say hi', 'cache' => 'off'], null, 'alpacabot');
    expect($first)->toContain('Fresh')->and($second)->toContain('Fresh')
        ->and($h->stored)->toBe([])
        ->and(array_filter($h->reads, static fn(string $k): bool => str_starts_with($k, 'alpaca_bot_shortcode_')))->toBe([]);
});

it('keys the cache on the post, so the same shortcode on two pages generates twice, and the same one twice on a page generates once', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('stream')->twice()->andReturnUsing(static function (): \Generator {
        yield from shortcodeReply('Answer');
    });
    $h = pipelineWith($provider, turns: 2);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    // Twice on post 7 in one request: one generation, one write (the second render is the memo, not the transient).
    $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($h->stored)->toHaveCount(1);
    // Post 8, same attributes: its own key, its own generation.
    Functions\when('get_the_ID')->justReturn(8);
    $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($h->stored)->toHaveCount(2)
        ->and($h->stored[1][0])->not->toBe($h->stored[0][0])
        ->and($h->stored[1][0])->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => '', 'system' => '', 'temperature' => null], 8));
    // And a shortcode with no post at all (a widget) keys on 0 rather than sharing a page's entry.
    expect(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi'], 0))->not->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi'], 7))
        ->and(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi'], 7))->not->toBe(Chat::cacheKey('alpacabot_agent', ['prompt' => 'Say hi'], 7));
});

it('renders format="text" escaped, so a reply that spells markup shows it as text', function (): void {
    $h = pipelineWith(pipelineProvider(shortcodeReply("<b>bold</b> & \"quoted\"\nline two")));
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $html = $chat->render(['prompt' => 'Say hi', 'format' => 'text'], null, 'alpacabot');
    expect($html)->toContain('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;')->toContain('<br')->not->toContain('<b>');
});

it('shows an editor a notice and caches nothing when the turn fails', function (): void {
    $h = pipelineWith(pipelineProvider([new \RuntimeException('connection refused')]));
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $html = $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('could not answer')->toContain('connection refused')
        ->and($h->stored)->toBe([]);
});

it('renders the chat shell for an editor with no prompt, on their model, and enqueues the front-end bundle', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3, ['edit_posts', 'upload_files']);
    Functions\when('get_posts')->justReturn([]);
    Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'Carmelo', 'ID' => 3]);
    Functions\when('get_avatar_url')->justReturn('/u.png');
    Functions\when('plugins_url')->alias(static fn(string $p): string => '/plugins/alpaca-bot/' . $p);
    Functions\when('admin_url')->alias(static fn(string $p): string => '/wp-admin/' . $p);
    Functions\when('rest_url')->alias(static fn(string $p): string => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('wp_convert_hr_to_bytes')->justReturn(8 * 1024 * 1024);
    Functions\when('get_user_meta')->justReturn('llama3.2');
    Functions\when('selected')->alias(static fn(mixed $a, mixed $b, bool $echo = true): string => $a == $b ? ' selected' : '');
    Functions\when('number_format_i18n')->alias(static fn(mixed $n): string => (string) $n);
    Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-htmx', Mockery::type('string'), [], Assets::HTMX_VERSION, true);
    Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-chat', Mockery::type('string'), ['alpaca-bot-htmx', 'heartbeat'], Mockery::type('string'), true);
    Functions\expect('wp_enqueue_style')->once()->with('alpaca-bot', Mockery::type('string'), [], Mockery::type('string'));
    Functions\expect('wp_localize_script')->once()->with('alpaca-bot-chat', 'alpacaBot', Mockery::type('array'));
    Functions\expect('wp_enqueue_media')->once();
    $html = $chat->render('', null, 'alpacabot');
    expect($html)->toContain('id="ab-chat"')->toContain('data-conversation="0"')->toContain('name="model" value="llama3.2"')
        // The page the shortcode is on is not "the post being edited": no post context rides on the turn.
        ->toContain('name="context[post_id]" value="0"')
        ->and($h->writes)->toBe([]);
});

it('shows a guest the login notice instead of the shell, and enqueues nothing', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(0);
    Functions\expect('wp_enqueue_script')->never();
    Functions\expect('wp_enqueue_style')->never();
    Functions\expect('wp_enqueue_media')->never();
    // The shell is for logged-in editors only in 0.5; the guest filter is about cached answers and is not consulted.
    Filters\expectApplied('alpaca_bot/shortcode/allow_guests')->never();
    expect($chat->render('', null, 'alpacabot'))->toContain('class="alpaca-bot-notice"')->toContain('Log in')->not->toContain('id="ab-chat"');
    shortcodeViewer(5, ['read']);
    expect($chat->render('', null, 'alpacabot'))->toContain('edit posts')->not->toContain('id="ab-chat"');
});

it('registers itself as [alpacabot]', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h);
    Functions\expect('add_shortcode')->once()->with('alpacabot', [$chat, 'render']);
    $chat->register();
    expect(Chat::TAG)->toBe('alpacabot')->and(Chat::CAPABILITY)->toBe('edit_posts');
});
