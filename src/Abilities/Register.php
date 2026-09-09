<?php

declare(strict_types=1);

namespace AlpacaBot\Abilities;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Rest\Errors;
use AlpacaBot\Rest\RateLimit;
use AlpacaBot\Toolkit\DraftPostToolkit;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Toolkit\SummarizeToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

/**
 * The bot as WordPress Abilities: `alpaca-bot/chat`, `alpaca-bot/summarize` and
 * `alpaca-bot/draft-post`, for the MCP Adapter, the WP AI Client and core's `wp-abilities/v1`
 * routes to call. Each is a thin door onto work that already exists (the Pipeline, and the
 * summarize and draft_post toolkits as the registry hands them out), so a fix behind the door
 * fixes every surface at once; nothing here decides what a turn or a draft is.
 *
 * Registration is on core's own hooks and nowhere else. `wp_register_ability()` refuses, with
 * `_doing_it_wrong()`, any call made outside `wp_abilities_api_init`, and the same goes for
 * `wp_register_ability_category()` and `wp_abilities_api_categories_init`; both hooks fire
 * from the registries' first use, after `init`. An `init` fallback was considered for sites
 * without the hook and rejected: the plugin's floor is 6.9, which is the release that put the
 * API in core with this hook, so on every supported site the fallback could only fire the
 * notice and register nothing. A site whose WordPress has no API at all is the other case, and
 * that one is `$exists`: the two entry points ask it (function_exists() by default) before
 * calling anything, so the plugin loads and the abilities are simply absent. It is injected
 * because Brain Monkey cannot stub function_exists(), and a branch that cannot be tested is
 * one that rots.
 *
 * The category is registered by us: core refuses an ability whose category is not in its
 * categories registry, and that registry is built, and its hook fired, before the abilities
 * one, which is the order Plugin::register() hooks the two methods in.
 *
 * Who may call. The acting user is the closure's answer when a callback runs, never the
 * current user asked at registration (the registries are built once per process and
 * registration is not a request), and the capability is asked about that id, user_can(),
 * for the reason DraftPostToolkit gives: the same id authors the draft and owns the
 * conversation, so the check and the act cannot disagree. Chat and summarize need
 * `edit_posts`, the capability every other surface that spends (the REST routes, the
 * shortcode, the chat screen) asks for; a draft needs the post type's own capability, from
 * DraftPostToolkit::TYPES, which is what the editor asks before showing a New button. The
 * capabilities are not filterable here: core's `wp_ability_permission_result` filter exists
 * for exactly that, with the ability name and the input in hand, and a second seam would be
 * one more place a `__return_true` could open a surface that costs money. Every execute
 * callback asks the capability (and the toolkit switch, below) again itself, before it runs
 * anything: core's execute() always runs check_permissions first and exposes no way round it,
 * but a third-party adapter, or a site's own `ability_class`, may hand the callback a call the
 * permission callback never saw, and a callback that is only safe when something else checked
 * first is not defensible on its own. A refusal there is the WP_Error the REST routes' own
 * permission callback answers (Errors::forbidden(): `rest_forbidden`, 403 or 401).
 *
 * The rate limit. Chat and summarize spend tokens, and so does `POST /alpaca-bot/v1/chat`,
 * which is limited by Rest\RateLimit at thirty a minute per user. The abilities reach the
 * pipeline through core's run route and every MCP client without that route's wrapper, so
 * the two execute callbacks record the same hit themselves: the same limiter, the same `chat`
 * bucket (one person on two surfaces is one person, as Rest\Controller says of two routes) and
 * the same `alpaca_bot/rate_limit` filter, so a site that moves the limit moves it for every
 * surface at once (Shortcodes\Chat counts a shortcode's generation there too). The hit is
 * recorded after the capability and the switch, as the REST wrapper counts
 * only requests that were allowed, and before the run; the refusal is Errors::tooMany(), 429
 * with `retry_after` in its data, the one place a client of an ability can read it (there is
 * no response header to carry Retry-After on this path). Without this the monthly caps were
 * the only brake, and both default to 0, unlimited. Draft-post spends nothing and is not
 * limited, as the REST routes that write a post are not.
 *
 * The Tools setting. An ability whose toolkit is switched off (`toolkits.enabled`) stays
 * registered and refuses in its permission callback, with a WP_Error that says which switch,
 * evaluated on every call through Registry::enabled() for the calling user so the
 * `alpaca_bot/toolkits` filter counts too. And what enabled() hands back under the id is what
 * runs, not an instance held here: a site that swaps `summarize` or `draft_post` through the
 * filter swaps it for the model and the ability alike, and never has the two answer with
 * different implementations. Unregistering was rejected: registration happens
 * once, on a hook that fires before any particular caller is known, so it would freeze one
 * moment's setting and one user's filter answer for the whole process (a test run, a
 * long-lived worker), and a client would see a 404 it cannot tell from a typo. The cost of
 * staying registered is that MCP's discovery lists a tool that then answers 403 with a
 * reason; the alternative was a tool that silently vanished. Chat is not gated by the
 * setting: it is not a toolkit, and what its turn may run is the pipeline's own decision
 * through the same registry.
 *
 * Refusals keep their codes. A pipeline throw is mapped by Rest\Errors::fromPipeline() to the
 * WP_Error the `/alpaca-bot/v1/chat` route answers (402 for a spent cap, 400 for the caller's
 * mistake, 502 for the provider), so one turn told two ways refuses the same way. That is why
 * summarize calls SummarizeToolkit::summarize() itself, when the toolkit under the id is the
 * plugin's own, rather than the model-facing Tool::execute(): the Tool wrapper turns every
 * throw into a text error, which would flatten a 402 into a 400 and put the provider's own
 * message, which quotes its URL and which Errors::provider() deliberately withholds from a
 * non-administrator, in front of anyone who can edit posts. A toolkit a site swapped in has no
 * throw of ours to preserve and runs through its `summarize` tool as the model runs it.
 * Draft-post has no throw to preserve either and always runs through its `draft_post` tool;
 * a tool's text refusal is `alpaca_bot_tool_error`, 400.
 *
 * Input. Every schema says `additionalProperties: false`, so a key that is not named is a 400
 * from core before any callback runs, and no key a client sends can reach the pipeline as an
 * option: the options are built here, from the named keys, and nothing else. `meta.public` is
 * true as well as `show_in_rest`: `public` (7.1) is what the MCP Adapter keys on; an ability
 * without it is registered, listed by REST, and invisible to MCP. The annotations are honest:
 * none of the three is read-only or idempotent (a turn spends tokens and a draft is a new
 * post), and none is destructive.
 *
 * `label` and `description` are translated: they are shown to people in admin surfaces and
 * client tool lists, unlike the toolkits' model-facing descriptions, which are English by
 * design.
 *
 * @since 0.5.0
 */
