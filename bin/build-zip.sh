#!/usr/bin/env bash
# Build dist/alpaca-bot.zip exactly as CI does: the same script the release workflow calls, so a
# local dry run and a tagged release produce the same artifact from the same tree.
#
# Order matters. The version guard runs first (a mismatched marker is cheap to catch and
# expensive to ship), then vendor-prefixed/ is rebuilt from a clean --no-dev install, then the
# front-end build produces assets/js and assets/css — which are gitignored, so a zip cut without
# this step would ship no JS and no CSS at all. .distignore drops everything else.
#
# bin/build-vendor.sh leaves vendor/ as a --no-dev install, so the restore is an EXIT trap armed
# before it runs: dev dependencies come back on every path — success, a failed pnpm build, a
# failed content assertion, an interrupt — and `composer check` still runs in the working tree
# afterwards. A plain statement at the end would only hold on the happy path.
set -euo pipefail
cd "$(dirname "$0")/.."

bash bin/version-check.sh

# Armed before the --no-dev install, not after it: every step from here to the last assertion
# below (pnpm install, pnpm build, rm -rf, rsync, zip, the contents check) can fail under
# `set -e`, and each one would otherwise strand the tree on a --no-dev vendor/. The EXIT trap
# does not change the script's exit status, so a real failure still surfaces as itself.
trap 'composer install --no-interaction >/dev/null || echo "WARNING: dev dependency restore failed; run composer install"' EXIT

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

# The zip is the artifact everything downstream tests, so assert its contents rather than
# eyeballing them: every runtime path present, nothing from the dev toolchain.
# The list is fed to grep by herestring, never `printf | grep -q`: -q exits on the first match
# and closes the pipe, and the writer is killed if it is still writing. Not hypothetically, on
# this artifact. 911 entries is 68,398 bytes against a 65,536-byte pipe -- just over it, and
# measured here `printf '%s\n' "$list" | grep -qxF alpaca-bot/alpaca-bot.php` still wins the
# race 40 times out of 40, because grep finds its match and exits before the writer notices.
# Six copies of the same listing, 410,388 bytes, loses it 20 times out of 20: `pipefail` hands
# back 141 and a path that IS in the zip is reported MISSING. What decides it is bytes still in
# flight, not how many entries matched. So there is no match count to reason from, and the
# margin this build has is a few kilobytes of growth wide, not a design. The failure
# arrives when the artifact grows rather than when this line is edited. A herestring has no
# writer to kill.
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
  alpaca-bot/assets/img/alpaca-bot-avatar.png
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
    # Herestring again, and for the same reason. `grep ... | head` is the same race, and the ten
    # lines head reads are not what decides it: head exits after them, and grep dies only if it
    # is still writing by then. On this artifact's 68,398 bytes it survives; on six copies of it
    # `grep -E '^alpaca-bot/vendor-prefixed/' | head` dies at 141 in 18 runs of 20. The flip
    # moves with line width, not match count (measured elsewhere in this phase at 510 matches of
    # 22 bytes, 339 of 40, 275 of 126) — a small pipeline is not safe by being small, it is safe
    # by being under the pipe, and this one is not far under it. Because this is a plain command
    # in an `if` body rather
    # than a condition, `pipefail` plus `set -e` would abort the script on that 141 — skipping
    # fail=1, the FAILED message and exit 1, inside the one branch whose whole job is to print
    # a diagnostic.
    head <<<"$(grep -E -- "$pattern" <<<"$list")"
    fail=1
  fi
done
# Every classmap entry has to name a file the zip actually holds. Composer classmaps a vendored
# package by scanning it, so anything .distignore drops out of one is still named in
# vendor-prefixed/composer/autoload_classmap.php — an entry pointing at nothing, which is a fatal
# the first time something asks for that class. The check runs over dist/alpaca-bot, the staged
# tree the zip is built from by `find` above, so its file set and the zip's entry set are the same
# one. autoload_static.php carries a copy of the same list (composer writes both from one scan;
# on this tree the two agree entry for entry), so checking the array file checks both.
# The $ signs in the snippet below are PHP variables, so it is single-quoted and the shell must
# not expand them.
# shellcheck disable=SC2016
if ! php -r '
$map = require "dist/alpaca-bot/vendor-prefixed/composer/autoload_classmap.php";
$missing = array_filter($map, static fn(string $f): bool => !is_file($f));
foreach ($missing as $class => $file) { echo "DANGLING CLASSMAP $class => $file\n"; }
exit($missing === [] ? 0 : 1);
'; then
  echo "the zip ships an autoload classmap naming files it does not contain"
  fail=1
fi

[ "$fail" = 0 ] || { echo "zip contents check FAILED"; exit 1; }

ls -la dist/alpaca-bot.zip
echo "zip contents check ok: $(wc -l <<<"$list") entries"
