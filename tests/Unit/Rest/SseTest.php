<?php

declare(strict_types=1);

use AlpacaBot\Rest\Sse;

/*
 * The wire format of the stream route, pinned byte for byte: a client parses `event:` and
 * `data:` lines, blank-line delimited, and nothing else.
 *
 * prepareOutput() cannot run in this process: it ends every output buffer, the test runner's
 * included, and sets a process time limit on the runner itself. It runs in a child instead
 * (timeLimitInFreshProcess() below), which is what lets the one thing it does that a curl check
 * cannot see -- the number it hands set_time_limit() -- be asserted.
 */

/**
 * `ini_get('max_execution_time')` after Sse::prepareOutput($seconds), read in a PHP process of
 * its own. freshProcess() (Pest.php) says why a child rather than this one; here the reason is
 * narrower still, since prepareOutput() would end PHPUnit's output buffers and put its own limit
 * on the runner. set_time_limit() writes max_execution_time, which is why reading the ini back is
 * reading the call's argument (verified: `php -r 'set_time_limit(720); echo ini_get(...)'` prints
 * 720). Only vendor/autoload.php is loaded: Sse::prepareOutput() calls nothing but PHP builtins.
 */
function timeLimitInFreshProcess(int $seconds): string
{
    $script = <<<'PHP_SCRIPT'
    <?php
    [, $root, $seconds] = $argv;
    require $root . '/vendor/autoload.php';
    AlpacaBot\Rest\Sse::prepareOutput((int) $seconds);
    echo ini_get('max_execution_time');
    PHP_SCRIPT;

    return freshProcess($script, [dirname(__DIR__, 3), (string) $seconds]);
}

it('caps the streaming process at the budget it was handed, never lifting the limit', function (): void {
    // The M-1 fix, and the only assertion on it. `set_time_limit(0)` is what this replaced: a
    // lifted limit let one redeemed ticket hold a PHP worker with no end, and the
    // ignore_user_abort(true) two lines above it means closing the browser does not end it
    // either -- the worker-exhaustion bug StreamBudget was written to close. A mutation back to
    // set_time_limit(0) leaves every other test in both suites green, so this is where it dies.
    expect(timeLimitInFreshProcess(720))->toBe('720')
        ->and(timeLimitInFreshProcess(60))->toBe('60');
});

it('floors the process time limit at one second, since 0 is the value that means no limit', function (): void {
    // Defensive, not a path anything takes today: StreamBudget::seconds() returns
    // max($timeout, $seconds) over a $timeout that is itself max(1, ...), so it cannot hand this
    // a 0, and StreamController::serve() passes it nothing else. The floor is here because 0 is
    // not a small budget but the absence of one, so an argument that arrives as 0 by any other
    // route must not be read as "run forever".
    expect(timeLimitInFreshProcess(0))->toBe('1')
        ->and(timeLimitInFreshProcess(-5))->toBe('1');
});

it('formats a frame as an event line, one JSON data line and a blank line', function (): void {
    expect(Sse::frame('delta', ['text' => 'a"b']))->toBe("event: delta\ndata: {\"text\":\"a\\\"b\"}\n\n");
});

it('keeps a frame to a single data line whatever the payload holds', function (): void {
    // A newline inside the JSON would start a second line and break the frame; unicode and
    // slashes go through unescaped, so a client reads the model's text as the model wrote it.
    $frame = Sse::frame('delta', ['text' => "line one\nline two — café /path"]);
    expect(substr_count($frame, "\n"))->toBe(3)
        ->and($frame)->toBe("event: delta\ndata: {\"text\":\"line one\\nline two — café /path\"}\n\n");
});

it('declares the headers a streamed response needs, and no connection-specific one', function (): void {
    // `Connection: keep-alive` is HTTP/1.1's default and forbidden on HTTP/2, where a
    // connection-specific header makes the response malformed.
    expect(Sse::headers())->toBe([
        'Content-Type' => 'text/event-stream; charset=utf-8',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no',
    ]);
});
