#!/usr/bin/env bash
# Fail unless every version marker agrees with $1 (or the plugin header when $1 is omitted).
#
# readme.txt's `Stable tag` is compared only for a release version. A pre-release ("0.5.0-dev",
# anything with a `-`) leaves it alone on purpose: readme.txt keeps `Stable tag: 0.4.17` so the
# PHP 8.1 audience keeps getting 0.4.x updates while the branch carries 0.5.0-dev.
set -euo pipefail
cd "$(dirname "$0")/.."
hdr=$(grep -m1 '^Version:' alpaca-bot.php | awk '{print $2}')
want="${1:-$hdr}"
stable=$(grep -m1 '^Stable tag:' readme.txt | awk '{print $3}')
pkg=$(node -p "require('./package.json').version")
php=$(grep -m1 "VERSION = '" src/Plugin.php | sed -E "s/.*'([^']+)'.*/\1/")
ok=1
# `=` separates label from value, not `:` — "Plugin::VERSION" contains colons of its own.
for pair in "header=$hdr" "package.json=$pkg" "Plugin::VERSION=$php"; do
  [ "${pair#*=}" = "$want" ] || { echo "MISMATCH ${pair%%=*}=${pair#*=} want=$want"; ok=0; }
done
case "$want" in *-*) ;; *) [ "$stable" = "$want" ] || { echo "MISMATCH readme Stable tag=$stable want=$want"; ok=0; };; esac
[ "$ok" = 1 ] || exit 1
echo "versions ok: $want"
