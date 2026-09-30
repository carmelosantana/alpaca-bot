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
    $fields = settingsFields(['access.chat' => 'publish_posts'], null, chatRouteControllers());

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
    // 0.5's key runs first for both settings rows, with the same built request (docs/api.md says so).
    Filters\expectApplied('alpaca_bot/capability/settings')->atLeast()->twice()
        ->with('manage_options', Mockery::on(static fn(mixed $r): bool => $r instanceof WP_REST_Request && in_array($r->get_method(), ['GET', 'PUT'], true) && $r->get_route() === '/alpaca-bot/v1/settings'))
        ->andReturn('manage_options');
    // A capability that is not one of the five is named as itself.
    Filters\expectApplied('alpaca_bot/capability/shortcode')->atLeast()->once()->with('edit_posts', 0, 'alpacabot')->andReturn('exist');
    Filters\expectApplied('alpaca_bot/capability/shortcode')->atLeast()->once()->with('edit_posts', 0, 'alpacabot_agent')->andReturnFirstArg();
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

    $screen = (settingsFields([], null, chatRouteControllers())['alpaca_bot_access.chat']['render'])();

    expect($screen)->toContain('<strong>Set in code</strong> for the chat screen, its panel on other admin screens and the block editor sidebar: a filter changes this to Any logged-in user (<code>read</code>).')
        ->not->toContain('POST /chat');
});

it('says when a filter changed the Chat row for the chat REST route and not for the screen', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->atLeast()->once()->with('edit_posts')->andReturn('edit_posts');
    Filters\expectApplied('alpaca_bot/capability/chat')->atLeast()->once()->andReturn('manage_options');

    $api = (settingsFields([], null, chatRouteControllers())['alpaca_bot_access.chat']['render'])();

    expect($api)->toContain('<strong>Set in code</strong> for the chat REST route (<code>POST /chat</code>): a filter changes this to Administrators (<code>manage_options</code>).')
        ->not->toContain('for the chat screen');
});

// Kanboard #4537: the Chat row is every REST route that declares Controller::CHAT, each under its
// own `alpaca_bot/capability/{route}` key, so a site that filters only one of them other than
// `chat` has moved the row for that route. Each (key, verb) pair a Chat-row route declares is
// asked once, with a request of that verb and the path of the first route declaring it; a route
// declaring a capability of its own is not the Chat row's and is not asked.
it('asks every REST route that follows the Chat row, once per route key and verb, and names the one a filter changed', function (): void {
    stubSelected();
    $request = static fn(string $method, string $route): Mockery\Matcher\Closure => Mockery::on(static fn(mixed $r): bool => $r instanceof WP_REST_Request && $r->get_method() === $method && $r->get_route() === $route);
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->once()->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/chat')->once()->with('edit_posts', $request('POST', '/alpaca-bot/v1/chat'))->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/chat/stream')->once()->with('edit_posts', $request('GET', '/alpaca-bot/v1/chat/{id}/stream'))->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/conversations')->once()->with('edit_posts', $request('GET', '/alpaca-bot/v1/conversations'))->andReturn('read');
    Filters\expectApplied('alpaca_bot/capability/conversations')->once()->with('edit_posts', $request('DELETE', '/alpaca-bot/v1/conversations'))->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/view/mcp-tools')->never();

    $html = (settingsFields([], null, chatRouteControllers())['alpaca_bot_access.chat']['render'])();

    expect($html)->toContain('<strong>Set in code</strong> for the chat REST route (<code>GET /conversations</code>): a filter changes this to Any logged-in user (<code>read</code>).')
        ->not->toContain('POST /chat')
        ->not->toContain('for the chat screen')
        ->and(substr_count($html, 'Set in code'))->toBe(1);
});

it('lists the REST routes a filter changed to the same capability in one note, and each other answer in a note of its own', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/capability/chat')->once()->andReturn('read');
    Filters\expectApplied('alpaca_bot/capability/chat/stream')->once()->andReturn('read');
    Filters\expectApplied('alpaca_bot/capability/conversations')->twice()->andReturn('manage_options');

    $html = (settingsFields([], null, chatRouteControllers())['alpaca_bot_access.chat']['render'])();

    expect($html)->toContain('<strong>Set in code</strong> for the chat REST routes (<code>POST /chat</code>, <code>GET /chat/{id}/stream</code>): a filter changes this to Any logged-in user (<code>read</code>).')
        ->toContain('<strong>Set in code</strong> for the chat REST routes (<code>GET /conversations</code>, <code>DELETE /conversations</code>): a filter changes this to Administrators (<code>manage_options</code>).')
        ->and(substr_count($html, 'Set in code'))->toBe(2);
});

