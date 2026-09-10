#!/usr/bin/env php
<?php
/**
 * Renders three sections of `readme.txt` -- `== Description ==`,
 * `== Frequently Asked Questions ==` and `== Changelog ==` -- from the matching `##` headings of
 * `README.md`, so the wordpress.org listing and the GitHub page cannot describe different
 * plugins. `composer docs:readme` runs it; CI regenerates and demands no diff, exactly as it
 * already does for `composer docs:hooks`, so a README edit that was never synced is a red build
 * rather than a listing that quietly stops being true.
 *
 * SECTIONS is the whole of what it owns, and the whole of what it may write. Everything else in
 * readme.txt is a human's: `== Installation ==`, `== Screenshots ==` and `== Upgrade Notice ==`
 * are hand-written sections, and the preamble -- the `=== Alpaca Bot ===` title, the header
 * block and the short description under it -- is not touched at all. That is not a convention
 * this file follows by habit; it is the rule the file is built on, because the header block
 * holds four rows that are a release decision and nothing else:
 *
 *   Stable tag        which version wordpress.org serves and offers as an update
 *   Requires PHP      the floor the update API filters that offer by
 *   Requires at least the same, for WordPress
 *   Tested up to      the compatibility claim on the listing
 *
 * Whatever those four rows say is deliberate, and they say it about the release wordpress.org
 * is serving rather than about the tree: while a line is in development the block goes on
 * describing the published release, so the floors the update API filters that release by keep
 * matching it. A sync run that could move one of those rows -- even by rewriting the block with
 * the same values and different whitespace -- would be a release decision made by a generator.
 * So the split is structural rather than careful: replace() locates the first `== ... ==` line
 * and treats everything above it as opaque bytes it copies through, and readTarget() refuses a
 * readme.txt whose line endings it would have to rewrite to do that, so there is no code path
 * from this script to the header block at all. tests/Unit/ReadmeSyncTest.php pins that the
 * preamble comes out byte-identical.
 *
 * The conversion is from GitHub Markdown to the readme.txt dialect, and it is deliberately
 * small:
 *
 *   a heading          => `= X =` at the first level below the `== Section ==` marker, `**X**`
 *                        at every level under that. It is the nesting that decides, not the
 *                        number of hashes: `= X =` is the only heading readme.txt has inside a
 *                        section, since `== X ==` would start a new one, so the second level
 *                        down has to flatten to bold. This is what makes `### 0.5.0` under
 *                        `## Changelog` come out as `= 0.5.0 =`, which is the line the
 *                        wordpress.org parser splits releases on, and each `### question` of
 *                        the FAQ come out as the `= question =` that listing renders.
 *   a table           => a bullet per body row, cells joined by " -- ", the first cell bolded,
 *                        an empty cell dropped along with its separator. The header row and the
 *                        `---` rule are dropped: the readme parser renders no table, and a
 *                        pipe-drawn one reads as noise.
 *   ```lang fences    => the same lines indented four spaces, which every Markdown flavour
 *                        renders as a code block and this one is not documented to.
 *   a repo-relative   => the same path under GITHUB, so a link that works on the GitHub page
 *   link                works on the listing too rather than 404ing under wordpress.org.
 *
 * Anything else -- paragraphs, lists, inline code, absolute links, emphasis -- is copied
 * through. The output is deterministic: it depends on README.md, readme.txt and this file, and
 * on nothing about the machine or the clock.
 *
 * Usage: `php bin/readme-sync.php [--root=<repo>] [--out=<file>|-]`. `--root` defaults to the
 * repo this script lives in; `--out` to `<root>/readme.txt`, and `-` writes to stdout.
 */

declare(strict_types=1);

namespace AlpacaBot\Bin\ReadmeSync;

/**
 * readme.txt section => the `##` headings of README.md that make it up, in order.
 *
 * A readme.txt section named here must exist in the file; a README heading named here must
 * exist in README.md. Either missing is an error rather than an empty section, because an empty
 * `== Description ==` would pass a no-diff check on the run that emptied it.
 */
const SECTIONS = [
    'Description' => ['Description', 'Requirements', 'Setup', 'Usage', 'Shortcodes', 'Support', 'Made Possible By'],
    'Frequently Asked Questions' => ['Frequently Asked Questions'],
    'Changelog' => ['Changelog'],
];

/** Where a repo-relative link points once it is on wordpress.org. */
const GITHUB = 'https://github.com/carmelosantana/alpaca-bot/blob/main/';

/**
 * @param list<string> $argv
 * @return array{root: string, out: string}
 */
function options(array $argv): array
{
    $root = dirname(__DIR__);
    $out = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--root=')) {
            $root = rtrim(substr($arg, 7), '/');
        } elseif (str_starts_with($arg, '--out=')) {
            $out = substr($arg, 6);
        } else {
            fail('unknown argument: ' . $arg);
        }
    }
    return ['root' => $root, 'out' => $out ?? $root . '/readme.txt'];
}

