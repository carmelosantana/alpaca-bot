<?php

declare(strict_types=1);

use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Schema;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// Core calls the sanitize callback with the option *name* as its second argument, so the
// callback must be a closure that supplies the stored array itself: that is what lets a secret
// posted back as the mask keep the stored key instead of becoming the literal mask.
it('registers the option under the alpaca_bot group with a sanitize callback that keeps the stored key on the mask', function (): void {
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('add_settings_field')->justReturn(null);
    $opts = null;
    Functions\expect('register_setting')->once()->withArgs(function (string $group, string $option, array $o) use (&$opts): bool {
        $opts = $o;
        return $group === 'alpaca_bot' && $option === Plugin::OPTION;
    });
    Functions\when('get_option')->justReturn(['provider.api_key' => 'sk-stored', 'models.temperature' => 1.2, 'models.default' => 'kept']);
    settingsPage()->register();
    expect($opts['type'])->toBe('array')->and($opts['default'])->toBe(Schema::defaults());
    // What core does on options.php: the callback, the posted array, the option name.
    $out = ($opts['sanitize_callback'])(['provider.api_key' => Schema::MASK, 'models.temperature' => '0.3'], Plugin::OPTION);
    expect($out['provider.api_key'])->toBe('sk-stored')->and($out['models.temperature'])->toBe(0.3)
        // A field the post does not name keeps its stored value: the page posts every field, and a post that arrives short loses nothing.
        ->and($out['models.default'])->toBe('kept');
    expect(($opts['sanitize_callback'])('', Plugin::OPTION)['models.num_ctx'])->toBe(Schema::defaults()['models.num_ctx'])
        ->and(($opts['sanitize_callback'])(['provider.api_key' => ''], Plugin::OPTION)['provider.api_key'])->toBe('');
});

// PHP's max_input_vars cuts a long form off at the tail and says nothing to userland. The page
// ends the form with a marker input; a post that reached the sanitize callback without it was
// cut, and is refused whole with a notice rather than saved in part. Outside a form post (REST,
// WP-CLI, a test calling the callback) there is no marker to miss and the guard is inert.
it('refuses a form post that PHP cut short, keeping the option as it was and telling the admin why', function (): void {
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('add_settings_field')->justReturn(null);
    $opts = null;
    Functions\expect('register_setting')->once()->withArgs(function (string $group, string $option, array $o) use (&$opts): bool {
        $opts = $o;
        return true;
    });
    Functions\when('get_option')->justReturn(['provider.api_key' => 'sk-stored', 'models.default' => 'kept', 'provider.base_url' => 'https://openrouter.ai/api/v1']);
    Functions\expect('add_settings_error')->once()->withArgs(fn(string $setting, string $code, string $message): bool => $setting === Plugin::OPTION && $code === 'truncated' && str_contains($message, 'max_input_vars'));
    settingsPage()->register();
    $_POST = [Plugin::OPTION => ['models.default' => 'changed']];
    $out = ($opts['sanitize_callback'])(['models.default' => 'changed'], Plugin::OPTION);
    expect($out['models.default'])->toBe('kept')
        ->and($out['provider.api_key'])->toBe('sk-stored')
        ->and($out['provider.base_url'])->toBe('https://openrouter.ai/api/v1')
        ->and(array_keys($out))->toBe(array_keys(Schema::fields()));
    $_POST[SettingsPage::END_MARKER] = '1';
    expect(($opts['sanitize_callback'])(['models.default' => 'changed'], Plugin::OPTION)['models.default'])->toBe('changed');
    $_POST = [];
});