it('reports a chat route whose filter throws as set in code without a figure, and asks the other routes anyway', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/capability/chat/stream')->once()->andReturnUsing(static function (): never {
        throw new ArgumentCountError('Too few arguments to function {closure}(), 2 passed and exactly 3 expected');
    });
    Filters\expectApplied('alpaca_bot/capability/conversations')->twice()->andReturn('read');

    $html = (settingsFields([], null, chatRouteControllers())['alpaca_bot_access.chat']['render'])();

    expect($html)->toContain('<select id="ab-access-chat"')
        ->toContain('<strong>Set in code</strong> for the chat REST route (<code>GET /chat/{id}/stream</code>): a filter decides this, and asking it from this page failed')
        ->toContain('for the chat REST routes (<code>GET /conversations</code>, <code>DELETE /conversations</code>): a filter changes this to Any logged-in user (<code>read</code>).');
});

// docs/api.md's own example: a filter that tightens only the DELETE of the history, off the
// request's verb. Asking each key with one verb would hand it a GET and miss it.
it('asks each verb a chat route key is gated under, so a filter that tightens only DELETE /conversations gets a note', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/capability/conversations')->twice()->andReturnUsing(static fn(string $cap, WP_REST_Request $r): string => $r->get_method() === 'DELETE' ? 'manage_options' : $cap);

    $html = (settingsFields([], null, chatRouteControllers())['alpaca_bot_access.chat']['render'])();

    expect($html)->toContain('<strong>Set in code</strong> for the chat REST route (<code>DELETE /conversations</code>): a filter changes this to Administrators (<code>manage_options</code>).')
        ->not->toContain('GET /conversations')
        ->and(substr_count($html, 'Set in code'))->toBe(1);
});

// The controllers come through `alpaca_bot/rest/controllers` (Plugin::controllers()), which is a
// site's code as much as a capability filter is.
it('says the chat REST routes could not be asked when listing the controllers throws, and still asks the menu', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/admin/menu_capability')->once()->andReturn('read');

    $html = (settingsFields([], null, static function (): never {
        throw new RuntimeException('a controllers filter broke');
    })['alpaca_bot_access.chat']['render'])();

    expect($html)->toContain('<select id="ab-access-chat"')
        ->toContain('<strong>Set in code</strong> for the chat REST routes: a filter decides this, and asking it from this page failed')
        ->toContain('for the chat screen, its panel on other admin screens and the block editor sidebar: a filter changes this to Any logged-in user');
});

// The list is the plugin's own route declarations, not a copy of them: every route key a
// controller declares Controller::CHAT under is asked, and nothing else.
it('asks the chat route keys the plugin\'s own controllers declare', function (): void {
    stubSelected();
    $h = pipelineWith(null);
    $controllers = static fn(): array => [
        new AlpacaBot\Rest\ChatController($h->pipeline),
        new AlpacaBot\Rest\StreamController($h->pipeline, $h->store),
        new AlpacaBot\Rest\ConversationsController(new AlpacaBot\Chat\ConversationStore($h->store), $h->store),
        new AlpacaBot\Rest\ModelsController($h->catalog, $h->store),
        new AlpacaBot\Rest\SettingsController($h->store),
        new AlpacaBot\Rest\UsageController($h->meter, $h->store),
        new AlpacaBot\Rest\ViewController(new AlpacaBot\Chat\ConversationStore($h->store), $h->store, $h->catalog, new AlpacaBot\View\Markdown(), new AlpacaBot\Chat\UserPrefs()),
    ];
    $asked = [];
    Functions\when('apply_filters')->alias(static function (string $hook, mixed $value, mixed ...$args) use (&$asked): mixed {
        if (str_starts_with($hook, 'alpaca_bot/capability/')) {
            $asked[] = $args[0]->get_method() . ' ' . substr($hook, strlen('alpaca_bot/capability/'));
            return 'read';
        }
        return $value;
    });

    $html = (settingsFields([], null, $controllers)['alpaca_bot_access.chat']['render'])();

    expect($asked)->toBe([
        'POST chat', 'GET chat/stream', 'GET conversations', 'DELETE conversations', 'GET models', 'GET usage',
        'GET view/messages', 'GET view/history', 'GET view/models', 'POST view/default-model', 'GET view/bubble', 'POST view/bubble', 'GET view/panel', 'POST view/drawer',
    ])
        ->and($html)->toContain('for the chat REST routes (<code>POST /chat</code>, <code>GET /chat/{id}/stream</code>, <code>GET /conversations</code>, <code>DELETE /conversations</code>, <code>GET /models</code>, <code>GET /usage</code>, <code>GET /view/messages/{id}</code>,')
        ->toContain('<code>GET /view/bubble</code>, <code>POST /view/bubble</code>');
});

