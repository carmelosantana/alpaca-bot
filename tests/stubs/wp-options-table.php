<?php

declare(strict_types=1);

/**
 * The part of `$wpdb` that Rest\StreamBudget uses: the options table, with the UNIQUE index on
 * `option_name` that makes a slot claim atomic.
 *
 * Brain Monkey stubs functions, not classes, and `$wpdb` is a global object, so the double is
 * installed as one (slotRowsIn() in tests/Pest.php). It answers the three statements the budget
 * writes and the one it reads, and **throws on anything else**: a statement the class changes
 * has to be taught here, so the double cannot quietly stop modelling the code it stands for.
 *
 * It also answers the one non-options read the unit suite puts on the same global,
 * `SELECT @@max_allowed_packet` (Chat\ConversationStore::storageBudget()), because one test can
 * drive both: pipelineWith() installs one `$wpdb` for the whole test. Every statement it is
 * handed is recorded in `->queries`.
 *
 * `$frozen` is what makes a race testable. Set it to a snapshot and every read answers from
 * that snapshot while the writes still land on the real rows — which is what concurrent PHP-FPM
 * workers see, and which is the condition the transient version of this cap failed under.
 */
final class OptionsTable
{
    public string $options = 'wp_options';

    /** @var list<string> every statement this double was handed */
    public array $queries = [];

    /** What `SELECT @@max_allowed_packet` answers; MySQL's documented default unless a test says otherwise. */
    public function __construct(public mixed $packet = '16777216') {}

    /** @var array<string, string> option_name => option_value */
    public array $rows = [];

    /** @var array<string, string>|null while set, every read answers from here */
    public ?array $frozen = null;

    /** wpdb::prepare() as far as this double needs it: %s is a quoted, escaped string. */
    public function prepare(string $sql, mixed ...$args): string
    {
        return vsprintf(str_replace('%s', "'%s'", $sql), array_map(static fn(mixed $a): string => addslashes((string) $a), $args));
    }

    /** Affected rows, which is what every claim in StreamBudget is decided by. */
    public function query(string $sql): int
    {
        $this->queries[] = $sql;
        if (preg_match("/^INSERT IGNORE INTO `[^`]+` \(`option_name`, `option_value`, `autoload`\) VALUES \('([^']*)', '([^']*)', 'off'\)$/", $sql, $m) === 1) {
            // The UNIQUE index: a name already there is 0 affected rows, whatever anyone read.
            if (array_key_exists($m[1], $this->rows)) {
                return 0;
            }
            $this->rows[$m[1]] = $m[2];
            return 1;
        }
        if (preg_match("/^UPDATE `[^`]+` SET `option_value` = '([^']*)' WHERE `option_name` = '([^']*)' AND `option_value` = '([^']*)'$/", $sql, $m) === 1) {
            if (($this->rows[$m[2]] ?? null) !== $m[3]) {
                return 0;
            }
            $this->rows[$m[2]] = $m[1];
            return 1;
        }
        if (preg_match("/^DELETE FROM `[^`]+` WHERE `option_name` = '([^']*)' AND `option_value` = '([^']*)'$/", $sql, $m) === 1) {
            if (($this->rows[$m[1]] ?? null) !== $m[2]) {
                return 0;
            }
            unset($this->rows[$m[1]]);
            return 1;
        }
        throw new RuntimeException('OptionsTable was handed a statement it does not model: ' . $sql);
    }

    public function get_var(string $sql): mixed
    {
        $this->queries[] = $sql;
        if ($sql === 'SELECT @@max_allowed_packet') {
            return $this->packet;
        }
        if (preg_match("/^SELECT `option_value` FROM `[^`]+` WHERE `option_name` = '([^']*)'$/", $sql, $m) === 1) {
            return ($this->frozen ?? $this->rows)[$m[1]] ?? null;
        }
        throw new RuntimeException('OptionsTable was handed a statement it does not model: ' . $sql);
    }
}
