# Alpaca Bot — agent notes

Kanboard project 17 (hosted) is the tracker of record; ticket ids are Kanboard tasks, never GitHub issues. Specs live in `docs/superpowers/specs/`, plans in `docs/superpowers/plans/`, research in `docs/research/`.

## Versioning (decided 2026-09-06, Kanboard #4094)

| Rule | Detail |
| --- | --- |
| Next release | **0.5.0**. The whole P1–P5 core refactor ships together. Code on the integration branch says `0.5.0-dev`. |
| After that | Stay on 0.x, one minor per release: 0.6 admin-wide panel + MCP toolkit, 0.7 front-end chat, then whatever the board says next. |
| 1.0 | **Reserved** for feature complete and fully tested. Do not write "1.0" in code, docs, commit messages, or ticket titles for current work. |
| Old wording | Specs, plans, and tickets titled "1.0" (written 2026-09-05) mean this 0.x line. They are not rewritten; this file wins. |
| Branch | Integration branch is `develop` (was `1.0` until the P2 chip finished). Merge to `main` only at a release. Draft PR #66 tracks it. |
| Public headers | `readme.txt` keeps `Stable tag: 0.4.17` until 0.5.0 is tagged; `Version:` and `Plugin::VERSION` carry `0.5.0-dev`. |

## Local site

`alpaca10.wp.test` (WP Harness) runs the integration branch; `alpacabot.wp.test` runs the 0.4 release from `main` and is the demo, never remount it. Ollama's address is machine-specific and lives in `~/Sites/<site>/wp-site.override.json`, never in shipped code.
