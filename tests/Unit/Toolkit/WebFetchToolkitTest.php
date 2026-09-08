<?php

declare(strict_types=1);

use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\WebFetchToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// The HTTP API is stubbed: wp_http_validate_url() decides what may be fetched, wp_safe_remote_get()
// does the fetch. What core makes of a loopback address or a private range is pinned in
// tests/Integration/ToolkitsTest.php against real core; here the guard's answer is given and
// what the tool does with it is what is under test. The plugin's own address check runs after
// core's, over the addresses a resolver answers: the helper hands in a resolver that says
// "public" so the tests about everything else never touch DNS, and the tests about the check
// hand in their own.

/** The response `wp_safe_remote_get()` answers, in the array shape the HTTP API uses, and the retrieve_* helpers reading it. */
function webFetchResponse(string $body, int $code = 200, string $contentType = 'text/html; charset=utf-8'): array
{
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r): string => $r['body']);
    Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r): int => $r['response']['code']);
    Functions\when('wp_remote_retrieve_header')->alias(static fn(array $r, string $h): string => $r['headers'][$h] ?? '');
    return ['headers' => ['content-type' => $contentType], 'body' => $body, 'response' => ['code' => $code]];
}

function webFetchTool(string $userAgent = 'UA/1', ?\Closure $resolver = null): Tool
{
    $tool = (new WebFetchToolkit(new Store(['toolkits.user_agent' => $userAgent]), $resolver ?? static fn(string $host): array => ['93.184.216.34']))->tools()[0];
    expect($tool)->toBeInstanceOf(Tool::class);
    return $tool;
}

beforeEach(function (): void {
    Functions\when('home_url')->justReturn('https://site.test/');
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

// The guard's return value is what is fetched, not what was passed in: core returns the
// wp_kses_bad_protocol() form of the URL, which can differ from the input, so a stub that
// answered the same string could not tell `wp_safe_remote_get($safe)` from `($url)`.
it('fetches the URL the guard returns, not the one it was given', function (): void {
    Functions\expect('wp_http_validate_url')->once()->with('https://example.test/a')->andReturn('https://example.test/normalised');
    Functions\expect('wp_safe_remote_get')->once()->withArgs(static fn(string $url): bool => $url === 'https://example.test/normalised')->andReturn(webFetchResponse('<p>ok</p>'));
    expect(webFetchTool()->execute(['url' => ' https://example.test/a '])->content)->toBe('ok');
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

// ---------------------------------------------------------------- charset, and a regex that fails
// The content-type header's charset parameter decides how the bytes are read, and a page that
// declares none is windows-1252 unless its bytes are UTF-8, which is what a browser does. A
// page in another charset once had its non-UTF-8 lines silently dropped: the `/u` regex over
// them returned null, `(string) null` is '', and the tool answered Success on what was left.

it('reads a page in the charset its Content-Type declares, so a byte outside UTF-8 is a character rather than a lost line', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse("<p>Price is 50 \xA3 today</p><p>Second line ok</p>", 200, 'text/html; charset=windows-1252'));
    $res = webFetchTool()->execute(['url' => 'https://example.test/cp1252']);
    expect($res->status)->toBe(ToolResultStatus::Success)
        ->and($res->content)->toBe("Price is 50 £ today\n\nSecond line ok");
});

it('reads the charset from a meta tag when the header names none, and falls back to windows-1252 for bytes that are not UTF-8 when nothing names one', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse("<html><head><title>T</title><meta charset=\"ISO-8859-1\"></head><body><p>caf\xE9</p></body></html>", 200, 'text/html'));
    expect(webFetchTool()->execute(['url' => 'https://example.test/meta'])->content)->toBe("T\n\ncafé");
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse("<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=windows-1252\"></head><body><p>caf\xE9</p></body></html>", 200, 'text/html'));
    expect(webFetchTool()->execute(['url' => 'https://example.test/http-equiv'])->content)->toBe('café');
    // The reviewer's reproduction: no charset anywhere, cp1252 bytes.
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse("<p>Price is 50 \xA3 today</p><p>Second line ok</p>", 200, 'text/html'));
    expect(webFetchTool()->execute(['url' => 'https://example.test/undeclared'])->content)->toBe("Price is 50 £ today\n\nSecond line ok");
    // UTF-8 bytes with nothing declared are UTF-8.
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('<p>café</p>', 200, ''));
    expect(webFetchTool()->execute(['url' => 'https://example.test/utf8'])->content)->toBe('café');
});

