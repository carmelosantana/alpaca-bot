<?php

declare(strict_types=1);

namespace AlpacaBot\View\Settings;

use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Toolkit\SchemaTool;
use AlpacaBot\Toolkit\ToolName;
use AlpacaBot\View\Chat\Notice;
use AlpacaBot\View\Component;

/**
 * One MCP server's tools as the approval list of the settings form: what `GET
 * /view/mcp-tools/{id}` answers, swapped by htmx into that server's approvals cell on the Tools
 * tab (Admin\SettingsPage). Its boxes post as
 * `alpaca_bot_settings[toolkits.mcp_servers][<index>][approved][<tool>]`, `<index>` being the
 * row's own, so ticking a box and saving the form is the approval, and what a box posts is the
 * fingerprint Discovery computed for the definition shown beside it. A tool's box is checked as
 * Discovery's `ticked` says.
 *
 * Each tool shows its name, its title when it has one, whether it has changed since approval,
 * whether the server calls it destructive, and its description through SchemaTool::describe(),
 * the plugin's rule for a tool description another party wrote (AbilitiesToolkit hands the model
 * its abilities' descriptions through the same function), so what is shown is cleaned and cut as
 * that rule has it. The title goes through describe() too. Under each tool its input schema,
 * which approving pins with the rest (ToolDefinition::fingerprint()) and which reaches the model
 * neither cleaned nor capped (SchemaTool), is shown collapsed in a `<details>`, as pretty-printed
 * JSON cut at SCHEMA_CHARS characters, with a line saying so when it is cut; a schema JSON cannot
 * write (json_decode() makes INF of `1e999`) is a line saying it cannot be shown. The name, the
 * title, the description and the schema are the server's text, and each is printed escaped.
 *
 * A tool whose name Schema::isToolName() refuses, asked of the key the name becomes, is listed
 * with no box and a line saying its name cannot be approved: Schema::sanitizeMcpServers() would
 * drop that key on save, and a box whose tick silently vanishes is worse than none. A name the
 * listing carries more than once is listed the same way on every copy, with a line saying so:
 * boxes under one name post as one, and the tick that came last would be kept. The name is
 * listed, so gone() does not name it, and a save drops an approval it has. Two tools that keep
 * their boxes but reach the model under one name (ToolName::fit() of `<prefix>__<name>`, under
 * the server's prefix) are each marked with a line naming the other, as the abilities list marks
 * two abilities: McpToolkit offers neither of them while both are approved and unchanged.
 *
 * The swap replaces what the cell held, the hidden inputs carrying the row's approvals among it
 * (SettingsPage), so the list decides the row's approvals on the next save. `$approved`, the
 * row's stored map, is how the fragment says what that costs: an approved tool the server no
 * longer lists is named, since saving drops its approval. On an error, when the server could not
 * be listed, the fragment is the error notice followed by what the cell held, drawn by
 * approvals() as SettingsPage draws the cell: a hidden input per stored approval, so a save
 * after a failed look keeps every approval as a save that never looked does, how many there
 * are, and the drift note for those `$drifted` names. An empty list is an info notice.
 *
 * @since 0.6.0
 */
final class McpTools extends Component
{
    /** The most of a tool's input schema, as pretty-printed JSON, the list shows, in characters. */
    public const SCHEMA_CHARS = 4000;

    /**
     * @param list<array{definition: ToolDefinition, fingerprint: string, state: string, ticked: bool}> $tools    Discovery::tools()
     * @param string                                                                                   $error    why the server could not be listed, untrusted text; '' when it was
     * @param array<array-key, mixed>                                                                  $approved the row's stored `approved` map, tool name => fingerprint
     * @param string                                                                                   $prefix   the server's tool-name prefix, which the model's names for its tools start with
     * @param list<string>                                                                             $drifted  the server's drift marker (Mcp\Drift::get()), which the error fragment's drift note reads
     */
    public function __construct(private int $index, private array $tools, private string $error = '', private array $approved = [], private string $prefix = '', private array $drifted = []) {}

