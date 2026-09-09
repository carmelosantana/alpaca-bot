<?php

declare(strict_types=1);

namespace AlpacaBot\Shortcodes;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

/**
 * `[alpacabot_agent name="get|summarize" url="…" length="…"]`, 0.4's agent shortcode, as a
 * deprecation shim: it still does what it did, on the new pipeline and under Shortcodes\Chat's
 * capability and cache rules (Chat::answer()), and says through _doing_it_wrong() once per
 * request that it is deprecated and what to write instead. It is a shim rather than a stub
 * because a 0.4 page carrying it renders content today; a stub would turn that into a notice
 * on upgrade, which is the breakage P3 chose to make visible by registering nothing, and this
 * task exists to end that.
 *
 * `get` is the page's readable text, as the web_fetch toolkit reads it (its address guard, its
 * size and time limits, its text extraction), shown escaped: no model turn. `summarize` is that
 * text sent through one ephemeral turn behind a prompt that begins `Summarize {url}`, with
 * `length` as 0.4 took it, free text ("2 sentences", "3 bullet points") appended as "in …".
 * The fetch is the toolkit's tool run directly, not a tool turn: an ephemeral turn runs no
 * tools (Pipeline's docblock), and here the URL is the author's, not the model's, so nothing
 * is lost by not letting the model choose it. The toolkit is the one the registry enables for
 * the viewer (Toolkit\Registry::enabled(): the `toolkits.enabled` setting, then the
 * `alpaca_bot/toolkits` filter), so an administrator who switches web_fetch off in Settings ›
 * Tools has switched off this fetch too: the setting's words are what the assistant may do,
 * and an outbound request from the server on a page's say-so is not exempt from them. The
 * shortcode then shows the editor a notice naming the setting, and caches nothing.
 *
 * What the hint says is deliberately not `[alpacabot prompt="Summarize {url}"]`: that turn
 * runs no tools either, so the model would be handed a URL it cannot open and answer from
 * nothing. The replacement that works is the text itself in the prompt, or the chat screen,
 * where the web_fetch and summarize toolkits read the URL for the user.
 *
 * @since 0.5.0
 */
final class AgentShim
{
    public const TAG = 'alpacabot_agent';

    private const AGENTS = ['get', 'summarize'];

    /** _doing_it_wrong() once per request, however many of the shortcode a page carries. */
    private bool $warned = false;

    public function __construct(private Chat $chat, private Pipeline $pipeline, private Registry $toolkits) {}

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /** @param array<string, mixed>|string $atts */
    public function render(array|string $atts = '', ?string $content = null, string $tag = self::TAG): string
    {
        $this->deprecate();
        $a = shortcode_atts(['name' => 'get', 'url' => '', 'length' => '', 'model' => '', 'cache' => Chat::DEFAULT_CACHE], is_array($atts) ? $atts : [], self::TAG);
        $name = strtolower(trim((string) $a['name']));
        $url = trim((string) $a['url']);
        $length = trim((string) $a['length']);
        $model = $this->chat->authorModel(trim((string) $a['model']));
        if (!in_array($name, self::AGENTS, true)) {
            /* translators: %s: the name attribute as written */
            return $this->chat->notice(sprintf(__('[alpacabot_agent] has no agent named "%s"; it knows get and summarize.', 'alpaca-bot'), $name));
        }
        if ($url === '') {
            return $this->chat->notice(__('[alpacabot_agent] needs a url attribute.', 'alpaca-bot'));
        }
        $identity = ['name' => $name, 'url' => $url, 'length' => $length, 'model' => $model];
        return $this->chat->answer(self::TAG, $identity, Chat::cacheSeconds((string) $a['cache']), $name === 'get' ? 'text' : 'markdown', function (int $userId) use ($name, $url, $length, $model): string {
            $page = $this->fetch($userId, $url);
            if ($name === 'get') {
                return $page;
            }
            // English on purpose, as the toolkits' own prompts are: it is read by the model.
            $prompt = 'Summarize ' . $url . ($length !== '' ? ' in ' . $length : '') . ":\n\n" . $page;
            $options = [
                'ephemeral' => true,
                'context' => [],
                'system' => 'The user sends the text of a web page and asks for a summary. Return only the summary: no preamble, no commentary, and nothing that is not in the text.',
            ];
            if ($model !== '') {
                $options['model'] = $model;
            }
            return trim($this->pipeline->complete($userId, $prompt, $options)->reply->content);
        });
    }

    /**
     * The page's text through the web_fetch tool the registry enables for this viewer. Its
     * refusal (an address that is not public, a page that is not text, an HTTP error) and the
     * tool being switched off are thrown as the caller's mistake, the arm of
     * Rest\Errors::fromPipeline() that shows the message: the URL is the author's and the
     * tool's words are written for them, and the setting is the administrator's to name.
     *
     * @throws \InvalidArgumentException with the tool's own message, or the setting's name
     * @throws \RuntimeException when the enabled toolkit has no web_fetch tool, which is a plugin bug
     */
    private function fetch(int $userId, string $url): string
    {
        $toolkit = $this->toolkits->enabled($userId)['web_fetch'] ?? null;
        if ($toolkit === null) {
            throw new \InvalidArgumentException(__('The web_fetch tool is switched off in Settings › Tools, so [alpacabot_agent] fetches nothing.', 'alpaca-bot'));
        }
        $tool = Registry::tool($toolkit, 'web_fetch');
        if ($tool === null) {
            throw new \RuntimeException(__('The web_fetch tool is not available.', 'alpaca-bot'));
        }
        $result = $tool->execute(['url' => $url]);
        if ($result->status !== ToolResultStatus::Success) {
            throw new \InvalidArgumentException($result->content);
        }
        return $result->content;
    }

    private function deprecate(): void
    {
        if ($this->warned) {
            return;
        }
        $this->warned = true;
        _doing_it_wrong('alpacabot_agent', sprintf(
            /* translators: 1: [alpacabot_agent], 2: the replacement shortcode */
            __('The %1$s shortcode is deprecated since 0.5.0 and goes away in a later 0.x release. It still fetches its URL and, for summarize, asks the model. Put the text to work on in the prompt of %2$s instead, or open the URL in the chat screen, where the web_fetch and summarize tools read it for you.', 'alpaca-bot'),
            '[alpacabot_agent]',
            '[alpacabot prompt="Summarize the following: …"]',
        ), '0.5.0');
    }
}
