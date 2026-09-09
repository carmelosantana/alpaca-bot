#!/usr/bin/env bash
# Build dist/alpaca-bot.zip exactly as CI does: the same script the release workflow calls, so a
# local dry run and a tagged release produce the same artifact from the same tree.
#
# Order matters. The version guard runs first (a mismatched marker is cheap to catch and
# expensive to ship), then vendor-prefixed/ is rebuilt from a clean --no-dev install, then the
# front-end build produces assets/js and assets/css — which are gitignored, so a zip cut without
# this step would ship no JS and no CSS at all. .distignore drops everything else.
#
# bin/build-vendor.sh leaves vendor/ as a --no-dev install; dev dependencies are restored at the
# end so `composer check` still runs in the working tree afterwards.
set -euo pipefail
cd "$(dirname "$0")/.."

bash bin/version-check.sh
bash bin/build-vendor.sh
pnpm install --frozen-lockfile
pnpm build

rm -rf dist && mkdir -p dist/alpaca-bot
rsync -a --exclude-from=.distignore --exclude dist ./ dist/alpaca-bot/

# Reproducible: a zip records mtimes and whatever order the filesystem hands back, and the
# front-end build rewrites assets/ on every run — so the same tree would otherwise produce a
# different file each time. Stamp every entry with SOURCE_DATE_EPOCH (the HEAD commit date),
# feed zip a sorted list, and -X drops the uid/gid/extra-attribute records.
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct 2>/dev/null || echo 0)}"
find dist/alpaca-bot -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +
(cd dist && find alpaca-bot -print | LC_ALL=C sort | zip -qX alpaca-bot.zip -@)

composer install --no-interaction >/dev/null   # restore dev deps

# The zip is the artifact everything downstream tests, so assert its contents rather than
# eyeballing them: every runtime path present, nothing from the dev toolchain.
# The list is fed to grep by herestring, never `printf | grep -q`: -q closes the pipe on the
# first match, the writer takes SIGPIPE, and `pipefail` then reports a found path as missing.
list=$(unzip -Z1 dist/alpaca-bot.zip)
fail=0
for path in \
  alpaca-bot/alpaca-bot.php \
  alpaca-bot/readme.txt \
  alpaca-bot/LICENSE.md \
  alpaca-bot/src/Plugin.php \
  alpaca-bot/vendor-prefixed/autoload.php \
  alpaca-bot/assets/js/chat.js \
  alpaca-bot/assets/js/htmx.min.js \
  alpaca-bot/assets/css/alpaca-bot.css \
  alpaca-bot/assets/css/alpaca-bot-shortcode.css \
  alpaca-bot/assets/img/icons.svg \
  alpaca-bot/assets/img/icon-80.png
do
  grep -qxF -- "$path" <<<"$list" || { echo "MISSING $path"; fail=1; }
done
for pattern in \
  '^alpaca-bot/vendor/' \
  '^alpaca-bot/node_modules/' \
  '^alpaca-bot/tests/' \
  '^alpaca-bot/bin/' \
  '^alpaca-bot/tools/' \
  '^alpaca-bot/resources/' \
  '^alpaca-bot/dist/' \
  '(^|/)\.'
do
  if grep -qE -- "$pattern" <<<"$list"; then
    echo "UNEXPECTED entries matching $pattern:"
    grep -E -- "$pattern" <<<"$list" | head
    fail=1
  fi
done
[ "$fail" = 0 ] || { echo "zip contents check FAILED"; exit 1; }

ls -la dist/alpaca-bot.zip
echo "zip contents check ok: $(wc -l <<<"$list") entries"
