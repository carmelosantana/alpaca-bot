#!/usr/bin/env bash
# Run the WordPress integration suite inside a container that has this checkout and a database.
#
# WPH_MODE picks which container. `harness` (the default) is the local wp-harness site, the
# machine Carmelo develops on; `wp-env` is @wordpress/env, which is what CI uses because it needs
# no wp-harness and can be pointed at a chosen WordPress version. Everything the two modes share
# is the suite itself: the same phpunit.integration.xml, the same tests/Integration/bootstrap.php
# and the same tests/Integration/wp-tests-config.php, so a pass here means the same thing in both.
#
# The suite needs no network beyond the database, and refuses one: tests/Integration/bootstrap.php
# turns down any WP_Http request a test did not stub, swaps a configured Ollama provider for
# one whose chat, stream, structured and models calls throw, and gives the plugin's own MCP client
# factory a builder that refuses, so a pass here is the same on a runner with no provider and no
# DNS. Those three are the doors it guards, which is not every door the plugin has:
# Factory::make() also builds WpAiClientProvider, and that one is passed through as built -- what
# keeps it off the wire is the fake model WpAiClientTest registers with core, not a guard. A
# caller that builds its own HTTP client is outside the guards too, and takes one from its test
# instead (Kanboard #4322). McpClientLiveTest builds a real one on purpose, to reach a real MCP
# server, and skips unless ALPACA_BOT_MCP_URL is set, which this script never passes into the
# container (the test's docblock says how to run it).
#
# The suite's PHPUnit 9.6 lives in tools/integration (its composer.json says why it is not the
# root's PHPUnit 13) and is installed here, on the host, on every run, as the root `prefix`
# script installs tools/strauss: a no-op when vendor matches the lock, and the only way a lock
# bump or a branch switch is picked up. It happens on the host because the harness's container
# runs as uid 33 and cannot write into the bind-mounted checkout (wp-env's runs as the host user
# and could, but one place is better than two), which is also why PHPUnit's cache is under /tmp
# (phpunit.integration.xml).
#
# Arguments are passed to phpunit, the same in both modes: `composer test:integration -- --filter
# Smoke`, or `bin/test-integration.sh --filter Smoke`. One leading `--` is dropped first, so
# `bin/test-integration.sh -- --filter Smoke` works too.
#
# WP_MULTISITE=1 runs the suite on a network: core's test bootstrap reads it and installs the
# test database as one. It is passed into the container in both modes, and only 0, 1 or unset is
# accepted. A network install refuses the default admin@localhost, so wp-env mode needs a domain
# with a dot as well: `WP_MULTISITE=1 WP_TESTS_DOMAIN=example.org`. CI runs the uninstall group
# this way once (.github/workflows/ci.yml), because its network test skips on a single site.
#
# WP_NO_CURL=1 runs PHP with `-d disable_functions=curl_init,curl_exec`, the configuration shared
# hosts ship, so Requests' Transport\Curl::test() answers false through its own function_exists()
# check, WordPress would send requests through Fsockopen, and web_fetch must refuse (Kanboard
# #4484) while the provider and MCP clients take Symfony's Native client (HttpTransport, Kanboard
# #4690). disable_functions rather than a PHP without ext-curl: both containers' PHP has cURL
# compiled in, not loaded from a shared .so, so there is no extension to leave out, and disabling
# the two functions is what those hosts do anyway (extension_loaded('curl') stays true there
# too). Only the php running phpunit gets the flag; core's install.php subprocess does not need
# it. The tests that need it are the no-curl group, which skips without it; tests that need a
# fetch to go through skip with it. Only 0, 1 or unset is accepted, like WP_MULTISITE. CI runs
# the group this way once (.github/workflows/ci.yml).
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
#
# Both modes set WPH_MODE inside the container (the literal mode, never a value from outside), so
# a test can tell it runs under this script: RawOptionWriteTest fails rather than skips there when
# it cannot load WP-CLI's Option_Command, since both containers ship WP-CLI.
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

# Spliced into the container's command line like the database name above and the domain below,
# so held to the values core reads: '1' is a network, and '0' or unset is a single site.
MULTISITE="${WP_MULTISITE:-}"
if [[ ! "$MULTISITE" =~ ^[01]?$ ]]; then
    echo "bin/test-integration.sh: WP_MULTISITE '$MULTISITE' must be 0, 1 or unset" >&2
    exit 1
fi

# Held to 0, 1 or unset like WP_MULTISITE. Only a fixed flag, never the value itself, reaches
# either container's command line.
NO_CURL="${WP_NO_CURL:-}"
if [[ ! "$NO_CURL" =~ ^[01]?$ ]]; then
    echo "bin/test-integration.sh: WP_NO_CURL '$NO_CURL' must be 0, 1 or unset" >&2
    exit 1
fi
PHP_FLAGS=()
if [ "$NO_CURL" = "1" ]; then
    PHP_FLAGS=(-d 'disable_functions=curl_init,curl_exec')
fi

# The domain is spliced into the single-quoted `sh -c` payload in wp-env mode, exactly like the
# database name, so it gets the same treatment: a value carrying a quote would close that quoting
# and run in the container. Both are developer-set, not untrusted input -- checking one and not
# the other is the part that was wrong. A host, optionally with a port, is all core wants here.
if [[ ! "$DOMAIN" =~ ^[A-Za-z0-9.-]+(:[0-9]+)?$ ]]; then
    echo "bin/test-integration.sh: WP_TESTS_DOMAIN '$DOMAIN' must match ^[A-Za-z0-9.-]+(:[0-9]+)?\$" >&2
    exit 1
fi

# Composer consumes the `--` of `composer test:integration -- --filter X` itself, but a direct
# `bin/test-integration.sh -- --filter X` keeps it, and phpunit reads it as the end of its
# options, so --filter became a test file name (Cannot open file "--filter"). Dropped here, once,
# before either mode builds its command line (Kanboard #4697).
if [ "${1:-}" = "--" ]; then
    shift
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
    # The php flags go through the same quoting, though they are fixed strings of this script's.
    PHP_ARGS=""
    for arg in ${PHP_FLAGS[@]+"${PHP_FLAGS[@]}"}; do
        PHP_ARGS="$PHP_ARGS '${arg//\'/\'\\\'\'}'"
    done
    exec pnpm exec wp-env run tests-cli --env-cwd="wp-content/plugins/$SLUG" -- \
        sh -c "WPH_MODE=wp-env WP_TESTS_DB_NAME='$DB_NAME' WP_TESTS_DOMAIN='$DOMAIN' WP_MULTISITE='$MULTISITE' \
            php$PHP_ARGS tools/integration/vendor/bin/phpunit -c phpunit.integration.xml$ARGS"
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
    -e WPH_MODE=harness -e WP_TESTS_DB_NAME="$DB_NAME" -e WP_TESTS_DOMAIN="$DOMAIN" -e WP_MULTISITE="$MULTISITE" \
    cli php ${PHP_FLAGS[@]+"${PHP_FLAGS[@]}"} tools/integration/vendor/bin/phpunit -c phpunit.integration.xml "$@"
