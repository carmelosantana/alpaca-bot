<?php

declare(strict_types=1);

// Read by wp-phpunit's bootstrap (and its install.php subprocess) in place of wp-config.php, so
// the site's own wp-config.php, WORDPRESS_CONFIG_EXTRA included, is never loaded: a constant it
// defines (OLLAMA_API_URL on the harness sites) is absent here, and a test that needs a provider
// hands one in through the alpaca_bot/provider filter, as the unit suite does.
//
// This file is authoritative in both of bin/test-integration.sh's modes. Under wp-env the test
// library is core's own at /wordpress-phpunit, which ships a wp-tests-config.php beside itself
// that core's bootstrap would otherwise take; tests/Integration/bootstrap.php defines
// WP_TESTS_CONFIG_FILE_PATH so this file wins wherever the test library came from.
//
// Everything that differs between the two containers arrives as environment rather than being
// written here. The database credentials are the container's own WORDPRESS_DB_* (the `db`
// fallback is the harness compose service alias, which resolves on the site's network whatever
// the container is called); only the database name differs from the site's, so wp-phpunit's
// per-run reinstall (prefix wptests_) lands in a database of its own -- wordpress_tests on the
// harness, tests-wordpress under wp-env -- and no site database is ever touched.
//
// ABSPATH is the WordPress the container serves: the harness site's own copy on the shared `wp`
// volume, or the core version wp-env installed for this matrix leg. Only the database is
// separate. The one thing on that filesystem tests would write to, wp-content/uploads, is
// redirected to /tmp by bootstrap.php (upload_path), so no media library is touched either.
define('ABSPATH', '/var/www/html/');
define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_tests');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'wordpress');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'wordpress');
define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

// bin/test-integration.sh always passes WP_TESTS_DOMAIN: the harness site's own host (WPH_SITE,
// default alpaca10) or `localhost` under wp-env, where the site is localhost:8889. The literal
// below is only the fallback for running phpunit by hand without that script.
define('WP_TESTS_DOMAIN', getenv('WP_TESTS_DOMAIN') ?: 'alpaca10.wp.test');
define('WP_TESTS_EMAIL', 'admin@' . WP_TESTS_DOMAIN);
define('WP_TESTS_TITLE', 'Alpaca Bot tests');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
