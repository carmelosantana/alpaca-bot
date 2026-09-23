<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Access;
use AlpacaBot\Capability;
use AlpacaBot\Errors;
use AlpacaBot\RateLimit;

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
     * The Chat row of Settings › Access, as a route declares it in place of a capability name.
     * capability() resolves it per request for a route; Admin\Menu::capability() reads the same row
     * for the menu, its chat screen, the admin-wide drawer and the block editor's sidebar
     * (Admin\Drawer). Two readers of one
     * row, not one mechanism serving both —
     * which is what makes a site that moves the row move the screen and the API together, each
     * still behind its own filter (`alpaca_bot/capability/{route}` and
     * `alpaca_bot/admin/menu_capability`).
     */
    public const CHAT = 'chat';

    /**
     * The Settings › Access rows this controller's routes resolve tokens against, once something
     * has handed them over. Null until then, which capability() reads as the shipped default.
     */
    private ?Access $access = null;

    /**
     * One entry per route; `path` is core's route pattern relative to the namespace (named regex
     * groups allowed), `methods` a core method string ('GET', 'POST', 'GET, DELETE', ...).
     * `capability` is a capability name, `Controller::CHAT` for a route that follows the Chat row
     * of Settings › Access, or — for a subclass that overrides capability() — whatever token that
     * override resolves (SettingsController declares Access row names such as `settings.read`).
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
     * Hands this controller the Settings › Access rows its routes resolve tokens against.
     * Plugin::controllers() calls it on everything the `alpaca_bot/rest/controllers` filter hands
     * back, a third party's subclass included, so a route registered through that filter may
     * declare self::CHAT and get the row this site saved. A controller nobody called this on (a
     * test, a site registering one by hand) resolves the token to Access::defaults() instead —
     * the shipped default, never an unresolved literal.
     *
     * @since 0.6.0
     */
    public function useAccess(Access $access): void
    {
        $this->access = $access;
    }

    /**
     * The permission callback for a route whose declared capability is `$capability` — a
     * capability name, self::CHAT for a route that follows the Chat row of Settings › Access, or
     * whatever token an overriding subclass declares. capability() resolves that per request, and
     * the filter it applies sees the request, so a site can tighten (or, for a route it exposes to
     * subscribers, loosen) per request.
     *
     * Only a capability name is honoured, and Capability::filtered() is where that rule lives:
     * a filter returning a bool or a number would otherwise cast to a legacy user-level check
     * and open the route rather than close it. The admin menu's filter goes through the same
     * guard.
     */
    public function permission(string $route, string $capability): \Closure
    {
        return function (\WP_REST_Request $request) use ($route, $capability): bool|\WP_Error {
            return current_user_can($this->capability($route, $capability, $request)) ? true : Errors::forbidden();
        };
    }

    /**
     * What one row of Settings › Access resolves to here, for a controller that may not have been
     * handed an Access: the row through its own filter, or the row's shipped default when nothing
     * handed this controller one. Access::effective() fires no filter for self::CHAT — that row's
     * hooks are the menu's and each route's, and Access owns that rule — so a chat route's only
     * filter is still the one capability() applies.
     *
     * This is the seam a subclass overriding capability() resolves its rows through, rather than
     * reaching for the Access object: the null case is answered once, here, so an override never
     * null-checks, and nothing has to build a second Access over the same Store — Plugin::register()
     * keeps one per request on purpose, and a second would read the settings option again.
     *
     * `$args` are the row's own, passed straight through. Access::expectedArgs() fixes how many
     * each row fires with and effective() throws when handed fewer, which is a programming error
     * and stays loud. A row Access::defaults() does not list falls back to `manage_options`,
     * so a row nobody declared fails closed rather than open.
     *
     * @param mixed ...$args the arguments this row's filter fires with, Access::expectedArgs() of them
     * @throws \InvalidArgumentException when fewer than Access::expectedArgs($row) arguments are passed
     * @since 0.6.0
     */
    protected function accessRow(string $row, mixed ...$args): string
    {
        return $this->access?->effective($row, ...$args) ?? (Access::defaults()[$row] ?? 'manage_options');
    }

    /**
     * The capability this route's permission callback checks for this request: the route's
     * declared capability — or, for self::CHAT, the Chat row of Settings › Access — through the
     * route's own filter. Overridable, so a subclass can resolve a route of its own some other
     * way — accessRow() is how it reaches another row — and fall back here for the rest.
     *
     * The row is read here rather than in routes(): routes() is called from register(), on
     * `rest_api_init`, which fires for every REST request the site serves, `/wp/v2/*` included,
     * while this runs only for a request that has reached one of these routes — and then through
     * the memoised Store, which reads the settings option at most once per request however many
     * rows are asked.
     */
    protected function capability(string $route, string $declared, \WP_REST_Request $request): string
    {
        $default = $declared === self::CHAT ? $this->accessRow(self::CHAT) : $declared;
        return self::filteredCapability($route, $default, $request);
    }

    /**
     * `alpaca_bot/capability/{$route}` over `$default`, for `$request`, reduced to a capability
     * name. capability() applies it to every request a route authorises, and Admin\SettingsPage
     * asks it of the `chat` key to show whether code changed the Chat row for the API, which is
     * why it is public and static: that page has no controller to ask.
     *
     * @since 0.6.0
     */
    public static function filteredCapability(string $route, string $default, \WP_REST_Request $request): string
    {
        /**
         * Filters the capability a REST route's permission callback checks, per request. `{route}`
         * is the route's key (Controller::routeKey(): `chat`, `conversations`, `chat/stream`,
         * `view/messages`...), so one filter covers a collection and its items. Not every route
         * has one: SettingsController overrides capability() and resolves its Settings › Access
         * row instead, so `/settings` and `/settings/schema` apply no key here at all — which is
         * why 0.5's `alpaca_bot/capability/settings/schema` no longer fires. Return a
         * capability to tighten a route, or to open one to a role (a subscriber-facing chat). Only
         * a non-empty, non-numeric string is honoured (Capability::filtered()): a bool or a number
         * would become a legacy user-level check, so it is ignored and the default stands.
         *
         * Opening `chat` or `chat/stream` does not open the tools with it.
         * Toolkit\Registry::enabled() offers a turn only the toolkits whose Settings › Access row
         * the turn's user passes (`alpaca_bot/capability/tool/{id}`), before `alpaca_bot/toolkits`
         * runs, so a role admitted here only to converse gets no tools until a row admits it —
         * `web_fetch`, which makes the web server send an outbound request and hands the reply
         * back, included. `draft_post` re-checks a capability of its own on top. `read` and
         * `exist` are honoured strings, so `read` is every Subscriber and `exist` is every
         * visitor, logged out included. README's "Tools, and what they let the model reach" is
         * the operator-facing version of this, with what the address pinning covers for
         * `web_fetch` and what it does not.
         *
         * Settings › Access asks the `chat` key as well, to say under the Chat row whether code
         * has changed it for the `/chat` route: with the Chat row as `$capability` and a
         * `POST /chat` request built for that question, which no client sent and nothing is
         * authorised by.
         *
         * @since 0.5.0
         * @param string           $capability the route's default: its declared capability, or for a chat route the Chat row of Settings › Access (`edit_posts` unless the site changed it)
         * @param \WP_REST_Request $request    the request being authorised
         */
        return Capability::filtered("alpaca_bot/capability/{$route}", $default, $request);
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
