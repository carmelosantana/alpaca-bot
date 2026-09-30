<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

/**
 * Enforces the monthly token caps server-side, before every provider call: once before a turn
 * starts (assertAllowed()), and again before each further call of a tool turn
 * (assertMayContinue(), Kanboard #4701). Both count, on top of the month's receipts, what every
 * tool turn still running in this request has spent (hold()), which no receipt holds until that
 * turn ends. So a turn that a tool starts inside another (SummarizeToolkit's) is checked against
 * the enclosing turn's spend too, and no provider call is made once the cap has been reached:
 * the overshoot is at most the one call that crossed it.
 *
 * The per-user cap is checked first, then the site-wide one; a cap of 0 is "unlimited" and is
 * skipped without reading usage. Usage equal to the cap counts as reached. Each verdict passes
 * through `alpaca_bot/cap/allowed` (bool $allowed, int $userId, string $scope, int $limit,
 * int $used), which can lift a block or impose one, at both checks alike.
 *
 * The running spend lives on this instance, and one instance serves the request (Plugin hands
 * every consumer the container's), which is the scope a nested turn shares with the turn it is
 * nested in. Concurrent requests do not see each other's (Pipeline::send() says what that costs).
 */
final class CapPolicy
{
    /** @var array<int, array{0: int, 1: \Closure(): int}> the running turns' spend, by hold() id: [user id, what the turn has spent so far] */
    private array $held = [];

    private int $nextHold = 0;

    public function __construct(private Store $store, private UsageMeter $meter) {}

    /**
     * Before a turn: may it start?
     *
     * @throws CapExceeded with `stopped` false
     */
    public function assertAllowed(int $userId): void
    {
        $this->check($userId, false);
    }

    /**
     * Between two provider calls of one turn: may it make the next? The turn's own spend is
     * counted through its hold().
     *
     * @throws CapExceeded with `stopped` true
     */
    public function assertMayContinue(int $userId): void
    {
        $this->check($userId, true);
    }

    /**
     * Counts a running turn's spend toward the caps until release(): `$spent` is read at each
     * check, and a negative figure counts as 0. The id is release()'s.
     *
     * @param \Closure(): int $spent
     */
    public function hold(int $userId, \Closure $spent): int
    {
        $id = ++$this->nextHold;
        $this->held[$id] = [$userId, $spent];
        return $id;
    }

    /** Stops counting a hold(): its turn has ended, and its receipt (if any) is now the month's. */
    public function release(int $id): void
    {
        unset($this->held[$id]);
    }

    /** What the running turns have spent: the user's, or every user's when null. */
    private function running(?int $userId): int
    {
        $spent = 0;
        foreach ($this->held as [$owner, $read]) {
            if ($userId === null || $owner === $userId) {
                $spent += max(0, $read());
            }
        }
        return $spent;
    }

    /** @throws CapExceeded */
    private function check(int $userId, bool $midTurn): void
    {
        foreach (['user' => $userId, 'site' => null] as $scope => $who) {
            $limit = (int) $this->store->get("governance.{$scope}_monthly_tokens", 0);
            if ($limit <= 0) {
                continue;
            }
            $used = $this->meter->monthTotal($who) + $this->running($who);
            /**
             * Filters the verdict of one monthly token-cap check. A check is made before a turn
             * starts, and again before each further provider call of a tool turn; at both, `$used`
             * counts what any tool turn still running in this request has spent so far (the turn
             * itself, or the one a summarize call was made from). Fires once per scope that has a cap
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
             * @param int    $used    tokens this scope has used this UTC calendar month, the running turns' spend included
             */
            $allowed = (bool) apply_filters('alpaca_bot/cap/allowed', $used < $limit, $userId, $scope, $limit, $used);
            if (!$allowed) {
                throw new CapExceeded($scope, $limit, $used, $midTurn);
            }
        }
    }
}
