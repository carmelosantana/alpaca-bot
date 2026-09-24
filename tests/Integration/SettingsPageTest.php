<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Access;
use AlpacaBot\Admin\Menu;
use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\Model;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * The Settings API page over real core: register_setting()'s sanitize path as options.php
 * drives it (update_option() -> sanitize_option() -> our callback), the real menu globals, and
 * the page markup itself. wp-admin/includes/admin.php is loaded by wp-phpunit's bootstrap
 * (its xmlrpc test case requires it), so add_menu_page() and the settings template helpers
 * exist in this process.
 *
 * @group admin
 */
final class SettingsPageTest extends TestCase
{
    /**
     * The registration is run directly rather than through do_action('admin_init'): core hooks
     * send_frame_options_header() there, and header() under PHPUnit's CLI, with output already
     * started, is a fatal. That the plugin hooks admin_init is asserted on its own below.
     */
    public function set_up(): void
    {
        parent::set_up();
        $this->asAdmin();
        Plugin::instance()->get(SettingsPage::class)->register();
    }

    public function test_the_page_registers_on_admin_init_and_the_menu_on_admin_menu(): void
    {
        $this->assertNotFalse(has_action('admin_init', [Plugin::instance()->get(SettingsPage::class), 'register']));
        $this->assertNotFalse(has_action('admin_menu'));
    }

    public function tear_down(): void
    {
        unset($_GET['tab']);
        $_POST = [];
        $GLOBALS['wp_settings_errors'] = [];
        // Settings fields live in a process global core's rollback does not touch, and a page
        // registered by one test (renderAccessPage()) would leave its MCP rows for the next to
        // render. set_up() registers the plugin's own again.
        $GLOBALS['wp_settings_fields'] = [];
        parent::tear_down();
    }

    public function test_setting_is_registered_and_sanitized_on_save(): void
    {
        $registered = get_registered_settings();
        $this->assertArrayHasKey('alpaca_bot_settings', $registered);
        $this->assertSame('alpaca_bot', $registered['alpaca_bot_settings']['group']);
        update_option('alpaca_bot_settings', ['models.num_ctx' => '99999999999', 'provider.kind' => 'nope']);
        $saved = get_option('alpaca_bot_settings');
        $this->assertSame(1048576, $saved['models.num_ctx']);
        $this->assertSame('ollama', $saved['provider.kind']);
        // A save is the whole array: every schema key, in schema order, nothing else.
        $this->assertSame(array_keys(Schema::fields()), array_keys($saved));
    }

    public function test_a_fresh_install_reads_the_defaults_from_the_registered_setting(): void
    {
        delete_option('alpaca_bot_settings');
        $this->assertSame(Schema::defaults(), get_option('alpaca_bot_settings'));
    }

    // What the page posts for an untouched password field is the mask, and what it posts for
    // a cleared one is ''. Both must reach the option the way Schema::sanitize() means them.
    public function test_saving_the_mask_keeps_the_stored_key_and_an_empty_string_clears_it(): void
    {
        update_option('alpaca_bot_settings', array_merge(Schema::defaults(), ['provider.api_key' => 'sk-integration']));
        $this->assertSame('sk-integration', get_option('alpaca_bot_settings')['provider.api_key']);
        update_option('alpaca_bot_settings', ['provider.api_key' => Schema::MASK, 'models.temperature' => '1.1'] + Schema::defaults());
        $saved = get_option('alpaca_bot_settings');
        $this->assertSame('sk-integration', $saved['provider.api_key']);
        $this->assertSame(1.1, $saved['models.temperature']);
        update_option('alpaca_bot_settings', ['provider.api_key' => ''] + Schema::defaults());
        $this->assertSame('', get_option('alpaca_bot_settings')['provider.api_key']);
    }

