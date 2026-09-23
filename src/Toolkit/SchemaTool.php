<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
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
 * - toFunctionSchema() returns the schema as given, with one repair: an object schema whose
 *   `properties` is missing or an empty PHP array gets an empty object for it, because an empty
 *   PHP array encodes as the JSON array `[]`. Only that top-level `properties` is repaired. An
 *   empty object nested deeper (an inner `properties`, `items`, `additionalProperties`) still
 *   encodes as `[]`; SchemaToolTest pins that it does.
 * - parameters() returns []. Its one caller in the library is SystemPrompt::withTools()
 *   (Prompt/SystemPrompt.php:76), which prints a "Parameters:" block only for a non-empty list.
 *   The argument checks the library has are Tool::execute()'s, over Tool's own list, so an
 *   empty list here turns none of them off.
 * - execute() runs the closure and nothing else, and a throw becomes a fixed error naming the
 *   tool rather than the raw message, for the reason SummarizeToolkit::tool() gives: a tool
 *   result is text the model reads and may repeat, and an exception's message can quote an
 *   endpoint, or a key in its query string.
 *
 * describe() is the rule for text another party wrote that the model will read. Tags are
 * stripped, script and style elements with their content (wp_strip_all_tags()); format
 * characters (zero-width and bidirectional controls among them) are removed; every run of
 * whitespace, control characters and Unicode separators becomes one space, so no line break of
 * any kind survives; `#`, `>`, backticks, `~` and spaces are trimmed from the front, and spaces
 * from the end, so the one line it becomes cannot open a Markdown heading, a quote or a code
 * fence where it is printed as a line of its own, as SystemPrompt::withTools() prints a
 * description; and what is left is cut to DESCRIPTION_CHARS characters, with an ellipsis when
 * it was longer. Text that is not valid UTF-8 becomes ''. None of that makes the text
 * trustworthy, only bounded, which is why the Tools tab shows an administrator the same text,
 * through this same function, when choosing the tool.
 *
 * @since 0.6.0
 */
final class SchemaTool implements ToolInterface
{
    /** The most of someone else's description the model is given, in characters. */
    public const DESCRIPTION_CHARS = 300;

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
            return ($this->run)($input);
        } catch (\Throwable) {
            return self::failed($this->name);
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
            if (($schema['properties'] ?? []) === []) {
                $schema['properties'] = new \stdClass();
            }
        }
        return ['type' => 'function', 'function' => ['name' => $this->name, 'description' => $this->description, 'parameters' => $schema]];
    }

    public static function describe(string $untrusted): string
    {
        $text = (string) preg_replace('/\p{Cf}+/u', '', wp_strip_all_tags($untrusted));
        $text = ltrim((string) preg_replace('/[\s\p{Cc}\p{Z}]+/u', ' ', $text), '#>`~ ');
        $text = rtrim($text, ' ');
        return mb_strlen($text) > self::DESCRIPTION_CHARS ? mb_substr($text, 0, self::DESCRIPTION_CHARS) . '…' : $text;
    }
}
