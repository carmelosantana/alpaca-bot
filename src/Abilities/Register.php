<?php

declare(strict_types=1);

namespace AlpacaBot\Abilities;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Rest\Errors;
use AlpacaBot\Toolkit\DraftPostToolkit;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Toolkit\SummarizeToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

/**
 * The bot as WordPress Abilities: `alpaca-bot/chat`, `alpaca-bot/summarize` and
 * `alpaca-bot/draft-post`, for the MCP Adapter, the WP AI Client and core's `wp-abilities/v1`
 * routes to call. Each is a thin door onto work that already exists (the Pipeline, the two
 * toolkits the registry holds), so a fix behind the door fixes every surface at once; nothing
 * here decides what a turn or a draft is.
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
 * one more place a `__return_true` could open a surface that costs money.
 *
 * The Tools setting. An ability whose toolkit is switched off (`toolkits.enabled`) stays
 * registered and refuses in its permission callback, with a WP_Error that says which switch,
 * evaluated on every call through Registry::enabled() for the calling user so the
 * `alpaca_bot/toolkits` filter counts too. Unregistering was rejected: registration happens
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
 * summarize calls SummarizeToolkit::summarize() itself rather than the model-facing
 * Tool::execute(): the Tool wrapper turns every throw into a text error, which would flatten
 * a 402 into a 400 and put the provider's own message, which quotes its URL and which
 * Errors::provider() deliberately withholds from a non-administrator, in front of anyone who
 * can edit posts. Draft-post has no throw to preserve and runs through its Tool as the model
 * does; its text refusal is `alpaca_bot_tool_error`, 400.
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
        private SummarizeToolkit $summarize,
        private DraftPostToolkit $draft,
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

    /** Whether the acting user, if there is one, holds `$capability`. */
    private function can(string $capability): bool
    {
        $userId = ($this->userId)();
        return $userId > 0 && user_can($userId, $capability);
    }

    /**
     * Whether the acting user may create the requested post type: its own capability, and the
     * draft_post toolkit on. An unknown type is refused outright; the schema refuses it first.
     *
     * @param array<string, mixed> $input
     */
    private function canDraft(array $input): bool|\WP_Error
    {
        $capability = DraftPostToolkit::TYPES[(string) ($input['post_type'] ?? 'post')] ?? null;
        return $capability !== null && $this->can($capability) ? $this->gate('draft_post') : false;
    }

    /** True while the toolkit `$id` is switched on for the acting user; else the refusal that names the switch. */
    private function gate(string $id): true|\WP_Error
    {
        if (array_key_exists($id, $this->registry->enabled(($this->userId)()))) {
            return true;
        }
        return new \WP_Error(
            'alpaca_bot_toolkit_disabled',
            /* translators: %s: the tool's id, e.g. summarize */
            sprintf(__('The %s tool is switched off on this site. An administrator can enable it under Alpaca Bot > Settings > Tools.', 'alpaca-bot'), $id),
            ['status' => 403],
        );
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
     * docblock). The gate is asked again here: the setting can change between the permission
     * check and the run, and a client that calls execute_callback without check_permissions
     * (core's execute() always does; a third-party adapter may not) must still meet it.
     *
     * @param array<string, mixed> $input
     * @return array{summary: string}|\WP_Error
     */
    private function summarize(array $input): array|\WP_Error
    {
        $gate = $this->gate('summarize');
        if ($gate instanceof \WP_Error) {
            return $gate;
        }
        try {
            $result = $this->summarize->summarize((string) ($input['text'] ?? ''), (string) ($input['length'] ?? 'medium'));
        } catch (\Throwable $e) {
            return Errors::fromPipeline($e);
        }
        return ['summary' => $result->content];
    }

    /**
     * The draft_post tool as the model runs it, its JSON answer reduced to the two keys the
     * output schema promises.
     *
     * @param array<string, mixed> $input
     * @return array{id: int, edit_url: string}|\WP_Error
     */
    private function draft(array $input): array|\WP_Error
    {
        $gate = $this->gate('draft_post');
        if ($gate instanceof \WP_Error) {
            return $gate;
        }
        $tool = self::tool($this->draft, 'draft_post');
        if ($tool === null) {
            return new \WP_Error('alpaca_bot_tool_error', __('The draft_post tool is not available.', 'alpaca-bot'), ['status' => 500]);
        }
        $args = ['title' => (string) ($input['title'] ?? ''), 'content' => (string) ($input['content'] ?? '')];
        if (isset($input['post_type'])) {
            $args['post_type'] = (string) $input['post_type'];
        }
        $result = $tool->execute($args);
        if ($result->status !== ToolResultStatus::Success) {
            return new \WP_Error('alpaca_bot_tool_error', $result->content, ['status' => 400]);
        }
        $data = json_decode($result->content, true);
        $data = is_array($data) ? $data : [];
        return ['id' => (int) ($data['id'] ?? 0), 'edit_url' => (string) ($data['edit_url'] ?? '')];
    }

    /** The tool named `$name` in `$toolkit`, by name rather than position so a toolkit that grows a second tool keeps working. */
    private static function tool(ToolkitInterface $toolkit, string $name): ?ToolInterface
    {
        foreach ($toolkit->tools() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }
        return null;
    }
}
