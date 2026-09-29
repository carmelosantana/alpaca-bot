<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Access;
use AlpacaBot\Mcp\Drift;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Rest\Controller;
use AlpacaBot\Rest\RouteCapability;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Shortcodes\Chat as ChatShortcode;
use AlpacaBot\Toolkit\AbilitiesToolkit;
use AlpacaBot\Toolkit\SchemaTool;
use AlpacaBot\View\Hx;
use AlpacaBot\View\Settings\McpTools;

/**
 * The Settings API page for `alpaca_bot_settings`: one setting, one group (`alpaca_bot`), a
 * section per Schema section, a field per Schema field (the Access tab's MCP rows stand in for
 * `access.mcp`, below), shown one section at a time as tabs (`?tab=`) and posted to core's
 * options.php.
 *
 * Core saves an option whole, so a form that shows one tab posts every tab: the fields of the
 * tabs not shown go out as hidden inputs (Fields::hidden()), `access.mcp` aside (below), though a
 * carried empty list or map posts no input at all. Under that, Schema::sanitize() keeps
 * the stored value for any key a post does not name, so a save can never reset a field it did not
 * carry (the old first-save bug, where saving one tab reset the others to their defaults). The
 * two together cover PHP's max_input_vars, which drops the tail of a long post (an overrides table
 * is five controls per model) and tells no one: the carry-over is printed before the visible tab
 * so the tail is never the key or the URL, a dropped field keeps its stored value, and the form
 * ends with END_MARKER, whose absence from a post means it was cut and the whole save is refused
 * with a notice rather than stored in part.
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
 * The abilities allowlist is a box per ability the site lists (AbilitiesToolkit::listed()), posted as
 * `alpaca_bot_settings[toolkits.abilities][]` (renderAbilities()); its options are the site's,
 * not the schema's, which is why it is not a checkbox-list field.
 *
 * The MCP servers are a table too, a row per server posted as
 * `alpaca_bot_settings[toolkits.mcp_servers][<index>][<field>]` (renderMcpServers()). A save
 * that carries a server whose URL is new or changed has that address checked in the sanitize
 * callback (heldServers()), where a refusal can be put on the screen, and a stored server the
 * post lists, as the schema wrote it, is removed only by its `remove` box.
 *
 * The Access tab is not one Schema field per control either. Every row is the capability select
 * the schema describes, on Access::stored(), and under a row whose filter moves it off that value,
 * or cannot be asked from this page, a line says "Set in code" (renderAccess()). The Chat row has
 * no filter of its own, so the menu's filter and the `/chat` route's are asked instead, and the
 * line names the one that moved. `access.mcp` has no control of its own: each MCP server
 * `toolkits.mcp_servers` lists gets a row posting one entry of that map, as
 * `alpaca_bot_settings[access.mcp][<server id>]`. The carry-over leaves the map out, so a save
 * from another tab posts nothing for it and Schema::sanitize() keeps the stored map, sanitized
 * again, rather than replacing it with what the page could print of it. The Access tab posts the
 * selects of the listed servers and nothing else. An entry whose server the list no longer has
 * is not carried, because every update_option() of the option drops it, from whichever tab
 * (Mcp\ServerSettings::beforeSave()): a server added later under the same id starts at
 * administrators only instead of inheriting it.
 *
 * @phpstan-import-type Field from Schema
 */
final class SettingsPage
{
    public const SLUG = 'alpaca-bot-settings';

    /**
     * The Discover button's `hx-on::response-error` handler (discoverButton()), run by htmx with
     * `this` the button and `event` htmx's responseError event.
     */
    public const DISCOVER_REFUSED_JS = "var busy=event.detail.xhr.status===429,cell=document.querySelector(this.getAttribute('hx-target')),note=cell.querySelector('.ab-mcp-refused')||cell.insertBefore(document.createElement('div'),cell.firstChild),p=document.createElement('p');"
        . "note.className='notice inline ab-mcp-refused notice-'+(busy?'warning':'error');p.textContent=busy?this.dataset.abBusy:this.dataset.abRefused;note.replaceChildren(p);";
    public const GROUP = 'alpaca_bot';

