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

it('names the chat screen and the settings page as the two screens that get the tabs', function (): void {
    expect(HelpTabs::SCREENS)->toBe([Assets::HOOK, 'alpaca-bot_page_' . SettingsPage::SLUG])
        ->and(HelpTabs::SCREENS)->toBe(['toplevel_page_alpaca-bot', 'alpaca-bot_page_alpaca-bot-settings']);
});

it('adds the Chat, Shortcodes and Support tabs, in that order, to the chat screen and to the settings page', function (): void {
    Functions\when('esc_url')->returnArg();
    foreach (HelpTabs::SCREENS as $id) {
        $added = [];
        $screen = helpScreen($id);
        $screen->shouldReceive('add_help_tab')->times(3)->andReturnUsing(static function (array $tab) use (&$added): void {
            $added[] = $tab;
        });
        (new HelpTabs())->add($screen);
        expect(array_column($added, 'id'))->toBe(['alpaca-bot-chat', 'alpaca-bot-shortcodes', 'alpaca-bot-support'], $id)
            ->and(array_column($added, 'title'))->toBe(['Chat', 'Shortcodes', 'Support'], $id);
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
        ->not->toContain('removed')->not->toContain('registers no shortcodes')->not->toContain('1.0');
});

it('describes what the chat screen does now, and points support at Discord, Patreon and the issue tracker', function (): void {
    Functions\when('esc_url')->returnArg();
    $chat = helpTabContent('alpaca-bot-chat');
    expect($chat)->toContain('New chat')->toContain('Enter')->toContain('Shift')->toContain('image')->toContain('Copy')->toContain('Edit and resend')
        // The image cap is Assets::maxImageBytes(), which reads post_max_size alone; the tab must name that setting, not the upload limit that has no say.
        ->toContain('post_max_size')->not->toMatch('/is the site.s own upload limit/');
    $support = helpTabContent('alpaca-bot-support');
    expect($support)->toContain('href="https://discord.gg/vWQTHphkVt"')
        ->toContain('href="https://www.patreon.com/carmelosantana"')
        ->toContain('href="https://github.com/carmelosantana/alpaca-bot/issues"')
        ->toContain('rel="noopener"');
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
    $screen->shouldReceive('add_help_tab')->times(3)->andReturnUsing(static function (array $tab) use (&$content, $tabId): void {
        if ($tab['id'] === $tabId) {
            $content = $tab['content'];
        }
    });
    (new HelpTabs())->add($screen);
    expect($content)->toBeString();
    return $content;
}
