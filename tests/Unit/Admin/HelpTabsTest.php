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
// the id out: what a hard-coded string cost was all four tabs on every translated locale, and a
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

it('adds the Chat, Shortcodes, Tools and Support tabs, in that order, to the chat screen and to the settings page', function (): void {
    Functions\when('esc_url')->returnArg();
    foreach (HelpTabs::screens() as $id) {
        $added = [];
        $screen = helpScreen($id);
        $screen->shouldReceive('add_help_tab')->times(4)->andReturnUsing(static function (array $tab) use (&$added): void {
            $added[] = $tab;
        });
        (new HelpTabs())->add($screen);
        expect(array_column($added, 'id'))->toBe(['alpaca-bot-chat', 'alpaca-bot-shortcodes', 'alpaca-bot-tools', 'alpaca-bot-support'], $id)
            ->and(array_column($added, 'title'))->toBe(['Chat', 'Shortcodes', 'Tools', 'Support'], $id);
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

it('describes what the chat screen does now, and points support at Discord, the wordpress.org forum, a GitHub star and Patreon, not the issue tracker', function (): void {
    Functions\when('esc_url')->returnArg();
    $chat = helpTabContent('alpaca-bot-chat');
    expect($chat)->toContain('New chat')->toContain('Enter')->toContain('Shift')->toContain('image')->toContain('Copy')->toContain('Edit and resend')
        // The image cap is Assets::maxImageBytes(), which reads post_max_size alone; the tab must name that setting, not the upload limit that has no say.
        ->toContain('post_max_size')->not->toMatch('/is the site.s own upload limit/');
    // Support questions go to the wordpress.org forum, and the GitHub link is an ask for a star,
    // not a place to file issues: the tab must carry both new hrefs and no longer point at /issues.
    $support = helpTabContent('alpaca-bot-support');
    expect($support)->toContain('href="https://discord.gg/vWQTHphkVt"')
        ->toContain('href="https://wordpress.org/support/plugin/alpaca-bot/"')
        ->toContain('href="https://github.com/carmelosantana/alpaca-bot"')
        ->toContain('href="https://www.patreon.com/carmelosantana"')
        ->toContain('rel="noopener"')
        ->not->toContain('/issues')->not->toContain('issue tracker');
});

it('tells a site owner what the tools grant: the fetch is an outbound request, the rebinding window is open, an egress policy is the mitigation, and opening the chat opens the tools', function (): void {
    // H-1 and M-3 of the 0.5.0 security audit, both accepted for this release and both argued
    // until now only in a source docblock, where the only person who can act on them will never
    // read it. What must be here: what web_fetch does, that the DNS-rebinding window between the
    // address check and the connection is open, who can reach it with no model involved, that an
    // egress policy is the supported mitigation, and that opening a capability filter to a role
    // hands that role every enabled tool.
    Functions\when('esc_url')->returnArg();
    $tools = helpTabContent('alpaca-bot-tools');
    expect($tools)->toContain('web_fetch')->toContain('draft_post')
        ->toContain('outbound')->toContain('DNS')->toContain('redirect')
        ->toContain('egress policy')->toContain('IMDSv2')
        ->toContain('Contributor')->toContain('[alpacabot_agent name="get" url="…"]')
        ->toContain('alpaca_bot/capability/chat')->toContain('alpaca_bot/toolkits')
        // Not overstated into a scare, and not dated with a version that is not this line's.
        ->not->toContain('vulnerab')->not->toMatch('/(?<![\\d.])1\\.\\d/');
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

/** The content of one tab as add() hands it to the chat screen. */
function helpTabContent(string $tabId): string
{
    $screen = helpScreen(Assets::HOOK);
    $content = null;
    $screen->shouldReceive('add_help_tab')->times(4)->andReturnUsing(static function (array $tab) use (&$content, $tabId): void {
        if ($tab['id'] === $tabId) {
            $content = $tab['content'];
        }
    });
    (new HelpTabs())->add($screen);
    expect($content)->toBeString();
    return $content;
}
