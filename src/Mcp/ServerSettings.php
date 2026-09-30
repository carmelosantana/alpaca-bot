<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Plugin;
use AlpacaBot\Settings\Origin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Toolkit\AddressRefused;

/**
 * The two things a write of `toolkits.mcp_servers` needs beyond Schema::sanitizeMcpServers(),
 * kept apart because they run at different points and answer to different writers.
 *
 * beforeSave() is the split, on `pre_update_option_alpaca_bot_settings`. It is a filter rather
 * than something in Store for the reason Plugin::register() gives for its catalog bust: every
 * update_option() of the option runs it, and only one writer goes through Store. Core applies it
 * in update_option() before the branch that turns a site's first save into add_option(), so that
 * save is covered too. add_option() called on its own (`wp option add`) runs no
 * `pre_update_option_*` filter, so a row written that way keeps whatever it was given; the read
 * side masks it all the same (Schema::maskedServers()).
 *
 * For each row, a header value of Schema::MASK keeps the value Secrets holds for that id, '' removes
 * it, and any other string replaces it; the row is rewritten to carry MASK when a value is kept and
 * '' when none is, and never the value. MASK keeps a value only for the server it was set for, at
 * the address it was set for:
 * - a server is new when the stored list has no row with its id, and a new server's MASK keeps
 *   nothing, so a row that does not bring a stored server's id cannot pick up that server's value
 *   (Schema::sanitize() never gives a row without an id one the stored list holds);
 * - a stored server whose URL now has another scheme, host or port (Settings\Origin::moved())
 *   keeps nothing either. The `settings.write` row can be lowered below `manage_options`, and a
 *   user who may write the settings but not reveal them could otherwise post a stored id, a URL of their
 *   own and the mask, and have the stored token sent to their host. A path is not part of it, so
 *   an administrator moving an endpoint on the same host keeps the value; moving host means
 *   typing it again. clearedByMove() names the servers this is about to happen to, for a writer
 *   that can say so.
 *
 * Values for ids the list no longer has are dropped, and so are their `access.mcp` entries
 * (below). The secrets option is written only when what it holds changes.
 *
 * refusals() is the address check, and it is deliberately not in the filter: it resolves a host,
 * and a writer that has somewhere to put an error should be told rather than silently lose a row.
 * The REST route (Rest\SettingsController::update()) and `wp alpaca-bot settings`
 * (Cli\ChatCommand::settings()) run it through check(), with the check for rows the schema would
 * drop, and refuse the write whole: a 400, or an error and a non-zero exit, and nothing written.
 * The settings page's sanitize callback (Admin\SettingsPage) keeps the stored row for a refused
 * edit, drops a refused new row, and says which address and why. Anything else that writes the
 * option gets no check at all. Mcp\Egress checks the address again whenever it builds a client,
 * and pins the connection to what it checked: the ClientFactory the plugin constructs (for
 * Mcp\Discovery and Mcp\Toolkits) builds every MCP client through it, so a row that skipped this
 * check still meets that one before anything is sent. The check is AddressCheck::resolve(), which does not let the site's own host
 * through (AddressCheck says why).
 *
 * @since 0.6.0
 */
final class ServerSettings
{
    /** @var \Closure(string, string): list<string> */
    private \Closure $resolve;

    /**
     * URLs that passed the check since the option was last written or check() refused a write. Core's
     * add_option() runs the settings page's sanitize callback a second time on a site's first
     * save, with nothing stored yet, so every row reads as new there; this is what keeps that
     * second pass from looking each host up again, and from refusing a row whose value
     * beforeSave() has already kept.
     *
     * forgetPassed() empties it: on `update_option_alpaca_bot_settings` and
     * `add_option_alpaca_bot_settings`, which core fires once the row is written (after that second
     * pass), and when check() refuses a write for an address, which writes nothing. So a URL
     * is not taken as passed by a later save that the same process makes, which may run after the
     * site's opt-in or the DNS answer has changed. A save that ends up changing nothing fires
     * neither action (update_option() returns before them), and what it looked up stays until the
     * next write.
     *
     * @var array<string, true>
     */
    private array $passed = [];

