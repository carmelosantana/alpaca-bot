<?php

declare(strict_types=1);

// Read by wp-phpunit's bootstrap (and its install.php subprocess) in place of wp-config.php, so
// the site's own wp-config.php, WORDPRESS_CONFIG_EXTRA included, is never loaded: a constant it
// defines (OLLAMA_API_URL on the harness sites) is absent here, and a test that needs a provider
// hands one in through the alpaca_bot/provider filter, as the unit suite does.
//
// The database credentials are the cli container's own WORDPRESS_DB_* environment; only the
// database name differs, so wp-phpunit's per-run reinstall (prefix wptests_) lands in
// wordpress_tests and the site's database is never touched. The `db` fallback is the compose
// service alias, which resolves on the site's network whatever the container is called.
define('ABSPATH', '/var/www/html/');
define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_tests');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'wordpress');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'wordpress');
define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

// bin/test-integration.sh derives the domain from WPH_SITE; the default is the 1.0 harness site.
define('WP_TESTS_DOMAIN', getenv('WP_TESTS_DOMAIN') ?: 'alpaca10.wp.test');
define('WP_TESTS_EMAIL', 'admin@' . WP_TESTS_DOMAIN);
define('WP_TESTS_TITLE', 'Alpaca Bot tests');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