// The Shortcodes row governs both shortcodes, and Shortcodes\Chat hands the row's filter the tag
// it is rendering, so a filter can move one and not the other: each tag is asked, once.
it('asks the Shortcodes row for each shortcode, and names the one a filter changed', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/capability/shortcode')->once()->with('edit_posts', 0, 'alpacabot')->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/capability/shortcode')->once()->with('edit_posts', 0, 'alpacabot_agent')->andReturn('manage_options');

    $html = (settingsFields()['alpaca_bot_access.shortcode']['render'])();

    expect($html)->toContain('<strong>Set in code</strong> for the shortcode <code>[alpacabot_agent]</code>: a filter changes this to Administrators (<code>manage_options</code>).')
        ->not->toContain('<code>[alpacabot]</code>')
        ->and(substr_count($html, 'Set in code'))->toBe(1);
});

it('reports a Shortcodes filter that throws as set in code without a figure, for both shortcodes, and still renders the select', function (): void {
    stubSelected();
    Filters\expectApplied('alpaca_bot/capability/shortcode')->twice()->andReturnUsing(static function (): never {
        throw new RuntimeException('broken');
    });

    $html = (settingsFields()['alpaca_bot_access.shortcode']['render'])();

    expect($html)->toContain('<select id="ab-access-shortcode"')
        ->toContain('<strong>Set in code</strong> for the shortcodes <code>[alpacabot]</code>, <code>[alpacabot_agent]</code>: a filter decides this, and asking it from this page failed');
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

// A server id comes from the settings, and an option edited by hand may hold anything there. The
// Access tab gives a select only to an id Schema::isMcpId() admits, the rule every stored server
// id and every access.mcp key is held to, so a select is never shown that a save would then drop
// (R73). The rule's characters, `[a-z0-9_]`, all come back from a browser and PHP's form parser
// as themselves; each refused id below is one that the old, wider allowlist admitted or that
// changes on the way back: `x]"<y` posts as `x`, `lf\nx` as `lf\r\nx`, `12` as an int key.
it('gives a select only to a server id the id rule admits, and escapes the row anyway', function (): void {
    stubSelected();
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    $refused = ['a"<b>c', 'x]"<y', "nul\0byte", "lf\nx", "crlf\r\nx", "tab\tx", 'ent&amp;x', "e\u{e9}x", '12', '1abc', "trail\n", ' ', 'two words', 'Upper', '_lead', 'dot.ted', 'sla/sh', str_repeat('z', 25)];
    $fields = settingsFields(['toolkits.mcp_servers' => array_map(static fn(string $id): array => ['id' => $id], [...$refused, 'docs_2'])]);

    expect(array_values(array_filter(array_keys($fields), static fn(string $id): bool => str_starts_with($id, 'alpaca_bot_access.mcp'))))->toBe(['alpaca_bot_access.mcp.docs_2']);
    $row = $fields['alpaca_bot_access.mcp.docs_2'];
    expect($row['title'])->toBe('MCP server: docs_2')
        ->and(($row['render'])())->toContain('<select id="ab-access-mcp-docs_2" name="alpaca_bot_settings[access.mcp][docs_2]">');
});

// Schema::sanitize() keeps the stored value of a key a post leaves out, so a save from another
// tab keeps the whole access.mcp map by not posting it. The Access tab posts the map, one select
// per listed server, and nothing for an entry whose server is not listed: every write of the
// option drops such an entry (Mcp\ServerSettings::beforeSave()), so carrying it would carry it
// into its own removal.
it('posts nothing for the access.mcp map from another tab, and from the Access tab only the listed servers\' selects', function (): void {
    Functions\when('settings_errors')->justReturn(null);
    Functions\when('settings_fields')->justReturn(null);
    Functions\when('do_settings_sections')->alias(function (string $page): void { echo "<!-- sections:{$page} -->"; });
    Functions\when('submit_button')->justReturn(null);
    Functions\when('admin_url')->justReturn('http://x/wp-admin/admin.php');
    Functions\when('add_query_arg')->justReturn('http://x/');
    Functions\when('sanitize_key')->returnArg();
    $settings = ['toolkits.mcp_servers' => [['id' => 'docs']], 'access.mcp' => ['docs' => 'read', 'gone' => 'publish_posts', 'bad' => ['x' => 'read']]];

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
    // do_settings_sections() is a stand-in here, so the selects are not in this markup at all:
    // whatever access.mcp input the page printed, it printed itself.
    expect($access)->not->toContain('alpaca_bot_settings[access.mcp]');
});

// ---------------------------------------------------------------- MCP servers on the Tools tab
// toolkits.mcp_servers is a table: a row per stored server and one blank row to add one, every
// control posting under the row's index. The id is a hidden input, so an edit of the prefix or
// the URL keeps the server's id, and with it its Access entry and its header value.

/**
 * What a server row asks WordPress for beyond escaping: the REST URL and nonce its Discover button
 * carries, and the drift marker it reads (Mcp\Drift), which `$drift` seeds by server id.
 *
 * @param array<string, list<string>> $drift
 */
function stubMcpServerRows(array $drift = []): void
{
    Functions\when('checked')->alias(fn($a, $b = true, $echo = true) => $a == $b ? ' checked="checked"' : '');
    Functions\when('rest_url')->alias(fn(string $p) => 'https://site.test/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('nonce-1');
    Functions\when('get_transient')->alias(static fn(string $key): mixed => $drift[substr($key, strlen('alpaca_bot_mcp_drift_'))] ?? false);
}

it('draws a row per stored server and a blank one, posting every control under the row\'s index', function (): void {
    stubMcpServerRows();
    $html = (settingsFields(['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'trk', 'timeout' => 12.5, 'max_bytes' => 2048, 'approved' => ['search' => str_repeat('a', 64)]],
        ['id' => 'gh', 'url' => 'https://gh.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'gh', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []],
    ]])['alpaca_bot_toolkits.mcp_servers']['render'])();

    $n = 'alpaca_bot_settings[toolkits.mcp_servers]';
    expect($html)->toContain('<div class="ab-mcp-servers" id="ab-mcp-servers" hx-headers="{&quot;X-WP-Nonce&quot;:&quot;nonce-1&quot;}"><table class="widefat striped">')
        ->toContain('<input type="hidden" name="' . $n . '[0][id]" value="trk">')
        ->toContain('name="' . $n . '[0][prefix]" value="trk"')
        ->toContain('<input type="url" class="regular-text" name="' . $n . '[0][url]" value="https://mcp.example.com/mcp" pattern="https://.*"')
        ->toContain('name="' . $n . '[0][header_name]" value="Authorization"')
        ->toContain('<input type="password" class="regular-text" name="' . $n . '[0][header_value]" value="' . Schema::MASK . '" autocomplete="new-password"')
        ->toContain('name="' . $n . '[0][timeout]" value="12.5"')
        ->toContain('name="' . $n . '[0][max_bytes]" value="2048"')
        ->toContain('<input type="checkbox" name="' . $n . '[0][remove]" value="1" aria-label="Remove">')
        // The approvals as stored, carried by a save that never pressed Discover.
        ->toContain('<div id="ab-mcp-tools-trk"><input type="hidden" name="' . $n . '[0][approved][search]" value="' . str_repeat('a', 64) . '">')
        ->toContain('<input type="hidden" name="' . $n . '[1][id]" value="gh">')
        ->toContain('name="' . $n . '[1][header_value]" value=""')
        // The blank row: no id, so a server added there is given one on save.
        ->toContain('name="' . $n . '[2][url]" value=""')
        ->not->toContain('[2][id]')
        ->not->toContain('[3]');
    expect(substr_count($html, '<tr>'))->toBe(4);
});

it('never prints a header value into the table, whatever the row holds', function (): void {
    stubMcpServerRows();
    $html = (settingsFields(['toolkits.mcp_servers' => [
        ['id' => 'raw', 'url' => 'https://mcp.example.com/mcp', 'header_value' => 'Bearer raw-secret', 'prefix' => 'raw'],
    ]])['alpaca_bot_toolkits.mcp_servers']['render'])();
    expect($html)->not->toContain('raw-secret')
        ->toContain('name="alpaca_bot_settings[toolkits.mcp_servers][0][header_value]" value="' . Schema::MASK . '"');
});

it('escapes what a stored row holds at every attribute and text node', function (): void {
    stubMcpServerRows();
    Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    $evil = '"><script>alert(1)</script>';
    $html = (settingsFields(['toolkits.mcp_servers' => [
        ['id' => $evil, 'url' => $evil, 'header_name' => $evil, 'prefix' => $evil, 'timeout' => $evil, 'max_bytes' => $evil, 'approved' => [$evil => $evil]],
    ]])['alpaca_bot_toolkits.mcp_servers']['render'])();
    expect($html)->not->toContain('<script');
});

// Discover is an hx-get at the approval fragment, swapped into the row's approvals cell. The
// fragment's boxes post under the row's index, which hx-vals hands the route; the nonce rides on
// the table's wrapper, which htmx hands down to every request inside it. A refused request (M-3:
// htmx swaps no 4xx) runs the button's response-error handler, which reads its three sentences
// from the button's data attributes: a 403's, a 429's, and one for any other status (R28-11;
// tests/e2e/settings.spec.ts drives it in a browser).
it('gives each stored server a Discover button that swaps its approval list into its row, and the blank row a disabled one', function (): void {
    stubMcpServerRows();
    $html = (settingsFields(['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk', 'approved' => ['search' => str_repeat('a', 64)]],
        ['id' => 'gh', 'url' => 'https://gh.example.com/mcp', 'prefix' => 'gh', 'approved' => []],
    ]])['alpaca_bot_toolkits.mcp_servers']['render'])();
    $button = static fn(string $id, int $i): string => '<button type="button" class="button" hx-get="https://site.test/wp-json/alpaca-bot/v1/view/mcp-tools/' . $id . '" hx-vals="{&quot;index&quot;:' . $i . '}" hx-target="#ab-mcp-tools-' . $id . '" hx-swap="innerHTML" hx-on::response-error="' . htmlspecialchars(SettingsPage::DISCOVER_REFUSED_JS, ENT_QUOTES) . '"'
        . ' data-ab-refused="Discover tools got no list back: this page&#039;s session may have expired. Reload the page and try again."'
        . ' data-ab-busy="Too many requests for now. Wait a minute, then press Discover tools again."'
        . ' data-ab-failed="Discover tools failed. Try again in a moment.">Discover tools</button>';
    expect($html)->toContain('<div class="ab-mcp-servers" id="ab-mcp-servers" hx-headers="{&quot;X-WP-Nonce&quot;:&quot;nonce-1&quot;}"><table')
        ->toContain('1 tool approved.</div>' . $button('trk', 0))
        ->toContain('0 tools approved.</div>' . $button('gh', 1))
        ->toContain('Save the server first.</div><button type="button" class="button" disabled>Discover tools</button>')
        ->toContain('<p class="description">Discover tools lists the tools a saved server offers. Tick the ones to approve and save; a box left clear drops that tool&#039;s approval.')
        ->and(substr_count($html, 'hx-get='))->toBe(2);
});

// Drift is the marker discovery leaves when an approved tool's definition no longer matches its
// pin; the page reads it rather than asking the server. It
// names only tools the row still approves, and sits inside the cell the fragment replaces.
it('says which approved tools were found changed since approval, from the drift marker', function (): void {
    stubMcpServerRows(['trk' => ['report', 'dropped'], 'gh' => ['issues']]);
    $html = (settingsFields(['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk', 'approved' => ['search' => str_repeat('a', 64), 'report' => str_repeat('b', 64)]],
        ['id' => 'gh', 'url' => 'https://gh.example.com/mcp', 'prefix' => 'gh', 'approved' => []],
        ['id' => 'none', 'url' => 'https://none.example.com/mcp', 'prefix' => 'none', 'approved' => ['search' => str_repeat('a', 64)]],
    ]])['alpaca_bot_toolkits.mcp_servers']['render'])();
    expect($html)->toContain('2 tools approved.<p class="description"><strong>changed since approval: review</strong> <code>report</code></p></div>')
        ->and(substr_count($html, 'changed since approval'))->toBe(1)
        ->and($html)->not->toContain('dropped')->not->toContain('issues');
});