// Every schema field but `access.mcp`, which has no control of its own: a row per MCP server
// stands in for it, and with no server there is none.
it('adds a section per schema section on its own page and a field per schema field but the MCP map on that page', function (): void {
    Functions\when('register_setting')->justReturn(null);
    $sections = [];
    $fields = [];
    Functions\expect('add_settings_section')->times(count(Schema::sections()))->withArgs(function (string $id, string $title, callable $cb, string $page) use (&$sections): bool {
        $sections[$page] = $id;
        return true;
    });
    Functions\expect('add_settings_field')->times(count(Schema::fields()) - 1)->withArgs(function (string $id, string $title, callable $cb, string $page, string $section, array $args) use (&$fields): bool {
        $fields[$id] = [$page, $section, $args];
        return true;
    });
    settingsPage()->register();
    expect($sections)->toHaveKey(SettingsPage::SLUG . '-privacy')
        ->and($sections[SettingsPage::SLUG . '-privacy'])->toBe('alpaca_bot_privacy')
        ->and($fields['alpaca_bot_models.temperature'][0])->toBe(SettingsPage::SLUG . '-models')
        ->and($fields['alpaca_bot_models.temperature'][1])->toBe('alpaca_bot_models')
        ->and($fields['alpaca_bot_models.temperature'][2]['label_for'])->toBe('ab-models-temperature')
        // The overrides table has no single control to label, and a checkbox carries its own label.
        ->and($fields['alpaca_bot_models.overrides'][2])->not->toHaveKey('label_for')
        ->and($fields['alpaca_bot_chat.spellcheck'][2])->not->toHaveKey('label_for')
        // A checkbox-list has a box per option and no single control for a label to point at.
        ->and($fields['alpaca_bot_toolkits.enabled'][2])->not->toHaveKey('label_for')
        ->and($fields)->not->toHaveKey('alpaca_bot_access.mcp');
});

// The table's model ids come from the provider's JSON and its values from the option: both are
// escaped at every attribute and text node, the columns carry the labels of the global fields
// they override, and the caption names where each global lives.
it('renders the overrides table with every model id and stored value escaped, labelled like the fields it overrides', function (): void {
    Functions\when('register_setting')->justReturn(null);
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    $evilId = '"><script>alert(1)</script>';
    $evilValue = '"><img src=x onerror=alert(2)>';
    Functions\when('get_transient')->alias(fn(string $key): mixed => $key === ModelCatalog::TRANSIENT ? [['id' => $evilId, 'label' => 'x'], ['id' => 'llama3.2', 'label' => 'llama3.2']] : false);
    $render = null;
    Functions\expect('add_settings_field')->times(count(Schema::fields()) - 1)->withArgs(function (string $id, string $title, callable $cb) use (&$render): bool {
        if ($id === 'alpaca_bot_models.overrides') {
            $render = $cb;
        }
        return true;
    });
    settingsPage(['models.num_ctx' => 4096, 'models.overrides' => ['llama3.2' => ['system' => $evilValue], 'gone-model' => ['num_ctx' => 2048]]])->register();
    ob_start();
    $render();
    $html = (string) ob_get_clean();
    $escapedId = htmlspecialchars($evilId, ENT_QUOTES);
    expect($html)->not->toContain('<script')->not->toContain('<img')
        // The wrapper is what Assets' inline rules hang off: it scopes them to this table and
        // scrolls it sideways where the row is narrower than the six columns at their narrowest.
        // The captions sit outside it, so the scrollbar is under the table and not under two paragraphs.
        ->toContain('<div class="ab-overrides"><table class="widefat striped"><thead>')
        ->toContain('</table></div><p class="description">')
        ->toContain('<th scope="row">' . $escapedId . '</th>')
        ->toContain('name="alpaca_bot_settings[models.overrides][' . $escapedId . '][temperature]"')
        ->toContain('name="alpaca_bot_settings[models.overrides][llama3.2][system]" value="' . htmlspecialchars($evilValue, ENT_QUOTES) . '"')
        // A stored override for a model the catalog no longer lists still gets a row, so it can be seen and cleared.
        ->toContain('<th scope="row">gone-model</th>')
        ->toContain('name="alpaca_bot_settings[models.overrides][gone-model][num_ctx]" value="2048"')
        ->toContain('placeholder="4096"')
        ->toContain('<th>Context window (tokens)</th><th>Keep alive</th>')
        // The system prompt's heading carries the class Assets sizes that column by: it asks for
        // 35% of the table and shrinks when the row is narrow.
        ->toContain('<th>Keep alive</th><th class="ab-overrides__system">System prompt</th>')
        ->not->toContain('<th>num_ctx</th>')
        ->toContain('Chat tab');
    expect(substr_count($html, '<tr><th scope="row">'))->toBe(3);
});

