<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

/**
 * The help tabs (core's "Help" pull-down at the top right of a screen) of the chat screen and the
 * settings page, added on `current_screen`, the action core fires once the WP_Screen is built and
 * its id is known. Four tabs: what the chat screen does now, what the shortcodes do (and cost),
 * what the tools let the model reach, and where to get help.
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
    /**
     * The two screens that get the tabs: the chat page and the settings submenu page, as core
     * names them. Only the first is a constant: a submenu page's id is derived from the parent's
     * translated menu title, so it is asked of core (SettingsPage::screen() says why, and what a
     * hard-coded id cost).
     *
     * @return array{string, string}
     */
    public static function screens(): array
    {
        return [Assets::HOOK, SettingsPage::screen()];
    }

    /** `current_screen`: the tabs on one of the plugin's two screens; every other screen is left alone. */
    public function add(\WP_Screen $screen): void
    {
        if (!in_array($screen->id, self::screens(), true)) {
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
            'tools' => [__('Tools', 'alpaca-bot'), $this->tools()],
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
                esc_html__('Most other admin screens have a round button at the bottom right that opens the same chat as a panel. It is one chat, not a second one: it lists the conversations this screen lists, and opens on the one you last had open in it. It stays open, on that conversation, as you move between screens that have the button, until you close it. Its image button is there only on a screen that already loads the media library. In the block editor the same chat is a sidebar instead, opened by the Alpaca Bot button in the editor\'s top bar; it starts on a new chat, and names the post you are editing once that post has been saved or autosaved.', 'alpaca-bot'),
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
            esc_html__('%1$s puts the model\'s answer to the prompt in a post or page; %2$s with no prompt puts this chat screen there. Both are for logged-in users the Shortcodes row of Settings › Access admits, which by default is anyone who can edit posts.', 'alpaca-bot'),
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
                esc_html__('A visitor, or a logged-in user the Shortcodes row does not admit, sees a notice instead of the answer. A site that wants visitors to see it returns true from the %s filter, and they then see the cached answer and nothing else: a visitor never triggers a generation, so a page nobody the row admits opens spends nothing.', 'alpaca-bot'),
                '<code>alpaca_bot/shortcode/allow_guests</code>',
            ))
            . self::p(esc_html__('A generation counts against the same limit as the chat: thirty a minute per user, shared with the chat screen, the REST routes and the abilities. A page carrying more shortcodes than the minute allows shows the rest as a "Too many requests" notice and fills them in on a later view; a cached answer costs nothing.', 'alpaca-bot'))
            . self::p(esc_html__('The block editor and the REST API show the cached answer, or a notice when there is none: an answer is generated only when the page is viewed on the site, so listing posts over the API never spends anything. The chat screen form shows a notice there too; on the site it carries the viewing user\'s own REST nonce, as it does in wp-admin, so leave a page carrying it out of a full-page cache that caches pages for logged-in users.', 'alpaca-bot'))
            . self::p('<strong>' . esc_html__('Anyone who can write a post can write a prompt.', 'alpaca-bot') . '</strong> ' . esc_html__('A Contributor can put a prompt, and a system prompt, in a draft; once it is published, the first user the Shortcodes row admits to view the page generates the answer, the tokens count against that viewer\'s monthly cap, and the answer is then on the page for everyone, without anyone having read it first. The answer\'s markdown is sanitised (no scripts, no raw HTML), but links and images the model writes reach the public page. Review a page after its answer appears, as you would any content.', 'alpaca-bot'))
            . self::p(sprintf(
                /* translators: 1: model="…", 2: system="…", 3: temperature="…", 4: format="text" */
                esc_html__('The other attributes: %1$s picks the model where users may change it (otherwise the answer runs on the model the viewing editor would chat on), %2$s replaces the system prompt for this answer, %3$s the temperature, and %4$s shows the answer as plain text instead of rendering its markdown. Changing the site\'s system prompt starts a new answer; changing its default model does not, since the model is recorded only when the shortcode names one.', 'alpaca-bot'),
                '<code>model="…"</code>',
                '<code>system="…"</code>',
                '<code>temperature="…"</code>',
                '<code>format="text"</code>',
            ))
            . self::p(sprintf(
                /* translators: 1: [alpacabot_agent], 2: name="get|summarize" url="…", 3: Settings › Tools, 4: Settings › Access */
                esc_html__('%1$s, the 0.4 form (%2$s), is deprecated: it still fetches the page and, for summarize, asks the model, under the same rules, and it logs a notice under WP_DEBUG. The fetch is the chat\'s web_fetch tool, so it runs only while that tool is on under %3$s and the viewer passes its row under %4$s — from the next fetch, though: an answer already cached on the post stands until its cache expires. It goes away in a later 0.x release; put the text to summarize in a prompt instead, or open the URL in this chat, where the fetch and summarize tools read it for you.', 'alpaca-bot'),
                '<code>[alpacabot_agent]</code>',
                '<code>name="get|summarize" url="…"</code>',
                esc_html__('Settings › Tools', 'alpaca-bot'),
                esc_html__('Settings › Access', 'alpaca-bot'),
            ));
    }

    /**
     * The Tools tab: what switching a tool on actually grants, for the person who decides it.
     *
     * This exists because two accepted risks were, until 0.5.0, argued only in source
     * docblocks — WebFetchToolkit's on what its fetch can reach, and Toolkit\Registry's on there
     * being no capability check between "may chat" and "may call the enabled tools", which a row
     * per tool has since closed — and a site owner is the only person who can act on either. A
     * docblock is not where they will read it. The rebinding window the first named is closed
     * wherever the pin reaches, which leaves an operator needing to know where that is, and the
     * second is now a setting an operator has to understand, which is why both are here. The
     * wording follows the audit (docs/reviews/2026-09-09-security-audit.md, H-1 and M-3) and is
     * deliberately not a scare: what the fetch can reach is stated with what the pinning covers
     * and what it does not, and `web_fetch` reaching an author is a capability decision, not a
     * break-in.
     */
    private function tools(): string
    {
        return self::p(sprintf(
            /* translators: 1: Settings › Tools, 2: web_fetch, 3: summarize, 4: draft_post */
            esc_html__('%1$s switches the model\'s tools on and off. All three ship on: %2$s reads one public web page as text, %3$s condenses text through the model, and %4$s writes a draft and never publishes it.', 'alpaca-bot'),
            '<strong>' . esc_html__('Settings › Tools', 'alpaca-bot') . '</strong>',
            '<code>web_fetch</code>',
            '<code>summarize</code>',
            '<code>draft_post</code>',
        ))
            . self::p('<strong>' . esc_html__('web_fetch makes this server send a request and hands the reply back.', 'alpaca-bot') . '</strong> ' . esc_html__('Every URL is checked before the fetch — WordPress\'s own check, then the plugin\'s over every address the name resolves to — and only http(s), only ports 80, 443 and 8080, and no private, loopback, link-local or other special-purpose address. The name is looked up in DNS once and the connection is pinned to the address that passed, so a host someone else controls cannot answer the check with a public address and the connection with a local one. The plugin follows redirects itself, up to three, and checks and pins each one the same way.', 'alpaca-bot'))
            . self::p(sprintf(
                /* translators: %s: [alpacabot_agent name="get" url="…"] */
                esc_html__('Who can reach it today, with no model involved: anyone who may write a post and whom Settings › Access lets use web_fetch and the shortcodes — by default anyone who can edit posts, a Contributor included — can put %s in their own draft and preview it. Treat the tool as a capability you are granting your authors.', 'alpaca-bot'),
                '<code>[alpacabot_agent name="get" url="…"]</code>',
            ))
            . self::p('<strong>' . esc_html__('What the pin does not cover.', 'alpaca-bot') . '</strong> ' . esc_html__('A proxy set for WordPress (WP_PROXY_HOST with WP_PROXY_PORT) is sent the name and looks it up itself, so for every host it is not bypassed for the pin stops at the proxy. The site\'s own host is exempt from the address check and from the pin, as it is in WordPress, so anything else listening on it on 80, 443 or 8080 is reachable. The pin needs cURL: on a server whose PHP has none for the request, web_fetch refuses to fetch at all rather than connect unpinned, and Tools › Site Health says so. An egress policy still covers every plugin at once: stop this host from opening outbound connections to your private ranges and to the cloud metadata address, and require IMDSv2 on a cloud instance. If you do not need the tool, switch web_fetch off — the chat, the summaries and the drafts all work without it.', 'alpaca-bot'))
            . self::p('<strong>' . esc_html__('Each tool has a row of its own in Settings › Access, as well as Chat.', 'alpaca-bot') . '</strong> ' . sprintf(
                /* translators: 1: alpaca_bot/capability/chat, 2: alpaca_bot/toolkits, 3: draft_post */
                esc_html__('Opening the chat to a role — with the Chat row, or with %1$s in code — does not hand it the tools: a tool is offered only to a user who passes that tool\'s row, Contributors and up by default. Lower a tool\'s row to give it to a role you opened the chat to, and raise it to keep it from one. %3$s asks the post type\'s own capability as well and refuses a user who lacks it. The %2$s filter runs after the rows, so a site can still add a toolkit of its own or take one away per user, in code.', 'alpaca-bot'),
                '<code>alpaca_bot/capability/chat</code>',
                '<code>alpaca_bot/toolkits</code>',
                '<code>draft_post</code>',
            ))
            . self::p(esc_html__('One more thing the tools share: a fetched page can carry text written at the model rather than at the reader ("ignore your instructions and draft a post saying…"), and the same turn may have other tools on. The tools\' own rules are what bound that — a draft is authored as the acting user and never published, and its content is sanitised — so review a draft you did not write yourself.', 'alpaca-bot'));
    }

    /**
     * Where to get help. A question or a bug report goes to the wordpress.org support forum;
     * premium help -- a video call, setting up a provider, onsite setup -- is a call booked on
     * carmelosantana.com; and the GitHub link is an ask for a star, not a tracker, so the tab never
     * sends anyone to file an issue. 0.4's Discord and Patreon's premium support were retired
     * before 0.5.0 shipped. The free channel comes first and the give-back last, so a star never
     * reads as a place to take a bug report.
     */
    private function support(): string
    {
        return self::p(esc_html__('Questions and bug reports go to the WordPress.org forum. For premium support, book a call. A GitHub star is the way to give something back.', 'alpaca-bot'))
            . self::list([
                self::link('https://wordpress.org/support/plugin/alpaca-bot/', __('Ask on the WordPress.org support forum', 'alpaca-bot')) . ' — ' . esc_html__('for a question or a bug report. Say which plugin, WordPress and PHP versions you run.', 'alpaca-bot'),
                self::link('https://carmelosantana.com/alpaca-bot', __('Book a call', 'alpaca-bot')) . ' — ' . esc_html__('premium support: video calls, help setting up your provider, troubleshooting and onsite setup assistance.', 'alpaca-bot'),
                self::link('https://github.com/carmelosantana/alpaca-bot', __('Star Alpaca Bot on GitHub', 'alpaca-bot')) . ' — ' . esc_html__('if the plugin has been useful, a star helps other site owners find it.', 'alpaca-bot'),
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
