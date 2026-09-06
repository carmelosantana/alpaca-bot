# Alpaca Bot 1.0 P5: Hardening and Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make 1.0 shippable: CI that runs the same checks a wordpress.org reviewer would (standards, static analysis, unit + integration tests, Plugin Check), a tag-driven release that builds the zip, publishes the GitHub release, and deploys to SVN, plus the security audit, performance baseline, deep code review, and readme/assets work that close the remaining Board 17 tasks.

**Architecture:** Three workflows: `ci.yml` (every push/PR), `plugin-check.yml` (PRs, against a built zip in wp-env), `release.yml` (`v*` tags; reuses the checks, builds with `bin/build-vendor.sh` + `pnpm build`, packs with `.distignore`, creates the GitHub release, deploys with `10up/action-wordpress-plugin-deploy`, assets from `.wordpress-org/`). Locally the same commands run through composer/pnpm scripts. Review tasks are executed as subagent reviews whose findings become Kanboard tickets and fixes on the branch.

**Tech Stack:** GitHub Actions, `@wordpress/env`, WordPress Plugin Check (`plugin-check` plugin + `wp plugin check`), PHPCS 3 + WordPress-Coding-Standards 3.1 + PHPCompatibilityWP, PHPStan 2 (from P1), Pest, Playwright (one smoke e2e), `10up/action-wordpress-plugin-deploy@v2`, SVN.

**Spec:** `docs/superpowers/specs/2026-09-05-alpaca-bot-release-and-quality-gates.md` and `2026-09-05-alpaca-bot-1-0-core-refactor.md` (sequencing steps 8-9). Board tasks closed here: Kanboard #2950 (plugin review automation), #2944 (security audit), #2947 (performance), #2953 (deep code review).

> **Corrections from P1 execution (2026-09-05):** strauss ^0.29 and pest ^5 cannot share one lockfile (conflicting `psr/simple-cache` majors). strauss lives in `tools/strauss/composer.json`; `bin/build-vendor.sh` and every CI step below must install and run it from there (`composer --working-dir=tools/strauss install && tools/strauss/vendor/bin/strauss`), not via a root `composer prefix` script. The `composer audit` step should run for both manifests.

## Global Constraints

