<?php

declare(strict_types=1);

/**
 * uninstall.php names everything it removes literally (it cannot load a class), so nothing ties
 * its list to the code that writes the rows. This is the tie, read from src/ with PHP's own
 * tokenizer (so comments and docblocks are not code):
 *
 * - Every call to a function that stores something under a name (UNINSTALL_WRITERS: options,
 *   transients, every kind of meta, cron events, post types, taxonomies, roles, posts, terms,
 *   comments, users) is found, and its name argument read. A string literal, or a class constant
 *   that resolves to one, must be quoted in uninstall.php. Anything else -- a variable, a
 *   concatenation such as `Plugin::OPTION . '_x'` or `'alpaca_bot_' . $x` -- fails, unless
 *   UNINSTALL_WRITE_MAP names that exact call site and expression and says which uninstall.php
 *   entry covers it (or why it is not the plugin's to remove). So is a literal the map sends
 *   elsewhere: `ab_messages` is post meta that goes with its posts, not by name.
 * - `$wpdb` writes (query, insert, replace, update, delete) are counted per file against
 *   UNINSTALL_WPDB_WRITES, with the uninstall.php entry that covers each file's rows.
 * - Tables, uploads and files: a `CREATE TABLE`/`ALTER TABLE` string, dbDelta(), and every
 *   filesystem or upload writer in UNINSTALL_FILE_WRITERS must not occur, apart from the one
 *   entry that writes to the CLI's standard output. The plugin has none of these today; a new
 *   one fails here, because uninstall.php removes no table and no file.
 *
 * What this does not see: a name built into a variable elsewhere and handed to a call site the
 * map already covers (RateLimit's `$key`, UsageMeter's `$key`) is trusted to keep the shape the
 * map says. A new shape under an existing prefix (a drift marker keyed some new way, through
 * Drift::set()) is caught only by tests/Integration/UninstallTest.php, and only if that test
 * writes it. A write through a WordPress function missing from UNINSTALL_WRITERS is not seen.
 */

const UNINSTALL_WRITERS = [
    // function => index of the argument that names what is stored
    'update_option' => 0, 'add_option' => 0, 'set_transient' => 0,
    'update_site_option' => 0, 'add_site_option' => 0, 'set_site_transient' => 0,
    'update_network_option' => 1, 'add_network_option' => 1,
    'update_user_meta' => 1, 'add_user_meta' => 1, 'update_user_option' => 1,
    'update_post_meta' => 1, 'add_post_meta' => 1,
    'update_term_meta' => 1, 'add_term_meta' => 1, 'update_comment_meta' => 1, 'add_comment_meta' => 1,
    'update_metadata' => 2, 'add_metadata' => 2,
    'wp_schedule_event' => 2, 'wp_schedule_single_event' => 1,
    'register_post_type' => 0, 'register_taxonomy' => 0, 'add_role' => 0, 'add_cap' => 0,
    'wp_insert_post' => 0, 'wp_insert_term' => 1, 'wp_set_object_terms' => 2, 'wp_insert_comment' => 0,
    'wp_insert_user' => 0, 'set_theme_mod' => 0,
];

const UNINSTALL_FILE_WRITERS = [
    'dbdelta', 'wp_upload_dir', 'file_put_contents', 'fwrite', 'fputs', 'fopen', 'tempnam', 'copy', 'rename', 'mkdir',
    'wp_mkdir_p', 'move_uploaded_file', 'wp_handle_upload', 'wp_handle_sideload', 'media_handle_upload',
    'media_handle_sideload', 'wp_insert_attachment', 'wp_filesystem',
];

/**
 * `<file>|<function>|<name>` => the uninstall.php entry that removes it (quoted there), or
 * `left:` and why it is not the plugin's to remove. `<name>` is the resolved literal, or the
 * name argument's source with whitespace collapsed; for wp_insert_post() it is the `post_type`.
 */
const UNINSTALL_WRITE_MAP = [
    'Chat/ConversationStore.php|update_post_meta|ab_messages' => 'chat_history',
    'Settings/Migrate04.php|update_post_meta|ab_migration_attempts' => 'chat_history',
    'Chat/UsageMeter.php|wp_insert_post|wp_slash($post)' => 'chat_log',
    'Chat/UsageMeter.php|set_transient|$key' => 'alpaca_bot_usage_',
    'RateLimit.php|set_transient|$key' => 'alpaca_bot_rl_',
    'Shortcodes/Chat.php|set_transient|$key' => 'alpaca_bot_shortcode_',
    'Mcp/Drift.php|set_transient|self::PREFIX . $id' => 'alpaca_bot_mcp_drift_',
    'Rest/ChatController.php|set_transient|self::STREAM_TRANSIENT . $token' => 'alpaca_bot_stream_',
    'Toolkit/DraftPostToolkit.php|wp_insert_post|$type' => 'left: a draft of the site\'s own post type, owned by the user who asked for it',
];

