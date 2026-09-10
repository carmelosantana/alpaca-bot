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

/** The identity of `[alpacabot prompt="Say hi"]` with nothing else set: no model named (the pipeline's choice is not recorded), and the harness's site has no system prompt. */
function sayHiKey(int $postId = 7, int $cacheSeconds = 3600): string
{
    return Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => '', 'system' => '', 'temperature' => null], $postId, $cacheSeconds);
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
        ->and(Chat::attributes(['temperature' => '-1'])['temperature'])->toBe(0.0)
        ->and(Chat::attributes(['temperature' => '1e999'])['temperature'])->toBe(2.0);
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

it('holds a cache duration to a year, so an attribute that overflows an int cannot fatal the page', function (): void {
    // Review C1: `(int) $m[1] * 86400` overflowed to a float and, under strict_types, the int
    // return type threw a TypeError out of attribute parsing, outside answer()'s try and before
    // the capability check: a published page white-screened for every visitor, from an
    // attribute any Contributor can write. The reviewer's exact value first; a year is the ceiling.
    Functions\when('shortcode_atts')->alias(static fn(array $pairs, array $atts): array => array_merge($pairs, array_intersect_key($atts, $pairs)));
    expect(Chat::cacheSeconds('999999999999999d'))->toBe(31536000)
        ->and(Chat::cacheSeconds('99999999999999999999'))->toBe(31536000)
        ->and(Chat::cacheSeconds('9223372036854775807h'))->toBe(31536000)
        ->and(Chat::cacheSeconds('366d'))->toBe(31536000)
        ->and(Chat::cacheSeconds('365d'))->toBe(31536000)
        ->and(Chat::cacheSeconds('364d'))->toBe(364 * 86400)
        ->and(Chat::MAX_SECONDS)->toBe(31536000)
        ->and(Chat::attributes(['prompt' => 'x', 'cache' => '999999999999999d'])['cache'])->toBe(31536000);
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
    // Cached for the default hour under the attributes, the post and the duration.
    expect($h->stored)->toHaveCount(1)
        ->and($h->stored[0][0])->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => 'llama3.2', 'system' => 'Be terse', 'temperature' => 0.2], 7, 3600))
        ->and($h->stored[0][0])->toStartWith('alpaca_bot_shortcode_')
        ->and($h->stored[0][1])->toBe("**Hi** there\n\n<script>x()</script>")
        ->and($h->stored[0][2])->toBe(3600);
});

it('keys the cache on the system prompt the site resolves and on the model the author named where the site honours it, and otherwise lets the pipeline pick the model as it would for a chat turn', function (): void {
    // Review M6: with no system= the pipeline fell back to chat.system_prompt, and it was not
    // in the identity, so editing the site prompt invalidated nothing. The site's prompt is in
    // the key. The model is the pipeline's to choose when the author names none (the viewer's
    // preference where users may change it, else the site's default through the catalog), and
    // the key does not record it: the shortcode sends the pipeline no model it did not get from
    // the author, and records none it did not send (re-review N1/N2 are what recording the
    // stored default cost).
    $provider = pipelineProvider(shortcodeReply('Answer'), $call);
    // The pipeline is given the per-user preferences here, as Plugin builds it (the harness's
    // default is the CLI's, none), so the viewer's own model is a real premise, not a stub nothing reads.
    $h = pipelineWith($provider, ['chat.system_prompt' => 'Site prompt', 'models.default' => 'llama3.2'], catalog: ['llama3.2', 'mistral'], prefs: new AlpacaBot\Chat\UserPrefs());
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    // The viewer prefers another listed model, and users may change it: the turn runs on it, as their chat would.
    Functions\when('get_user_meta')->justReturn('mistral');
    $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($h->model)->toBe('mistral')
        ->and($call['messages'][0]->content())->toBe('Site prompt')
        ->and($h->stored[0][0])->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => '', 'system' => 'Site prompt', 'temperature' => null], 7, 3600))
        ->and($h->stored[0][0])->not->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => 'mistral', 'system' => 'Site prompt', 'temperature' => null], 7, 3600))
        ->and($h->stored[0][0])->not->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => '', 'system' => 'Another prompt', 'temperature' => null], 7, 3600));
    // Where users may not change the model, a model= attribute is not honoured: the pipeline
    // resolves it as it would with none, and the identity says none was honoured, not what was asked.
    $h = pipelineWith(pipelineProvider(shortcodeReply('Answer')), ['chat.user_can_change_model' => false], catalog: ['llama3.2', 'mistral']);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $chat->render(['prompt' => 'Say hi', 'model' => 'mistral'], null, 'alpacabot');
    expect($h->model)->toBe('llama3.2')
        ->and($h->stored[0][0])->toBe(sayHiKey());
});

