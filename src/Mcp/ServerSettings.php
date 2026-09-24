<?php

declare(strict_types=1);

namespace AlpacaBot\Mcp;

use AlpacaBot\Plugin;
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
 * - a stored server whose URL now has another scheme, host or port (moved()) keeps nothing
 *   either. The `settings.write` row can be lowered below `manage_options`, and a user who
 *   may write the settings but not reveal them could otherwise post a stored id, a URL of their
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
 * The REST route (Rest\SettingsController::update()) answers a 400 and writes nothing; the
 * settings page's sanitize callback (Admin\SettingsPage) keeps the stored row for a refused edit,
 * drops a refused new row, and says which address and why. `wp alpaca-bot settings` writes through
 * Store and gets no check at all, and nor does anything else that writes the option. Mcp\Egress
 * checks the address again whenever it builds a client, and pins the connection to what it
 * checked; in this release nothing builds one: no code calls Egress::client(), and ClientFactory,
 * whose default client is UnavailableClient, is not constructed either. The check is AddressCheck::resolve(), which does not let the
 * site's own host through (AddressCheck says why).
 *
 * @since 0.6.0
 */
final class ServerSettings
{
    /** @var \Closure(string, string): list<string> */
    private \Closure $resolve;

    /**
     * URLs that passed the check since the last write of the option. Core's add_option() runs the
     * settings page's sanitize callback a second time on a site's first save, with nothing stored
     * yet, so every row reads as new there; this is what keeps that second pass from looking each
     * host up again, and from refusing a row whose value beforeSave() has already kept.
     *
     * forgetPassed() empties it: on `update_option_alpaca_bot_settings` and
     * `add_option_alpaca_bot_settings`, which core fires once the row is written (after that second
     * pass), and when the REST route refuses a PUT for an address, which writes nothing. So a URL
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
            if (($posted === Schema::MASK || !is_string($posted)) && self::moved($urls[$row['id']], is_string($row['url'] ?? null) ? $row['url'] : '')) {
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
     * passed since the last write of the option (`$passed`).
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
     * Whether `$to` is another origin than `$from`: scheme, host or port. Scheme and host are
     * compared lowercase and the host without the dots a name may end in, as Egress reads it; a
     * port left out is the scheme's own (443 for https, 80 for http), so `:443` written out is
     * the same origin. A URL that does not parse has no origin, and is another one.
     */
    private static function moved(string $from, string $to): bool
    {
        return self::origin($from) === null || self::origin($from) !== self::origin($to);
    }

    /** `scheme://host:port` of `$url`, normalised as moved() says, or null when it has no scheme or host. */
    private static function origin(string $url): ?string
    {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) wp_parse_url($url, PHP_URL_HOST), '.'));
        if ($scheme === '' || $host === '') {
            return null;
        }
        $port = wp_parse_url($url, PHP_URL_PORT);
        return $scheme . '://' . $host . ':' . (is_int($port) ? $port : ($scheme === 'https' ? 443 : 80));
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
