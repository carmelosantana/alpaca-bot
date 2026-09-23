<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Access;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Rest\Controller;
use AlpacaBot\Rest\RouteCapability;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Shortcodes\Chat as ChatShortcode;

/**
 * The Settings API page for `alpaca_bot_settings`: one setting, one group (`alpaca_bot`), a
 * section per Schema section, a field per Schema field (the Access tab's MCP rows stand in for
 * `access.mcp`, below), shown one section at a time as tabs (`?tab=`) and posted to core's
 * options.php.
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
 *
 * The Access tab is not one Schema field per control either. Every row is the capability select
 * the schema describes, on Access::stored(), and under a row whose filter moves it off that value,
 * or cannot be asked from this page, a line says "Set in code" (renderAccess()). The Chat row has
 * no filter of its own, so the menu's filter and the `/chat` route's are asked instead, and the
 * line names the one that moved. `access.mcp` has no control of its own: each MCP server
 * `toolkits.mcp_servers` lists gets a row posting one entry of that map, as
 * `alpaca_bot_settings[access.mcp][<server id>]`. A save from another tab posts nothing for the
 * map, which Fields::hidden() does not carry since its keys are server ids, so Schema::sanitize()
 * keeps it as stored; the Access tab carries every stored entry it shows no select for as a
 * hidden input, so saving it drops none whose id postable() admits.
 *
 * @phpstan-import-type Field from Schema
 */
final class SettingsPage
{
    public const SLUG = 'alpaca-bot-settings';
    public const GROUP = 'alpaca_bot';

    /** The last input of the form. A post of the option that arrives without it was cut short by PHP. */
    public const END_MARKER = 'alpaca_bot_settings_end';

