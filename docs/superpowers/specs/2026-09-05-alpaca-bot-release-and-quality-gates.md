# Alpaca Bot — release pipeline and quality gates

Wayfinding map: Kanboard #2938. Companion to `2026-09-05-alpaca-bot-1-0-core-refactor.md`.

## Why

Releases are manual today: zips in `_builds/`, a hand-maintained SVN checkout at `~/Projects/WordPress/wordpress.org/alpaca-bot-svn`, no `.github/`, no tests, no coding-standards run, and a readme that says "Tested up to 6.5.5". The plugin has already failed a wordpress.org review once (Kanboard #418/#437 history). 1.0 cannot ship unless every tag passes the same checks a reviewer runs.

## Decisions

| Decision | Choice | Ticket |
| --- | --- | --- |
| Release path | tag-driven GitHub Actions: tests → Plugin Check → build → GitHub release → SVN deploy via `10up/action-wordpress-plugin-deploy` | Kanboard #2949 |
| SVN credentials | `SVN_USERNAME` / `SVN_PASSWORD` repository secrets; manual SVN only via harness break-glass tools with confirm | Kanboard #2949, #2966 |
| Test stack | Pest + Brain\Monkey unit; WP test suite integration via harness/wp-env; Playwright e2e later | Kanboard #2951 |
| Standards | PHPCS with WordPress-Coding-Standards + PHPCompatibility, WordPress Plugin Check CLI, readme/header validator | Kanboard #2950 |
| Dependency hygiene | `composer audit` in CI; strauss prefixing; lockfile committed for the plugin | Kanboard #2952 |
| Builds | `_builds/` removed from the tree; artifacts live on GitHub releases | Kanboard #2949 |

## Changes

### Workflows (`.github/workflows/`)

- `ci.yml` on push/PR: `composer validate`, `composer audit`, PHPCS, PHPStan (level from php-agents), Pest unit; integration job spins wp-env (matrix WP latest + 7.0, PHP 8.4) and runs the WP test suite; `pnpm build` and a check that `assets/` is reproducible.
- `plugin-check.yml` on PR: runs `wp plugin check alpaca-bot` against a built zip inside wp-env and fails on any error-level result; posts the report as a PR comment.
- `release.yml` on `v*` tags: reuses ci + plugin-check, then `composer install --no-dev`, `strauss`, `pnpm build`, zip per `.zipignore` (renamed `.distignore`), `gh release create` with the zip, then the 10up deploy action with `ASSETS_DIR: .wordpress-org` (screenshots/banners moved there from `_org/assets`).
- Version consistency guard: tag must equal the `Version:` header, `Stable tag` in readme, and `package.json` version.

### Local

- `composer test`, `composer lint`, `composer check` (Plugin Check via harness site), `pnpm build`, `pnpm release:dry` (builds the zip exactly as CI does).
- Pre-commit hook (optional, `.githooks/`) runs PHPCS on staged files.

### Repo hygiene

- `.distignore` replaces `.zipignore`; `_builds/`, `_org/`, `examples/` removed; `README.md` and `readme.txt` kept in sync by a script that generates the wordpress.org readme sections from the markdown.
- `Tested up to` bumped on each WP release by a scheduled workflow that opens a PR after the integration matrix passes.

## Non-goals

- release-please / automated changelog PRs (revisit after two releases).
- Publishing php-agents itself (own repo, own CI).
- Signing or notarizing zips.

## Sequencing

1. `ci.yml` with unit tests only, on the foundations branch (first refactor milestone).
2. PHPCS + PHPStan + audit added once `src/` is restructured.
3. Integration job once the harness can run the WP test suite (Kanboard #2969).
4. `plugin-check.yml` before the first 1.0 beta tag.
5. `release.yml` dry-run against a `v1.0.0-beta.1` tag with SVN deploy disabled; enable SVN for `v1.0.0`.