/** `<file>` => [how many `$wpdb` writes it makes, the uninstall.php entry that removes their rows]. */
const UNINSTALL_WPDB_WRITES = [
    'Rest/StreamBudget.php' => [3, 'alpaca_bot_stream_slot_'],
];

/** `<file>|<function>|<first argument>` for a file writer that is allowed, and why. */
const UNINSTALL_FILE_WRITES_ALLOWED = [
    'Cli/ChatCommand.php|fwrite|STDOUT' => 'the CLI command\'s own output, not a file',
];

function uninstallSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
}

/** @return array<string, string> every src/ PHP file's source, by path under src/ */
function uninstallSrcFiles(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($files);
    return $files;
}

/**
 * A `self::X`, `static::X` or `Alias::X` class constant as the string it holds, or null.
 */
function uninstallResolve(string $expr, string $source): ?string
{
    if (preg_match("/^'([^'\\\\]*)'$/", $expr, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/^(self|static|[A-Z][A-Za-z0-9_]*)::([A-Z][A-Z0-9_]*)$/', $expr, $m) !== 1) {
        return null;
    }
    preg_match('/^namespace\s+([^;]+);/m', $source, $ns);
    if ($m[1] === 'self' || $m[1] === 'static') {
        preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|enum)\s+([A-Za-z0-9_]+)/m', $source, $cls);
        $class = $ns[1] . '\\' . $cls[1];
    } elseif (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\' . $m[1] . ');/m', $source, $use) === 1) {
        $class = $use[1];
    } else {
        $class = $ns[1] . '\\' . $m[1];
    }
    // Reflection rather than constant(), which cannot read a private constant from here.
    $value = class_exists($class) && (new ReflectionClass($class))->hasConstant($m[2]) ? (new ReflectionClassConstant($class, $m[2]))->getValue() : null;
    return is_string($value) ? $value : null;
}

/**
 * Every call to one of `$functions` in `$source`: its lower-case name, line, whether it was a
 * method call (`->`) and on which variable, and its top-level arguments with whitespace collapsed.
 *
 * @param list<string> $functions lower-case names
 * @return list<array{fn: string, line: int, method: bool, on: string, args: list<string>}>
 */
