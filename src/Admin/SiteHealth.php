<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\WebFetchToolkit;

/**
 * One Site Health test: whether web_fetch can run pinned on this server.
 *
 * web_fetch holds its connections to the address its check passed by handing that address to
 * cURL (Toolkit\CurlPin), and refuses to fetch at all on a server whose PHP has no cURL for it
 * to use. On such a server the tool can still be on in Settings while answering every call with
 * a refusal, and the only place the reason would otherwise surface is a tool error in a chat. Site Health
 * is where a site owner looks for "this server lacks X", so that is where it is said. The same
 * page is the natural place for the other server-level thing the pin cannot reach, and the only
 * other one this test can see: a proxy configured for the HTTP API is sent the name and resolves
 * it itself. What the pin does not reach on any server -- this site's own host, a public address
 * the site's own network routes somewhere private -- is the Tools help tab's and README's to
 * say, since no server fact decides it.
 *
 * A direct test, not an async one: both facts are local (an extension check and two constants),
 * so there is nothing to wait on. Status `recommended`, not `critical`: on a server without
 * cURL the tool refuses rather than fetching unpinned, so what is wrong is a tool that does
 * nothing, not one that reaches further than it should; and a proxy is an egress point the
 * operator chose and can restrict, which is what bounds the rebinding window the pin would
 * otherwise have closed there. Both change what the tool is, which is why neither is silent
 * and why the proxy is not left to a docblock. When web_fetch is switched off the answer is
 * good with nothing to do; `alpaca_bot/toolkits` can still add it back per user, which this
 * test does not try to guess.
 *
 * @since 0.6.0
 */
final class SiteHealth
{
    /** The test's id in `site_status_tests`, prefixed as core asks (class-wp-site-health.php:3018-3020). */
    public const TEST = 'alpaca_bot_web_fetch';

    /**
     * @param null|\Closure(bool): bool $curl    whether WordPress would send a request through cURL; WebFetchToolkit::curlCarries() by default
     * @param null|\Closure(): bool     $proxied whether the HTTP API is configured to use a proxy; WP_HTTP_Proxy::is_enabled() by default
     */
    public function __construct(private Store $store, private ?\Closure $curl = null, private ?\Closure $proxied = null) {}

    /**
     * The `site_status_tests` filter (applied at class-wp-site-health.php:3047).
     *
     * @param array<string, array<string, mixed>> $tests
     * @return array<string, array<string, mixed>>
     */
    public function register(array $tests): array
    {
        $tests['direct'][self::TEST] = [
            'label' => __('Alpaca Bot: web_fetch pinning', 'alpaca-bot'),
            'test' => [$this, 'webFetch'],
        ];
        return $tests;
    }

    /**
     * The test's own answer, in the shape core renders (class-wp-site-health.php:170-180).
     *
     * The cURL question is asked for https, the stricter of the two schemes: a cURL built
     * without SSL carries an http fetch and not an https one, and a server that cannot fetch
     * https is worth saying so about even where http would still work.
     *
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
     */
    public function webFetch(): array
    {
        $enabled = $this->store->get('toolkits.enabled', []);
        if (!is_array($enabled) || !in_array('web_fetch', $enabled, true)) {
            return self::result('good', __('Alpaca Bot\'s web_fetch tool is switched off', 'alpaca-bot'), __('The model cannot fetch pages from this server, so there is nothing to pin.', 'alpaca-bot'));
        }
        if (!($this->curl ?? WebFetchToolkit::curlCarries(...))(true)) {
            return self::result('recommended', __('Alpaca Bot\'s web_fetch cannot run on this server', 'alpaca-bot'), __('web_fetch connects to the address its check passed, and only cURL can be held to it. WordPress would send the request without cURL here, so web_fetch refuses every fetch. Ask your host for PHP\'s cURL extension with SSL, or switch web_fetch off under Alpaca Bot › Settings › Tools.', 'alpaca-bot'));
        }
        if (($this->proxied ?? static fn(): bool => (new \WP_HTTP_Proxy())->is_enabled())()) {
            return self::result('recommended', __('Alpaca Bot\'s web_fetch goes through a proxy that looks names up itself', 'alpaca-bot'), __('WP_PROXY_HOST and WP_PROXY_PORT are set, so WordPress hands a request to the proxy with the host name and the proxy resolves it. This site\'s own host, localhost and anything in WP_PROXY_BYPASS_HOSTS are not proxied. For the hosts that are, the address web_fetch checked is not the one the proxy connects to. What the proxy may reach, web_fetch may reach: restrict the proxy from private ranges and the cloud metadata address.', 'alpaca-bot'));
        }
        return self::result('good', __('Alpaca Bot\'s web_fetch connects to the address it checked', 'alpaca-bot'), __('A page web_fetch reads is looked up once, checked, and fetched from exactly that address through cURL, and so is each redirect it follows. The exception is this site\'s own host, which WordPress exempts from the address check and which web_fetch does not pin either, so whatever else answers on it stays reachable.', 'alpaca-bot'));
    }

    /** @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string} */
    private static function result(string $status, string $label, string $description): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => __('Security', 'alpaca-bot'), 'color' => 'blue'],
            'description' => '<p>' . esc_html($description) . '</p>',
            'actions' => '',
            'test' => self::TEST,
        ];
    }
}