    public function test_menu_pages_are_registered(): void
    {
        set_current_screen('dashboard');
        do_action('admin_menu');
        global $menu, $submenu;
        $this->assertContains(Menu::SLUG, array_column($menu, 2));
        $slugs = array_column($submenu['alpaca-bot'] ?? [], 2);
        $this->assertSame(['alpaca-bot', 'alpaca-bot-settings'], $slugs);
        $settings = $submenu['alpaca-bot'][array_search('alpaca-bot-settings', $slugs, true)];
        $this->assertSame('manage_options', $settings[1]);
        $this->assertSame('Settings', $settings[0]);
    }

    public function test_menu_capability_is_filterable(): void
    {
        add_filter('alpaca_bot/admin/menu_capability', static fn(): string => 'read');
        set_current_screen('dashboard');
        do_action('admin_menu');
        global $menu;
        $entry = null;
        foreach ($menu as $item) {
            if (($item[2] ?? null) === Menu::SLUG) {
                $entry = $item;
            }
        }
        $this->assertNotNull($entry);
        $this->assertSame('read', $entry[1]);
    }

    /**
     * `__return_true` is what a site reaches for when a menu will not appear, and it is the one
     * value that must not work: `(string) true` is '1', which core reads as the legacy `level_1`
     * check rather than as a capability. On stock roles `edit_posts` and `level_1` happen to
     * cover the same set, so the harm is not visible there; what the cast really does is throw
     * away whatever capability the site settled on and check a user level instead. Asserted
     * against a real WordPress, because the point is what core's capability map makes of '1' —
     * which is also why this test is the one place that asks core a deprecated question.
     *
     * @expectedDeprecated has_cap
     */
    public function test_menu_capability_filter_returning_true_does_not_open_the_chat_screen(): void
    {
        add_filter('alpaca_bot/admin/menu_capability', '__return_true');
        set_current_screen('dashboard');
        do_action('admin_menu');
        global $menu;
        $entry = null;
        foreach ($menu as $item) {
            if (($item[2] ?? null) === Menu::SLUG) {
                $entry = $item;
            }
        }
        $this->assertNotNull($entry);
        $this->assertSame('edit_posts', $entry[1], 'a filter that is not a capability name must leave the declared one in place');

        // A role with a legacy user level and none of the plugin's capability: the two checks
        // are not the same question, and the cast would have answered the wrong one. Plugins
        // that build roles from user levels still produce roles shaped like this.
        add_role('ab_leveller', 'Leveller', ['read' => true, 'level_0' => true, 'level_1' => true]);
        $leveller = self::factory()->user->create_and_get(['role' => 'ab_leveller']);
        $this->assertTrue($leveller->has_cap('1'), "'1' is the level_1 check, which this role passes");
        $this->assertFalse($leveller->has_cap('edit_posts'), 'and edit_posts, which it does not');
        remove_role('ab_leveller');
    }

