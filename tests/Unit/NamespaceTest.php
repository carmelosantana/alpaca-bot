<?php

declare(strict_types=1);

use AlpacaBot\Errors;
use AlpacaBot\RateLimit;

/*
 * Errors and RateLimit left Rest\ in 0.6: Shortcodes\Chat and Abilities\Register use both, and
 * Toolkit\SummarizeToolkit uses Errors, none of them from a route. PHPStan catches a stale
 * `use`; nothing else catches a stale docblock, and in this codebase the docblock carries the
 * argument. The scan covers what ships and what documents the plugin; the dated records under
 * docs/superpowers, docs/reviews and docs/research say what was true when they were written and
 * are not scanned.
 */

it('autoloads Errors and RateLimit from the plugin root namespace, and the Rest\ names are gone', function (): void {
    expect(class_exists(Errors::class))->toBeTrue()
        ->and(class_exists(RateLimit::class))->toBeTrue()
        ->and(class_exists('AlpacaBot\Rest\Errors'))->toBeFalse()
        ->and(class_exists('AlpacaBot\Rest\RateLimit'))->toBeFalse();
});

it('leaves no file that ships or documents the plugin naming Rest\Errors or Rest\RateLimit', function (): void {
    $root = dirname(__DIR__, 2);
    $files = ['docs/api.md', 'docs/hooks.md', 'phpcs.xml.dist', 'README.md', 'readme.txt'];
    foreach (['src', 'tests', 'bin'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array($file->getExtension(), ['php', 'ts', 'md', 'sh', 'txt'], true)) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    $stale = [];
    foreach ($files as $path) {
        if ($path === 'tests/Unit/NamespaceTest.php') {
            continue;
        }
        foreach (preg_split('~\R~', (string) file_get_contents($root . '/' . $path)) ?: [] as $n => $line) {
            if (preg_match('~Rest[\\\\/](?:Errors|RateLimit)\b~', $line) === 1) {
                $stale[] = $path . ':' . ($n + 1);
            }
        }
    }
    expect($stale)->toBe([]);
});