    public function render(): string
    {
        if ($this->error !== '') {
            return (new Notice('error', $this->error))->render() . self::approvals($this->index, $this->approved, $this->drifted);
        }
        $listed = array_map(static fn(array $tool): string => $tool['definition']->name, $this->tools);
        $counts = array_count_values($listed);
        $clashes = $this->clashes($counts);
        $items = '';
        foreach ($this->tools as $tool) {
            $items .= $this->item($tool, $counts[$tool['definition']->name] > 1, $clashes[$tool['definition']->name] ?? []);
        }
        $out = $items === ''
            ? (new Notice('info', __('This server lists no tools.', 'alpaca-bot')))->render()
            : $this->tag('ul', ['class' => 'ab-mcp-tools'], $items);
        return $out . $this->gone($listed);
    }

    /**
     * @param array{definition: ToolDefinition, fingerprint: string, state: string, ticked: bool} $tool
     * @param bool                                                                                   $repeated whether the listing carries this tool's name more than once
     * @param list<string>                                                                           $clashes  the other tools the model would know by this one's name
     */
    private function item(array $tool, bool $repeated, array $clashes): string
    {
        $definition = $tool['definition'];
        $name = $definition->name;
        $title = $definition->title === null ? '' : SchemaTool::describe($definition->title);
        $label = $this->tag('code', [], $this->e($name)) . ($title === '' ? '' : ' ' . $this->tag('strong', [], $this->e($title)));
        $notes = '';
        if ($tool['state'] === 'changed') {
            $notes .= ' ' . $this->tag('strong', [], $this->e(__('changed since approval: review', 'alpaca-bot')));
        }
        if ($clashes !== []) {
            $notes .= '<br>' . $this->tag('span', ['class' => 'description'], sprintf(
                /* translators: %s: the other tool's name, or names, e.g. search */
                $this->e(__('Reaches the model under the same tool name as %s, so while both are ticked neither is offered.', 'alpaca-bot')),
                implode(', ', array_map(fn(string $other): string => $this->tag('code', [], $this->e($other)), $clashes)),
            ));
        }
        if ($definition->destructive()) {
            $notes .= ' ' . $this->tag('em', [], $this->e(__('Destructive, by the server\'s own account: a claim this site cannot check.', 'alpaca-bot')));
        }
        if ($repeated) {
            $head = $label . ' ' . $this->tag('em', [], $this->e(__('The server lists this name more than once, so no copy has a box; saving drops the name\'s approval, if it has one.', 'alpaca-bot')));
        } elseif (Schema::isToolName(array_key_first([$name => true]))) {
            $box = $this->tag('input', [
                'type' => 'checkbox',
                'name' => $this->field($name),
                'value' => $tool['fingerprint'],
                'checked' => $tool['ticked'] ? 'checked' : null,
            ]);
            $head = $this->tag('label', [], $box . ' ' . $label);
        } else {
            $head = $label . ' ' . $this->tag('em', [], $this->e(__('This name cannot be approved, so the tool has no box.', 'alpaca-bot')));
        }
        return $this->tag('li', [], $head . $notes . '<br>' . $this->tag('span', ['class' => 'description'], $this->e(SchemaTool::describe($definition->description))) . $this->schema($definition->inputSchema));
    }

