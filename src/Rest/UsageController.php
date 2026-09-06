<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Settings\Store;

/**
 * `GET /usage`: this calendar month's token spend, `{tokens, requests, month, caps: {user,
 * site}}`, from UsageMeter's cached month summary. `user=me` (the default) is the current
 * user's own figures, the ones the per-user cap is measured against; `user=all` is the whole
 * site's, which only an administrator may read: what other people spend is theirs, and the
 * site-wide total is the operator's concern (CapExceeded keeps it out of the user-facing
 * message for the same reason). The caps come with either answer so a client can draw
 * "used of allowed" without a second request; 0 means no cap, as the schema says.
 *
 * `user` is an enum in the route schema, so a user id or anything else is core's 400 before
 * this runs: the route has two answers, and a client that wants another person's figures is
 * looking for a report, not this route.
 */
final class UsageController extends Controller
{
    public function __construct(private UsageMeter $meter, private Store $store) {}

    public function routes(): array
    {
        return [[
            'path' => '/usage',
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            'capability' => 'edit_posts',
            'args' => ['user' => ['type' => 'string', 'default' => 'me', 'enum' => ['me', 'all']]],
        ]];
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $all = $request->get_param('user') === 'all';
        if ($all && !current_user_can('manage_options')) {
            return Errors::forbidden();
        }
        $summary = $this->meter->monthSummary($all ? null : $this->userId());
        return new \WP_REST_Response($summary + ['caps' => [
            'user' => (int) $this->store->get('governance.user_monthly_tokens'),
            'site' => (int) $this->store->get('governance.site_monthly_tokens'),
        ]]);
    }
}