// Core derives a submenu page's screen id (and its admin_enqueue_scripts hook suffix) from the
// parent's *translated* menu title, so the id is asked of core rather than spelled; HelpTabs and
// Assets both gate on it. The derivation runs against real core, in a translated locale, in
// tests/Integration/HelpTabsTest.php. The `function_exists()` fallback in screen() has no
// coverage: Brain Monkey declares a stubbed function with eval() (FunctionStub.php:51) and PHP
// cannot undeclare it, so whether an unstubbed call here would take that branch depends on which
// tests ran first, and the integration suite runs against real core, where the function is
// defined.
it('names its screen as core derives it from the page slug and its parent, and follows a new answer rather than caching one', function (): void {
    Functions\when('get_plugin_page_hookname')->alias(static fn(string $page, string $parent): string => 'robot-alpaca_page_' . $page . '_under_' . $parent);
    expect(SettingsPage::screen())->toBe('robot-alpaca_page_alpaca-bot-settings_under_alpaca-bot');
    Functions\when('get_plugin_page_hookname')->alias(static fn(string $page, string $parent): string => 'alpaca-bot_page_' . $page);
    expect(SettingsPage::screen())->toBe('alpaca-bot_page_alpaca-bot-settings');
});

it('renders the active tab and carries every other tab as hidden inputs with the secret masked', function (): void {
    Functions\when('settings_errors')->justReturn(null);
    Functions\when('settings_fields')->alias(function (string $group): void { echo '<input type="hidden" name="option_page" value="' . $group . '">'; });
    Functions\when('do_settings_sections')->alias(function (string $page): void { echo "<!-- sections:{$page} -->"; });
    Functions\when('submit_button')->alias(function (): void { echo '<input type="submit">'; });
    Functions\when('admin_url')->alias(fn(string $p = '') => 'http://x/wp-admin/' . $p);
    Functions\when('add_query_arg')->alias(fn(array $args, string $url) => $url . '?' . http_build_query($args));
    Functions\when('sanitize_key')->alias(fn(string $k) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)));
    $_GET['tab'] = 'chat';
    ob_start();
    settingsPage(['provider.api_key' => 'sk-real-key', 'provider.base_url' => 'http://ollama:11434/v1', 'models.overrides' => ['m' => ['num_ctx' => 4096]]])->render();
    $html = (string) ob_get_clean();
    unset($_GET['tab']);
    expect($html)->toContain('<!-- sections:' . SettingsPage::SLUG . '-chat -->')
        ->toContain('name="option_page" value="alpaca_bot"')
        ->toContain('action="options.php"')
        ->toContain('<input type="hidden" name="alpaca_bot_settings[provider.base_url]" value="http://ollama:11434/v1">')
        ->toContain('<input type="hidden" name="alpaca_bot_settings[provider.api_key]" value="' . Schema::MASK . '">')
        ->toContain('name="alpaca_bot_settings[models.overrides][m][num_ctx]" value="4096"')
        ->not->toContain('sk-real-key')
        ->not->toContain('name="alpaca_bot_settings[chat.welcome]"')
        ->toContain('nav-tab-active">Chat<');
    expect(preg_match_all('/class="nav-tab( nav-tab-active)?"/', $html))->toBe(count(Schema::sections()));
    // The carry-over comes before the tab's own fields, so a post PHP cuts short loses the end of
    // the visible tab, never the key or the URL; the end marker is the last input before submit.
    $carry = (int) strpos($html, 'name="alpaca_bot_settings[provider.api_key]"');
    $sections = (int) strpos($html, '<!-- sections:');
    $marker = (int) strpos($html, 'name="' . SettingsPage::END_MARKER . '" value="1"');
    expect($carry)->toBeGreaterThan(0)->toBeLessThan($sections)
        ->and($marker)->toBeGreaterThan($sections)->toBeLessThan((int) strpos($html, '<input type="submit">'));
});

