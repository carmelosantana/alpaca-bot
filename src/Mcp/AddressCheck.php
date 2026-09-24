<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Toolkit\AddressPin;

/**
 * The address check for an MCP server: AddressPin's rule, with core's own opt-ins taken out of
 * the question for the length of one call.
 *
 * AddressPin lets a private or other special-purpose answer through when
 * `http_request_host_is_external` says the host is external. Core answers that filter itself:
 * `allowed_http_request_hosts` (priority 10, wp-includes/default-filters.php) says yes for any
 * host wp_validate_redirect() accepts, which is the site's own host and every
 * `allowed_redirect_hosts` host, at any port; on multisite `ms_allowed_http_request_hosts`
 * (priority 20, ms-default-filters.php) says yes for every domain of the network. web_fetch keeps
 * that: a page on the site's own host is a page, and it calls AddressPin directly. An MCP server
 * is not: it is an address an administrator typed, and a site whose own host name resolves
 * privately would otherwise let that address be any port on the private network behind it. So an
 * MCP server is refused the site's own host unless the site says otherwise, and the way to say so
 * is the one Egress's docblock gives: a listener of the site's own on
 * `http_request_host_is_external`, which this leaves in place.
 *
 * The mechanism: the filter's WP_Hook is copied, core's two callbacks are removed, the check
 * runs, and the copy is put back in a `finally`, so a throw (AddressRefused is how a refusal
 * arrives) restores it too. Putting the copy back, rather than adding the two callbacks again,
 * keeps their place among the other priority-10 and priority-20 listeners: add_filter() would
 * append them, after a site's own listener at the same priority, and a site listener that says no
 * after core's yes would then be overruled by it for every later web_fetch in the request. What
 * the copy costs: a listener added to the filter *during* the check is dropped with it; the only
 * code that runs during it is AddressPin and the filter's own listeners.
 *
 * @since 0.6.0
 */
final class AddressCheck
{
    /** The filter AddressPin asks, and the core callbacks this takes out of it, by priority. */
    private const FILTER = 'http_request_host_is_external';

    /** @var array<string, int> */
    private const CORE = ['allowed_http_request_hosts' => 10, 'ms_allowed_http_request_hosts' => 20];

    /**
     * AddressPin::resolve() for an MCP server's host, with core's own-host and network opt-ins
     * removed while it runs.
     *
     * @param null|\Closure(string): list<string> $lookup passed to AddressPin::resolve(); the system resolver by default
     * @return list<string> every checked address
     * @throws \AlpacaBot\Toolkit\AddressRefused as AddressPin::resolve() does
     */
    public static function resolve(string $host, string $url = '', ?\Closure $lookup = null): array
    {
        global $wp_filter;
        $saved = isset($wp_filter[self::FILTER]) && $wp_filter[self::FILTER] instanceof \WP_Hook ? clone $wp_filter[self::FILTER] : null;
        foreach (self::CORE as $callback => $priority) {
            remove_filter(self::FILTER, $callback, $priority);
        }
        try {
            return AddressPin::resolve($host, $url, $lookup);
        } finally {
            if ($saved !== null) {
                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Puts back the WP_Hook this method copied a moment ago; the class docblock says why a copy rather than add_filter().
                $wp_filter[self::FILTER] = $saved;
            }
        }
    }
}
