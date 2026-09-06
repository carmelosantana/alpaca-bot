<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

/**
 * The server-sent-events wire format for the stream route, and what a PHP process has to undo
 * to write it progressively. A frame is `event: name`, one `data:` line of JSON, a blank line;
 * a client (EventSource, or a fetch reader splitting on blank lines) needs nothing else.
 */
final class Sse
{
    /**
     * The JSON is one line by construction: json_encode escapes the newlines inside strings,
     * and a raw newline in the data line would end the frame early. Unicode and slashes go
     * through as they are, so the model's text reaches the client as the model wrote it.
     *
     * @param array<string, mixed> $data
     */
    public static function frame(string $event, array $data): string
    {
        return "event: {$event}\ndata: " . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    /**
     * Sent in place of the JSON headers core has already queued (Content-Type replaces its
     * application/json). X-Accel-Buffering is for an nginx in front of PHP, which otherwise
     * holds the response until it ends; no-cache is for anything else in between. No
     * `Connection: keep-alive`: it is what HTTP/1.1 does anyway, and under HTTP/2 a
     * connection-specific header is malformed (Apache strips it; a stricter peer may not).
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Makes every subsequent echo + flush() reach the client at once. Output buffers (a
     * plugin's, or PHP's own output_buffering) are ended and discarded, not flushed: what they
     * held was never part of the stream. Apache's mod_deflate would otherwise hold frames to
     * compress them (the no-gzip note is what it honours), zlib.output_compression likewise.
     *
     * The script keeps running when the client goes away (ignore_user_abort): PHP only notices a
     * closed connection while writing, and would otherwise end the process inside that write,
     * leaving the pipeline's generator to be destroyed at shutdown. StreamController checks
     * connection_aborted() after each frame instead and returns in an orderly way, so the
     * pipeline stores the partial reply as part of the request rather than as its teardown.
     * The time limit is lifted because a slow model can outlast max_execution_time, which on
     * Linux counts only this process's CPU time but is not guaranteed to.
     */
    public static function prepareOutput(): void
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        // A buffer started without PHP_OUTPUT_HANDLER_REMOVABLE refuses to end and leaves the
        // level as it was; stop there rather than spin (its contents then precede the stream).
        while (ob_get_level() > 0) {
            if (@ob_end_clean() === false) {
                break;
            }
        }
        ignore_user_abort(true);
        set_time_limit(0);
    }
}