it('answers on the catalog\'s fallback when the stored default model is one the provider no longer lists, as every other surface does, and records no model the author did not name', function (): void {
    // Re-review N1: fix round 1 sent the stored `models.default` to the pipeline as an explicit
    // model, and Pipeline::model() refuses an explicit model the catalog does not list, so a
    // default the provider had since dropped turned every shortcode on the site into "not
    // available" while the chat screen, REST and the CLI kept answering through
    // ModelCatalog::defaultId()'s grace. With no model= the pipeline resolves the model as it
    // does for a chat turn, and the identity says the author named none.
    $h = pipelineWith(pipelineProvider(shortcodeReply('Answer')), ['models.default' => 'gone-model'], catalog: ['llama3.2', 'mistral']);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $html = $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($html)->not->toContain('not available')->toContain('class="alpaca-bot-answer"')
        ->and($h->model)->toBe('llama3.2')
        ->and($h->stored[0][0])->toBe(sayHiKey());
    // Re-review N2, the mirror: with users unable to change the model the pipeline ignored the
    // option and ran on the catalog's default, while the key named the stored setting, a model
    // the turn did not run on. The key never names a model the author did not write.
    $h = pipelineWith(pipelineProvider(shortcodeReply('Answer')), ['models.default' => 'gone-model', 'chat.user_can_change_model' => false], catalog: ['llama3.2', 'mistral']);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($h->model)->toBe('llama3.2')
        ->and($h->stored[0][0])->toBe(sayHiKey())
        ->and($h->stored[0][0])->not->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi', 'model' => 'gone-model', 'system' => '', 'temperature' => null], 7, 3600));
});

it('serves a cached answer to an editor without building a provider', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $h->transients[sayHiKey()] = 'From the cache';
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
    $h->transients[sayHiKey()] = 'From the cache';
    $html = $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($html)->toContain('From the cache')->not->toContain('alpaca-bot-notice')
        ->and($h->writes)->toBe([]);
});

