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
     * Between frames the process cannot know: on a plain turn that is the wait for the next
     * token; on a tool turn it would have been a whole tool's side effect, so the pipeline
     * yields an empty delta before every tool runs (Chat\AgentStreamObserver's heartbeat), a
     * frame whose write is the check, and a disconnected client's run is dropped before the
     * tool, not after. The window that remains is one provider call. StreamController's
     * wall-clock deadline is checked in the same places, with the same window.
     *
     * `$seconds` replaces the `set_time_limit(0)` this used to do. A lifted limit was for a slow
     * model outlasting max_execution_time; what it also allowed was one redeemed ticket holding a
     * PHP worker with no end, and the `ignore_user_abort(true)` at the foot of this method means
     * closing the browser does not end it either. StreamBudget::seconds() sizes the replacement and says how.
     * It is a backstop and not the bound: on Unix, max_execution_time does not count time spent
     * blocked in a stream operation, which is where a slow provider's time goes, so this fires
     * for a run that is burning CPU and StreamController's own deadline check is what ends one
     * that is waiting. Both are here because they fail in different places.
     */
    public static function prepareOutput(int $seconds): void
    {
        // phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- Turning off buffering and compression for the life of this one request is what makes progressive streaming possible at all; there is no WordPress API for it. Each call is silenced because a host that disables the setting, or runs PHP as something other than an Apache module, makes it emit a warning that would be written into the event stream and corrupt the first frame. Every one is advisory: a failure only means the client sees the reply in larger pieces.
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
        // phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv
        ignore_user_abort(true);
        set_time_limit(max(1, $seconds));
    }
}
