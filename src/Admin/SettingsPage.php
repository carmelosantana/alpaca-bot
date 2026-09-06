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
 * Core saves an option whole, so a form that shows one tab has to post every tab: the fields of
 * the tabs not shown go out as hidden inputs (Fields::hidden()), and the sanitize callback
 * receives the entire array every time. That is the fix for the old first-save bug, where saving
 * one tab reset the others to their defaults.
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

    public function __construct(private Store $store, private ModelCatalog $catalog) {}

    /** On admin_init: the setting, its sections (one page id per tab) and its fields. */
    public function register(): void
    {
        register_setting(self::GROUP, Plugin::OPTION, [
            'type' => 'array',
            'sanitize_callback' => static function (mixed $input): array {
                $stored = get_option(Plugin::OPTION, []);
                return Schema::sanitize(is_array($input) ? $input : [], is_array($stored) ? $stored : []);
            },
            'default' => Schema::defaults(),
        ]);
        foreach (Schema::sections() as $id => $section) {
            add_settings_section('alpaca_bot_' . $id, $section['label'], static function () use ($section): void {
                echo '<p>' . esc_html($section['description']) . '</p>';
            }, self::page($id));
        }
        foreach (Schema::fields() as $key => $f) {
            // A checkbox carries its own label; a table has no single control to point a label at.
            $args = $f['type'] === 'array' ? [] : ['label_for' => Fields::id($key)];
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
        do_settings_sections(self::page($active));
        foreach (Schema::fields() as $key => $f) {
            if ($f['section'] !== $active) {
                echo Fields::hidden($key, $this->store->get($key)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Fields.
            }
        }
        submit_button();
        echo '</form></div>';
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
     * The overrides table. Placeholders show the global value each blank cell falls back to.
     * A model with a stored override that the catalog no longer lists still gets a row, so the
     * override can be seen and cleared rather than carried invisibly.
     */
    private function renderOverrides(): string
    {
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
            $rows .= '<tr><th scope="row">' . esc_html($id) . '</th>'
                . $cell('temperature', 'number', 'small-text', ' step="0.1" min="0" max="2"' . $placeholder('models.temperature'))
                . $cell('num_ctx', 'number', 'small-text', ' step="1" min="512" max="1048576"' . $placeholder('models.num_ctx'))
                . $cell('keep_alive', 'text', 'small-text', $placeholder('models.keep_alive'))
                . $cell('system', 'text', 'regular-text')
                . '</tr>';
        }
        return '<table class="widefat striped"><thead><tr><th>' . esc_html__('Model', 'alpaca-bot') . '</th><th>' . esc_html__('Temperature', 'alpaca-bot') . '</th><th>num_ctx</th><th>keep_alive</th><th>' . esc_html__('System prompt', 'alpaca-bot') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="description">' . esc_html__('Blank cells use the global values above. A model-level system prompt replaces the global one for that model.', 'alpaca-bot') . '</p>';
    }
}