// A stored row with no id (one written round the schema) has no Discover to press, but its
// approvals still ride in the cell as hidden inputs, so a save keeps them.
it('keeps the hidden approvals of a stored row that has no id, and says to save the server first', function (): void {
    stubMcpServerRows([]);
    $html = (settingsFields(['toolkits.mcp_servers' => [
        ['url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk', 'approved' => ['search' => str_repeat('a', 64)]],
    ]])['alpaca_bot_toolkits.mcp_servers']['render'])();
    expect($html)->toContain('<div id="ab-mcp-tools-new"><input type="hidden" name="alpaca_bot_settings[toolkits.mcp_servers][0][approved][search]" value="' . str_repeat('a', 64) . '">Save the server first.</div>');
});

// The address check at save time runs in the page's sanitize callback, which is where a person is
// there to be told. A refused new server is left out; a refused edit of a stored server keeps the
// stored row whole, so its saved address, header value and approvals stand; everything else in
// the post is saved; and the screen says which address and why.
it('keeps a server\'s stored row for a refused edit, leaves out a refused new one, and says which and why', function (): void {
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('add_settings_field')->justReturn(null);
    $opts = null;
    Functions\expect('register_setting')->once()->withArgs(function (string $group, string $option, array $o) use (&$opts): bool {
        $opts = $o;
        return true;
    });
    $stored = ['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => Schema::MASK, 'prefix' => 'trk', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => ['search' => str_repeat('a', 64)]],
    ]];
    Functions\when('get_option')->justReturn($stored);
    $errors = [];
    Functions\when('add_settings_error')->alias(function (string $setting, string $code, string $message) use (&$errors): void {
        $errors[] = [$setting, $code, $message];
    });
    $page = new SettingsPage(new AlpacaBot\Settings\Store([]), new ModelCatalog(new AlpacaBot\Provider\Factory(new AlpacaBot\Settings\Store([]))), new AlpacaBot\Access(new AlpacaBot\Settings\Store([])), null, new AlpacaBot\Mcp\ServerSettings(static function (string $host, string $url): array {
        return str_starts_with($host, 'internal') ? throw new AlpacaBot\Toolkit\AddressRefused("{$host} resolves to 10.0.0.7, a private, local or other special-purpose address.") : ['93.184.216.34'];
    }));
    $page->register();
    $out = ($opts['sanitize_callback'])(['chat.welcome' => 'Saved', 'toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://internal-a.example.com/mcp', 'prefix' => 'trk', 'header_value' => 'Bearer do-not-print'],
        ['url' => 'https://internal-b.example.com/mcp', 'prefix' => 'new'],
        ['url' => 'https://ok.example.com/mcp', 'prefix' => 'ok'],
    ]], Plugin::OPTION);

    expect($out['chat.welcome'])->toBe('Saved')
        ->and(array_column($out['toolkits.mcp_servers'], 'id'))->toBe(['trk', 'ok'])
        ->and($out['toolkits.mcp_servers'][0])->toBe($stored['toolkits.mcp_servers'][0])
        ->and($errors)->toHaveCount(2)
        ->and(array_column($errors, 1))->toBe(['mcp_address', 'mcp_address'])
        ->and($errors[0][2])->toContain('https://internal-a.example.com/mcp')->toContain('internal-a.example.com resolves to 10.0.0.7')->toContain('https://mcp.example.com/mcp')
        ->and($errors[1][2])->toContain('https://internal-b.example.com/mcp')->toContain('not added')
        ->and(json_encode($errors))->not->toContain('do-not-print');
});

