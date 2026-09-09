<?php

declare(strict_types=1);

namespace AlpacaBot\Shortcodes;

use AlpacaBot\Admin\Assets;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Chat\Shell;
use AlpacaBot\View\Markdown;

/**
 * `[alpacabot]`, on the new pipeline, in two forms.
 *
 * With a `prompt`, the model's answer is put in the page: one ephemeral turn through
 * Pipeline::complete() (billed to the viewer, a receipt written, no conversation kept), rendered
 * as markdown through the same View\Markdown the chat screen uses, or as escaped text with
 * `format="text"`. `model`, `system` and `temperature` are the turn's options (`model` is
 * honoured under the same rule as everywhere else: only while `chat.user_can_change_model` is
 * on and the catalog lists it). Without a prompt, the chat screen's Shell is rendered on the
 * page and its bundle enqueued (Assets::enqueueFront()), for a logged-in viewer who can edit
 * posts; anyone else sees a notice. That form is a preview of the front-end chat a later 0.x
 * release designs (its own bundle, a guest policy); here it is the admin screen on a page.
 *
 * The prompt form is what 0.4's `[alpacabot]` was: content generated at render time. P3 shipped
 * no shortcodes rather than that, because a page nobody is watching would go on spending
 * provider tokens on every view, and this class exists to bring the feature back without that.
 * Two rules do it, and they are the design, not details:
 *
 * A viewer who is not logged in with `edit_posts` never triggers generation. They may be served
 * what an editor's view already cached, and only when the site says so through filter
 * `alpaca_bot/shortcode/allow_guests`; with no cache entry they get the login notice, not a
 * generated answer. So a site with visitors sees the answer an editor primed, for as long as
 * the cache holds, and a page nobody with the capability opens again spends nothing. The
 * capability is not filterable: the plugin's one guard for a filtered capability (Capability)
 * exists because `__return_true` on such a filter opens a surface to every role, and this is
 * the surface where that costs money.
 *
 * The cache is the spend control, not an optimisation. The answer is a transient keyed on the
 * attributes that shape the generation and on the post (cacheKey()), so two identical
 * shortcodes on two pages are two answers and one page's prompt never serves another's; the
 * default is an hour, and only the word `off` switches it off (cacheSeconds() says what else
 * a value can be, and that a mistyped one keeps the default rather than reading as "off").
 * Within one request the same shortcode is generated once whatever the cache says (`$served`):
 * a theme that runs `the_content` twice, or the REST API's `rendered` beside the page, must not
 * spend twice. A failed turn is shown to the editor as a notice and cached as nothing, so the
 * next view tries again; that costs the provider's timeout per view while it is down, and
 * caching a failure would cost showing a stale one after it is back.
 *
 * The attributes are the post author's, and the output lands on a public page, so the answer
 * goes through Markdown (raw HTML stripped, links held, wp_kses over an allowlist) or through
 * esc_html(), and a notice is escaped as it is built. What an author who can write shortcodes
 * but not unfiltered HTML gains here is a model turn with their prompt, billed to whoever views
 * the page with `edit_posts`, held to that viewer's monthly cap; a `system` attribute is a
 * prompt, and a prompt is what the shortcode is for. `temperature` is held to the schema's
 * range and `model` to the catalog (the pipeline refuses one it does not list).
 *
 * The shell form carries no post context: the page the shortcode is on is not "the post being
 * edited", and a context block on every turn would spend the page's length in tokens each time.
 *
 * @since 0.5.0
 */
final class Chat
{
    public const TAG = 'alpacabot';

    /** The capability a viewer needs to trigger generation or to open the shell. */
    public const CAPABILITY = 'edit_posts';

    /** The `cache` attribute's default, as an author would write it. */
    public const DEFAULT_CACHE = '1h';

    /** The transient prefix; ShortcodesTest reads the options table for it. */
    public const TRANSIENT_PREFIX = 'alpaca_bot_shortcode_';

    private const DEFAULT_SECONDS = 3600;

    /** The units a `cache` duration may end in. */
    private const UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    /** @var array<string, string> the text this request already produced, by cache key */
    private array $served = [];

