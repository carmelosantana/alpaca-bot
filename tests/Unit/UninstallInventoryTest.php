<?php

declare(strict_types=1);

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\UsageMeter;

/**
 * uninstall.php names everything it removes literally (it cannot load a class), so nothing ties
 * its list to the code that writes the rows. This is the tie: every quoted `alpaca_bot_*` or
 * `ab_*` string in src/ is either quoted in uninstall.php or listed below with the reason it is
 * not stored under its own name, and the kinds of storage uninstall.php does not look at all
 * (site options, site transients, a post type or cron event it does not name) are counted, so a
 * new one fails here until someone decides what uninstall does with it.
 *
 * It checks names, not rows: tests/Integration/UninstallTest.php is what shows the routine
 * removes them.
 */

function uninstallSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
}

/** @return array<string, list<string>> every src/ PHP file's contents, by path */
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
 * Quoted strings in src/ that begin `alpaca_bot_` or `ab_`, cut at the first character a stored
 * name cannot hold here: `'alpaca_bot_rl_%s_%s_%s'` is the prefix `alpaca_bot_rl_`.
 *
 * @return array<string, list<string>> name => the files it is in
 */
function uninstallSrcNames(): array
{
    $names = [];
    foreach (uninstallSrcFiles() as $path => $source) {
        preg_match_all('/([\'"])((?:alpaca_bot_|ab_)[^\'"]*)\1/', $source, $m);
        foreach ($m[2] as $literal) {
            preg_match('/^[A-Za-z0-9_]+/', $literal, $name);
            $names[$name[0]][] = $path;
        }
    }
    ksort($names);
    return $names;
}

/** Quoted in src/ and deliberately absent from uninstall.php, each with why. */
const UNINSTALL_NOT_STORED_UNDER_ITS_OWN_NAME = [
    'alpaca_bot_' => 'Migrate04::LEGACY_PREFIX: the 0.4.17 options it reads are listed whole',
    'ab_messages' => 'post meta on chat_history posts, removed with the posts',
    'ab_migration_attempts' => 'post meta on chat_history posts, removed with the posts',
    'alpaca_bot_access' => 'a settings section id on the settings screen',
    'alpaca_bot_settings_end' => 'a marker in the settings form',
    'alpaca_bot_nonce' => 'a heartbeat payload key',
    'alpaca_bot_web_fetch' => 'a Site Health test id',
    'alpaca_bot_bad_request' => 'an error code',
    'alpaca_bot_cap_exceeded' => 'an error code',
    'alpaca_bot_mcp_address' => 'an error code',
    'alpaca_bot_mcp_row' => 'an error code',
    'alpaca_bot_method_not_allowed' => 'an error code',
    'alpaca_bot_not_found' => 'an error code',
    'alpaca_bot_provider_error' => 'an error code',
    'alpaca_bot_rate_limited' => 'an error code',
    'alpaca_bot_stream_concurrency' => 'an error code',
    'alpaca_bot_stream_timeout' => 'an error code',
    'alpaca_bot_tool_error' => 'an error code',
    'alpaca_bot_toolkit_disabled' => 'an error code',
];

it('names every alpaca_bot_ and ab_ string in src/ that it does not list as not stored', function (): void {
    $uninstall = uninstallSource();
    $missing = [];
    foreach (uninstallSrcNames() as $name => $paths) {
        if (!array_key_exists($name, UNINSTALL_NOT_STORED_UNDER_ITS_OWN_NAME) && !str_contains($uninstall, "'" . $name . "'")) {
            $missing[] = $name . ' (' . implode(', ', array_unique($paths)) . ')';
        }
    }
    expect($missing)->toBe([], 'uninstall.php does not name these; remove them there, or list them here with the reason they are not stored');
});

it('lists nothing as not stored that src/ no longer has, or that uninstall.php names after all', function (): void {
    $names = uninstallSrcNames();
    $uninstall = uninstallSource();
    foreach (array_keys(UNINSTALL_NOT_STORED_UNDER_ITS_OWN_NAME) as $name) {
        expect(array_key_exists($name, $names))->toBeTrue("$name is not in src/ any more");
        expect(str_contains($uninstall, "'" . $name . "'"))->toBeFalse("$name is both removed and listed as not stored");
    }
});

it('names the post types and the cron hook, and there are no others', function (): void {
    $uninstall = uninstallSource();
    foreach ([ConversationStore::POST_TYPE, UsageMeter::POST_TYPE, UsageMeter::CLEANUP_HOOK] as $name) {
        expect(str_contains($uninstall, "'" . $name . "'"))->toBeTrue("uninstall.php does not name $name");
    }
    $count = static fn(string $pattern): int => array_sum(array_map(static fn(string $s): int => preg_match_all($pattern, $s), uninstallSrcFiles()));
    expect($count('/\bregister_post_type\s*\(/'))->toBe(2, 'a new post type: add its name to uninstall.php, then this count');
    expect($count('/\bwp_schedule_(?:single_)?event\s*\(/'))->toBe(1, 'a new cron event: add its hook to uninstall.php, then this count');
    expect($count('/\b(?:add_role|add_cap)\s*\(/'))->toBe(0, 'the plugin now changes roles: uninstall.php must undo it');
    expect($count('/\b(?:set_site_transient|update_site_option|add_site_option|update_network_option|add_network_option)\s*\(/'))
        ->toBe(0, 'the plugin now stores network-wide: uninstall.php removes no site option or site transient');
    expect($count('/\bregister_taxonomy\s*\(/'))->toBe(0, 'a taxonomy: uninstall.php deletes posts in SQL and leaves term rows alone');
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
