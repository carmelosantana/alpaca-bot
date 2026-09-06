<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

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
     * Only a capability name is honoured. WP_User::has_cap() reads a numeric capability as a
     * legacy user level ('1' is level_1, which every Contributor holds), so a filter that
     * returns a bool or a number by mistake, say `fn() => current_user_can('manage_options')`,
     * would cast to '1' and quietly open the route to Contributors. Anything that is not a
     * non-empty, non-numeric string is treated as no opinion and the declared capability is
     * what gets checked; a filter cannot loosen a route by accident, only by naming a capability.
     */
    public function permission(string $route, string $capability): \Closure
    {
        return static function (\WP_REST_Request $request) use ($route, $capability): bool|\WP_Error {
            $filtered = apply_filters("alpaca_bot/capability/{$route}", $capability, $request);
            $cap = is_string($filtered) && $filtered !== '' && !is_numeric($filtered) ? $filtered : $capability;
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
