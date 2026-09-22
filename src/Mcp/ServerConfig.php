<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * One remote MCP server, as a `toolkits.mcp_servers` settings row describes it: where it is,
 * the one static header it is sent (typically `Authorization: Bearer …`), the prefix its tools
 * are named under, how long a call may take and how many bytes it may answer with, and the
 * tools an administrator approved, each with the fingerprint it had when approved.
 *
 * fromSettings() types a row and nothing more: no URL is judged and no number is clamped here,
 * and nothing downstream is written as though one had been. Egress judges the scheme and the
 * address of whatever URL this carries, on every build, however the row got here.
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
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['url'] ?? ''),
            (string) ($row['header_name'] ?? ''),
            (string) ($row['header_value'] ?? ''),
            (string) ($row['prefix'] ?? ''),
            (float) ($row['timeout'] ?? 30.0),
            (int) ($row['max_bytes'] ?? 1048576),
            $approved,
        );
    }
}
