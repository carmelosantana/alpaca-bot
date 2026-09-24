<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Toolkit\AddressPin;
use AlpacaBot\Toolkit\AddressRefused;

/**
 * The two things a write of `toolkits.mcp_servers` needs beyond Schema::sanitizeMcpServers(),
 * kept apart because they run at different points and answer to different writers.
 *
 * beforeSave() is the split, on `pre_update_option_alpaca_bot_settings`. It is a filter rather
 * than something in Store for the reason Plugin::register() gives for its catalog bust: every
 * writer goes through the option, and only one of them through Store. Core applies it in
 * update_option() before the branch that turns a site's first save into add_option(), so that
 * save is covered too. For each row, a header value of Schema::MASK keeps the value Secrets holds
 * for that id, '' removes it, and any other string replaces it; the row is rewritten to carry MASK
 * when a value is kept and '' when none is, and never the value. A server is new when the stored
 * list has no row with its id, and a new server's MASK keeps nothing, so a row that does not
 * bring a stored server's id cannot pick up that server's value (Schema::sanitize() never gives
 * a row without an id one the stored list holds). Values for ids the list no longer has are dropped, and
 * so are their `access.mcp` entries (below). The secrets option is written only when what it
 * holds changes.
 *
 * refusals() is the address check, and it is deliberately not in the filter: it resolves a host,
 * and a writer that has somewhere to put an error should be told rather than silently lose a row.
 * The REST route (Rest\SettingsController::update()) answers a 400 and writes nothing; the
 * settings page's sanitize callback (Admin\SettingsPage) keeps the stored row for a refused edit,
 * drops a refused new row, and says which address and why. `wp alpaca-bot settings` writes through
 * Store and gets no check at all, and nor does anything else that writes the option. The guard
 * that holds for every writer is Mcp\Egress, which checks the address again when it builds a
 * client and pins the connection to what it checked.
 *
 * @since 0.6.0
 */
final class ServerSettings
{
    /** @var \Closure(string, string): list<string> */
    private \Closure $resolve;

    /** @param null|\Closure(string, string): list<string> $resolve the address check, host and URL in, the checked addresses out, AddressRefused when refused; AddressPin::resolve() by default, as Egress has it */
    public function __construct(?\Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static fn(string $host, string $url): array => AddressPin::resolve($host, $url);
    }

    public function register(): void
    {
        add_filter('pre_update_option_' . Plugin::OPTION, [$this, 'beforeSave'], 10, 2);
    }

    /**
     * The split (the class docblock). `$value` is what is about to be written and `$old` what is
     * stored, both as core hands them to the filter: either may be something other than the
     * settings array, and a `$value` that is not an array is handed back as it came.
     *
     * An `access.mcp` entry is dropped when its id is not a listed server, so a server added
     * later under the same id starts with no entry, administrators only. One more case goes the
     * same way: an entry for a new server that is identical to the entry stored under that id.
     * That is an entry carried over from a server this same write removed (a save of the Tools
     * tab posts no `access.mcp`, so the stored map rides along with it), not one anyone chose for
     * the new server; an entry the write chose, one that differs from the stored one, is kept.
     */
    public function beforeSave(#[\SensitiveParameter] mixed $value, mixed $old): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $old = is_array($old) ? $old : [];
        $oldIds = Schema::serverIds($old['toolkits.mcp_servers'] ?? null);
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
                $posted === Schema::MASK || !is_string($posted) => in_array($id, $oldIds, true) ? ($held[$id] ?? '') : '',
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
     * Row index => why its address is refused, for each row of `$rows` whose URL is new or
     * changed against the row of the same id in `$stored`. A URL the stored list already has
     * under that id is not looked up again, so saving another tab costs no lookup; whether that
     * address still passes is Egress's question when it builds a client.
     *
     * `$rows` are rows Schema::sanitizeMcpServers() made, so each URL is https with a host; the
     * host is handed to the check as wp_parse_url() gives it, brackets and all for an IPv6
     * literal, which AddressPin::resolve() takes.
     *
     * @param list<array<string, mixed>> $rows   the rows about to be saved
     * @param mixed                      $stored the stored `toolkits.mcp_servers`
     * @return array<int, string>
     */
    public function refusals(array $rows, mixed $stored): array
    {
        $urls = [];
        foreach (is_array($stored) ? $stored : [] as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['url'] ?? null)) {
                $urls[$row['id']] = $row['url'];
            }
        }
        $refused = [];
        foreach ($rows as $i => $row) {
            $url = is_string($row['url'] ?? null) ? $row['url'] : '';
            $id = $row['id'] ?? null;
            if (is_string($id) && ($urls[$id] ?? null) === $url) {
                continue;
            }
            try {
                ($this->resolve)((string) wp_parse_url($url, PHP_URL_HOST), $url);
            } catch (AddressRefused $e) {
                $refused[$i] = $e->getMessage();
            }
        }
        return $refused;
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
