<?php

declare(strict_types=1);

use AlpacaBot\Admin\Assets;
use AlpacaBot\Admin\HelpTabs;
use AlpacaBot\Admin\SettingsPage;
use Brain\Monkey\Functions;

// WP_Screen is a class (wordpress-stubs is PHPStan-only), so Mockery declares it; `id` is the
// public property core sets on it, and add_help_tab() is the method the tabs go through.
function helpScreen(string $id): Mockery\MockInterface
{
    $screen = Mockery::mock('WP_Screen');
    $screen->id = $id;
    return $screen;
}

// Core derives a submenu screen's id from the *parent's translated menu title*, so the settings
// page's id is not a constant. These stand in for get_plugin_page_hookname() rather than spell
// the id out: what a hard-coded string cost was every tab on every translated locale, and a
// test that names the string again cannot notice. The derivation itself is exercised against
// real core, in a translated locale, in tests/Integration/HelpTabsTest.php.
beforeEach(function (): void {
    Functions\when('get_plugin_page_hookname')->alias(static fn(string $page, string $parent): string => 'alpaca-bot_page_' . $page);
});

it('names the chat screen and the settings page as the two screens that get the tabs, asking core for the submenu id', function (): void {
    expect(HelpTabs::screens())->toBe(['toplevel_page_alpaca-bot', 'alpaca-bot_page_alpaca-bot-settings']);

    // A locale that translates "Alpaca Bot": the top-level page keeps its id (its own slug is in
    // $admin_page_hooks, which takes the `toplevel` branch), the settings page does not.
    Functions\when('get_plugin_page_hookname')->alias(static fn(string $page, string $parent): string => 'robot-alpaca_page_' . $page);
    expect(HelpTabs::screens())->toBe([Assets::HOOK, 'robot-alpaca_page_' . SettingsPage::SLUG]);
});

it('adds the Chat, Shortcodes, Tools, Access and Support tabs, in that order, to the chat screen and to the settings page', function (): void {
    Functions\when('esc_url')->returnArg();
    foreach (HelpTabs::screens() as $id) {
        $added = [];
        $screen = helpScreen($id);
        $screen->shouldReceive('add_help_tab')->times(5)->andReturnUsing(static function (array $tab) use (&$added): void {
            $added[] = $tab;
        });
        (new HelpTabs())->add($screen);
        expect(array_column($added, 'id'))->toBe(['alpaca-bot-chat', 'alpaca-bot-shortcodes', 'alpaca-bot-tools', 'alpaca-bot-access', 'alpaca-bot-support'], $id)
            ->and(array_column($added, 'title'))->toBe(['Chat', 'Shortcodes', 'Tools', 'Access', 'Support'], $id);
        foreach ($added as $tab) {
            expect($tab['content'])->toBeString()->toContain('<p>');
        }
    }
});

it('adds nothing to any other screen', function (): void {
    foreach (['post', 'dashboard', 'edit-post', 'alpaca-bot_page_other', ''] as $id) {
        $screen = helpScreen($id);
        $screen->shouldReceive('add_help_tab')->never();
        (new HelpTabs())->add($screen);
    }
});

it('says what both shortcodes do now, that an answer costs tokens and is cached, and who sees one; never that they are removed', function (): void {
    // P3 shipped no shortcodes and this tab said so. They are back on the new pipeline, and a
    // tab that still said "removed" would be the product's own documentation lying. What a
    // site owner needs from it: the two forms of [alpacabot], that a prompt spends provider
    // tokens when it is generated and is cached (the default hour) so it is not generated on
    // every view, that a visitor only ever sees a cached answer, and that [alpacabot_agent]
    // is the deprecated 0.4 form.
    Functions\when('esc_url')->returnArg();
    $content = helpTabContent('alpaca-bot-shortcodes');
    expect($content)->toContain('[alpacabot]')->toContain('[alpacabot_agent]')->toContain('prompt=')
        ->toContain('token')->toContain('cache')->toContain('edit posts')->toContain('deprecated')
        ->toContain('alpaca_bot/shortcode/allow_guests')
        // Review I2, documented rather than gated: anyone who can write a post can write a
        // prompt, the tokens are the viewer's, nobody reads the answer before it is public,
        // and links and images in it reach the page. I3: the REST API and the editor show the
        // cache or a notice. M8: the shim's fetch is under the Tools setting.
        ->toContain('write a post')->toContain('monthly cap')->toContain('links')->toContain('images')
        ->toContain('REST')->toContain('Settings › Tools')
        ->not->toContain('removed')->not->toContain('registers no shortcodes')->not->toMatch('/(?<![\d.])1\.\d/');
});

