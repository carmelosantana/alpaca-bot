<?php

declare(strict_types=1);

namespace AlpacaBot\Shortcodes;

use AlpacaBot\Admin\Assets;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Rest\Errors;
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
 * the surface where that costs money. The same rule governs a REST request: `content.rendered`
 * is produced for every item of a collection, so a `GET /wp/v2/posts?per_page=100` by an editor
 * would otherwise be up to a hundred serialized turns in one request. Inside one, an editor is
 * served the cache or a notice, never a generation; a page render on the site is where an
 * editor's view generates, which is also what the block editor's preview should show. The
 * shell form is under the same rule (shell() says what a shell in a listing would cost).
 *
 * The cache is the spend control, not an optimisation. The answer is a transient keyed on the
 * attributes that shape the generation, on the post, and on the duration (cacheKey()), so two
 * identical shortcodes on two pages are two answers, one page's prompt never serves another's,
 * and a `cache="off"` twin is never served from, or stands in for, its cached sibling; the
 * default is an hour, only the word `off` switches it off, and a year is the most an author
 * can ask for (cacheSeconds() says what else a value can be, and that a mistyped one keeps the
 * default rather than reading as "off"). Within one request the same shortcode is generated
 * once whatever the cache says (`$served`): a theme that runs `the_content` twice must not
 * spend twice. A failed turn is shown to the editor as a notice and cached as nothing, so the
 * next view tries again; that costs the provider's timeout per view while it is down, and
 * caching a failure would cost showing a stale one after it is back. What the notice says is
 * Rest\Errors::fromPipeline()'s decision, made once for every caller that runs a turn: the cap
 * and a refused model in their own words, and for a provider failure the fixed message, since
 * what the provider threw quotes its endpoint and the raw text is the debug log's.
 *
 * The attributes are the post author's, and the output lands on a public page, so the answer
 * goes through Markdown (raw HTML stripped, links held, wp_kses over an allowlist) or through
 * esc_html(), and a notice is escaped as it is built. What an author who can write shortcodes
 * but not unfiltered HTML gains here is a model turn with their prompt, billed to whoever views
 * the page with `edit_posts`, held to that viewer's monthly cap; a `system` attribute is a
 * prompt, and a prompt is what the shortcode is for. `temperature` is held to the schema's
 * range and `model` to the catalog (the pipeline refuses one it does not list). That anyone
 * who can write a post can write a prompt whose answer nobody reads before it is public, and
 * that the answer's links and images reach the page, is the feature's shape, said in the help
 * tab rather than gated here: an authoring gate would be a second copy of WordPress's own
 * contributor model, and a per-site capability setting belongs to the admin-wide panel of a
 * later 0.x release.
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

    /** The most a `cache` attribute can ask for, in seconds: a year. */
    public const MAX_SECONDS = 365 * 86400;

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
        $model = $this->authorModel($a['model']);
        // The identity is what will shape the text, as far as this can know it without asking
        // the catalog: the author's model where the site honours it (else that none was named,
        // authorModel()), and the system prompt the site resolves, so a `model=` the site does
        // not honour and none are the same entry, and a site prompt the administrator edits is
        // a new one.
        $identity = [
            'prompt' => $a['prompt'],
            'model' => $model,
            'system' => $a['system'] !== '' ? $a['system'] : (string) $this->store->get('chat.system_prompt'),
            'temperature' => $a['temperature'],
        ];
        return $this->answer(self::TAG, $identity, $a['cache'], $a['format'], function (int $userId) use ($a, $model): string {
            $options = ['ephemeral' => true, 'context' => []];
            if ($model !== '') {
                $options['model'] = $model;
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
        $key = self::cacheKey($tag, $identity, $postId, $cacheSeconds);
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
             * @since 0.5.0
             * @param bool   $allow  false
             * @param int    $postId the post being rendered, 0 outside a post
             * @param string $tag    the shortcode, `alpacabot` or `alpacabot_agent`
             */
            $allowed = (bool) apply_filters('alpaca_bot/shortcode/allow_guests', false, $postId, $tag);
            if ($allowed && is_string($cached)) {
                return $this->output($cached, $format);
            }
            return $this->refused();
        }
        if (is_string($cached)) {
            return $this->output($cached, $format);
        }
        if (wp_is_rest_endpoint()) {
            // A REST response is being produced (a standalone request, or an internal
            // dispatch): the cache was not there, and generating here is the class docblock's
            // hundred-turns-in-one-request case.
            return $this->notice(__('Alpaca Bot answers here when the page is viewed on the site; there is no cached answer yet.', 'alpaca-bot'));
        }
        try {
            $text = $generate(get_current_user_id());
        } catch (\Throwable $e) {
            /* translators: %s: the reason, in the words the REST routes use: the cap, a refused model, or the fixed provider message */
            return $this->notice(sprintf(__('Alpaca Bot could not answer: %s', 'alpaca-bot'), Errors::fromPipeline($e)->get_error_message()));
        }
        $this->served[$key] = $text;
        if ($cacheSeconds > 0) {
            set_transient($key, $text, $cacheSeconds);
        }
        return $this->output($text, $format);
    }

    /**
     * The model the author named, where the site honours it: the `model` attribute while
     * `chat.user_can_change_model` is on (the rule the pipeline applies to the option), else ''.
     * That is the only model a shortcode sends the pipeline, and the only one the cache
     * identity records. Given none, the pipeline resolves the model as it does for a chat turn:
     * the viewer's own stored preference where users may change it, else the site's default
     * with the catalog's grace (ModelCatalog::defaultId(): a default the provider has since
     * dropped falls back to the first listed model rather than refusing every turn), and the
     * identity records that none was named, never a model the turn may not have run on.
     *
     * Not the stored `models.default`, sent as an explicit option: the pipeline holds an
     * explicit model strictly to the catalog, so a default the provider dropped refused every
     * shortcode on the site while the chat screen, REST and the CLI kept answering. Not the
     * catalog's resolution either, because the catalog discovers over the network when it is
     * cold, and this runs for a visitor's view too, which must cost nothing. The cost, said in
     * the README: with no `model=` the page's answer runs on whichever model the editor who
     * primed it runs on, and changing the site's default model does not start a new answer.
     * Public for the shim, whose `model` attribute is the same attribute.
     */
    public function authorModel(string $requested): string
    {
        if ($requested !== '' && (bool) $this->store->get('chat.user_can_change_model')) {
            return $requested;
        }
        return '';
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
            // min() over a float first: a numeric string past the float range is INF, and the range holds it.
            'temperature' => is_numeric($temperature) ? max(0.0, min(2.0, (float) $temperature)) : null,
            'format' => strtolower(trim((string) $a['format'])) === 'text' ? 'text' : 'markdown',
            'cache' => self::cacheSeconds((string) $a['cache']),
        ];
    }

    /**
     * A `cache` attribute in seconds: `off` is 0, a positive count with an optional unit
     * (`45s`, `30m`, `1h`, `2d`; bare is seconds) is that, held to a year, and anything else,
     * `0` and a blank included, is the default hour. The word is the only way off on purpose:
     * the cache is what keeps a page from generating on every view, and a value that is not a
     * duration is far more often a slip than a decision to spend on every view.
     *
     * The arithmetic is in floats and clamped before the cast: a digit run is the author's,
     * and one long enough overflows an int product to a float, which the int return type
     * would refuse with a TypeError, here, outside answer()'s try and before the capability
     * check, from any Contributor's attribute, for every visitor of the published page.
     *
     * @internal Public only so the tests and the shim can call it; not part of the plugin's API.
     */
    public static function cacheSeconds(string $spec): int
    {
        $spec = strtolower(trim($spec));
        if ($spec === 'off') {
            return 0;
        }
        if (preg_match('/^(\d+)\s*([smhd])?$/', $spec, $m) === 1 && (float) $m[1] > 0) {
            return (int) min((float) $m[1] * self::UNITS[$m[2] ?? 's'], (float) self::MAX_SECONDS);
        }
        return self::DEFAULT_SECONDS;
    }

    /**
     * The transient name for one shortcode's answer: the tag, the post, the identity and the
     * cache duration, hashed (a transient name is bounded at 172 characters and a prompt is
     * not). The post is part of it so two pages with the same shortcode are two entries; the
     * tag so the shim's `url` never collides with a prompt; the duration so two shortcodes that
     * differ only in `cache` are two entries, and the `off` one's per-request memo can never
     * stand in for the other's transient, or keep it from being written.
     *
     * @param array<string, mixed> $identity
     * @internal Public only so the tests and the shim can call it; not part of the plugin's API.
     */
    public static function cacheKey(string $tag, array $identity, int $postId, int $cacheSeconds): string
    {
        return self::TRANSIENT_PREFIX . md5((string) wp_json_encode([$tag, $postId, $identity, $cacheSeconds]));
    }

    /**
     * A notice in the page, escaped: the login or permission text, or why a turn failed. Its few
     * rules are enqueued with it, here and in the two other places that print the classes
     * (output() and the visitor's link in refused()), so the stylesheet rides with the markup
     * and nowhere else; the shell's markup does not use them.
     */
    public function notice(string $text): string
    {
        $this->assets->enqueueShortcode();
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
    private function refused(): string
    {
        if (is_user_logged_in()) {
            return $this->notice(__('Alpaca Bot answers here only for users who can edit posts.', 'alpaca-bot'));
        }
        $this->assets->enqueueShortcode();
        $link = '<a href="' . esc_url(wp_login_url((string) get_permalink())) . '">' . esc_html__('Log in', 'alpaca-bot') . '</a>';
        /* translators: %s: a "Log in" link */
        return '<p class="alpaca-bot-notice">' . sprintf(esc_html__('%s to see what Alpaca Bot answers here.', 'alpaca-bot'), $link) . '</p>';
    }

    /** The text as the page shows it: markdown (raw HTML stripped, then kses) or escaped text with its line breaks. */
    private function output(string $text, string $format): string
    {
        $this->assets->enqueueShortcode();
        $inner = $format === 'text' ? '<p>' . nl2br(esc_html($text)) . '</p>' : $this->markdown->toHtml($text);
        return '<div class="alpaca-bot-answer">' . $inner . '</div>';
    }

    /**
     * The chat screen on the page, as Admin\ChatScreen builds it, a new conversation and the
     * viewer's own history. Never inside a REST request, under the rule answer() applies to a
     * prompt: `content.rendered` is produced per item of a collection, and a shell is a history
     * query and a full markup build carrying the viewer's `wp_rest` nonce, which belongs in a
     * page they are looking at, not in a hundred items of a listing.
     */
    private function shell(): string
    {
        if (!self::viewerMayGenerate()) {
            return $this->refused();
        }
        if (wp_is_rest_endpoint()) {
            return $this->notice(__('Alpaca Bot opens its chat here when the page is viewed on the site.', 'alpaca-bot'));
        }
        $userId = get_current_user_id();
        $history = $this->conversations->listFor($userId, max(1, (int) $this->store->get('chat.history_limit')));
        $shell = new Shell($this->store, $this->catalog, null, $history, 0, null, $this->prefs->modelFor($userId, $this->catalog, $this->store));
        $this->assets->enqueueFront();
        return $shell->render();
    }
}
