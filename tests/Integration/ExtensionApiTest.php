<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\AbilitiesToolkit;
use AlpacaBot\Toolkit\Guarded;
use AlpacaBot\Toolkit\Registrar;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * The extension API over real core: the conversation hooks through the REST routes, a companion
 * toolkit saved through PUT /settings and offered by the container's registry, and the tool
 * guard in front of an ability call, with core's own `wp_pre_execute_ability` still answering
 * after it on WordPress 7.1+.
 */
final class ExtensionApiTest extends TestCase
{
    private const CATEGORY = 'alpaca-bot-ext-test';
    private const ABILITY = 'alpaca-bot-ext-test/echo';

    public function tear_down(): void
    {
        // The registrar's list is for the request, and the suite is one request.
        (new \ReflectionProperty(Registrar::class, 'entries'))->setValue(null, []);
        if (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY)) {
            wp_unregister_ability(self::ABILITY);
            wp_unregister_ability_category(self::CATEGORY);
        }
        parent::tear_down();
    }

    public function test_a_turn_saves_through_conversation_saved_and_a_delete_fires_conversation_deleted(): void
    {
        $this->fakeProvider();
        $user = $this->asAdmin();
        $saved = [];
        $deleted = [];
        add_action('alpaca_bot/conversation/saved', static function ($c) use (&$saved): void {
            $saved[] = $c->id;
        });
        add_action('alpaca_bot/conversation/deleted', static function (int $id, int $userId) use (&$deleted): void {
            $deleted[] = [$id, $userId];
        }, 10, 2);

        $id = $this->rest('POST', '/chat', ['message' => 'hello there'])->get_data()['conversation_id'];
        $this->assertSame([$id], $saved);
        $this->assertTrue($this->rest('DELETE', '/conversations/' . $id)->get_data()['deleted']);
        $this->assertSame([[$id, $user]], $deleted);
    }

    public function test_a_companion_toolkit_is_saved_through_put_settings_and_offered_only_to_users_its_row_admits(): void
    {
        // A registry of its own: the container's lives for the whole suite and has no way to
        // take a toolkit back, so registering into it would leak `orders` into later tests.
        $registry = new Registry(new Store());
        $kit = new class implements ToolkitInterface {
            public function tools(): array
            {
                return [new Tool('lookup_order', 'Looks up an order.', [], static fn(): ToolResult => ToolResult::success('order 7'))];
            }

            public function guidelines(): string
            {
                return '';
            }
        };
        $this->assertTrue((new Registrar($registry))->add('orders', $kit, 'Look up an order', 'Reads one order.', 'edit_others_posts'));

        $this->asAdmin();
        $res = $this->rest('PUT', '/settings', ['toolkits.enabled' => ['web_fetch', 'orders'], 'access.tool.orders' => 'publish_posts']);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $stored = get_option(Plugin::OPTION);
        $this->assertSame(['web_fetch', 'orders'], $stored['toolkits.enabled']);
        $this->assertSame('publish_posts', $stored['access.tool.orders']);

        // A Registry over a fresh Store (TestCase says why the container's memo is not read).
        $fresh = new Registry(new Store());
        $fresh->register('orders', $kit);
        $author = self::factory()->user->create(['role' => 'author']);
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        $this->assertSame(['orders'], array_keys(array_intersect_key($fresh->enabled($author), ['orders' => 1])));
        $this->assertArrayNotHasKey('orders', $fresh->enabled($contributor));
        $this->assertArrayHasKey('access.tool.orders', Schema::fields());
    }

    public function test_the_guard_asks_before_core_and_core_s_wp_pre_execute_ability_still_refuses_after_it(): void
    {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('The Abilities API is not in this WordPress.');
        }
        if (version_compare(get_bloginfo('version'), '7.1-alpha', '<')) {
            $this->markTestSkipped('wp_pre_execute_ability is new in WordPress 7.1.');
        }
        $this->registerEcho();
        $admin = $this->asAdmin();
        update_option(Plugin::OPTION, ['toolkits.abilities' => [self::ABILITY]] + Schema::defaults());
        $inner = (new AbilitiesToolkit(new Store(), static fn(): int => $admin))->tools()[0];
        $tool = new Guarded($inner, 'abilities', $admin);

        $order = [];
        $before = static function (mixed $verdict) use (&$order): mixed {
            $order[] = 'before_call';
            return $verdict;
        };
        $core = static function (mixed $pre, string $name) use (&$order): mixed {
            if ($name !== self::ABILITY) {
                return $pre;
            }
            $order[] = 'wp_pre_execute_ability';
            return new \WP_Error('held', 'Held for review.');
        };
        add_filter('alpaca_bot/tool/before_call', $before);
        add_filter('wp_pre_execute_ability', $core, 10, 2);
        $result = $tool->execute(['text' => 'ping']);
        remove_filter('wp_pre_execute_ability', $core, 10);

        $this->assertSame(['before_call', 'wp_pre_execute_ability'], $order);
        $this->assertSame(ToolResultStatus::Error, $result->status);
        $this->assertSame('Held for review.', $result->content);

        $ran = $tool->execute(['text' => 'ping']);
        $this->assertSame(ToolResultStatus::Success, $ran->status, $ran->content);
        $this->assertStringContainsString('ping', $ran->content);
    }

    /** A public echo ability in a category of its own, registered as core's hooks would. */
    private function registerEcho(): void
    {
        $during = static function (string $hook, callable $register): void {
            $GLOBALS['wp_current_filter'][] = $hook;
            try {
                $register();
            } finally {
                array_pop($GLOBALS['wp_current_filter']);
            }
        };
        $during('wp_abilities_api_categories_init', static fn() => wp_register_ability_category(self::CATEGORY, ['label' => 'Extension test', 'description' => 'For ExtensionApiTest.']));
        $during('wp_abilities_api_init', static fn() => wp_register_ability(self::ABILITY, [
            'label' => 'Echo',
            'description' => 'Echoes its text.',
            'category' => self::CATEGORY,
            'input_schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            'output_schema' => ['type' => 'object', 'properties' => ['echo' => ['type' => 'string']]],
            'execute_callback' => static fn(array $input): array => ['echo' => $input['text']],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'meta' => ['annotations' => ['readonly' => true]],
        ]));
    }
}