// A prefix the rule refuses is named with the rule itself, on the edit of a stored server and on a
// new row: `trk_` ends in an underscore, and `ability` is the abilities' own.
it('says what a prefix has to be when it refuses one, on an edit and on a new server', function (): void {
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('add_settings_field')->justReturn(null);
    $opts = null;
    Functions\expect('register_setting')->once()->withArgs(function (string $group, string $option, array $o) use (&$opts): bool {
        $opts = $o;
        return true;
    });
    $stored = ['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'trk', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []],
    ]];
    Functions\when('get_option')->justReturn($stored);
    $errors = [];
    Functions\when('add_settings_error')->alias(function (string $setting, string $code, string $message) use (&$errors): void {
        $errors[] = [$setting, $code, $message];
    });
    $page = new SettingsPage(new AlpacaBot\Settings\Store([]), new ModelCatalog(new AlpacaBot\Provider\Factory(new AlpacaBot\Settings\Store([]))), new AlpacaBot\Access(new AlpacaBot\Settings\Store([])), null, new AlpacaBot\Mcp\ServerSettings(static fn(string $host, string $url): array => ['93.184.216.34']));
    $page->register();
    $out = ($opts['sanitize_callback'])(['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk_'],
        ['url' => 'https://new.example.com/mcp', 'prefix' => 'ability'],
    ]], Plugin::OPTION);

    expect($out['toolkits.mcp_servers'])->toBe($stored['toolkits.mcp_servers'])
        ->and(array_column($errors, 1))->toBe(['mcp_dropped', 'mcp_dropped'])
        ->and($errors[0][2])->toContain('(trk) was not saved')->toContain(esc_html(Schema::mcpPrefixRule()))
        ->and($errors[1][2])->toContain('https://new.example.com/mcp was not added')->toContain(esc_html(Schema::mcpPrefixRule()))
        ->and(Schema::mcpPrefixRule())->toContain('single underscores')->toContain('not "ability"');
});