    public function __construct(
        private Store $store,
        private ModelCatalog $catalog,
        private ConversationStore $conversations,
        private UserPrefs $prefs,
        private Pipeline $pipeline,
        private Markdown $markdown,
        private Assets $assets,
    ) {}

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * The shortcode handler, as core calls one: `$atts` is '' when the shortcode has none.
     *
     * @param array<string, mixed>|string $atts
     */
    public function render(array|string $atts = '', ?string $content = null, string $tag = self::TAG): string
    {
        $a = self::attributes($atts);
        if ($a['prompt'] === '') {
            return $this->shell();
        }
        $identity = ['prompt' => $a['prompt'], 'model' => $a['model'], 'system' => $a['system'], 'temperature' => $a['temperature']];
        return $this->answer(self::TAG, $identity, $a['cache'], $a['format'], function (int $userId) use ($a): string {
            $options = ['ephemeral' => true, 'context' => []];
            if ($a['model'] !== '') {
                $options['model'] = $a['model'];
            }
            if ($a['system'] !== '') {
                $options['system'] = $a['system'];
            }
            if ($a['temperature'] !== null) {
                $options['temperature'] = $a['temperature'];
            }
            return trim($this->pipeline->complete($userId, $a['prompt'], $options)->reply->content);
        });
    }

    /**
     * The capability and cache rules of the class docblock around one generation, for this
     * shortcode and for the `[alpacabot_agent]` shim, which is the same rules over a different
     * generator. `$identity` is what shapes the text (the attributes, less the ones about
     * presentation), `$generate` produces it for the viewer's id and may throw, and its text is
     * what is cached and memoised, before `$format` is applied.
     *
     * @param array<string, mixed> $identity
     * @param \Closure(int): string $generate
     */
    public function answer(string $tag, array $identity, int $cacheSeconds, string $format, \Closure $generate): string
    {
        $postId = self::postId();
        $key = self::cacheKey($tag, $identity, $postId);
        // What this request already made, else the transient (never read with the cache off).
        $cached = $this->served[$key] ?? ($cacheSeconds > 0 ? get_transient($key) : false);
        if (!self::viewerMayGenerate()) {
            /**
             * Whether a viewer without `edit_posts` may be shown a cached shortcode answer.
             * Default false: they see a notice. True serves them what an editor's view cached
             * for this post, and only that: a viewer the filter admits never triggers a
             * generation, so a page with no cache entry (expired, or never primed) shows them
             * the notice until someone with the capability opens it.
             *
             * @param bool   $allow  false
             * @param int    $postId the post being rendered, 0 outside a post
             * @param string $tag    the shortcode, `alpacabot` or `alpacabot_agent`
             */
            $allowed = (bool) apply_filters('alpaca_bot/shortcode/allow_guests', false, $postId, $tag);
            if ($allowed && is_string($cached)) {
                return $this->output($cached, $format);
            }
            return self::refused();
        }
        if (is_string($cached)) {
            return $this->output($cached, $format);
        }
        try {
            $text = $generate(get_current_user_id());
        } catch (\Throwable $e) {
            /* translators: %s: the reason, e.g. the provider's error or the monthly cap */
            return self::notice(sprintf(__('Alpaca Bot could not answer: %s', 'alpaca-bot'), $e->getMessage()));
        }
        $this->served[$key] = $text;
        if ($cacheSeconds > 0) {
            set_transient($key, $text, $cacheSeconds);
        }
        return $this->output($text, $format);
    }

    /**
     * The attributes, parsed: trimmed strings, `temperature` a float in the schema's range or
     * null when the attribute is absent or not a number (null is "the model's setting", never
     * 0), `format` one of the two the class knows (anything else is markdown, the path that
     * strips), `cache` in seconds. Through shortcode_atts(), so core's `shortcode_atts_alpacabot`
     * filter applies and an attribute the shortcode does not know is dropped.
     *
     * @param array<string, mixed>|string $atts
     * @return array{prompt: string, model: string, system: string, temperature: float|null, format: string, cache: int}
     * @internal Public only so the tests can call it; not part of the plugin's API.
     */
    public static function attributes(array|string $atts): array
    {
        $a = shortcode_atts(['prompt' => '', 'model' => '', 'system' => '', 'temperature' => '', 'format' => 'markdown', 'cache' => self::DEFAULT_CACHE], is_array($atts) ? $atts : [], self::TAG);
        $temperature = trim((string) $a['temperature']);
        return [
            'prompt' => trim((string) $a['prompt']),
            'model' => trim((string) $a['model']),
            'system' => trim((string) $a['system']),
            'temperature' => is_numeric($temperature) ? max(0.0, min(2.0, (float) $temperature)) : null,
            'format' => strtolower(trim((string) $a['format'])) === 'text' ? 'text' : 'markdown',
            'cache' => self::cacheSeconds((string) $a['cache']),
        ];
    }