it('describes what the chat screen does now, and points support at the wordpress.org forum, a booked call and a GitHub star, in that order, not Discord, Patreon or the issue tracker', function (): void {
    Functions\when('esc_url')->returnArg();
    $chat = helpTabContent('alpaca-bot-chat');
    expect($chat)->toContain('New chat')->toContain('Enter')->toContain('Shift')->toContain('image')->toContain('Copy')->toContain('Edit and resend')
        // The drawer (Admin\Drawer): where it is, and the image button it lacks where the screen has no media library.
        ->toContain('round button')->toContain('screens that have the button')->toContain('already loads the media library')
        // The block editor's sidebar (resources/ts/editor-start.ts) in the drawer's place, and when it names the post.
        ->toContain('In the block editor the same chat is a sidebar')->toContain('once that post has been saved or autosaved')
        // The two share one memory of the conversation last open (Kanboard #4527).
        ->toContain('opens on the one you last had open in it or in the block editor')
        ->toContain('it opens on the conversation you last had open in it or in the panel')
        ->not->toContain('starts on a new chat')
        // The image cap is Assets::maxImageBytes(), which reads post_max_size alone; the tab must name that setting, not the upload limit that has no say.
        ->toContain('post_max_size')->not->toMatch('/is the site.s own upload limit/');
    // A question goes to the wordpress.org forum, premium help is a call booked on
    // carmelosantana.com, and the GitHub link is an ask for a star, not a place to file issues.
    // Discord and Patreon's premium support were retired before 0.5.0 shipped.
    $support = helpTabContent('alpaca-bot-support');
    expect($support)->toContain('href="https://wordpress.org/support/plugin/alpaca-bot/"')
        ->toContain('href="https://carmelosantana.com/alpaca-bot"')
        ->toContain('href="https://github.com/carmelosantana/alpaca-bot"')
        ->toContain('rel="noopener"')
        ->not->toContain('/issues')->not->toContain('issue tracker');
    expect(strtolower($support))->not->toContain('discord')->not->toContain('patreon');
    // The free channel first, the paid call second and the give-back last, so a star never reads
    // as a place to take a bug report.
    $order = array_map(static fn(string $needle): int => strpos($support, $needle), [
        'href="https://wordpress.org/support/plugin/alpaca-bot/"',
        'href="https://carmelosantana.com/alpaca-bot"',
        'href="https://github.com/carmelosantana/alpaca-bot"',
    ]);
    $sorted = $order;
    sort($sorted);
    expect($order)->toBe($sorted)->not->toContain(false);
});

it('tells a site owner what the tools grant: the fetch is an outbound request pinned to the checked addresses, what the pin does not cover, and who can reach it', function (): void {
    // H-1 and M-3 of the 0.5.0 security audit, both accepted for this release and both argued
    // until now only in a source docblock, where the only person who can act on them will never
    // read it. What must be here: what web_fetch does, that the connection can go only to an
    // address the check passed, on every redirect, and what the pin does not reach (a proxy, the
    // site's own host, a server without cURL), who can reach it with no model involved, that an
    // egress policy still covers what the pin does not, and -- since 0.6 closed M-3 -- that opening a
    // capability filter to a role does not hand that role the tools: each one has a row of its
    // own in Settings > Access.
    Functions\when('esc_url')->returnArg();
    $tools = helpTabContent('alpaca-bot-tools');
    expect($tools)->toContain('web_fetch')->toContain('draft_post')
        ->toContain('outbound')->toContain('DNS')->toContain('redirect')
        ->toContain('pinned')->toContain('cURL')->toContain('proxy')->toContain('Site Health')
        ->toContain('egress policy')->toContain('IMDSv2')
        ->not->toContain('later 0.x')
        ->toContain('Contributor')->toContain('[alpacabot_agent name="get" url="…"]')
        ->toContain('alpaca_bot/capability/chat')->toContain('alpaca_bot/toolkits')
        ->toContain('Settings › Access')
        // Not overstated into a scare, and not dated with a version that is not this line's.
        ->not->toContain('vulnerab')->not->toMatch('/(?<![\\d.])1\\.\\d/');
});

// The Access tab is the capability model in the product's own words: a row is a default a filter
// may override, not an answer, and "Set in code" under a row is that override showing. It names
// every row but the tool rows by the label Settings › Access gives it, and the tool rows
// together, so an operator can find the one it means.
it('says what each Settings › Access row decides and what "Set in code" under one means', function (): void {
    Functions\when('esc_url')->returnArg();
    $access = helpTabContent('alpaca-bot-access');
    foreach (AlpacaBot\Settings\Schema::fields() as $key => $field) {
        if (str_starts_with($key, 'access.') && $key !== 'access.mcp' && !str_starts_with($key, 'access.tool.')) {
            expect($access)->toContain('<strong>' . $field['label'] . '</strong>');
        }
    }
    expect($access)->toContain('Set in code')
        ->toContain('Administrators')->toContain('Editors and up')->toContain('Authors and up')->toContain('Contributors and up')->toContain('Any logged-in user')
        // The tool floor: opening the chat does not hand a role the tools.
        ->toContain('as well as Chat')
        // The Chat row's surfaces, each behind a filter of its own: the menu's, and every chat
        // REST route's own key, asked once per key and verb with the first such route's path, so a
        // route sharing both with an earlier one is not named apart (Kanboard #4537).
        ->toContain('alpaca_bot/admin/menu_capability')->toContain('alpaca_bot/capability/{route}')->toContain('every chat REST route')->toContain('once for each route key and method')->toContain('the path of the first route that declares that pair')
        ->toContain('is not asked or named on its own')->not->toContain('names each route and method that moved it')
        ->not->toContain('which the note does not ask')
        ->toContain('once no filter changes it')
        // Both shortcodes are asked, outside a post; what the note does not cover is a post or a user.
        ->toContain('outside any post')->toContain('<code>[alpacabot]</code> and <code>[alpacabot_agent]</code>')
        ->not->toContain('<code>[alpacabot_agent]</code>, gets no note')
        ->not->toMatch('/(?<![\\d.])1\\.\\d/');
});

