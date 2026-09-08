<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\DraftPostToolkit;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Toolkit\WebFetchToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

/**
 * The built-in toolkits over real WordPress: the capability map, wp_insert_post(), the HTTP
 * API's own URL validation and the settings option, none of which a unit test's stubs can
 * vouch for. The unit tests pin what each toolkit does with what core answers; these pin what
 * core answers.
 *
 * No test here reaches the network. A fetch that core's validation lets through is served by
 * `pre_http_request`, and the URLs the validation refuses never get that far.
 */
final class ToolkitsTest extends TestCase
{
    public function test_the_registry_is_wired_and_reads_toolkits_enabled_from_the_option(): void
    {
        $admin = $this->asAdmin();
        $registry = Plugin::instance()->get(Registry::class);
        $this->assertSame(['web_fetch', 'summarize', 'draft_post'], $registry->ids());
        $this->assertSame(['web_fetch', 'summarize', 'draft_post'], array_keys($registry->enabled($admin)));

        $this->assertSame(200, $this->rest('PUT', '/settings', ['toolkits.enabled' => ['draft_post', 'bogus']])->get_status());
        $this->assertSame(['draft_post'], get_option('alpaca_bot_settings')['toolkits.enabled']);
        $this->assertSame(['draft_post'], array_keys($registry->enabled($admin)));
        $this->assertInstanceOf(DraftPostToolkit::class, $registry->enabled($admin)['draft_post']);
    }

    public function test_the_settings_page_renders_one_checkbox_per_toolkit_and_a_save_with_none_checked_stores_none(): void
    {
        $this->asAdmin();
        Plugin::instance()->get(SettingsPage::class)->register();
        $_GET['tab'] = 'toolkits';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();
        unset($_GET['tab']);

        foreach (['web_fetch', 'summarize', 'draft_post'] as $id) {
            $this->assertMatchesRegularExpression('/<input type="checkbox"[^>]*name="alpaca_bot_settings\[toolkits\.enabled\]\[\]" value="' . $id . '"[^>]*checked/', $html, $id);
        }
        // The sentinel ahead of the boxes, so "none checked" is posted at all.
        $this->assertMatchesRegularExpression('/<input type="hidden" name="alpaca_bot_settings\[toolkits\.enabled\]\[\]" value="">.*<input type="checkbox"/s', $html);

        // options.php's path: the registered sanitize callback over what the form posts with no
        // box checked. Every other field carried as the page posts it (defaults, here).
        update_option('alpaca_bot_settings', ['toolkits.enabled' => ['']] + \AlpacaBot\Settings\Schema::defaults());
        $this->assertSame([], get_option('alpaca_bot_settings')['toolkits.enabled']);
        $this->assertSame([], Plugin::instance()->get(Registry::class)->enabled(get_current_user_id()));
    }

    public function test_draft_post_creates_a_draft_owned_by_the_acting_user_and_never_publishes(): void
    {
        $user = $this->asAdmin();
        $tool = (new DraftPostToolkit(get_current_user_id(...)))->tools()[0];
        $res = $tool->execute(['title' => 'From the chat', 'content' => '<p>Body</p><script>alert(1)</script>']);
        $this->assertSame(ToolResultStatus::Success, $res->status, $res->content);
        $data = json_decode($res->content, true);
        $this->assertIsArray($data);
        $post = get_post((int) $data['id']);
        $this->assertNotNull($post);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('draft', $data['status']);
        $this->assertSame('post', $post->post_type);
        $this->assertSame((string) $user, $post->post_author);
        $this->assertSame('From the chat', $post->post_title);
        $this->assertStringContainsString('<p>Body</p>', $post->post_content);
        $this->assertStringNotContainsString('<script>', $post->post_content);
        $this->assertStringContainsString('post=' . $data['id'], $data['edit_url']);
        $this->assertStringContainsString('action=edit', $data['edit_url']);
    }

    public function test_draft_post_makes_a_page_when_asked_and_refuses_a_user_who_may_not_edit(): void
    {
        $this->asAdmin();
        $tool = (new DraftPostToolkit(get_current_user_id(...)))->tools()[0];
        $res = $tool->execute(['title' => 'A page', 'content' => 'x', 'post_type' => 'page']);
        $this->assertSame(ToolResultStatus::Success, $res->status, $res->content);
        $this->assertSame('page', get_post((int) json_decode($res->content, true)['id'])->post_type);

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $before = count(get_posts(['post_status' => 'any', 'post_type' => ['post', 'page'], 'numberposts' => -1]));
        $refused = $tool->execute(['title' => 'Nope', 'content' => 'x']);
        $this->assertSame(ToolResultStatus::Error, $refused->status);
        $this->assertSame($before, count(get_posts(['post_status' => 'any', 'post_type' => ['post', 'page'], 'numberposts' => -1])));
    }

    /**
     * Core's wp_http_validate_url() is the guard, and it resolves a hostname to decide, so a
     * loopback name, a private address and a scheme the HTTP API does not speak are all refused
     * before any request is built. The list is what the guard documents refusing, not every
     * range it knows.
     */
    public function test_web_fetch_refuses_private_loopback_and_malformed_urls_through_core(): void
    {
        $tool = (new WebFetchToolkit(Plugin::instance()->get(Store::class)))->tools()[0];
        $requests = 0;
        add_filter('pre_http_request', static function () use (&$requests) {
            $requests++;
            return new \WP_Error('unexpected', 'no request was expected');
        });
        foreach (['http://127.0.0.1/', 'http://localhost/', 'http://10.0.0.1/', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'ftp://example.com/', 'file:///etc/passwd', 'not a url', ''] as $url) {
            $res = $tool->execute(['url' => $url]);
            $this->assertSame(ToolResultStatus::Error, $res->status, $url);
        }
        $this->assertSame(0, $requests);
    }

    public function test_web_fetch_sends_the_configured_user_agent_within_the_limits_and_returns_readable_text(): void
    {
        Plugin::instance()->get(Store::class)->set('toolkits.user_agent', 'Integration/1');
        $seen = null;
        add_filter('pre_http_request', static function (mixed $pre, array $args, string $url) use (&$seen): array {
            $seen = ['args' => $args, 'url' => $url];
            return ['headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => '<html><body><h1>Hi</h1><script>x()</script><p>there</p></body></html>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        }, 10, 3);
        // The site's own host is the one wp_http_validate_url() passes without a DNS lookup.
        $url = home_url('/a-page/');
        $tool = (new WebFetchToolkit(Plugin::instance()->get(Store::class)))->tools()[0];
        $res = $tool->execute(['url' => $url]);
        $this->assertSame(ToolResultStatus::Success, $res->status, $res->content);
        $this->assertSame("Hi\n\nthere", $res->content);
        $this->assertNotNull($seen);
        $this->assertSame($url, $seen['url']);
        $this->assertSame('Integration/1', $seen['args']['user-agent']);
        $this->assertSame(5, $seen['args']['timeout']);
        $this->assertSame(1048576, $seen['args']['limit_response_size']);
        $this->assertTrue($seen['args']['reject_unsafe_urls']);
    }
}
