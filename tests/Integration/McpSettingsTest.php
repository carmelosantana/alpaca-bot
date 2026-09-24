<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Access;
use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Mcp\ServerSettings;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * `toolkits.mcp_servers` over real core: the REST route, the settings page as options.php saves
 * it (update_option() -> sanitize_option() -> the page's callback -> the
 * `pre_update_option_alpaca_bot_settings` filter), and the options table itself, read with SQL
 * where the question is what the row holds or how it is loaded.
 *
 * Every address here is an IP literal, so no name is looked up and the suite stays off the
 * network: a public one that passes AddressPin, and private ones that do not. 203.0.113.0/24 is
 * a documentation range, which AddressPin refuses like any other special-purpose address, so it
 * cannot stand for "public" here.
 *
 * @group mcp
 */
final class McpSettingsTest extends TestCase
{
    private const URL = 'https://93.184.216.34/mcp';

    private const SECRET = 'Bearer int-secret-7f3a';

    private const OTHER = 'Bearer int-other-91c2';

    public function set_up(): void
    {
        parent::set_up();
        $this->asAdmin();
    }

    public function tear_down(): void
    {
        unset($_GET['tab']);
        $_POST = [];
        $GLOBALS['wp_settings_errors'] = [];
        $GLOBALS['wp_settings_fields'] = [];
        parent::tear_down();
    }