    public function __construct(private Store $store, private ModelCatalog $catalog, private Access $access) {}

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
        $fields = Schema::fields();
        foreach ($fields as $key => $f) {
            if ($key === 'access.mcp') {
                // No control of its own; the MCP rows below each post one entry of it.
                continue;
            }
            // A checkbox carries its own label (its row title is blank, and a label_for there
            // would be an empty second label); a table has no single control to point a label at.
            $args = in_array($f['type'], ['array', 'boolean', 'checkbox-list'], true) ? [] : ['label_for' => Fields::id($key)];
            add_settings_field('alpaca_bot_' . $key, $f['type'] === 'boolean' ? '' : $f['label'], function () use ($key, $f): void {
                $html = match (true) {
                    $key === 'models.overrides' => $this->renderOverrides(),
                    str_starts_with($key, 'access.') => $this->renderAccess(substr($key, strlen('access.')), $f),
                    default => Fields::render($key, $f, $this->store->get($key)),
                };
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fields escapes every attribute and text node, and renderAccess() escapes what it adds.
            }, self::page($f['section']), 'alpaca_bot_' . $f['section'], $args);
        }
        // One row per MCP server. The servers are the site's, not the schema's, so the rows are
        // not fields(); each borrows the Chat row's field, the capability select with its
        // labelled options, under a label and a description of its own. Core prints a field's
        // title as it is handed it, and a server id is the settings' to say, so it is escaped here.
        foreach ($this->mcpServers() as $id) {
            $row = Access::MCP_PREFIX . $id;
            /* translators: %s: an MCP server's id */
            $label = sprintf(__('MCP server: %s', 'alpaca-bot'), $id);
            $field = ['label' => $label, 'description' => __('Who may use this server\'s tools. A server nobody has chosen a capability for is administrators only.', 'alpaca-bot')] + $fields['access.chat'];
            add_settings_field('alpaca_bot_access.' . $row, esc_html($label), function () use ($row, $field, $id): void {
                echo $this->renderAccess($row, $field, Plugin::OPTION . '[access.mcp][' . $id . ']'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
            }, self::page('access'), 'alpaca_bot_access', ['label_for' => Fields::id('access.' . $row)]);
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
        if ($active === 'access') {
            // This tab posts the access.mcp map, one entry per MCP row, and a posted map replaces
            // the stored one whole: an entry with no row here (a server the list no longer has)
            // is carried, or saving the tab would drop it. Fields::hidden() prints nothing for a
            // map keyed by server id, so the inputs are written here.
            $stored = $this->store->get('access.mcp', []);
            foreach (is_array($stored) ? $stored : [] as $id => $capability) {
                if (is_string($id) && is_string($capability) && self::postable($id) && !in_array($id, $this->mcpServers(), true)) {
                    printf('<input type="hidden" name="%s" value="%s">', esc_attr(Plugin::OPTION . '[access.mcp][' . $id . ']'), esc_attr($capability));
                }
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
     * The page's screen id, which is also its `admin_enqueue_scripts` hook suffix: what HelpTabs
     * and Assets gate on.
     *
     * Not a constant. Core derives a *submenu* screen's id from the parent's menu *title*,
     * translated — `add_menu_page()` stores `sanitize_title($menu_title)` in
     * `$admin_page_hooks[$slug]` and `get_plugin_page_hookname()` uses it as the prefix
     * (wp-admin/includes/plugin.php:1397, :2140-2158) — so on a locale that translates
     * "Alpaca Bot" this page is not `alpaca-bot_page_alpaca-bot-settings` at all, and a
     * hard-coded id once lost every help tab there. The top-level page is unaffected: its
     * own slug is in `$admin_page_hooks`, which takes the `toplevel` branch of the same function
     * and never reads the title, which is why Assets::HOOK can be a constant.
     *
     * So the id is asked of the function core built it with, rather than spelled again here.
     * Both callers run after menu.php has filled `$admin_page_hooks` (wp-admin/admin.php:163):
     * `current_screen` fires from set_current_screen() at admin.php:217, and
     * `admin_enqueue_scripts` from admin-header.php:123, which admin.php requires at :244 for a
     * page registered with a callback, as this one is (Menu), and at :292 for one backed by a
     * plugin file; wp-admin/includes/plugin.php is loaded by then. The guard is for a caller
     * that is not an admin request (a test), where the untranslated form is the right answer
     * anyway.
     */
    public static function screen(): string
    {
        return function_exists('get_plugin_page_hookname')
            ? get_plugin_page_hookname(self::SLUG, Menu::SLUG)
            : 'alpaca-bot_page_' . self::SLUG;
    }

    /**
     * One Access row: the capability select on the stored value, posted under `$name` when that is
     * not the row's own key (an MCP row's entry of the `access.mcp` map), and a line under it for
     * each filter that moves the row off that value or cannot be asked (setInCode()).
     *
     * A row with a filter of its own is asked through Access, with the arguments that filter is
     * given at runtime (accessArgs()), so a listener registered for its row's arguments is called
     * with them here too. Access::overridden() asks first: it never throws, and when resolving
     * the row does, it leaves a line in the debug log under WP_DEBUG. effective() is then asked
     * again for the figure, inside setInCode()'s catch, so a listener on such a row runs twice.
     *
     * The Chat row has no filter in Access, so overridden('chat') would answer false whatever a
     * site did. The menu's filter (Menu::capability(), which the chat screen, the drawer and the
     * editor sidebar ask) and the `/chat` route's (Rest\RouteCapability::filtered(), with a
     * `POST /chat` request) are asked instead, and the line names the one that moved. Every other
     * chat route has a filter of its own, under its own route key, which this does not ask.
     *
     * @param Field $f
     */
    private function renderAccess(string $row, array $f, ?string $name = null): string
    {
        $stored = $this->access->stored($row);
        $html = Fields::render('access.' . $row, $f, $stored, $name);
        if ($row === 'chat') {
            $lines = [
                self::setInCode(esc_html__('for the chat screen, its panel on other admin screens and the block editor sidebar', 'alpaca-bot'), fn(): string => Menu::capability($this->access), $stored, $f),
                self::setInCode(
                    /* translators: %s: the chat route, POST /chat */
                    sprintf(esc_html__('for the chat REST route (%s)', 'alpaca-bot'), '<code>POST /chat</code>'),
                    static fn(): string => RouteCapability::filtered('chat', $stored, new \WP_REST_Request('POST', '/' . Controller::NAMESPACE . '/chat')),
                    $stored,
                    $f,
                ),
            ];
        } else {
            $args = self::accessArgs($row);
            $lines = $this->access->overridden($row, ...$args)
                ? [self::setInCode('', fn(): string => $this->access->effective($row, ...$args), $stored, $f)]
                : [];
        }
        $lines = array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
        if ($lines === []) {
            return $html;
        }
        return $html . '<p class="description">' . implode('<br>', $lines) . ' '
            . esc_html__('Your choice above is still saved, and is what applies once no filter changes it.', 'alpaca-bot') . '</p>';
    }

    /**
     * "Set in code", for `$surface` (already-escaped HTML, or '' for a row with one surface), when
     * what `$resolve` answers is not `$stored`: naming the capability, by its label when it is one
     * the select offers. '' when it is `$stored`. Anything `$resolve` throws — a site's listener that
     * throws, or one declaring more arguments than its hook fires with, which core calls into an
     * ArgumentCountError — is reported as set in code with no figure, so a site's filter cannot
     * take the settings page down.
     *
     * @param \Closure(): string $resolve
     * @param Field $f
     */
    private static function setInCode(string $surface, \Closure $resolve, string $stored, array $f): string
    {
        $head = '<strong>' . esc_html__('Set in code', 'alpaca-bot') . '</strong>' . ($surface === '' ? '' : ' ' . $surface) . ': ';
        try {
            $effective = $resolve();
        } catch (\Throwable) {
            return $head . esc_html__('a filter decides this, and asking it from this page failed, so what it would return is not shown.', 'alpaca-bot');
        }
        if ($effective === $stored) {
            return '';
        }
        $label = $f['options'][$effective] ?? null;
        return $head . ($label !== null
            ? sprintf(
                /* translators: 1: a capability's label, such as "Administrators", 2: the capability's name */
                esc_html__('a filter changes this to %1$s (%2$s).', 'alpaca-bot'),
                esc_html($label),
                '<code>' . esc_html($effective) . '</code>',
            )
            : sprintf(
                /* translators: %s: the name of a capability a filter returned that is not one the select offers */
                esc_html__('a filter changes this to %s.', 'alpaca-bot'),
                '<code>' . esc_html($effective) . '</code>',
            ));
    }

    /**
     * The arguments `$row`'s filter is given at runtime, in the same shape, for the page to ask
     * it with. A tool row and an MCP row get a user id, which is what Access declares they fire
     * with (Toolkit\Registry::enabled() passes a tool row the turn's user); here it is the
     * administrator viewing the page. A settings row gets a
     * WP_REST_Request of the verb it authorises on `/settings`, as SettingsController passes the
     * request. The shortcode row gets post id 0 and the `[alpacabot]` tag, which is what
     * Shortcodes\Chat passes for a shortcode rendered outside a post.
     *
     * A listener that reads them answers for this administrator and this made-up request, not for
     * everyone, so the note under a row is what the filter says here and now.
     *
     * @return list<mixed>
     */
    private static function accessArgs(string $row): array
    {
        return match (true) {
            $row === 'settings.read' => [new \WP_REST_Request('GET', '/' . Controller::NAMESPACE . '/settings')],
            $row === 'settings.write' => [new \WP_REST_Request('PUT', '/' . Controller::NAMESPACE . '/settings')],
            $row === 'shortcode' => [0, ChatShortcode::TAG],
            default => [get_current_user_id()],
        };
    }

    /**
     * The ids of the MCP servers `toolkits.mcp_servers` lists, in list order: the ones the Access
     * tab gives a row (core keeps one field per id, so a repeated id is one row). A server with no
     * id, or one postable() does not admit, gets none, and so reads as whatever Access::stored()
     * has for it: administrators only unless something other than this page stored an entry.
     *
     * @return list<string>
     */
    private function mcpServers(): array
    {
        $servers = $this->store->get('toolkits.mcp_servers', []);
        $ids = [];
        foreach (is_array($servers) ? $servers : [] as $server) {
            $id = is_array($server) ? ($server['id'] ?? null) : null;
            if (is_string($id) && self::postable($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Whether the page gives a server id a select posting as
     * `alpaca_bot_settings[access.mcp][<id>]`: only when every character is one the round trip
     * through esc_attr(), a browser and PHP's form parser hands back unchanged, so the select
     * cannot write some other server's key. That is an allowlist, printable ASCII other than `]`
     * and `&`, and not the whole set that survives: CRLF, a tab or `é` come back intact in
     * Chromium and are refused all the same.
     *
     * What is left out, and why. PHP ends a bracketed segment at its first `]`, so `a]b` posts as
     * `a`, and cuts the name at a NUL byte. A browser posts a lone LF or CR as CRLF, so `a\nb`
     * would post as `a\r\nb`. esc_attr() leaves an entity such as `&amp;` as it is, and the browser
     * decodes it, so `a&amp;b` posts as `a&b`. Anything else outside printable ASCII is refused
     * rather than argued one by one. And an id that is an integer as PHP writes one (`12`, not
     * `012` or ` 12`) becomes an int key, which Schema::sanitizeAccessMcp() drops.
     */
    private static function postable(string $id): bool
    {
        return preg_match('/^[\x20-\x25\x27-\x5C\x5E-\x7E]+$/', $id) === 1 && (string) (int) $id !== $id;
    }

    /**
     * The overrides table, its columns labelled as the global fields they override. Placeholders
     * show the global value a blank cell falls back to, except the system prompt's: that global
     * is multi-line and lives on the Chat tab, so the caption names it instead. A model with a
     * stored override that the catalog no longer lists still gets a row, so the override can be
     * seen and cleared rather than carried invisibly.
     *
     * The table is a `widefat` inside a Settings API row, where core's forms.css reaches its
     * cells (Assets::OVERRIDES_CSS says how); `div.ab-overrides` around it is what the rules
     * Assets adds inline hang off, and it scrolls sideways where the row is narrower than the
     * six columns at their narrowest. The system prompt's heading carries `ab-overrides__system`,
     * which those rules size to 35% of the table, shrinking to a floor. The captions stay outside
     * the wrapper, so that scrollbar is under the table and not under two paragraphs.
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
            // Unreadable is inherit, as it is for every other cell ($cell above,
            // Store::toolsOverride()): a row a filter or a hand edit left holding an array must
            // render, not warn.
            $stored = is_scalar($o['tools'] ?? null) ? (string) $o['tools'] : '';
            $tools = '<td><select name="' . esc_attr(Plugin::OPTION . '[models.overrides][' . $id . '][tools]') . '">';
            foreach (self::toolsStates() as $value => $label) {
                $tools .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($value),
                    $stored === $value ? ' selected="selected"' : '',
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
        foreach (['models.temperature', 'models.num_ctx', 'models.keep_alive'] as $key) {
            $head .= '<th>' . esc_html($fields[$key]['label']) . '</th>';
        }
        $head .= '<th class="ab-overrides__system">' . esc_html($fields['chat.system_prompt']['label']) . '</th>';
        $head .= '<th>' . esc_html__('Tools', 'alpaca-bot') . '</th>';
        return '<div class="ab-overrides"><table class="widefat striped"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="description">' . esc_html__('A blank cell uses the global value: the fields above for temperature, context window and keep alive, and the system prompt on the Chat tab. A model-level system prompt replaces the global one for that model.', 'alpaca-bot') . '</p>'
            . '<p class="description">' . esc_html__('Tools decides whether this model is offered the tools enabled on the Tools tab. Leave it on the model default unless the model misbehaves: some models accept tools and then write the tool call out as text in the reply instead of calling it, and turning tools off for that model gives a plain answer instead. Turn them on for a model you know can call tools that is not being offered them; if no tools are enabled on the Tools tab, this model gets none either way.', 'alpaca-bot') . '</p>';
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
