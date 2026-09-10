<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\Assistant;
use AlpacaBot\Settings\Store;

/**
 * What one streamed turn may consume: a wall-clock budget in seconds, and a cap on how many
 * streams one person may hold open at once. Both are this class's because they are one policy
 * and share one number — a slot is stamped with the same budget the process was given, so a
 * process that is killed at its deadline cannot leave a slot anyone will count.
 *
 * Why there has to be a bound at all. `GET /chat/{id}/stream` is deliberately not rate limited
 * (StreamController::routes() says why: the turn was counted on the POST that issued the
 * ticket), and Sse::prepareOutput() sets `ignore_user_abort(true)`, so a redeemed ticket runs
 * to its end whether or not anyone is still reading. Thirty tickets a minute — the `chat`
 * bucket's limit — therefore redeem into thirty PHP workers held for as long as the turns take,
 * from one authenticated account or one leaked Application Password. An ordinary PHP-FPM pool is
 * 5 to 50 workers, so that is the whole site down, not only the plugin.
 *
 * ## The budget is chosen, not derived, and this is why
 *
 * There is no ceiling to read out of the code:
 *
 * - A tool turn runs Assistant::MAX_ITERATIONS (6) iterations, each exactly one
 *   `provider->stream()` call. That part is a real bound. (The library's empty-reply nudges are
 *   not extra calls on top: they `continue` the same loop and spend iterations from the 6.)
 * - Each iteration may also run tool calls, and `summarize` is a nested ephemeral
 *   `Pipeline::complete()` — another whole provider call. How many tool calls one iteration
 *   asks for is the model's choice, so the number of nested calls is not bounded by anything
 *   here.
 * - `provider.timeout` is not a per-call ceiling either. Factory passes it to Symfony's HTTP
 *   client as `timeout`, which is the *idle* timeout between chunks, so a provider that emits
 *   one byte just under it keeps a call alive indefinitely; and the `wp-ai` provider kind does
 *   not consult the setting at all.
 *
 * So `seconds()` is a policy: `provider.timeout × MAX_ITERATIONS × 2`, read as "one provider
 * call per iteration, plus one nested tool call per iteration". At the defaults (60 s, 6) that
 * is 720 s — twelve minutes for one chat turn, generous for a person watching a reply arrive and
 * finite for a pool. At the schema's maximum timeout of 600 s it is two hours, which is what a
 * site asking for ten-minute provider waits has asked for; the concurrency cap, not the clock,
 * is what keeps that site's pool alive. Filter `alpaca_bot/stream/budget` moves it.
 *
 * ## How the budget is enforced, and where each mechanism stops
 *
 * Three things, because no one of them covers the ground:
 *
 * - StreamController::stream() compares the clock after every frame it writes and ends the turn
 *   with a `stream_timeout` error frame. This is the bound that does the work for a stream that
 *   is producing output, and it is checked in exactly the places the abort check is, including
 *   before every tool call (the pipeline emits an empty delta there).
 * - `set_time_limit(seconds())` in Sse::prepareOutput(), instead of the `0` that was there. This
 *   is a backstop for a runaway that is spending CPU rather than waiting, and **not** the
 *   wall-clock bound: on Unix, max_execution_time does not count time blocked in a stream
 *   operation, which is where a slow provider's time goes.
 * - A stream stuck inside one provider call writes no frame, so neither check above reaches it.
 *   What ends that is the provider's own idle timeout (Factory's Symfony `timeout` on the
 *   `ollama` kind; core's AI client on `wp-ai`), which throws into stream()'s catch and becomes
 *   an error frame.
 *
 * ## The concurrency cap
 *
 * `claim()` hands out at most LIMIT slots per person at a time, and a refused redemption is a
 * 429 that leaves the ticket unspent, so the client may retry it within its 120 s life. The
 * slots live in one transient per person, as slot id => the timestamp the slot expires at.
 * Per-entry stamps rather than a counter with a TTL: a counter's TTL expires the whole count at
 * once, taking other live streams' slots with it, while a stamped entry frees exactly the slot
 * whose stream is over.
 *
 * `release()` is called from a `finally` in StreamController::serve(), which is why the stamp
 * matters. `finally` covers every ordinary end — the reply finishing, the client aborting, a
 * throw — but not a fatal error, an out-of-memory, or a killed process. A shutdown function
 * would cover the fatal; it is deliberately not used, because the one fatal a bounded stream
 * has is `set_time_limit()` expiring, and the slot was stamped with that same deadline, so the
 * slot is already expired to every later reader by the time the process dies. That leaves only
 * kill and OOM, which no in-process hook survives and which the stamp bounds anyway. One
 * release path that always runs beats two that have to agree.
 *
 * Read-modify-write on a transient is not atomic, so N redemptions arriving at the same instant
 * can each see the same free slot and overshoot the cap by up to N. That is accepted: this cap
 * exists to stop tickets *accumulating* into held workers over a minute, which it does, and the
 * exact-simultaneity case is bounded by the `chat` bucket's 30/minute on the POST side. The
 * ticket redemption, where an off-by-one would mean a billed turn running twice, is not built
 * this way — StreamController::handle() claims it with delete_transient()'s own report.
 *
 * @since 0.5.0
 */