it('keeps a line whose bytes contradict a declared UTF-8, with the bad byte replaced, rather than dropping the line', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse("<p>Price is 50 \xA3 today</p><p>Second line ok</p>", 200, 'text/html; charset=utf-8'));
    $res = webFetchTool()->execute(['url' => 'https://example.test/lying']);
    expect($res->status)->toBe(ToolResultStatus::Success)
        ->and($res->content)->toMatch("/^Price is 50 \\S today\n\nSecond line ok$/u")
        ->and(mb_check_encoding($res->content, 'UTF-8'))->toBeTrue();
});

it('refuses a charset this PHP cannot convert, naming it, rather than guessing', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('<p>x</p>', 200, 'text/html; charset="x-no-such-charset"'));
    $res = webFetchTool()->execute(['url' => 'https://example.test/odd']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('x-no-such-charset');
});

// Entities are decoded after the tags go, not before: a page that shows HTML as text (a code
// sample on a docs page is `&lt;div&gt;` in the source) would otherwise have that text stripped
// as markup. What the tool returns is text, never markup, whatever it spells; whoever renders it
// escapes it as text, as with any tool result.
it('keeps a page\'s escaped markup as the literal text a reader saw, so a code sample survives', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('<p>Write <code>&lt;div class=&quot;x&quot;&gt;</code> here</p>'));
    expect(webFetchTool()->execute(['url' => 'https://example.test/docs'])->content)->toBe('Write <div class="x"> here');
});

// The 1 MiB cap is the input bound, so a page has to be reduced in time linear in its size.
// The shipped pattern's lazy `.*?</script>` scanned to the end of the page once per unclosed
// `<script>`: 0.6 s at 108 KB, 3 s at 216 KB, 13 s at 432 KB, 51 s at 864 KB on the reviewer's
// input, and a model-chosen URL is enough to run it. At the cap itself it tripped
// pcre.backtrack_limit instead, in 0.00 s, and the `(string)` cast over the null answered
// Success on an emptied page, so the sizes below the cap are the ones that catch the grind and
// the text ahead of the script is what catches the emptying. Two seconds is two orders over
// what a linear pass takes here and one under the quadratic one at the smallest size that fails.
it('reduces a page of unclosed script openers at every size up to the cap inside a bound only linear work meets, keeping the text ahead of them', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    $tool = webFetchTool();
    $runs = [];
    foreach ([12_288, 24_576, 49_152, 98_304, intdiv(WebFetchToolkit::MAX_BYTES - 14, 9)] as $n) {
        $body = '<p>Visible</p>' . str_repeat('<script>x', $n);
        Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse($body));
        $start = hrtime(true);
        $res = $tool->execute(['url' => 'https://example.test/pathological']);
        $runs[intdiv(strlen($body), 1024) . ' KB'] = [(hrtime(true) - $start) / 1e9, $res];
    }
    foreach ($runs as $size => [$seconds]) {
        expect($seconds)->toBeLessThan(2.0, sprintf('%s took %.2f s', $size, $seconds));
    }
    foreach ($runs as $size => [, $res]) {
        expect($res->status)->toBe(ToolResultStatus::Success, $size)
            // An unclosed <script> is script to the end of the page, as a browser reads it.
            ->and($res->content)->toBe('Visible', $size);
    }
});

it('reports a page PCRE gave up on as an error rather than as the part it had reduced', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('<p>Hello <b>there</b></p><script>x</script><p>after</p>'));
    // PCRE's match limit is counted by the interpreter, not the JIT; both are runtime settings.
    $jit = (string) ini_get('pcre.jit');
    $limit = (string) ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');
    try {
        $res = webFetchTool()->execute(['url' => 'https://example.test/limit']);
    } finally {
        ini_set('pcre.jit', $jit);
        ini_set('pcre.backtrack_limit', $limit);
    }
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('Backtrack limit')->not->toContain('Hello');
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

