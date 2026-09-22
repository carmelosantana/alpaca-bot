<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * One remote MCP server, as a `toolkits.mcp_servers` settings row describes it: where it is,
 * the one static header it is sent (typically `Authorization: Bearer …`), the prefix its tools
 * are named under, how long a call may take and how many bytes it may answer with, and the
 * tools an administrator approved, each with the fingerprint it had when approved.
 *
 * fromSettings() types a row and judges no URL: Egress judges the scheme and the address of
 * whatever URL this carries, on every build, however the row got here.
 *
 * The two numbers are the exception, and only because zero does not mean zero downstream. A
 * settings field left blank stores '', `(float) '' === 0.0`, and `??` catches an absent key and
 * not an empty one -- so a blank timeout would reach Symfony as `max_duration` 0, which it reads
 * as no limit at all (CurlHttpClient.php:298, NativeHttpClient.php:139). A negative one is worse
 * again: `max_duration` stays uncapped and the idle `timeout` PinnedHttpClient sets from the
 * same field is rewritten to two days (HttpClientTrait.php:185-186). That is the wall-clock cap
 * PinnedHttpClient says it holds a turn to, removed by a field nobody filled in. A blank byte
 * cap fails the other way, refusing every response at 0 bytes. Neither is a number an
 * administrator asked for, so a value that does not cast to a positive one falls back to the
 * shipped default rather than being passed on.
 *
 * @since 0.6.0
 */
final readonly class ServerConfig
{
    /**
     * @param array<string, string> $approved tool name => fingerprint pinned at approval
     */
    public function __construct(
        public string $id,
        public string $url,
        public string $headerName,
        public string $headerValue,
        public string $prefix,
        public float $timeout = 30.0,
        public int $maxBytes = 1048576,
        public array $approved = [],
    ) {}

    /** @param array<string, mixed> $row one `toolkits.mcp_servers` row */
    public static function fromSettings(array $row): self
    {
        $approved = [];
        foreach ((array) ($row['approved'] ?? []) as $name => $fingerprint) {
            if (is_string($name) && is_string($fingerprint)) {
                $approved[$name] = $fingerprint;
            }
        }
        $timeout = (float) ($row['timeout'] ?? 0);
        $maxBytes = (int) ($row['max_bytes'] ?? 0);
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['url'] ?? ''),
            (string) ($row['header_name'] ?? ''),
            (string) ($row['header_value'] ?? ''),
            (string) ($row['prefix'] ?? ''),
            $timeout > 0 ? $timeout : 30.0,
            $maxBytes > 0 ? $maxBytes : 1048576,
            $approved,
        );
    }
}
