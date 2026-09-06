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
            $allowed = (bool) apply_filters('alpaca_bot/cap/allowed', $used < $limit, $userId, $scope, $limit, $used);
            if (!$allowed) {
                throw new CapExceeded($scope, $limit, $used);
            }
        }
    }
}
