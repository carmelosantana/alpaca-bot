#!/usr/bin/env bash
# Rebuild vendor-prefixed/ from a clean production install: what the release zip ships next to
# src/. `composer install` fires post-install-cmd -> @prefix, which installs strauss from its
# own manifest (tools/strauss) and runs it, once. Nothing else is needed: the runtime loads
# vendor-prefixed/autoload.php plus its own src/ loader (see alpaca-bot.php) and never vendor/,
# so vendor/ is a build-time input here, not an artifact.
set -euo pipefail
cd "$(dirname "$0")/.."
composer install --no-dev --no-interaction
php bin/check-bootstrap.php
echo "vendor/ is now a --no-dev install; run 'composer install' to get the dev tools back."
