#!/usr/bin/env bash
# Fail unless every version marker agrees with $1 (or the plugin header when $1 is omitted).
#
# readme.txt's headers are compared only for a release version. A pre-release ("0.5.0-dev",
# anything with a `-`) leaves them alone on purpose: readme.txt still describes the shipped
# 0.4.17 — `Stable tag: 0.4.17`, `Requires PHP: 8.1`, `Requires at least: 6.4` — so the PHP 8.1
# audience keeps getting 0.4.x updates while the branch carries 0.5.0-dev.
#
# The support headers are checked against the plugin header rather than against $1, and they are
# checked here rather than left to Plugin Check because they are the release step easiest to
# forget: alpaca-bot.php refuses to run below PHP 8.4 and blocks activation, but wordpress.org's
# listing and its update API read readme.txt, so a release whose readme still says 8.1 offers
# itself to sites that cannot run it. The four readme headers are one decision — moving `Requires
# PHP` alone would tell the update API that *0.4.17* needs 8.4 and cut off the audience it was
# frozen for — so this fires for the release version and says nothing before it.
set -euo pipefail
cd "$(dirname "$0")/.."
hdr=$(grep -m1 '^Version:' alpaca-bot.php | awk '{print $2}')
want="${1:-$hdr}"
stable=$(grep -m1 '^Stable tag:' readme.txt | awk '{print $3}' || true)
pkg=$(node -p "require('./package.json').version")
php=$(grep -m1 "VERSION = '" src/Plugin.php | sed -E "s/.*'([^']+)'.*/\1/")
ok=1
# `=` separates label from value, not `:` — "Plugin::VERSION" contains colons of its own.
for pair in "header=$hdr" "package.json=$pkg" "Plugin::VERSION=$php"; do
  [ "${pair#*=}" = "$want" ] || { echo "MISMATCH ${pair%%=*}=${pair#*=} want=$want"; ok=0; }
done
case "$want" in
  *-*) ;;
  *)
    [ "$stable" = "$want" ] || { echo "MISMATCH readme Stable tag=$stable want=$want"; ok=0; }
    # Compared between the two files, not against $want: these are support floors, not versions,
    # and the plugin header is where each is decided.
    #
    # `Requires PHP` and `Requires at least` are the two optional headers here: WordPress treats
    # either as absent-means-no-floor, so a readme.txt or an alpaca-bot.php without one is a real
    # file and not a mistake this script should call a mismatch against "". A pair with a side
    # missing is therefore skipped, out loud, so a maintainer who drops or renames a header can
    # see that the check did not run.
    #
    # The `|| true` is what makes that possible: under `set -euo pipefail` (line 16) a `grep`
    # that matches nothing fails the pipeline, the assignment takes its status, and the script
    # dies right here having printed nothing — which a caller reads as a mismatch with no line
    # to act on. The guard below never ran.
    hdr_php=$(grep -m1 '^Requires PHP:' alpaca-bot.php | awk '{print $3}') || true
    rdm_php=$(grep -m1 '^Requires PHP:' readme.txt | awk '{print $3}') || true
    hdr_wp=$(grep -m1 '^Requires at least:' alpaca-bot.php | awk '{print $4}') || true
    rdm_wp=$(grep -m1 '^Requires at least:' readme.txt | awk '{print $4}') || true
    for triple in "Requires PHP|$hdr_php|$rdm_php" "Requires at least|$hdr_wp|$rdm_wp"; do
      IFS='|' read -r label from_hdr from_rdm <<<"$triple"
      if [ -z "$from_hdr" ] || [ -z "$from_rdm" ]; then
        absent=""
        [ -n "$from_hdr" ] || absent="alpaca-bot.php"
        [ -n "$from_rdm" ] || absent="${absent:+$absent and }readme.txt"
        echo "SKIP $label: absent from $absent, so the two files were not compared"
        continue
      fi
      [ "$from_rdm" = "$from_hdr" ] || { echo "MISMATCH readme $label=$from_rdm plugin header=$from_hdr"; ok=0; }
    done
    ;;
esac
[ "$ok" = 1 ] || exit 1
echo "versions ok: $want"