function fail(string $message): never
{
    fwrite(STDERR, 'readme-sync: ' . $message . "\n");
    exit(1);
}

/**
 * README.md's contents with CRLF normalised away, so a heading is a heading whatever wrote it.
 *
 * README.md is only ever read: nothing here writes it, so normalising a copy in memory changes
 * no bytes on disk. readme.txt is the file this script rewrites, and it is read by readTarget()
 * instead, which does not normalise anything.
 */
function read(string $path): string
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fail('cannot read ' . $path);
    }
    return str_replace(["\r\n", "\r"], "\n", $raw);
}

/**
 * readme.txt exactly as it is on disk, or an error.
 *
 * Nothing normalises it. Everything above the first `== ... ==` line is copied through as the
 * bytes it arrived as, and normalising CRLF here would rewrite those bytes -- the four header
 * rows among them -- which is the one thing this script must never do, values unchanged or not.
 * A readme.txt with CR in it is therefore refused rather than quietly converted; `.gitattributes`
 * is `* text=auto` and the committed file is LF, so this fires only on a checkout that has
 * already rewritten it.
 */
function readTarget(string $path): string
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fail('cannot read ' . $path);
    }
    if (str_contains($raw, "\r")) {
        fail($path . ' has CR line endings; this script will not rewrite them -- convert it to LF first');
    }
    return $raw;
}

/**
 * README.md split into its `##` sections: heading text => the lines under it, up to the next
 * `##` or `#` heading. A fenced block's contents are never read as a heading.
 *
 * @return array<string, list<string>>
 */
function markdownSections(string $markdown): array
{
    $sections = [];
    $current = null;
    $fence = null;
    foreach (explode("\n", $markdown) as $line) {
        if (preg_match('/^\s*(```+|~~~+)/', $line, $m) === 1) {
            $token = $m[1][0];
            if ($fence === null) {
                $fence = $token;
            } elseif ($fence === $token) {
                $fence = null;
            }
        }
        if ($fence === null && preg_match('/^(#{1,2})\s+(.*?)\s*$/', $line, $m) === 1) {
            $current = $m[1] === '##' ? $m[2] : null;
            if ($current !== null) {
                $sections[$current] = [];
            }
            continue;
        }
        if ($current !== null) {
            $sections[$current][] = $line;
        }
    }
    return $sections;
}

/**
 * The Markdown-to-readme.txt conversion the file docblock lists, applied line by line. A table
 * is the one construct that needs more than one line of context, so it is collected and flushed.
 *
 * `$nested` says whether these lines already sit under a `= X =` of their own: false when the
 * body hangs straight off the `== Section ==` marker, and then a heading still has `= X =` to
 * become; true when render() has emitted one above them, and then there is no heading level
 * left and everything flattens to bold.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function convert(array $lines, bool $nested): array
{
    $out = [];
    $table = [];
    $fence = null;
    foreach ($lines as $line) {
        if (preg_match('/^\s*(```+|~~~+)/', $line, $m) === 1) {
            $token = $m[1][0];
            if ($fence === null) {
                $fence = $token;
                continue;
            }
            if ($fence === $token) {
                $fence = null;
                continue;
            }
        }
        if ($fence !== null) {
            $out[] = $line === '' ? '' : '    ' . $line;
            continue;
        }
        if (preg_match('/^\s*\|.*\|\s*$/', $line) === 1) {
            $table[] = $line;
            continue;
        }
        if ($table !== []) {
            $out = array_merge($out, table($table));
            $table = [];
        }
        $out[] = links(heading($line, $nested));
    }
    if ($table !== []) {
        $out = array_merge($out, table($table));
    }
    return trimBlank($out);
}

/**
 * A `###`..`######` heading, one nesting step down. `##` cannot appear here: markdownSections()
 * consumed it to name the block these lines came from.
 *
 * The shallowest heading in the block becomes `= X =` when the block is not already nested, and
 * everything deeper than that flattens to bold, because readme.txt has exactly one heading level
 * inside a section.
 */
function heading(string $line, bool $nested): string
{
    if (preg_match('/^(#{3,6})\s+(.*?)\s*$/', $line, $m) !== 1) {
        return $line;
    }
    return !$nested && strlen($m[1]) === 3 ? '= ' . $m[2] . ' =' : '**' . $m[2] . '**';
}

/** A repo-relative Markdown link target becomes the same path on GitHub. */
function links(string $line): string
{
    return (string) preg_replace_callback(
        '/\]\(([^):\s#][^):\s]*)\)/',
        static fn (array $m): string => '](' . GITHUB . ltrim($m[1], '/') . ')',
        $line,
    );
}

