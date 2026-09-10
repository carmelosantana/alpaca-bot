# Alpaca Bot — agent notes

Kanboard project 17 (hosted) is the tracker of record; ticket ids are Kanboard tasks, never GitHub issues. Specs live in `docs/superpowers/specs/`, plans in `docs/superpowers/plans/`, research in `docs/research/`.

## Versioning (decided 2026-09-06, Kanboard #4094)

| Rule | Detail |
| --- | --- |
| Next release | **0.5.0**. The whole P1–P5 core refactor ships together. The integration branch carried `0.5.0-dev` until the release commit bumped it. |
| After that | Stay on 0.x, one minor per release: 0.6 admin-wide panel + MCP toolkit, 0.7 front-end chat, then whatever the board says next. |
| 1.0 | **Reserved** for feature complete and fully tested. Do not write "1.0" in code, docs, commit messages, or ticket titles for current work. |
| Old wording | Specs, plans, and tickets titled "1.0" (written 2026-09-05) mean this 0.x line. They are not rewritten; this file wins. |
| Branch | Integration branch is `develop` (was `1.0` until the P2 chip finished). Merge to `main` only at a release. Draft PR #67 tracks it (GitHub closed #66 when the branch was renamed). |
| Public headers | `Version:`, `package.json` and `Plugin::VERSION` carry `0.5.0`, and `readme.txt`'s header block was flipped with them: `Stable tag: 0.5.0`, `Requires PHP: 8.4`, `Requires at least: 6.9`, `Tested up to: 7.1`. `bin/version-check.sh 0.5.0` is green. wordpress.org holds one `Requires PHP` and one `Requires at least` per *plugin*, not per release, so once this is published a PHP 8.1 site is told 0.5.0 exists and blocked from taking it, a WordPress 6.4-6.8 site is offered nothing at all, no further 0.4.x can be published to either without un-publishing 0.5.0, and neither can install the plugin any more. The four rows move together or not at all. |

## Local site

`alpaca10.wp.test` (WP Harness) runs the integration branch; `alpacabot.wp.test` runs the 0.4 release from `main` and is the demo, never remount it. Ollama's address is machine-specific and lives in `~/Sites/<site>/wp-site.override.json`, never in shipped code.
