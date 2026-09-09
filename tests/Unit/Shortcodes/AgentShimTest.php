<?php

declare(strict_types=1);

use AlpacaBot\Shortcodes\AgentShim;
use AlpacaBot\Shortcodes\Chat;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Toolkit\WebFetchToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Functions;

// The 0.4 `[alpacabot_agent]` shim over the same harness as ChatTest (shortcodeChat() and
// shortcodeViewer() in tests/Pest.php). The fetch is the real WebFetchToolkit with the HTTP API
// stubbed the way WebFetchToolkitTest stubs it: core's guard answers what it is told, the
// resolver says every host is public, and wp_safe_remote_get() serves `agentShimPage()`.

/** The page `wp_safe_remote_get()` serves for `$url`, and the guard passing it. */
function agentShimPage(string $url, string $body): void
{
    Functions\when('home_url')->justReturn('https://site.test/');
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r): string => $r['body']);
    Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r): int => $r['response']['code']);
    Functions\when('wp_remote_retrieve_header')->alias(static fn(array $r, string $h): string => $r['headers'][$h] ?? '');
    Functions\expect('wp_safe_remote_get')->once()->withArgs(static fn(string $u): bool => $u === $url)
        ->andReturn(['headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => $body, 'response' => ['code' => 200]]);
}

function agentShim(object $h): AgentShim
{
    // The registry the shim reads: the fetch toolkit under its id, gated by the harness's
    // `toolkits.enabled` (the schema default lists it; a test about the setting says otherwise).
    $registry = new Registry($h->store);
    $registry->register('web_fetch', new WebFetchToolkit($h->store, static fn(string $host): array => ['93.184.216.34']));
    return new AgentShim(shortcodeChat($h, 7), $h->pipeline, $registry);
}

it('maps name=summarize url=… to a prompt that begins with Summarize over the fetched page, through an ephemeral turn, and says it is deprecated once', function (): void {
    $provider = pipelineProvider([new Response('A **summary**.', ProviderFinishReason::Stop), new Response('', ProviderFinishReason::Stop, usage: new Usage(5, 2, 7))], $call);
    $h = pipelineWith($provider);
    $shim = agentShim($h);
    shortcodeViewer(3);
    agentShimPage('https://example.test/a', '<html><body><h1>Title</h1><p>Body text</p></body></html>');
    $message = null;
    Functions\expect('_doing_it_wrong')->once()->withArgs(static function (string $function, string $text, string $version) use (&$message): bool {
        $message = $text;
        return $function === 'alpacabot_agent' && $version === '0.5.0';
    });
    $html = $shim->render(['name' => 'summarize', 'url' => 'https://example.test/a', 'length' => '2 sentences'], null, 'alpacabot_agent');
    expect($html)->toContain('<strong>summary</strong>')->toContain('class="alpaca-bot-answer"')
        ->and($call['messages'][0])->toBeInstanceOf(SystemMessage::class)
        ->and($call['messages'][1])->toBeInstanceOf(UserMessage::class)
        ->and($call['messages'][1]->content())->toStartWith('Summarize')
        ->and($call['messages'][1]->content())->toContain('https://example.test/a')->toContain('2 sentences')->toContain("Title\n\nBody text")
        // The hint names the replacement, which begins the same way, and never names the reserved major version.
        ->and($message)->toContain('[alpacabot prompt="Summarize')->toContain('0.5.0')->not->toMatch('/(?<![\d.])1\.\d/');
    // Ephemeral: a receipt, no conversation; cached under the shim's own tag, the post, the
    // duration and the model the site resolved (no model= means the site's default).
    expect(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([['wp_insert_post', 'chat_log']])
        ->and($h->model)->toBe('llama3.2')
        ->and($h->stored)->toHaveCount(1)
        ->and($h->stored[0][0])->toBe(Chat::cacheKey('alpacabot_agent', ['name' => 'summarize', 'url' => 'https://example.test/a', 'length' => '2 sentences', 'model' => 'llama3.2'], 7, 3600))
        ->and($h->stored[0][1])->toBe('A **summary**.')
        ->and($h->stored[0][2])->toBe(3600);
});

it('maps name=get url=… to the fetched text, escaped, with no model turn, and warns once per request however many render', function (): void {
    $h = pipelineWith(null);
    $shim = agentShim($h);
    shortcodeViewer(3);
    agentShimPage('https://example.test/a', '<html><body><p>Fish &amp; chips</p><p>&lt;b&gt;not bold&lt;/b&gt;</p></body></html>');
    Functions\expect('_doing_it_wrong')->once();
    $html = $shim->render(['name' => 'get', 'url' => 'https://example.test/a'], null, 'alpacabot_agent');
    expect($html)->toContain('Fish &amp; chips')->toContain('&lt;b&gt;not bold&lt;/b&gt;')->not->toContain('<b>')
        ->and($h->stored)->toHaveCount(1)
        ->and($h->stored[0][1])->toBe("Fish & chips\n\n<b>not bold</b>")
        ->and($h->writes)->toBe([]);
    // The second render is the memo (one fetch, expect()ed once above) and no second warning.
    expect($shim->render(['name' => 'get', 'url' => 'https://example.test/a'], null, 'alpacabot_agent'))->toBe($html);
    // `name` defaults to get, as it did in 0.4.
    expect($shim->render(['url' => 'https://example.test/a'], null, 'alpacabot_agent'))->toBe($html);
});

it('refuses an agent it does not know and a call without a url, fetching nothing', function (): void {
    $h = pipelineWith(null);
    $shim = agentShim($h);
    shortcodeViewer(3);
    Functions\expect('_doing_it_wrong')->once();
    Functions\expect('wp_safe_remote_get')->never();
    Functions\expect('wp_http_validate_url')->never();
    expect($shim->render(['name' => 'translate', 'url' => 'https://example.test/a'], null, 'alpacabot_agent'))->toContain('class="alpaca-bot-notice"')->toContain('translate')
        ->and($shim->render(['name' => 'summarize'], null, 'alpacabot_agent'))->toContain('class="alpaca-bot-notice"')->toContain('url')
        ->and($h->stored)->toBe([]);
});

it('shows the fetch tool\'s refusal as a notice and caches nothing, so a private address is never summarized', function (): void {
    $h = pipelineWith(null);
    $shim = agentShim($h);
    shortcodeViewer(3);
    Functions\when('home_url')->justReturn('https://site.test/');
    Functions\when('_doing_it_wrong')->justReturn();
    // Core's guard refuses (false), as it does a loopback address; nothing is requested.
    Functions\when('wp_http_validate_url')->justReturn(false);
    Functions\expect('wp_safe_remote_get')->never();
    $html = $shim->render(['name' => 'summarize', 'url' => 'http://127.0.0.1/'], null, 'alpacabot_agent');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('not allowed')
        ->and($h->stored)->toBe([]);
});

it('does not fetch while the administrator has web_fetch switched off in Settings › Tools, and says so', function (): void {
    // Review M8: the setting's words are "what the assistant may do", and an unticked fetch
    // tool is a policy about outbound requests from this server; a shortcode is not exempt
    // from it. Nothing is requested, nothing is cached, and the notice names the setting.
    $h = pipelineWith(null, ['toolkits.enabled' => ['summarize']]);
    $shim = agentShim($h);
    shortcodeViewer(3);
    Functions\when('home_url')->justReturn('https://site.test/');
    Functions\when('_doing_it_wrong')->justReturn();
    Functions\expect('wp_safe_remote_get')->never();
    Functions\expect('wp_http_validate_url')->never();
    $html = $shim->render(['name' => 'get', 'url' => 'https://example.test/a'], null, 'alpacabot_agent');
    expect($html)->toContain('class="alpaca-bot-notice"')->toContain('switched off')
        ->and($h->stored)->toBe([]);
});

it('never fetches or generates for a guest', function (): void {
    $h = pipelineWith(null);
    $shim = agentShim($h);
    shortcodeViewer(0);
    Functions\when('_doing_it_wrong')->justReturn();
    Functions\expect('wp_safe_remote_get')->never();
    Functions\expect('wp_http_validate_url')->never();
    expect($shim->render(['name' => 'get', 'url' => 'https://example.test/a'], null, 'alpacabot_agent'))->toContain('Log in');
});

it('registers itself as [alpacabot_agent]', function (): void {
    $h = pipelineWith(null);
    $shim = agentShim($h);
    Functions\expect('add_shortcode')->once()->with('alpacabot_agent', [$shim, 'render']);
    $shim->register();
    expect(AgentShim::TAG)->toBe('alpacabot_agent');
});
