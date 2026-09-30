<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Access;
use AlpacaBot\Mcp\ClientFactory;
use AlpacaBot\Mcp\Drift;
use AlpacaBot\Mcp\McpToolkit;
use AlpacaBot\Mcp\ServerConfig;
use AlpacaBot\Mcp\Toolkits;
use AlpacaBot\Mcp\ToolDefinition;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * An MCP server as the registry offers it over real core: a row saved through the REST route
 * (its header value split off into Mcp\Secrets by the option's own filter), real roles answering
 * its `mcp.<id>` row through user_can(), and the option narrowed with update_option().
 *
 * The client is FakeClient, handed in through a ClientFactory of the test's own, so nothing
 * leaves the process. The address is a public IP literal, as McpSettingsTest's is, so the save's
 * address check looks nothing up.
 *
 * @group mcp
 */
final class McpToolkitTest extends TestCase
{
    private const URL = 'https://93.184.216.34/mcp';

    private const SECRET = 'Bearer int-mcp-4d1e';

    public function tear_down(): void
    {
        Drift::set('trk', []);
        parent::tear_down();
    }

    public function test_a_server_with_an_approved_tool_is_offered_to_the_users_its_access_row_admits(): void
    {
        $admin = $this->asAdmin();
        $search = new ToolDefinition('search', 'Search.', ['type' => 'object']);
        $this->saveServer(['search' => $search->fingerprint()]);
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        $editor = self::factory()->user->create(['role' => 'editor']);
        $seen = [];
        $factory = new ClientFactory(static function (ServerConfig $server) use (&$seen, $search): FakeClient {
            $seen[] = $server;
            return new FakeClient([$search], ['search' => ToolResult::success('hit')]);
        });

        $kit = $this->registry($factory)->enabled($admin)['mcp.trk'] ?? null;
        $this->assertInstanceOf(McpToolkit::class, $kit);
        $this->assertSame(['trk__search'], array_map(static fn($tool): string => $tool->name(), $kit->tools()));
        // The header value came back from the secrets option, not from the row, which holds the mask.
        $this->assertSame(self::SECRET, $seen[0]->headerValue);
        $this->assertArrayNotHasKey('mcp.trk', $this->registry($factory)->enabled($subscriber));
        $this->assertArrayNotHasKey('mcp.trk', $this->registry($factory)->enabled($editor));

        $settings = get_option(Plugin::OPTION);
        $settings['access.mcp'] = ['trk' => 'edit_posts'];
        update_option(Plugin::OPTION, $settings);

        $this->assertArrayHasKey('mcp.trk', $this->registry($factory)->enabled($editor));
        $this->assertArrayNotHasKey('mcp.trk', $this->registry($factory)->enabled($subscriber));
    }

    /**
     * The plugin's own registry offers the server through the container's ClientFactory, which
     * builds the real client everywhere but here: bootstrap.php replaces its builder with one that
     * refuses (TestCase::OFFLINE_MCP). A client that cannot be built is a server with no tools and
     * no guidelines, never a failed turn, and the drift marker is left as it was.
     */
    public function test_the_plugins_registry_offers_the_server_and_a_client_it_cannot_build_lists_nothing(): void
    {
        $admin = $this->asAdmin();
        $this->saveServer(['search' => str_repeat('a', 64)]);
        Drift::set('trk', ['search']);

        $kit = Plugin::instance()->get(Registry::class)->enabled($admin)['mcp.trk'] ?? null;
        $this->assertInstanceOf(McpToolkit::class, $kit);
        $this->assertSame([], $kit->tools());
        $this->assertSame('', $kit->guidelines());
        $this->assertSame(['search'], Drift::get('trk'));
    }

    /**
     * N-2: rows written straight into the option never met Schema::sanitizeMcpServers(), so the
     * registry holds them to the same prefix rule: `trk` and `trk_` would put `_x` and `x` under
     * one name, `trk___x`, and only the row whose prefix the rule admits is offered.
     */
    public function test_a_row_written_round_the_schema_is_held_to_the_prefix_rule(): void
    {
        global $wpdb;
        $admin = $this->asAdmin();
        $row = static fn(string $id, string $prefix): array => ['id' => $id, 'url' => 'https://' . $id . '.example.com/mcp', 'header_name' => '', 'header_value' => '', 'prefix' => $prefix, 'approved' => ['x' => str_repeat('a', 64), '_x' => str_repeat('b', 64)]];
        delete_option(Plugin::OPTION);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the point is a write no filter or sanitize callback sees.
        $wpdb->insert($wpdb->options, ['option_name' => Plugin::OPTION, 'option_value' => serialize(['toolkits.mcp_servers' => [$row('trk', 'trk'), $row('trku', 'trk_')]]), 'autoload' => 'off']);
        wp_cache_delete(Plugin::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        $this->assertSame(['trk', 'trk_'], array_column(get_option(Plugin::OPTION)['toolkits.mcp_servers'], 'prefix'));

        $this->assertSame(['mcp.trk'], array_keys($this->registry(new ClientFactory(static fn(ServerConfig $server): FakeClient => new FakeClient()))->enabled($admin)));
    }

    /** @param array<string, string> $approved */
    private function saveServer(array $approved): void
    {
        $res = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [[
            'url' => self::URL, 'prefix' => 'trk', 'header_name' => 'Authorization', 'header_value' => self::SECRET,
            'approved' => $approved,
        ]]]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame('trk', get_option(Plugin::OPTION)['toolkits.mcp_servers'][0]['id']);
    }

    /** A registry as Plugin::register() builds one, over a Store that reads the option afresh, as the next request would. */
    private function registry(ClientFactory $factory): Registry
    {
        $store = new Store();
        $access = new Access($store);
        return new Registry($store, $access, new Toolkits($store, $access, $factory));
    }
}