final class Register
{
    public const CATEGORY = 'alpaca-bot';
    public const CHAT = 'alpaca-bot/chat';
    public const SUMMARIZE = 'alpaca-bot/summarize';
    public const DRAFT_POST = 'alpaca-bot/draft-post';

    /** @var \Closure(string): bool */
    private \Closure $exists;

    /**
     * @param \Closure(): int $userId the acting user's id, resolved when a callback runs (the class docblock says why)
     * @param (callable(string): bool)|null $exists function_exists() or a stand-in for it
     */
    public function __construct(
        private Pipeline $pipeline,
        private Registry $registry,
        private \Closure $userId,
        ?callable $exists = null,
    ) {
        $this->exists = $exists === null ? function_exists(...) : \Closure::fromCallable($exists);
    }

    /** On `wp_abilities_api_categories_init`: the category the three abilities claim. */
    public function registerCategory(): void
    {
        if (!($this->exists)('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category(self::CATEGORY, [
            'label' => __('Alpaca Bot', 'alpaca-bot'),
            'description' => __('Chat with the site\'s model, summarize text, and draft posts through Alpaca Bot.', 'alpaca-bot'),
        ]);
    }

    /**
     * On `wp_abilities_api_init`: definitions() through filter `alpaca_bot/abilities`
     * (array<string, array<string, mixed>>: id => the wp_register_ability() arguments), then
     * one wp_register_ability() per entry. The filter is where a site drops an ability it does
     * not want reachable, or adds one of its own under any namespace; an entry whose key is not
     * a string or whose value is not an array is dropped rather than handed to core, which
     * would refuse it with a notice.
     */
    public function register(): void
    {
        if (!($this->exists)('wp_register_ability')) {
            return;
        }
        /**
         * Filters the abilities the plugin registers with the WordPress Abilities API, keyed by
         * ability id. Drop an entry to keep that ability off every surface the API feeds (WP-CLI,
         * MCP servers, other plugins) without touching the REST routes, or add one of your own
         * under any namespace. An entry whose key is not a string or whose value is not an array
         * is dropped before core sees it, which would otherwise refuse it with a notice.
         *
         * @since 0.5.0
         * @param array<string, array<string, mixed>> $abilities ability id => the wp_register_ability() arguments
         * @var mixed $filtered what the filter returned, checked before it is trusted
         */
        $filtered = apply_filters('alpaca_bot/abilities', $this->definitions());
        foreach (is_array($filtered) ? $filtered : [] as $id => $args) {
            if (is_string($id) && is_array($args)) {
                wp_register_ability($id, $args);
            }
        }
    }

