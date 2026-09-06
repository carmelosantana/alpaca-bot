#!/usr/bin/env bash
# Rebuild vendor-prefixed/ from a clean production install (what the release zip ships).
set -euo pipefail
cd "$(dirname "$0")/.."
composer install --no-dev --no-interaction --optimize-autoloader
composer prefix
composer dump-autoload --no-dev --optimize