it('falls back to the provider tab for an unknown or missing tab', function (): void {
    Functions\when('settings_errors')->justReturn(null);
    Functions\when('settings_fields')->justReturn(null);
    Functions\when('submit_button')->justReturn(null);
    Functions\when('admin_url')->justReturn('http://x/wp-admin/admin.php');
    Functions\when('add_query_arg')->justReturn('http://x/');
    Functions\when('sanitize_key')->returnArg();
    $pages = [];
    Functions\when('do_settings_sections')->alias(function (string $page) use (&$pages): void { $pages[] = $page; });
    $_GET['tab'] = 'nope"><script>';
    ob_start();
    settingsPage()->render();
    ob_end_clean();
    unset($_GET['tab']);
    ob_start();
    settingsPage()->render();
    $html = (string) ob_get_clean();
    expect($pages)->toBe([SettingsPage::SLUG . '-provider', SettingsPage::SLUG . '-provider'])
        ->and($html)->not->toContain('name="alpaca_bot_settings[provider.base_url]"');
});

// The operator's lever needs a control. It is a three-state select, not a checkbox: the third
// state is "whatever the catalogue says", and a checkbox cannot say that.
it('renders the tools override as a three-state select per model, with the stored state selected', function (): void {
    Functions\when('register_setting')->justReturn(null);
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('get_transient')->alias(fn(string $key): mixed => $key === ModelCatalog::TRANSIENT ? [['id' => 'faker:2b', 'label' => 'faker'], ['id' => 'llama3.2', 'label' => 'llama3.2']] : false);
    $render = null;
    Functions\expect('add_settings_field')->times(count(Schema::fields()) - 1)->withArgs(function (string $id, string $title, callable $cb) use (&$render): bool {
        if ($id === 'alpaca_bot_models.overrides') {
            $render = $cb;
        }
        return true;
    });
    settingsPage(['models.overrides' => ['faker:2b' => ['tools' => Schema::TOOLS_OFF]]])->register();
    ob_start();
    $render();
    $html = (string) ob_get_clean();

    expect($html)->toContain('<th>Tools</th>')
        ->toContain('<select name="alpaca_bot_settings[models.overrides][faker:2b][tools]">')
        ->toContain('<select name="alpaca_bot_settings[models.overrides][llama3.2][tools]">')
        // Three options in every row, inherit first and blank so a save stores nothing for it.
        ->toContain('<option value="">')
        ->toContain('<option value="on">')
        ->toContain('<option value="off">')
        // The stored state is the selected one, and only on the row that stores it.
        ->toContain('<option value="off" selected="selected">')
        ->and(substr_count($html, 'selected="selected"'))->toBe(2)
        ->and(substr_count($html, '[tools]"'))->toBe(2);
    // The caption tells an operator what the switch is for, in the terms they will hit it in,
    // and says where "Always on" stops: it offers the toolkits the Tools tab enables, so with
    // none enabled it offers nothing.
    expect($html)->toContain('write the tool call out as text')
        ->toContain('no tools are enabled on the Tools tab');
});

// A hand-edited option, a migration, or an `option_alpaca_bot_settings` filter can put anything
// in a cell. The `$cell` closure guards its cast and Store::toolsOverride() guards its own; the
// select must too, or one unreadable row raises "Array to string conversion" while rendering the
// Models tab. Unreadable is inherit, the same answer every other cell gives.
it('renders an unreadable tools cell as inherit rather than casting it to a string', function (): void {
    Functions\when('register_setting')->justReturn(null);
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('get_transient')->alias(fn(string $key): mixed => $key === ModelCatalog::TRANSIENT ? [['id' => 'faker:2b', 'label' => 'faker']] : false);
    $render = null;
    Functions\expect('add_settings_field')->times(count(Schema::fields()) - 1)->withArgs(function (string $id, string $title, callable $cb) use (&$render): bool {
        if ($id === 'alpaca_bot_models.overrides') {
            $render = $cb;
        }
        return true;
    });
    settingsPage(['models.overrides' => ['faker:2b' => ['tools' => ['on']]]])->register();

    $raised = [];
    set_error_handler(static function (int $no, string $message) use (&$raised): bool {
        $raised[] = $message;
        return true;
    });
    ob_start();
    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
        restore_error_handler();
    }

    expect($raised)->toBe([])
        ->and($html)->toContain('<select name="alpaca_bot_settings[models.overrides][faker:2b][tools]">')
        // Inherit, and only inherit: the same row a readable cell would render for a blank.
        ->and($html)->toContain('<option value="" selected="selected">')
        ->and(substr_count($html, 'selected="selected"'))->toBe(1);
});

