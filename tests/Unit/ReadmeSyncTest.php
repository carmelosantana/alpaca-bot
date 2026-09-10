<?php

declare(strict_types=1);

/**
 * bin/readme-sync.php, run as the CLI script it is (a fresh PHP process per call), mostly over a
 * throwaway tree of a README.md and a readme.txt the test writes, so each case pins one rule of
 * the conversion without depending on what the plugin's own readme says today. The last cases
 * run it over the real repo, which is what the committed readme.txt has to agree with.
 *
 * @return array{code: int, stdout: string, stderr: string}
 */
function readmeSync(string $root, ?string $out = '-'): array
{
    $args = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/readme-sync.php', '--root=' . $root];
    if ($out !== null) {
        $args[] = '--out=' . $out;
    }
    $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * A header block shaped like the one readme.txt ships, trailing whitespace and all. The four
 * release rows are a fixture: which versions they name is beside the point, because the whole
 * claim these tests make is that the generator cannot reach any of them, whatever they say.
 */
const README_SYNC_HEADER = "=== Alpaca Bot ===  \n"
    . "Contributors: carmelosantana  \n"
    . "Requires at least: 6.4  \n"
    . "Tested up to: 6.5.5  \n"
    . "Stable tag: 0.4.17  \n"
    . "Requires PHP: 8.1  \n"
    . "License: GPLv2 or later  \n"
    . "  \n"
    . "A privately hosted WordPress AI chatbot.  \n"
    . "  \n";

/** A throwaway repo root holding a README.md and a readme.txt; removed after the test. */
function readmeSyncTree(string $markdown, ?string $readme = null): string
{
    $root = sys_get_temp_dir() . '/alpaca-readme-sync-' . bin2hex(random_bytes(6));
    mkdir($root, 0o777, true);
    file_put_contents($root . '/README.md', $markdown);
    file_put_contents($root . '/readme.txt', $readme ?? readmeSyncTarget());
    $GLOBALS['readmeSyncRoots'][] = $root;
    return $root;
}

/** A readme.txt with the header block, the three owned sections and two that are not owned. */
function readmeSyncTarget(): string
{
    return README_SYNC_HEADER . <<<'TXT'
    == Description ==

    old description

    == Installation ==

    1. Hand-written, and nothing generates this.

    == Frequently Asked Questions ==

    old faq

    == Screenshots ==

    1. Also hand-written.

    == Changelog ==

    old changelog

    == Upgrade Notice ==

    = 0.5.0 =

    Hand-written too.

    TXT;
}

/** A README.md carrying every heading SECTIONS names, with $extra appended to Description. */
function readmeSyncMarkdown(string $extra = ''): string
{
    $sections = ['Description' => "the lead paragraph\n" . $extra, 'Requirements' => 'requires things', 'Setup' => 'set it up', 'Usage' => 'use it', 'Shortcodes' => 'shortcodes here', 'Support' => 'ask on Discord', 'Made Possible By' => 'other people', 'Frequently Asked Questions' => "### Is it good?\n\nYes.", 'Changelog' => "### 0.5.0\n\n- a change"];
    $out = "# Alpaca Bot\n\nbadges and blurb, owned by nobody\n";
    foreach ($sections as $heading => $body) {
        $out .= "\n## " . $heading . "\n\n" . $body . "\n";
    }
    return $out;
}

/** Everything above the first `== Section ==` line: the title, the header block, the blurb. */
function readmeSyncPreamble(string $readme): string
{
    $at = strpos($readme, "\n== ");
    expect($at)->not->toBeFalse();
    return substr($readme, 0, (int) $at + 1);
}

afterEach(function (): void {
    foreach ($GLOBALS['readmeSyncRoots'] ?? [] as $root) {
        foreach (['/README.md', '/readme.txt', '/out.txt'] as $file) {
            if (is_file($root . $file)) {
                unlink($root . $file);
            }
        }
        rmdir($root);
    }
    $GLOBALS['readmeSyncRoots'] = [];
});

// Ruling 7. `Stable tag`, `Requires PHP`, `Requires at least` and `Tested up to` are a release
// decision; a generator must not be able to touch one, not even to rewrite it with the value it
// already had. The README here does its worst -- it carries lines shaped exactly like header
// rows, and a title line of its own -- and the bytes above the first section marker still come
// back unchanged.
it('leaves the header block byte-identical, whatever README.md contains', function (): void {
    $root = readmeSyncTree(readmeSyncMarkdown("Stable tag: 9.9.9\nRequires PHP: 5.6\nTested up to: 1.0\nRequires at least: 1.0\n\n=== Alpaca Bot ===\n"));
    $result = readmeSync($root, $root . '/out.txt');

    expect($result['code'])->toBe(0);
    $out = (string) file_get_contents($root . '/out.txt');
    expect(readmeSyncPreamble($out))->toBe(README_SYNC_HEADER)
        ->and($out)->toContain('the lead paragraph');
});

it('replaces only the three sections it owns', function (): void {
    $root = readmeSyncTree(readmeSyncMarkdown());
    readmeSync($root, $root . '/out.txt');
    $out = (string) file_get_contents($root . '/out.txt');

    expect($out)->toContain("== Installation ==\n\n1. Hand-written, and nothing generates this.")
        ->and($out)->toContain("== Screenshots ==\n\n1. Also hand-written.")
        ->and($out)->toContain("== Upgrade Notice ==\n\n= 0.5.0 =\n\nHand-written too.")
        ->and($out)->not->toContain('old description')
        ->and($out)->not->toContain('old faq')
        ->and($out)->not->toContain('old changelog');
});

// The nesting decides the heading, not the number of hashes: `### 0.5.0` under `## Changelog`,
// whose own heading the section marker already is, has `= X =` left to become -- and that is the
// line wordpress.org splits releases on. The same `###` under `## Usage`, which got a `= Usage =`
// of its own, has no level left and flattens to bold.
it('renders a heading one nesting step below the section marker', function (): void {
    $root = readmeSyncTree(str_replace('## Usage' . "\n\n" . 'use it', '## Usage' . "\n\n" . '### The chat screen' . "\n\n" . 'use it', readmeSyncMarkdown()));
    readmeSync($root, $root . '/out.txt');
    $out = (string) file_get_contents($root . '/out.txt');

    expect($out)->toContain("= 0.5.0 =\n\n- a change")
        ->and($out)->toContain("= Is it good? =\n\nYes.")
        ->and($out)->toContain("= Usage =\n\n**The chat screen**");
});

it('renders a table as one bullet per row and drops the header and the rule', function (): void {
    $root = readmeSyncTree(readmeSyncMarkdown("\n| Attribute | Default | What it does |\n| --- | --- | --- |\n| `prompt` | | The message. |\n| `cache` | `1h` | How long. |\n"));
    readmeSync($root, $root . '/out.txt');
    $out = (string) file_get_contents($root . '/out.txt');

    expect($out)->toContain('* **`prompt`** -- The message.')
        ->and($out)->toContain('* **`cache`** -- `1h` -- How long.')
        ->and($out)->not->toContain('Attribute')
        ->and($out)->not->toContain('| ---');
});

it('indents a fenced code block and keeps its contents verbatim', function (): void {
    $root = readmeSyncTree(readmeSyncMarkdown("\n```php\nadd_filter('alpaca_bot/toolkits', '__return_empty_array');\n```\n"));
    readmeSync($root, $root . '/out.txt');
    $out = (string) file_get_contents($root . '/out.txt');

    expect($out)->toContain("    add_filter('alpaca_bot/toolkits', '__return_empty_array');")
        ->and($out)->not->toContain('```');
});

it('points a repo-relative link at GitHub and leaves an absolute one alone', function (): void {
    $root = readmeSyncTree(readmeSyncMarkdown("\nSee [the API](docs/api.md) and [Ollama](https://github.com/ollama/ollama).\n"));
    readmeSync($root, $root . '/out.txt');
    $out = (string) file_get_contents($root . '/out.txt');

    expect($out)->toContain('[the API](https://github.com/carmelosantana/alpaca-bot/blob/main/docs/api.md)')
        ->and($out)->toContain('[Ollama](https://github.com/ollama/ollama)');
});

it('fails and writes nothing when README.md has lost a heading it renders from', function (): void {
    $root = readmeSyncTree(str_replace("## Changelog\n", "## Change log\n", readmeSyncMarkdown()));
    $before = (string) file_get_contents($root . '/readme.txt');
    $result = readmeSync($root, null);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toContain('## Changelog')
        ->and(file_get_contents($root . '/readme.txt'))->toBe($before);
});

it('fails when readme.txt has no section to write a rendered block into', function (): void {
    $root = readmeSyncTree(readmeSyncMarkdown(), str_replace('== Changelog ==', '== Change log ==', readmeSyncTarget()));
    $result = readmeSync($root, null);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toContain('== Changelog ==');
});

// Ruling 7's remaining write path, closed. read() normalises CRLF for README.md, which is only
// ever read; readme.txt is the file that gets rewritten, so a CRLF one is refused rather than
// converted -- converting it would rewrite the header rows' bytes, which is the one thing this
// script may not do, values unchanged or not.
it('refuses a readme.txt with CR line endings instead of rewriting them', function (): void {
    $crlf = str_replace("\n", "\r\n", readmeSyncTarget());
    $root = readmeSyncTree(readmeSyncMarkdown(), $crlf);
    $result = readmeSync($root, null);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toContain('CR line endings')
        ->and(file_get_contents($root . '/readme.txt'))->toBe($crlf);
});

// A `== X ==` line reaching a rendered body from README.md would become a real section marker:
// the next run would write the owned bodies into it and push the hand-written sections below it
// down, so the generator would stop being idempotent. It is an error rather than a diff.
it('refuses a rendered section carrying a "== ... ==" line of its own', function (): void {
    $root = readmeSyncTree(readmeSyncMarkdown("\n== Upgrade Notice ==\n"));
    $before = (string) file_get_contents($root . '/readme.txt');
    $result = readmeSync($root, null);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toContain('== ... ==')
        ->and(file_get_contents($root . '/readme.txt'))->toBe($before);
});

// The two cases over the real repo. The first is what CI enforces: the committed readme.txt is
// what the generator produces from the committed README.md, so a README edit that was never
// synced fails here as well as there.
it('is what the committed readme.txt already says', function (): void {
    $root = dirname(__DIR__, 2);
    $result = readmeSync($root);

    expect($result['code'])->toBe(0)
        ->and($result['stdout'])->toBe((string) file_get_contents($root . '/readme.txt'));
});

it('leaves the shipped header block alone on the real repo too', function (): void {
    $root = dirname(__DIR__, 2);
    $committed = (string) file_get_contents($root . '/readme.txt');
    $result = readmeSync($root);

    expect(readmeSyncPreamble($result['stdout']))->toBe(readmeSyncPreamble($committed))
        ->and(readmeSyncPreamble($committed))->toContain('Stable tag:');
});
