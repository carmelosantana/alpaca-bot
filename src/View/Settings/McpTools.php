<?php

declare(strict_types=1);

namespace AlpacaBot\View\Settings;

use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Toolkit\SchemaTool;
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
 * that rule has it. The title goes through describe() too. The name, the title and the
 * description are the server's text, and each is printed escaped.
 *
 * A tool whose name Schema::isToolName() refuses, asked of the key the name becomes, is listed
 * with no box and a line saying its name cannot be approved: Schema::sanitizeMcpServers() would
 * drop that key on save, and a box whose tick silently vanishes is worse than none.
 *
 * The swap replaces what the cell held, the hidden inputs carrying the row's approvals among it
 * (SettingsPage), so the list decides the row's approvals on the next save. `$approved`, the
 * row's stored map, is how the fragment says what that costs: an approved tool the server no
 * longer lists is named, since saving drops its approval. On an error, when the server could not
 * be listed, the fragment is the error notice and one hidden input per stored approval, so a save
 * after a failed look keeps every approval as a save that never looked does. An empty list is an
 * info notice.
 *
 * @since 0.6.0
 */
final class McpTools extends Component
{
    /**
     * @param list<array{definition: ToolDefinition, fingerprint: string, state: string, ticked: bool}> $tools    Discovery::tools()
     * @param string                                                                                   $error    why the server could not be listed, untrusted text; '' when it was
     * @param array<array-key, mixed>                                                                  $approved the row's stored `approved` map, tool name => fingerprint
     */
    public function __construct(private int $index, private array $tools, private string $error = '', private array $approved = []) {}

    public function render(): string
    {
        if ($this->error !== '') {
            return (new Notice('error', $this->error))->render() . $this->kept();
        }
        $listed = [];
        $items = '';
        foreach ($this->tools as $tool) {
            $listed[] = $tool['definition']->name;
            $items .= $this->item($tool);
        }
        $out = $items === ''
            ? (new Notice('info', __('This server lists no tools.', 'alpaca-bot')))->render()
            : $this->tag('ul', ['class' => 'ab-mcp-tools'], $items);
        return $out . $this->gone($listed);
    }

    /** @param array{definition: ToolDefinition, fingerprint: string, state: string, ticked: bool} $tool */
    private function item(array $tool): string
    {
        $definition = $tool['definition'];
        $name = $definition->name;
        $title = $definition->title === null ? '' : SchemaTool::describe($definition->title);
        $label = $this->tag('code', [], $this->e($name)) . ($title === '' ? '' : ' ' . $this->tag('strong', [], $this->e($title)));
        $notes = '';
        if ($tool['state'] === 'changed') {
            $notes .= ' ' . $this->tag('strong', [], $this->e(__('changed since approval: review', 'alpaca-bot')));
        }
        if ($definition->destructive()) {
            $notes .= ' ' . $this->tag('em', [], $this->e(__('Destructive, by the server\'s own account: a claim this site cannot check.', 'alpaca-bot')));
        }
        if (Schema::isToolName(array_key_first([$name => true]))) {
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
        return $this->tag('li', [], $head . $notes . '<br>' . $this->tag('span', ['class' => 'description'], $this->e(SchemaTool::describe($definition->description))));
    }

    /** One hidden input per stored approval, as SettingsPage draws them: the error fragment's carry-over. */
    private function kept(): string
    {
        $out = '';
        foreach ($this->approved as $name => $fingerprint) {
            if (is_string($name) && is_string($fingerprint)) {
                $out .= $this->tag('input', ['type' => 'hidden', 'name' => $this->field($name), 'value' => $fingerprint]);
            }
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

    private function field(string $tool): string
    {
        return Plugin::OPTION . '[toolkits.mcp_servers][' . $this->index . '][approved][' . $tool . ']';
    }
}
