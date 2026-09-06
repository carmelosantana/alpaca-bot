<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Rest\Controller;

final class SmokeTest extends TestCase
{
    /**
     * Plugin::controllers() starts from an empty list and the plugin's own controllers only
     * arrive with the route tasks, so until then nothing registers under alpaca-bot/v1 and the
     * namespace index would be absent for a reason that has nothing to do with the harness. A
     * probe controller through the documented `alpaca_bot/rest/controllers` filter keeps the
     * assertion honest: it holds only if the plugin booted, the filter fired on rest_api_init and
     * Rest\Controller registered against real core. WP_UnitTestCase drops the filter after the test.
     */
    public function test_plugin_is_loaded_and_routes_exist(): void
    {
        $this->assertTrue(class_exists(\AlpacaBot\Plugin::class));
        add_filter('alpaca_bot/rest/controllers', static function (array $controllers): array {
            $controllers[] = new class extends Controller {
                public function routes(): array
                {
                    return [['path' => '/ping', 'methods' => 'GET', 'callback' => static fn(): array => ['pong' => true], 'capability' => 'read']];
                }
            };
            return $controllers;
        });
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/alpaca-bot/v1', $routes);
        $this->assertArrayHasKey('/alpaca-bot/v1/ping', $routes);
    }
}