// ---------------------------------------------------------------- the Access tab
// The Access rows are the one field type on this page that shows something the option does not
// hold: what a capability filter did to the row. Every row is a select over the five
// capabilities, on the stored value; a line under it says when code has changed that value, and
// to what. These drive register()'s field callbacks (settingsFields()), which is how the page
// renders one row.

it('renders every Access row as its capability select on the stored value, and says nothing about code when nothing changed it', function (): void {
    stubSelected();
    Functions\when('get_current_user_id')->justReturn(7);
    $fields = settingsFields(['access.chat' => 'publish_posts']);

    $chat = ($fields['alpaca_bot_access.chat']['render'])();
    expect($chat)->toContain('<select id="ab-access-chat" name="alpaca_bot_settings[access.chat]">')
        ->toContain('<option value="publish_posts" selected="selected">Authors and up</option>')
        ->toContain('<option value="manage_options">Administrators</option>')
        ->not->toContain('Set in code');
    foreach (['tool.web_fetch', 'tool.summarize', 'tool.draft_post', 'tool.abilities', 'settings.read', 'settings.write', 'shortcode'] as $row) {
        $html = ($fields['alpaca_bot_access.' . $row]['render'])();
        expect($html)->toContain('<select id="ab-access-' . str_replace('.', '-', $row) . '" name="alpaca_bot_settings[access.' . $row . ']">')
            ->not->toContain('Set in code');
    }
});

// Merge point 5: a row's filter is asked with the arguments the runtime passes it, in the same
// shape, so a listener registered for its row's arguments is called with them here too.
it('marks a tool row a filter changes as set in code, asking the filter with a user id as a turn does', function (): void {
    stubSelected();
    Functions\when('get_current_user_id')->justReturn(7);
    Filters\expectApplied('alpaca_bot/capability/tool/web_fetch')->atLeast()->once()->with('edit_posts', 7)->andReturn('manage_options');

    $html = (settingsFields(['access.tool.web_fetch' => 'edit_posts'])['alpaca_bot_access.tool.web_fetch']['render'])();

    expect($html)->toContain('<strong>Set in code</strong>: a filter changes this to Administrators (<code>manage_options</code>).')
        // The select is still there and still saves, on what the site stored.
        ->toContain('name="alpaca_bot_settings[access.tool.web_fetch]"')
        ->toContain('<option value="edit_posts" selected="selected">')
        ->toContain('is what applies once no filter changes it');
});

it('asks a settings row with the REST request its route authorises, and a shortcode row with a post id and the shortcode tag', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/capability/settings/write')->atLeast()->once()
        ->with('manage_options', Mockery::on(static fn(mixed $r): bool => $r instanceof WP_REST_Request && $r->get_method() === 'PUT' && $r->get_route() === '/alpaca-bot/v1/settings'))
        ->andReturn('edit_others_posts');
    Filters\expectApplied('alpaca_bot/capability/settings/read')->atLeast()->once()
        ->with('manage_options', Mockery::on(static fn(mixed $r): bool => $r instanceof WP_REST_Request && $r->get_method() === 'GET' && $r->get_route() === '/alpaca-bot/v1/settings'))
        ->andReturn('manage_options');
    // A capability that is not one of the five is named as itself.
    Filters\expectApplied('alpaca_bot/capability/shortcode')->atLeast()->once()->with('edit_posts', 0, 'alpacabot')->andReturn('exist');
    $fields = settingsFields();

    expect(($fields['alpaca_bot_access.settings.write']['render'])())->toContain('a filter changes this to Editors and up (<code>edit_others_posts</code>).')
        ->and(($fields['alpaca_bot_access.settings.read']['render'])())->not->toContain('Set in code')
        ->and(($fields['alpaca_bot_access.shortcode']['render'])())->toContain('a filter changes this to <code>exist</code>.');
});

