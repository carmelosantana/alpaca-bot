<?php

declare(strict_types=1);

namespace AlpacaBot\Cli;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\Result;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * Alpaca Bot from the command line: `wp alpaca-bot chat|models|usage|settings`.
 *
 * Registered by Plugin::register() only when WP-CLI is running. The class itself never touches
 * WP-CLI: stdout goes through `$write` and failures through `$fail`, both injectable, so it can
 * be driven in a plain PHP process. The defaults write to STDOUT and call `WP_CLI::error()`
 * (which prints `Error: ...` to STDERR and exits 1); nothing here reads the constant or the
 * class until one of those defaults actually runs.
 *
 * WP-CLI turns every public method (constructor aside) into a subcommand, so the four
 * subcommands are the only public methods.
 */
final class ChatCommand
{
    /** A reply with a broken UTF-8 sequence in it is still printed, with the sequence replaced, rather than as `false`. */
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var callable(string): void */
    private $write;

    /** @var callable(string): void */
    private $fail;

    /** Whether the last thing emitted left stdout mid-line (a streamed delta does). */
    private bool $midLine = false;

    /**
     * @param callable(string): void|null $write stdout
     * @param callable(string): void|null $fail  reports an error and, under WP-CLI, ends the command
     */
    public function __construct(
        private Pipeline $pipeline,
        private ModelCatalog $catalog,
        private UsageMeter $meter,
        private Store $store,
        ?callable $write = null,
        ?callable $fail = null,
    ) {
        $this->write = $write ?? static function (string $s): void {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to the CLI process's standard output stream, not to a file; WP_Filesystem has no equivalent and is not loaded under WP-CLI anyway.
            fwrite(STDOUT, $s);
        };
        $this->fail = $fail ?? static function (string $message): void {
            \WP_CLI::error($message);
        };
    }

