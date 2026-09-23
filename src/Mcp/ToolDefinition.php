<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * One tool as a server describes it in `tools/list`, and the fingerprint an approval is pinned to
 * (ServerConfig::$approved maps a tool's name to it).
 *
 * A server can redefine a tool after an administrator approved it, and a description is text the
 * model reads. fingerprint() is the sha256 of a canonical JSON encoding of four fields: the name,
 * the description, the input schema and the annotations. Canonical means:
 * - every array that is not a list has its keys sorted (SORT_STRING), recursively, so the digest
 *   does not depend on the order a server emitted an object's keys in;
 * - every list keeps the order it came in, because reordering an enum changes what the tool
 *   accepts;
 * - the flags are JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_PRESERVE_ZERO_FRACTION and
 *   JSON_INVALID_UTF8_SUBSTITUTE.
 *
 * The top-level title is outside the hash, because it is a display label. `annotations` is hashed
 * as sent, `annotations.title` included. JSON_INVALID_UTF8_SUBSTITUTE is what lets a definition
 * carrying an invalid UTF-8 byte encode at all: without it json_encode() returns false,
 * `(string) false` is '', and every such definition would share the digest of the empty string.
 *
 * php-agents 0.16's McpToolDefinition::fingerprint() is specified to be byte-identical to this
 * one (the contract frozen on Kanboard #4364). A stored pin is only portable between the two
 * classes while they agree, so ToolDefinitionTest pins digests that php-agents' own
 * McpToolDefinitionTest pins for the same definitions.
 *
 * destructive() is true only when the `destructiveHint` annotation is exactly `true`. The MCP
 * specification says a client must treat annotations as untrusted unless they come from a trusted
 * server, so this is a hint for when to ask a person, never grounds to grant anything.
 * php-agents' McpToolDefinition::destructive() answers differently: it reads a missing or
 * non-boolean destructiveHint as true, and reads any tool whose readOnlyHint is exactly `true` as
 * not destructive.
 *
 * @since 0.6.0
 */
final readonly class ToolDefinition
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<array-key, mixed> $inputSchema the tool's JSON Schema, as the server sent it
     * @param array<array-key, mixed> $annotations the server's own hints (`readOnlyHint`, `destructiveHint`, ...)
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $annotations = [],
        public ?string $title = null,
    ) {}

    public function fingerprint(): string
    {
        $canonical = self::canonical([
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'annotations' => $this->annotations,
        ]);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The plain function, as php-agents' McpToolDefinition calls it: when json_encode() returns false, wp_json_encode() runs _wp_json_sanity_check() and encodes again, a second path the library does not have.
        return hash('sha256', (string) json_encode($canonical, self::JSON_FLAGS));
    }

    public function destructive(): bool
    {
        return ($this->annotations['destructiveHint'] ?? null) === true;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }
}