// A header name with no letter in it, or outside `[A-Za-z0-9-]{1,64}` (R28-11), is refused on the
// page as over REST: the edit of a stored server keeps the stored row, a new row is left out, and
// the notice states the rule, alphabet and all.
it('refuses an MCP header name with no letter in it or outside the rule, on an edit and on a new server, and says why', function (): void {
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('add_settings_field')->justReturn(null);
    $opts = null;
    Functions\expect('register_setting')->once()->withArgs(function (string $group, string $option, array $o) use (&$opts): bool {
        $opts = $o;
        return true;
    });
    $stored = ['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => 'Authorization', 'header_value' => '', 'prefix' => 'trk', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []],
    ]];
    Functions\when('get_option')->justReturn($stored);
    $errors = [];
    Functions\when('add_settings_error')->alias(function (string $setting, string $code, string $message) use (&$errors): void {
        $errors[] = [$setting, $code, $message];
    });
    $page = new SettingsPage(new AlpacaBot\Settings\Store([]), new ModelCatalog(new AlpacaBot\Provider\Factory(new AlpacaBot\Settings\Store([]))), new AlpacaBot\Access(new AlpacaBot\Settings\Store([])), null, new AlpacaBot\Mcp\ServerSettings(static fn(string $host, string $url): array => ['93.184.216.34']));
    $page->register();
    $out = ($opts['sanitize_callback'])(['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'prefix' => 'trk', 'header_name' => '123', 'header_value' => 'Bearer typed'],
        ['url' => 'https://new.example.com/mcp', 'prefix' => 'new', 'header_name' => '-1', 'header_value' => 'Bearer typed'],
        ['url' => 'https://under.example.com/mcp', 'prefix' => 'und', 'header_name' => 'X_Key', 'header_value' => 'Bearer typed'],
    ]], Plugin::OPTION);

    $rule = 'its header name has to be ' . esc_html(Schema::mcpHeaderNameRule()) . '.';
    expect($out['toolkits.mcp_servers'])->toBe($stored['toolkits.mcp_servers'])
        ->and(array_column($errors, 1))->toBe(['mcp_dropped', 'mcp_dropped', 'mcp_dropped'])
        ->and($errors[0][2])->toContain('(trk) was not saved')->toContain($rule)
        ->and($errors[1][2])->toContain('https://new.example.com/mcp was not added')->toContain($rule)
        ->and($errors[2][2])->toContain('https://under.example.com/mcp was not added')->toContain($rule)
        ->and(json_encode($errors))->not->toContain('Bearer typed');
});

