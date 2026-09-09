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

require $tests . '/includes/bootstrap.php';
