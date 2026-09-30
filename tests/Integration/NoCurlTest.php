<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\SiteHealth;
use AlpacaBot\Plugin;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Toolkit\WebFetchToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

/**
 * web_fetch on a PHP where WordPress would not send a request through cURL: the refusal
 * ToolkitsTest cannot reach, because the harness and wp-env containers both have cURL (Kanboard
 * #4484). The server is the one shared hosts ship, with curl_exec in disable_functions, and it is
 * made by running this suite's own PHP that way, not by a seam: Requests' Transport\Curl::test()
 * asks function_exists('curl_init') && function_exists('curl_exec') first (Curl.php:605), so it
 * answers false through the real code path, Requests' own selector falls through to Fsockopen,
 * and the plugin has to agree. A seam forcing test() false would only re-test the injected closure
 * the unit suite already covers.
 *
 * `WP_NO_CURL=1 bash bin/test-integration.sh --group no-curl --fail-on-skipped` is the run
 * (bin/test-integration.sh adds `-d disable_functions=curl_init,curl_exec`); CI makes it once, on
 * the latest leg. Every test here skips where both cURL functions exist, which is every ordinary
 * run of the suite, and --fail-on-skipped is what makes a run that did not disable them fail.
 *
 * @group no-curl
 */
final class NoCurlTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $this->markTestSkipped('cURL is usable here; run with WP_NO_CURL=1 (bin/test-integration.sh).');
        }
    }

    public function test_curl_carries_neither_scheme(): void
    {
        $this->assertFalse(WebFetchToolkit::curlCarries(true));
        $this->assertFalse(WebFetchToolkit::curlCarries(false));
    }

    /**
     * Requests' own selector, asked through reflection as ToolkitsTest asks it (it is protected,
     * Requests.php:225), so the refusal is pinned to the transport WordPress would really use and
     * not only to curlCarries()'s own expression.
     */
    public function test_requests_picks_a_transport_other_than_curl_for_either_scheme(): void
    {
        $selector = new \ReflectionMethod(\WpOrg\Requests\Requests::class, 'get_transport_class');
        $selector->setAccessible(true);
        foreach ([true, false] as $https) {
            $chosen = $selector->invoke(null, [\WpOrg\Requests\Capability::SSL => $https]);
            $this->assertNotSame(\WpOrg\Requests\Transport\Curl::class, $chosen, $https ? 'https' : 'http');
            $this->assertSame($chosen === \WpOrg\Requests\Transport\Curl::class, WebFetchToolkit::curlCarries($https), (string) $chosen);
        }
    }

    /**
     * The tool as the plugin offers it, from the registry with web_fetch in `toolkits.enabled`,
     * refuses and names cURL. The URL is the site's own host, the one wp_http_validate_url()
     * passes without a DNS lookup and the plugin does not pin, so the cURL question is the only
     * thing standing between it and a request -- which a listener here would see, ahead of the
     * suite's own refusal of unstubbed requests (bootstrap.php).
     */
    public function test_web_fetch_refuses_naming_curl_and_makes_no_request(): void
    {
        $admin = $this->asAdmin();
        $this->assertSame(200, $this->rest('PUT', '/settings', ['toolkits.enabled' => ['web_fetch']])->get_status());
        $toolkit = Plugin::instance()->get(Registry::class)->enabled($admin)['web_fetch'] ?? null;
        $this->assertInstanceOf(WebFetchToolkit::class, $toolkit);

        $requests = [];
        add_filter('pre_http_request', static function (mixed $pre, array $args, string $url) use (&$requests): \WP_Error {
            $requests[] = $url;
            return new \WP_Error('unexpected', 'no request was expected');
        }, 10, 3);

        foreach ([home_url('/a-page/'), set_url_scheme(home_url('/a-page/'), 'https')] as $url) {
            $res = $toolkit->tools()[0]->execute(['url' => $url]);
            $this->assertSame(ToolResultStatus::Error, $res->status, $url);
            $this->assertStringContainsString('cURL', $res->content, $url);
        }
        $this->assertSame([], $requests);
    }

    public function test_site_health_recommends_with_the_no_curl_label(): void
    {
        $this->asAdmin();
        $this->assertSame(200, $this->rest('PUT', '/settings', ['toolkits.enabled' => ['web_fetch']])->get_status());
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
        $this->assertArrayHasKey(SiteHealth::TEST, $tests['direct']);
        $result = call_user_func($tests['direct'][SiteHealth::TEST]['test']);
        $this->assertSame('recommended', $result['status'], $result['label']);
        $this->assertSame('Alpaca Bot\'s web_fetch cannot run on this server', $result['label']);
    }
}
