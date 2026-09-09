<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

/**
 * The help tabs (core's "Help" pull-down at the top right of a screen) of the chat screen and the
 * settings page, added on `current_screen`, the action core fires once the WP_Screen is built and
 * its id is known. Three tabs: what the chat screen does now, what the shortcodes do (and cost),
 * and where to get help.
 *
 * 0.4's Help ran README.md through a markdown parser and made a tab of every heading. Nothing of
 * that is kept: the parser is gone with the 0.4 tree, and the tabs are written for what the
 * screens actually do. The Shortcodes tab said, while P3 registered none, that they were gone and
 * a 0.4 page printed them as text; it says what they do now that Shortcodes\Chat has them back,
 * because a help tab that lags the product is the product lying about itself.
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

    /**
     * The two shortcodes as Shortcodes\Chat and Shortcodes\AgentShim have them. What a site owner
     * needs from this tab, in order: that a prompt spends tokens when it is generated and is
     * cached so it is not generated on every view, who triggers a generation and who only ever
     * sees the cache, the chat form, and that the 0.4 agent form is deprecated.
     */
    private function shortcodes(): string
    {
        return self::p(sprintf(
            /* translators: 1: [alpacabot prompt="…"], 2: [alpacabot] */
            esc_html__('%1$s puts the model\'s answer to the prompt in a post or page; %2$s with no prompt puts this chat screen there. Both are for logged-in users who can edit posts.', 'alpaca-bot'),
            '<code>[alpacabot prompt="…"]</code>',
            '<code>[alpacabot]</code>',
        ))
            . self::p('<strong>' . esc_html__('Generating an answer costs provider tokens', 'alpaca-bot') . '</strong> ' . sprintf(
                /* translators: 1: cache="off", 2: cache="2d" */
                esc_html__('and counts against the monthly cap of the user viewing the page, so the answer is cached for an hour and served from the cache until then. %1$s generates on every view; %2$s keeps an answer for two days (s, m, h and d are the units).', 'alpaca-bot'),
                '<code>cache="off"</code>',
                '<code>cache="2d"</code>',
            ))
            . self::p(sprintf(
                /* translators: %s: the filter name alpaca_bot/shortcode/allow_guests */
                esc_html__('A visitor, or a logged-in user who cannot edit posts, sees a notice instead of the answer. A site that wants visitors to see it returns true from the %s filter, and they then see the cached answer and nothing else: a visitor never triggers a generation, so a page nobody with the capability opens spends nothing.', 'alpaca-bot'),
                '<code>alpaca_bot/shortcode/allow_guests</code>',
            ))
            . self::p(esc_html__('The block editor and the REST API show the cached answer, or a notice when there is none: an answer is generated only when the page is viewed on the site, so listing posts over the API never spends anything.', 'alpaca-bot'))
            . self::p('<strong>' . esc_html__('Anyone who can write a post can write a prompt.', 'alpaca-bot') . '</strong> ' . esc_html__('A Contributor can put a prompt, and a system prompt, in a draft; once it is published, the first user who can edit posts to view the page generates the answer, the tokens count against that viewer\'s monthly cap, and the answer is then on the page for everyone, without anyone having read it first. The answer\'s markdown is sanitised (no scripts, no raw HTML), but links and images the model writes reach the public page. Review a page after its answer appears, as you would any content.', 'alpaca-bot'))
            . self::p(sprintf(
                /* translators: 1: model="…", 2: system="…", 3: temperature="…", 4: format="text" */
                esc_html__('The other attributes: %1$s picks the model where users may change it (otherwise the answer runs on the model the viewing editor would chat on), %2$s replaces the system prompt for this answer, %3$s the temperature, and %4$s shows the answer as plain text instead of rendering its markdown. Changing the site\'s system prompt starts a new answer; changing its default model does not, since the model is recorded only when the shortcode names one.', 'alpaca-bot'),
                '<code>model="…"</code>',
                '<code>system="…"</code>',
                '<code>temperature="…"</code>',
                '<code>format="text"</code>',
            ))
            . self::p(sprintf(
                /* translators: 1: [alpacabot_agent], 2: name="get|summarize" url="…", 3: Settings › Tools */
                esc_html__('%1$s, the 0.4 form (%2$s), is deprecated: it still fetches the page and, for summarize, asks the model, under the same rules, and it logs a notice under WP_DEBUG. The fetch is the chat\'s web_fetch tool, so it runs only while that tool is on under %3$s. It goes away in a later 0.x release; put the text to summarize in a prompt instead, or open the URL in this chat, where the fetch and summarize tools read it for you.', 'alpaca-bot'),
                '<code>[alpacabot_agent]</code>',
                '<code>name="get|summarize" url="…"</code>',
                esc_html__('Settings › Tools', 'alpaca-bot'),
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