    public function test_a_server_saved_over_rest_keeps_its_header_value_out_of_the_autoloaded_option(): void
    {
        $res = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [[
            'url' => self::URL, 'prefix' => 'trk', 'header_name' => 'Authorization', 'header_value' => self::SECRET,
            'approved' => ['search' => str_repeat('a', 64)],
        ]]]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame(Schema::MASK, $res->get_data()['toolkits.mcp_servers'][0]['header_value']);
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($res->get_data()));

        // The settings row, straight from the table: the mask and never the value.
        $row = $this->raw(Plugin::OPTION);
        $this->assertIsString($row);
        $this->assertStringNotContainsString(self::SECRET, $row);
        $stored = maybe_unserialize($row);
        $this->assertSame('trk', $stored['toolkits.mcp_servers'][0]['id']);
        $this->assertSame(Schema::MASK, $stored['toolkits.mcp_servers'][0]['header_value']);
        $this->assertSame(['search' => str_repeat('a', 64)], $stored['toolkits.mcp_servers'][0]['approved']);

        // The value, in a row of its own that core does not autoload.
        $this->assertSame(['trk' => self::SECRET], maybe_unserialize((string) $this->raw(Secrets::OPTION)));
        $this->assertNotAutoloaded(Secrets::OPTION);
        $this->assertArrayNotHasKey(Secrets::OPTION, wp_load_alloptions(true));
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode(wp_load_alloptions(true)));

        // The read masks it; reveal answers it, from where it is kept.
        $this->assertSame(Schema::MASK, $this->rest('GET', '/settings')->get_data()['toolkits.mcp_servers'][0]['header_value']);
        $this->assertSame(self::SECRET, $this->rest('GET', '/settings', ['reveal' => 1])->get_data()['toolkits.mcp_servers'][0]['header_value']);

        // Task 21's Access row appears for the server, one select posting its access.mcp entry.
        $html = $this->page('access');
        $this->assertSame(1, substr_count($html, 'name="alpaca_bot_settings[access.mcp][trk]"'));
        $this->assertStringContainsString('MCP server: trk', $html);
        $this->assertSame('manage_options', (new Access(new Store()))->stored('mcp.trk'));
        $this->assertStringNotContainsString(self::SECRET, $html);
    }

    /**
     * A site's first save of the option takes update_option()'s add_option() branch (core
     * option.php:928-929), after the pre_update_option filter has run (option.php:901), so the
     * value is split on that save too.
     */
    public function test_the_first_save_a_site_makes_is_split_as_well(): void
    {
        delete_option(Plugin::OPTION);
        $this->assertFalse($this->raw(Plugin::OPTION));
        (new Store())->replace(['toolkits.mcp_servers' => [['url' => self::URL, 'prefix' => 'trk', 'header_value' => self::SECRET]]]);

        $this->assertStringNotContainsString(self::SECRET, (string) $this->raw(Plugin::OPTION));
        $this->assertSame(['trk' => self::SECRET], Secrets::all());
        $this->assertNotAutoloaded(Secrets::OPTION);
    }

    /**
     * R75: the check that a person would make by hand, made through options.php's own path.
     * A server is added on the Tools tab, its approvals are stored, the Tools tab is saved again
     * untouched and then the Chat tab is saved, and the server, its mask and its approvals are all
     * still there, with the value where it was and not autoloaded.
     */
    public function test_a_server_saved_on_the_tools_tab_survives_a_save_of_the_chat_tab_with_its_mask_and_approvals(): void
    {
        $posted = self::formPost($this->page('toolkits'));
        $blank = count(get_option(Plugin::OPTION)['toolkits.mcp_servers']);
        $this->assertSame(0, $blank);
        $posted['toolkits.mcp_servers'][$blank] = ['url' => self::URL, 'prefix' => 'trk', 'header_name' => 'Authorization', 'header_value' => self::SECRET, 'timeout' => '12.5', 'max_bytes' => '2048'];
        $this->save($posted);
        $this->assertSame([], get_settings_errors(Plugin::OPTION));
        $this->assertSame(['trk' => self::SECRET], Secrets::all());

        // Approving is Task 26's; its result is a map in the row, written here as any writer would.
        $rows = get_option(Plugin::OPTION)['toolkits.mcp_servers'];
        $rows[0]['approved'] = ['search' => str_repeat('a', 64), 'repo.list' => str_repeat('b', 64)];
        (new Store())->replace(['toolkits.mcp_servers' => $rows]);
        $before = get_option(Plugin::OPTION)['toolkits.mcp_servers'];
        $this->assertSame(Schema::MASK, $before[0]['header_value']);

        $tools = $this->page('toolkits');
        $this->assertStringNotContainsString(self::SECRET, $tools);
        $this->save(self::formPost($tools));
        $this->assertSame($before, get_option(Plugin::OPTION)['toolkits.mcp_servers']);

        $chat = $this->page('chat');
        $this->assertStringNotContainsString(self::SECRET, $chat);
        $posted = self::formPost($chat);
        $posted['chat.welcome'] = 'Saved from the Chat tab';
        $this->save($posted);

        $after = get_option(Plugin::OPTION);
        $this->assertSame('Saved from the Chat tab', $after['chat.welcome']);
        $this->assertSame($before, $after['toolkits.mcp_servers']);
        $this->assertSame(['search' => str_repeat('a', 64), 'repo.list' => str_repeat('b', 64)], $after['toolkits.mcp_servers'][0]['approved']);
        $this->assertSame(12.5, $after['toolkits.mcp_servers'][0]['timeout']);
        $this->assertSame(['trk' => self::SECRET], Secrets::all());
        $this->assertNotAutoloaded(Secrets::OPTION);
        $this->assertStringNotContainsString(self::SECRET, (string) $this->raw(Plugin::OPTION));
    }

    /**
     * R74: a server removed on the Tools tab takes its access.mcp entry and its header value with
     * it, so a server added later under the same id starts at administrators only with no value.
     * The Tools tab posts no access.mcp, so the stored map rides along with the save and it is the
     * option's filter that drops the entry.
     */
    public function test_removing_a_server_drops_its_access_entry_and_its_header_value(): void
    {
        $res = $this->rest('PUT', '/settings', [
            'toolkits.mcp_servers' => [
                ['url' => self::URL, 'prefix' => 'trk', 'header_value' => self::SECRET],
                ['url' => 'https://93.184.216.35/mcp', 'prefix' => 'gh', 'header_value' => self::OTHER],
            ],
            'access.mcp' => ['trk' => 'read', 'gh' => 'edit_posts'],
        ]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame(['trk' => 'read', 'gh' => 'edit_posts'], get_option(Plugin::OPTION)['access.mcp']);
        $this->assertSame(['trk' => self::SECRET, 'gh' => self::OTHER], Secrets::all());

        $posted = self::formPost($this->page('toolkits'));
        $this->assertArrayNotHasKey('access.mcp', $posted);
        $posted['toolkits.mcp_servers'][1]['remove'] = '1';
        $this->save($posted);

        $after = get_option(Plugin::OPTION);
        $this->assertSame(['trk'], array_column($after['toolkits.mcp_servers'], 'id'));
        $this->assertSame(['trk' => 'read'], $after['access.mcp']);
        $this->assertSame(['trk' => self::SECRET], Secrets::all());

        // A new server that comes to take the id starts with neither.
        $posted = self::formPost($this->page('toolkits'));
        $posted['toolkits.mcp_servers'][1] = ['url' => 'https://93.184.216.36/mcp', 'prefix' => 'gh', 'header_value' => Schema::MASK];
        $this->save($posted);
        $this->assertSame(['trk', 'gh'], array_column(get_option(Plugin::OPTION)['toolkits.mcp_servers'], 'id'));
        $this->assertSame('manage_options', (new Access(new Store()))->stored('mcp.gh'));
        $this->assertSame(['trk' => self::SECRET], Secrets::all());
    }

    /**
     * The same, in one save: trk is removed and a new row takes its prefix. The id it is given is
     * not trk, which the stored list held (Schema::sanitize() reserves it), so the new server is
     * read as new: trk's access.mcp entry, which the Tools tab's post carried along, is dropped
     * with trk, and the mask the new row was posted with keeps nothing.
     */
    public function test_a_server_that_takes_a_removed_servers_id_in_the_same_save_inherits_nothing(): void
    {
        $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => self::URL, 'prefix' => 'trk', 'header_value' => self::SECRET]], 'access.mcp' => ['trk' => 'read']]);
        $posted = self::formPost($this->page('toolkits'));
        $posted['toolkits.mcp_servers'][0]['remove'] = '1';
        $posted['toolkits.mcp_servers'][1] = ['url' => 'https://93.184.216.36/mcp', 'prefix' => 'trk', 'header_value' => Schema::MASK];
        $this->save($posted);

        $after = get_option(Plugin::OPTION);
        $this->assertSame([['trk_2', 'https://93.184.216.36/mcp', '']], array_map(static fn(array $r): array => [$r['id'], $r['url'], $r['header_value']], $after['toolkits.mcp_servers']));
        $this->assertSame([], $after['access.mcp']);
        $this->assertSame([], Secrets::all());
        $this->assertFalse($this->raw(Secrets::OPTION));
    }

    public function test_a_put_whose_address_is_refused_writes_nothing(): void
    {
        $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => self::URL, 'prefix' => 'trk', 'header_value' => self::SECRET]]]);
        $before = $this->raw(Plugin::OPTION);

        foreach (['https://10.0.0.5/mcp', 'https://127.0.0.1/mcp', 'https://[::1]/mcp', 'https://169.254.169.254/latest'] as $url) {
            $res = $this->rest('PUT', '/settings', [
                'models.num_ctx' => 2048,
                'toolkits.mcp_servers' => [['id' => 'trk', 'url' => $url, 'prefix' => 'trk', 'header_value' => 'Bearer changed']],
            ]);
            $this->assertSame(400, $res->get_status(), $url);
            $this->assertSame('alpaca_bot_mcp_address', $res->get_data()['code'], $url);
            $this->assertStringContainsString($url, $res->get_data()['message']);
            $this->assertStringNotContainsString('Bearer changed', (string) wp_json_encode($res->get_data()));
            $this->assertSame($before, $this->raw(Plugin::OPTION), $url);
            $this->assertSame(['trk' => self::SECRET], Secrets::all(), $url);
        }
    }

    /**
     * On the settings page a refused edit keeps the stored row whole, its value included, a refused
     * new row is left out, the rest of the post is saved, and the screen says which and why.
     */
    public function test_the_settings_page_keeps_the_stored_server_for_a_refused_edit_and_says_why(): void
    {
        $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => self::URL, 'prefix' => 'trk', 'header_value' => self::SECRET]]]);
        $before = get_option(Plugin::OPTION)['toolkits.mcp_servers'];

        $posted = self::formPost($this->page('toolkits'));
        $posted['toolkits.mcp_servers'][0]['url'] = 'https://127.0.0.1/mcp';
        $posted['toolkits.mcp_servers'][0]['header_value'] = 'Bearer changed';
        $posted['toolkits.mcp_servers'][1] = ['url' => 'https://[fd00::1]/mcp', 'prefix' => 'new'];
        $posted['toolkits.user_agent'] = 'Changed/1.0';
        $this->save($posted);

        $after = get_option(Plugin::OPTION);
        $this->assertSame('Changed/1.0', $after['toolkits.user_agent']);
        $this->assertSame($before, $after['toolkits.mcp_servers']);
        $this->assertSame(['trk' => self::SECRET], Secrets::all());
        $errors = get_settings_errors(Plugin::OPTION);
        $this->assertSame(['mcp_address', 'mcp_address'], array_column($errors, 'code'));
        $this->assertStringContainsString('127.0.0.1', $errors[0]['message']);
        $this->assertStringContainsString(self::URL, $errors[0]['message']);
        $this->assertStringContainsString('fd00::1', $errors[1]['message']);
        $this->assertStringNotContainsString('Bearer changed', (string) wp_json_encode($errors));
    }

    /**
     * The reply to a PUT is read from Store's memo, and the memo is what the option was written
     * with after its filters: an access.mcp entry dropped with its server, and a mask that keeps
     * nothing, are gone from the reply as they are from the row.
     */
    public function test_the_reply_to_a_put_is_what_the_option_holds_after_the_split(): void
    {
        $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => self::URL, 'prefix' => 'trk', 'header_value' => self::SECRET]], 'access.mcp' => ['trk' => 'read']]);

        $res = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://93.184.216.35/mcp', 'prefix' => 'gh', 'header_value' => Schema::MASK]]]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame([], $res->get_data()['access.mcp']);
        $this->assertSame('', $res->get_data()['toolkits.mcp_servers'][0]['header_value']);
        $stored = get_option(Plugin::OPTION);
        $this->assertSame($stored['access.mcp'], $res->get_data()['access.mcp']);
        $this->assertSame($stored['toolkits.mcp_servers'], $res->get_data()['toolkits.mcp_servers']);
    }

    /**
     * I-1 / R76, the reviewer's case: `settings.write` and `settings.read` lowered to editors, an
     * editor PUTs a stored server's id with a URL of their own and the mask. The write goes
     * through, the administrator's value is not kept for the new host, the reply says which
     * server lost it, and the editor still cannot reveal anything.
     */
    public function test_an_editor_who_may_write_the_settings_cannot_move_a_stored_header_value_to_their_host(): void
    {
        $this->rest('PUT', '/settings', [
            'toolkits.mcp_servers' => [['url' => self::URL, 'prefix' => 'trk', 'header_value' => self::SECRET]],
            'access.settings.write' => 'edit_posts',
            'access.settings.read' => 'edit_posts',
        ]);
        $this->assertSame(['trk' => self::SECRET], Secrets::all());
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $res = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['id' => 'trk', 'url' => 'https://1.1.1.1/steal', 'prefix' => 'trk', 'header_value' => Schema::MASK]]]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertSame('trk', $res->get_headers()['X-Alpaca-Bot-Mcp-Cleared'] ?? null);
        $this->assertSame('', $res->get_data()['toolkits.mcp_servers'][0]['header_value']);
        $this->assertSame('https://1.1.1.1/steal', get_option(Plugin::OPTION)['toolkits.mcp_servers'][0]['url']);
        $this->assertSame([], Secrets::all());
        $this->assertSame('', Secrets::resolve(get_option(Plugin::OPTION)['toolkits.mcp_servers'][0]));
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($this->rest('GET', '/settings', ['reveal' => 1])->get_data()));
    }

    /** R76 on the settings page: a path-only move keeps the value, a host move drops it, and the screen says which. */
    public function test_the_settings_page_says_when_a_moved_server_lost_its_header_value(): void
    {
        $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [
            ['url' => self::URL, 'prefix' => 'trk', 'header_value' => self::SECRET],
            ['url' => 'https://93.184.216.35/mcp', 'prefix' => 'gh', 'header_value' => self::OTHER],
        ]]);
        $posted = self::formPost($this->page('toolkits'));
        $posted['toolkits.mcp_servers'][0]['url'] = 'https://93.184.216.34/v2/mcp';
        $posted['toolkits.mcp_servers'][1]['url'] = 'https://93.184.216.36/mcp';
        $this->save($posted);

        $this->assertSame(['trk' => self::SECRET], Secrets::all());
        $errors = get_settings_errors(Plugin::OPTION);
        $this->assertSame(['mcp_cleared'], array_column($errors, 'code'));
        $this->assertStringContainsString('https://93.184.216.36/mcp', $errors[0]['message']);
        $this->assertStringContainsString('gh', $errors[0]['message']);
    }

    /**
     * I-2, the reviewer's case: aa and bb swap prefixes and bb's URL goes private. bb's edit is
     * refused, so bb stays exactly as stored, and aa, whose new prefix is the one bb keeps, keeps
     * its stored row too; the screen names each for what happened to it.
     */
    public function test_a_refused_edit_leaves_its_server_whole_when_another_row_took_its_prefix(): void
    {
        $this->rest('PUT', '/settings', [
            'toolkits.mcp_servers' => [
                ['url' => self::URL, 'prefix' => 'aa', 'header_value' => self::SECRET],
                ['url' => 'https://93.184.216.35/mcp', 'prefix' => 'bb', 'header_value' => self::OTHER],
            ],
            'access.mcp' => ['aa' => 'read', 'bb' => 'edit_posts'],
        ]);
        $before = get_option(Plugin::OPTION);
        $posted = self::formPost($this->page('toolkits'));
        $posted['toolkits.mcp_servers'][0]['prefix'] = 'bb';
        $posted['toolkits.mcp_servers'][1]['prefix'] = 'aa';
        $posted['toolkits.mcp_servers'][1]['url'] = 'https://10.9.9.9/mcp';
        $this->save($posted);

        $after = get_option(Plugin::OPTION);
        $this->assertEqualsCanonicalizing($before['toolkits.mcp_servers'], $after['toolkits.mcp_servers']);
        $this->assertSame($before['access.mcp'], $after['access.mcp']);
        $this->assertSame(['aa' => self::SECRET, 'bb' => self::OTHER], Secrets::all());
        $errors = get_settings_errors(Plugin::OPTION);
        $this->assertSame(['mcp_address', 'mcp_prefix'], array_column($errors, 'code'));
        $this->assertStringContainsString('https://10.9.9.9/mcp', $errors[0]['message']);
        $this->assertStringContainsString('https://93.184.216.35/mcp', $errors[0]['message']);
        $this->assertStringContainsString(self::URL, $errors[1]['message']);
        $this->assertStringNotContainsString('https://93.184.216.35/mcp', $errors[1]['message']);
    }

    /**
     * I-3: one server's prefix changed to another's. The stored server is never deleted for it:
     * the colliding edit is refused, both servers stay as stored, and the screen says so. A new
     * row with a URL and no usable prefix is not dropped quietly either.
     */
    public function test_a_prefix_collision_keeps_the_stored_server_and_refuses_the_edit(): void
    {
        $this->rest('PUT', '/settings', [
            'toolkits.mcp_servers' => [
                ['url' => self::URL, 'prefix' => 'aa', 'header_value' => self::SECRET],
                ['url' => 'https://93.184.216.35/mcp', 'prefix' => 'bb', 'header_value' => self::OTHER],
            ],
            'access.mcp' => ['aa' => 'read', 'bb' => 'edit_posts'],
        ]);
        $before = get_option(Plugin::OPTION);
        $posted = self::formPost($this->page('toolkits'));
        $posted['toolkits.mcp_servers'][0]['prefix'] = 'bb';
        $posted['toolkits.mcp_servers'][2] = ['url' => 'https://93.184.216.36/mcp', 'prefix' => '', 'header_value' => 'Bearer lost'];
        $posted['toolkits.user_agent'] = 'Changed/2.0';
        $this->save($posted);

        $after = get_option(Plugin::OPTION);
        $this->assertSame('Changed/2.0', $after['toolkits.user_agent']);
        $this->assertSame($before['toolkits.mcp_servers'], $after['toolkits.mcp_servers']);
        $this->assertSame($before['access.mcp'], $after['access.mcp']);
        $this->assertSame(['aa' => self::SECRET, 'bb' => self::OTHER], Secrets::all());
        $errors = get_settings_errors(Plugin::OPTION);
        $this->assertSame(['mcp_prefix', 'mcp_dropped'], array_column($errors, 'code'));
        $this->assertStringContainsString(self::URL, $errors[0]['message']);
        $this->assertStringContainsString('https://93.184.216.36/mcp', $errors[1]['message']);
        $this->assertStringNotContainsString('Bearer lost', (string) wp_json_encode($errors));
    }

    /** m-4: a client that PUTs the same body without ids keeps the same id, and its Access entry, every time. */
    public function test_the_same_put_without_ids_keeps_the_same_server(): void
    {
        foreach ([1, 2, 3, 4] as $put) {
            $res = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => self::URL, 'prefix' => 'trk', 'header_value' => Schema::MASK]], 'access.mcp' => ['trk' => 'read']]);
            $this->assertSame(200, $res->get_status());
            $this->assertSame(['trk'], array_column(get_option(Plugin::OPTION)['toolkits.mcp_servers'], 'id'), "PUT {$put}");
            $this->assertSame(['trk' => 'read'], get_option(Plugin::OPTION)['access.mcp'], "PUT {$put}");
        }
    }

    /**
     * I-4 / R77: core's own opt-ins (the site's own host, `allowed_redirect_hosts`) do not let an
     * MCP server through, at save time or when Egress builds a client; one the site adds on
     * `http_request_host_is_external` does; web_fetch's AddressPin keeps core's exemption; and
     * the filter is left as it was, core's callback in its place, after a refusal.
     */
    public function test_the_sites_own_host_is_no_exemption_for_an_mcp_server(): void
    {
        global $wp_filter;
        update_option('home', 'http://10.0.0.5');
        add_filter('allowed_redirect_hosts', static fn(array $hosts): array => [...$hosts, '10.0.0.6']);
        $siteOwn = static fn(bool $external, string $host): bool => $host === '10.0.0.7' ? true : $external;
        add_filter('http_request_host_is_external', $siteOwn, 10, 2);
        $order = array_keys($wp_filter['http_request_host_is_external']->callbacks[10]);

        foreach (['10.0.0.5', '10.0.0.6'] as $host) {
            $res = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => "https://{$host}/mcp", 'prefix' => 'trk']]]);
            $this->assertSame(400, $res->get_status(), $host);
            $this->assertSame('alpaca_bot_mcp_address', $res->get_data()['code'], $host);
            try {
                (new \AlpacaBot\Mcp\Egress())->client(\AlpacaBot\Mcp\ServerConfig::fromSettings(['id' => 'trk', 'url' => "https://{$host}/mcp", 'prefix' => 'trk']));
                $this->fail("Egress built a client for {$host}");
            } catch (\AlpacaBot\Toolkit\AddressRefused) {
                // Refused, as at save time.
            }
            // web_fetch's rule is AddressPin's, core's exemption included.
            $this->assertSame([$host], \AlpacaBot\Toolkit\AddressPin::resolve($host, "http://{$host}/"));
        }
        $this->assertSame($order, array_keys($wp_filter['http_request_host_is_external']->callbacks[10]));
        $this->assertSame(10, has_filter('http_request_host_is_external', 'allowed_http_request_hosts'));

        // Multisite's opt-in, core's ms_allowed_http_request_hosts() at priority 20. It calls
        // get_network(), which a single-site install does not load, so here it can be shown only
        // never to be called by the MCP check: called, it would end the check with an Error.
        if (!is_multisite()) {
            add_filter('http_request_host_is_external', 'ms_allowed_http_request_hosts', 20, 2);
        }
        $res = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://10.0.0.5/mcp', 'prefix' => 'trk']]]);
        $this->assertSame('alpaca_bot_mcp_address', $res->get_data()['code'] ?? null);
        try {
            (new \AlpacaBot\Mcp\Egress())->client(\AlpacaBot\Mcp\ServerConfig::fromSettings(['id' => 'trk', 'url' => 'https://10.0.0.5/mcp', 'prefix' => 'trk']));
            $this->fail('Egress built a client for 10.0.0.5');
        } catch (\AlpacaBot\Toolkit\AddressRefused) {
            // Refused, without asking the network's callback.
        }
        $this->assertSame(20, has_filter('http_request_host_is_external', 'ms_allowed_http_request_hosts'));
        if (!is_multisite()) {
            remove_filter('http_request_host_is_external', 'ms_allowed_http_request_hosts', 20);
        }

        $res = $this->rest('PUT', '/settings', ['toolkits.mcp_servers' => [['url' => 'https://10.0.0.7/mcp', 'prefix' => 'trk']]]);
        $this->assertSame(200, $res->get_status(), print_r($res->get_data(), true));
        $this->assertInstanceOf(\AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface::class, (new \AlpacaBot\Mcp\Egress())->client(\AlpacaBot\Mcp\ServerConfig::fromSettings(['id' => 'trk', 'url' => 'https://10.0.0.7/mcp', 'prefix' => 'trk'])));
    }

    /**
     * A page over its own Store, registered in place of the plugin's, rendering `$tab`: the
     * plugin's Store memoises the option for the whole process, and these saves go round it
     * through update_option(). The page is handed the container's ServerSettings, so its address
     * check is the plugin's own.
     */
    private function page(string $tab): string
    {
        unregister_setting(SettingsPage::GROUP, Plugin::OPTION);
        $GLOBALS['wp_settings_fields'] = [];
        $store = new Store();
        $page = new SettingsPage($store, Plugin::instance()->get(ModelCatalog::class), new Access($store), null, Plugin::instance()->get(ServerSettings::class));
        $page->register();
        $_GET['tab'] = $tab;
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        $page->render();
        return (string) ob_get_clean();
    }

    /** What the form posts, saved as options.php saves it: update_option(), through the registered sanitize callback. */
    private function save(array $posted): void
    {
        $_POST = [Plugin::OPTION => $posted, SettingsPage::END_MARKER => '1'];
        update_option(Plugin::OPTION, $posted);
    }

    /** The row's option_value as the table holds it, or false when there is no row. */
    private function raw(string $option): string|false
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
        return $value === null ? false : (string) $value;
    }

    /** Read as core reads it: the raw `autoload` column against wp_autoload_values_to_autoload(), as Migrate04AutoloadTest does. */
    private function assertNotAutoloaded(string $option): void
    {
        global $wpdb;
        $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
        $this->assertNotNull($autoload, "$option has no row");
        $this->assertNotContains((string) $autoload, wp_autoload_values_to_autoload(), "$option is autoloaded");
    }
}
