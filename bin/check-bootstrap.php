#!/usr/bin/env php
<?php
/**
 * Loads alpaca-bot.php the way WordPress would (ABSPATH defined, the few WP functions the
 * bootstrap calls stubbed) and asserts what the runtime autoloads: the plugin's own classes
 * and the strauss-prefixed vendor copies resolve, the unprefixed originals do not, Composer's
 * vendor/ loader is not registered, and no unprefixed vendor function was defined.
 *
 * Guards the Global Constraint "the plugin never autoloads vendor/ at runtime". Never loads
 * vendor/autoload.php itself, so a negative here means what it says. Run from anywhere:
 * `php bin/check-bootstrap.php`; exits 1 on the first failed check.
 */

declare(strict_types=1);

define('ABSPATH', '/');

/** @var list<array{0: string, 1: int}> */
$GLOBALS['alpaca_bot_check_actions'] = [];
function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}
function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/';
}
function add_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['alpaca_bot_check_actions'][] = [$hook, $priority];
}
function esc_html__(string $text, string $domain = 'default'): string
{
    return $text;
}

require dirname(__DIR__) . '/alpaca-bot.php';

$checks = [
    'AlpacaBot\Plugin resolves (src/ loader)' => class_exists(\AlpacaBot\Plugin::class),
    'AlpacaBot\Chat\Pipeline resolves (nested namespace)' => class_exists(\AlpacaBot\Chat\Pipeline::class),
    'AlpacaBot\Vendor\...PHPAgents\Message\UserMessage resolves (vendor-prefixed/)' => class_exists('AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage'),
    'AlpacaBot\Vendor\League\CommonMark\CommonMarkConverter resolves (vendor-prefixed/)' => class_exists('AlpacaBot\Vendor\League\CommonMark\CommonMarkConverter'),
    'unprefixed CarmeloSantana\PHPAgents\Message\UserMessage does NOT resolve' => !class_exists('CarmeloSantana\PHPAgents\Message\UserMessage'),
    'unprefixed League\CommonMark\CommonMarkConverter does NOT resolve' => !class_exists('League\CommonMark\CommonMarkConverter'),
    'unprefixed Symfony\Component\HttpClient\HttpClient does NOT resolve' => !class_exists('Symfony\Component\HttpClient\HttpClient'),
    'unprefixed Psr\Log\LoggerInterface does NOT resolve' => !interface_exists('Psr\Log\LoggerInterface'),
    'Composer\Autoload\ClassLoader (vendor/) is NOT loaded' => !class_exists('Composer\Autoload\ClassLoader', false),
    'unprefixed trigger_deprecation() is NOT defined' => !function_exists('trigger_deprecation'),
    'Plugin::boot() hooked plugins_loaded at 9' => in_array(['plugins_loaded', 9], $GLOBALS['alpaca_bot_check_actions'], true),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}
echo PHP_EOL . 'autoloaders registered: ' . count(spl_autoload_functions()) . ' (' . implode(', ', array_map(
    static fn(mixed $fn): string => is_array($fn) ? (is_object($fn[0]) ? $fn[0]::class : $fn[0]) . '::' . $fn[1] : (is_object($fn) ? 'closure in ' . (new \ReflectionFunction($fn))->getFileName() : (string) $fn),
    spl_autoload_functions(),
)) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
