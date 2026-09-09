<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * The Settings API page for `alpaca_bot_settings`: one setting, one group (`alpaca_bot`), a
 * section per Schema section, a field per Schema field, shown one section at a time as tabs
 * (`?tab=`) and posted to core's options.php.
 *
 * Core saves an option whole, so a form that shows one tab posts every tab: the fields of the
 * tabs not shown go out as hidden inputs (Fields::hidden()), and the sanitize callback receives
 * the entire array every time. Under that, Schema::sanitize() keeps the stored value for any key
 * a post does not name, so a save can never reset a field it did not carry (the old first-save
 * bug, where saving one tab reset the others to their defaults). The two together cover PHP's
 * max_input_vars, which drops the tail of a long post (an overrides table is five controls per
 * model) and tells no one: the carry-over is printed before the visible tab so the tail is never
 * the key or the URL, a dropped field keeps its stored value, and the form ends with END_MARKER,
 * whose absence from a post means it was cut and the whole save is refused with a notice rather
 * than stored in part.
 *
 * The sanitize callback is a closure, not `[Schema::class, 'sanitize']`: core calls it with the
 * option *name* second, and Schema::sanitize() wants the stored array there so a secret posted
 * back as Schema::MASK resolves to the stored key. Handing core the method itself would be a
 * TypeError on every save; the closure reads the option and passes it. What it reads is the raw
 * stored row (get_option()), not Store's memo: the memo may be from earlier in the request, and
 * a sanitize callback should compare against what is in the database at the moment of the write.
 *
 * Per-model overrides are a table with a row per model the catalog knows, posted as
 * `alpaca_bot_settings[models.overrides][<model>][<field>]`; Schema::sanitizeOverrides() drops a
 * row whose fields are all empty, so clearing a row's inputs removes the override.
 */
final class SettingsPage
{
    public const SLUG = 'alpaca-bot-settings';
    public const GROUP = 'alpaca_bot';

    /** The last input of the form. A post of the option that arrives without it was cut short by PHP. */
    public const END_MARKER = 'alpaca_bot_settings_end';

    public function __construct(private Store $store, private ModelCatalog $catalog) {}

    /** On admin_init: the setting, its sections (one page id per tab) and its fields. */
    public function register(): void
    {
        register_setting(self::GROUP, Plugin::OPTION, [
            'type' => 'array',
            'sanitize_callback' => static function (mixed $input): array {
                $stored = get_option(Plugin::OPTION, []);
                $stored = is_array($stored) ? $stored : [];
                if (self::postIsTruncated()) {
                    add_settings_error(Plugin::OPTION, 'truncated', __('Nothing was saved: the form was longer than PHP accepts in one request (max_input_vars), so its end never arrived. Raise max_input_vars in php.ini, or set per-model overrides through the REST API.', 'alpaca-bot'));
                    $input = [];
                }
                return Schema::sanitize(is_array($input) ? $input : [], $stored);
            },
            'default' => Schema::defaults(),
        ]);
        foreach (Schema::sections() as $id => $section) {
            add_settings_section('alpaca_bot_' . $id, $section['label'], static function () use ($section): void {
                echo '<p>' . esc_html($section['description']) . '</p>';
            }, self::page($id));
        }
        foreach (Schema::fields() as $key => $f) {
            // A checkbox carries its own label (its row title is blank, and a label_for there
            // would be an empty second label); a table has no single control to point a label at.
            $args = in_array($f['type'], ['array', 'boolean', 'checkbox-list'], true) ? [] : ['label_for' => Fields::id($key)];
            add_settings_field('alpaca_bot_' . $key, $f['type'] === 'boolean' ? '' : $f['label'], function () use ($key, $f): void {
                echo $key === 'models.overrides' ? $this->renderOverrides() : Fields::render($key, $f, $this->store->get($key)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fields escapes every attribute and text node.
            }, self::page($f['section']), 'alpaca_bot_' . $f['section'], $args);
        }
    }

    /** The menu page callback: tabs, the active section's fields, every other section hidden. */
    public function render(): void
    {
        $sections = Schema::sections();
        $active = $this->activeTab();
        echo '<div class="wrap"><h1>' . esc_html__('Alpaca Bot Settings', 'alpaca-bot') . '</h1>';
        // No setting argument: options.php files "Settings saved." under 'general', and a page
        // outside the Settings menu has to show those itself.
        settings_errors();
        echo '<nav class="nav-tab-wrapper">';
        foreach ($sections as $id => $section) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(add_query_arg(['page' => self::SLUG, 'tab' => $id], admin_url('admin.php'))),
                $id === $active ? ' nav-tab-active' : '',
                esc_html($section['label']),
            );
        }
        echo '</nav><form method="post" action="options.php">';
        settings_fields(self::GROUP);
        // The carry-over before the visible tab: what PHP's max_input_vars cuts is the tail, so
        // the tail is the last rows of an overrides table, never the key or the URL. The marker
        // is the last input of all; a post without it was cut, and the sanitize callback refuses it.
        foreach (Schema::fields() as $key => $f) {
            if ($f['section'] !== $active) {
                echo Fields::hidden($key, $this->store->get($key)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Fields.
            }
        }
        do_settings_sections(self::page($active));
        printf('<input type="hidden" name="%s" value="1">', esc_attr(self::END_MARKER));
        submit_button();
        echo '</form></div>';
    }

