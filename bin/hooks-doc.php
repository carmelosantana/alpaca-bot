#!/usr/bin/env php
<?php
/**
 * Writes docs/hooks.md, the reference for every `alpaca_bot/*` filter and action the plugin
 * fires, from the docblock above each call site in src/. `composer docs:hooks` runs it; the
 * committed file must match its output (tests/Unit/HooksDocTest.php checks, and P5's CI will).
 *
 * It reads tokens, not lines, so a call whose hook name sits on the line after `do_action(`
 * (Chat\Pipeline::settle()) is found, and so the docblock is located by the statement it heads
 * rather than by adjacency: walking back from the call to the previous `;`, `{` or `}` and
 * taking the docblock there covers `$x = (bool) apply_filters(...)`, `return apply_filters(...)`
 * and `max(1, (int) apply_filters(...))` alike.
 *
 * Two of the plugin's hooks are not spelled at an `apply_filters` call at all: the REST
 * permission callbacks (`alpaca_bot/capability/{route}`, Rest\Controller) and the admin menu
 * (`alpaca_bot/admin/menu_capability`, Admin\Menu) hand their names to Capability::filtered(),
 * which applies the filter and reduces the result to a capability name. So `Capability::filtered(`
 * is treated as a filter call site, its first argument the hook, and the `apply_filters($hook, ...)`
 * inside the body of Capability::filtered() itself, the one place a hook name is legitimately a
 * variable, is the only non-literal name the scanner skips: that method, in that file, not the
 * file. Anywhere else a hook name that is not a string literal is an error, not an omission: a
 * wrapper added later, a second one in Capability.php included, has to be named here, in
 * WRAPPERS, or the run fails, rather than the reference quietly losing whatever went through it.
 * An interpolated literal (`"alpaca_bot/capability/{$route}"`) is documented with the variable
 * as `{route}`.
 *
 * The table is alphabetical, so the order a chat turn fires its hooks in is shown separately,
 * ahead of it, read from the "Hooks, in firing order:" paragraph of Chat\Pipeline's class
 * docblock (the place the plan names as the source of that order): every name in that paragraph
 * must be a documented hook, or the run fails, so the paragraph and the table cannot drift apart
 * unnoticed. The pipeline's other hooks (`chat/failed`) are named after the list as outside it.
 *
 * A call site with no docblock, a docblock with no description or no `@since`, or one whose
 * `@param` count differs from what the call passes, fails the run (every problem is listed, exit
 * 1, nothing written): an undocumented hook is the thing this file exists to prevent, and a row
 * with an empty description would pass CI. Output is deterministic by construction: files are
 * sorted, rows are ordered by hook name then location, locations are relative to the repo root,
 * and nothing about the machine or the clock is written.
 *
 * Usage: `php bin/hooks-doc.php [--root=<repo>] [--out=<file>|-]`. `--root` defaults to the
 * repo this script lives in; `--out` to `<root>/docs/hooks.md`, and `-` writes to stdout.
 */

declare(strict_types=1);

namespace AlpacaBot\Bin\HooksDoc;

/** Hook names this reference covers. */
const PREFIX = 'alpaca_bot/';

/**
 * Static calls that apply a filter named by their first argument: `Class::method` => type. The
 * body of each (the method of that name, in the file named after the class) is the only place a
 * hook name may be a variable.
 */
const WRAPPERS = ['Capability::filtered' => 'filter'];

/** Functions that fire a hook named by their first argument: name => type. */
const FUNCTIONS = ['apply_filters' => 'filter', 'do_action' => 'action'];

/** The file whose class docblock states the order a turn fires its hooks in, and how that paragraph starts. */
const ORDER_FILE = 'src/Chat/Pipeline.php';
const ORDER_MARKER = 'Hooks, in firing order:';