it('reports a row whose filter throws as set in code without a figure, and still renders its select', function (): void {
    stubSelected();
    Functions\when('get_current_user_id')->justReturn(7);
    Filters\expectApplied('alpaca_bot/capability/tool/summarize')->zeroOrMoreTimes()->andReturnUsing(static function (): never {
        throw new ArgumentCountError('Too few arguments to function {closure}(), 2 passed and exactly 3 expected');
    });

    $html = (settingsFields()['alpaca_bot_access.tool.summarize']['render'])();

    expect($html)->toContain('<select id="ab-access-tool-summarize"')
        ->toContain('<strong>Set in code</strong>: a filter decides this, and asking it from this page failed')
        ->not->toContain('a filter changes this to');
});

// Merge point 4: the Chat row has no filter of its own (Access::effective('chat') applies none),
// so asking Access would always say "not set in code". The two surfaces that read the row are
// asked instead, each with its own hook, and the line says which one a filter changed.
it('asks the Chat row of each surface that reads it, and names the surface a filter changed', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->atLeast()->once()->with('edit_posts')->andReturn('read');
    Filters\expectApplied('alpaca_bot/capability/chat')->atLeast()->once()
        ->with('edit_posts', Mockery::on(static fn(mixed $r): bool => $r instanceof WP_REST_Request && $r->get_method() === 'POST' && $r->get_route() === '/alpaca-bot/v1/chat'))
        ->andReturn('edit_posts');

    $screen = (settingsFields()['alpaca_bot_access.chat']['render'])();

    expect($screen)->toContain('<strong>Set in code</strong> for the chat screen, its panel on other admin screens and the block editor sidebar: a filter changes this to Any logged-in user (<code>read</code>).')
        ->not->toContain('POST /chat');
});

it('says when a filter changed the Chat row for the chat REST route and not for the screen', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->atLeast()->once()->with('edit_posts')->andReturn('edit_posts');
    Filters\expectApplied('alpaca_bot/capability/chat')->atLeast()->once()->andReturn('manage_options');

    $api = (settingsFields()['alpaca_bot_access.chat']['render'])();

    expect($api)->toContain('<strong>Set in code</strong> for the chat REST route (<code>POST /chat</code>): a filter changes this to Administrators (<code>manage_options</code>).')
        ->not->toContain('for the chat screen');
});

it('adds an Access row for each MCP server the settings hold, and none for the access.mcp map itself', function (): void {
    stubSelected();
    Functions\when('get_current_user_id')->justReturn(7);
    $none = settingsFields();
    expect($none)->not->toHaveKey('alpaca_bot_access.mcp')
        ->and(array_filter(array_keys($none), static fn(string $id): bool => str_starts_with($id, 'alpaca_bot_access.mcp')))->toBe([]);

    Filters\expectApplied('alpaca_bot/capability/mcp/wiki')->atLeast()->once()->with('publish_posts', 7)->andReturn('edit_others_posts');
    $fields = settingsFields([
        'toolkits.mcp_servers' => [['id' => 'docs', 'url' => 'https://mcp.example.test/mcp'], ['url' => 'https://nameless.example.test/'], ['id' => 'wiki']],
        'access.mcp' => ['wiki' => 'publish_posts'],
    ]);

    expect($fields)->not->toHaveKey('alpaca_bot_access.mcp')
        ->and(array_values(array_filter(array_keys($fields), static fn(string $id): bool => str_starts_with($id, 'alpaca_bot_access.mcp'))))->toBe(['alpaca_bot_access.mcp.docs', 'alpaca_bot_access.mcp.wiki'])
        ->and($fields['alpaca_bot_access.mcp.docs']['title'])->toBe('MCP server: docs');
    $docs = ($fields['alpaca_bot_access.mcp.docs']['render'])();
    // Posted into the access.mcp map, which is where Access::stored('mcp.docs') reads it.
    expect($docs)->toContain('<select id="ab-access-mcp-docs" name="alpaca_bot_settings[access.mcp][docs]">')
        // A server nobody has chosen for is an administrator's (Kanboard #4370).
        ->toContain('<option value="manage_options" selected="selected">')
        ->not->toContain('Set in code');
    expect(($fields['alpaca_bot_access.mcp.wiki']['render'])())->toContain('<option value="publish_posts" selected="selected">')
        ->toContain('a filter changes this to Editors and up (<code>edit_others_posts</code>).');
});