    /**
     * The whole old first-save bug, in-process: render the Chat tab, post back exactly what
     * the form carries plus one change, and every other tab's value survives, the key included
     * and never printed. The posted array is rebuilt from the hidden inputs the way a browser
     * would build it, so the test fails if the carry-over ever stops covering a field.
     */
    public function test_saving_one_tab_keeps_every_other_tab_and_never_prints_the_key(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->replace(array_merge(Schema::defaults(), [
            'provider.api_key' => 'sk-secret-integration',
            'provider.base_url' => 'http://ollama.invalid:11434/v1',
            'models.default' => 'qwen3-vl:2b',
            'models.temperature' => 0.3,
            'models.overrides' => ['qwen3-vl:2b' => ['num_ctx' => 4096, 'system' => 'Be brief & kind']],
            // A map the carry-over leaves out (SettingsPage::render()): it is absent from the post,
            // Schema::sanitize() keeps what is stored, and the round trip below is what proves
            // it. Seeded non-empty on purpose -- with an empty map the assertion passes whatever
            // the carry-over does, and what would be lost is access-control data. Its server is
            // listed, since an entry for one that is not is dropped on every write.
            'access.mcp' => ['github' => 'read'],
            // Carried to any depth, its approvals included, and its header value as the mask.
            'toolkits.mcp_servers' => [['url' => 'https://93.184.216.34/mcp', 'prefix' => 'github', 'header_value' => 'Bearer tab-secret-integration', 'approved' => ['search' => str_repeat('c', 64)]]],
            // Posted by a textarea as CRLF; stored as LF, and carried through a hidden input unchanged.
            'chat.system_prompt' => "You are terse.\r\nAnswer in one line.",
            'chat.spellcheck' => false,
            'privacy.usage_retention_days' => 0,
        ]));
        $before = get_option('alpaca_bot_settings');
        $this->assertSame('sk-secret-integration', $before['provider.api_key']);
        $this->assertSame(['github' => 'read'], $before['access.mcp']);
        $this->assertSame(['search' => str_repeat('c', 64)], $before['toolkits.mcp_servers'][0]['approved']);
        $this->assertSame(Schema::MASK, $before['toolkits.mcp_servers'][0]['header_value']);
        $this->assertSame("You are terse.\nAnswer in one line.", $before['chat.system_prompt']);

        $_GET['tab'] = 'chat';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('sk-secret-integration', $html);
        $this->assertStringNotContainsString('tab-secret-integration', $html);
        $this->assertStringContainsString('<textarea id="ab-chat-welcome" name="alpaca_bot_settings[chat.welcome]"', $html);
        $this->assertMatchesRegularExpression('/name=[\'"]option_page[\'"] value=[\'"]alpaca_bot[\'"]/', $html);
        $this->assertStringContainsString('name="_wpnonce"', $html);
        $this->assertSame(count(Schema::sections()), preg_match_all('/class="nav-tab( nav-tab-active)?"/', $html));

        $posted = self::formPost($html);
        $this->assertSame(Schema::MASK, $posted['provider.api_key']);
        $this->assertSame('How can I help?', $posted['chat.welcome']);
        $posted['chat.welcome'] = 'Hello from the Chat tab';
        $posted['chat.spellcheck'] = '1';

        update_option('alpaca_bot_settings', $posted);
        $after = get_option('alpaca_bot_settings');
        $this->assertSame('Hello from the Chat tab', $after['chat.welcome']);
        $this->assertTrue($after['chat.spellcheck']);
        unset($before['chat.welcome'], $after['chat.welcome'], $before['chat.spellcheck'], $after['chat.spellcheck']);
        $this->assertSame($before, $after);
    }

    /**
     * The Access tab arrives with the section: SettingsPage builds a tab per Schema section and a
     * control per field, `access.mcp` aside, so a row added to Schema is a select on this screen
     * with nothing else written. Asserted by rendering it rather than by counting tabs, because an
     * empty tab counts the same as a working one. `access.mcp` gets no row of its own, empty or
     * otherwise: with no MCP server there are exactly the declared rows' selects and nothing else.
     */
    public function test_the_access_tab_renders_a_control_for_every_row(): void
    {
        $_GET['tab'] = 'access';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        $page = Plugin::instance()->get(SettingsPage::class);
        $page->render();
        $html = (string) ob_get_clean();

        $this->assertSame('access', $page->activeTab());
        foreach (array_keys(Access::defaults()) as $row) {
            $this->assertStringContainsString('<select id="ab-access-' . str_replace('.', '-', $row) . '"', $html, $row);
        }
        $this->assertSame(count(Access::defaults()), preg_match_all('/<select id="ab-access-/', $html));
        $this->assertStringContainsString('value="edit_posts" selected', $html);
        $this->assertStringNotContainsString('>' . Schema::fields()['access.mcp']['label'] . '<', $html);
        // A PHP notice from a field the page cannot render would be printed into this markup.
        $this->assertStringNotContainsString('Notice:', $html);
        $this->assertStringNotContainsString('Warning:', $html);
    }