// M4 (R101): a user name or password in the address is refused on the page as over REST. The edit
// of a stored server keeps the stored row, a new row is left out, and the notice says the
// credential goes in the header, naming the URL without it.
it('refuses an MCP URL that carries a credential, on an edit and on a new server, and says to use the header', function (): void {
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('add_settings_field')->justReturn(null);
    $opts = null;
    Functions\expect('register_setting')->once()->withArgs(function (string $group, string $option, array $o) use (&$opts): bool {
        $opts = $o;
        return true;
    });
    $stored = ['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://mcp.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'trk', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []],
    ]];
    Functions\when('get_option')->justReturn($stored);
    $errors = [];
    Functions\when('add_settings_error')->alias(function (string $setting, string $code, string $message) use (&$errors): void {
        $errors[] = [$setting, $code, $message];
    });
    $page = new SettingsPage(new AlpacaBot\Settings\Store([]), new ModelCatalog(new AlpacaBot\Provider\Factory(new AlpacaBot\Settings\Store([]))), new AlpacaBot\Access(new AlpacaBot\Settings\Store([])), null, new AlpacaBot\Mcp\ServerSettings(static fn(string $host, string $url): array => ['93.184.216.34']));
    $page->register();
    $out = ($opts['sanitize_callback'])(['toolkits.mcp_servers' => [
        ['id' => 'trk', 'url' => 'https://user:s3cret@mcp.example.com/mcp', 'prefix' => 'trk'],
        ['url' => 'https://tok3n@new.example.com/mcp', 'prefix' => 'new'],
        // Not https, so the fault is the URL's, and the notice still leaves the password out.
        ['url' => 'http://user:s3cret@plain.example.com/mcp', 'prefix' => 'plain'],
    ]], Plugin::OPTION);

    expect($out['toolkits.mcp_servers'])->toBe($stored['toolkits.mcp_servers'])
        ->and(array_column($errors, 1))->toBe(['mcp_dropped', 'mcp_dropped', 'mcp_dropped'])
        ->and($errors[0][2])->toContain('(trk) was not saved')->toContain('user name or password')->toContain('header')
        ->and($errors[1][2])->toContain('https://new.example.com/mcp was not added')->toContain('user name or password')->toContain('header')
        ->and($errors[2][2])->toContain('http://plain.example.com/mcp was not added')->toContain('https with a host')
        ->and(json_encode($errors))->not->toContain('s3cret')->not->toContain('tok3n');
});