    /**
     * A tool's input schema, collapsed: the class docblock.
     *
     * @param array<array-key, mixed> $schema
     */
    private function schema(array $schema): string
    {
        $json = wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $body = $this->tag('p', [], $this->e(__('This schema holds a value JSON cannot write, such as a number too large for it, so it cannot be shown.', 'alpaca-bot')));
        } elseif (mb_strlen($json) > self::SCHEMA_CHARS) {
            $body = $this->tag('pre', [], $this->e(mb_substr($json, 0, self::SCHEMA_CHARS) . '…'))
                . $this->tag('p', ['class' => 'description'], $this->e(sprintf(
                    /* translators: %d: how many characters of an MCP tool's input schema the approval list shows */
                    __('Cut at %d characters here; the model is handed the whole schema.', 'alpaca-bot'),
                    self::SCHEMA_CHARS,
                )));
        } else {
            $body = $this->tag('pre', [], $this->e($json));
        }
        return $this->tag('details', ['class' => 'ab-mcp-schema'], $this->tag('summary', [], $this->e(__('Input schema', 'alpaca-bot'))) . $body);
    }

    /**
     * Each tool that could be approved (a name the listing carries once, and one Schema::isToolName()
     * takes) whose name ToolName::fit() makes the same as another's, with the others' names.
     * McpToolkit offers neither of two such tools while both are approved and unchanged, as AbilitiesToolkit does
     * with two abilities, and this is how the list says so.
     *
     * @param array<array-key, int> $counts how often the listing carries each name
     * @return array<string, list<string>> by name; empty for a tool no other one shares a name with
     */
    private function clashes(array $counts): array
    {
        $byName = [];
        foreach ($this->tools as $tool) {
            $name = $tool['definition']->name;
            if ($counts[$name] === 1 && Schema::isToolName(array_key_first([$name => true]))) {
                $byName[ToolName::fit($this->prefix . '__' . $name)][] = $name;
            }
        }
        $out = [];
        foreach ($byName as $group) {
            foreach ($group as $name) {
                $out[$name] = array_values(array_diff($group, [$name]));
            }
        }
        return $out;
    }

    /**
     * A stored server's approvals cell as it is before any Discover: a hidden input per approved
     * tool, posting under row `$index`, how many there are, and, when the drift marker names tools
     * the row approves, a line saying they changed since approval, in the marker's order.
     * SettingsPage draws the cell with it, and the error fragment carries it over.
     *
     * @param array<array-key, mixed> $approved the row's stored `approved` map, tool name => fingerprint
     * @param list<string>            $drifted  the server's drift marker
     */
    public static function approvals(int $index, array $approved, array $drifted): string
    {
        $out = self::kept($index, $approved);
        $count = count(array_filter($approved, 'is_scalar'));
        /* translators: %d: how many of a server's tools are approved */
        $out .= esc_html(sprintf(_n('%d tool approved.', '%d tools approved.', $count, 'alpaca-bot'), $count));
        $noted = array_values(array_filter($drifted, static fn(string $tool): bool => array_key_exists($tool, $approved)));
        if ($noted !== []) {
            $out .= '<p class="description"><strong>' . esc_html__('changed since approval: review', 'alpaca-bot') . '</strong> '
                . implode(', ', array_map(static fn(string $tool): string => '<code>' . esc_html($tool) . '</code>', $noted)) . '</p>';
        }
        return $out;
    }

    /** @param list<string> $listed the names the server listed */
    private function gone(array $listed): string
    {
        $names = [];
        foreach (array_keys($this->approved) as $name) {
            if (!in_array((string) $name, $listed, true)) {
                $names[] = $this->tag('code', [], $this->e((string) $name));
            }
        }
        if ($names === []) {
            return '';
        }
        return $this->tag('p', ['class' => 'description'], $this->e(__('No longer listed by the server, so saving drops its approval:', 'alpaca-bot')) . ' ' . implode(', ', $names));
    }

    /**
     * One hidden input per approval whose fingerprint is a scalar, posting under row `$index`.
     *
     * @param array<array-key, mixed> $approved tool name => fingerprint
     */
    public static function kept(int $index, array $approved): string
    {
        $out = '';
        foreach ($approved as $tool => $fingerprint) {
            if (is_scalar($fingerprint)) {
                $out .= '<input type="hidden" name="' . esc_attr(self::fieldName($index, (string) $tool)) . '" value="' . esc_attr((string) $fingerprint) . '">';
            }
        }
        return $out;
    }

    private function field(string $tool): string
    {
        return self::fieldName($this->index, $tool);
    }

    private static function fieldName(int $index, string $tool): string
    {
        return Plugin::OPTION . '[toolkits.mcp_servers][' . $index . '][approved][' . $tool . ']';
    }
}