function uninstallCalls(string $source, array $functions): array
{
    $t = array_values(array_filter(token_get_all($source), static fn($tok): bool => !is_array($tok) || !in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)));
    $calls = [];
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || !in_array($t[$i][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }
        $fn = strtolower(ltrim($t[$i][1], '\\'));
        if (!in_array($fn, $functions, true)) {
            continue;
        }
        $j = $i + 1;
        while (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) {
            $j++;
        }
        $k = $i - 1;
        while (is_array($t[$k]) && $t[$k][0] === T_WHITESPACE) {
            $k--;
        }
        if ($t[$j] !== '(' || (is_array($t[$k]) && in_array($t[$k][0], [T_FUNCTION, T_DOUBLE_COLON, T_NEW], true))) {
            continue;
        }
        $depth = 0;
        $args = [''];
        for ($m = $j; $m < $n; $m++) {
            $text = is_array($t[$m]) ? $t[$m][1] : $t[$m];
            if (in_array($text, ['(', '[', '{'], true) || (is_array($t[$m]) && in_array($t[$m][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = '';
                continue;
            }
            $args[count($args) - 1] .= $text;
        }
        $calls[] = [
            'fn' => $fn,
            'line' => $t[$i][2],
            'method' => $method = is_array($t[$k]) && in_array($t[$k][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true),
            'on' => $method && is_array($t[$k - 1]) && $t[$k - 1][0] === T_VARIABLE ? $t[$k - 1][1] : '',
            'args' => array_map(static fn(string $a): string => trim((string) preg_replace('/\s+/', ' ', $a)), $args),
        ];
    }
    return $calls;
}

/**
 * Every write site in src/ as `<file>|<function>|<name>` => [line, the resolved literal or null].
 *
 * @return array<string, array{string, ?string}>
 */
function uninstallWriteSites(): array
{
    $sites = [];
    foreach (uninstallSrcFiles() as $path => $source) {
        foreach (uninstallCalls($source, array_keys(UNINSTALL_WRITERS)) as $call) {
            if ($call['method'] && $call['fn'] !== 'add_cap') {
                continue;
            }
            $arg = $call['args'][UNINSTALL_WRITERS[$call['fn']]] ?? '';
            if ($call['fn'] === 'wp_insert_post' && preg_match("/'post_type' => ([^,\\]]+)/", $arg, $m) === 1) {
                $arg = trim($m[1]);
            }
            $literal = uninstallResolve($arg, $source);
            $sites[$path . '|' . $call['fn'] . '|' . ($literal ?? $arg)][] = [$path . ':' . $call['line'], $literal];
        }
    }
    return array_map(static fn(array $all): array => $all[0], $sites);
}

it('names, or maps to an entry it names, the name of every write in src/', function (): void {
    $uninstall = uninstallSource();
    $problems = [];
    foreach (uninstallWriteSites() as $key => [$where, $literal]) {
        $mapped = UNINSTALL_WRITE_MAP[$key] ?? null;
        if (is_string($mapped)) {
            if (!str_starts_with($mapped, 'left:') && !str_contains($uninstall, "'" . $mapped . "'")) {
                $problems[] = "$where ($key): mapped to '$mapped', which uninstall.php does not name";
            }
        } elseif ($literal === null) {
            $problems[] = "$where ($key): not a literal and not in UNINSTALL_WRITE_MAP";
        } elseif (!str_contains($uninstall, "'" . $literal . "'")) {
            $problems[] = "$where ($key): uninstall.php does not name '$literal'";
        }
    }
    expect($problems)->toBe([]);
});

it('maps no write site that src/ no longer has', function (): void {
    expect(array_values(array_diff(array_keys(UNINSTALL_WRITE_MAP), array_keys(uninstallWriteSites()))))->toBe([]);
});

it('counts the $wpdb writes of each file, and names the entry that removes their rows', function (): void {
    $uninstall = uninstallSource();
    $counts = [];
    foreach (uninstallSrcFiles() as $path => $source) {
        foreach (uninstallCalls($source, ['query', 'insert', 'replace', 'update', 'delete']) as $call) {
            if ($call['on'] === '$wpdb') {
                $counts[$path] = ($counts[$path] ?? 0) + 1;
            }
        }
    }
    expect($counts)->toBe(array_map(static fn(array $e): int => $e[0], UNINSTALL_WPDB_WRITES));
    foreach (UNINSTALL_WPDB_WRITES as $path => [, $entry]) {
        expect(str_contains($uninstall, "'" . $entry . "'"))->toBeTrue("uninstall.php does not name $entry ($path)");
    }
});

it('finds no table, upload or file the plugin writes, beyond the CLI output', function (): void {
    $found = [];
    foreach (uninstallSrcFiles() as $path => $source) {
        foreach (uninstallCalls($source, UNINSTALL_FILE_WRITERS) as $call) {
            $key = $path . '|' . $call['fn'] . '|' . ($call['args'][0] ?? '');
            if (!$call['method'] && !array_key_exists($key, UNINSTALL_FILE_WRITES_ALLOWED)) {
                $found[] = "$path:{$call['line']} {$call['fn']}()";
            }
        }
        foreach (token_get_all($source) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match('/\b(?:CREATE|ALTER)\s+(?:TEMPORARY\s+)?TABLE\b/i', $tok[1]) === 1) {
                $found[] = "$path:{$tok[2]} a CREATE/ALTER TABLE string";
            }
        }
    }
    expect($found)->toBe([], 'uninstall.php removes no table and no file');
});

it('names the post types and the cron hook', function (): void {
    $uninstall = uninstallSource();
    foreach ([AlpacaBot\Chat\ConversationStore::POST_TYPE, AlpacaBot\Chat\UsageMeter::POST_TYPE, AlpacaBot\Chat\UsageMeter::CLEANUP_HOOK, AlpacaBot\Plugin::POST_TYPE_MARK] as $name) {
        expect(str_contains($uninstall, "'" . $name . "'"))->toBeTrue("uninstall.php does not name $name");
    }
});

it('does nothing, and says nothing, when WordPress did not include it for an uninstall', function (): void {
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', dirname(__DIR__, 2) . '/uninstall.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0);
    expect($out)->toBe('');
});
