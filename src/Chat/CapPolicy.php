<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

/**
 * Enforces the monthly token caps server-side, before every provider call: once before a turn
 * starts (assertAllowed()), and again before each further call of a tool turn
 * (assertMayContinue(), Kanboard #4701), counting what the turn has spent so far on top of the
 * month's total. Between the two, a turn can overshoot a cap by at most one provider call.
 *
 * The per-user cap is checked first, then the site-wide one; a cap of 0 is "unlimited" and is
 * skipped without reading usage. Usage equal to the cap counts as reached. Each verdict passes
 * through `alpaca_bot/cap/allowed` (bool $allowed, int $userId, string $scope, int $limit,
 * int $used), which can lift a block or impose one, at both checks alike.
 */
final class CapPolicy
{
    public function __construct(private Store $store, private UsageMeter $meter) {}

    /**
     * Before a turn: may it start?
     *
     * @throws CapExceeded with `stopped` false
     */
    public function assertAllowed(int $userId): void
    {
        $this->check($userId, 0, false);
    }

    /**
     * Between two provider calls of one turn: may it make the next? `$spent` is what the turn
     * has used so far, which no receipt holds yet (the turn records one when it ends), so it is
     * added to the month's total here; a negative figure counts as 0.
     *
     * @throws CapExceeded with `stopped` true
     */
    public function assertMayContinue(int $userId, int $spent): void
    {
        $this->check($userId, max(0, $spent), true);
    }

    /** @throws CapExceeded */
    private function check(int $userId, int $spent, bool $midTurn): void
    {
        foreach (['user' => $userId, 'site' => null] as $scope => $who) {
            $limit = (int) $this->store->get("governance.{$scope}_monthly_tokens", 0);
            if ($limit <= 0) {
                continue;
            }
            $used = $this->meter->monthTotal($who) + $spent;
            /**
             * Filters the verdict of one monthly token-cap check. A check is made before a turn
             * starts, and again before each further provider call of a tool turn, where `$used`
             * counts the tokens the turn has spent so far. Fires once per scope that has a cap
             * (`user`, then `site`) at each check; a cap of 0 skips its scope without firing.
             * Return false to block a turn the numbers would allow (a per-role cap, a site-wide
             * freeze), or true to let one through that has reached its cap. A block throws
             * CapExceeded, which the REST routes answer with 402 and the CLI with an error; a turn
             * blocked mid-way keeps what it had produced, stored as a partial reply.
             *
             * @since 0.5.0
             * @param bool   $allowed whether `$used` is under `$limit`
             * @param int    $userId  the user whose turn is being checked
             * @param string $scope   `user` or `site`
             * @param int    $limit   the cap for this scope, in tokens
             * @param int    $used    tokens this scope has used this UTC calendar month, the running turn's included
             */
            $allowed = (bool) apply_filters('alpaca_bot/cap/allowed', $used < $limit, $userId, $scope, $limit, $used);
            if (!$allowed) {
                throw new CapExceeded($scope, $limit, $used, $midTurn);
            }
        }
    }
}