    /** The last input of the form. A post of the option that arrives without it was cut short by PHP. */
    public const END_MARKER = 'alpaca_bot_settings_end';

    /** @var \Closure(string): bool */
    private \Closure $exists;

    private ServerSettings $servers;

    /**
     * @param (callable(string): bool)|null $exists  function_exists() or a stand-in for it, as Abilities\Register takes one
     * @param ServerSettings|null           $servers the address check a save of `toolkits.mcp_servers` runs; one over AddressCheck by default
     */
    public function __construct(private Store $store, private ModelCatalog $catalog, private Access $access, ?callable $exists = null, ?ServerSettings $servers = null)
    {
        $this->exists = $exists === null ? function_exists(...) : \Closure::fromCallable($exists);
        $this->servers = $servers ?? new ServerSettings();
    }

    /** On admin_init: the setting, its sections (one page id per tab) and its fields. */
    public function register(): void
    {
        $servers = $this->servers;
        register_setting(self::GROUP, Plugin::OPTION, [
            'type' => 'array',
            'sanitize_callback' => static function (#[\SensitiveParameter] mixed $input) use ($servers): array {
                $stored = get_option(Plugin::OPTION, []);
                $stored = is_array($stored) ? $stored : [];
                if (self::postIsTruncated()) {
                    add_settings_error(Plugin::OPTION, 'truncated', __('Nothing was saved: the form was longer than PHP accepts in one request (max_input_vars), so its end never arrived. Raise max_input_vars in php.ini, or set per-model overrides through the REST API.', 'alpaca-bot'));
                    $input = [];
                }
                $input = is_array($input) ? $input : [];
                return self::heldServers(Schema::sanitize($input, $stored), $input, $stored, $servers);
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
                    $key === 'toolkits.abilities' => $this->renderAbilities($f),
                    $key === 'toolkits.mcp_servers' => $this->renderMcpServers($f),
                    str_starts_with($key, 'access.') => $this->renderAccess(substr($key, strlen('access.')), $f),
                    default => Fields::render($key, $f, $this->store->get($key)),
                };
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fields escapes every attribute and text node, and renderAbilities(), renderMcpServers() and renderAccess() escape what they add.
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
        // the tail is the visible tab's own controls (the last rows of an overrides table, the
        // last boxes of the abilities list), never the key or the URL. The marker is the last
        // input of all; a post without it was cut, and the sanitize callback refuses it.
        foreach (Schema::fields() as $key => $f) {
            // Not access.mcp: left out of the post, Schema::sanitize() keeps the stored map,
            // sanitized again, while a carried map would replace it with whatever
            // Fields::hidden() could print.
            if ($f['section'] !== $active && $key !== 'access.mcp') {
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
     * with (Toolkit\Registry::enabled() passes a tool row the turn's user, and Mcp\Toolkits::for()
     * an MCP row); here it is the
     * administrator viewing the page. A settings row gets a
     * WP_REST_Request of the verb it authorises on `/settings`, as SettingsController passes the
     * request. The shortcode row gets post id 0 and the `[alpacabot]` tag, which is what
     * Shortcodes\Chat passes for a shortcode rendered outside a post.
     *
     * A listener that reads them answers for this administrator, this made-up request, or an
     * `[alpacabot]` outside any post, not for everyone, so the note under a row is what the filter
     * says here and now: one that changes the row only for another user, a post or
     * `[alpacabot_agent]` gets no note.
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
     * tab gives a row (core keeps one field per id, so a repeated id is one row). Only an id
     * Schema::isMcpId() admits gets one, which is every id Schema::sanitizeMcpServers() stores and
     * the only kind of key Schema::sanitizeAccessMcp() keeps, so the tab never shows a select a
     * save would drop. That id rule's characters, `[a-z0-9_]`, are all ones form encoding leaves
     * as they are, and PHP's form parser reads `alpaca_bot_settings[access.mcp][<id>]` back with
     * the id unchanged; an id starting with a letter is never read as an int key. A row that reached the option with any
     * other id gets no select, and reads as administrators only (Access::stored()).
     *
     * @return list<string>
     */
    private function mcpServers(): array
    {
        $servers = $this->store->get('toolkits.mcp_servers', []);
        $ids = [];
        foreach (is_array($servers) ? $servers : [] as $server) {
            $id = is_array($server) ? ($server['id'] ?? null) : null;
            if (Schema::isMcpId($id)) {
                /** @var string $id */
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * `$clean` with its MCP servers held to what the page promises: a stored server the post lists,
     * as the schema wrote it, is removed only by its `remove` box, and a refusal is said on the
     * screen. Four steps, in order.
     *
     * 1. Rows the schema left out (Schema::droppedMcpRows()): a stored server the post lists
     *    without `remove` is put back, as posted when only its prefix was taken by an earlier row,
     *    which step 3 settles, and as stored, said, when its URL or prefix is one the schema does
     *    not accept. A new row with a URL comes back the same way when only its prefix was taken,
     *    and is said and left out otherwise.
     * 2. Addresses (ServerSettings::refusals()), over the list as step 1 left it, so a row step 1
     *    put back as posted is checked too: a stored server whose new or changed URL is refused is
     *    put back as `$stored` has it, whole (header value, approvals and Access entry stand, since
     *    its id and origin do not change); a refused new row is left out. Steps 3 and 4 add no
     *    URL: step 3 only puts rows back as stored or leaves rows out.
     * 3. Prefixes: while two rows share one, the row that keeps it is, in this order, a stored
     *    server whose prefix is its own stored one (as a row put back as stored is), any other
     *    stored server, a new row, and the first of equals. The other loses: a stored server is
     *    put back as stored, a new row is left out, so each round changes a row for good and it
     *    ends. The exception is a loser whose own stored prefix is the one in dispute: the keeper
     *    then holds it as its stored prefix too, so the option already has two servers under one
     *    prefix, which only a write round the schema leaves, and putting the loser back changes
     *    nothing. The server list is then kept exactly as stored, neither server removed, and the
     *    screen names both.
     * 4. Header values: a stored server whose posted mask ServerSettings::beforeSave() is about to
     *    drop, because its URL moved to another origin (ServerSettings::clearedByMove()), is said.
     *
     * Then the list goes through the schema again, against `$stored`, to be the shape it stores
     * (not in step 3's exception, where the schema would drop the second of the two), with the
     * post's `remove` rows handed along so that a row given its id there cannot take the id of a
     * server this save removes.
     * Notices name the server each is about (its URL, and its id for a stored one; a URL the post
     * brought and the schema left out is named without its user name and password) and are escaped
     * here, because settings_errors() prints a message as it is given; they are added address,
     * prefix, dropped and cleared, in that order.
     *
     * @param array<string, mixed> $clean  Schema::sanitize()'s output for this post
     * @param array<string, mixed> $input  the post itself
     * @param array<string, mixed> $stored the option as it is now
     * @return array<string, mixed>
     */
    private static function heldServers(#[\SensitiveParameter] array $clean, #[\SensitiveParameter] array $input, array $stored, ServerSettings $servers): array
    {
        $storedRows = is_array($stored['toolkits.mcp_servers'] ?? null) ? $stored['toolkits.mcp_servers'] : [];
        $was = [];
        foreach ($storedRows as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $was[$row['id']] = $row;
            }
        }
        $rows = is_array($clean['toolkits.mcp_servers'] ?? null) ? $clean['toolkits.mcp_servers'] : [];
        $notices = ['mcp_address' => [], 'mcp_prefix' => [], 'mcp_dropped' => [], 'mcp_cleared' => []];
        $url = static fn(mixed $row): string => is_array($row) && is_string($row['url'] ?? null) ? $row['url'] : '';

        $posted = $input['toolkits.mcp_servers'] ?? null;
        // Valid on its own means the only fault was a prefix an earlier row had taken.
        foreach (Schema::droppedMcpRows($posted) as $key => $fault) {
            /** @var array<array-key, mixed> $row droppedMcpRows() lists arrays only */
            $row = is_array($posted) ? $posted[$key] : [];
            $id = is_string($row['id'] ?? null) ? $row['id'] : null;
            $alone = Schema::sanitizeMcpServers([$row], $storedRows);
            if ($id !== null && isset($was[$id])) {
                if (in_array($id, array_column($rows, 'id'), true)) {
                    continue;
                }
                if ($alone !== []) {
                    $rows[] = $alone[0];
                } else {
                    $rows[] = $was[$id];
                    $notices['mcp_dropped'][] = match ($fault) {
                        /* translators: 1: an MCP server's URL, 2: its id */
                        'userinfo' => sprintf(__('The change to MCP server %1$s (%2$s) was not saved: its URL carries a user name or password. Put a credential in the header instead.', 'alpaca-bot'), $url($was[$id]), $id),
                        /* translators: 1: an MCP server's URL, 2: its id, 3: what a header name has to be (Schema::mcpHeaderNameRule()) */
                        'header' => sprintf(__('The change to MCP server %1$s (%2$s) was not saved: its header name has to be %3$s.', 'alpaca-bot'), $url($was[$id]), $id, Schema::mcpHeaderNameRule()),
                        /* translators: 1: an MCP server's URL, 2: its id, 3: what a prefix has to be (Schema::mcpPrefixRule()) */
                        default => sprintf(__('The change to MCP server %1$s (%2$s) was not saved: its URL has to be https with a host, and its prefix %3$s.', 'alpaca-bot'), $url($was[$id]), $id, Schema::mcpPrefixRule()),
                    };
                }
            } elseif (trim($url($row)) !== '') {
                if ($alone !== []) {
                    // No id: the last pass through the schema gives it one, against every row kept
                    // and every row the post removes.
                    $rows[] = array_merge($alone[0], ['id' => '']);
                } else {
                    $notices['mcp_dropped'][] = match ($fault) {
                        /* translators: %s: the URL of an MCP server that was not added, without its user name and password */
                        'userinfo' => sprintf(__('The MCP server %s was not added: its URL carries a user name or password. Put a credential in the header instead.', 'alpaca-bot'), Schema::withoutUserinfo($url($row))),
                        /* translators: 1: the URL of an MCP server that was not added, without its user name and password, 2: what a header name has to be (Schema::mcpHeaderNameRule()) */
                        'header' => sprintf(__('The MCP server %1$s was not added: its header name has to be %2$s.', 'alpaca-bot'), Schema::withoutUserinfo($url($row)), Schema::mcpHeaderNameRule()),
                        /* translators: 1: the URL of an MCP server that was not added, 2: what a prefix has to be (Schema::mcpPrefixRule()) */
                        default => sprintf(__('The MCP server %1$s was not added: its URL has to be https with a host, and its prefix %2$s.', 'alpaca-bot'), Schema::withoutUserinfo($url($row)), Schema::mcpPrefixRule()),
                    };
                }
            }
        }

        foreach ($servers->refusals($rows, $storedRows) as $i => $reason) {
            $before = $was[$rows[$i]['id']] ?? null;
            $notices['mcp_address'][] = $before !== null
                /* translators: 1: the MCP server's URL as posted, 2: why its address was refused, 3: the URL it keeps */
                ? sprintf(__('The MCP server address %1$s was not saved: %2$s The server keeps %3$s.', 'alpaca-bot'), $rows[$i]['url'], $reason, $url($before))
                /* translators: 1: the MCP server's URL as posted, 2: why its address was refused */
                : sprintf(__('The MCP server %1$s was not added: %2$s', 'alpaca-bot'), $rows[$i]['url'], $reason);
            if ($before !== null) {
                $rows[$i] = $before;
            } else {
                unset($rows[$i]);
            }
        }

        $rank = static fn(array $row): int => match (true) {
            isset($was[$row['id']]) && ($was[$row['id']]['prefix'] ?? null) === $row['prefix'] => 1,
            isset($was[$row['id']]) => 2,
            default => 3,
        };
        $twice = null;
        do {
            $clash = false;
            $owner = [];
            foreach ($rows as $i => $row) {
                $prefix = $row['prefix'] ?? null;
                if (!is_string($prefix) || !isset($owner[$prefix])) {
                    $owner[(string) $prefix] = $i;
                    continue;
                }
                $first = $owner[$prefix];
                [$keep, $lose] = $rank($row) < $rank($rows[$first]) ? [$i, $first] : [$first, $i];
                $owner[$prefix] = $keep;
                $loser = $rows[$lose];
                if (isset($was[$loser['id']]) && ($was[$loser['id']]['prefix'] ?? null) === $prefix) {
                    $twice = [$rows[$keep], $loser, $prefix];
                    break 2;
                }
                if (isset($was[$loser['id']])) {
                    /* translators: 1: an MCP server's URL, 2: its id, 3: a tool-name prefix */
                    $notices['mcp_prefix'][] = sprintf(__('The change to MCP server %1$s (%2$s) was not saved: the prefix %3$s belongs to another server.', 'alpaca-bot'), $url($was[$loser['id']]), $loser['id'], $prefix);
                    $rows[$lose] = $was[$loser['id']];
                } else {
                    /* translators: 1: an MCP server's URL, 2: a tool-name prefix */
                    $notices['mcp_prefix'][] = sprintf(__('The MCP server %1$s was not added: the prefix %2$s belongs to another server.', 'alpaca-bot'), $url($loser), $prefix);
                    unset($rows[$lose]);
                }
                $clash = true;
                break;
            }
        } while ($clash);

        if ($twice !== null) {
            [$one, $two, $prefix] = $twice;
            /* translators: 1: an MCP server's URL, 2: its id, 3: another MCP server's URL, 4: its id, 5: a tool-name prefix */
            $message = sprintf(__('The MCP servers were not saved: %1$s (%2$s) and %3$s (%4$s) are both stored under the prefix %5$s. Give one of them another prefix, or remove one, and save again.', 'alpaca-bot'), $url($one), $one['id'], $url($two), $two['id'], $prefix);
            add_settings_error(Plugin::OPTION, 'mcp_prefix', esc_html($message));
            $clean['toolkits.mcp_servers'] = $storedRows;
            return $clean;
        }

        foreach ($servers->clearedByMove($rows, $storedRows) as $id) {
            foreach ($rows as $row) {
                if (($row['id'] ?? null) === $id) {
                    /* translators: 1: an MCP server's URL, 2: its id */
                    $notices['mcp_cleared'][] = sprintf(__('The header value of MCP server %1$s (%2$s) was cleared, because its address moved to another host or port. Enter it again.', 'alpaca-bot'), $url($row), $id);
                }
            }
        }

        foreach ($notices as $code => $messages) {
            foreach ($messages as $message) {
                add_settings_error(Plugin::OPTION, $code, esc_html($message));
            }
        }
        // The post's `remove` rows go along: the schema keeps none of them, but it counts their ids
        // as named, so a row that brings no id cannot be taken for a server this save removes.
        $removed = array_filter(is_array($posted) ? $posted : [], static fn(mixed $row): bool => is_array($row) && !empty($row['remove']));
        $clean['toolkits.mcp_servers'] = Schema::sanitizeMcpServers([...array_values($rows), ...array_values($removed)], $storedRows);
        return $clean;
    }

    /**
     * The abilities allowlist: a checkbox per ability AbilitiesToolkit::listed() has, which is
     * what the toolkit offers from, each with its label, its name, a flag when the
     * ability's own `annotations` meta says `destructive` is true, and its description as the
     * model will read it (SchemaTool::describe()), so the administrator approves the text the
     * model gets. Each ability AbilitiesToolkit::collisions() finds sharing a tool name is marked,
     * naming the others, since the toolkit offers none of them while they are all ticked. A stored
     * name the list does not have (its plugin deactivated, or a `wp_get_abilities_item_include`
     * filter hiding it) still gets a ticked box, marked as not in the list, so it can be seen and
     * cleared rather than carried invisibly; renderOverrides() keeps a stale model's row for the
     * same reason. A stored name of Alpaca Bot's own gets none: the toolkit never offers one, and
     * Schema::sanitizeAbilities() drops it on the next save.
     *
     * The hidden '' ahead of the boxes is the sentinel Fields::render() explains for a
     * checkbox-list: without it a form with every box clear posts nothing for the field, and
     * Schema::sanitize() keeps what is stored. Where the Abilities API is absent there are no
     * boxes and no sentinel, so a save leaves the stored list as it is.
     *
     * Labels, names and descriptions are other plugins' text: each goes through esc_html() or
     * esc_attr() as it is printed.
     *
     * @param Field $f
     */
    private function renderAbilities(array $f): string
    {
        $desc = '<p class="description">' . esc_html((string) ($f['description'] ?? '')) . '</p>';
        if (!($this->exists)('wp_get_abilities')) {
            return '<p class="description">' . esc_html__('This site\'s WordPress has no Abilities API, so there are no abilities to offer.', 'alpaca-bot') . '</p>';
        }
        $stored = $this->store->get('toolkits.abilities', []);
        $chosen = array_values(array_filter(is_array($stored) ? $stored : [], 'is_string'));
        $listed = AbilitiesToolkit::listed();
        $clashes = AbilitiesToolkit::collisions(array_keys($listed));
        $name = esc_attr(Plugin::OPTION . '[toolkits.abilities][]');
        $items = '';
        foreach ($listed as $id => $ability) {
            $annotations = $ability->get_meta_item('annotations', []);
            $destructive = is_array($annotations) && ($annotations['destructive'] ?? null) === true;
            $clash = isset($clashes[$id])
                ? '<br><span class="description">' . sprintf(
                    /* translators: %s: the other ability's name, or names, e.g. core/get-site-info */
                    esc_html__('Reaches the model under the same tool name as %s, so while both are ticked neither is offered.', 'alpaca-bot'),
                    implode(', ', array_map(static fn(string $other): string => '<code>' . esc_html($other) . '</code>', $clashes[$id])),
                ) . '</span>'
                : '';
            $items .= sprintf(
                '<li><label><input type="checkbox" name="%s" value="%s"%s> <strong>%s</strong> <code>%s</code></label>%s<br><span class="description">%s</span>%s</li>',
                $name,
                esc_attr($id),
                checked(in_array($id, $chosen, true), true, false),
                esc_html($ability->get_label()),
                esc_html($id),
                $destructive ? ' <strong>' . esc_html__('Destructive', 'alpaca-bot') . '</strong>' : '',
                esc_html(SchemaTool::describe($ability->get_description())),
                $clash,
            );
        }
        foreach ($chosen as $id) {
            if (!isset($listed[$id]) && !AbilitiesToolkit::excluded($id)) {
                $items .= sprintf(
                    '<li><label><input type="checkbox" name="%s" value="%s" checked="checked"> <code>%s</code></label> <em>%s</em></li>',
                    $name,
                    esc_attr($id),
                    esc_html($id),
                    esc_html__('(not in this site\'s list of abilities; untick to remove)', 'alpaca-bot'),
                );
            }
        }
        $list = $items === ''
            ? '<p>' . esc_html__('No abilities are registered on this site besides Alpaca Bot\'s own.', 'alpaca-bot') . '</p>'
            : '<ul>' . $items . '</ul>';
        return '<input type="hidden" name="' . $name . '" value="">' . $list . $desc;
    }

    /**
     * The MCP servers table: a row per stored server, then one blank row to add one, every
     * control posting as `alpaca_bot_settings[toolkits.mcp_servers][<index>][<field>]`, so a
     * post is the list Schema::sanitizeMcpServers() reads. A stored row's id is a hidden input
     * and nothing else: an edit of the prefix or the URL keeps it, and with it the server's
     * Access entry and header value. The blank row has none, so a server added there is given
     * one on save; its URL left empty, the schema drops it.
     *
     * The header value is a password control showing Schema::MASK when a value is kept and ''
     * when not, through Schema::maskedServers() as the carry-over does, so no value reaches the
     * page. Posting the mask back keeps the value, '' clears it, anything else replaces it.
     *
     * The approvals cell, `div#ab-mcp-tools-<id>`, holds a hidden input per approved tool, so a
     * save that never pressed Discover carries every approval as it is, and, when the drift marker
     * (Mcp\Drift) names tools the row approves, a line saying they changed since approval; the
     * marker is read rather than the server asked, so drawing the row asks no server anything.
     * Beside the cell, a stored server's Discover button is an hx-get at `GET
     * /view/mcp-tools/<id>` with the row's index, whose fragment (View\Settings\McpTools)
     * replaces the cell's contents with a box per tool the stored server lists, posting under the
     * same names, or, when the server cannot be listed, with the reason and the cell as it was
     * (its hidden inputs, count and drift note); ticking a box and saving is the approval, and a box left clear drops one. It lists
     * the server as saved, not as the row's fields are edited. The blank row's button is disabled.
     * The table's wrapper carries the REST nonce in `hx-headers`, which htmx hands down to the
     * requests of the elements inside it, so the fragment request authenticates as the chat
     * screen's do. Assets enqueues htmx on this page for that button. Ticking `remove` drops the
     * row on save, and with it the server's header value and Access entry (Mcp\ServerSettings).
     *
     * The table is a `widefat` inside a Settings API row like the overrides table, and
     * `div.ab-mcp-servers` gets the same rules (Assets). Every value from a row goes through
     * esc_attr() or esc_html() as it is printed.
     *
     * @param Field $f
     */
    private function renderMcpServers(array $f): string
    {
        $stored = Schema::maskedServers($this->store->get('toolkits.mcp_servers', []));
        $rows = array_values(array_filter(is_array($stored) ? $stored : [], 'is_array'));
        $body = '';
        foreach ($rows as $i => $row) {
            $body .= self::serverRow($i, $row);
        }
        $body .= self::serverRow(count($rows), null);
        $head = '';
        foreach (self::serverColumns() as $label) {
            $head .= '<th>' . esc_html($label) . '</th>';
        }
        return '<div class="ab-mcp-servers" id="ab-mcp-servers"' . Hx::attrs(['headers' => Hx::formHeaders()]) . '><table class="widefat striped"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>'
            . '<p class="description">' . esc_html((string) ($f['description'] ?? '')) . '</p>'
            . '<p class="description">' . esc_html(sprintf(
                /* translators: %s: what a prefix has to be (Schema::mcpPrefixRule()) */
                __('The prefix names this server\'s tools for the model, as prefix__tool: %s. The last row adds a server; leave its URL empty to add none.', 'alpaca-bot'),
                Schema::mcpPrefixRule(),
            )) . '</p>'
            . '<p class="description">' . esc_html__('Discover tools lists the tools a saved server offers. Tick the ones to approve and save; a box left clear drops that tool\'s approval. Each approval is of the tool as it was shown, so a tool the server changes afterwards is marked for review the next time it is listed.', 'alpaca-bot') . '</p>';
    }

    /** @return list<string> the MCP servers table's column headings, in column order */
    private static function serverColumns(): array
    {
        return [__('Prefix', 'alpaca-bot'), __('URL', 'alpaca-bot'), __('Header name', 'alpaca-bot'), __('Header value', 'alpaca-bot'), __('Timeout (seconds)', 'alpaca-bot'), __('Largest response (bytes)', 'alpaca-bot'), __('Approved tools', 'alpaca-bot'), __('Remove', 'alpaca-bot')];
    }

    /**
     * One row of the MCP servers table: `$row` as stored and masked, or null for the blank row
     * that adds a server.
     *
     * @param array<array-key, mixed>|null $row
     */
    private static function serverRow(int $i, ?array $row): string
    {
        $id = is_string($row['id'] ?? null) ? $row['id'] : null;
        $labels = self::serverColumns();
        $cells = [
            ($id === null ? '' : self::serverInput($i, 'id', 'hidden', '', $id, ''))
                . self::serverInput($i, 'prefix', 'text', 'small-text', $row['prefix'] ?? '', $labels[0], ' pattern="[a-z](_?[a-z0-9])*" maxlength="16"'),
            self::serverInput($i, 'url', 'url', 'regular-text', $row['url'] ?? '', $labels[1], ' pattern="https://.*"'),
            self::serverInput($i, 'header_name', 'text', 'regular-text', $row['header_name'] ?? '', $labels[2]),
            self::serverInput($i, 'header_value', 'password', 'regular-text', $row['header_value'] ?? '', $labels[3], ' autocomplete="new-password"'),
            self::serverInput($i, 'timeout', 'number', 'small-text', $row['timeout'] ?? 30, $labels[4], ' step="0.1" min="1" max="120"'),
            self::serverInput($i, 'max_bytes', 'number', 'small-text', $row['max_bytes'] ?? 1048576, $labels[5], ' step="1" min="1024" max="8388608"'),
            '<div id="' . esc_attr('ab-mcp-tools-' . ($id ?? 'new')) . '">' . self::serverApprovals($i, $id, $row['approved'] ?? []) . '</div>' . self::discoverButton($i, $id),
            $id === null ? '' : self::serverInput($i, 'remove', 'checkbox', '', '1', $labels[7]),
        ];
        return '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>';
    }

    /** One control of a server row, posting as `alpaca_bot_settings[toolkits.mcp_servers][<i>][<field>]`; `$extra` is attributes already escaped. */
    private static function serverInput(int $i, string $field, string $type, string $class, mixed $value, string $label, string $extra = ''): string
    {
        return '<input type="' . $type . '"' . ($class === '' ? '' : ' class="' . $class . '"')
            . ' name="' . esc_attr(Plugin::OPTION . '[toolkits.mcp_servers][' . $i . '][' . $field . ']') . '"'
            . ' value="' . esc_attr(is_scalar($value) ? (string) $value : '') . '"' . $extra
            . ($label === '' ? '' : ' aria-label="' . esc_attr($label) . '"') . '>';
    }

    /**
     * A server row's approvals cell: View\Settings\McpTools::approvals() over the row's approvals
     * and its drift marker, which the Discover fragment's error notice carries over too. A row with
     * no id, the blank row among them, keeps its hidden inputs and says to save the server first.
     */
    private static function serverApprovals(int $i, ?string $id, mixed $approved): string
    {
        $approved = is_array($approved) ? $approved : [];
        if ($id === null) {
            return McpTools::kept($i, $approved) . esc_html__('Save the server first.', 'alpaca-bot');
        }
        return McpTools::approvals($i, $approved, Drift::get($id));
    }

    /**
     * A row's Discover button: the approval fragment for a stored server, disabled on the blank row.
     *
     * htmx swaps no 4xx response, so a Discover refused before the route answers its fragment (a
     * 403 for a REST nonce that has expired, or a 429 from the chat bucket's rate limit) would
     * otherwise change nothing on the page. The nonce can expire on a page left open because it
     * is printed once, with the page, into the servers table's static `hx-headers`
     * (Hx::formHeaders(), renderMcpServers()), and nothing renews it there. Core's heartbeat does
     * run on this screen (wp_auth_check_load() enqueues wp-auth-check, which depends on it), but
     * the fresh `rest_nonce` its tick carries goes to `wpApiSettings.nonce`, which htmx does not
     * read, and Assets::heartbeat() answers only a tick that asks for `alpaca_bot_nonce`, which
     * only the chat bundle does. The
     * button's `htmx:responseError` handler, DISCOVER_REFUSED_JS, puts a notice at the top of the
     * approvals cell instead: a warning to wait for a 429, and for any other status an error
     * saying to reload the page. It is an `hx-on` attribute rather than a script because the
     * settings screen loads htmx and no bundle of the plugin's (Assets::enqueue()). The two
     * sentences are the button's `data-ab-busy` and `data-ab-refused` attributes, translated
     * and attribute-escaped here, and the handler writes them as text. It adds one element and
     * replaces the one an earlier refusal added, so the hidden approvals, the count and the drift
     * note stay as they were, and a Discover that is answered swaps the notice away with the rest.
     */
    private static function discoverButton(int $i, ?string $id): string
    {
        $label = esc_html__('Discover tools', 'alpaca-bot');
        if ($id === null) {
            return '<button type="button" class="button" disabled>' . $label . '</button>';
        }
        return '<button type="button" class="button"' . Hx::attrs(['get' => '/mcp-tools/' . $id, 'vals' => ['index' => $i], 'target' => '#ab-mcp-tools-' . $id, 'swap' => 'innerHTML', 'on::response-error' => self::DISCOVER_REFUSED_JS])
            . ' data-ab-refused="' . esc_attr__('Discover tools got no list back: this page\'s session may have expired. Reload the page and try again.', 'alpaca-bot') . '"'
            . ' data-ab-busy="' . esc_attr__('Too many requests for now. Wait a minute, then press Discover tools again.', 'alpaca-bot') . '">' . $label . '</button>';
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
