<?php

declare(strict_types=1);

use AlpacaBot\Rest\Sse;

/*
 * The wire format of the stream route, pinned byte for byte: a client parses `event:` and
 * `data:` lines, blank-line delimited, and nothing else. prepareOutput() is not covered here: it
 * ends every output buffer, the test runner's included, so only the real check (curl against the
 * harness site) exercises it.
 */

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
