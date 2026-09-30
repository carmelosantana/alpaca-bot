<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Capability;

/**
 * Where `alpaca_bot/capability/{route}` is applied: Controller::capability() asks it for every
 * request a route authorises, and Admin\SettingsPage asks it of the `chat` key to show whether
 * code changed the Chat row for the `/chat` route.
 *
 * A final class of its own rather than a method on Controller, because Controller is the class a
 * site extends (docs/api.md), and a method added there can clash with one a subclass already
 * declares: when this was a public static method on Controller, a subclass declaring it
 * non-static, or protected, did not load (probed on PHP 8.4). Nothing here is for a site to call
 * or extend.
 *
 * @internal
 * @since 0.6.0
 */
final class RouteCapability
{
    /** `alpaca_bot/capability/{$route}` over `$default`, for `$request`, reduced to a capability name. */
    public static function filtered(string $route, string $default, \WP_REST_Request $request): string
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
         * the turn's user passes (`alpaca_bot/capability/tool/{id}`, or `alpaca_bot/capability/mcp/{id}`
         * for an MCP server), before `alpaca_bot/toolkits`
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
         * @param \WP_REST_Request $request    the request being authorised, or the `POST /chat` one Settings › Access built to ask
         */
        return Capability::filtered("alpaca_bot/capability/{$route}", $default, $request);
    }
}