// A server id comes from the settings, so it may be anything. It reaches the page escaped in the
// row's title (core prints a field title as it is given), its id and its field name; and an id a
// form cannot post back as itself gets no select at all. PHP ends a bracketed name segment at
// the first `]`, so `x]"<y` would post as `x` and set another server's row.
it('escapes a hostile MCP server id everywhere it reaches the page, and gives no select to one a form cannot post back', function (): void {
    stubSelected();
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    $evil = 'a"<b>c';
    $fields = settingsFields(['toolkits.mcp_servers' => [['id' => $evil], ['id' => 'x]"<y'], ['id' => "nul\0byte"], ['id' => '12']]]);

    expect(array_values(array_filter(array_keys($fields), static fn(string $id): bool => str_starts_with($id, 'alpaca_bot_access.mcp'))))->toBe(['alpaca_bot_access.mcp.' . $evil]);
    $row = $fields['alpaca_bot_access.mcp.' . $evil];
    $html = ($row['render'])();
    expect($row['title'])->toBe('MCP server: a&quot;&lt;b&gt;c')
        ->and($html)->toContain('<select id="ab-access-mcp-a&quot;&lt;b&gt;c" name="alpaca_bot_settings[access.mcp][a&quot;&lt;b&gt;c]">')
        ->not->toContain('<b>');
});

// Schema::sanitize() keeps the stored value of a key a post leaves out, so a save from another
// tab keeps the whole access.mcp map by not posting it. The Access tab posts the map (one select
// per server), so it carries the entries it has no select for, or saving it would drop them.
it('posts nothing for the access.mcp map from another tab, and carries the entries with no select from the Access tab', function (): void {
    Functions\when('settings_errors')->justReturn(null);
    Functions\when('settings_fields')->justReturn(null);
    Functions\when('do_settings_sections')->alias(function (string $page): void { echo "<!-- sections:{$page} -->"; });
    Functions\when('submit_button')->justReturn(null);
    Functions\when('admin_url')->justReturn('http://x/wp-admin/admin.php');
    Functions\when('add_query_arg')->justReturn('http://x/');
    Functions\when('sanitize_key')->returnArg();
    $settings = ['toolkits.mcp_servers' => [['id' => 'docs']], 'access.mcp' => ['docs' => 'read', 'gone' => 'publish_posts', 'x]y' => 'read']];

    $_GET['tab'] = 'chat';
    ob_start();
    settingsPage($settings)->render();
    $other = (string) ob_get_clean();
    $_GET['tab'] = 'access';
    ob_start();
    settingsPage($settings)->render();
    $access = (string) ob_get_clean();
    unset($_GET['tab']);

    expect($other)->not->toContain('alpaca_bot_settings[access.mcp]')
        // The Access tab's schema rows are carried from another tab like any other field; only the map is left out.
        ->toContain('name="alpaca_bot_settings[access.chat]"');
    expect($access)->toContain('<input type="hidden" name="alpaca_bot_settings[access.mcp][gone]" value="publish_posts">')
        // docs has its select on this tab; a second input under its name would race it.
        ->not->toContain('name="alpaca_bot_settings[access.mcp][docs]"')
        // An id a form cannot post back as itself is not carried either: it would post as `x`.
        ->not->toContain('alpaca_bot_settings[access.mcp][x]')
        ->and(strpos($access, '[access.mcp][gone]'))->toBeLessThan(strpos($access, '<!-- sections:'));
});
