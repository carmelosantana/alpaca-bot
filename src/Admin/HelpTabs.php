<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

/**
 * The help tabs (core's "Help" pull-down at the top right of a screen) of the chat screen and the
 * settings page, added on `current_screen`, the action core fires once the WP_Screen is built and
 * its id is known. Three tabs: what the chat screen does now, what became of 0.4's shortcodes,
 * and where to get help.
 *
 * 0.4's Help ran README.md through a markdown parser and made a tab of every heading. Nothing of
 * that is kept: the parser is gone with the 0.4 tree, and the tabs are written for what the
 * screens actually do. The Shortcodes tab is not a placeholder for a feature that is not here: a
 * site that upgraded from 0.4 with `[alpacabot]` in a post now shows that text to its visitors,
 * because WordPress prints a shortcode nobody registers, and the tab is where that site is told.
 *
 * Every string is escaped as it is built (esc_html__() for text, esc_url() for a link), so the
 * content is handed to add_help_tab() ready to print.
 */
final class HelpTabs
{
    /** The two screens that get the tabs: the chat page (its hook suffix is its screen id) and the settings submenu page. */
    public const SCREENS = [Assets::HOOK, 'alpaca-bot_page_' . SettingsPage::SLUG];

    /** `current_screen`: the tabs on one of the plugin's two screens; every other screen is left alone. */
    public function add(\WP_Screen $screen): void
    {
        if (!in_array($screen->id, self::SCREENS, true)) {
            return;
        }
        foreach ($this->tabs() as $id => [$title, $content]) {
            $screen->add_help_tab(['id' => 'alpaca-bot-' . $id, 'title' => $title, 'content' => $content]);
        }
    }

    /** @return array<string, array{string, string}> tab id => [title, content], in display order */
    private function tabs(): array
    {
        return [
            'chat' => [__('Chat', 'alpaca-bot'), $this->chat()],
            'shortcodes' => [__('Shortcodes', 'alpaca-bot'), $this->shortcodes()],
            'support' => [__('Support', 'alpaca-bot'), $this->support()],
        ];
    }

    /** What the chat screen does, as View\Chat\* and chat.ts have it. */
    private function chat(): string
    {
        return self::p(esc_html__('Alpaca Bot answers from the provider set on the settings page. Every conversation is your own: only you can open it, and where the site keeps history it is stored on this site, nowhere else.', 'alpaca-bot'))
            . self::list([
                esc_html__('The model select in the header picks the model for this conversation. Where the site allows it, your pick is saved as your default for next time.', 'alpaca-bot'),
                esc_html__('The history select opens one of your earlier conversations; "New chat" starts a fresh one.', 'alpaca-bot'),
                esc_html__('Type in the box at the foot of the screen. Enter sends, Shift+Enter adds a line, Escape clears the box.', 'alpaca-bot'),
                esc_html__('The image button attaches a picture from the media library to your next message, for a model that can see. The largest image it takes is set by the site\'s PHP post_max_size, not its upload limit: the image travels inside the message, not as an upload.', 'alpaca-bot'),
                esc_html__('Replies stream in as they are written. Every message has a Copy button; your own messages have "Edit and resend", which puts the text back in the box; a code block has its own copy button.', 'alpaca-bot'),
                esc_html__('Under a reply is its receipt: the model, the tokens it used and how long it took. Monthly usage caps set on the settings page apply here, and the screen says so when one is reached.', 'alpaca-bot'),
            ]);
    }

    /** The 0.4 shortcodes are gone; a post that still carries one prints it as text, and this is where the site is told what to do about it. */
    private function shortcodes(): string
    {
        return self::p(sprintf(
            /* translators: 1: [alpacabot], 2: [alpacabot_agent] */
            esc_html__('This version registers no shortcodes. The %1$s and %2$s shortcodes from 0.4 were removed in the rewrite; they return with the toolkits in a later 0.x release.', 'alpaca-bot'),
            '<code>[alpacabot]</code>',
            '<code>[alpacabot_agent]</code>',
        ))
            . self::p('<strong>' . esc_html__('If you upgraded from 0.4, check your content.', 'alpaca-bot') . '</strong> ' . esc_html__('WordPress prints a shortcode nothing registers exactly as written, so a post or page that still contains one now shows the shortcode itself to visitors, as plain text, where the generated content used to be.', 'alpaca-bot'))
            . self::p(sprintf(
                /* translators: %s: the search term "[alpacabot", with no closing bracket on purpose so that it finds both shortcodes */
                esc_html__('Search your posts and pages for %s (the search box on the Posts and Pages lists searches content) and, in each one, either remove the shortcode or replace it with the text you want shown. The chat screen is the place to draft that text.', 'alpaca-bot'),
                '<code>[alpacabot</code>',
            ));
    }

    /** Where to get help, as 0.4's Define::support() listed it, plus the issue tracker. */
    private function support(): string
    {
        return self::p(esc_html__('Questions, bug reports and feature requests are welcome in any of these places.', 'alpaca-bot'))
            . self::list([
                self::link('https://discord.gg/vWQTHphkVt', __('Join the Discord community', 'alpaca-bot')) . ' — ' . esc_html__('the quickest way to get an answer.', 'alpaca-bot'),
                self::link('https://github.com/carmelosantana/alpaca-bot/issues', __('Open an issue on GitHub', 'alpaca-bot')) . ' — ' . esc_html__('for a bug or a feature request. Say which plugin, WordPress and PHP versions you run.', 'alpaca-bot'),
                self::link('https://www.patreon.com/carmelosantana', __('Become a Patreon', 'alpaca-bot')) . ' — ' . esc_html__('premium support, video calls and help setting up your provider, and it funds the plugin\'s development.', 'alpaca-bot'),
            ]);
    }

    private static function p(string $html): string
    {
        return '<p>' . $html . '</p>';
    }

    /** @param list<string> $items already-escaped HTML, one per item */
    private static function list(array $items): string
    {
        return '<ul>' . implode('', array_map(static fn(string $item): string => '<li>' . $item . '</li>', $items)) . '</ul>';
    }

    private static function link(string $url, string $text): string
    {
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($text) . '</a>';
    }
}
