<?php

declare(strict_types=1);

// This suite's PHPUnit, wp-phpunit, the polyfills and the AlpacaBot\Tests\Integration\ namespace
// all come from tools/integration (its composer.json says why it is not the root manifest).
// The root vendor/autoload.php is deliberately not loaded: it would register PHPUnit 13's
// classes, Pest 5's dependency, on top of the PHPUnit 12 running this process.
$plugin = dirname(__DIR__, 2);
require_once $plugin . '/tools/integration/vendor/autoload.php';

// wp-phpunit exports its own location as WP_PHPUNIT__DIR from an autoloaded file and reads the
// config path from WP_PHPUNIT__TESTS_CONFIG, in this process and in the install.php subprocess
// its bootstrap spawns, which inherits the environment.
$tests = getenv('WP_TESTS_DIR') ?: (getenv('WP_PHPUNIT__DIR') ?: $plugin . '/tools/integration/vendor/wp-phpunit/wp-phpunit');
putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');

require_once $tests . '/includes/functions.php';

// The plugin is loaded the way core's test suite loads the plugin under test, not activated in
// the wordpress_tests database: wp-phpunit reinstalls WordPress there on every run, so an
// activation would not survive, and loading the main file is what an active plugin amounts to.
tests_add_filter('muplugins_loaded', static function () use ($plugin): void {
    require $plugin . '/alpaca-bot.php';
});

require $tests . '/includes/bootstrap.php';