    /**
     * Send a message and stream the reply.
     *
     * The turn runs as one WordPress user: the one named by WP-CLI's global `--user=<id|login|email>`
     * flag when given (it is a global flag, not an option of this command, and may sit anywhere
     * on the command line), else the administrator with the lowest id. That user is who the
     * monthly token cap is charged to, who owns the conversation (and the only one whose existing
     * conversation --conversation may continue), whose id authors the chat_history and chat_log
     * posts, and whose capabilities the context sources check. The receipt names them as
     * `as user <id>`, so a run that fell back to the administrator says so. There is no
     * capability check here: whoever runs WP-CLI already has the site.
     *
     * **This command sets WordPress's current user**, which under WP-CLI is process-global state
     * (`wp eval 'echo get_current_user_id();'` prints 0 without the flag and the id with it).
     * It has to: the pipeline is told the id, but the toolkits are not — Plugin::register()
     * hands SummarizeToolkit and DraftPostToolkit `get_current_user_id(...)` as a closure and
     * they ask it when a tool runs. Left at 0, `draft_post` refuses ("Nobody is logged in…")
     * and `summarize` bills its inner turn to user 0, so the acting user's monthly cap is
     * measured against the wrong month's receipts. Threading the id instead would mean
     * building a second Registry (and a second Pipeline to hold it, since the pipeline is
     * handed the registry at construction) for this one caller, and it would still leave
     * `--user` and the administrator fallback behaving differently everywhere else that asks
     * WordPress who is acting — a site's own filter, wp_insert_post()'s default author. One
     * call makes the two invocations the same thing.
     *
     * Only the reply text is streamed; a thinking model's reasoning is not shown.
     *
     * ## OPTIONS
     *
     * <message>
     * : The message to send.
     *
     * [--model=<id>]
     * : A model from `wp alpaca-bot models`. Honoured only while chat.user_can_change_model is on.
     *
     * [--conversation=<id>]
     * : Continue one of the user's conversations instead of starting a new one.
     *
     * [--format=<format>]
     * : Print the finished turn as JSON (conversation_id, reply, receipt) instead of streaming it.
     * WP-CLI 2.12+ rewrites `--json` into `--format=json` before the command runs, so both spellings work.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * [--json]
     * : Same as --format=json, for WP-CLI releases before 2.12.
     *
     * ## EXAMPLES
     *
     *     wp alpaca-bot chat "Reply with exactly: pipeline ok"
     *     wp alpaca-bot chat "Summarise my last post" --user=editor --model=llama3.2 --json
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function chat(array $args, array $assoc): void
    {
        $json = isset($assoc['json']) || ($assoc['format'] ?? 'text') === 'json';
        try {
            $userId = $this->userId($assoc);
            // Become them, for the toolkits and anything else that reads the current user; the
            // method docblock says why this and not a threaded id. Before send(), so a tool the
            // first iteration calls already sees the right user, and after userId() has refused
            // an id that does not exist.
            wp_set_current_user($userId);
            $model = trim((string) ($assoc['model'] ?? ''));
            if ($model !== '' && !(bool) $this->store->get('chat.user_can_change_model')) {
                // Pipeline::model() owns this policy: it honours a requested model only while
                // chat.user_can_change_model is on and otherwise quietly uses the default, so the
                // receipt would name a model the operator did not ask for. This is a pre-check of
                // the same rule so the flag never silently does nothing. If Pipeline::model()
                // ever grows an operator override, this check must change with it or it will
                // refuse what the pipeline would accept.
                throw new \InvalidArgumentException('--model is ignored while chat.user_can_change_model is off. Turn it on with: wp alpaca-bot settings chat.user_can_change_model 1');
            }
            $conversation = (string) ($assoc['conversation'] ?? '0');
            if (!ctype_digit($conversation)) {
                // (int) would read anything else as 0 and quietly start a new conversation.
                throw new \InvalidArgumentException('--conversation takes a conversation id (a number).');
            }
            $gen = $this->pipeline->send($userId, (string) ($args[0] ?? ''), [
                'model' => $model,
                'conversation_id' => (int) $conversation,
            ]);
            // The caller owns draining: every delta is consumed, then the Result is the return.
            foreach ($gen as $delta) {
                if (!$json) {
                    $this->emit($delta->text);
                }
            }
            $result = $gen->getReturn();
        } catch (\Exception $e) {
            // Everything send() documents throwing (the cap, an empty or refused message, a
            // refused model or conversation, a provider failure) is an Exception with a
            // message written for the requester; an Error is a bug and is left to surface.
            if ($this->midLine) {
                // Close the partial reply so the error does not land on it.
                $this->emit("\n");
            }
            $this->error($e->getMessage(), $json);
            return;
        }
        if ($json) {
            $this->json(['conversation_id' => $result->conversation->id, 'reply' => $result->reply->toArray(), 'receipt' => $result->receipt]);
            return;
        }
        $this->emit("\n\n" . self::receiptLine($result) . "\n");
    }

    /**
     * List the models the provider reports, asked fresh (the five-minute cache is bypassed).
     *
     * Each line is the model id, then which of `tools`, `vision` and `thinking` it is flagged
     * with. `tools` is the per-model routing decision rather than the catalogue alone: an
     * operator's `models.overrides[<model>][tools]` outranks it at that decision
     * (Chat\Pipeline::toolkitsFor()), so it is laid over the flag on the way out here too. It
     * does not promise what a turn does: toolkitsFor() returns [] before it reads the override
     * when the user whose turn it is has no toolkit enabled, and enablement is resolved per user
     * (Toolkit\Registry::enabled()), which this listing takes no user for. The overlay never
     * reaches the catalog's Model objects or its transient.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function models(array $args, array $assoc): void
    {
        try {
            $models = $this->catalog->all(true);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return;
        }
        if ($models === []) {
            $this->error(sprintf('No models were listed by %s. Is the provider running, and is provider.base_url right?', (string) $this->store->get('provider.base_url')));
            return;
        }
        foreach ($models as $m) {
            $tools = $this->store->toolsOverride($m->id) ?? $m->tools;
            $flags = array_keys(array_filter(['tools' => $tools, 'vision' => $m->vision, 'thinking' => $m->thinking]));
            $this->emit(sprintf("%-40s %s\n", $m->id, implode(' ', $flags)));
        }
    }

    /**
     * This month's token usage: one user's under WP-CLI's global `--user=<id|login|email>` flag
     * (the same user resolution `chat` uses, minus the administrator fallback), else site-wide.
     *
     * ## EXAMPLES
     *
     *     wp alpaca-bot usage
     *     wp alpaca-bot usage --user=editor
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function usage(array $args, array $assoc): void
    {
        try {
            $userId = $this->requestedUser($assoc);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return;
        }
        $summary = $this->meter->monthSummary($userId);
        $this->emit(sprintf(
            "%s: %d tokens over %d requests (%s)\n",
            $summary['month'],
            $summary['tokens'],
            $summary['requests'],
            $userId !== null ? "user {$userId}" : 'site-wide',
        ));
    }

    /**
     * Read or write a setting.
     *
     * With no key, prints every setting as JSON, with provider.api_key shown as `***` when it is
     * set (ask for it by key to see it). With a key, prints that setting's value as JSON; with a
     * key and a value, stores the value first (through the same schema the settings screen uses,
     * so what is echoed is what was kept). An array setting such as models.overrides takes its
     * value as a JSON object.
     *
     * ## OPTIONS
     *
     * [<key>]
     * : A dotted key, e.g. provider.base_url.
     *
     * [<value>]
     * : The value to store.
     *
     * ## EXAMPLES
     *
     *     wp alpaca-bot settings provider.base_url http://host.docker.internal:11434/v1
     *     wp alpaca-bot settings models.default
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function settings(array $args, array $assoc): void
    {
        if (!isset($args[0])) {
            $all = $this->store->all();
            // Schema::SECRETS is the one list of credential-holding keys, and Schema::MASK the
            // one stand-in for a stored one: the settings screen (Admin\Fields::display()), the
            // REST read (Rest\SettingsController::masked()) and this dump all read them, so a
            // second secret added to the schema is hidden here too. The whole dump is what ends
            // up in CI logs and shell history; asking for one by name
            // (`wp alpaca-bot settings provider.api_key`) still prints it, since that is an
            // operator deliberately asking.
            foreach (Schema::SECRETS as $secret) {
                // An unset key stays visibly empty: "is one configured?" is still answerable.
                if (($all[$secret] ?? '') !== '') {
                    $all[$secret] = Schema::MASK;
                }
            }
            $this->json($all);
            return;
        }
        $key = $args[0];
        $field = Schema::fields()[$key] ?? null;
        if ($field === null) {
            $this->error(sprintf('Unknown setting "%s". Run `wp alpaca-bot settings` to list them.', $key));
            return;
        }
        if (isset($args[1])) {
            $value = $args[1];
            if ($field['type'] === 'array') {
                $value = json_decode($value, true);
                if (!is_array($value)) {
                    $this->error(sprintf('%s takes a JSON object.', $key));
                    return;
                }
            }
            $this->store->set($key, $value);
        }
        $this->emit(wp_json_encode($this->store->get($key), self::JSON) . "\n");
    }

    /**
     * The user the caller asked for, or null when nobody was named. `$assoc['user']` is honoured
     * for a caller that hands it in directly (WP-CLI never does: its global `--user` flag is
     * consumed before the command runs, and shows up here as the current user instead). Both
     * `chat` and `usage` resolve through here, so a bad id is refused the same way in each.
     *
     * @param array<string, mixed> $assoc
     * @throws \InvalidArgumentException when the id is not a positive number, or names no user
     */
    private function requestedUser(array $assoc): ?int
    {
        if (isset($assoc['user'])) {
            $raw = (string) $assoc['user'];
            if (!ctype_digit($raw) || (int) $raw < 1) {
                // (int) would read anything else as 0: chat would quietly fall back to the
                // administrator, and usage would quietly report site-wide totals.
                throw new \InvalidArgumentException('--user takes a user id (a number).');
            }
            $id = (int) $raw;
        } else {
            $id = (int) get_current_user_id();
            if ($id < 1) {
                return null;
            }
        }
        return self::existing($id);
    }