it('keeps a cached answer from a guest, and from a logged-in user who cannot edit posts, while the filter is off', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    $h->transients[sayHiKey()] = 'From the cache';
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

it('never generates inside a REST request: an editor gets the cached answer, or a notice, and only a page view generates', function (): void {
    // Review I3: content.rendered is produced per item, so GET /wp/v2/posts?per_page=100 as an
    // editor was up to 100 serialized turns in one request. The rule that governs guests
    // governs REST: serve the cache or the notice; generation is a page render's.
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    Functions\when('wp_is_rest_endpoint')->justReturn(true);
    $html = $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('viewed')->not->toContain('alpaca-bot-answer')
        ->and($h->stored)->toBe([])
        ->and($h->writes)->toBe([]);
    $h->transients[sayHiKey()] = 'From the cache';
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('From the cache')->not->toContain('alpaca-bot-notice');
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

it('keys a cache="off" shortcode apart from its cached twin, so neither one\'s memo stands in for the other', function (): void {
    // Review M7: with one key for both, whichever rendered second was served from the memo; when
    // the `off` one came first, the caching twin never wrote its transient and the page
    // generated on every request for good. The duration is part of the identity.
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('stream')->twice()->andReturnUsing(static function (): \Generator {
        yield from shortcodeReply('Fresh');
    });
    $h = pipelineWith($provider, turns: 2);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $chat->render(['prompt' => 'Say hi', 'cache' => 'off'], null, 'alpacabot');
    $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($h->stored)->toHaveCount(1)
        ->and($h->stored[0][2])->toBe(3600)
        ->and($h->stored[0][0])->toBe(sayHiKey(7, 3600))
        ->and(sayHiKey(7, 0))->not->toBe(sayHiKey(7, 3600));
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
        ->and($h->stored[1][0])->toBe(sayHiKey(8));
    // And a shortcode with no post at all (a widget) keys on 0 rather than sharing a page's entry.
    expect(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi'], 0, 3600))->not->toBe(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi'], 7, 3600))
        ->and(Chat::cacheKey('alpacabot', ['prompt' => 'Say hi'], 7, 3600))->not->toBe(Chat::cacheKey('alpacabot_agent', ['prompt' => 'Say hi'], 7, 3600));
});

it('renders format="text" escaped, so a reply that spells markup shows it as text', function (): void {
    $h = pipelineWith(pipelineProvider(shortcodeReply("<b>bold</b> & \"quoted\"\nline two")));
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $html = $chat->render(['prompt' => 'Say hi', 'format' => 'text'], null, 'alpacabot');
    expect($html)->toContain('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;')->toContain('<br')->not->toContain('<b>');
});

it('shows an editor the fixed provider message when the turn fails, never the provider\'s own text, and caches nothing', function (): void {
    // Review I1, and Rest\Errors::provider()'s policy in one place: what the provider threw
    // quotes its endpoint, and anyone who may view the page can make it throw by viewing while
    // the provider is down, so the raw text is the debug log's and the page gets the fixed message.
    $h = pipelineWith(pipelineProvider([new \RuntimeException('Failed to connect to localhost port 11434 after 1 ms: Couldn\'t connect to server for "http://localhost:11434/v1/chat/completions".')]));
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    $html = $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('could not answer')->toContain('The model provider could not complete the request.')
        ->not->toContain('11434')->not->toContain('localhost')->not->toContain('Provider error')
        ->and($h->stored)->toBe([]);
});

it('shows an editor the cap and a refused model in their own words, the other two arms of the REST routes\' policy', function (): void {
    // Errors::fromPipeline(): CapExceeded's message and an InvalidArgumentException's are written for the person who asked.
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 12, 'requests' => 1];
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('could not answer')->toContain('Your monthly token cap has been reached (12 of 10 tokens).')
        ->and($h->stored)->toBe([]);
    // A model the catalog does not list is refused by the pipeline before any provider is built.
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    expect($chat->render(['prompt' => 'Say hi', 'model' => 'nope'], null, 'alpacabot'))->toContain('Model &quot;nope&quot; is not available.')
        ->and($h->stored)->toBe([]);
});

// ---------------------------------------------------------------- the rate limit
// Final review F2: every other surface that spends (POST /chat, the stream route, the chat
// and summarize abilities) counts a hit on Rest\RateLimit's `chat` bucket; the shortcodes had
// the cache and the monthly caps, both of which default to unlimited, and `cache="off"` is an
// attribute any Contributor can write. Fifty distinct prompts on one page were fifty turns
// per editor view. The same limiter, the same bucket, the same filter: a site that moves the
// limit moves it everywhere, and one person on five surfaces is one person.

