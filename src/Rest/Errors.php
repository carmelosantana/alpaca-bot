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
     * own, which keeps the site-wide figures out of a user-facing reply, and the data follows
     * it: `limit` and `used` ride along for the user's own cap only. The site's cap and its
     * month-to-date total are what `GET /usage` withholds from a non-administrator, and anyone
     * who may chat can reach this error by chatting once past the cap. `scope` is always there,
     * so a client can say whose cap it was.
     */
    public static function capExceeded(CapExceeded $e): \WP_Error
    {
        $data = ['status' => 402, 'scope' => $e->scope];
        if ($e->scope === 'user') {
            $data += ['limit' => $e->limit, 'used' => $e->used];
        }
        return new \WP_Error('alpaca_bot_cap_exceeded', $e->getMessage(), $data);
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

    /**
     * 405 for a method a route cannot answer. Core sends a HEAD to a route's GET handler when
     * no HEAD handler is registered, so a route whose GET has an effect (the stream route runs
     * a turn) refuses it here; the Allow header a 405 must carry is the route's to add.
     */
    public static function methodNotAllowed(): \WP_Error
    {
        return new \WP_Error('alpaca_bot_method_not_allowed', __('This route does not answer that method.', 'alpaca-bot'), ['status' => 405]);
    }

    /** `$what` is the (translated) noun, e.g. "Conversation"; used for another user's resource too, so existence is not leaked. */
    public static function notFound(string $what): \WP_Error
    {
        /* translators: %s: the kind of thing that was asked for, e.g. Conversation */
        return new \WP_Error('alpaca_bot_not_found', sprintf(__('%s not found.', 'alpaca-bot'), $what), ['status' => 404]);
    }

    /**
     * 502: the upstream model provider failed, so the client should not retry blindly and the
     * operator should look at the provider.
     *
     * The message is fixed. What the provider threw quotes its endpoint (the vendored client's
     * transport and HTTP errors both end in `for "http://host:port/v1/chat/completions"`), and
     * anyone who may chat can make it throw by posting while the provider is down, so the raw
     * text is not for the caller: it goes to the debug log, behind WP_DEBUG as core's own
     * logging is, and to `data.detail` when the request is an administrator's, who is the one
     * person it helps and may read the settings that hold the URL anyway. The code and status are
     * the wire contract and do not change with who asked.
     */
    public static function provider(\Throwable $e): \WP_Error
    {
        $detail = $e->getMessage();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[alpaca-bot] ' . $detail);
        }
        $data = ['status' => 502];
        if (current_user_can('manage_options')) {
            $data['detail'] = $detail;
        }
        return new \WP_Error('alpaca_bot_provider_error', __('The model provider could not complete the request.', 'alpaca-bot'), $data);
    }

    /**
     * What a pipeline turn's failure becomes, for every caller that runs one: CapExceeded is the
     * 402, an InvalidArgumentException (the caller's mistake, in the pipeline's own words) the
     * 400, anything else the 502.
     *
     * The buffered route returns this and the stream route writes it into an `error` frame, so
     * one turn told two ways refuses the same way. It is a function rather than the same three
     * catch blocks in both because the policy has to stay one when a later phase adds a fourth
     * exception class: prose saying "these must match" does not fail a build, and the second
     * copy is the one that gets missed.
     */
    public static function fromPipeline(\Throwable $e): \WP_Error
    {
        return match (true) {
            $e instanceof CapExceeded => self::capExceeded($e),
            $e instanceof \InvalidArgumentException => self::badRequest($e->getMessage()),
            default => self::provider($e),
        };
    }
}