    /**
     * A page over its own Store and Access, registered in place of the plugin's, rendering `$tab`.
     *
     * Fresh objects because the plugin's Store memoises the option for the whole process, and a
     * test here writes the option round it (update_option(), or the table itself). The plugin's
     * registration is taken down first, so one sanitize callback runs per save, as on a real
     * site.
     */
    private function renderAccessPage(string $tab): string
    {
        unregister_setting(SettingsPage::GROUP, Plugin::OPTION);
        $store = new Store();
        $page = new SettingsPage($store, Plugin::instance()->get(\AlpacaBot\Provider\ModelCatalog::class), new Access($store));
        $page->register();
        $_GET['tab'] = $tab;
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        $page->render();
        return (string) ob_get_clean();
    }

    /** `$raw` written to the option's row directly, round every filter, as a hand edit of the table would; the option caches are dropped so the next read sees it. */
    private static function writeRaw(array $raw): void
    {
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($raw)], ['option_name' => Plugin::OPTION]);
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete(Plugin::OPTION, 'options');
    }

    /** What the form posts, saved as options.php saves it: update_option(), through the registered sanitize callback. */
    private static function save(array $posted): void
    {
        $_POST = [Plugin::OPTION => $posted, SettingsPage::END_MARKER => '1'];
        update_option(Plugin::OPTION, $posted);
    }

    /**
     * R63: an MCP row saves. `access.mcp` is one map field, and Schema::sanitize() keeps only the
     * keys fields() declares, so a row posted under a key of its own (`access.mcp.docs`) would be
     * dropped on save while the page went on showing a select that looked settable. The row is
     * saved here by picking an option in the rendered select and posting the form as a browser
     * would, through the registered sanitize callback, and read back through Access: whatever
     * name the select carries is what is proved. Then another tab is saved and the row survives
     * it.
     *
     * R74: an entry for a server the list does not have is not carried by the Access tab and does
     * not survive its save. It is put in the row by hand here, since every write through
     * update_option() drops it (Mcp\ServerSettings::beforeSave()).
     */
    public function test_an_mcp_row_saved_on_the_access_tab_reaches_access_survives_another_tab_and_an_unlisted_entry_does_not(): void
    {
        Plugin::instance()->get(Store::class)->replace(['toolkits.mcp_servers' => [['id' => 'docs', 'url' => 'https://93.184.216.34/mcp', 'prefix' => 'docs']]]);
        $raw = get_option(Plugin::OPTION);
        $raw['access.mcp'] = ['gone' => 'edit_posts'];
        self::writeRaw($raw);
        $fresh = static fn(): Access => new Access(new Store());
        $this->assertSame('manage_options', $fresh()->stored('mcp.docs'), 'a server nobody has chosen for is an administrator\'s');
        $this->assertSame('edit_posts', $fresh()->stored('mcp.gone'));

        $html = $this->renderAccessPage('access');
        $this->assertStringContainsString('MCP server: docs', $html);
        $this->assertStringNotContainsString('[access.mcp][gone]', $html);
        self::save(self::postedFrom(self::choose($html, 'ab-access-mcp-docs', 'publish_posts')));

        $this->assertSame('publish_posts', $fresh()->stored('mcp.docs'));
        $this->assertSame('manage_options', $fresh()->stored('mcp.gone'), 'an entry whose server is not listed does not survive a save');
        $this->assertSame(['docs' => 'publish_posts'], get_option(Plugin::OPTION)['access.mcp']);

        $posted = self::formPost($this->renderAccessPage('chat'));
        $posted['chat.welcome'] = 'Saved from the Chat tab';
        self::save($posted);

        $this->assertSame('Saved from the Chat tab', get_option(Plugin::OPTION)['chat.welcome']);
        $this->assertSame('publish_posts', $fresh()->stored('mcp.docs'));
    }

    /**
     * A save from another tab must keep the access.mcp map, sanitized again, whatever the stored
     * map holds, rather than replace it with what the page could print of it. Here a hand edit
     * left one entry holding an array, written to the row directly: update_option() in this test
     * would run the sanitize callback set_up() registered, which drops such an entry. Were the map
     * carried like other fields, Fields::hidden() would post that entry alone, the post would
     * replace the map, and Schema::sanitizeAccessMcp() would store []: every server back to
     * administrators only.
     */
    public function test_saving_another_tab_keeps_the_access_mcp_map_even_with_a_hand_edited_entry_in_it(): void
    {
        $raw = get_option(Plugin::OPTION);
        $raw['toolkits.mcp_servers'] = [['id' => 'docs', 'url' => 'https://93.184.216.34/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'docs', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []]];
        $raw['access.mcp'] = ['docs' => 'read', 'bad' => ['x' => 'read']];
        self::writeRaw($raw);
        $this->assertSame(['docs' => 'read', 'bad' => ['x' => 'read']], get_option(Plugin::OPTION)['access.mcp']);

        $html = $this->renderAccessPage('chat');
        $this->assertStringNotContainsString('alpaca_bot_settings[access.mcp]', $html);
        $posted = self::formPost($html);
        $posted['chat.welcome'] = 'Saved from the Chat tab';
        self::save($posted);

        $this->assertSame('Saved from the Chat tab', get_option(Plugin::OPTION)['chat.welcome']);
        // The readable entry is kept; the one holding an array is dropped, as any save drops it.
        $this->assertSame(['docs' => 'read'], get_option(Plugin::OPTION)['access.mcp']);
    }

    /**
     * Merge point 5, over real core hooks: a listener registered for its row's arguments is
     * asked with them from this page, and one that cannot be called with them (core raises an
     * ArgumentCountError when it declares more than its row fires with) is reported as set in
     * code without a figure, rather than taking the page down. The Chat row is asked of both its
     * surfaces, each through its own hook, and the note says which one moved.
     */
    public function test_the_access_tab_asks_each_filter_with_its_rows_arguments_and_survives_one_that_cannot_be_called(): void
    {
        $admin = get_current_user_id();
        add_filter('alpaca_bot/capability/tool/web_fetch', static fn(string $cap, int $userId): string => $userId === $admin ? 'manage_options' : $cap, 10, 2);
        add_filter('alpaca_bot/capability/shortcode', static fn(string $cap, int $postId, string $tag): string => $tag === 'alpacabot' ? 'edit_others_posts' : $cap, 10, 3);
        add_filter('alpaca_bot/capability/settings/write', static fn(string $cap, \WP_REST_Request $request): string => $request->get_method() === 'PUT' ? 'edit_others_posts' : $cap, 10, 2);
        add_filter('alpaca_bot/capability/chat', static fn(string $cap, \WP_REST_Request $request): string => $request->get_route() === '/alpaca-bot/v1/chat' ? 'read' : $cap, 10, 2);
        // Declares one argument more than its row fires with.
        add_filter('alpaca_bot/capability/tool/summarize', static fn(string $cap, int $userId, string $extra): string => 'read', 10, 3);

        // Access::overridden() leaves a line in the debug log when resolving a row throws; the
        // page asks it first so that a row flipping to "set in code" is never silent there.
        $log = (string) tempnam(sys_get_temp_dir(), 'ab-access-log');
        $previous = ini_set('error_log', $log);
        try {
            $html = $this->renderAccessPage('access');
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
        $this->assertStringContainsString('resolving the tool.summarize access row threw', $logged);
        $row = static function (string $id) use ($html): string {
            preg_match('#<tr[^>]*>(?:(?!<tr).)*id="' . preg_quote($id, '#') . '".*?</tr>#s', $html, $m);
            return $m[0] ?? '';
        };

        $this->assertStringContainsString('<strong>Set in code</strong>: a filter changes this to Administrators (<code>manage_options</code>).', $row('ab-access-tool-web_fetch'));
        $this->assertStringContainsString('a filter changes this to Editors and up (<code>edit_others_posts</code>).', $row('ab-access-shortcode'));
        $this->assertStringContainsString('a filter changes this to Editors and up (<code>edit_others_posts</code>).', $row('ab-access-settings-write'));
        $this->assertStringContainsString('<strong>Set in code</strong>: a filter decides this, and asking it from this page failed', $row('ab-access-tool-summarize'));
        $this->assertStringContainsString('<select id="ab-access-tool-summarize"', $row('ab-access-tool-summarize'));
        $chat = $row('ab-access-chat');
        $this->assertStringContainsString('for the chat REST route (<code>POST /chat</code>): a filter changes this to Any logged-in user (<code>read</code>).', $chat);
        $this->assertStringNotContainsString('for the chat screen', $chat);
        $this->assertStringNotContainsString('Set in code', $row('ab-access-tool-draft_post'));
        $this->assertStringContainsString('<input type="submit"', $html, 'the page rendered to its end');
    }

    /**
     * A server id reaches the page through core's do_settings_fields(), which prints a field's
     * title as it is given. Only an id Schema::isMcpId() admits gets a row, the rule every stored
     * id and every access.mcp key is held to (R73), so each hostile id below, written into the
     * row by hand, gets none: `x]"<y` would post as `x`, `lf\nx` as `lf\r\nx`, `x\n` as `x\r\n`,
     * and `12` as an int key.
     */
    public function test_a_hostile_mcp_server_id_gets_no_access_row(): void
    {
        $raw = get_option(Plugin::OPTION);
        $raw['toolkits.mcp_servers'] = [['id' => 'a"<b>c'], ['id' => 'x]"<y'], ['id' => "lf\nx"], ['id' => '12'], ['id' => "x\n"], ['id' => 'docs_2']];
        self::writeRaw($raw);
        $html = $this->renderAccessPage('access');

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<y', $html);
        $this->assertStringNotContainsString('[access.mcp][a', $html);
        $this->assertStringNotContainsString('[access.mcp][x', $html);
        $this->assertStringNotContainsString('[access.mcp][lf', $html);
        $this->assertStringNotContainsString('[access.mcp][12]', $html);
        $this->assertSame(1, substr_count($html, 'MCP server: '));
        $this->assertStringContainsString('name="alpaca_bot_settings[access.mcp][docs_2]"', $html);
    }

    /**
     * PHP's max_input_vars (1000 by default) drops the tail of a long POST and tells userland
     * nothing; a Models tab with four inputs per catalog model gets there at a few hundred
     * models. Two layers cover it, and each is proved on its own here. The page ends the form
     * with a marker input, and the sanitize callback refuses a post of the option that arrives
     * without it: nothing changes and the admin is told why. And under that, the schema keeps
     * the stored value for every key a post does not name, so even a post the guard does not
     * see (the callback reached with no $_POST at all) can never clear the key or the URL: the
     * reviewer's case, reproduced against the old layout where the carry-over was the tail.
     */
    public function test_a_post_php_cut_short_is_refused_whole_and_the_schema_keeps_what_it_never_heard(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->replace(['provider.api_key' => 'sk-REVIEW-SENTINEL-9f3a', 'provider.base_url' => 'https://openrouter.ai/api/v1', 'models.default' => 'qwen3-vl:2b']);
        $before = get_option('alpaca_bot_settings');

        $_GET['tab'] = 'models';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();
        // The carry-over precedes the visible tab, and the marker is the last input before submit.
        $this->assertLessThan(strpos($html, 'id="ab-models-default"'), strpos($html, 'name="alpaca_bot_settings[provider.api_key]"'));
        $this->assertLessThan(strpos($html, 'id="submit"'), strpos($html, 'name="' . SettingsPage::END_MARKER . '" value="1"'));
        $posted = self::postedFrom($html);
        $posted['models.default'] = 'changed-on-the-models-tab';

        // Layer one, the guard: the post arrived without its marker, so it was cut, and is refused.
        $_POST = ['alpaca_bot_settings' => $posted];
        update_option('alpaca_bot_settings', $posted);
        $this->assertSame($before, get_option('alpaca_bot_settings'));
        $errors = get_settings_errors('alpaca_bot_settings');
        $this->assertCount(1, $errors);
        $this->assertSame('truncated', $errors[0]['code']);
        $this->assertStringContainsString('max_input_vars', $errors[0]['message']);
        // With the marker the same post saves.
        $_POST[SettingsPage::END_MARKER] = '1';
        update_option('alpaca_bot_settings', $posted);
        $this->assertSame('changed-on-the-models-tab', get_option('alpaca_bot_settings')['models.default']);
        $this->assertSame('sk-REVIEW-SENTINEL-9f3a', get_option('alpaca_bot_settings')['provider.api_key']);

        // Layer two, the schema alone: no $_POST, the guard is inert, and the post is the old
        // layout's truncation, the Models fields with the whole carry-over dropped.
        $_POST = [];
        $cut = array_filter($posted, static fn(string $key): bool => Schema::fields()[$key]['section'] === 'models', ARRAY_FILTER_USE_KEY);
        $cut['models.default'] = 'changed-again';
        $this->assertArrayNotHasKey('provider.api_key', $cut);
        update_option('alpaca_bot_settings', $cut);
        $after = get_option('alpaca_bot_settings');
        $this->assertSame('changed-again', $after['models.default']);
        $this->assertSame('sk-REVIEW-SENTINEL-9f3a', $after['provider.api_key']);
        $this->assertSame('https://openrouter.ai/api/v1', $after['provider.base_url']);
        $this->assertSame(array_keys(Schema::fields()), array_keys($after));
    }

    /**
     * The overrides table over real core: a model id from the provider and an override value
     * from the option, both hostile, render escaped at every attribute and text node, and the
     * form posts the stored override back exactly as it was stored.
     */
    public function test_the_models_tab_escapes_model_ids_and_override_values_and_posts_them_back_intact(): void
    {
        $evilId = '"><script>alert(1)</script>';
        $evilValue = '"><img src=x onerror=alert(2)>';
        $this->fakeProvider();
        add_filter('alpaca_bot/models', static fn(): array => [Model::fromArray(['id' => $evilId, 'label' => 'x']), Model::fromArray(['id' => 'qwen3-vl:2b', 'label' => 'qwen'])]);
        $store = Plugin::instance()->get(Store::class);
        $store->replace(['models.num_ctx' => 4096, 'models.overrides' => ['qwen3-vl:2b' => ['num_ctx' => 2048, 'system' => $evilValue, 'tools' => Schema::TOOLS_OFF], 'gone-model' => ['temperature' => 0.2]]]);

        $_GET['tab'] = 'models';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<th scope="row">' . esc_html($evilId) . '</th>', $html);
        $this->assertStringContainsString('name="' . esc_attr('alpaca_bot_settings[models.overrides][' . $evilId . '][temperature]') . '"', $html);
        $this->assertStringContainsString('name="alpaca_bot_settings[models.overrides][qwen3-vl:2b][system]" value="' . esc_attr($evilValue) . '"', $html);
        $this->assertStringContainsString('<th scope="row">gone-model</th>', $html);
        $this->assertStringContainsString('<th>Context window (tokens)</th><th>Keep alive</th>', $html);
        // The tools override is a three-state select, and the stored state is the selected one.
        $this->assertStringContainsString('name="alpaca_bot_settings[models.overrides][qwen3-vl:2b][tools]"', $html);
        $this->assertStringContainsString('<option value="off" selected="selected">', $html);
        $this->assertStringContainsString('placeholder="4096"', $html);
        // Three table rows (the settings fields above the table are <th scope="row"> too).
        $this->assertSame(1, preg_match('#<tbody>(.*)</tbody>#s', $html, $body));
        $this->assertSame(3, substr_count($body[1], '<tr><th scope="row">'));

        $_POST = ['alpaca_bot_settings' => [], SettingsPage::END_MARKER => '1'];
        update_option('alpaca_bot_settings', self::postedFrom($html));
        $this->assertSame(
            ['qwen3-vl:2b' => ['num_ctx' => 2048, 'system' => $evilValue, 'tools' => Schema::TOOLS_OFF], 'gone-model' => ['temperature' => 0.2]],
            get_option('alpaca_bot_settings')['models.overrides'],
        );
    }

    /**
     * `$html` with the select whose id is `$id` set to `$value`, as a person picking that option
     * would leave it: what the form then posts is under whatever name the select carries, so a
     * test that saves through this proves the name as well as the value.
     */
    private static function choose(string $html, string $id, string $value): string
    {
        $found = preg_match('#(<select id="' . preg_quote($id, '#') . '"[^>]*>)(.*?)(</select>)#s', $html, $select);
        self::assertSame(1, $found, "no select #{$id}");
        // Core's selected() quotes with ' and the page's own tables with ": either is the one to clear.
        $options = str_replace([' selected="selected"', " selected='selected'"], '', $select[2]);
        $options = str_replace('<option value="' . $value . '">', '<option value="' . $value . '" selected="selected">', $options);
        self::assertStringContainsString('<option value="' . $value . '" selected="selected">', $options, "no option {$value} in #{$id}");
        return str_replace($select[0], $select[1] . $options . $select[3], $html);
    }

    /**
     * The form fields as a browser would post them: visible controls and hidden carry-overs
     * alike, `alpaca_bot_settings[a.b]`, `alpaca_bot_settings[a.b][id]` and
     * `alpaca_bot_settings[a.b][m][f]` names unpacked.
     *
     * @return array<string, mixed>
     */
    private static function postedFrom(string $html): array
    {
        $posted = [];
        preg_match_all('/<(input|textarea|select)\b([^>]*)>(.*?<\/\1>)?/s', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            if (!preg_match('/name="alpaca_bot_settings\[([^"]+)\]"/', $tag[2], $name)) {
                continue;
            }
            // A browser decodes the name attribute as it does any other: a model id with markup in it comes back as itself.
            $path = array_map(static fn(string $segment): string => html_entity_decode($segment, ENT_QUOTES), explode('][', $name[1]));
            if ($tag[1] === 'textarea') {
                $value = substr($tag[3] ?? '', 0, -strlen('</textarea>'));
            } elseif ($tag[1] === 'select') {
                preg_match('/<option value="([^"]*)" selected=["\']selected["\']/', $tag[3] ?? '', $sel);
                $value = $sel[1] ?? '';
            } else {
                preg_match('/value="([^"]*)"/', $tag[2], $val);
                $value = $val[1] ?? '';
                // A checkbox posts only when checked; its hidden 0 in front was already taken.
                if (str_contains($tag[2], 'type="checkbox"') && !str_contains($tag[2], 'checked')) {
                    continue;
                }
            }
            $value = html_entity_decode((string) $value, ENT_QUOTES);
            if (count($path) === 3) {
                $posted[$path[0]][$path[1]][$path[2]] = $value;
            } elseif (count($path) === 2 && $path[1] === '') {
                // `[key][]`: PHP appends, so a checkbox list posts as a list.
                $posted[$path[0]][] = $value;
            } elseif (count($path) === 2) {
                // `[key][id]`: a flat map, the access.mcp rows.
                $posted[$path[0]][$path[1]] = $value;
            } else {
                $posted[$path[0]] = $value;
            }
        }
        return $posted;
    }
}
