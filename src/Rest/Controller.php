<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Capability;

/**
 * Base for every route under `alpaca-bot/v1`. A subclass lists its routes; register() hands each
 * to core with a permission callback that resolves the capability through filter
 * `alpaca_bot/capability/{route key}` (string capability, WP_REST_Request), and, for a route
 * flagged `rate_limit`, a callback wrapped in RateLimit.
 *
 * Authentication is core's: cookie + X-WP-Nonce for the admin, Application Passwords for
 * external clients. There is no nonce or token code here on purpose; the only identity a route
 * sees is get_current_user_id(), which core has already established by the time a callback runs.
 *
 * The rate limit lives in the callback wrapper, not the permission callback, so it counts only
 * requests that were allowed: a user without the capability is refused before a bucket is
 * touched. A route a site has opened to visitors through its capability filter (`exist` holds
 * for a logged-out request) is still limited, but per client address rather than as user 0, so
 * one script cannot spend every visitor's allowance; RateLimit explains the keying.
 */
abstract class Controller
{
    public const NAMESPACE = 'alpaca-bot/v1';

    /**
     * One entry per route; `path` is core's route pattern relative to the namespace (named regex
     * groups allowed), `methods` a core method string ('GET', 'POST', 'GET, DELETE', ...).
     *
     * @return list<array{path: string, methods: string, callback: callable, capability: string, args?: array<string, array<string, mixed>>, rate_limit?: bool}>
     */
    abstract public function routes(): array;

    public function register(): void
    {
        foreach ($this->routes() as $route) {
            $callback = $route['callback'];
            if (!empty($route['rate_limit'])) {
                $callback = $this->rateLimited($callback);
            }
            register_rest_route(self::NAMESPACE, $route['path'], [
                'methods' => $route['methods'],
                'callback' => $callback,
                'permission_callback' => $this->permission(self::routeKey($route['path']), $route['capability']),
                'args' => $route['args'] ?? [],
            ]);
        }
    }

    /**
     * The permission callback for a route whose default capability is `$capability`. The filter
     * sees the request, so a site can tighten (or, for a route it exposes to subscribers, loosen)
     * per request.
     *
     * Only a capability name is honoured, and Capability::filtered() is where that rule lives:
     * a filter returning a bool or a number would otherwise cast to a legacy user-level check
     * and open the route rather than close it. The admin menu's filter goes through the same
     * guard.
     */
    public function permission(string $route, string $capability): \Closure
    {
        return static function (\WP_REST_Request $request) use ($route, $capability): bool|\WP_Error {
            /**
             * Filters the capability a REST route's permission callback checks, per request. `{route}`
             * is the route's key (Controller::routeKey(): `chat`, `conversations`, `chat/stream`,
             * `settings/schema`...), so one filter covers a collection and its items. Return a
             * capability to tighten a route, or to open one to a role (a subscriber-facing chat). Only
             * a non-empty, non-numeric string is honoured (Capability::filtered()): a bool or a number
             * would become a legacy user-level check, so it is ignored and the default stands.
             *
             * Opening `chat` or `chat/stream` opens every enabled tool to that role as well.
             * Toolkit\Registry::enabled() chooses a turn's toolkits from the `toolkits.enabled`
             * setting and the `alpaca_bot/toolkits` filter, and has no capability check of its own,
             * so there is nothing between "may chat" and "may call the tools that are switched on" —
             * including `web_fetch`, which makes the web server send an outbound request and hands
             * the reply back. Only `draft_post` re-checks a capability and refuses a role that lacks
             * it. `read` and `exist` are honoured strings, so `read` is every Subscriber and `exist`
             * is every visitor, logged out included. Use `alpaca_bot/toolkits` to take a tool away
             * from the users a loosened route admits; README's "Tools, and what they let the model
             * reach" is the operator-facing version of this, with the egress policy that mitigates it.
             *
             * @since 0.5.0
             * @param string           $capability the route's default capability
             * @param \WP_REST_Request $request    the request being authorised
             */
            $cap = Capability::filtered("alpaca_bot/capability/{$route}", $capability, $request);
            return current_user_can($cap) ? true : Errors::forbidden();
        };
    }

    /**
     * The filter key for a route path: leading slash off, regex groups out, so `/conversations`
     * and `/conversations/(?P<id>\d+)` share `conversations` (one filter covers the collection and
     * the item) while `/chat/(?P<id>\d+)/stream` is `chat/stream`. Only simple named groups
     * (no nested parentheses) are recognised, which is all the plugin's routes use.
     */
    public static function routeKey(string $path): string
    {
        return trim((string) preg_replace('#/\(\?P<[^)]+\)[^/]*#', '', $path), '/');
    }

    protected function userId(): int
    {
        return (int) get_current_user_id();
    }

    /**
     * Wraps `$callback` so an exhausted bucket answers 429 without running it. Routes are
     * limited together under the 'chat' bucket: the limiter guards model spend per person, and
     * one person opening two chat routes is still one person.
     *
     * The refusal is a WP_REST_Response built from Errors::tooMany() rather than the WP_Error
     * itself: core renders a WP_Error's status and body but has nowhere to carry a header, and
     * Retry-After is the one thing a well-behaved client needs from a 429.
     */
    private function rateLimited(callable $callback): \Closure
    {
        return function (\WP_REST_Request $request) use ($callback): mixed {
            $hit = (new RateLimit())->hit($this->userId(), 'chat');
            if (!$hit['allowed']) {
                $response = rest_convert_error_to_response(Errors::tooMany($hit['retry_after']));
                $response->header('Retry-After', (string) $hit['retry_after']);
                return $response;
            }
            return $callback($request);
        };
    }
}