// A refused edit puts the stored row back whole, prefix included, and a new row of the same post
// may have taken that prefix in the meantime. The schema keeps the first of two rows under one
// prefix, so the new one is left out, and the screen says so rather than lose it quietly.
it('says so when a server kept after a refused edit takes back a prefix a new row had claimed', function (): void {
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('add_settings_field')->justReturn(null);
    $opts = null;
    Functions\expect('register_setting')->once()->withArgs(function (string $group, string $option, array $o) use (&$opts): bool {
        $opts = $o;
        return true;
    });
    Functions\when('get_option')->justReturn(['toolkits.mcp_servers' => [
        ['id' => 'a', 'url' => 'https://a.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => 'p', 'timeout' => 30.0, 'max_bytes' => 1048576, 'approved' => []],
    ]]);
    $errors = [];
    Functions\when('add_settings_error')->alias(function (string $setting, string $code, string $message) use (&$errors): void {
        $errors[] = $code . ': ' . $message;
    });
    $store = new AlpacaBot\Settings\Store([]);
    (new SettingsPage($store, new ModelCatalog(new AlpacaBot\Provider\Factory($store)), new AlpacaBot\Access($store), null, new AlpacaBot\Mcp\ServerSettings(static function (string $host, string $url): array {
        return $host === 'internal.example.com' ? throw new AlpacaBot\Toolkit\AddressRefused('refused.') : ['93.184.216.34'];
    })))->register();
    $out = ($opts['sanitize_callback'])(['toolkits.mcp_servers' => [
        ['id' => 'a', 'url' => 'https://internal.example.com/mcp', 'prefix' => 'q'],
        ['url' => 'https://b.example.com/mcp', 'prefix' => 'p'],
    ]], Plugin::OPTION);
    expect(array_column($out['toolkits.mcp_servers'], 'prefix'))->toBe(['p'])
        ->and(array_column($out['toolkits.mcp_servers'], 'url'))->toBe(['https://a.example.com/mcp'])
        ->and($errors)->toHaveCount(2)
        ->and($errors[1])->toStartWith('mcp_prefix: ')->toContain('https://b.example.com/mcp');
});

// ---------------------------------------------------------------- the abilities allowlist
// toolkits.abilities is the one Tools field whose options are the site's, not the schema's: a box
// per ability core has registered, read from abilitiesRegistry() (tests/Pest.php) here.

it('lists every registered ability but Alpaca Bot\'s own as a box, with the description the model will read, and keeps a stored name core no longer has', function (): void {
    Functions\when('checked')->alias(fn($a, $b = true, $echo = true) => $a == $b ? ' checked="checked"' : '');
    abilitiesRegistry([
        'core/get-site-info' => siteAbility('core/get-site-info', description: "Returns\n\tsite <em>information</em>."),
        'x/delete-everything' => siteAbility('x/delete-everything', meta: ['annotations' => ['destructive' => true]]),
        'x/maybe' => siteAbility('x/maybe', meta: ['annotations' => ['destructive' => null]]),
        'alpaca-bot/chat' => siteAbility('alpaca-bot/chat'),
    ]);
    // A hand-edited option can hold one of Alpaca Bot's own names; it is not listed as a stale one.
    $html = (settingsFields(['toolkits.abilities' => ['x/delete-everything', 'gone/missing', 'alpaca-bot/chat', 'alpaca-bot/gone']])['alpaca_bot_toolkits.abilities']['render'])();

    // The sentinel ahead of the boxes, so a save with every box clear is heard.
    expect($html)->toMatch('/^<input type="hidden" name="alpaca_bot_settings\[toolkits\.abilities\]\[\]" value="">/')
        ->toContain('<input type="checkbox" name="alpaca_bot_settings[toolkits.abilities][]" value="core/get-site-info"> <strong>Label of core/get-site-info</strong> <code>core/get-site-info</code>')
        ->toContain('<input type="checkbox" name="alpaca_bot_settings[toolkits.abilities][]" value="x/delete-everything" checked="checked">')
        ->toContain('<code>core/get-site-info</code></label><br><span class="description">Returns site information.</span>')
        ->not->toContain('value="alpaca-bot/chat"')->not->toContain('value="alpaca-bot/gone"')
        ->toContain('<input type="checkbox" name="alpaca_bot_settings[toolkits.abilities][]" value="gone/missing" checked="checked"> <code>gone/missing</code>')
        ->toContain('not in this site&#039;s list of abilities; untick to remove');
    // Destructive only where the ability's own annotation says true.
    expect(substr_count($html, 'Destructive'))->toBe(1)
        ->and(strpos($html, 'Destructive'))->toBeGreaterThan(strpos($html, 'value="x/delete-everything"'))
        ->and(strpos($html, 'Destructive'))->toBeLessThan(strpos($html, 'value="x/maybe"'));
});

it('escapes an ability\'s label and description, which are other plugins\' text', function (): void {
    Functions\when('checked')->alias(fn($a, $b = true, $echo = true) => $a == $b ? ' checked="checked"' : '');
    $evil = siteAbility('x/evil', description: '<script>alert(1)</script>"><img src=x onerror=alert(2)>');
    $evil->shouldReceive('get_label')->andReturn('<img src=x onerror=alert(3)>');
    abilitiesRegistry(['x/evil' => $evil]);
    $html = (settingsFields()['alpaca_bot_toolkits.abilities']['render'])();
    expect($html)->not->toContain('<script')->not->toContain('<img')->toContain('&lt;img src=x onerror=alert(3)&gt;');
});

// Two abilities that reach the model under one tool name are both left out of the offer
// (AbilitiesToolkitTest); this is where an administrator is told so.
it('marks two abilities that would share a tool name, naming the other one', function (): void {
    Functions\when('checked')->alias(fn($a, $b = true, $echo = true) => $a == $b ? ' checked="checked"' : '');
    $ns = str_repeat('n', 45);
    $long = $ns . '/' . str_repeat('l', 20);
    $short = $ns . '/' . substr(hash('sha256', 'ability__' . $ns . '__' . str_repeat('l', 20)), 0, 8);
    abilitiesRegistry([$long => siteAbility($long), $short => siteAbility($short), 'core/get-site-info' => siteAbility('core/get-site-info')]);
    $html = (settingsFields()['alpaca_bot_toolkits.abilities']['render'])();
    expect(substr_count($html, 'Reaches the model under the same tool name'))->toBe(2)
        ->and($html)->toContain('same tool name as <code>' . $short . '</code>')
        ->and($html)->toContain('same tool name as <code>' . $long . '</code>');
});

it('says so, and posts nothing for the field, where the Abilities API is absent', function (): void {
    $html = (settingsFields(['toolkits.abilities' => ['core/get-site-info']], static fn(string $fn): bool => false)['alpaca_bot_toolkits.abilities']['render'])();
    expect($html)->toContain('Abilities API')->not->toContain('<input');
});
