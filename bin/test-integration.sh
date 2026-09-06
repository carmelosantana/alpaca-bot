#!/usr/bin/env bash
# Run the WordPress integration suite inside the harness site's cli container.
#
# The site is WPH_SITE (default alpaca10, the 1.0 harness site; never alpacabot, which mounts
# the 0.4 release). Its compose file bind-mounts this checkout at $PLUGIN and puts the cli
# service on the db service's network, so the suite reaches the database as "db".
#
# The suite's PHPUnit lives in tools/integration (its composer.json says why it is not the
# root's) and is installed here, on the host, when missing: the container runs as uid 33 and
# cannot write into the bind-mounted checkout, which is also why PHPUnit's cache is under /tmp
# (phpunit.integration.xml). The test database is created on first run with the db container's
# own root credentials; wp-phpunit reinstalls WordPress into it (prefix wptests_) on every run,
# so the site's own database is never touched.
#
# Arguments are passed to phpunit: `composer test:integration -- --filter Smoke`.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

SITE="${WPH_SITE:-alpaca10}"
COMPOSE="$HOME/Sites/$SITE/.harness/compose.yml"
PLUGIN=/var/www/html/wp-content/plugins/alpaca-bot
DB_NAME="${WP_TESTS_DB_NAME:-wordpress_tests}"

if [ ! -f "$COMPOSE" ]; then
    echo "bin/test-integration.sh: no harness site '$SITE' ($COMPOSE missing); set WPH_SITE or run: wph site start $SITE" >&2
    exit 1
fi
if [ ! -d tools/integration/vendor ]; then
    composer install --working-dir=tools/integration --no-interaction
fi

docker compose -f "$COMPOSE" exec -T -e DB_NAME="$DB_NAME" db sh -c \
    'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS $DB_NAME; GRANT ALL ON $DB_NAME.* TO \"$MARIADB_USER\"@\"%\";"'

docker compose -f "$COMPOSE" run --rm -T -w "$PLUGIN" \
    -e WP_TESTS_DB_NAME="$DB_NAME" -e WP_TESTS_DOMAIN="$SITE.wp.test" \
    cli php tools/integration/vendor/bin/phpunit -c phpunit.integration.xml "$@"
