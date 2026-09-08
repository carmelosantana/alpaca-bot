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

it('tells a site that upgraded from 0.4 that both shortcodes are gone, print as text now, and what to do', function (): void {
    // A 0.4 post with [alpacabot] in its content renders the literal shortcode once nothing
    // registers it; the tab is where a user is told, so it must name both, say they are
    // removed, say what the front end shows, and say what to do about it.
    Functions\when('esc_url')->returnArg();
    $content = helpTabContent('alpaca-bot-shortcodes');
    expect($content)->toContain('[alpacabot]')->toContain('[alpacabot_agent]')
        ->toContain('removed')
        ->toContain('later 0.x release')
        ->toMatch('/as (plain )?text/i')
        ->toContain('Search')
        ->not->toContain('1.0');
});

it('describes what the chat screen does now, and points support at Discord, Patreon and the issue tracker', function (): void {
    Functions\when('esc_url')->returnArg();
    $chat = helpTabContent('alpaca-bot-chat');
    expect($chat)->toContain('New chat')->toContain('Enter')->toContain('Shift')->toContain('image')->toContain('Copy')->toContain('Edit and resend');
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