/**
 * @phpstan-type Param array{type: string, name: string, description: string}
 * @phpstan-type Row array{hook: string, type: string, file: string, line: int, params: list<Param>, description: string}
 * @phpstan-type Order array{sequence: list<array{hook: string, type: string}>, outside: list<array{hook: string, type: string}>}
 */
final class HooksDoc
{
    /** @var list<string> every problem found, as `file:line hook: what` */
    private array $errors = [];

    /**
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $root = dirname(__DIR__);
        $out = null;
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--root=')) {
                $root = substr($arg, 7);
            } elseif (str_starts_with($arg, '--out=')) {
                $out = substr($arg, 6);
            } else {
                fwrite(STDERR, "hooks-doc: unknown argument {$arg}\n");
                return 2;
            }
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real . '/src')) {
            fwrite(STDERR, "hooks-doc: {$root}/src is not a directory\n");
            return 2;
        }
        $out ??= $real . '/docs/hooks.md';

        $doc = new self();
        $rows = $doc->scan($real);
        $order = $doc->firingOrder($real, $rows);
        if ($doc->errors !== []) {
            fwrite(STDERR, "hooks-doc: " . count($doc->errors) . " problem(s); nothing written\n  " . implode("\n  ", $doc->errors) . "\n");
            return 1;
        }
        $markdown = self::render($rows, $order);
        if ($out === '-') {
            fwrite(STDOUT, $markdown);
            return 0;
        }
        $dir = dirname($out);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            fwrite(STDERR, "hooks-doc: cannot create {$dir}\n");
            return 1;
        }
        if (@file_put_contents($out, $markdown) !== strlen($markdown)) {
            fwrite(STDERR, "hooks-doc: cannot write {$out}\n");
            return 1;
        }
        $hooks = count(array_unique(array_column($rows, 'hook')));
        $shown = str_starts_with($out, $real . '/') ? substr($out, strlen($real) + 1) : $out;
        fwrite(STDOUT, sprintf("hooks-doc: %d call site(s), %d hook(s) -> %s\n", count($rows), $hooks, $shown));
        return 0;
    }

    /**
     * Every documented call site under `<root>/src`, ordered by hook, then file, then line.
     *
     * @return list<Row>
     */
    private function scan(string $root): array
    {
        $rows = [];
        foreach (self::files($root . '/src') as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $code = file_get_contents($path);
            if ($code === false) {
                $this->errors[] = "{$relative}: unreadable";
                continue;
            }
            foreach ($this->sites(\PhpToken::tokenize($code), $relative) as $row) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$a['hook'], $a['file'], $a['line']] <=> [$b['hook'], $b['file'], $b['line']]);
        return $rows;
    }

    /**
     * The order a turn fires its hooks in, from the ORDER_MARKER paragraph of the first docblock
     * in ORDER_FILE that has one: the `alpaca_bot/*` names in that paragraph, in order, each of
     * which must be a documented hook, plus ORDER_FILE's other hooks as `outside`. Null when the
     * tree has no ORDER_FILE (a fixture); a missing paragraph or an unknown name is a problem.
     *
     * @param list<Row> $rows
     * @return Order|null
     */
    private function firingOrder(string $root, array $rows): ?array
    {
        $path = $root . '/' . ORDER_FILE;
        if (!is_file($path)) {
            return null;
        }
        $code = file_get_contents($path);
        $paragraph = null;
        foreach ($code === false ? [] : \PhpToken::tokenize($code) as $token) {
            $at = $token->is(T_DOC_COMMENT) ? strpos($token->text, ORDER_MARKER) : false;
            if ($at !== false) {
                $paragraph = (string) preg_split('~\R[ \t]*\*?[ \t]*\R~', substr($token->text, $at), 2)[0];
                break;
            }
        }
        if ($paragraph === null) {
            $this->errors[] = ORDER_FILE . ': no docblock paragraph starts with "' . ORDER_MARKER . '", which is where the turn\'s firing order is read from';
            return null;
        }
        $types = [];
        foreach ($rows as $row) {
            $types[$row['hook']] = $row['type'];
        }
        preg_match_all('~`(' . preg_quote(PREFIX, '~') . '[^`]+)`~', $paragraph, $m);
        $sequence = [];
        foreach ($m[1] as $hook) {
            if (!isset($types[$hook])) {
                $this->errors[] = ORDER_FILE . ": the firing order names `{$hook}`, which no call site documents";
                continue;
            }
            $sequence[] = ['hook' => $hook, 'type' => $types[$hook]];
        }
        $outside = [];
        foreach ($rows as $row) {
            if ($row['file'] === ORDER_FILE && !in_array($row['hook'], $m[1], true) && !in_array(['hook' => $row['hook'], 'type' => $row['type']], $outside, true)) {
                $outside[] = ['hook' => $row['hook'], 'type' => $row['type']];
            }
        }
        return ['sequence' => $sequence, 'outside' => $outside];
    }

    /**
     * @return list<string> absolute paths of every .php file below $dir, sorted
     */
    private static function files(string $dir): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * The hook call sites in one file's tokens; problems go to $this->errors.
     *
     * @param list<\PhpToken> $tokens
     * @return list<Row>
     */
    private function sites(array $tokens, string $file): array
    {
        $rows = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $call = self::callAt($tokens, $i);
            if ($call === null) {
                continue;
            }
            [$type, $callee, $open] = $call;
            $where = "{$file}:{$tokens[$callee]->line}";
            $hook = self::hookName($tokens, $open);
            if ($hook === null) {
                if (!self::isWrapperBody($tokens, $callee, $file)) {
                    $this->errors[] = "{$where}: hook name is not a string literal; a wrapper that names the hook belongs in WRAPPERS in bin/hooks-doc.php";
                }
                continue;
            }
            if (!str_starts_with($hook, PREFIX)) {
                continue;
            }
            $doc = self::docblockBefore($tokens, $callee);
            if ($doc === null) {
                $this->errors[] = "{$where} {$hook}: no docblock above the call";
                continue;
            }
            $parsed = self::parseDocblock($doc);
            if ($parsed['description'] === '') {
                $this->errors[] = "{$where} {$hook}: the docblock has no description";
                continue;
            }
            if (!$parsed['since']) {
                $this->errors[] = "{$where} {$hook}: the docblock has no @since";
                continue;
            }
            $args = self::argumentCount($tokens, $open) - 1;
            if ($args !== count($parsed['params'])) {
                $this->errors[] = sprintf('%s %s: the call passes %d argument(s) after the hook but the docblock has %d @param', $where, $hook, $args, count($parsed['params']));
                continue;
            }
            $rows[] = ['hook' => $hook, 'type' => $type, 'file' => $file, 'line' => $tokens[$callee]->line, 'params' => $parsed['params'], 'description' => $parsed['description']];
        }
        return $rows;
    }

    /**
     * A hook-firing call whose callee starts at $i: `apply_filters(`, `do_action(` or one of
     * WRAPPERS, as [type, index of the callee's first token, index of the `(`]. Method calls,
     * declarations and bare names are not calls.
     *
     * @param list<\PhpToken> $tokens
     * @return array{string, int, int}|null
     */
    private static function callAt(array $tokens, int $i): ?array
    {
        $token = $tokens[$i];
        if (!$token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])) {
            return null;
        }
        $previous = self::significant($tokens, $i, -1);
        if ($previous !== null && $tokens[$previous]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
            return null;
        }
        $name = ltrim($token->text, '\\');
        $next = self::significant($tokens, $i, 1);
        if ($next === null) {
            return null;
        }
        if (isset(FUNCTIONS[$name]) && $tokens[$next]->text === '(') {
            return [FUNCTIONS[$name], $i, $next];
        }
        if (!$tokens[$next]->is(T_DOUBLE_COLON)) {
            return null;
        }
        $method = self::significant($tokens, $next, 1);
        $paren = $method === null ? null : self::significant($tokens, $method, 1);
        if ($method === null || $paren === null || !$tokens[$method]->is(T_STRING) || $tokens[$paren]->text !== '(') {
            return null;
        }
        $class = substr($name, (int) strrpos('\\' . $name, '\\'));
        $wrapper = "{$class}::{$tokens[$method]->text}";
        return isset(WRAPPERS[$wrapper]) ? [WRAPPERS[$wrapper], $i, $paren] : null;
    }

    /**
     * The hook named by the first argument of the call whose `(` is at $open: a plain string
     * literal, or a double-quoted one whose interpolated variables are shown as `{name}`. Null
     * for anything else.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function hookName(array $tokens, int $open): ?string
    {
        $i = self::significant($tokens, $open, 1);
        if ($i === null) {
            return null;
        }
        $token = $tokens[$i];
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return stripcslashes(substr($token->text, 1, -1));
        }
        if ($token->text !== '"') {
            return null;
        }
        $hook = '';
        for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->text !== '"'; $j++) {
            $part = $tokens[$j];
            if ($part->is(T_ENCAPSED_AND_WHITESPACE)) {
                $hook .= stripcslashes($part->text);
            } elseif ($part->is(T_VARIABLE)) {
                $hook .= '{' . substr($part->text, 1) . '}';
            } elseif (in_array($part->text, ['{', '${'], true) && isset($tokens[$j + 1], $tokens[$j + 2]) && $tokens[$j + 1]->is([T_VARIABLE, T_STRING_VARNAME]) && $tokens[$j + 2]->text === '}') {
                $hook .= '{' . ltrim($tokens[$j + 1]->text, '$') . '}';
                $j += 2;
            } else {
                return null;
            }
        }
        return $hook;
    }

    /**
     * Whether the call at $callee is the body of one of WRAPPERS: inside the method of that name,
     * in the file named after its class (`Capability::filtered` => `Capability.php`, `filtered()`).
     *
     * @param list<\PhpToken> $tokens
     */
    private static function isWrapperBody(array $tokens, int $callee, string $file): bool
    {
        $method = self::enclosingFunction($tokens, $callee);
        if ($method === null) {
            return false;
        }
        $class = substr(basename($file), 0, -4);
        return isset(WRAPPERS["{$class}::{$method}"]);
    }

    /**
     * The name of the innermost named function or method whose body contains $i, or null when
     * there is none (top-level code, or only closures).
     *
     * @param list<\PhpToken> $tokens
     */
    private static function enclosingFunction(array $tokens, int $i): ?string
    {
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $text = $tokens[$j]->text;
            if ($text === '}') {
                $depth++;
            } elseif ($text === '{' || $text === '${') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                $name = self::functionOpenedBy($tokens, $j);
                if ($name !== null) {
                    return $name;
                }
            }
        }
        return null;
    }

    /**
     * The name of the function whose body the `{` at $brace opens, when the tokens before it are
     * `function name(...)` with an optional return type; null for any other block.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function functionOpenedBy(array $tokens, int $brace): ?string
    {
        $j = self::significant($tokens, $brace, -1);
        while ($j !== null && $tokens[$j]->text !== ')') {
            if (in_array($tokens[$j]->text, [';', '{', '}'], true)) {
                return null;
            }
            $j = self::significant($tokens, $j, -1);
        }
        for ($depth = 0; $j !== null && $j >= 0; $j--) {
            if ($tokens[$j]->text === ')') {
                $depth++;
            } elseif ($tokens[$j]->text === '(' && --$depth === 0) {
                break;
            }
        }
        if ($j === null || $j < 0) {
            return null;
        }
        $name = self::significant($tokens, $j, -1);
        $keyword = $name === null ? null : self::significant($tokens, $name, -1);
        if ($name === null || $keyword === null || !$tokens[$name]->is(T_STRING) || !$tokens[$keyword]->is(T_FUNCTION)) {
            return null;
        }
        return $tokens[$name]->text;
    }

    /**
     * The docblock heading the statement the call at $callee belongs to: the last token before
     * the previous `;`, `{`, `}` or the open tag, when that token is a docblock.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function docblockBefore(array $tokens, int $callee): ?string
    {
        for ($i = $callee - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($token->is(T_DOC_COMMENT)) {
                return $token->text;
            }
            if ($token->is([T_OPEN_TAG, T_CLOSE_TAG]) || in_array($token->text, [';', '{', '}'], true)) {
                return null;
            }
        }
        return null;
    }

    /**
     * How many arguments the call whose `(` is at $open passes: top-level commas plus one, a
     * trailing comma not counted.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function argumentCount(array $tokens, int $open): int
    {
        $depth = 0;
        $commas = 0;
        $lastSignificant = '(';
        for ($i = $open + 1; isset($tokens[$i]); $i++) {
            $token = $tokens[$i];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->text === ')' && $depth === 0) {
                return $lastSignificant === '(' ? 0 : $commas + ($lastSignificant === ',' ? 0 : 1);
            }
            if (in_array($token->text, ['(', '[', '{', '${'], true)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($token->text === ',' && $depth === 0) {
                $commas++;
            }
            $lastSignificant = $token->text;
        }
        return $commas + 1;
    }

    /**
     * The description (the paragraphs before the first tag, joined), the `@param` tags and
     * whether `@since` is present. A tag's continuation lines belong to it, and a type may
     * contain spaces (`array<string, mixed>`): it runs up to the `$name`.
     *
     * @return array{description: string, params: list<Param>, since: bool}
     */
    private static function parseDocblock(string $doc): array
    {
        $body = (string) preg_replace(['~^/\*\*~', '~\*/$~'], '', trim($doc));
        $description = [];
        $params = [];
        $since = false;
        $inTags = false;
        foreach (preg_split('~\R~', $body) ?: [] as $raw) {
            $line = trim((string) preg_replace('~^\s*\*\s?~', '', $raw));
            if (str_starts_with($line, '@')) {
                $inTags = true;
                if (preg_match('~^@param\s+(.+?)\s+(\.\.\.)?(\$\w+)\s*(.*)$~', $line, $m) === 1) {
                    $params[] = ['type' => $m[1], 'name' => $m[2] . $m[3], 'description' => $m[4]];
                } elseif (str_starts_with($line, '@since')) {
                    $since = true;
                }
                continue;
            }
            if ($line === '') {
                continue;
            }
            if (!$inTags) {
                $description[] = $line;
            } elseif ($params !== []) {
                $params[count($params) - 1]['description'] .= ' ' . $line;
            }
        }
        return ['description' => implode(' ', $description), 'params' => $params, 'since' => $since];
    }

    /**
     * @param list<Row> $rows
     * @param Order|null $order
     */
    private static function render(array $rows, ?array $order): string
    {
        $lines = [
            '# Hooks',
            '',
            'Every `alpaca_bot/*` filter and action the plugin fires, generated from the docblock above',
            'each call site in `src/` by `composer docs:hooks` (`bin/hooks-doc.php`). Do not edit by hand:',
            'change the docblock, regenerate, and commit the result; the unit suite fails when this file',
            'is stale. Location is the call site, relative to the plugin root; a hook fired from more',
            'than one place has a row per site. Params are what a listener receives, in order; for a',
            'filter the first is the value to return.',
            '',
            'Not a row, on purpose: `alpaca_bot/usage/cleanup` is the name of a WP-Cron event',
            '(`UsageMeter::CLEANUP_HOOK`) the plugin schedules and listens to. WordPress fires it, not the',
            'plugin, so a listener can `add_action` to it but it is not a hook the plugin applies.',
            '',
            '## Before you loosen a capability filter',
            '',
            'Read this once before using `alpaca_bot/capability/{route}` or',
            '`alpaca_bot/admin/menu_capability` to open something up. Both honour any capability name,',
            '`read` (every Subscriber) and `exist` (every visitor, logged out included) among them, and',
            'that is deliberate: it is how a site builds a subscriber-facing or public chat.',
            '',
            'What is easy to miss is that **opening `chat` or `chat/stream` to a role opens every enabled',
            'tool to that role as well.** `Toolkit\Registry::enabled()` picks a turn\'s toolkits from the',
            '`toolkits.enabled` setting and the `alpaca_bot/toolkits` filter and has no capability check',
            'of its own, so nothing sits between "may chat" and "may call the tools that are switched',
            'on" — including `web_fetch`, which makes the web server send an outbound HTTP request and',
            'hands the reply back as text. Only `draft_post` re-checks a capability and refuses a role',
            'that lacks it. Use `alpaca_bot/toolkits` with `user_can( $user_id, … )` to take a tool away',
            'from the users a loosened route admits. A capability floor inside `enabled()` is a later',
            '0.x release; the operator-facing version of all this, including the egress policy that',
            'mitigates `web_fetch`, is under "Tools, and what they let the model reach" in the README.',
            '',
            '`alpaca_bot/capability/settings` is the other one worth a second look: one key covers both',
            'verbs on `/settings`, so admitting a role to the read also admits it to the write, and',
            '`provider.base_url` is a settable field. Tighten per request off the `WP_REST_Request` the',
            'filter is handed (`$request->get_method()`). The `?reveal=1` parameter, which answers with',
            'the provider API key in cleartext, asks `manage_options` on its own account and is not',
            'reachable through this filter.',
        ];
        if ($order !== null) {
            $lines[] = '';
            $lines[] = '## A chat turn, in firing order';
            $lines[] = '';
            $lines[] = 'The table is alphabetical. The order one turn fires its hooks in, from the class docblock of';
            $lines[] = '`' . ORDER_FILE . '`, where that order is kept:';
            $lines[] = '';
            foreach ($order['sequence'] as $n => $step) {
                $lines[] = sprintf('%d. `%s` (%s)', $n + 1, $step['hook'], $step['type']);
            }
            if ($order['outside'] !== []) {
                $lines[] = '';
                $lines[] = 'Also fired by the pipeline, outside that sequence: ' . implode(', ', array_map(
                    static fn(array $step): string => "`{$step['hook']}` ({$step['type']})",
                    $order['outside'],
                )) . '; its rows say when.';
            }
        }
        $lines[] = '';
        $lines[] = '| Hook | Type | Params | Location | Description |';
        $lines[] = '| --- | --- | --- | --- | --- |';
        foreach ($rows as $row) {
            $params = array_map(
                static fn(array $p): string => "`{$p['type']} {$p['name']}`" . ($p['description'] === '' ? '' : ' — ' . $p['description']),
                $row['params'],
            );
            $lines[] = sprintf(
                '| `%s` | %s | %s | `%s:%d` | %s |',
                $row['hook'],
                $row['type'],
                self::cell($params === [] ? '—' : implode('<br>', $params)),
                $row['file'],
                $row['line'],
                self::cell($row['description']),
            );
        }
        return implode("\n", $lines) . "\n";
    }

    /** One table cell: no pipes, no line breaks. */
    private static function cell(string $text): string
    {
        return str_replace('|', '\\|', (string) preg_replace('~\s+~', ' ', $text));
    }

    /**
     * The index of the nearest non-whitespace, non-comment token from $i in $direction, or null.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function significant(array $tokens, int $i, int $direction): ?int
    {
        for ($j = $i + $direction; isset($tokens[$j]); $j += $direction) {
            if (!$tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $j;
            }
        }
        return null;
    }
}

exit(HooksDoc::main(is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : []));
