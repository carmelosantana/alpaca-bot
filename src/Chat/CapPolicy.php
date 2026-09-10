<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

/**
 * Enforces the monthly token caps server-side, before any provider call.
 *
 * The per-user cap is checked first, then the site-wide one; a cap of 0 is "unlimited" and is
 * skipped without reading usage. Usage equal to the cap counts as reached. Each verdict passes
 * through `alpaca_bot/cap/allowed` (bool $allowed, int $userId, string $scope, int $limit,
 * int $used), which can lift a block or impose one.
 */
final class CapPolicy
{
    public function __construct(private Store $store, private UsageMeter $meter) {}

    /** @throws CapExceeded */
    public function assertAllowed(int $userId): void
    {
        foreach (['user' => $userId, 'site' => null] as $scope => $who) {
            $limit = (int) $this->store->get("governance.{$scope}_monthly_tokens", 0);
            if ($limit <= 0) {
                continue;
            }
            $used = $this->meter->monthTotal($who);
            /**
             * Filters the verdict of one monthly token-cap check, made before any provider call. Fires
             * once per scope that has a cap (`user`, then `site`); a cap of 0 skips its scope without
             * firing. Return false to block a turn the numbers would allow (a per-role cap, a site-wide
             * freeze), or true to let one through that has reached its cap. A block throws CapExceeded,
             * which the REST routes answer with 402 and the CLI with an error.
             *
             * @since 0.5.0
             * @param bool   $allowed whether `$used` is under `$limit`
             * @param int    $userId  the user whose turn is being checked
             * @param string $scope   `user` or `site`
             * @param int    $limit   the cap for this scope, in tokens
             * @param int    $used    tokens this scope has used this UTC calendar month
             */
            $allowed = (bool) apply_filters('alpaca_bot/cap/allowed', $used < $limit, $userId, $scope, $limit, $used);
            if (!$allowed) {
                throw new CapExceeded($scope, $limit, $used);
            }
        }
    }
}