it('holds a generation to the limiter the REST routes and the abilities share, in the chat bucket: at the limit the page shows a notice, caches nothing, and the hit is counted', function (): void {
    $h = pipelineWith(pipelineProvider(shortcodeReply('Answer')));
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    // The key RateLimit writes for the REST route and the abilities: twenty-nine hits already
    // this minute, from whichever surface. The thirtieth generates.
    $key = 'alpaca_bot_rl_chat_3_' . gmdate('YmdHi', 1_725_000_000);
    $h->transients[$key] = 29;
    Filters\expectApplied('alpaca_bot/rate_limit')->times(2)->with(30, 3, 'chat')->andReturnFirstArg();
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('class="alpaca-bot-answer"')
        ->and($h->transients[$key])->toBe(30)
        ->and($h->limited)->toBe([[$key, 30]])
        ->and($h->stored)->toHaveCount(1);
    // The thirty-first, a distinct prompt the memo does not cover: the notice, in the REST
    // routes' words, distinct from the cap's and the provider's; nothing cached, no turn run,
    // and counted even when refused, as the REST route counts it.
    $html = $chat->render(['prompt' => 'Say more', 'cache' => 'off'], null, 'alpacabot');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('could not answer')->toContain('Too many requests')->not->toContain('alpaca-bot-answer')
        ->and($h->transients[$key])->toBe(31)
        ->and($h->stored)->toHaveCount(1)
        ->and(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([['wp_insert_post', 'chat_log']]);
    // The first shortcode again in the same request is the memo: no hit.
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('class="alpaca-bot-answer"')
        ->and($h->transients[$key])->toBe(31);
});

it('the alpaca_bot/rate_limit filter moves the shortcodes\' limit as it moves the REST routes\': at one a minute the second prompt on a page is refused', function (): void {
    $h = pipelineWith(pipelineProvider(shortcodeReply('Answer')));
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);
    Filters\expectApplied('alpaca_bot/rate_limit')->times(2)->with(30, 3, 'chat')->andReturn(1);
    expect($chat->render(['prompt' => 'one'], null, 'alpacabot'))->toContain('class="alpaca-bot-answer"')
        ->and($chat->render(['prompt' => 'two'], null, 'alpacabot'))->toContain('Too many requests')
        ->and($h->limited)->toHaveCount(2)
        ->and($h->stored)->toHaveCount(1);
});

it('counts no hit for a cached answer, a guest, or a REST request: only a generation is a hit', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    Filters\expectApplied('alpaca_bot/rate_limit')->never();
    $h->transients[sayHiKey()] = 'From the cache';
    shortcodeViewer(3);
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('From the cache');
    Functions\when('wp_is_rest_endpoint')->justReturn(true);
    expect($chat->render(['prompt' => 'Say more'], null, 'alpacabot'))->toContain('viewed');
    Functions\when('wp_is_rest_endpoint')->justReturn(false);
    shortcodeViewer(0);
    Filters\expectApplied('alpaca_bot/shortcode/allow_guests')->once()->andReturn(true);
    expect($chat->render(['prompt' => 'Say more', 'cache' => 'off'], null, 'alpacabot'))->toContain('Log in')
        ->and($h->limited)->toBe([]);
});

it('enqueues the shortcode stylesheet for an answer and for a notice, and never the chat bundle', function (): void {
    // Review M9: .alpaca-bot-answer and .alpaca-bot-notice had no rule anywhere, and the
    // prompt form enqueued nothing, so they were unstyled for good. The prompt form's own
    // stylesheet is a few rules; the bundle stays the shell's.
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    Functions\expect('wp_enqueue_script')->never();
    Functions\expect('wp_enqueue_media')->never();
    $h->transients[sayHiKey()] = 'From the cache';
    shortcodeViewer(3);
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('From the cache')
        ->and($h->styles)->toBe([['alpaca-bot-shortcode', '/plugins/alpaca-bot/assets/css/alpaca-bot-shortcode.css']]);
    shortcodeViewer(0);
    Filters\expectApplied('alpaca_bot/shortcode/allow_guests')->once()->andReturn(false);
    expect($chat->render(['prompt' => 'Say hi'], null, 'alpacabot'))->toContain('Log in')
        ->and($h->styles)->toHaveCount(2)
        ->and($h->styles[1][0])->toBe('alpaca-bot-shortcode');
});

it('renders the chat shell for an editor with no prompt, on their model, and enqueues the front-end bundle', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3, ['edit_posts', 'upload_files']);
    Functions\when('get_posts')->justReturn([]);
    Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'Carmelo', 'ID' => 3]);
    Functions\when('get_avatar_url')->justReturn('/u.png');
    Functions\when('admin_url')->alias(static fn(string $p): string => '/wp-admin/' . $p);
    Functions\when('rest_url')->alias(static fn(string $p): string => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('wp_convert_hr_to_bytes')->justReturn(8 * 1024 * 1024);
    Functions\when('get_user_meta')->justReturn('llama3.2');
    Functions\when('selected')->alias(static fn(mixed $a, mixed $b, bool $echo = true): string => $a == $b ? ' selected' : '');
    Functions\when('number_format_i18n')->alias(static fn(mixed $n): string => (string) $n);
    Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-htmx', Mockery::type('string'), [], Assets::HTMX_VERSION, true);
    Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-chat', Mockery::type('string'), ['alpaca-bot-htmx', 'heartbeat'], Mockery::type('string'), true);
    Functions\expect('wp_localize_script')->once()->with('alpaca-bot-chat', 'alpacaBot', Mockery::type('array'));
    Functions\expect('wp_enqueue_media')->once();
    $html = $chat->render('', null, 'alpacabot');
    expect($html)->toContain('id="ab-chat"')->toContain('data-conversation="0"')->toContain('name="model" value="llama3.2"')
        // The page the shortcode is on is not "the post being edited": no post context rides on the turn.
        ->toContain('name="context[post_id]" value="0"')
        // The chat screen's stylesheet; the shell's markup does not use the shortcode's two classes.
        ->and(array_column($h->styles, 0))->toBe(['alpaca-bot'])
        ->and($h->writes)->toBe([]);
});

