<?php

declare(strict_types=1);

namespace AlpacaBot\Rest;

use AlpacaBot\Chat\Assistant;
use AlpacaBot\Settings\Store;

/**
 * What one streamed turn may consume: a wall-clock budget in seconds, and a cap on how many
 * streams one person may hold open at once. Both are this class's because they are one policy
 * and share one number — a slot is leased for the same budget the process was given, so a
 * process killed at its deadline cannot leave a slot anyone will count.
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
 * site asking for ten-minute provider waits has asked for. Filter `alpaca_bot/stream/budget`
 * moves it.
 *
 * ## How the budget is enforced, and where each mechanism stops
 *
 * Three things, because no one of them covers the ground:
 *
 * - StreamController::stream() compares the clock after every frame it writes and ends the turn
 *   with a `stream_timeout` error frame. This is the bound that does the work for a stream that
 *   is producing output, and it is checked in exactly the places the abort check is, including
 *   before every tool call (the pipeline emits an empty delta there).
 * - `set_time_limit()` in Sse::prepareOutput(), instead of the `0` that was there, on the number
 *   StreamController::serve() reads from seconds() and hands it. This
 *   is a backstop for a runaway that is spending CPU rather than waiting, and **not** the
 *   wall-clock bound: on Unix, max_execution_time does not count time blocked in a stream
 *   operation, which is where a slow provider's time goes.
 * - A stream stuck inside one provider call writes no frame, so neither check above reaches it.
 *   What ends that is the provider's own idle timeout (Factory's Symfony `timeout` on the
 *   `ollama` kind; core's AI client on `wp-ai`), which throws into stream()'s catch and becomes
 *   an error frame.
 *
 * ## The concurrency cap, and why it is SQL
 *
 * `claim()` hands out at most LIMIT slots per person at a time, and a refused redemption is a
 * 429 that leaves the ticket unspent, so the client may retry it within its 120 s life.
 *
 * A cap is only a cap if the claim is atomic, and this one is the only place in `src/` that
 * reaches for `$wpdb` to make it so. That is a deliberate exception, and the reason is that
 * every portable alternative decides on a *prior read*, which is what a concurrency cap cannot
 * do:
 *
 * - A read-modify-write over one transient — a slot array, or a counter — is what this class
 *   did first, and it does not cap anything. K redemptions arriving together all read the same
 *   set and all write `set + their own slot`, so the store ends at "one more than before" while
 *   K streams are live. The count never records the overshoot, so the race is repeatable rather
 *   than a one-off skew, and a refused redemption keeps its ticket, so retrying until it lands
 *   is free. Probed against this class as it then stood, three batches of ten redemptions from
 *   one account against a cap of three gave 30 live streams and a recorded count of 3.
 * - `add_option()` is not the atomic insert it looks like. It decides on `get_option()`
 *   (`wp-includes/option.php`, "Make sure the option doesn't already exist"), and its write is
 *   `INSERT … ON DUPLICATE KEY UPDATE`, which succeeds for every racer.
 * - `wp_cache_add()` is atomic on some persistent object caches and guaranteed by none of them.
 *   On a default installation there is no persistent cache at all: the cache is an array that
 *   lives for one request, so it can only refuse a key this same process already added and knows
 *   nothing of any other worker.
 *
 * What is atomic is a uniquely-keyed row: `option_name` carries a UNIQUE index, so `INSERT
 * IGNORE` either inserts the row (1 affected) or finds it already there (0 affected), decided by
 * the database rather than by anything this process read. Core claims its own locks exactly this
 * way: `WP_Upgrader::create_lock()` runs
 * `INSERT IGNORE INTO $wpdb->options … VALUES (%s, %s, 'off')`, with a trailing SQL comment
 * reading LOCK, and it is what the core updater (`Core_Upgrader::upgrade()`) and every automatic
 * background update run (`WP_Automatic_Updater::run()`) take their lock with. So this is core's
 * idiom on core's table, not a new dependency on the schema.
 *
 * One row per slot, named `alpaca_bot_stream_slot_<person>_<index>`, holding `<expiry>:<token>`,
 * autoloaded off. `claim()` walks the indexes and takes the first it can; a row whose expiry has
 * passed is taken over with `UPDATE … WHERE option_name = %s AND option_value = %s`, again
 * decided by the affected-row count, so two processes finding the same lapsed row cannot both
 * win it. `release()` deletes by name *and* value, so a slot that lapsed and was taken over by a
 * later stream is never deleted by the earlier one. The rows are read and written only here and
 * only through `$wpdb`, so no object cache holds a stale copy of them.
 *
 * The longest name this makes is the prefix, then the subject, then `_` and the slot index. The
 * subject is the longer for a visitor (`ip_` and a 32-character wp_hash, 35) than for a user (an
 * id), so at the shipped LIMIT of 3 that is 60 characters against `option_name`'s varchar(191).
 * The index is one digit only because LIMIT is 3, and `alpaca_bot/stream/concurrent` has no
 * ceiling -- claim() floors it at 1 and stops there -- so the bound has to come from the type:
 * the filter's result is cast to int, so the largest index is PHP_INT_MAX - 1, 19 digits, and
 * the longest name any site can produce is 78 characters. Still nothing near the length where a
 * name would be truncated into another one's. It cannot collide with the stream
 * ticket either, which is a transient and therefore a row core names
 * `_transient_alpaca_bot_stream_…`.
 *
 * ## What the lease does and does not bound
 *
 * `release()` is called from a `finally` in StreamController::serve(), and the expiry on the row
 * is what covers the ends a `finally` cannot see: a fatal error, an out-of-memory, a killed
 * process, or a redemption whose `rest_pre_serve_request` is never reached at all. A shutdown
 * function would catch some of those and is deliberately not used: one release path that always
 * runs beats two that have to agree, and the leak it would save is fail-closed — it costs that
 * person their own capacity and nobody else's — and lasts at most `seconds()`.
 *
 * A lapsed row is taken over by that subject's next claim rather than swept on a schedule, so
 * what a killed process leaves behind is at most LIMIT autoload-off options rows per subject,
 * reused the next time that subject streams and otherwise inert. Nothing sweeps them for a
 * subject that never comes back; that is the price of a lease that outlives the process holding
 * it. Per subject is the bound, and on a site that keeps the route to logged-in people the
 * subjects are users, so the total is bounded by the user table. Open the route to visitors and
 * the subject becomes a client address: the rows stay autoload-off and inert, but nothing bounds
 * how many addresses turn up, so that site wants a sweep this class does not provide.
 *
 * The lease bounds **counting, not holding**. A stream blocked inside one provider call writes
 * no frame, and Unix max_execution_time does not count time blocked in a stream operation, so
 * neither the in-band deadline nor `set_time_limit()` reaches it: at `seconds()` its row lapses
 * while its PHP worker is still held, and that person may then claim the slot again. So a
 * provider that hangs — or trickles a byte just under its idle timeout — is the one case where
 * one person holds more than LIMIT workers, and it is not fixable from inside this process,
 * because the only code that could shorten the lease is the code that is blocked. What ends
 * those workers is the provider's own idle timeout. Everything that reaches a frame, a throw or
 * an exit is capped at LIMIT.
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

    /** One option row per slot: this prefix, then the person, then the slot's index. */
    public const OPTION = 'alpaca_bot_stream_slot_';

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
         * Applied wherever the number is needed rather than resolved once per request, so one
         * redemption asks three times: the slot lease in claim(), then StreamController twice.
         * serve() reads it for both of its uses at once -- the process time limit it hands
         * prepareOutput(), and the deadline instant it passes into stream(). stream() reads it
         * again, and on a redemption that copy is *not* the deadline, because serve() already
         * gave it one: it is only the number the `stream_timeout` frame advertises as the limit.
         * (stream()'s `??=` default is for a caller that passes no deadline, which is the tests
         * and the direct-call path, not the route.) So a filter that does not answer the same
         * number every time will advertise a limit it did not enforce, and a filter that counts
         * or logs will see three calls where a request looks like one.
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
     * The returned slot is `<index>:<expiry>:<token>` — the index names the row and the rest is
     * exactly what was written into it, which is what lets release() delete the claim it made
     * and only that claim.
     *
     * `retry_after` on a refusal is the seconds until the *soonest*-expiring slot this person
     * holds lapses: that slot is claimable then, so a client that waits it out gets through. A
     * stream that ends normally frees its slot long before, which is why it is a ceiling on the
     * wait and not a prediction of it. A slot whose row could not be read contributes no stamp,
     * and if none of them could the ceiling is a whole budget.
     *
     * @return array{slot: string|null, retry_after: int}
     */
    public function claim(int $userId): array
    {
        $now = (int) current_time('timestamp', true);
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
        $expires = $now + $this->seconds();
        // Unique, not unpredictable: a slot token is minted here, kept in this process and in
        // this person's own rows, and never sent to a client or accepted from one, so there is
        // nothing to guess. random_bytes() is for uniqueness across concurrent processes, which
        // is the only property asked of it — wp_generate_password() would be the same bytes
        // dressed as a secret.
        $held = $expires . ':' . bin2hex(random_bytes(6));
        $subject = RateLimit::subject($userId);
        $waits = [];
        for ($index = 0; $index < $limit; $index++) {
            $wait = $this->take(self::OPTION . $subject . '_' . $index, $held, $now);
            if ($wait === null) {
                return ['slot' => $index . ':' . $held, 'retry_after' => 0];
            }
            $waits[] = $wait;
        }
        $waits = array_filter($waits);
        return ['slot' => null, 'retry_after' => max(1, ($waits === [] ? $expires : min($waits)) - $now)];
    }

    /**
     * Gives back a slot claim() handed out. The delete names the value as well as the row, so a
     * slot this claim had already lost — its lease lapsed and another stream took the row over —
     * is left with its new holder. A slot already gone is a no-op.
     */
    public function release(int $userId, string $slot): void
    {
        [$index, $held] = array_pad(explode(':', $slot, 2), 2, '');
        if ($held === '' || preg_match('/^\d+$/', $index) !== 1) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The class docblock says why the slot rows are $wpdb's and not the options API's: the claim has to be decided by the affected-row count rather than by a prior read. Both values are prepared; the only interpolation is $wpdb->options, the table name, as core's own WP_Upgrader::create_lock() writes it. No cache to invalidate: nothing reads these rows through get_option().
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s", self::OPTION . RateLimit::subject($userId) . '_' . $index, $held));
    }

    /**
     * Takes one slot row, or says when its holder's lease lapses.
     *
     * The insert is the claim: `INSERT IGNORE` is 1 affected row when this call created the row
     * and 0 when the UNIQUE index on `option_name` found it already there, which is the whole
     * reason this is SQL (see the class docblock). A row that is there but lapsed is taken over
     * with a compare-and-set on its exact value, so the affected-row count decides that race too.
     *
     * @return int|null null when the slot is now this caller's; otherwise the timestamp the
     *                  holder's lease expires at, or 0 when that could not be read — the row was
     *                  gone by the time it was asked for, or the store refused the write for a
     *                  reason of its own. 0 says "occupied, no stamp to offer", which keeps a
     *                  guess out of the Retry-After a caller is handed.
     */
    private function take(string $name, string $held, int $now): ?int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As release(): the affected-row count is the claim, every value is prepared, and $wpdb->options is the table name core interpolates the same way in WP_Upgrader::create_lock().
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off')", $name, $held)) === 1) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads the row the insert above did not get, to learn whether its lease has lapsed. get_option() would answer from the object cache, which nothing here writes to.
        $there = $wpdb->get_var($wpdb->prepare("SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", $name));
        if (!is_string($there)) {
            return 0;
        }
        $expires = (int) explode(':', $there, 2)[0];
        if ($expires > $now) {
            return $expires;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The takeover, conditioned on the exact value that was read: 1 affected row means this process won the lapsed slot, 0 means another one did. Prepared, and the interpolation is the table name.
        $taken = $wpdb->query($wpdb->prepare("UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", $held, $name, $there));
        // A lost takeover leaves the slot held to a fresh lease, not to the stamp just read, so
        // that stamp is not offered as a wait: 0 keeps it out of the Retry-After.
        return $taken === 1 ? null : 0;
    }
}