it('escapes every URL it prints through esc_url', function (): void {
    $urls = [];
    Functions\when('esc_url')->alias(static function (string $url) use (&$urls): string {
        $urls[] = $url;
        return 'ESCAPED';
    });
    $support = helpTabContent('alpaca-bot-support');
    expect($urls)->not->toBeEmpty()->and($support)->not->toContain('href="https://');
});

// The abilities tool is the one tool whose reach is set by other plugins' code, so the person
// switching tools on is told what a call is and who it runs as, that the list shows what the model
// reads, and that its row starts where the other tools' rows do not.
it('tells the person switching tools on what an ability call is: another plugin\'s code, run as the user whose turn it is', function (): void {
    Functions\when('esc_url')->returnArg();
    $tools = helpTabContent('alpaca-bot-tools');
    expect($tools)->toContain('as the user whose turn it is')->toContain('<code>alpaca-bot/*</code>')
        ->toContain('own permission check')->toContain('destructive')->toContain('same tool name')
        ->toContain('Contributors and up by default for <code>web_fetch</code>, <code>summarize</code> and <code>draft_post</code>')
        ->toContain('Administrators for the abilities tool')
        ->not->toContain('All three')->not->toContain('three ship')
        // The allowlist also bounds what a ticked ability runs (Kanboard #4538), and the tab says what differs before 7.1 and what it does not reach.
        ->toContain('also bounds what a ticked ability runs')->toContain('run any ability')->toContain('Before WordPress 7.1')
        ->toContain('is not bounded by the list')->not->toContain('decides only what the model may call directly')
        ->not->toContain('cannot call the plugin')
        // The schema's own text (review N2): what Alpaca Bot does with it, not what a provider does.
        ->toContain('Alpaca Bot neither cleans nor caps')->not->toContain('as the plugin registered it')
        ->toContain('longer than ' . AlpacaBot\Toolkit\SchemaTool::RESULT_CHARS . ' characters is cut');
});

// An MCP server's tools are another party's, and Discover and a tool turn contact the server
// (Task 28): the tab says what is offered and when the address is checked, and does not borrow
// web_fetch's address rules, which differ (review R88).
it('tells the person adding an MCP server what is offered, whose text it is, who may use it, and when its address is checked', function (): void {
    Functions\when('esc_url')->returnArg();
    $tools = helpTabContent('alpaca-bot-tools');
    // The one paragraph about MCP, so what it says is not borrowed from the abilities one.
    preg_match('~<p><strong>An MCP server.*?</p>~s', $tools, $m);
    $mcp = str_replace('&#039;', "'", $m[0] ?? '');
    expect($mcp)->not->toContain('no MCP server is contacted')->not->toContain('php-agents')
        ->toContain('Discover tools asks the server for its tools')
        ->toContain('checked when it is saved from this screen or over the REST API, and again each time Alpaca Bot connects to it: on Discover tools, and on a chat turn that lists its tools')
        ->toContain('only to an address that passed, never through a proxy, and follows no redirect')
        ->toContain('Put a credential in the header, never in the address')
        ->toContain('only the tools you tick')->toContain('prefix__tool')
        ->toContain('withheld until you approve it again')->toContain('lists twice')
        ->toContain("the server's text")->toContain('shortened and flattened exactly as the model gets it')
        ->toContain('starts at Administrators')
        // M5 (R101): the schema is the server's text too and reaches the model as sent; C3
        // (Task 28.3): approving pins it, so the list shows it, cut at McpTools::SCHEMA_CHARS.
        ->toContain("input schema carries the server's text too")->toContain('Alpaca Bot neither cleans nor caps')
        ->toContain("pins its input schema with the rest, so the list shows each tool's schema under it, collapsed, with every character outside ASCII written as its \\u escape so that none can hide, and cut at 4000 characters of that text.")
        ->not->toContain('does not show')
        ->toContain("even when it is the site's own host")
        ->toContain('<code>alpaca_bot/mcp/called</code>')
        ->and($tools)->not->toContain('same address rules');
});

/** The content of one tab as add() hands it to the chat screen; how many tabs there are is the ordering test's to pin. */
function helpTabContent(string $tabId): string
{
    $screen = helpScreen(Assets::HOOK);
    $content = null;
    $screen->shouldReceive('add_help_tab')->atLeast()->once()->andReturnUsing(static function (array $tab) use (&$content, $tabId): void {
        if ($tab['id'] === $tabId) {
            $content = $tab['content'];
        }
    });
    (new HelpTabs())->add($screen);
    expect($content)->toBeString();
    return $content;
}
