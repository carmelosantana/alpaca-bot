<?php

declare(strict_types=1);

use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\WebFetchToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use Brain\Monkey\Functions;

// The HTTP API is stubbed: wp_http_validate_url() decides what may be fetched, wp_safe_remote_get()
// does the fetch. What core makes of a loopback address or a private range is pinned in
// tests/Integration/ToolkitsTest.php against real core; here the guard's answer is given and
// what the tool does with it is what is under test.

/** The response `wp_safe_remote_get()` answers, in the array shape the HTTP API uses, and the retrieve_* helpers reading it. */
function webFetchResponse(string $body, int $code = 200, string $contentType = 'text/html; charset=utf-8'): array
{
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r): string => $r['body']);
    Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r): int => $r['response']['code']);
    Functions\when('wp_remote_retrieve_header')->alias(static fn(array $r, string $h): string => $r['headers'][$h] ?? '');
    return ['headers' => ['content-type' => $contentType], 'body' => $body, 'response' => ['code' => $code]];
}

function webFetchTool(string $userAgent = 'UA/1'): Tool
{
    $tool = (new WebFetchToolkit(new Store(['toolkits.user_agent' => $userAgent])))->tools()[0];
    expect($tool)->toBeInstanceOf(Tool::class);
    return $tool;
}

beforeEach(function (): void {
    Functions\when('wp_strip_all_tags')->alias(static fn(string $s): string => strip_tags($s));
});

it('fetches a URL the guard passes, with the configured user agent under the timeout and size caps, and returns the readable text', function (): void {
    Functions\expect('wp_http_validate_url')->once()->with('https://example.test/a')->andReturn('https://example.test/a');
    Functions\expect('wp_safe_remote_get')->once()->withArgs(static fn(string $url, array $args): bool => $url === 'https://example.test/a'
        && $args['user-agent'] === 'UA/1'
        && $args['timeout'] === 5
        && $args['limit_response_size'] === 1048576
        && $args['reject_unsafe_urls'] === true
        && $args['redirection'] === 3)->andReturn(webFetchResponse('<html><head><title>T</title><style>b{}</style></head><body><h1>Hi</h1><script>x()</script><p>Fish &amp; chips</p><noscript>no</noscript><ul><li>one</li><li>two</li></ul></body></html>'));
    $tool = webFetchTool();
    expect($tool->name())->toBe('web_fetch')
        ->and($tool->toFunctionSchema()['function']['parameters']['required'])->toBe(['url']);
    $res = $tool->execute(['url' => 'https://example.test/a']);
    expect($res->status)->toBe(ToolResultStatus::Success)
        ->and($res->content)->toBe("T\n\nHi\n\nFish & chips\n\none\n\ntwo");
});

it('refuses a URL the guard rejects before any request is made, and says why', function (): void {
    Functions\expect('wp_http_validate_url')->once()->with('http://127.0.0.1/')->andReturn(false);
    Functions\expect('wp_safe_remote_get')->never();
    $res = webFetchTool()->execute(['url' => 'http://127.0.0.1/']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('not allowed');
});

it('falls back to the schema user agent when the setting is blank, so the request never goes out without one', function (): void {
    Functions\expect('wp_http_validate_url')->once()->andReturnFirstArg();
    Functions\expect('wp_safe_remote_get')->once()->withArgs(static fn(string $url, array $args): bool => $args['user-agent'] === 'AlpacaBot/0.5 (+https://github.com/carmelosantana/alpaca-bot)')->andReturn(webFetchResponse('<p>ok</p>'));
    expect(webFetchTool('  ')->execute(['url' => 'https://example.test/'])->content)->toBe('ok');
});

it('reports an HTTP failure and a transport failure as errors that name the cause', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('<h1>Not here</h1>', 404));
    $res = webFetchTool()->execute(['url' => 'https://example.test/missing']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('404')->toContain('https://example.test/missing')->not->toContain('Not here');

    Functions\expect('wp_safe_remote_get')->once()->andReturn(new WP_Error('http_request_failed', 'cURL error 28: timed out'));
    Functions\when('is_wp_error')->alias(static fn(mixed $v): bool => $v instanceof WP_Error);
    $res = webFetchTool()->execute(['url' => 'https://example.test/slow']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('timed out');
});

it('refuses a response that is not text rather than handing binary to the model', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('%PDF-1.7 binary', 200, 'application/pdf'));
    $res = webFetchTool()->execute(['url' => 'https://example.test/file.pdf']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('application/pdf')->not->toContain('%PDF');
    // A text type with parameters, JSON, and a response with no header at all all go through.
    foreach (['text/plain; charset=utf-8', 'application/json', ''] as $type) {
        Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('{"a":1}', 200, $type));
        expect(webFetchTool()->execute(['url' => 'https://example.test/x'])->content)->toBe('{"a":1}', $type);
    }
});

it('truncates the text to 8000 characters with a marker, counting characters rather than bytes', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse(str_repeat('é', 9000)));
    $content = webFetchTool()->execute(['url' => 'https://example.test/long'])->content;
    expect(mb_strlen($content))->toBe(8001)
        ->and(mb_substr($content, -1))->toBe('…')
        ->and(mb_substr($content, 0, 8000))->toBe(str_repeat('é', 8000));
});

it('is refused by the tool itself without a url, or with one that is not a string, before the guard is asked', function (): void {
    Functions\expect('wp_http_validate_url')->never();
    Functions\expect('wp_safe_remote_get')->never();
    expect(webFetchTool()->execute([])->status)->toBe(ToolResultStatus::Error)
        ->and(webFetchTool()->execute(['url' => ['https://example.test/']])->status)->toBe(ToolResultStatus::Error);
});

it('carries guidelines for the system prompt', function (): void {
    $kit = new WebFetchToolkit(new Store([]));
    expect($kit->tools())->toHaveCount(1)
        ->and($kit->guidelines())->toContain('web_fetch');
});