// ---------------------------------------------------------------- the plugin's own address check
// wp_http_validate_url() accepts everything in these tests, which is what core below 7.1 does
// for link-local (the cloud metadata address), CGNAT, multicast and every IPv6 address; the
// refusal has to be the plugin's. SpecialPurposeAddressTest walks the whole table; these pin
// that the tool asks it: for a literal, for every address a name resolves to, and for a
// redirect target, with the site's own host exempt as it is in core.

it('refuses a special-purpose address itself when core accepts it: a literal, a name that resolves to one among public ones, and a name that does not resolve', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Functions\expect('wp_safe_remote_get')->never();
    $answers = [
        'mixed.test' => ['93.184.216.34', '10.0.0.7'],
        'v6.test' => ['93.184.216.34', '::1'],
        'nowhere.test' => [],
    ];
    $tool = webFetchTool(resolver: static fn(string $host): array => $answers[$host] ?? ['1.2.3.4']);
    foreach ([
        'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        'http://[::ffff:169.254.169.254]/latest/meta-data/',
        'http://[fe80::1]/', 'http://[::1]/', 'http://[fd00::1]/', 'http://[ff02::1]/',
        'http://100.64.0.1/', 'http://0.0.0.0/', 'http://224.0.0.1/', 'http://255.255.255.255/',
        'http://127.0.0.1/', 'http://10.0.0.1/', 'http://172.16.0.1/', 'http://192.168.1.1/',
        'http://mixed.test/', 'http://v6.test/', 'http://nowhere.test/',
    ] as $url) {
        $res = $tool->execute(['url' => $url]);
        expect($res->status)->toBe(ToolResultStatus::Error, $url)
            ->and($res->content)->toContain('not allowed');
    }
});

it('lets a public address through, resolves a name once for both families, and exempts the site\'s own host the way core does', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    $asked = [];
    $tool = webFetchTool(resolver: static function (string $host) use (&$asked): array {
        $asked[] = $host;
        return $host === 'site.test' ? ['127.0.0.1'] : ['93.184.216.34', '2606:4700::1'];
    });
    Functions\expect('wp_safe_remote_get')->times(3)->andReturn(webFetchResponse('<p>ok</p>'));
    expect($tool->execute(['url' => 'https://public.test/page'])->content)->toBe('ok')
        ->and($tool->execute(['url' => 'http://93.184.216.34/'])->content)->toBe('ok')
        // The site's own host resolves privately here (a local site does), and is fetched anyway.
        ->and($tool->execute(['url' => 'https://SITE.test/about/'])->content)->toBe('ok')
        ->and($asked)->toBe(['public.test']);
});

it('honours core\'s http_request_host_is_external opt-in for a special-purpose address, as the docblock promises', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    Filters\expectApplied('http_request_host_is_external')->once()->with(false, '169.254.169.254', 'http://169.254.169.254/')->andReturn(true);
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('<p>internal</p>'));
    expect(webFetchTool()->execute(['url' => 'http://169.254.169.254/'])->content)->toBe('internal');
});

it('applies the same check to every redirect hop through the HTTP API\'s before_redirect hook, and unhooks after the fetch', function (): void {
    Functions\when('wp_http_validate_url')->returnArg();
    $guard = null;
    Actions\expectAdded('requests-requests.before_redirect')->once()->whenHappen(static function (\Closure $callback) use (&$guard): void {
        $guard = $callback;
    });
    Actions\expectRemoved('requests-requests.before_redirect')->once();
    $tool = webFetchTool(resolver: static fn(string $host): array => $host === 'internal.test' ? ['10.0.0.7'] : ['93.184.216.34']);
    Functions\expect('wp_safe_remote_get')->once()->andReturn(webFetchResponse('<p>ok</p>'));
    expect($tool->execute(['url' => 'https://public.test/'])->content)->toBe('ok')
        ->and($guard)->toBeInstanceOf(\Closure::class);
    $guard('https://elsewhere.test/');
    foreach (['http://169.254.169.254/', 'http://internal.test/', 'http://[::1]/'] as $location) {
        expect(fn() => $guard($location))->toThrow(\WpOrg\Requests\Exception::class, 'not allowed');
    }
});
