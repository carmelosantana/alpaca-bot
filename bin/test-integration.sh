#!/usr/bin/env bash
# Run the WordPress integration suite inside a container that has this checkout and a database.
#
# WPH_MODE picks which container. `harness` (the default) is the local wp-harness site, the
# machine Carmelo develops on; `wp-env` is @wordpress/env, which is what CI uses because it needs
# no wp-harness and can be pointed at a chosen WordPress version. Everything the two modes share
# is the suite itself: the same phpunit.integration.xml, the same tests/Integration/bootstrap.php
# and the same tests/Integration/wp-tests-config.php, so a pass here means the same thing in both.
#
# The suite's PHPUnit 9.6 lives in tools/integration (its composer.json says why it is not the
# root's PHPUnit 13) and is installed here, on the host, on every run, as the root `prefix`
# script installs tools/strauss: a no-op when vendor matches the lock, and the only way a lock
# bump or a branch switch is picked up. It happens on the host because the harness's container
# runs as uid 33 and cannot write into the bind-mounted checkout (wp-env's runs as the host user
# and could, but one place is better than two), which is also why PHPUnit's cache is under /tmp
# (phpunit.integration.xml).
#
# Arguments are passed to phpunit: `composer test:integration -- --filter Smoke`.
#
# --- harness mode -------------------------------------------------------------------------
# The site is WPH_SITE (default alpaca10, the 0.5 harness site; never alpacabot, which mounts the
# 0.4 release). Its compose file bind-mounts this checkout at $PLUGIN and puts the cli service on
# the db service's network, so the suite reaches the database as "db". The test database is
# created on first run with the db container's own root credentials; wp-phpunit reinstalls
# WordPress into it (prefix wptests_) on every run, so the site's own database is never touched.
#
# --- wp-env mode --------------------------------------------------------------------------
# `wp-env start` has already built the tests environment from .wp-env.json, including a database
# of its own (tests-wordpress) that exists to be reinstalled, and core's matching test library at
# /wordpress-phpunit, which tests/Integration/bootstrap.php picks up through WP_TESTS_DIR. wp-env
# mounts the checkout under the *directory's* own name, not "alpaca-bot", so the working
# directory is derived rather than assumed -- on a runner it is the repository name, in a git
# worktree it is the worktree's. `wp-env run` passes no environment through, so the settings the
# suite reads arrive as a shell prefix inside the container instead of as `docker run -e`.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

MODE="${WPH_MODE:-harness}"
# The site is only used in harness mode, but its name is the domain default, so both settings
# are resolved in one place.
SITE="${WPH_SITE:-alpaca10}"
DB_NAME_DEFAULT=wordpress_tests
DOMAIN_DEFAULT="$SITE.wp.test"
if [ "$MODE" = "wp-env" ]; then
    DB_NAME_DEFAULT=tests-wordpress
    DOMAIN_DEFAULT=localhost
fi
DB_NAME="${WP_TESTS_DB_NAME:-$DB_NAME_DEFAULT}"
DOMAIN="${WP_TESTS_DOMAIN:-$DOMAIN_DEFAULT}"

# In harness mode the name is spliced into a CREATE DATABASE below (backtick-quoted) as well as
# into wp-tests-config.php, so a bad value should fail here, plainly, rather than as SQL. The
# hyphen is allowed because wp-env's own database is called tests-wordpress; that mode runs no
# SQL of its own, and a backtick-quoted identifier would take it anyway.
if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_-]+$ ]]; then
    echo "bin/test-integration.sh: WP_TESTS_DB_NAME '$DB_NAME' must match ^[A-Za-z0-9_-]+\$" >&2
    exit 1
fi

# The domain is spliced into the single-quoted `sh -c` payload in wp-env mode, exactly like the
# database name, so it gets the same treatment: a value carrying a quote would close that quoting
# and run in the container. Both are developer-set, not untrusted input -- checking one and not
# the other is the part that was wrong. A host, optionally with a port, is all core wants here.
if [[ ! "$DOMAIN" =~ ^[A-Za-z0-9.-]+(:[0-9]+)?$ ]]; then
    echo "bin/test-integration.sh: WP_TESTS_DOMAIN '$DOMAIN' must match ^[A-Za-z0-9.-]+(:[0-9]+)?\$" >&2
    exit 1
fi

composer install --working-dir=tools/integration --no-interaction

if [ "$MODE" = "wp-env" ]; then
    SLUG="$(basename "$PWD")"
    # Quoted for the shell inside the container, so a phpunit argument with a space survives.
    # The container's /bin/sh is dash, so the quoting has to be POSIX: single quotes protect
    # everything except a single quote itself, which is closed, escaped and reopened. bash's
    # printf %q was the wrong tool here -- for an argument holding a tab or a newline it emits
    # $'...' ANSI-C quoting, which bash understands and dash passes through literally ($'a\tb'
    # arrives as the four characters $a\tb). The loop also runs zero times with no arguments,
    # which is what the old `printf ' %q'` needed an explicit $# guard to avoid: printf runs its
    # format once even with nothing to substitute, handing phpunit one empty argument.
    ARGS=""
    for arg in "$@"; do
        ARGS="$ARGS '${arg//\'/\'\\\'\'}'"
    done
    exec pnpm exec wp-env run tests-cli --env-cwd="wp-content/plugins/$SLUG" -- \
        sh -c "WP_TESTS_DB_NAME='$DB_NAME' WP_TESTS_DOMAIN='$DOMAIN' \
            php tools/integration/vendor/bin/phpunit -c phpunit.integration.xml$ARGS"
fi

if [ "$MODE" != "harness" ]; then
    echo "bin/test-integration.sh: WPH_MODE '$MODE' is not one of: harness, wp-env" >&2
    exit 1
fi

COMPOSE="$HOME/Sites/$SITE/.harness/compose.yml"
PLUGIN=/var/www/html/wp-content/plugins/alpaca-bot

if [ ! -f "$COMPOSE" ]; then
    echo "bin/test-integration.sh: no harness site '$SITE' ($COMPOSE missing); set WPH_SITE, set WPH_MODE=wp-env, or run: wph site start $SITE" >&2
    exit 1
fi

# The cli service only starts db through depends_on when it runs; the exec below needs db up
# and healthy first, and a stopped site should not fail with a bare "service db is not running".
docker compose -f "$COMPOSE" up -d --wait db

docker compose -f "$COMPOSE" exec -T -e DB_NAME="$DB_NAME" db sh -c \
    'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`; GRANT ALL ON \`$DB_NAME\`.* TO \"$MARIADB_USER\"@\"%\";"'

docker compose -f "$COMPOSE" run --rm -T -w "$PLUGIN" \
    -e WP_TESTS_DB_NAME="$DB_NAME" -e WP_TESTS_DOMAIN="$DOMAIN" \
    cli php tools/integration/vendor/bin/phpunit -c phpunit.integration.xml "$@"
