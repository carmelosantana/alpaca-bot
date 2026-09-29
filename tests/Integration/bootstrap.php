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
// (Kanboard #4322). Three doors get a guard each, all here because both WPH_MODEs load this file
// (bin/test-integration.sh): WP_Http, the Ollama provider Factory::make() builds, and the MCP
// client the plugin's own Mcp\ClientFactory builds. Three doors guarded is not every door.
// Factory::make() also builds WpAiClientProvider when `provider.kind` selects it, and the second
// guard passes that one through untouched (its reason is below, and it is not that its transport
// is covered); a Mcp\ClientFactory a test constructs with no builder of its own builds the real
// client, which the third guard does not reach; and code that builds its own Symfony HttpClient,
// cURL handle or stream outside Provider\Factory is reached by none of them, and a test for such a
// caller has to hand it a client of its own. McpClientLiveTest builds a real one on purpose, to
// reach a real MCP server, and skips unless ALPACA_BOT_MCP_URL is set, which nothing here sets.
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
// At PHP_INT_MIN this guard runs before TestCase::fakeProvider()'s filter, and swaps the provider
// first. What keeps fakeProvider() in charge is that its callback ignores the value it is handed
// and returns its fake: made to pass a provider it is handed through, it hands on OfflineProvider,
// and ChatRoutesTest fails. The `instanceof` test decides nothing about the fake there (made to
// swap whatever it is given, it leaves ChatRoutesTest and HermeticTest green); it would decide
// only if this guard ran after the test's filter, as at PHP_INT_MAX, where the fake is no longer
// an OllamaProvider and is passed through. PHP_INT_MIN -- genuinely first, and the mirror of the
// sibling guard's PHP_INT_MAX -- buys something narrower: the guard sees the provider exactly as
// Factory::make() built it, before any filter can wrap it in something this `instanceof` would no
// longer recognise.
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

// The MCP client: the ClientFactory Plugin::register() puts in the container (at plugins_loaded
// 9) builds php-agents' client over Mcp\Egress, which talks through Symfony's HttpClient, where no
// `pre_http_request` sees it, to whatever address a stored server row names. Mcp\Toolkits holds
// that one instance from register() on, and the view routes' Mcp\Discovery is handed it when they
// are built, so the instance itself is changed rather than the container's entry: right after
// register(), its builder is replaced with one that throws McpUnavailable with
// TestCase::OFFLINE_MCP and builds nothing. McpToolkit reads that as a server with no tools, and
// the Discover route prints it in its notice. A test that means to list a server hands in a
// ClientFactory with a builder of its own (FakeClient), or swaps the container's entry for one, as
// McpSettingsTest does.
tests_add_filter('plugins_loaded', static function (): void {
    \Closure::bind(
        function (): void {
            $this->build = static fn(\AlpacaBot\Mcp\ServerConfig $server): never => throw new \AlpacaBot\Mcp\McpUnavailable(\AlpacaBot\Tests\Integration\TestCase::OFFLINE_MCP);
        },
        \AlpacaBot\Plugin::instance()->get(\AlpacaBot\Mcp\ClientFactory::class),
        \AlpacaBot\Mcp\ClientFactory::class,
    )();
}, 10);

require $tests . '/includes/bootstrap.php';
