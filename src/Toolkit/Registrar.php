<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Access;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * How a companion plugin adds a toolkit that is a first-class citizen: registered with the
 * Registry beside the built-ins, with a box under Settings › Tools › Enabled tools and a row of
 * its own under Settings › Access, so an administrator can see it, switch it and gate it like
 * web_fetch. Handed to `alpaca_bot/register_toolkits`, which Plugin::register() fires once the
 * built-ins are in.
 *
 * Compared with `alpaca_bot/toolkits`, which stays the last word over a turn's toolkits, a
 * toolkit added here is off until an administrator ticks it, and is offered only to users who
 * pass its row (`tool.{id}`, filtered as `alpaca_bot/capability/tool/{id}` with the user id,
 * like every tool row). Its tools are behind `alpaca_bot/tool/before_call` like every other.
 *
 * Most companions do not need this: a plugin that registers WordPress abilities is already a
 * toolkit, through the abilities allowlist (Settings › Tools), with core's permission check and
 * execution filters around every call. This is for a tool that needs the turn itself.
 *
 * What is registered is kept for the request in a static list, because Settings\Schema and
 * Access answer from static tables and have to know the companion's box and row wherever they
 * are asked. Settings name a companion by its id: while the companion is inactive the id is not
 * offered, and the next save of the settings drops the tick and the row, as it drops anything
 * the schema does not list.
 *
 * @since 0.8.0
 */
final class Registrar
{
    /** The id: a lower-case word of up to 32 characters, letters, digits and underscores, starting with a letter. */
    private const ID = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** @var array<string, array{label: string, description: string, capability: string}> id => what Schema and Access show and default to */
    private static array $entries = [];

    public function __construct(private Registry $registry) {}

    /**
     * Adds `$toolkit` under `$id`. `$label` names it under Enabled tools and, as "Tool: label",
     * on its Access row, whose description is `$description`. `$capability` is the row's default
     * and must be one the Access tab offers (Access::CAPABILITIES); anything else is stored as
     * `manage_options`, so a mistake fails closed. Pass strings already translated.
     *
     * An id that is not a lower-case word, or that is already registered (a built-in or an
     * earlier companion), is refused with _doing_it_wrong() and false, and nothing is registered.
     */
    public function add(string $id, ToolkitInterface $toolkit, string $label, string $description = '', string $capability = 'manage_options'): bool
    {
        if (preg_match(self::ID, $id) !== 1 || in_array($id, $this->registry->ids(), true)) {
            _doing_it_wrong(
                self::class . '::add',
                /* translators: %s: the toolkit id a companion asked for */
                sprintf(esc_html__('The toolkit id "%s" is taken or is not a lower-case word of letters, digits and underscores, starting with a letter, of up to 32 characters.', 'alpaca-bot'), esc_html($id)),
                '0.8.0',
            );
            return false;
        }
        $this->registry->register($id, $toolkit);
        self::$entries[$id] = [
            'label' => $label,
            'description' => $description,
            'capability' => in_array($capability, Access::CAPABILITIES, true) ? $capability : 'manage_options',
        ];
        return true;
    }

    /**
     * Every companion toolkit registered this request, in registration order.
     *
     * @return array<string, array{label: string, description: string, capability: string}>
     */
    public static function all(): array
    {
        return self::$entries;
    }
}