/**
 * A Markdown table as one bullet per body row. The header row and the `---` rule are dropped;
 * an empty cell is dropped with its separator, so a row whose middle column is blank does not
 * come out with a dangling dash.
 *
 * @param list<string> $rows
 * @return list<string>
 */
function table(array $rows): array
{
    $out = [];
    foreach ($rows as $i => $row) {
        $cells = array_map('trim', explode('|', trim(trim($row), '|')));
        if ($i === 0 || preg_match('/^[\s:|-]+$/', $row) === 1) {
            continue;
        }
        $cells = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
        if ($cells === []) {
            continue;
        }
        $cells[0] = '**' . $cells[0] . '**';
        $out[] = '* ' . implode(' -- ', $cells);
    }
    return $out === [] ? $out : array_merge($out, ['']);
}

/**
 * Leading and trailing blank lines off, and no run of more than one blank line inside: the
 * sections are joined with a fixed blank line of their own, so the output's spacing comes from
 * one place rather than from however the README happened to be laid out.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function trimBlank(array $lines): array
{
    $out = [];
    foreach ($lines as $line) {
        $blank = trim($line) === '';
        if ($blank && ($out === [] || trim((string) end($out)) === '')) {
            continue;
        }
        $out[] = $blank ? '' : rtrim($line);
    }
    while ($out !== [] && trim((string) end($out)) === '') {
        array_pop($out);
    }
    return $out;
}

/**
 * The rendered body of every owned readme.txt section.
 *
 * A README heading of the same name as the readme.txt section it feeds contributes its body
 * alone: `= Description =` inside `== Description ==` would be the same word twice. Every other
 * heading keeps its name, since the reader needs to know where Setup stops and Usage starts.
 *
 * @param array<string, list<string>> $md
 * @return array<string, string>
 */
function render(array $md): array
{
    $rendered = [];
    foreach (SECTIONS as $target => $headings) {
        $blocks = [];
        foreach ($headings as $heading) {
            if (!isset($md[$heading])) {
                fail('README.md has no "## ' . $heading . '" heading, which ' . $target . ' is rendered from');
            }
            $nested = $heading !== $target;
            $body = convert($md[$heading], $nested);
            if ($body === []) {
                fail('README.md\'s "## ' . $heading . '" section is empty');
            }
            $blocks[] = implode("\n", $nested ? array_merge(['= ' . $heading . ' =', ''], $body) : $body);
        }
        $rendered[$target] = implode("\n\n", $blocks);
    }
    return $rendered;
}

/**
 * readme.txt with the owned sections replaced and nothing else changed.
 *
 * The split is on the section markers, and everything before the first one -- the title, the
 * header block, the short description -- is copied through as the bytes it arrived as. That is
 * what makes `Stable tag`, `Requires PHP`, `Requires at least` and `Tested up to` unreachable
 * from here.
 *
 * Which is also why a rendered body may not contain a marker of its own. A `== X ==` line
 * arriving from README.md would become a real section: the next run would treat it as one,
 * write the owned bodies into it and push the hand-written sections below it down, so the
 * generator would stop being idempotent. It is an error here rather than a diff CI has to
 * catch.
 *
 * @param array<string, string> $rendered
 */
function replace(string $readme, array $rendered): string
{
    foreach ($rendered as $name => $body) {
        if (preg_match('/^== .+ ==$/m', $body) === 1) {
            fail('rendered "' . $name . '" carries a "== ... ==" line, which readme.txt reads as a new section; change that heading in README.md');
        }
    }
    $parts = preg_split('/^(== .+ ==)$/m', $readme, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false || count($parts) < 2) {
        fail('readme.txt has no "== Section ==" headings');
    }
    $out = $parts[0];
    $seen = [];
    for ($i = 1; $i < count($parts); $i += 2) {
        $marker = $parts[$i];
        $name = trim(substr($marker, 2, -2));
        $body = $parts[$i + 1] ?? "\n";
        if (isset($rendered[$name])) {
            $seen[$name] = true;
            $body = "\n\n" . $rendered[$name] . "\n\n";
        }
        $out .= $marker . $body;
    }
    foreach (array_keys($rendered) as $name) {
        if (!isset($seen[$name])) {
            fail('readme.txt has no "== ' . $name . ' ==" section to write');
        }
    }
    return rtrim($out, "\n") . "\n";
}

$opts = options($argv);
$readme = replace(readTarget($opts['root'] . '/readme.txt'), render(markdownSections(read($opts['root'] . '/README.md'))));
if ($opts['out'] === '-') {
    echo $readme;
    exit(0);
}
if (@file_put_contents($opts['out'], $readme) === false) {
    fail('cannot write ' . $opts['out']);
}
echo 'wrote ' . $opts['out'] . "\n";