    /**
     * The user the turn runs as: the requested one, else the administrator with the lowest id.
     *
     * @param array<string, mixed> $assoc
     * @throws \InvalidArgumentException when the requested user is refused, or nobody could be chosen
     */
    private function userId(array $assoc): int
    {
        $id = $this->requestedUser($assoc);
        if ($id !== null) {
            return $id;
        }
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC']);
        $id = (int) ($admins[0] ?? 0);
        if ($id < 1) {
            throw new \InvalidArgumentException('No user to run as: pass --user=<id> (no administrator was found).');
        }
        return self::existing($id);
    }

    /** @throws \InvalidArgumentException when no such user exists (a typo'd id would otherwise author posts under nobody) */
    private static function existing(int $id): int
    {
        if (get_userdata($id) === false) {
            throw new \InvalidArgumentException(sprintf('User %d does not exist.', $id));
        }
        return $id;
    }

    /**
     * `[model · N tokens · N ms · conversation N · as user N]`, with `not saved` in place of the
     * conversation when privacy.save_history left it unsaved. The user is always named: it is
     * who was charged and who owns what was written, and nothing else in the output says so.
     */
    private static function receiptLine(Result $r): string
    {
        return sprintf(
            '[%s · %d tokens · %d ms · %s · as user %d]',
            $r->receipt['model'],
            $r->receipt['total_tokens'],
            $r->receipt['duration_ms'],
            $r->conversation->id > 0 ? "conversation {$r->conversation->id}" : 'not saved',
            $r->receipt['user_id'],
        );
    }

    /**
     * Reports a failure. With `--json` the error also goes to stdout as `{"error": ...}`, so a
     * consumer parsing stdout sees it there; the report itself (stderr and exit 1 under WP-CLI)
     * happens either way.
     */
    private function error(string $message, bool $json = false): void
    {
        if ($json) {
            $this->json(['error' => $message]);
        }
        ($this->fail)($message);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): void
    {
        $this->emit(wp_json_encode($data, self::JSON | JSON_PRETTY_PRINT) . "\n");
    }

    private function emit(string $s): void
    {
        if ($s === '') {
            return;
        }
        ($this->write)($s);
        $this->midLine = !str_ends_with($s, "\n");
    }
}