- P1-P4 constraints hold. Branch `1.0` until the release PR merges to `main`; tags are cut from `main`.
- Version consistency: `alpaca-bot.php` `Version:`, `readme.txt` `Stable tag`, `package.json` `version`, and `Plugin::VERSION` must match the tag (without `v`); a script enforces it.
- The release zip contains: `alpaca-bot.php`, `src/`, `vendor/` (plugin PSR-4 autoload only), `vendor-prefixed/`, `assets/` (built), `readme.txt`, `LICENSE.md`, `languages/` (if any). It excludes everything in `.distignore`.
- SVN deploy is disabled (`dry-run: true`) until the `v1.0.0` tag; `v1.0.0-beta.*` tags produce GitHub pre-releases only.
- Secrets: `SVN_USERNAME`, `SVN_PASSWORD` as repository secrets set by Carmelo; never printed. Manual SVN only via the harness break-glass tools (Kanboard #2966).

---

### Task 1: Standards and static analysis locally (PHPCS + PHPCompatibility + PHPStan already present)

**Files:**
- Create: `phpcs.xml.dist`, `.editorconfig`
- Modify: `composer.json` (require-dev `wp-coding-standards/wpcs ^3.1`, `phpcompatibility/phpcompatibility-wp ^2.1`, `dealerdirect/phpcodesniffer-composer-installer ^1.0` with `allow-plugins`; scripts `lint`, `lint:fix`)

- [ ] **Step 1:** `phpcs.xml.dist`:
```xml
<?xml version="1.0"?>
<ruleset name="Alpaca Bot">
    <description>WordPress Coding Standards for Alpaca Bot 1.0 (PSR-4, strict types).</description>
    <file>alpaca-bot.php</file>
    <file>src</file>
    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>vendor-prefixed/*</exclude-pattern>
    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="sp"/>
    <config name="minimum_wp_version" value="6.9"/>
    <config name="testVersion" value="8.4-"/>
    <rule ref="WordPress-Extra">
        <exclude name="WordPress.Files.FileName"/>
        <exclude name="WordPress.NamingConventions.ValidVariableName"/>
        <exclude name="WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid"/>
        <exclude name="Universal.Arrays.DisallowShortArraySyntax"/>
        <exclude name="Generic.Arrays.DisallowShortArraySyntax"/>
        <exclude name="WordPress.PHP.YodaConditions"/>
        <exclude name="Squiz.Commenting"/>
        <exclude name="Generic.Commenting"/>
    </rule>
    <rule ref="WordPress.Security"/>
    <rule ref="WordPress.DB"/>
    <rule ref="WordPress.WP.I18n"><properties><property name="text_domain" type="array"><element value="alpaca-bot"/></property></properties></rule>
    <rule ref="WordPress.NamingConventions.PrefixAllGlobals"><properties><property name="prefixes" type="array"><element value="alpaca_bot"/><element value="AlpacaBot"/><element value="ALPACA_BOT"/></property></properties></rule>
    <rule ref="PHPCompatibilityWP"/>
</ruleset>
```
- [ ] **Step 2:** `composer require --dev wp-coding-standards/wpcs:^3.1 phpcompatibility/phpcompatibility-wp:^2.1 dealerdirect/phpcodesniffer-composer-installer:^1.0` (after the supply-chain check), scripts `"lint": "phpcs"`, `"lint:fix": "phpcbf"`, `"check": ["@lint", "@analyse", "@test"]`.
- [ ] **Step 3:** Run `composer lint`; fix every error (escaping, nonces are not needed on REST callbacks but PHPCS flags `$_GET` reads: the settings tab read already has the `phpcs:ignore` with a reason), until `composer check` is green.
- [ ] **Step 4:** Commit: `git add -A && git commit -m "chore: PHPCS with WordPress-Extra, security, i18n, prefix, and PHPCompatibility rules"`.

---

### Task 2: `.distignore`, version guard, build script, local release dry run

**Files:**
- Create: `.distignore`, `bin/version-check.sh`, `bin/build-zip.sh`, `.wordpress-org/` (move `_org/assets/*` → `banner-1544x500.png`, `banner-772x250.png`, `icon-256x256.png`, `icon-128x128.png`, `screenshot-1.png`…; regenerate screenshots from the new UI on alpacabot.wp.test at 1280×800)
- Modify: `package.json` (`"version": "1.0.0-dev"`, script `release:dry`), `composer.json` (script `build`)

- [ ] **Step 1:** `.distignore`:
```
.git
.github
.distignore
.editorconfig
.gitattributes
.gitignore
.npmrc
.phpunit.cache
.wordpress-org
bin
docs
node_modules
resources
tests
build.mjs
composer.json
composer.lock
package.json
pnpm-lock.yaml
phpcs.xml.dist
phpstan.neon.dist
phpunit.xml
phpunit.integration.xml
tsconfig.json
README.md
_builds
_org
examples
```
- [ ] **Step 2:** `bin/version-check.sh`:
```bash
#!/usr/bin/env bash
# Fail unless every version marker agrees with $1 (or the plugin header when $1 is omitted).
set -euo pipefail
cd "$(dirname "$0")/.."
hdr=$(grep -m1 '^Version:' alpaca-bot.php | awk '{print $2}')
want="${1:-$hdr}"
stable=$(grep -m1 '^Stable tag:' readme.txt | awk '{print $3}')
pkg=$(node -p "require('./package.json').version")
php=$(grep -m1 "VERSION = '" src/Plugin.php | sed -E "s/.*'([^']+)'.*/\1/")
ok=1
for pair in "header:$hdr" "package.json:$pkg" "Plugin::VERSION:$php"; do
  [ "${pair#*:}" = "$want" ] || { echo "MISMATCH ${pair%%:*}=${pair#*:} want=$want"; ok=0; }
done
case "$want" in *-*) ;; *) [ "$stable" = "$want" ] || { echo "MISMATCH readme Stable tag=$stable want=$want"; ok=0; };; esac
[ $ok = 1 ] && echo "versions ok: $want"
```
- [ ] **Step 3:** `bin/build-zip.sh`:
```bash
#!/usr/bin/env bash
# Build dist/alpaca-bot.zip exactly as CI does.
set -euo pipefail
cd "$(dirname "$0")/.."
bash bin/version-check.sh
bash bin/build-vendor.sh
pnpm install --frozen-lockfile && pnpm build
rm -rf dist && mkdir -p dist/alpaca-bot
rsync -a --exclude-from=.distignore --exclude dist ./ dist/alpaca-bot/
(cd dist && zip -qr alpaca-bot.zip alpaca-bot)
composer install --no-interaction >/dev/null   # restore dev deps
ls -la dist/alpaca-bot.zip && unzip -l dist/alpaca-bot.zip | grep -E 'vendor-prefixed/autoload.php|assets/js/chat.js|readme.txt' 
```
- [ ] **Step 4:** Run `bash bin/build-zip.sh`; install the zip on a fresh harness site (`wph site create zipcheck && wph wp zipcheck -- plugin install ~/Projects/.../dist/alpaca-bot.zip --activate`), open the chat screen, send a message, then `wph site destroy zipcheck --confirm`. Expected: works without composer or node on the site.
- [ ] **Step 5:** Commit: `git add -A && git commit -m "build: distignore, version guard, reproducible zip build, wordpress.org assets dir"`.

---

### Task 3: `ci.yml`

**Files:**
- Create: `.github/workflows/ci.yml`, `.wp-env.json`, `tests/e2e/chat.spec.ts`, `playwright.config.ts`
- Modify: `package.json` (devDependency `@playwright/test`, script `e2e`), `bin/test-integration.sh` (support `WPH_MODE=wp-env` → `wp-env run tests-cli --env-cwd=wp-content/plugins/alpaca-bot vendor/bin/phpunit -c phpunit.integration.xml`)

- [ ] **Step 1:** `.wp-env.json`:
```json
{ "core": null, "phpVersion": "8.4", "plugins": ["."], "config": { "WP_DEBUG": true }, "env": { "tests": { "config": { "WP_TESTS_DOMAIN": "localhost" } } } }
```
- [ ] **Step 2:** `ci.yml`:
```yaml
name: CI
on: { push: { branches: [main, "1.0"] }, pull_request: {} }
permissions: { contents: read }
concurrency: { group: ci-${{ github.ref }}, cancel-in-progress: true }
jobs:
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', coverage: none, tools: composer:v2 }
      - run: composer validate --strict
      - run: composer install --no-interaction --prefer-dist
      - run: composer audit
      - run: composer lint
      - run: composer analyse
      - run: composer test
      - run: composer docs:hooks && git diff --exit-code docs/hooks.md
  assets:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: 11 }
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: pnpm }
      - run: pnpm install --frozen-lockfile
      - run: pnpm check
      - uses: actions/upload-artifact@v4
        with: { name: assets, path: assets, retention-days: 7 }
  integration:
    runs-on: ubuntu-latest
    needs: [php, assets]
    strategy: { matrix: { wp: ['latest', '7.0'] } }
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', tools: composer:v2 }
      - run: composer install --no-interaction --prefer-dist
      - uses: actions/download-artifact@v4
        with: { name: assets, path: assets }
      - uses: pnpm/action-setup@v4
        with: { version: 11 }
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: pnpm }
      - run: pnpm install --frozen-lockfile
      - run: npx --yes @wordpress/env@latest start --update
        env: { WP_ENV_CORE: "WordPress/WordPress#${{ matrix.wp == 'latest' && 'master' || matrix.wp }}" }
      - run: WPH_MODE=wp-env bash bin/test-integration.sh
      - run: pnpm exec playwright install --with-deps chromium && pnpm e2e
        env: { WP_BASE_URL: http://localhost:8888 }
```
Pin every action to a commit SHA per the supply-chain skill before committing (replace `@v4`/`@v2` with `@<sha> # vX`).
- [ ] **Step 3:** `tests/e2e/chat.spec.ts` (Playwright): log in to `WP_BASE_URL` as `admin/password` (wp-env defaults), set `alpaca_bot_settings` via `wp-env run tests-cli wp option update` in a `beforeAll` with a provider filter mu-plugin (`tests/e2e/mu-fake-provider.php`, mapped through `.wp-env.json` `mappings`) that returns a fixed reply; open `/wp-admin/admin.php?page=alpaca-bot`; type "hello", press Enter; expect an assistant bubble containing the fake reply and a receipt.
- [ ] **Step 4:** Push the branch and confirm all three jobs green on the draft PR. Commit message: `ci: lint, analyse, unit, assets, wp-env integration matrix, e2e smoke`.

---

### Task 4: `plugin-check.yml` and Plugin Check findings

**Files:**
- Create: `.github/workflows/plugin-check.yml`

- [ ] **Step 1:**
```yaml
name: Plugin Check
on: { pull_request: {}, workflow_dispatch: {} }
permissions: { contents: read, pull-requests: write }
jobs:
  plugin-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', tools: composer:v2 }
      - uses: pnpm/action-setup@v4
        with: { version: 11 }
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: pnpm }
      - run: composer install --no-interaction && pnpm install --frozen-lockfile
      - run: bash bin/build-zip.sh
      - uses: wordpress/plugin-check-action@v1
        with: { build-dir: dist/alpaca-bot, exclude-directories: 'vendor-prefixed', categories: 'general,plugin_repo,security,performance,accessibility' }
```
(Pin to SHA. If `wordpress/plugin-check-action` rejects a prefixed vendor dir, run Plugin Check manually: start wp-env, `wp plugin install plugin-check --activate`, `wp plugin check alpaca-bot --format=json` and fail on `error` types.)
- [ ] **Step 2:** Run it on the PR; fix every error-level finding (typical: missing `Text Domain`/`Domain Path`, `Tested up to` stale, unescaped output, direct file access guards in `src/` files (`if (!defined('ABSPATH')) exit;` is not required for namespaced class files but Plugin Check may warn), `readme.txt` sections, `Stable tag` mismatch, enqueued scripts without version). Re-run until clean. Close Kanboard #2950 with the run URL.
- [ ] **Step 3:** Commit: `ci: Plugin Check on PRs against the built zip`.

---

### Task 5: `release.yml`

**Files:**
- Create: `.github/workflows/release.yml`
- Modify: `readme.txt` (`Tested up to`, `Stable tag`, changelog for 1.0.0), `README.md` (badges, install), `alpaca-bot.php` (`Version: 1.0.0-beta.1` for the first tag)

- [ ] **Step 1:**
```yaml
name: Release
on: { push: { tags: ['v*'] } }
permissions: { contents: write }
jobs:
  checks:
    uses: ./.github/workflows/ci.yml
  release:
    runs-on: ubuntu-latest
    needs: [checks]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', tools: composer:v2 }
      - uses: pnpm/action-setup@v4
        with: { version: 11 }
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: pnpm }
      - run: composer install --no-interaction && pnpm install --frozen-lockfile
      - run: bash bin/version-check.sh "${GITHUB_REF_NAME#v}"
      - run: bash bin/build-zip.sh
      - uses: softprops/action-gh-release@v2
        with:
          files: dist/alpaca-bot.zip
          prerelease: ${{ contains(github.ref_name, '-') }}
          generate_release_notes: true
      - name: Deploy to wordpress.org
        if: ${{ !contains(github.ref_name, '-') }}
        uses: 10up/action-wordpress-plugin-deploy@v2
        with:
          generate-zip: false
          dry-run: ${{ vars.SVN_DRY_RUN == 'true' }}
        env:
          SVN_USERNAME: ${{ secrets.SVN_USERNAME }}
          SVN_PASSWORD: ${{ secrets.SVN_PASSWORD }}
          SLUG: alpaca-bot
          BUILD_DIR: dist/alpaca-bot
          ASSETS_DIR: .wordpress-org
```
`ci.yml` gets `workflow_call:` in its `on:` so `release.yml` can reuse it. Pin SHAs.
- [ ] **Step 2:** Human step: Carmelo adds `SVN_USERNAME`/`SVN_PASSWORD` repository secrets and sets the repository variable `SVN_DRY_RUN=true` (flip to `false` for the real 1.0.0). Generate a `mattpocock-skills:wizard` for it.
- [ ] **Step 3:** Merge the `1.0` PR to `main` after review (Task 6 findings fixed). Tag `v1.0.0-beta.1` from `main`: `git tag v1.0.0-beta.1 && git push origin v1.0.0-beta.1`. Expected: CI green, a GitHub pre-release with `alpaca-bot.zip`, no SVN step (pre-release). Install the release zip on a clean harness site and run the P3 manual pass.
- [ ] **Step 4:** Commit: `ci: tag-driven release with GitHub release and wordpress.org SVN deploy`.

---

### Task 6: Security audit, performance baseline, deep code review (Kanboard #2944, #2947, #2953)

**Files:**
- Create: `docs/reviews/2026-xx-xx-security-audit.md`, `docs/reviews/2026-xx-xx-performance-baseline.md`, `docs/reviews/2026-xx-xx-code-review.md`
- Modify: whatever the findings require

- [ ] **Step 1: Security audit** — dispatch an Opus reviewer subagent with the `security-review` skill scope over `src/Rest/*`, `src/View/*`, `src/Toolkit/*`, `src/Shortcodes/*`, `src/Admin/*`, `resources/ts/*`: nonce/cookie handling, capability filters (can a filter downgrade to `read`? document), rate-limit bypass, SSRF in `web_fetch` (`wp_http_validate_url` + redirects), stored XSS through markdown (`allowed_tags` filter abuse), stream token lifetime and reuse, Application Password scope, settings mass-assignment, `wp_kses_post` on drafts, shortcode guest path, SSE resource exhaustion (`set_time_limit(0)` bounded by provider timeout), transient key collisions. Findings with severity → fix in the branch with tests, or ticket on Board 17 tagged `blocked`/`needs-info` when a decision is needed. Write the report; close Kanboard #2944 with a link.
- [ ] **Step 2: Performance baseline** — on alpacabot.wp.test with Query Monitor installed (`wph wp alpacabot -- plugin install query-monitor --activate`): measure (a) chat page load: total queries, `alpaca_bot_settings` reads (must be 1), transient hits for models; (b) `POST /chat` non-stream and (c) SSE first-byte latency with a small model, each 5 runs; (d) asset weight (`chat.js`, `htmx.min.js`, css, sprite) gzipped. Compare against 0.4.17 on a second harness site (`wph site create legacy --from blueprints/alpacabot.wp-site.json` mounting the `main` checkout at the v0.4.17 tag via a worktree) for the same four metrics. Table in the report; fix anything that regressed; close Kanboard #2947.
- [ ] **Step 3: Deep code review** — dispatch an Opus reviewer subagent with `engineering:code-review` over the whole `1.0` branch diff (`git diff main...1.0`) plus `superpowers:requesting-code-review` conventions; prioritized findings → fixes with tests; report; close Kanboard #2953. Then `superpowers:finishing-a-development-branch` to merge `1.0` into `main` via the PR.

---

### Task 7: readme, screenshots, changelog, and the 1.0.0 tag

**Files:**
- Modify: `readme.txt`, `README.md`, `.wordpress-org/screenshot-*.png`, `alpaca-bot.php`, `package.json`, `src/Plugin.php`
- Create: `bin/readme-sync.php` (renders the `== Description ==`, `== Frequently Asked Questions ==`, and `== Changelog ==` sections of `readme.txt` from `README.md` headings so the two never drift; `composer docs:readme`; CI diff check)

- [ ] **Step 1:** Write the 1.0.0 changelog (breaking: PHP 8.4, settings migrated automatically, `[alpacabot_agent]` deprecated, REST namespace unchanged but routes renamed; new: streaming, receipts and caps, toolkits, abilities, WP AI provider, Settings API page). `Tested up to` = current WP; `Requires PHP: 8.4`.
- [ ] **Step 2:** Capture screenshots on alpacabot.wp.test (chat with a streamed reply and receipt; settings Models tab; a draft created by the tool) at 1280×800 into `.wordpress-org/`.
- [ ] **Step 3:** Bump versions to `1.0.0` with `bin/version-check.sh 1.0.0` green; merge; tag `v1.0.0` with `SVN_DRY_RUN=true` first, inspect the action log for the SVN diff, then flip to `false` and re-run the workflow via `workflow_dispatch` (add `workflow_dispatch` with a `tag` input to `release.yml`) to deploy. Verify `https://wordpress.org/plugins/alpaca-bot/` shows 1.0.0 and `wph wp alpacabot -- plugin install alpaca-bot --force` pulls it.
- [ ] **Step 4:** Commit and push; comment the release URL on the Kanboard ticket; close it. Update the PowerBank note for alpaca-bot (`~/PowerBank/Personal/alpaca-bot.md` "Where left off").

---

## Not in this plan

- release-please / changelog automation (revisit after two releases). Signing. Multi-PHP matrix (8.4 only until 8.5 is GA in the WP Docker images).

## Self-review

- **Spec coverage:** ci.yml (T3), plugin-check.yml (T4), release.yml with SVN dry-run then real (T5, T7), version guard (T2), `.distignore` (T2), readme sync (T7), `Tested up to` handled in T7 (scheduled bump workflow deferred and noted), hooks doc diff check (T3), security/performance/code review tasks (T6).
- **Placeholders:** none; SHA pinning is an explicit step, not a TODO.
- **Type consistency:** scripts `composer build|lint|analyse|test|test:integration|docs:hooks|docs:readme`, `pnpm build|check|e2e` used consistently across workflows and bin scripts.
