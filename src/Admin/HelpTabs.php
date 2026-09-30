<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Toolkit\SchemaTool;
use AlpacaBot\View\Settings\McpTools;

/**
 * The help tabs (core's "Help" pull-down at the top right of a screen) of the chat screen and the
 * settings page, added on `current_screen`, the action core fires once the WP_Screen is built and
 * its id is known. The tabs, in order: what the chat screen does now, what the shortcodes do (and
 * cost), what the tools let the model reach, who may use each part of the plugin, and where to
 * get help.
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
            'access' => [__('Access', 'alpaca-bot'), $this->access()],
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
                esc_html__('Most other admin screens have a round button at the bottom right that opens the same chat as a panel. It is one chat, not a second one: it lists the conversations this screen lists, and opens on the one you last had open in it or in the block editor\'s sidebar. It stays open, on that conversation, as you move between screens that have the button, until you close it. Its image button is there only on a screen that already loads the media library. In the block editor the same chat is a sidebar instead, opened by the Alpaca Bot button in the editor\'s top bar; it opens on the conversation you last had open in it or in the panel, and names the post you are editing once that post has been saved or autosaved.', 'alpaca-bot'),
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
     *
     * The abilities tool is here for the same reason: what it can reach is set by other plugins'
     * code, one ability at a time, and the person ticking an ability is the one who has to know
     * that a call runs as the chatting user under that ability's own check.
     */
    private function tools(): string
    {
        return self::p(sprintf(
            /* translators: 1: Settings › Tools, 2: web_fetch, 3: summarize, 4: draft_post */
            esc_html__('%1$s switches the model\'s tools on and off. %2$s reads one public web page as text, %3$s condenses text through the model, and %4$s writes a draft and never publishes it; those ship on. The abilities tool ships off: it offers the model the site\'s WordPress abilities you tick on the same tab.', 'alpaca-bot'),
            '<strong>' . esc_html__('Settings › Tools', 'alpaca-bot') . '</strong>',
            '<code>web_fetch</code>',
            '<code>summarize</code>',
            '<code>draft_post</code>',
        ))
            . self::p('<strong>' . esc_html__('web_fetch makes this server send a request and hands the reply back.', 'alpaca-bot') . '</strong> ' . esc_html__('Every URL is checked before the fetch — WordPress\'s own check, then the plugin\'s over every address the name resolves to — and only http(s), only ports 80, 443 and 8080, and no private, loopback, link-local or other special-purpose address. The name is looked up in DNS once and the connection can go only to an address that passed, so a host someone else controls cannot answer the check with a public address and the connection with a local one. The plugin follows redirects itself, up to three, and checks and pins each one the same way.', 'alpaca-bot'))
            . self::p(sprintf(
                /* translators: %s: [alpacabot_agent name="get" url="…"] */
                esc_html__('Who can reach it today, with no model involved: anyone who may write a post and whom Settings › Access lets use web_fetch and the shortcodes — by default anyone who can edit posts, a Contributor included — can put %s in their own draft and preview it. Treat the tool as a capability you are granting your authors.', 'alpaca-bot'),
                '<code>[alpacabot_agent name="get" url="…"]</code>',
            ))
            . self::p('<strong>' . esc_html__('What the pin does not cover.', 'alpaca-bot') . '</strong> ' . esc_html__('A proxy set for WordPress (WP_PROXY_HOST with WP_PROXY_PORT) is sent the name and looks it up itself, so for every host it is not bypassed for the pin stops at the proxy. The site\'s own host is exempt from the address check and from the pin, as it is in WordPress, so anything else listening on it on 80, 443 or 8080 is reachable. The pin needs cURL: on a server whose PHP has none for the request, web_fetch refuses to fetch at all rather than connect unpinned, and Tools › Site Health says so. An egress policy still covers every plugin at once: stop this host from opening outbound connections to your private ranges and to the cloud metadata address, and require IMDSv2 on a cloud instance. If you do not need the tool, switch web_fetch off — the chat, the summaries and the drafts all work without it.', 'alpaca-bot'))
            . self::p('<strong>' . esc_html__('An ability is another plugin\'s code, run as the user whose turn it is.', 'alpaca-bot') . '</strong> ' . sprintf(
                /* translators: 1: Settings › Tools, 2: alpaca-bot/*, 3: Settings › Access, 4: the most characters of a result the model is given, e.g. 8000 */
                esc_html__('Ticking an ability under %1$s offers it to the model as a tool. Each call runs as the user whose turn it is and goes through that ability\'s own permission check, so what the call may do is the ability\'s decision, made for that user. A fetched page can ask the model to call one, so tick only abilities you would let that happen with. The list shows each description shortened and flattened exactly as the model gets it, since it is the other plugin\'s text; an ability\'s input schema carries text of its own, which Alpaca Bot neither cleans nor caps and which is not shown here. The list flags an ability whose own annotations call it destructive, and marks two abilities that would reach the model under the same tool name, neither of which is offered while both are ticked. Alpaca Bot\'s own abilities (%2$s) are never on the list, so the model is not handed them as tools. The list decides only what the model may call directly: an ability that itself runs other abilities, such as a "run any ability" tool, reaches whatever those can do, so tick one only knowing that. Alpaca Bot\'s own chat and summarize are the exception: they refuse to start a turn while one is running, so a turn cannot start another through such a tool; the model reads the refusal as the tool\'s result, and the turn goes on. A result longer than %4$s characters is cut, and the model is told so. Its row under %3$s starts at Administrators.', 'alpaca-bot'),
                '<strong>' . esc_html__('Settings › Tools', 'alpaca-bot') . '</strong>',
                '<code>alpaca-bot/*</code>',
                '<strong>' . esc_html__('Settings › Access', 'alpaca-bot') . '</strong>',
                (string) SchemaTool::RESULT_CHARS,
            ))
            . self::p('<strong>' . esc_html__('An MCP server\'s tools are another party\'s code, and their descriptions and results are its text.', 'alpaca-bot') . '</strong> ' . sprintf(
                /* translators: 1: Settings › Tools, 2: Settings › Access, 3: alpaca_bot/mcp/called, 4: the most characters of a tool's input schema the list shows, e.g. 4000 */
                esc_html__('Discover tools asks the server for its tools and lists them under %1$s, and the model is offered only the tools you tick there, named prefix__tool, and only while the server still describes each one as it did when you ticked it. A tool the server has changed is withheld until you approve it again, and a name the server lists twice is never offered. A tool\'s description and its results are the server\'s text, which the model is told to treat as data; the list shows each description shortened and flattened exactly as the model gets it. A tool\'s input schema carries the server\'s text too, which Alpaca Bot neither cleans nor caps: that text reaches the model as the server sent it, and approving a tool pins its input schema with the rest, so the list shows each tool\'s schema under it, collapsed, with every character outside ASCII written as its \\u escape so that none can hide, and cut at %4$s characters of that text. Each server has a row of its own under %2$s, and it starts at Administrators. A server\'s address is checked when it is saved from this screen or over the REST API, and again each time Alpaca Bot connects to it: on Discover tools, and on a chat turn that lists its tools. It has to be https, and a private or other special-purpose address is refused even when it is the site\'s own host, which web_fetch may reach. The connection goes only to an address that passed, never through a proxy, and follows no redirect. Put a credential in the header, never in the address: the address, its query string included, is stored as typed and shown on this screen. Every call fires %3$s with its arguments, the user and the result, for a site that wants a record of them.', 'alpaca-bot'),
                '<strong>' . esc_html__('Settings › Tools', 'alpaca-bot') . '</strong>',
                '<strong>' . esc_html__('Settings › Access', 'alpaca-bot') . '</strong>',
                '<code>alpaca_bot/mcp/called</code>',
                (string) McpTools::SCHEMA_CHARS,
            ))
            . self::p('<strong>' . esc_html__('Each tool has a row of its own in Settings › Access, as well as Chat.', 'alpaca-bot') . '</strong> ' . sprintf(
                /* translators: 1: alpaca_bot/capability/chat, 2: alpaca_bot/toolkits, 3: draft_post, 4: web_fetch, 5: summarize */
                esc_html__('Opening the chat to a role — with the Chat row, or with %1$s in code — does not hand it the tools: a tool is offered only to a user who passes that tool\'s row, Contributors and up by default for %4$s, %5$s and %3$s, Administrators for the abilities tool. Lower a tool\'s row to give it to a role you opened the chat to, and raise it to keep it from one. %3$s asks the post type\'s own capability as well and refuses a user who lacks it. The %2$s filter runs after the rows, so a site can still add a toolkit of its own or take one away per user, in code.', 'alpaca-bot'),
                '<code>alpaca_bot/capability/chat</code>',
                '<code>alpaca_bot/toolkits</code>',
                '<code>draft_post</code>',
                '<code>web_fetch</code>',
                '<code>summarize</code>',
            ))
            . self::p(esc_html__('One more thing the tools share: a fetched page can carry text written at the model rather than at the reader ("ignore your instructions and draft a post saying…"), and the same turn may have other tools on. The tools\' own rules are what bound that — a draft is authored as the acting user and never published, and its content is sanitised — so review a draft you did not write yourself.', 'alpaca-bot'));
    }

    /**
     * The Access tab: what each row of Settings › Access decides, and what "Set in code" under a
     * row means. It exists because a row is a default a filter may override, not an answer, and
     * an administrator has to know that before reading the tab as the site's policy; and because
     * the tool floor (Kanboard #4331) changes what opening the chat to a role does, which belongs
     * in the product and not only in a changelog. The rows are named by the labels the tab gives
     * them, the tool rows together.
     */
    private function access(): string
    {
        return self::p(sprintf(
            /* translators: %s: Settings › Access */
            esc_html__('%s decides who may use each part of Alpaca Bot, one capability per row: Administrators, Editors and up, Authors and up, Contributors and up, or Any logged-in user.', 'alpaca-bot'),
            '<strong>' . esc_html__('Settings › Access', 'alpaca-bot') . '</strong>',
        ))
            . self::list([
                '<strong>' . esc_html__('Chat', 'alpaca-bot') . '</strong> ' . esc_html__('is the chat screen, its panel on other admin screens, the block editor sidebar and the chat REST routes. Contributors and up by default.', 'alpaca-bot'),
                esc_html__('Each tool has a row of its own, and a user needs that row as well as Chat: opening the chat to a role does not hand that role the tools. The fetch, summarize and draft tools start at Contributors and up, the site\'s abilities tool and each MCP server at Administrators.', 'alpaca-bot'),
                sprintf(
                    /* translators: 1: Read settings over REST, 2: Write settings over REST */
                    esc_html__('%1$s and %2$s decide who may read and who may change the settings through the REST API. Both start at Administrators. This settings page always needs an administrator, whatever they say.', 'alpaca-bot'),
                    '<strong>' . esc_html__('Read settings over REST', 'alpaca-bot') . '</strong>',
                    '<strong>' . esc_html__('Write settings over REST', 'alpaca-bot') . '</strong>',
                ),
                '<strong>' . esc_html__('Shortcodes', 'alpaca-bot') . '</strong> ' . sprintf(
                    /* translators: 1: [alpacabot prompt="…"], 2: [alpacabot_agent] */
                    esc_html__('is who triggers a generation by viewing a page carrying %1$s, or the deprecated %2$s, which generates through the same rules. Contributors and up by default; a visitor never does.', 'alpaca-bot'),
                    '<code>[alpacabot prompt="…"]</code>',
                    '<code>[alpacabot_agent]</code>',
                ),
            ])
            . self::p('<strong>' . esc_html__('Set in code', 'alpaca-bot') . '</strong> ' . sprintf(
                /* translators: 1: the alpaca_bot/capability/… filter names, 2: alpaca_bot/capability/settings, 3: [alpacabot], 4: [alpacabot_agent] */
                esc_html__('under a row means a filter in a plugin, a theme or an mu-plugin moves that row off what you chose, and the note names the capability checked instead, or says the filter could not be asked from this page. A row\'s filter is %1$s, named after the row; the settings rows run 0.5\'s %2$s first. The filter wins. What you choose is still saved, and is what applies once no filter changes it. The note is what the filter answers when this page asks it: for you, with a request the page builds, and for the Shortcodes row as %3$s and %4$s outside any post, each asked and named on its own. A filter that answers differently for another user, request or post gets no note for that.', 'alpaca-bot'),
                '<code>alpaca_bot/capability/…</code>',
                '<code>alpaca_bot/capability/settings</code>',
                '<code>[alpacabot]</code>',
                '<code>[alpacabot_agent]</code>',
            ))
            . self::p(sprintf(
                /* translators: 1: alpaca_bot/admin/menu_capability, 2: alpaca_bot/capability/{route}, 3: alpaca_bot/capability/chat, 4: POST /chat */
                esc_html__('The Chat row has no filter of that kind. The note under it asks %1$s, which filters the chat screen, its panel and the editor sidebar, and the filter of every chat REST route, %2$s (%3$s for the %4$s route, and one per route besides), once for each method the route takes, such as GET and DELETE, and names each route and method that moved it.', 'alpaca-bot'),
                '<code>alpaca_bot/admin/menu_capability</code>',
                '<code>alpaca_bot/capability/{route}</code>',
                '<code>alpaca_bot/capability/chat</code>',
                '<code>POST /chat</code>',
            ));
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