    /** @param null|\Closure(string, string): list<string> $resolve the address check, host and URL in, the checked addresses out, AddressRefused when refused; AddressCheck::resolve() by default, as Egress has it */
    public function __construct(?\Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static fn(string $host, string $url): array => AddressCheck::resolve($host, $url);
    }

    public function register(): void
    {
        add_filter('pre_update_option_' . Plugin::OPTION, [$this, 'beforeSave'], 10, 2);
        add_action('update_option_' . Plugin::OPTION, [$this, 'forgetPassed'], 10, 0);
        add_action('add_option_' . Plugin::OPTION, [$this, 'forgetPassed'], 10, 0);
    }

    /** Empties the record of URLs that passed (`$passed` says when, and why). */
    public function forgetPassed(): void
    {
        $this->passed = [];
    }

    /**
     * The split (the class docblock). `$value` is what is about to be written and `$old` what is
     * stored, both as core hands them to the filter: either may be something other than the
     * settings array, and a `$value` that is not an array is handed back as it came.
     *
     * An `access.mcp` entry is dropped when its id is not a listed server, so a server added
     * later under the same id starts with no entry, administrators only. One more case goes the
     * same way: an entry for a new server that is identical to an entry `$old` holds under that
     * id. `$old` can hold an entry for an id its own list does not have only when the option was
     * written without this filter (add_option() on its own, a hand edit, a write while the plugin
     * was inactive); such an entry was never chosen for the new server, and is not handed to it.
     * An entry the write chose, one that differs, is kept.
     */
    public function beforeSave(#[\SensitiveParameter] mixed $value, mixed $old): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $old = is_array($old) ? $old : [];
        $oldRows = $old['toolkits.mcp_servers'] ?? null;
        $oldIds = Schema::serverIds($oldRows);
        $moved = $this->clearedByMove(is_array($value['toolkits.mcp_servers'] ?? null) ? $value['toolkits.mcp_servers'] : [], $oldRows);
        $held = Secrets::all();
        $keep = [];
        $ids = [];
        $rows = is_array($value['toolkits.mcp_servers'] ?? null) ? $value['toolkits.mcp_servers'] : [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = is_string($row['id'] ?? null) ? $row['id'] : null;
            $posted = $row['header_value'] ?? '';
            $secret = match (true) {
                $id === null, $posted === '' => '',
                $posted === Schema::MASK || !is_string($posted) => in_array($id, $oldIds, true) && !in_array($id, $moved, true) ? ($held[$id] ?? '') : '',
                default => $posted,
            };
            if ($id !== null) {
                $ids[] = $id;
                if ($secret !== '') {
                    $keep[$id] = $secret;
                }
            }
            $rows[$i]['header_value'] = $secret === '' ? '' : Schema::MASK;
        }
        if (array_key_exists('toolkits.mcp_servers', $value)) {
            $value['toolkits.mcp_servers'] = $rows;
        }
        if (!self::same($keep, $held)) {
            Secrets::put($keep);
        }
        if (is_array($value['access.mcp'] ?? null)) {
            $was = is_array($old['access.mcp'] ?? null) ? $old['access.mcp'] : [];
            $value['access.mcp'] = array_filter(
                $value['access.mcp'],
                static fn(mixed $capability, int|string $id): bool => in_array($id, $ids, true)
                    && (in_array($id, $oldIds, true) || !array_key_exists($id, $was) || $was[$id] !== $capability),
                ARRAY_FILTER_USE_BOTH,
            );
        }
        return $value;
    }

