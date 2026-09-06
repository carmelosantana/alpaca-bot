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
            fwrite(STDOUT, $s);
        };
        $this->fail = $fail ?? static function (string $message): void {
            \WP_CLI::error($message);
        };
    }

    /**
     * Send a message and stream the reply.
     *
     * The turn runs as a WordPress user: the one named by WP-CLI's global `--user=<id|login|email>`
     * flag when given (it may sit anywhere on the command line), else the first administrator.
     * That user is who the monthly cap is charged to, who owns the conversation, and whose
     * capabilities the context sources check. There is no capability check here: whoever runs
     * WP-CLI already has the site.
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
     * [--json]
     * : Print the finished turn as JSON (conversation_id, reply, receipt) instead of streaming it.
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
        $json = isset($assoc['json']);
        try {
            $userId = $this->userId($assoc);
            $model = trim((string) ($assoc['model'] ?? ''));
            if ($model !== '' && !(bool) $this->store->get('chat.user_can_change_model')) {
                // The pipeline would quietly use the default and the receipt would name it;
                // better to say why the flag did nothing.
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
     * Each line is the model id, then which of `tools`, `vision` and `thinking` it is flagged with.
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
            $flags = array_keys(array_filter(['tools' => $m->tools, 'vision' => $m->vision, 'thinking' => $m->thinking]));
            $this->emit(sprintf("%-40s %s\n", $m->id, implode(' ', $flags)));
        }
    }

    /**
     * This month's token usage: site-wide, or one user's under WP-CLI's global `--user` flag.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function usage(array $args, array $assoc): void
    {
        $userId = isset($assoc['user']) ? (int) $assoc['user'] : (int) get_current_user_id();
        $summary = $this->meter->monthSummary($userId > 0 ? $userId : null);
        $this->emit(sprintf(
            "%s: %d tokens over %d requests (%s)\n",
            $summary['month'],
            $summary['tokens'],
            $summary['requests'],
            $userId > 0 ? "user {$userId}" : 'site-wide',
        ));
    }

    /**
     * Read or write a setting.
     *
     * With no key, prints every setting as JSON. With a key, prints that setting's value as JSON;
     * with a key and a value, stores the value first (through the same schema the settings screen
     * uses, so what is echoed is what was kept). An array setting such as models.overrides takes
     * its value as a JSON object.
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
            $this->json($this->store->all());
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
        $this->emit(json_encode($this->store->get($key), self::JSON) . "\n");
    }

    /**
     * The user the turn runs as. `$assoc['user']` is honoured for a caller that hands it in
     * directly (WP-CLI never does: its global `--user` flag is consumed before the command runs,
     * and shows up here as the current user instead).
     *
     * @param array<string, mixed> $assoc
     * @throws \InvalidArgumentException when the user does not exist, or nobody could be chosen
     */
    private function userId(array $assoc): int
    {
        $id = isset($assoc['user']) ? (int) $assoc['user'] : (int) get_current_user_id();
        if ($id < 1) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC']);
            $id = (int) ($admins[0] ?? 0);
            if ($id < 1) {
                throw new \InvalidArgumentException('No user to run as: pass --user=<id> (no administrator was found).');
            }
        }
        if (get_userdata($id) === false) {
            throw new \InvalidArgumentException(sprintf('User %d does not exist.', $id));
        }
        return $id;
    }

    /** `[model · N tokens · N ms · conversation N]`, or `not saved` when privacy.save_history left it unsaved. */
    private static function receiptLine(Result $r): string
    {
        return sprintf(
            '[%s · %d tokens · %d ms · %s]',
            $r->receipt['model'],
            $r->receipt['total_tokens'],
            $r->receipt['duration_ms'],
            $r->conversation->id > 0 ? "conversation {$r->conversation->id}" : 'not saved',
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
        $this->emit(json_encode($data, self::JSON | JSON_PRETTY_PRINT) . "\n");
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
