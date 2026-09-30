<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\AbilitiesToolkit;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

/**
 * The abilities toolkit over core's own Abilities API. The unit tests pin what the toolkit does
 * with what core answers; these pin what core answers: a real permission callback asking
 * current_user_can(), core's registry for a name it does not have (which fires _doing_it_wrong(),
 * and WP_UnitTestCase fails a test on an unexpected one), and the settings page's list.
 *
 * Core's test library unhooks core's own abilities and their categories (wp-phpunit
 * includes/functions.php, _unhook_core_abilities_registration()), so this class registers them
 * itself, the way that library's WP_AI_Client_Test_Abilities_Trait registers its own: inside a
 * simulated firing of core's hook, which is the only place core accepts a registration. It
 * unregisters what it registered once the class is done, so no other test class sees them.
 */
final class AbilitiesToolkitTest extends TestCase
{
    /** @var list<string> the abilities this class registered, to unregister afterwards */
    private static array $abilities = [];

    /** @var list<string> the categories this class registered, to unregister afterwards */
    private static array $categories = [];

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        if (!function_exists('wp_register_core_abilities') || wp_has_ability('core/get-site-info')) {
            return;
        }
        $categories = array_keys(wp_get_ability_categories());
        self::during('wp_abilities_api_categories_init', 'wp_register_core_ability_categories');
        self::$categories = array_values(array_diff(array_keys(wp_get_ability_categories()), $categories));
        $abilities = array_keys(wp_get_abilities());
        self::during('wp_abilities_api_init', 'wp_register_core_abilities');
        self::$abilities = array_values(array_diff(array_keys(wp_get_abilities()), $abilities));
    }

    public static function tear_down_after_class(): void
    {
        foreach (self::$abilities as $name) {
            wp_unregister_ability($name);
        }
        foreach (self::$categories as $slug) {
            wp_unregister_ability_category($slug);
        }
        self::$abilities = self::$categories = [];
        parent::tear_down_after_class();
    }

    /** Runs `$register` as though `$hook` were firing, which is what core's registration functions check. */
    private static function during(string $hook, callable $register): void
    {
        $GLOBALS['wp_current_filter'][] = $hook;
        try {
            $register();
        } finally {
            array_pop($GLOBALS['wp_current_filter']);
        }
    }

    public function set_up(): void
    {
        parent::set_up();
        if (!function_exists('wp_get_ability')) {
            $this->markTestSkipped('The Abilities API is not in this WordPress.');
        }
    }

    /** A toolkit over the stored option, read fresh (TestCase says why the container's Store is not). */
    private function toolkit(array $allowed, int $userId): AbilitiesToolkit
    {
        update_option('alpaca_bot_settings', ['toolkits.abilities' => $allowed] + Schema::defaults());
        return new AbilitiesToolkit(new Store(), static fn(): int => $userId);
    }

    public function test_a_core_ability_runs_through_its_own_permission_check_as_the_turns_user(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user(0);

        $tool = $this->toolkit(['core/get-site-info'], $admin)->tools()[0];
        $this->assertSame('ability__core__get-site-info', $tool->name());
        $allowed = $tool->execute([]);
        $this->assertSame(ToolResultStatus::Success, $allowed->status, $allowed->content);
        $this->assertSame(get_bloginfo('name'), json_decode($allowed->content, true)['name'] ?? null);
        // core/get-site-info asks current_user_can('manage_options') (WP 7.1 abilities.php:131-133),
        // and nobody was logged in: the toolkit is what made the turn's user current, and gave
        // the request back afterwards.
        $this->assertSame(0, get_current_user_id());

        $refused = $this->toolkit(['core/get-site-info'], $editor)->tools()[0]->execute([]);
        $this->assertSame(ToolResultStatus::Error, $refused->status);
        $this->assertSame('Ability "core/get-site-info" does not have necessary permission.', $refused->content);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_a_name_core_does_not_have_is_skipped_without_a_notice_and_the_schema_goes_as_core_prepares_it(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $tools = $this->toolkit(['gone/missing', 'core/get-site-info'], $admin)->tools();
        $this->assertSame(['ability__core__get-site-info'], array_map(static fn($t): string => $t->name(), $tools));
        $schema = wp_get_ability('core/get-site-info')->get_input_schema();
        $expected = function_exists('wp_prepare_json_schema_for_client') ? wp_prepare_json_schema_for_client($schema) : $schema;
        $this->assertEquals($expected, $tools[0]->toFunctionSchema()['function']['parameters']);
        $this->assertSame(\AlpacaBot\Toolkit\SchemaTool::describe(wp_get_ability('core/get-site-info')->get_description()), $tools[0]->description());
    }

    public function test_an_ability_a_site_filter_hides_from_the_list_is_not_offered_and_the_tab_says_so(): void
    {
        if (version_compare($GLOBALS['wp_version'], '7.1', '<')) {
            $this->markTestSkipped('wp_get_abilities_item_include is new in WordPress 7.1.');
        }
        $admin = $this->asAdmin();
        add_filter('wp_get_abilities_item_include', static fn(bool $include, \WP_Ability $ability): bool => $include && $ability->get_name() !== 'core/get-site-info', 10, 2);
        $this->assertTrue(wp_has_ability('core/get-site-info'));
        $this->assertSame([], $this->toolkit(['core/get-site-info'], $admin)->tools());

        Plugin::instance()->get(Store::class)->replace(['toolkits.abilities' => ['core/get-site-info']]);
        Plugin::instance()->get(SettingsPage::class)->register();
        $_GET['tab'] = 'toolkits';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();
        unset($_GET['tab']);
        $this->assertMatchesRegularExpression('/value="core\/get-site-info" checked="checked"> <code>core\/get-site-info<\/code><\/label> <em>\(not in this site&#039;s list of abilities; untick to remove\)<\/em>/', $html);
    }

    public function test_a_throwing_ability_becomes_a_fixed_error_and_the_current_user_is_restored(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        self::during('wp_abilities_api_init', static function (): void {
            wp_register_ability('alpaca-bot-test/throws', [
                'label' => 'Throws',
                'description' => 'Throws a transport error.',
                'category' => 'site',
                'execute_callback' => static function (): never {
                    throw new \RuntimeException('GET https://api.example.test/v1?key=sk-secret failed');
                },
                'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            ]);
        });
        $this->assertTrue(wp_has_ability('alpaca-bot-test/throws'));
        try {
            wp_set_current_user(0);
            $result = $this->toolkit(['alpaca-bot-test/throws'], $admin)->tools()[0]->execute([]);
            $this->assertSame(ToolResultStatus::Error, $result->status);
            $this->assertStringNotContainsString('sk-secret', $result->content);
            $this->assertStringNotContainsString('api.example.test', $result->content);
            $this->assertSame(0, get_current_user_id());
        } finally {
            wp_unregister_ability('alpaca-bot-test/throws');
        }
    }

    public function test_an_ability_that_switches_user_does_not_leave_the_request_running_as_that_user(): void
    {
        $admin = $this->asAdmin();
        $other = self::factory()->user->create(['role' => 'subscriber']);
        self::during('wp_abilities_api_init', static function () use ($other): void {
            wp_register_ability('alpaca-bot-test/switches', [
                'label' => 'Switches',
                'description' => 'Switches the current user and leaves it switched.',
                'category' => 'site',
                'execute_callback' => static function () use ($other): string {
                    wp_set_current_user($other);
                    return 'switched';
                },
                'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            ]);
        });
        try {
            // The turn's user is the current one, as it is wherever Plugin wires the toolkit.
            $result = $this->toolkit(['alpaca-bot-test/switches'], $admin)->tools()[0]->execute([]);
            $this->assertSame(ToolResultStatus::Success, $result->status, $result->content);
            $this->assertSame($admin, get_current_user_id());
        } finally {
            wp_unregister_ability('alpaca-bot-test/switches');
        }
    }

    public function test_the_registry_offers_the_toolkit_only_to_a_user_who_passes_the_tool_abilities_row(): void
    {
        $admin = $this->asAdmin();
        $editor = self::factory()->user->create(['role' => 'editor']);
        $registry = Plugin::instance()->get(Registry::class);
        $this->assertArrayNotHasKey('abilities', $registry->enabled($admin));

        $this->assertSame(200, $this->rest('PUT', '/settings', ['toolkits.enabled' => ['abilities'], 'toolkits.abilities' => ['core/get-site-info', 'alpaca-bot/chat']])->get_status());
        $this->assertSame(['core/get-site-info'], get_option('alpaca_bot_settings')['toolkits.abilities']);
        $this->assertInstanceOf(AbilitiesToolkit::class, $registry->enabled($admin)['abilities'] ?? null);
        $this->assertArrayNotHasKey('abilities', $registry->enabled($editor));
    }

    public function test_the_tools_tab_lists_core_s_abilities_and_leaves_alpaca_bot_s_own_out(): void
    {
        $this->asAdmin();
        Plugin::instance()->get(SettingsPage::class)->register();
        $_GET['tab'] = 'toolkits';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();
        unset($_GET['tab']);

        $this->assertStringContainsString('value="core/get-site-info"', $html);
        $this->assertStringContainsString(esc_html(wp_get_ability('core/get-site-info')->get_label()), $html);
        $this->assertStringNotContainsString('value="alpaca-bot/chat"', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="alpaca_bot_settings\[toolkits\.abilities\]\[\]" value="">/', $html);

        // options.php's path: the registered sanitize callback over what the form posts with one
        // box ticked, and then with every box clear (the sentinel alone).
        update_option('alpaca_bot_settings', ['toolkits.abilities' => ['', 'core/get-site-info']] + Schema::defaults());
        $this->assertSame(['core/get-site-info'], get_option('alpaca_bot_settings')['toolkits.abilities']);
        update_option('alpaca_bot_settings', ['toolkits.abilities' => ['']] + Schema::defaults());
        $this->assertSame([], get_option('alpaca_bot_settings')['toolkits.abilities']);
    }

    /**
     * Kanboard #4538, the throw path on real core. A listener hooked at PHP_INT_MAX during the call
     * runs after the guard's `wp_pre_execute_ability`, and this one puts core's sentinel back (it
     * captured it at PHP_INT_MIN), so the refused call goes on to `wp_before_execute_ability`,
     * where the guard throws, as it always does before 7.1. The secret still does not run, and the
     * throw, which unwound through core's do_action() and WP_Hook without either tidying up, leaves
     * no hook state behind: $wp_current_filter is as it was, doing_action() is false, and the hook
     * a listener of another plugin keeps is back at nesting level 0 and fires as usual.
     */
    public function test_a_refusal_that_has_to_throw_leaves_the_hook_state_as_it_found_it(): void
    {
        if (!class_exists('WP_Filter_Sentinel')) {
            $this->markTestSkipped('wp_pre_execute_ability is new in WordPress 7.1.');
        }
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $secretRuns = 0;
        $sentinel = null;
        $capture = static function (mixed $pre, string $name) use (&$sentinel): mixed {
            if ($name === 'alpaca-bot-test/secret') {
                $sentinel = $pre;
            }
            return $pre;
        };
        $restore = static function (mixed $pre, string $name) use (&$sentinel): mixed {
            return $name === 'alpaca-bot-test/secret' ? $sentinel : $pre;
        };
        self::during('wp_abilities_api_init', static function () use (&$secretRuns, $restore): void {
            wp_register_ability('alpaca-bot-test/meta', [
                'label' => 'Execute ability',
                'description' => 'Runs any ability by name.',
                'category' => 'site',
                'input_schema' => ['type' => 'object', 'properties' => ['ability' => ['type' => 'string']], 'required' => ['ability']],
                'execute_callback' => static function (array $in) use ($restore): mixed {
                    add_filter('wp_pre_execute_ability', $restore, PHP_INT_MAX, 2);
                    return wp_get_ability((string) $in['ability'])->execute();
                },
                'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            ]);
            wp_register_ability('alpaca-bot-test/secret', [
                'label' => 'Secret',
                'description' => 'Not on the allowlist.',
                'category' => 'site',
                'execute_callback' => static function () use (&$secretRuns): string {
                    ++$secretRuns;
                    return 'the secret';
                },
                'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            ]);
        });
        $heard = [];
        $listen = static function (string $name) use (&$heard): void {
            $heard[] = $name;
        };
        add_filter('wp_pre_execute_ability', $capture, PHP_INT_MIN, 2);
        add_action('wp_before_execute_ability', $listen);
        try {
            $stack = $GLOBALS['wp_current_filter'];
            $result = $this->toolkit(['alpaca-bot-test/meta'], $admin)->tools()[0]->execute(['ability' => 'alpaca-bot-test/secret']);
            $this->assertSame(ToolResultStatus::Error, $result->status, $result->content);
            $this->assertSame('The ' . AbilitiesToolkit::toolName('alpaca-bot-test/meta') . ' tool failed before it could answer.', $result->content);
            $this->assertInstanceOf(\WP_Filter_Sentinel::class, $sentinel);
            $this->assertSame(0, $secretRuns);
            // The listener heard the refused call before the guard threw: the path really was the throw.
            $this->assertSame(['alpaca-bot-test/meta', 'alpaca-bot-test/secret'], $heard);
            $this->assertSame($stack, $GLOBALS['wp_current_filter']);
            $this->assertFalse(doing_action('wp_before_execute_ability'));
            $hook = $GLOBALS['wp_filter']['wp_before_execute_ability'];
            $this->assertSame(0, (new \ReflectionProperty(\WP_Hook::class, 'nesting_level'))->getValue($hook));
            $this->assertFalse((new \ReflectionProperty(\WP_Hook::class, 'doing_action'))->getValue($hook));
            $this->assertSame(10, has_action('wp_before_execute_ability', $listen));
            $heard = [];
            do_action('wp_before_execute_ability', 'probe', null);
            $this->assertSame(['probe'], $heard);
        } finally {
            remove_filter('wp_pre_execute_ability', $capture, PHP_INT_MIN);
            remove_filter('wp_pre_execute_ability', $restore, PHP_INT_MAX);
            remove_action('wp_before_execute_ability', $listen);
            wp_unregister_ability('alpaca-bot-test/meta');
            wp_unregister_ability('alpaca-bot-test/secret');
        }
    }
}