    /**
     * The check a writer that stores nothing on a refusal runs before it writes
     * `toolkits.mcp_servers`: the REST route (Rest\SettingsController::update()) and
     * `wp alpaca-bot settings` (Cli\ChatCommand::settings()). `$posted` is the list as the writer
     * was handed it and `$stored` the stored `toolkits.mcp_servers`. Two refusals, in this order:
     * - `alpaca_bot_mcp_row`: a row the schema would drop (Schema::droppedMcpRows()) that names a
     *   stored server's id, or that is new and has a URL (droppedRows()). The message names each
     *   such row, by its URL without any user name or password, or as `row N` when it has none,
     *   and says why; `data` carries `rows`.
     * - `alpaca_bot_mcp_address`: a row whose URL is new or changed fails the address check
     *   (refusals()), each row checked under the id Schema::sanitizeMcpServers() gives it. The
     *   message names each refused URL and why. What passed on the way is forgotten
     *   (forgetPassed()), since no write follows to fire the action that would.
     * Otherwise it passes, carrying clearedByMove()'s ids for the rows the write will store.
     */
    public function check(#[\SensitiveParameter] mixed $posted, mixed $stored): WriteCheck
    {
        $dropped = self::droppedRows($posted, $stored);
        if ($dropped !== []) {
            $lines = [];
            foreach ($dropped as $row) {
                /* translators: %s: the row's index in the posted list */
                $name = $row['url'] === '' ? sprintf(__('row %s', 'alpaca-bot'), $row['index']) : $row['url'];
                /* translators: 1: an MCP server's URL, or which row it is, 2: why the row cannot be kept */
                $lines[] = sprintf(__('%1$s: %2$s', 'alpaca-bot'), $name, $row['reason']);
            }
            return WriteCheck::refused('alpaca_bot_mcp_row', __('Nothing was saved: an MCP server row could not be kept.', 'alpaca-bot') . ' ' . implode(' ', $lines), ['rows' => $dropped]);
        }
        $rows = Schema::sanitizeMcpServers($posted, $stored);
        $refused = $this->refusals($rows, $stored);
        if ($refused !== []) {
            $this->forgetPassed();
            $reasons = [];
            foreach ($refused as $i => $reason) {
                /* translators: 1: an MCP server's URL, 2: why its address was refused */
                $reasons[] = sprintf(__('%1$s: %2$s', 'alpaca-bot'), $rows[$i]['url'], $reason);
            }
            return WriteCheck::refused('alpaca_bot_mcp_address', __('Nothing was saved: an MCP server\'s address did not pass the check.', 'alpaca-bot') . ' ' . implode(' ', $reasons));
        }
        return WriteCheck::passed($this->clearedByMove($rows, $stored));
    }

    /**
     * The rows of a posted `toolkits.mcp_servers` that the schema would drop and that a write must
     * not lose quietly: one that names a stored server's id, and a new one with a URL. A row whose
     * `remove` is ticked, and a blank row, are not among them (Schema::droppedMcpRows()). Each is
     * `{index, id, url, reason}`: its key in the posted list, the id it posted when that is a
     * string, the URL it posted with any user name and password taken out
     * (Schema::withoutUserinfo()), so a refused credential is not answered back, and why. The
     * header value is not read.
     *
     * @return list<array{index: array-key, id: string|null, url: string, reason: string}>
     */
    private static function droppedRows(#[\SensitiveParameter] mixed $raw, mixed $stored): array
    {
        $storedIds = Schema::serverIds($stored);
        $out = [];
        foreach (Schema::droppedMcpRows($raw) as $key => $fault) {
            /** @var array<array-key, mixed> $row droppedMcpRows() lists arrays only */
            $row = is_array($raw) ? $raw[$key] : [];
            $id = is_string($row['id'] ?? null) ? $row['id'] : null;
            $url = is_string($row['url'] ?? null) ? Schema::withoutUserinfo($row['url']) : '';
            if (!in_array($id, $storedIds, true) && trim($url) === '') {
                continue;
            }
            $prefix = is_string($row['prefix'] ?? null) ? $row['prefix'] : '';
            $out[] = ['index' => $key, 'id' => $id, 'url' => $url, 'reason' => match ($fault) {
                'url' => __('the URL has to be https with a host.', 'alpaca-bot'),
                'userinfo' => __('the URL may not carry a user name or password; send a credential as the header value, which reads back masked.', 'alpaca-bot'),
                /* translators: %s: what a header name has to be (Schema::mcpHeaderNameRule()) */
                'header' => sprintf(__('the header name has to be %s.', 'alpaca-bot'), Schema::mcpHeaderNameRule()),
                /* translators: %s: what a prefix has to be (Schema::mcpPrefixRule()) */
                'prefix' => sprintf(__('the prefix has to be %s.', 'alpaca-bot'), Schema::mcpPrefixRule()),
                /* translators: %s: a tool-name prefix */
                'taken' => sprintf(__('the prefix %s is already used by an earlier row of this request.', 'alpaca-bot'), $prefix),
            }];
        }
        return $out;
    }

    /**
     * The ids of the rows in `$rows` whose posted header value is the mask (or not a string, which
     * reads the same), whose URL has another scheme, host or port than the stored row of that id,
     * and for which Secrets holds a value: the servers beforeSave() is about to take a value from.
     * A new id is not named, and nor is a stored server that holds no value, since neither has one
     * to lose.
     *
     * @param array<array-key, mixed> $rows
     * @return list<string>
     */
    public function clearedByMove(#[\SensitiveParameter] array $rows, mixed $stored): array
    {
        $urls = self::urls($stored);
        $held = Secrets::all();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !isset($urls[$row['id']], $held[$row['id']])) {
                continue;
            }
            $posted = $row['header_value'] ?? '';
            if (($posted === Schema::MASK || !is_string($posted)) && Origin::moved($urls[$row['id']], is_string($row['url'] ?? null) ? $row['url'] : '')) {
                $out[] = $row['id'];
            }
        }
        return $out;
    }

    /**
     * Row index => why its address is refused, for each row of `$rows` whose URL is new or
     * changed against the row of the same id in `$stored`. A URL the stored list already has
     * under that id is not looked up again, so saving another tab costs no lookup; whether that
     * address still passes is Egress's question when it builds a client. Nor is a URL that
     * passed since the option was last written or check() refused a write (`$passed`).
     *
     * `$rows` are rows Schema::sanitizeMcpServers() made, so each URL is https with a host; the
     * host is handed to the check as wp_parse_url() gives it, brackets and all for an IPv6
     * literal, which AddressCheck::resolve() passes to AddressPin::resolve() as it is, and which
     * that takes.
     *
     * @param list<array<string, mixed>> $rows   the rows about to be saved
     * @param mixed                      $stored the stored `toolkits.mcp_servers`
     * @return array<int, string>
     */
    public function refusals(#[\SensitiveParameter] array $rows, mixed $stored): array
    {
        $urls = self::urls($stored);
        $refused = [];
        foreach ($rows as $i => $row) {
            $url = is_string($row['url'] ?? null) ? $row['url'] : '';
            $id = $row['id'] ?? null;
            if ((is_string($id) && ($urls[$id] ?? null) === $url) || isset($this->passed[$url])) {
                continue;
            }
            try {
                ($this->resolve)((string) wp_parse_url($url, PHP_URL_HOST), $url);
                $this->passed[$url] = true;
            } catch (AddressRefused $e) {
                $refused[$i] = $e->getMessage();
            }
        }
        return $refused;
    }

    /**
     * id => URL of a stored `toolkits.mcp_servers` list.
     *
     * @return array<string, string>
     */
    private static function urls(mixed $stored): array
    {
        $urls = [];
        foreach (is_array($stored) ? $stored : [] as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['url'] ?? null)) {
                $urls[$row['id']] = $row['url'];
            }
        }
        return $urls;
    }

    /**
     * Whether two id => value maps hold the same pairs, in whatever order: a server moved up the
     * list changes nothing that is kept. Compared strictly, so two tokens PHP would read as equal
     * numbers are still two tokens.
     *
     * @param array<string, string> $a
     * @param array<string, string> $b
     */
    private static function same(array $a, array $b): bool
    {
        ksort($a, SORT_STRING);
        ksort($b, SORT_STRING);
        return $a === $b;
    }
}
