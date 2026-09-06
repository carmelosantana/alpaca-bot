<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\CapExceeded;

/**
 * The WP_Error shapes every route answers with. Each carries `status` in its data, which is how
 * the REST server picks the HTTP status for an error, so a controller returns these as they are.
 *
 * Codes are namespaced `alpaca_bot_*` except `rest_forbidden`, which is core's own code for a
 * permission failure: clients (and core's own tooling) already special-case it, and a plugin
 * inventing a second name for the same condition only makes them handle both.
 */
final class Errors
{
    /**
     * 403 for a logged-in user who lacks the capability, 401 for a visitor: 401 tells a client
     * to authenticate (cookie + nonce, or an Application Password) and try again, 403 tells it
     * that would not help. Same split core's own permission callbacks make.
     */
    public static function forbidden(string $message = ''): \WP_Error
    {
        return new \WP_Error(
            'rest_forbidden',
            $message !== '' ? $message : __('You are not allowed to do that.', 'alpaca-bot'),
            ['status' => is_user_logged_in() ? 403 : 401],
        );
    }

    /** `retry_after` in the data mirrors the Retry-After header Controller adds, for clients that read the body only. */
    public static function tooMany(int $retryAfter): \WP_Error
    {
        return new \WP_Error(
            'alpaca_bot_rate_limited',
            __('Too many requests. Try again shortly.', 'alpaca-bot'),
            ['status' => 429, 'retry_after' => $retryAfter],
        );
    }

    /**
     * 402 Payment Required is the nearest status for "your monthly allowance is spent": not a
     * permission problem (403) and not the client's fault (4xx otherwise), and distinct enough
     * that a client can show the cap rather than a generic error. The message is CapExceeded's
     * own, which already keeps the site-wide figures out of a user-facing reply.
     */
    public static function capExceeded(CapExceeded $e): \WP_Error
    {
        return new \WP_Error(
            'alpaca_bot_cap_exceeded',
            $e->getMessage(),
            ['status' => 402, 'scope' => $e->scope, 'limit' => $e->limit, 'used' => $e->used],
        );
    }

    /**
     * 400 for a request the pipeline refused as the caller's mistake: an empty message, an image
     * that is not a data URL, a model the catalog does not list, a conversation that is not
     * theirs. The pipeline's InvalidArgumentException messages are already written for the
     * person who sent the request, so a route passes them through as the message.
     */
    public static function badRequest(string $message): \WP_Error
    {
        return new \WP_Error('alpaca_bot_bad_request', $message, ['status' => 400]);
    }

    /** `$what` is the (translated) noun, e.g. "Conversation"; used for another user's resource too, so existence is not leaked. */
    public static function notFound(string $what): \WP_Error
    {
        /* translators: %s: the kind of thing that was asked for, e.g. Conversation */
        return new \WP_Error('alpaca_bot_not_found', sprintf(__('%s not found.', 'alpaca-bot'), $what), ['status' => 404]);
    }

    /** 502: the upstream model provider failed, so the client should not retry blindly and the operator should look at the provider. */
    public static function provider(\Throwable $e): \WP_Error
    {
        return new \WP_Error('alpaca_bot_provider_error', $e->getMessage(), ['status' => 502]);
    }
}