    /**
     * True for a form post of the option that lacks the END_MARKER input render() prints last:
     * PHP dropped the tail (max_input_vars, 1000 by default, with no notice to userland) and what
     * arrived is not the form. Any other caller of the sanitize callback (the REST route, WP-CLI,
     * a test) posts nothing and is never refused. options.php verified the nonce before the
     * callback ran; this reads only whether the form reached its end.
     */
    private static function postIsTruncated(): bool
    {
        return isset($_POST[Plugin::OPTION]) && !isset($_POST[self::END_MARKER]); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by options.php; see the docblock.
    }

    /** `?tab=` when it names a section, else the first one. sanitize_key() bounds what is echoed back into the tab links. */
    public function activeTab(): string
    {
        $sections = Schema::sections();
        $requested = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key($_GET['tab']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, selects a tab.
        return isset($sections[$requested]) ? $requested : (string) array_key_first($sections);
    }

    /** The Settings API page id for one section: what do_settings_sections() is asked for. */
    private static function page(string $section): string
    {
        return self::SLUG . '-' . $section;
    }

    /**
     * The overrides table, its columns labelled as the global fields they override. Placeholders
     * show the global value a blank cell falls back to, except the system prompt's: that global
     * is multi-line and lives on the Chat tab, so the caption names it instead. A model with a
     * stored override that the catalog no longer lists still gets a row, so the override can be
     * seen and cleared rather than carried invisibly.
     *
     * The last column overrides no global field: `tools` overrides the model catalog's
     * capability flag, which is not a setting, so its heading is its own word and its control is
     * a three-state select rather than a text cell. The empty state has to be a real option an
     * operator can go back to, which is why it is a select and not a checkbox: a checkbox has
     * two states and the third one, "whatever the catalog says", is the default.
     */
    private function renderOverrides(): string
    {
        $fields = Schema::fields();
        $overrides = $this->store->get('models.overrides', []);
        $overrides = is_array($overrides) ? $overrides : [];
        $ids = array_map(static fn($m): string => $m->id, $this->catalog->all());
        foreach (array_keys($overrides) as $id) {
            if (is_string($id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return '<p class="description">' . esc_html__('No models reported by the provider yet. Check the Provider tab.', 'alpaca-bot') . '</p>';
        }
        $rows = '';
        foreach ($ids as $id) {
            $o = is_array($overrides[$id] ?? null) ? $overrides[$id] : [];
            $cell = function (string $field, string $type, string $class, string $extra = '') use ($id, $o): string {
                $value = $o[$field] ?? '';
                return sprintf(
                    '<td><input type="%s" class="%s" name="%s" value="%s"%s></td>',
                    $type,
                    $class,
                    esc_attr(Plugin::OPTION . '[models.overrides][' . $id . '][' . $field . ']'),
                    esc_attr(is_scalar($value) ? (string) $value : ''),
                    $extra,
                );
            };
            $placeholder = fn(string $key): string => ' placeholder="' . esc_attr((string) $this->store->get($key)) . '"';
            $tools = '<td><select name="' . esc_attr(Plugin::OPTION . '[models.overrides][' . $id . '][tools]') . '">';
            foreach (self::toolsStates() as $value => $label) {
                $tools .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($value),
                    (string) ($o['tools'] ?? '') === $value ? ' selected="selected"' : '',
                    esc_html($label),
                );
            }
            $rows .= '<tr><th scope="row">' . esc_html($id) . '</th>'
                . $cell('temperature', 'number', 'small-text', ' step="0.1" min="0" max="2"' . $placeholder('models.temperature'))
                . $cell('num_ctx', 'number', 'small-text', ' step="1" min="512" max="1048576"' . $placeholder('models.num_ctx'))
                . $cell('keep_alive', 'text', 'small-text', $placeholder('models.keep_alive'))
                . $cell('system', 'text', 'regular-text')
                . $tools . '</select></td>'
                . '</tr>';
        }
        $head = '<th>' . esc_html__('Model', 'alpaca-bot') . '</th>';
        foreach (['models.temperature', 'models.num_ctx', 'models.keep_alive', 'chat.system_prompt'] as $key) {
            $head .= '<th>' . esc_html($fields[$key]['label']) . '</th>';
        }
        $head .= '<th>' . esc_html__('Tools', 'alpaca-bot') . '</th>';
        return '<table class="widefat striped"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="description">' . esc_html__('A blank cell uses the global value: the fields above for temperature, context window and keep alive, and the system prompt on the Chat tab. A model-level system prompt replaces the global one for that model.', 'alpaca-bot') . '</p>'
            . '<p class="description">' . esc_html__('Tools decides whether this model is offered the tools enabled on the Tools tab. Leave it on the model default unless the model misbehaves: some models accept tools and then write the tool call out as text in the reply instead of calling it, and turning tools off for that model gives a plain answer instead. Turn them on for a model you know can call tools that is not being offered them.', 'alpaca-bot') . '</p>';
    }

    /**
     * The three states of the per-model `tools` cell, in the order the select offers them:
     * inherit first, under the empty value Schema::sanitizeOverrides() stores nothing for.
     *
     * @return array<string, string> stored value => label
     */
    private static function toolsStates(): array
    {
        return [
            '' => __('Model default', 'alpaca-bot'),
            Schema::TOOLS_ON => __('Always on', 'alpaca-bot'),
            Schema::TOOLS_OFF => __('Always off', 'alpaca-bot'),
        ];
    }
}
