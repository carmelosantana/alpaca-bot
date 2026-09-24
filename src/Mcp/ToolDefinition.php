<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

/**
 * One tool as a server describes it in `tools/list`, and the fingerprint an approval is pinned to
 * (ServerConfig::$approved maps a tool's name to it).
 *
 * A server can redefine a tool after an administrator approved it, and a description is text the
 * model reads. fingerprint() is the sha256 of a canonical encoding of four fields: the name, the
 * description, the input schema and the annotations. Canonical means:
 * - every array that is not a list has its keys sorted (SORT_STRING), recursively, so the digest
 *   does not depend on the order a server emitted an object's keys in;
 * - every list keeps the order it came in. JSON arrays are ordered, some are positional
 *   (`prefixItems`), and the canonical form cannot tell those from the ones that are sets, such
 *   as `enum`, so it sorts none of them;
 * - the encoding is json_encode() with JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE,
 *   JSON_PRESERVE_ZERO_FRACTION and JSON_INVALID_UTF8_SUBSTITUTE, with `serialize_precision` held
 *   at -1 for the call, so a php.ini that sets another value does not move the digest of a
 *   non-integral float.
 *
 * The top-level title is outside the hash, because it is a display label. `annotations` is hashed
 * as sent, `annotations.title` included. JSON_INVALID_UTF8_SUBSTITUTE writes U+FFFD for each
 * invalid UTF-8 sequence, so two definitions that differ only in which bad bytes they carry
 * share a digest.
 *
 * When json_encode() fails, the digest is of serialize() of the same canonical array instead. It
 * fails for a float that decoded to INF or -INF (`1e999`, which no JSON flag can encode) and for
 * nesting past json_encode()'s depth limit of 512. serialize() writes each value with its type and
 * length, INF included, and unserialize() gives the array back, so definitions that differ keep
 * different digests. JSON_PARTIAL_OUTPUT_ON_ERROR would not do that: it writes INF, -INF and NAN
 * all as 0. A JSON encoding of the canonical array starts with `{` and its serialize() form with
 * `a:`, so the two paths never hash the same bytes.
 *
 * The four fields are the values json_decode(..., true) produced, so what decoding merges the
 * digest cannot tell apart: `{}` and `[]`, an empty `annotations` object and none, an object keyed
 * "0", "1", ... in order and the array it matches, and two numbers that decode to one float
 * (9007199254740993.0 and 9007199254740992.0, or 99999999999999999999 and 100000000000000000000,
 * both past PHP_INT_MAX).
 *
 * php-agents 0.16's McpToolDefinition::fingerprint() is specified to be byte-identical to this
 * one (the contract frozen on Kanboard #4364). On the JSON path the two run the same canonical()
 * and the same flags, and ToolDefinitionTest pins digests that php-agents' own
 * McpToolDefinitionTest pins for the same definitions. Parity stops in two places:
 * - for a definition json_encode() cannot encode, php-agents hashes the empty string, which every
 *   such definition shares, and this class hashes the serialize() form;
 * - at a `serialize_precision` other than -1, php-agents' digest of a non-integral float moves and
 *   this one does not.
 * A stored pin is only portable between the two classes where they agree.
 *
 * destructive() is true only when the `destructiveHint` annotation is exactly `true`. The MCP
 * specification says a client must treat annotations as untrusted unless they come from a trusted
 * server, so this is a hint for when to ask a person, never grounds to grant anything.
 * php-agents' McpToolDefinition::destructive() answers differently: it reads a tool whose
 * readOnlyHint is exactly `true` as not destructive, and any other tool as destructive unless its
 * destructiveHint is exactly `false`.
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
        $precision = (string) ini_get('serialize_precision');
        // phpcs:ignore WordPress.PHP.IniSet.Risky -- serialize_precision sets how many digits json_encode() and serialize() write for a float; it is changed for these two calls only and put back in the finally below.
        ini_set('serialize_precision', '-1');
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The plain function, as php-agents' McpToolDefinition calls it: when json_encode() returns false, wp_json_encode() runs _wp_json_sanity_check() and encodes again, a second path the library does not have.
            $json = json_encode($canonical, self::JSON_FLAGS);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Only hashed, never stored or unserialized: the lossless fallback for a definition JSON cannot encode (see the class docblock).
            return hash('sha256', $json !== false ? $json : serialize($canonical));
        } finally {
            ini_set('serialize_precision', $precision); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value read above.
        }
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