final class StreamBudget
{
    /**
     * Live streams one person may hold at once. Three, because a person is one chat: a second
     * tab, or a reply still finishing while they send the next message, are ordinary; a fourth
     * concurrent stream from one account is a script. Filter `alpaca_bot/stream/concurrent`
     * moves it for a site whose clients are not people.
     */
    public const LIMIT = 3;

    /** One transient per person, holding that person's live slots. */
    public const TRANSIENT = 'alpaca_bot_streams_';

    public function __construct(private Store $store) {}

    /**
     * The wall-clock budget for one streamed turn, in seconds. Never below one provider timeout:
     * a filter that forgot to return (null casts to 0) would otherwise end every stream before
     * its first provider call, which is an outage dressed as a timeout.
     */
    public function seconds(): int
    {
        $timeout = max(1, (int) $this->store->get('provider.timeout'));
        /**
         * Filters how long one streamed turn may run before it is ended with a `stream_timeout`
         * error frame, in seconds. The default is `provider.timeout × 6 × 2`: six agent
         * iterations, each a provider call, plus room for one nested tool call (`summarize`) per
         * iteration. Raise it for a site whose turns legitimately run long, lower it to hold the
         * PHP worker pool tighter. Anything below one provider timeout is raised to it, so a
         * filter that forgot to return cannot end every turn before it starts.
         *
         * @since 0.5.0
         * @param int $seconds the default budget
         * @param int $timeout the site's `provider.timeout`, the number it was derived from
         */
        $seconds = (int) apply_filters('alpaca_bot/stream/budget', $timeout * Assistant::MAX_ITERATIONS * 2, $timeout);
        return max($timeout, $seconds);
    }

    /**
     * Takes a slot for `$userId`, or reports that they are already at the limit.
     *
     * `retry_after` on a refusal is the seconds until the *longest*-lived slot this person holds
     * expires: every held slot is gone by then, so a client that waits it out will get through.
     * A stream that ends normally frees its slot long before, which is why it is a ceiling on
     * the wait and not a prediction of it.
     *
     * @return array{slot: string|null, retry_after: int}
     */
    public function claim(int $userId): array
    {
        $now = (int) current_time('timestamp', true);
        $key = self::TRANSIENT . RateLimit::subject($userId);
        $slots = $this->live($key, $now);
        /**
         * Filters how many streamed turns one person may have running at once. The stream route
         * is not rate limited (the turn was counted on the POST that issued the ticket), so this
         * is what stops one account's tickets redeeming into one PHP worker each; a redemption
         * over the limit is a 429 and its ticket stays valid. `$userId` is 0 for a visitor on a
         * site that has opened the route through `alpaca_bot/capability/chat/stream`, and the
         * count is then kept per client address rather than for visitors as a whole. Anything
         * below 1 is raised to 1: a filter that forgot to return should not close the route.
         *
         * @since 0.5.0
         * @param int $limit  the default, StreamBudget::LIMIT (3)
         * @param int $userId whose streams are being counted, 0 for a visitor
         */
        $limit = max(1, (int) apply_filters('alpaca_bot/stream/concurrent', self::LIMIT, $userId));
        if (count($slots) >= $limit) {
            return ['slot' => null, 'retry_after' => max(1, max($slots) - $now)];
        }
        // Unique, not unpredictable: a slot id is minted here, kept in this process and in this
        // person's own transient, and never sent to a client or accepted from one, so there is
        // nothing to guess. random_bytes() is for uniqueness across concurrent processes, which
        // is the only property asked of it — wp_generate_password() would be the same bytes
        // dressed as a secret.
        $slot = bin2hex(random_bytes(6));
        $slots[$slot] = $now + $this->seconds();
        $this->store($key, $slots, $now);
        return ['slot' => $slot, 'retry_after' => 0];
    }

    /** Gives back a slot claim() handed out. A slot that has already expired on its own is a no-op. */
    public function release(int $userId, string $slot): void
    {
        $now = (int) current_time('timestamp', true);
        $key = self::TRANSIENT . RateLimit::subject($userId);
        $slots = $this->live($key, $now);
        unset($slots[$slot]);
        $this->store($key, $slots, $now);
    }

    /**
     * The person's slots that have not expired. Expired entries are dropped on the way past
     * rather than on a schedule: the only code that reads this transient is this class, and
     * every path through it either writes the pruned set back or is about to.
     *
     * @return array<string, int> slot id => the timestamp it expires at
     */
    private function live(string $key, int $now): array
    {
        $slots = get_transient($key);
        if (!is_array($slots)) {
            return [];
        }
        $live = [];
        foreach ($slots as $slot => $expires) {
            if (is_string($slot) && is_int($expires) && $expires > $now) {
                $live[$slot] = $expires;
            }
        }
        return $live;
    }

    /**
     * Writes the slot set back, with a TTL that reaches the last slot to expire so the row
     * outlives every stamp in it; an empty set is deleted rather than stored as one.
     *
     * @param array<string, int> $slots
     */
    private function store(string $key, array $slots, int $now): void
    {
        if ($slots === []) {
            delete_transient($key);
            return;
        }
        set_transient($key, $slots, max(1, max($slots) - $now));
    }
}
