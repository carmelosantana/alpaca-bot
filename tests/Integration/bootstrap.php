<?php

declare(strict_types=1);

// This suite's PHPUnit 9.6, wp-phpunit, the polyfills and the AlpacaBot\Tests\Integration\
// namespace all come from tools/integration (its composer.json says why it is not the root
// manifest: WordPress core's test library still targets PHPUnit 9, calling getName(false) and
// Util\Test::parseTestMethodAnnotations(), both removed in PHPUnit 10). The root
// vendor/autoload.php is deliberately not loaded: it would register PHPUnit 13's classes, Pest 5's
// dependency, on top of the PHPUnit 9.6 running this process.
$plugin = dirname(__DIR__, 2);
require_once $plugin . '/tools/integration/vendor/autoload.php';

// wp-phpunit exports its own location as WP_PHPUNIT__DIR from an autoloaded file and reads the
// config path from WP_PHPUNIT__TESTS_CONFIG, in this process and in the install.php subprocess
// its bootstrap spawns, which inherits the environment. WP_TESTS_DIR comes first so a host that
// ships core's test library itself is used instead: wp-env sets it to /wordpress-phpunit, the
// copy that matches the core version that environment installed, which is the whole point of a
// core-version matrix.
$tests = getenv('WP_TESTS_DIR') ?: (getenv('WP_PHPUNIT__DIR') ?: $plugin . '/tools/integration/vendor/wp-phpunit/wp-phpunit');
putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
// WP_PHPUNIT__TESTS_CONFIG is read by the wp-phpunit *package*'s own wp-tests-config.php shim,
// which only exists in the composer copy. Core's own bootstrap -- which is what WP_TESTS_DIR
// points at under wp-env -- looks instead for the WP_TESTS_CONFIG_FILE_PATH *constant* (not an
// environment variable) and otherwise takes the wp-tests-config.php sitting beside itself:
// wp-env's, which is not this suite's and would run it with WP_DEBUG off and uploads pointed at
// the site. Defining it here makes the same config file win wherever the test library came from,
// and core passes the path on to the install.php subprocess as an argument, so that agrees too.
if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
    define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
}

require_once $tests . '/includes/functions.php';

// The plugin is loaded the way core's test suite loads the plugin under test, not activated in
// the wordpress_tests database: wp-phpunit reinstalls WordPress there on every run, so an
// activation would not survive, and loading the main file is what an active plugin amounts to.
tests_add_filter('muplugins_loaded', static function () use ($plugin): void {
    require $plugin . '/alpaca-bot.php';
});

// ABSPATH is the running site's /var/www/html (wp-tests-config.php), and the fresh test install
// leaves upload_path empty, so wp_upload_dir() would resolve to the site's own wp-content/uploads:
// WP_UnitTestCase scans it on set_up() and any test that creates an attachment would write into
// the site's media library. Point uploads at the container's /tmp instead, the same writable,
// per-run location as PHPUnit's cache (uid 33 cannot write the bind-mounted checkout); the option
// is filtered rather than stored because wp-phpunit reinstalls the database on every run.
tests_add_filter('pre_option_upload_path', static fn(): string => '/tmp/alpaca-bot-integration/uploads');

// Not reaching the network is a property of the suite rather than of each test's care
// (Kanboard #4322). Two doors get a guard each, both here because both WPH_MODEs load this file
// (bin/test-integration.sh): WP_Http, and the Ollama provider Factory::make() builds. Two doors
// guarded is not every door. Factory::make() also builds WpAiClientProvider when `provider.kind`
// selects it, and the second guard passes that one through untouched (its reason is below, and it
// is not that its transport is covered); code that builds its own Symfony HttpClient, cURL handle
// or stream outside Provider\Factory is reached by neither guard, and a test for such a caller has
// to hand it a client of its own.
//
// WP_Http: a request no test stubbed is refused, naming the URL. At the last priority, so a
// test's own `pre_http_request` stub (ToolkitsTest, ShortcodesTest) answers first and this sees
// only what nobody answered.
tests_add_filter('pre_http_request', static function (mixed $pre, array $args, string $url): mixed {
    return $pre !== false
        ? $pre
        : new WP_Error('alpaca_bot_tests_offline', 'The integration suite makes no network requests; stub this one with pre_http_request: ' . $url);
}, PHP_INT_MAX, 3);
// The model provider: the configured Ollama provider talks through Symfony's HttpClient, which no
// `pre_http_request` sees, and ModelCatalog forgives a provider that fails, so a test that touched
// the catalog without TestCase::fakeProvider() made a real connection attempt to the stored
// `provider.base_url` and heard nothing back. It is swapped for OfflineProvider.
//
// What keeps fakeProvider() in charge is the `instanceof` test, not this priority: by the time a
// test's own filter has returned its fake, the value is no longer an OllamaProvider, so this guard
// passes it through whenever it runs (checked by moving it to PHP_INT_MAX, where HermeticTest
// stays green). PHP_INT_MIN -- genuinely first, and the mirror of the sibling guard's PHP_INT_MAX
// -- buys something narrower: the guard sees the provider exactly as Factory::make() built it,
// before any filter can wrap it in something this `instanceof` would no longer recognise.
//
// Only an OllamaProvider is swapped: a WpAiClientProvider is left exactly as built, which is not a
// claim that its transport is covered. It is left because WpAiClientTest asserts Factory::make()
// returns one, and what that provider reaches there is an in-test fake model registered with core.
tests_add_filter(
    'alpaca_bot/provider',
    static fn(mixed $provider): mixed => $provider instanceof \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider
        ? new \AlpacaBot\Tests\Integration\OfflineProvider()
        : $provider,
    PHP_INT_MIN,
);

require $tests . '/includes/bootstrap.php';