    /**
     * The three abilities as wp_register_ability() takes them, by id. Pure: nothing is read
     * from WordPress or the settings until a callback runs.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            self::CHAT => $this->ability(
                __('Chat', 'alpaca-bot'),
                __('Send one message to the site\'s assistant and get its reply. Continues a conversation the caller owns when a conversation id is given, else starts one. Counts against the caller\'s monthly usage.', 'alpaca-bot'),
                [
                    'message' => ['type' => 'string', 'minLength' => 1, 'description' => __('The message to send.', 'alpaca-bot')],
                    'conversation_id' => ['type' => 'integer', 'minimum' => 0, 'description' => __('A conversation of the caller\'s to continue; 0 or absent starts a new one.', 'alpaca-bot')],
                    'model' => ['type' => 'string', 'description' => __('A model to use for this turn, when the site lets users choose one.', 'alpaca-bot')],
                ],
                ['message'],
                [
                    'conversation_id' => ['type' => 'integer', 'description' => __('The conversation the turn was stored in.', 'alpaca-bot')],
                    'reply' => ['type' => 'string', 'description' => __('The assistant\'s reply.', 'alpaca-bot')],
                    'receipt' => ['type' => 'object', 'description' => __('The usage receipt: model, tokens, duration.', 'alpaca-bot')],
                ],
                ['conversation_id', 'reply', 'receipt'],
                fn(mixed $input = null): bool => $this->can('edit_posts'),
                fn(mixed $input = null): array|\WP_Error => $this->chat(is_array($input) ? $input : []),
            ),
            self::SUMMARIZE => $this->ability(
                __('Summarize', 'alpaca-bot'),
                __('Condense a piece of text with the site\'s model. Nothing is stored; the tokens count against the caller\'s monthly usage.', 'alpaca-bot'),
                [
                    'text' => ['type' => 'string', 'minLength' => 1, 'description' => __('The text to summarize.', 'alpaca-bot')],
                    'length' => ['type' => 'string', 'enum' => ['short', 'medium', 'long'], 'description' => __('How long the summary should be; medium is the default.', 'alpaca-bot')],
                ],
                ['text'],
                ['summary' => ['type' => 'string', 'description' => __('The summary.', 'alpaca-bot')]],
                ['summary'],
                fn(mixed $input = null): bool|\WP_Error => $this->can('edit_posts') ? $this->gate('summarize') : false,
                fn(mixed $input = null): array|\WP_Error => $this->summarize(is_array($input) ? $input : []),
            ),
            self::DRAFT_POST => $this->ability(
                __('Draft a post', 'alpaca-bot'),
                __('Create a draft post or page, authored by the caller, for review in the editor. It never publishes.', 'alpaca-bot'),
                [
                    'title' => ['type' => 'string', 'description' => __('The post title.', 'alpaca-bot')],
                    'content' => ['type' => 'string', 'description' => __('The post body, as HTML or Markdown.', 'alpaca-bot')],
                    'post_type' => ['type' => 'string', 'enum' => array_keys(DraftPostToolkit::TYPES), 'description' => __('A post (the default) or a page.', 'alpaca-bot')],
                ],
                ['title', 'content'],
                [
                    'id' => ['type' => 'integer', 'description' => __('The draft\'s post id.', 'alpaca-bot')],
                    'edit_url' => ['type' => 'string', 'description' => __('Where to review it in the editor.', 'alpaca-bot')],
                ],
                ['id', 'edit_url'],
                fn(mixed $input = null): bool|\WP_Error => $this->canDraft(is_array($input) ? $input : []),
                fn(mixed $input = null): array|\WP_Error => $this->draft(is_array($input) ? $input : []),
            ),
        ];
    }

    /**
     * One definition in core's argument shape (WP_Ability::prepare_properties() is the list).
     *
     * @param array<string, array<string, mixed>> $input the input properties
     * @param list<string> $required which of them are required
     * @param array<string, array<string, mixed>> $output the output properties
     * @param list<string> $produced which of them are always there
     * @return array<string, mixed>
     */
    private function ability(string $label, string $description, array $input, array $required, array $output, array $produced, \Closure $permission, \Closure $execute): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'category' => self::CATEGORY,
            'input_schema' => ['type' => 'object', 'properties' => $input, 'required' => $required, 'additionalProperties' => false],
            'output_schema' => ['type' => 'object', 'properties' => $output, 'required' => $produced],
            'permission_callback' => $permission,
            'execute_callback' => $execute,
            'meta' => [
                'show_in_rest' => true,
                'public' => true,
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ];
    }

    /** Whether the acting user, if there is one, holds `$capability`; '' (no capability named) is never held. */
    private function can(string $capability): bool
    {
        $userId = ($this->userId)();
        return $capability !== '' && $userId > 0 && user_can($userId, $capability);
    }

    /**
     * Whether the acting user may create the requested post type: its own capability, and the
     * draft_post toolkit on. An unknown type is refused outright; the schema refuses it first.
     *
     * @param array<string, mixed> $input
     */
    private function canDraft(array $input): bool|\WP_Error
    {
        return $this->can(self::draftCapability($input)) ? $this->gate('draft_post') : false;
    }

    /**
     * The capability the requested post type asks for, from the map DraftPostToolkit enforces
     * itself; '' for a type it does not know, which can() never grants.
     *
     * @param array<string, mixed> $input
     */
    private static function draftCapability(array $input): string
    {
        return DraftPostToolkit::TYPES[(string) ($input['post_type'] ?? 'post')] ?? '';
    }

    /** True while the toolkit `$id` is switched on for the acting user; else the refusal that names the switch. */
    private function gate(string $id): true|\WP_Error
    {
        $toolkit = $this->toolkit($id);
        return $toolkit instanceof \WP_Error ? $toolkit : true;
    }

    /**
     * The toolkit under `$id` for the acting user, as Registry::enabled() hands it out (the
     * setting, then the `alpaca_bot/toolkits` filter), or the refusal that names the switch.
     */
    private function toolkit(string $id): ToolkitInterface|\WP_Error
    {
        $toolkit = $this->registry->enabled(($this->userId)())[$id] ?? null;
        if ($toolkit !== null) {
            return $toolkit;
        }
        return new \WP_Error(
            'alpaca_bot_toolkit_disabled',
            /* translators: %s: the tool's id, e.g. summarize */
            sprintf(__('The %s tool is switched off on this site. An administrator can enable it under Alpaca Bot > Settings > Tools.', 'alpaca-bot'), $id),
            ['status' => 403],
        );
    }

    /**
     * One hit on the limiter the REST routes share, for the acting user, in their `chat`
     * bucket; the 429 when the minute is spent.
     */
    private function limit(): true|\WP_Error
    {
        $hit = (new RateLimit())->hit(($this->userId)(), 'chat');
        return $hit['allowed'] ? true : Errors::tooMany($hit['retry_after']);
    }

    /**
     * One stored turn as the acting user. The options are built from the named keys only, the
     * way Rest\ChatController::create() builds them.
     *
     * @param array<string, mixed> $input
     * @return array{conversation_id: int, reply: string, receipt: array<string, int|string>}|\WP_Error
     */
    private function chat(array $input): array|\WP_Error
    {
        if (!$this->can('edit_posts')) {
            return Errors::forbidden();
        }
        $limit = $this->limit();
        if ($limit instanceof \WP_Error) {
            return $limit;
        }
        try {
            $result = $this->pipeline->complete(($this->userId)(), (string) ($input['message'] ?? ''), [
                'conversation_id' => max(0, (int) ($input['conversation_id'] ?? 0)),
                'model' => sanitize_text_field((string) ($input['model'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            return Errors::fromPipeline($e);
        }
        return ['conversation_id' => $result->conversation->id, 'reply' => $result->reply->content, 'receipt' => $result->receipt];
    }

    /**
     * The summarize toolkit's own turn, called directly so a throw keeps its type (the class
     * docblock), or a swapped-in toolkit's `summarize` tool as the model runs it. The
     * capability and the switch are asked again here: the setting can change between the
     * permission check and the run, and a client that calls execute_callback without
     * check_permissions must still meet both.
     *
     * @param array<string, mixed> $input
     * @return array{summary: string}|\WP_Error
     */
    private function summarize(array $input): array|\WP_Error
    {
        if (!$this->can('edit_posts')) {
            return Errors::forbidden();
        }
        $toolkit = $this->toolkit('summarize');
        if ($toolkit instanceof \WP_Error) {
            return $toolkit;
        }
        $limit = $this->limit();
        if ($limit instanceof \WP_Error) {
            return $limit;
        }
        $text = (string) ($input['text'] ?? '');
        if ($toolkit instanceof SummarizeToolkit) {
            try {
                $result = $toolkit->summarize($text, (string) ($input['length'] ?? 'medium'));
            } catch (\Throwable $e) {
                return Errors::fromPipeline($e);
            }
            return ['summary' => $result->content];
        }
        $args = ['text' => $text];
        if (isset($input['length'])) {
            $args['length'] = (string) $input['length'];
        }
        $summary = self::run($toolkit, 'summarize', $args);
        return $summary instanceof \WP_Error ? $summary : ['summary' => $summary];
    }

    /**
     * The draft_post tool as the model runs it, its JSON answer reduced to the two keys the
     * output schema promises. The post type's capability and the switch are asked again here,
     * as summarize() says.
     *
     * @param array<string, mixed> $input
     * @return array{id: int, edit_url: string}|\WP_Error
     */
    private function draft(array $input): array|\WP_Error
    {
        if (!$this->can(self::draftCapability($input))) {
            return Errors::forbidden();
        }
        $toolkit = $this->toolkit('draft_post');
        if ($toolkit instanceof \WP_Error) {
            return $toolkit;
        }
        $args = ['title' => (string) ($input['title'] ?? ''), 'content' => (string) ($input['content'] ?? '')];
        if (isset($input['post_type'])) {
            $args['post_type'] = (string) $input['post_type'];
        }
        $content = self::run($toolkit, 'draft_post', $args);
        if ($content instanceof \WP_Error) {
            return $content;
        }
        $data = json_decode($content, true);
        $data = is_array($data) ? $data : [];
        return ['id' => (int) ($data['id'] ?? 0), 'edit_url' => (string) ($data['edit_url'] ?? '')];
    }

    /**
     * The tool named `$name` in `$toolkit` (Registry::tool()) run with `$args`, as the model
     * runs it: its content on success, else its text refusal as `alpaca_bot_tool_error` (400),
     * or a 500 when the toolkit has no such tool.
     *
     * @param array<string, mixed> $args
     */
    private static function run(ToolkitInterface $toolkit, string $name, array $args): string|\WP_Error
    {
        $tool = Registry::tool($toolkit, $name);
        if ($tool === null) {
            /* translators: %s: the tool's id, e.g. draft_post */
            return new \WP_Error('alpaca_bot_tool_error', sprintf(__('The %s tool is not available.', 'alpaca-bot'), $name), ['status' => 500]);
        }
        $result = $tool->execute($args);
        if ($result->status !== ToolResultStatus::Success) {
            return new \WP_Error('alpaca_bot_tool_error', $result->content, ['status' => 400]);
        }
        return $result->content;
    }
}