it('never renders the shell inside a REST request: an editor gets a notice, no history query, no nonce, no bundle', function (): void {
    // Final review F8: render() branched to shell() before answer()'s wp_is_rest_endpoint()
    // guard, so `GET /wp/v2/posts?per_page=100` as an editor was up to a hundred shells in
    // content.rendered, each a listFor() query and a full markup build carrying a live
    // `wp_rest` nonce. The rule that governs the prompt form governs the shell.
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3, ['edit_posts', 'upload_files']);
    Functions\when('wp_is_rest_endpoint')->justReturn(true);
    Functions\expect('get_posts')->never();
    Functions\expect('wp_create_nonce')->never();
    Functions\expect('wp_enqueue_script')->never();
    Functions\expect('wp_enqueue_media')->never();
    $html = $chat->render('', null, 'alpacabot');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('viewed')->not->toContain('id="ab-chat"')->not->toContain('hx-headers')
        ->and(array_column($h->styles, 0))->toBe(['alpaca-bot-shortcode'])
        ->and($h->writes)->toBe([]);
});

it('shows a guest the login notice instead of the shell, and enqueues only the shortcode stylesheet', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(0);
    Functions\expect('wp_enqueue_script')->never();
    Functions\expect('wp_enqueue_media')->never();
    // The shell is for logged-in editors only in 0.5; the guest filter is about cached answers and is not consulted.
    Filters\expectApplied('alpaca_bot/shortcode/allow_guests')->never();
    expect($chat->render('', null, 'alpacabot'))->toContain('class="alpaca-bot-notice"')->toContain('Log in')->not->toContain('id="ab-chat"');
    shortcodeViewer(5, ['read']);
    expect($chat->render('', null, 'alpacabot'))->toContain('edit posts')->not->toContain('id="ab-chat"')
        ->and(array_column($h->styles, 0))->toBe(['alpaca-bot-shortcode', 'alpaca-bot-shortcode']);
});

it('registers itself as [alpacabot]', function (): void {
    $h = pipelineWith(null);
    $chat = shortcodeChat($h);
    Functions\expect('add_shortcode')->once()->with('alpacabot', [$chat, 'render']);
    $chat->register();
    expect(Chat::TAG)->toBe('alpacabot')->and(Chat::CAPABILITY)->toBe('edit_posts');
});

// Pipeline's class docblock: an ephemeral turn runs no tools, and for the shortcode that is a
// decision, not a side effect of the flag. The prompt is written by anyone who can write the
// post and the turn runs as whichever editor views the page, so a tool turn here would let that
// prompt reach web_fetch or draft_post once per cache miss, unread. The model is catalogued as
// tool-capable and a toolkit is enabled, which is everything a chat turn needs to get tools.
it('offers the model no toolkit on a shortcode turn, even with a tool-capable model and a toolkit switched on', function (): void {
    $provider = pipelineProvider(shortcodeReply('Answer'), $call);
    $h = pipelineWith($provider, [], [], [['id' => 'llama3.2', 'tools' => true]], null, registryWith(['echo' => echoToolkit('echo_tool')]));
    $chat = shortcodeChat($h, 7);
    shortcodeViewer(3);

    $html = $chat->render(['prompt' => 'Say hi'], null, 'alpacabot');

    expect($html)->toContain('Answer')->and($call['tools'])->toBe([]);
});
