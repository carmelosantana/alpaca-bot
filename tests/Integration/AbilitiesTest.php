<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\AbilitiesToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Config\ModelDefinition;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * The three abilities over core's own Abilities API: registration on core's hooks, core's input
 * validation, core's permission gate, and the `wp-abilities/v1` routes that list and run them.
 * The unit tests pin what each callback does; these pin that core reaches the callbacks, and
 * refuses before it does.
 *
 * Core's registries are process singletons built on first use, so the first test here that
 * touches an ability is the one on which `wp_abilities_api_init` fires; every later test sees
 * the same registered abilities, whose callbacks read the setting and the user when called.
 */
final class AbilitiesTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('The Abilities API is not in this WordPress.');
        }
    }

    /** A `wp-abilities/v1` request: a JSON body under `input` for a run, query parameters for a listing. */
    private function abilities(string $method, string $path, ?array $input = null): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/wp-abilities/v1' . $path);
        if ($input !== null && $method === 'POST') {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode(['input' => $input]));
        } elseif ($input !== null) {
            $request->set_query_params($input);
        }
        return rest_get_server()->dispatch($request);
    }

    /** @return list<\WP_Post> */
    private function posts(string $type, int $author): array
    {
        return get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'author' => $author]);
    }

    /** A provider that fails the test if a turn reaches it. */
    private function noProvider(): void
    {
        add_filter('alpaca_bot/provider', function (): never {
            $this->fail('a turn reached the provider');
        });
    }

    public function test_the_category_and_the_three_abilities_are_registered_with_core_and_shown_to_rest_and_mcp(): void
    {
        $category = wp_get_ability_category('alpaca-bot');
        $this->assertNotNull($category);
        $this->assertSame('Alpaca Bot', $category->get_label());
        $this->assertSame(['alpaca-bot/chat', 'alpaca-bot/summarize', 'alpaca-bot/draft-post'], array_keys(wp_get_abilities(['namespace' => 'alpaca-bot'])));
        foreach (['alpaca-bot/chat', 'alpaca-bot/summarize', 'alpaca-bot/draft-post'] as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, $name);
            $this->assertSame('alpaca-bot', $ability->get_category());
            $this->assertTrue($ability->get_meta_item('show_in_rest'), $name);
            // Pins `meta.public`: from v0.6.0 the MCP Adapter's default server lists an ability on it when `meta.mcp.public` is absent (McpAbilityExposure::is_meta_public()), and Register's class docblock says the rest.
            $this->assertTrue($ability->get_meta_item('public'), $name);
            $this->assertSame(['readonly' => false, 'destructive' => $name === 'alpaca-bot/chat', 'idempotent' => false], $ability->get_meta_item('annotations'), $name);
        }
    }

    public function test_chat_runs_a_stored_turn_as_the_current_user_and_continues_it(): void
    {
        $admin = $this->asAdmin();
        $this->fakeProvider();
        $ability = wp_get_ability('alpaca-bot/chat');
        $this->assertNotNull($ability);
        $out = $ability->execute(['message' => 'hi']);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame(['conversation_id', 'reply', 'receipt'], array_keys($out));
        $this->assertSame('fake reply', $out['reply']);
        $this->assertGreaterThan(0, $out['conversation_id']);
        $this->assertSame($admin, $out['receipt']['user_id']);
        $this->assertSame(5, $out['receipt']['total_tokens']);
        $post = get_post($out['conversation_id']);
        $this->assertNotNull($post);
        $this->assertSame(ConversationStore::POST_TYPE, $post->post_type);
        $this->assertSame((string) $admin, $post->post_author);

        $again = $ability->execute(['message' => 'and again', 'conversation_id' => $out['conversation_id']]);
        $this->assertIsArray($again);
        $this->assertSame($out['conversation_id'], $again['conversation_id']);
        $this->assertCount(4, get_post_meta($out['conversation_id'], ConversationStore::META_MESSAGES, true));
    }

    public function test_an_input_the_schema_refuses_never_reaches_the_pipeline(): void
    {
        $admin = $this->asAdmin();
        $this->noProvider();
        $ability = wp_get_ability('alpaca-bot/chat');
        $this->assertNotNull($ability);
        foreach ([
            'no message' => [],
            'empty message' => ['message' => ''],
            'conversation_id not an integer' => ['message' => 'hi', 'conversation_id' => 'abc'],
            'negative conversation_id' => ['message' => 'hi', 'conversation_id' => -1],
            'a pipeline option smuggled in' => ['message' => 'hi', 'ephemeral' => true],
            'not an object' => 'hi',
        ] as $case => $input) {
            $out = $ability->execute($input);
            $this->assertWPError($out, $case);
            $this->assertSame('ability_invalid_input', $out->get_error_code(), $case);
        }
        $this->assertSame([], $this->posts(ConversationStore::POST_TYPE, $admin));
        $this->assertSame([], $this->posts(UsageMeter::POST_TYPE, $admin));

        // Over REST the same refusal is a 400, before the permission check.
        $response = $this->abilities('POST', '/abilities/alpaca-bot/chat/run', ['message' => 'hi', 'ephemeral' => true]);
        $this->assertSame(400, $response->get_status(), (string) wp_json_encode($response->get_data()));
    }

    public function test_chat_refuses_a_user_without_edit_posts_and_a_visitor_before_anything_runs(): void
    {
        $this->fakeProvider();
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $ability = wp_get_ability('alpaca-bot/chat');
        $this->assertNotNull($ability);
        $out = $ability->execute(['message' => 'hi']);
        $this->assertWPError($out);
        $this->assertSame('ability_invalid_permissions', $out->get_error_code());
        $this->assertSame([], $this->posts(ConversationStore::POST_TYPE, $subscriber));
        $this->assertSame([], $this->posts(UsageMeter::POST_TYPE, $subscriber));
        $response = $this->abilities('POST', '/abilities/alpaca-bot/chat/run', ['message' => 'hi']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('rest_ability_cannot_execute', $response->get_data()['code']);

        wp_set_current_user(0);
        $out = $ability->execute(['message' => 'hi']);
        $this->assertWPError($out);
        $this->assertSame('ability_invalid_permissions', $out->get_error_code());
        $this->assertSame(401, $this->abilities('POST', '/abilities/alpaca-bot/chat/run', ['message' => 'hi'])->get_status());
    }

    public function test_summarize_over_rest_is_one_ephemeral_turn_and_is_refused_once_its_toolkit_is_switched_off(): void
    {
        $admin = $this->asAdmin();
        $this->fakeProvider();
        $response = $this->abilities('POST', '/abilities/alpaca-bot/summarize/run', ['text' => 'A long text.', 'length' => 'short']);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame(['summary' => 'fake reply'], $response->get_data());
        // Ephemeral: a receipt for the admin, and no conversation.
        $this->assertCount(1, $this->posts(UsageMeter::POST_TYPE, $admin));
        $this->assertSame([], $this->posts(ConversationStore::POST_TYPE, $admin));

        Plugin::instance()->get(Store::class)->replace(['toolkits.enabled' => ['web_fetch', 'draft_post']]);
        $response = $this->abilities('POST', '/abilities/alpaca-bot/summarize/run', ['text' => 'A long text.']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('alpaca_bot_toolkit_disabled', $response->get_data()['code']);
        // The reason survives on this path, and only on this path: core's run controller calls
        // check_permissions() from its own REST permission callback and returns our WP_Error, so
        // the operator is told which switch and where to flip it.
        $this->assertStringContainsString('switched off on this site', $response->get_data()['message']);
        // It names both screens and not one tab: Registry::enabled() hands back an absence, not a
        // reason, so a toolkit held back by this user's Settings › Access row reads here exactly
        // like one switched off under Tools, and a message that named only Tools would send an
        // operator to the wrong tab half the time.
        $this->assertStringContainsString('Settings › Access', $response->get_data()['message']);
        $this->assertStringContainsString('Alpaca Bot > Settings', $response->get_data()['message']);
        // Direct execution -- the MCP and WP-AI-Client path -- is the other half: core withholds
        // the message on purpose and answers a fixed code, passing our text to _doing_it_wrong()
        // instead, so a WP_DEBUG site gets a "doing it wrong" notice on every call to a
        // switched-off tool. The class docblock's account of the split is these two assertions.
        $this->setExpectedIncorrectUsage('WP_Ability::execute');
        $out = wp_get_ability('alpaca-bot/summarize')->execute(['text' => 'A long text.']);
        $this->assertWPError($out);
        $this->assertSame('ability_invalid_permissions', $out->get_error_code());
        $this->assertStringNotContainsString('switched off on this site', $out->get_error_message());
        $this->assertCount(1, $this->posts(UsageMeter::POST_TYPE, $admin));
        // Still registered and still listed: the switch is the administrator's to flip back, and a client is told why, not shown a 404.
        $this->assertNotNull(wp_get_ability('alpaca-bot/summarize'));
    }

    public function test_draft_post_creates_a_draft_for_the_current_user_and_a_page_needs_edit_pages(): void
    {
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);
        $this->noProvider();
        $response = $this->abilities('POST', '/abilities/alpaca-bot/draft-post/run', ['title' => 'From an ability', 'content' => '<p>Body</p><script>alert(1)</script>']);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $data = $response->get_data();
        $this->assertSame(['id', 'edit_url'], array_keys($data));
        $post = get_post($data['id']);
        $this->assertNotNull($post);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('post', $post->post_type);
        $this->assertSame((string) $contributor, $post->post_author);
        $this->assertSame('<p>Body</p>alert(1)', $post->post_content);
        $this->assertStringContainsString('post=' . $data['id'], $data['edit_url']);

        // A contributor may draft posts but not pages: refused at the permission gate, nothing inserted.
        $response = $this->abilities('POST', '/abilities/alpaca-bot/draft-post/run', ['title' => 'A page', 'content' => 'Body', 'post_type' => 'page']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->posts('page', $contributor));
        $this->assertSame(400, $this->abilities('POST', '/abilities/alpaca-bot/draft-post/run', ['title' => 'x', 'content' => 'y', 'post_type' => 'attachment'])->get_status());

        Plugin::instance()->get(Store::class)->replace(['toolkits.enabled' => ['web_fetch', 'summarize']]);
        $response = $this->abilities('POST', '/abilities/alpaca-bot/draft-post/run', ['title' => 'Second', 'content' => 'Body']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('alpaca_bot_toolkit_disabled', $response->get_data()['code']);
        $this->assertCount(1, $this->posts('post', $contributor));
    }

    public function test_the_rest_listing_shows_the_three_abilities_and_the_category_to_a_logged_in_user_only(): void
    {
        $this->asAdmin();
        $response = $this->abilities('GET', '/abilities', ['namespace' => 'alpaca-bot']);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['alpaca-bot/chat', 'alpaca-bot/summarize', 'alpaca-bot/draft-post'], array_column($response->get_data(), 'name'));
        $this->assertSame(['alpaca-bot', 'alpaca-bot', 'alpaca-bot'], array_column($response->get_data(), 'category'));
        $this->assertSame(200, $this->abilities('GET', '/categories/alpaca-bot')->get_status());

        wp_set_current_user(0);
        $this->assertSame(401, $this->abilities('GET', '/abilities', ['namespace' => 'alpaca-bot'])->get_status());
    }

    public function test_chat_and_summarize_share_the_rest_routes_rate_limit_and_its_filter(): void
    {
        $admin = $this->asAdmin();
        $this->fakeProvider();
        add_filter('alpaca_bot/rate_limit', static fn(): int => 1);
        $response = $this->abilities('POST', '/abilities/alpaca-bot/summarize/run', ['text' => 'A long text.']);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $response = $this->abilities('POST', '/abilities/alpaca-bot/chat/run', ['message' => 'hi']);
        $this->assertSame(429, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame('alpaca_bot_rate_limited', $response->get_data()['code']);
        $this->assertGreaterThan(0, $response->get_data()['data']['retry_after']);
        // One bucket for the person, not one per surface: the plugin's own route is spent by the ability's hit.
        $response = $this->rest('POST', '/chat', ['message' => 'hi']);
        $this->assertSame(429, $response->get_status(), (string) wp_json_encode($response->get_data()));
        // Direct execution refuses the same way, and only the one summary was billed.
        $out = wp_get_ability('alpaca-bot/chat')->execute(['message' => 'hi']);
        $this->assertWPError($out);
        $this->assertSame('alpaca_bot_rate_limited', $out->get_error_code());
        $this->assertCount(1, $this->posts(UsageMeter::POST_TYPE, $admin));
        $this->assertSame([], $this->posts(ConversationStore::POST_TYPE, $admin));
    }

    /**
     * Kanboard #4538 over core's registry: a turn on `POST /chat` is offered a test-only
     * "execute any ability" ability, shaped like the MCP Adapter's `mcp-adapter/execute-ability`
     * (it runs wp_get_ability($name)->execute($input) and hands back what that answers), and
     * the model calls it for a test ability the allowlist leaves out (with a cache in front of it that
     * would short-circuit it on 7.1), for alpaca-bot/chat and for
     * alpaca-bot/summarize. Core would let the caller run all three, but on every version none of
     * their callbacks runs and the outer turn completes: one conversation, one receipt, one turn's
     * worth of provider calls. What the model reads back depends on the core. On 7.1 core's
     * `wp_pre_execute_ability` answers the toolkit's refusal first, so `wp_before_execute_ability`
     * never fires for them and the refusal is what the model reads. Before 7.1 there is no such
     * filter: `wp_before_execute_ability` fires for each of them, the guard throws there, and the
     * model reads the tool's fixed failure text (SchemaTool::failed()). Afterwards, outside the
     * toolkit, both abilities run as they always did.
     */
    public function test_an_execute_any_ability_tool_runs_nothing_the_allowlist_leaves_out_and_the_turn_completes(): void
    {
        $admin = $this->asAdmin();
        $GLOBALS['wp_current_filter'][] = 'wp_abilities_api_init';
        try {
            wp_register_ability('alpaca-bot-test/execute-ability', [
                'label' => 'Execute ability',
                'description' => 'Runs any ability by name.',
                'category' => 'alpaca-bot',
                'input_schema' => ['type' => 'object', 'properties' => ['ability' => ['type' => 'string'], 'input' => ['type' => 'object']], 'required' => ['ability']],
                'execute_callback' => static function (array $in): mixed {
                    $ability = wp_get_ability((string) $in['ability']);
                    return $ability === null ? new \WP_Error('not_found', 'No such ability.') : $ability->execute($in['input'] ?? null);
                },
                'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            ]);
            $secretRuns = 0;
            wp_register_ability('alpaca-bot-test/secret', [
                'label' => 'Secret',
                'description' => 'Not on the allowlist.',
                'category' => 'alpaca-bot',
                'execute_callback' => static function () use (&$secretRuns): string {
                    ++$secretRuns;
                    return 'the secret';
                },
                'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            ]);
        } finally {
            array_pop($GLOBALS['wp_current_filter']);
        }
        $before = [];
        $listen = static function (string $name) use (&$before): void {
            $before[] = $name;
        };
        add_action('wp_before_execute_ability', $listen);
        // A cache in front of the secret ability, at a late priority of its own: on 7.1 the toolkit's
        // refusal still has the last word, so the cached answer does not reach the model either.
        $cache = static fn(mixed $pre, string $name): mixed => $name === 'alpaca-bot-test/secret' ? 'the cached secret' : $pre;
        add_filter('wp_pre_execute_ability', $cache, 1000, 2);
        try {
            $store = Plugin::instance()->get(Store::class);
            $store->replace([
                'toolkits.enabled' => ['summarize', 'abilities'],
                'toolkits.abilities' => ['alpaca-bot-test/execute-ability'],
                'models.overrides' => ['fake-model' => ['tools' => Schema::TOOLS_ON]],
            ]);
            $tool = AbilitiesToolkit::toolName('alpaca-bot-test/execute-ability');
            $calls = [];
            add_filter('alpaca_bot/provider', static function () use ($tool, &$calls): ProviderInterface {
                return new class ($tool, $calls) implements ProviderInterface {
                    /** @param list<array{messages: array<mixed>, tools: array<mixed>}> $calls */
                    public function __construct(private string $tool, private array &$calls) {}

                    public function chat(array $messages, array $tools = [], array $options = []): Response
                    {
                        throw new \LogicException('not used');
                    }

                    public function stream(array $messages, array $tools = [], array $options = []): iterable
                    {
                        $this->calls[] = ['messages' => $messages, 'tools' => $tools];
                        if (count($this->calls) === 1) {
                            yield new Response('', ProviderFinishReason::ToolUse, [
                                new ToolCall('c0', $this->tool, ['ability' => 'alpaca-bot-test/secret']),
                                new ToolCall('c1', $this->tool, ['ability' => 'alpaca-bot/chat', 'input' => ['message' => 'Ask yourself again.']]),
                                new ToolCall('c2', $this->tool, ['ability' => 'alpaca-bot/summarize', 'input' => ['text' => 'Some long text.']]),
                            ], usage: new Usage(3, 1, 4));
                            return;
                        }
                        yield new Response('Done.', ProviderFinishReason::Stop);
                        yield new Response('', ProviderFinishReason::Stop, usage: new Usage(4, 2, 6));
                    }

                    public function structured(array $messages, string $schema, array $options = []): mixed
                    {
                        return [];
                    }

                    public function models(): array
                    {
                        return [new ModelDefinition('fake-model', 'Fake model', 'fake')];
                    }

                    public function isAvailable(): bool
                    {
                        return true;
                    }

                    public function getModel(): string
                    {
                        return 'fake-model';
                    }

                    public function withModel(string $model): static
                    {
                        return $this;
                    }
                };
            });

            $response = $this->rest('POST', '/chat', ['message' => 'Use the tool.']);
            $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
            $nested = ['alpaca-bot-test/secret', 'alpaca-bot/chat', 'alpaca-bot/summarize'];
            // WP_Filter_Sentinel is `wp_pre_execute_ability`'s default, and both are new in 7.1
            // (7.1 class-wp-ability.php:785-809), as the sibling test in AbilitiesToolkitTest checks.
            if (class_exists('WP_Filter_Sentinel')) {
                $refusals = array_map(
                    static fn(string $name): string => 'The ' . $name . ' ability was not run: an ability run from a chat may only run the abilities ticked under Settings › Tools.',
                    $nested,
                );
                // Only the allowlisted ability got as far as core's before-execute action: the refused ones were answered by the pre filter first.
                $heard = array_fill(0, 3, 'alpaca-bot-test/execute-ability');
            } else {
                $refusals = array_fill(0, 3, 'The ' . $tool . ' tool failed before it could answer.');
                // With no pre filter every nested call reaches the before-execute action, where this listener (hooked first) hears it before the guard throws.
                $heard = array_merge(...array_map(static fn(string $name): array => ['alpaca-bot-test/execute-ability', $name], $nested));
            }
            // A tool turn: the model was offered the test ability, and asked the provider twice, not a third time for a nested turn.
            $this->assertCount(2, $calls);
            $this->assertContains($tool, array_map(static fn(object $t): string => $t->name(), $calls[0]['tools']));
            $results = array_values(array_filter(array_map(static fn(object $m): string => (string) $m->content(), $calls[1]['messages']), static fn(string $c): bool => in_array($c, $refusals, true)));
            $this->assertSame($refusals, $results, 'each nested call reads its refusal back');
            $this->assertSame(0, $secretRuns, 'the ability the allowlist leaves out never ran');
            $this->assertSame($heard, $before);
            $data = $response->get_data();
            $this->assertSame('Done.', $data['message']['content']);
            // The reply's meta is an object on the wire; read it as a client would.
            $meta = json_decode((string) wp_json_encode($data['message']['meta']), true);
            $this->assertSame([false, false, false], array_column($meta['tool_calls'], 'ok'));
            $this->assertSame($refusals, array_column($meta['tool_calls'], 'result_excerpt'));
            $this->assertCount(1, $this->posts(ConversationStore::POST_TYPE, $admin));
            $this->assertCount(1, $this->posts(UsageMeter::POST_TYPE, $admin));
            $this->assertFalse(has_filter('wp_pre_execute_ability', [AbilitiesToolkit::class, 'preExecute']));
            $this->assertFalse(has_action('wp_before_execute_ability', [AbilitiesToolkit::class, 'beforeExecute']));

            // Outside the toolkit nothing is guarded: both run, summarize its turn.
            $calls = [];
            remove_filter('wp_pre_execute_ability', $cache, 1000);
            $this->assertSame('the secret', wp_get_ability('alpaca-bot-test/secret')->execute());
            $this->assertSame(1, $secretRuns);
            $out = wp_get_ability('alpaca-bot/summarize')->execute(['text' => 'Some long text.']);
            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertCount(1, $calls);
            $this->assertCount(2, $this->posts(UsageMeter::POST_TYPE, $admin));
        } finally {
            remove_action('wp_before_execute_ability', $listen);
            remove_filter('wp_pre_execute_ability', $cache, 1000);
            wp_unregister_ability('alpaca-bot-test/execute-ability');
            wp_unregister_ability('alpaca-bot-test/secret');
        }
    }
}
