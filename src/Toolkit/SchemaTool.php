<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Schema\JsonSchemaRepair;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * A tool whose input is a raw JSON Schema rather than typed parameters, such as an ability's
 * input schema (AbilitiesToolkit).
 *
 * php-agents' own Tool takes a list of typed Parameter objects, builds the function schema from
 * them and validates the model's arguments against them inside Tool::execute()
 * (vendor-prefixed/carmelosantana/php-agents/src/Tool/Tool.php). A schema somebody else wrote may
 * use keywords a Parameter has no field for (`oneOf`, `$ref`), and the party that published it
 * validates against it: WP_Ability::execute() runs validate_input(), which asks
 * rest_validate_value_from_schema() (WP 7.1 class-wp-ability.php:519-536). So this implements
 * ToolInterface directly:
 *
 * - toFunctionSchema() returns the schema as given, with its empty objects put back. A schema
 *   decoded with json_decode(..., true) holds `{}` as an empty PHP array, which encodes as the
 *   JSON array `[]`, and some providers refuse `"properties": []`. So an object schema (one whose
 *   `type` is `object` or missing) is given `type: object` and, when it has none, an empty
 *   `properties`; then php-agents' Schema\JsonSchemaRepair::repair() writes an object back
 *   wherever decoding left an array at a keyword whose value must be an object (`properties`,
 *   `items`, `additionalProperties` and the rest its docblock lists), at any depth, and leaves
 *   the keywords whose `[]` may be an empty list (`enum`, `required`, `default`) alone. repair()
 *   only fires on a keyword it finds, which is why `properties` is added first. Nothing else in
 *   the schema is touched here: its own text (a property's description, a title, an enum, a
 *   default) is neither cleaned nor capped by Alpaca Bot, and does not go through describe().
 * - parameters() returns []. Its one caller in the library is SystemPrompt::withTools()
 *   (Prompt/SystemPrompt.php:76), which prints a "Parameters:" block only for a non-empty list,
 *   and which the agent loop this plugin runs does not call (AbstractAgent::buildSystemPrompt()
 *   builds the prompt from the toolkits' guidelines, not the tools).
 *   The argument checks the library has are Tool::execute()'s, over Tool's own list, so an
 *   empty list here turns none of them off.
 * - execute() runs the closure with no argument checks, and a throw becomes a fixed error naming
 *   the tool rather than the raw message, for the reason SummarizeToolkit::tool() gives: a tool
 *   result is text the model reads and may repeat, and an exception's message can quote an
 *   endpoint, or a key in its query string.
 * - Whatever the tool answers, a success or an error, then has every byte sequence that is not
 *   UTF-8 replaced by U+FFFD. A result goes back to the provider in a JSON request body. The
 *   Ollama kind sends it through Symfony HttpClient's `json` option, which throws on a string
 *   that is not UTF-8, so there one such byte (an ability reading legacy Latin-1 content, say)
 *   would fail the turn's next request; core's AI client encodes JSON data with
 *   JSON_THROW_ON_ERROR (php-ai-client Request::getBody()).
 * - It is then cut to RESULT_CHARS characters with CUT_MARKER after it. The agent keeps a result
 *   in the turn's conversation and sends it with each later request of the turn
 *   (AbstractAgent::run()), and the monthly caps are asked only when a turn starts
 *   (Chat\Pipeline, CapPolicy::assertAllowed()), so an unbounded answer would be paid for again
 *   on each request with nothing to stop it. The cut counts characters, so it never falls inside
 *   a UTF-8 character, and it comes after the replacing, so a cut result carries U+FFFD where
 *   the bytes were, as a whole one does.
 *
 * describe() is the rule for a tool description another party wrote. The model reads a
 * description as the `description` string of the tool's function schema, which is JSON. Tags are
 * stripped, script and style elements with their content (wp_strip_all_tags()); format
 * characters (zero-width and bidirectional controls among them) are removed; every run of
 * whitespace, control characters and Unicode separators becomes one space, so no line break of
 * any kind survives; and what is left is cut to DESCRIPTION_CHARS characters, with an ellipsis
 * when it was longer. `#`, `>`, backticks, `~` and spaces are also trimmed from the front, and
 * spaces from the end, so the one line cannot open a Markdown heading, a quote or a code fence
 * wherever a description is printed as a line of Markdown. SystemPrompt::withTools() prints it
 * that way; the agent loop does not call it, so for the path the model reads this is defence in
 * depth. Text that is not valid UTF-8 becomes ''. None of that makes the text trustworthy, only
 * bounded, which is why the Tools tab shows an administrator the same text, through this same
 * function, when choosing the tool. It covers the description only, not the schema's own text
 * (above).
 *
 * @since 0.6.0
 */
final class SchemaTool implements ToolInterface
{
    /** The most of someone else's description the model is given, in characters. */
    public const DESCRIPTION_CHARS = 300;

    /**
     * The most of a tool's result the model is given, in characters: web_fetch's page cap, so a
     * page and an ability's answer are held to the same cap.
     */
    public const RESULT_CHARS = WebFetchToolkit::MAX_CHARS;

    /** What a result cut at RESULT_CHARS ends with, so the model knows there was more. English on purpose, as a tool's guidelines are. */
    public const CUT_MARKER = '… [cut at ' . self::RESULT_CHARS . ' characters]';

    /**
     * @param array<string, mixed>                       $schema the tool's input as JSON Schema
     * @param \Closure(array<string, mixed>): ToolResult $run    what the tool does with the model's arguments
     */
    public function __construct(private string $name, private string $description, private array $schema, private \Closure $run) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array{} the class docblock says why this is empty */
    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        try {
            $result = ($this->run)($input);
        } catch (\Throwable) {
            return self::failed($this->name);
        }
        $content = self::scrub($result->content);
        if (mb_strlen($content, 'UTF-8') > self::RESULT_CHARS) {
            $content = mb_substr($content, 0, self::RESULT_CHARS, 'UTF-8') . self::CUT_MARKER;
        }
        return $content === $result->content ? $result : $result->withContent($content);
    }

    /** `$text` with every byte sequence that is not UTF-8 replaced by U+FFFD (the class docblock says why). */
    private static function scrub(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        // mb_scrub() writes the substitute character the site's mbstring is set to, `?` unless
        // configured, and that setting is global, so it is set for this call and put back.
        $substitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }
    }

    /** The fixed error a tool named `$name` answers in place of a throw's message (the class docblock says why). */
    public static function failed(string $name): ToolResult
    {
        /* translators: %s: the tool's name as the model knows it */
        return ToolResult::error(sprintf(__('The %s tool failed before it could answer.', 'alpaca-bot'), $name));
    }

    public function toFunctionSchema(): array
    {
        $schema = $this->schema;
        if (($schema['type'] ?? 'object') === 'object') {
            $schema['type'] = 'object';
            $schema['properties'] ??= [];
        }
        return ['type' => 'function', 'function' => ['name' => $this->name, 'description' => $this->description, 'parameters' => JsonSchemaRepair::repair($schema)]];
    }

    public static function describe(string $untrusted): string
    {
        $text = (string) preg_replace('/\p{Cf}+/u', '', wp_strip_all_tags($untrusted));
        $text = ltrim((string) preg_replace('/[\s\p{Cc}\p{Z}]+/u', ' ', $text), '#>`~ ');
        $text = rtrim($text, ' ');
        return mb_strlen($text) > self::DESCRIPTION_CHARS ? rtrim(mb_substr($text, 0, self::DESCRIPTION_CHARS), ' ') . '…' : $text;
    }
}