    /**
     * A `cache` attribute in seconds: `off` is 0, a positive count with an optional unit
     * (`45s`, `30m`, `1h`, `2d`; bare is seconds) is that, and anything else, `0` and a blank
     * included, is the default hour. The word is the only way off on purpose: the cache is what
     * keeps a page from generating on every view, and a value that is not a duration is far
     * more often a slip than a decision to spend on every view.
     *
     * @internal Public only so the tests and the shim can call it; not part of the plugin's API.
     */
    public static function cacheSeconds(string $spec): int
    {
        $spec = strtolower(trim($spec));
        if ($spec === 'off') {
            return 0;
        }
        if (preg_match('/^(\d+)\s*([smhd])?$/', $spec, $m) === 1 && (int) $m[1] > 0) {
            return (int) $m[1] * self::UNITS[$m[2] ?? 's'];
        }
        return self::DEFAULT_SECONDS;
    }

    /**
     * The transient name for one shortcode's answer: the tag, the post and the identity, hashed
     * (a transient name is bounded at 172 characters and a prompt is not). The post is part of
     * it so two pages with the same shortcode are two entries; the tag so the shim's `url`
     * never collides with a prompt.
     *
     * @param array<string, mixed> $identity
     * @internal Public only so the tests and the shim can call it; not part of the plugin's API.
     */
    public static function cacheKey(string $tag, array $identity, int $postId): string
    {
        return self::TRANSIENT_PREFIX . md5((string) wp_json_encode([$tag, $postId, $identity]));
    }

    /** A notice in the page, escaped: the login or permission text, or why a turn failed. */
    public static function notice(string $text): string
    {
        return '<p class="alpaca-bot-notice">' . esc_html($text) . '</p>';
    }

    /** The post the shortcode is being rendered in, or 0 outside a post (a widget, a template part). */
    private static function postId(): int
    {
        $id = get_the_ID();
        return is_int($id) && $id > 0 ? $id : 0;
    }

    private static function viewerMayGenerate(): bool
    {
        return is_user_logged_in() && current_user_can(self::CAPABILITY);
    }

    /**
     * What a viewer who may not generate sees: a login link for a visitor (back to this page),
     * and for a logged-in user without the capability, who the answer is for, since a login
     * link would send them round in a circle.
     */
    private static function refused(): string
    {
        if (is_user_logged_in()) {
            return self::notice(__('Alpaca Bot answers here only for users who can edit posts.', 'alpaca-bot'));
        }
        $link = '<a href="' . esc_url(wp_login_url((string) get_permalink())) . '">' . esc_html__('Log in', 'alpaca-bot') . '</a>';
        /* translators: %s: a "Log in" link */
        return '<p class="alpaca-bot-notice">' . sprintf(esc_html__('%s to see what Alpaca Bot answers here.', 'alpaca-bot'), $link) . '</p>';
    }

    /** The text as the page shows it: markdown (raw HTML stripped, then kses) or escaped text with its line breaks. */
    private function output(string $text, string $format): string
    {
        $inner = $format === 'text' ? '<p>' . nl2br(esc_html($text)) . '</p>' : $this->markdown->toHtml($text);
        return '<div class="alpaca-bot-answer">' . $inner . '</div>';
    }

    /** The chat screen on the page, as Admin\ChatScreen builds it, a new conversation and the viewer's own history. */
    private function shell(): string
    {
        if (!self::viewerMayGenerate()) {
            return self::refused();
        }
        $userId = get_current_user_id();
        $history = $this->conversations->listFor($userId, max(1, (int) $this->store->get('chat.history_limit')));
        $shell = new Shell($this->store, $this->catalog, null, $history, 0, null, $this->prefs->modelFor($userId, $this->catalog, $this->store));
        $this->assets->enqueueFront();
        return $shell->render();
    }
}
