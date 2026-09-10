# Performance baseline — 0.5.0-dev vs 0.4.17

Task 6b of the P5 hardening-and-release plan (Kanboard #2947). Measured 2026-09-09/10 on one
machine, both legs interleaved on the same host at the same time wherever the numbers are
compared.

- **0.5.0-dev** — branch `develop`, commit at measurement time `6356bb9` plus the fix this
  report describes, bind-mounted into `alpaca10.wp.test`.
- **0.4.17** — the `v0.4.17` tag checked out into a throwaway git worktree and bind-mounted into
  a throwaway harness site `ab0417.wp.test`, built from `blueprints/alpacabot.wp-site.json`.
  **Both were destroyed after the run.** `alpacabot.wp.test` was never touched.

Model for every chat measurement: **`qwen2.5-coder:0.5b`** (0.4 GB, the smallest model pulled on
the Ollama at `192.168.1.141:11434`), chosen so provider time is as small as it can be made.
Both sites pointed at that same Ollama.

---

## The table

| Metric | 0.5.0-dev | 0.4.17 | Comparable? |
| --- | --- | --- | --- |
| **(a) Chat page — total DB queries** | **28** (all 7 warm runs) | **29** (all 7 warm runs) | Partly — see method |
| (a) Chat page — plugin option *reads* | **6** — 4 plugin options + the model transient's 2 rows | **20** — 6 plugin options (`api_url` alone 7×) + the model transient's 2 rows, read 4× | Yes |
| (a) Chat page — `alpaca_bot_settings` reads | **1** ✅ | n/a — 0.4 has no single settings option; the throwaway site holds 34 separate `alpaca_bot_*` rows, 28 of them non-autoloaded | — |
| (a) Chat page — model-list transient | **1 get, 1 hit** | **4 gets, 4 hits** | Yes |
| (a) Chat page — server wall time, 7 warm runs (ms) | 103.5, 95.3, 94.7, 94.9, 98.0, 100.6, 96.6 → **median 96.6** | 99.2, 96.4, 100.3, 104.3, 99.8, 96.0, 100.3 → **median 99.8** | Yes, but the two medians are inside the spread |
| (a) Chat page — total time in SQL (ms) | median **4.79** | median **4.70** | Yes |
| **(b) One non-stream turn, warm prompt cache, 5 runs (ms)** | 113, 89, 103, 94, 120 → **median 103** | 83, 83, 88, 90, 83 → **median 83** | End-to-end yes; see the caveat below |
| (b) …of which the pipeline's own timer (provider + generation) | 21, 18, 18, 19, 27 → median **19** | **not measurable** — 0.4.17 has no equivalent timer | No |
| (b) …the rest (WordPress + plugin) | 92, 71, 85, 75, 93 → median **85** | **not measurable** | No |
| (b) One non-stream turn, fresh prompt each time, 5 runs (ms) | 299, 284, 102, 162, 97 → **median 162** | 97, 96, 101, 90, 89 → **median 96** | No — the model generated different amounts on each side |
| (b) Server-side DB queries per non-stream turn | **65–67** | **75–77** | Yes |
| (b) Plugin option reads per non-stream turn | **16** (`alpaca_bot_settings` read **1**) | **37**, across 27 distinct options | Yes |
| **(c) SSE first byte from opening the stream, 5 runs (ms)** | 41, 40, 41, 44, 40 → **median 41** | **n/a — 0.4.17 has no streaming endpoint** | No counterpart |
| (c) SSE first byte from pressing send (ticket POST + stream), 5 runs (ms) | 87, 77, 74, 85, 74 → **median 77** | n/a | No counterpart |
| (c) Ticket POST alone, 5 runs (ms) | 46, 37, 33, 42, 34 → **median 37** (19 DB queries) | n/a | No counterpart |
| **(d) Admin chat screen asset weight, gzipped over the wire** | **31.1 KB** | **3,194.9 KB** (3.12 MB) | Yes |
| (d) …excluding the 3 MB icon font 0.4.17 ships | 31.1 KB | 148.3 KB | Yes |

### (d) broken down, per file

Gzip column is `gzip -9 -c <file> | wc -c`; "over the wire" is `PerformanceResourceTiming.encodedBodySize`
read from the loaded chat screen, so it is what the harness's Apache actually sent.

**0.5.0-dev — everything the admin chat screen loads**

| File | Raw | gzip -9 | Over the wire |
| --- | ---: | ---: | ---: |
| `assets/js/htmx.min.js` (2.0.10) | 51,238 | 16,539 | 16,606 |
| `assets/js/chat.js` | 14,605 | 6,411 | 6,403 |
| `assets/css/alpaca-bot.css` | 12,134 | 3,813 | 3,800 |
| `assets/img/icon-80.png` (menu icon, also in 0.4.17) | 4,297 | — | 4,297 |
| `assets/img/icons.svg` (sprite) | 2,967 | 873 | **0 — inlined into the HTML**, so it is compressed with the page rather than fetched |
| **Total fetched** | | | **31,106** |

`assets/css/alpaca-bot-shortcode.css` (900 raw / 529 gz) exists but is a front-end shortcode
asset; it is not loaded on the admin chat screen and is not in the total.

**0.4.17 — everything its admin chat screen loads**

| File | Raw | gzip -9 | Over the wire |
| --- | ---: | ---: | ---: |
| `assets/css/materialsymbolsoutlined.woff2` | 3,046,732 | 3,045,487 | **3,046,732** (a woff2 is already compressed; the server sent it whole) |
| `assets/js/prism.min.js` | 120,698 | 40,430 | 40,838 |
| `assets/img/alpaca-bot-512.png` | 75,211 | — | 75,211 |
| `assets/js/htmx.min.js` (1.9.10) | 48,036 | 15,623 | 15,690 |
| `assets/js/alpaca-bot.js` | 10,784 | 3,134 | 3,139 |
| `assets/css/alpaca-bot.css` | 12,898 | 3,322 | 3,358 |
| `assets/css/hint.min.css` | 7,218 | 1,600 | 1,601 |
| `assets/img/icon-80.png` | 4,297 | — | 4,297 |
| `assets/img/grid.svg` | 2,051 | — | 2,051 |
| `assets/css/prism-default.min.css` | 3,209 | 1,119 | 1,121 |
| `assets/css/materialsymbolsoutlined.css` | 607 | 331 | 331 |
| **Total fetched** | | | **3,194,984** |

The 3 MB font is not a theoretical cost: the browser fetched it on the chat screen, and the
resource-timing entry is quoted above. Replacing it with a 2,967-byte inline SVG sprite is where
essentially all of 0.5's asset win comes from.

---

## Method — what was run, how often, and what was held still

**Instrumentation.** One mu-plugin, byte-identical on both sites, mounted as
`wp-content/mu-plugins/ab-perf.php`. It counts every `get_option()` call by option name through
the generic `pre_option` filter (WP 6.1+, which fires on every call before the cache lookup, so
it counts logical reads and not DB queries), counts `get_transient()` calls and hits for the two
model-list transients (`alpaca_bot_models` on 0.5, `alpaca_bot_ollama_models` on 0.4.17), and on
`shutdown` writes one JSON line with `get_num_queries()`, the summed `$wpdb->queries` timings,
peak memory and the request's own wall time. It skips CLI requests. Query Monitor 4.0.7 was
installed and active on both sites, which is what defines `SAVEQUERIES`.

**Browser driving.** `node ~/Projects/wp-harness/bin/wph.js shot <site> <path> …` for every page
load and every latency probe; latency probes ran as `--eval` of an async IIFE doing `fetch()`
from the logged-in admin chat screen, so each request carried the real cookie and REST nonce.

**Held still between the two legs**

- WP 7.1, PHP 8.4.25, MariaDB 11.4.13, plain permalinks, no persistent object cache — verified
  identical on both sites by `wp eval`.
- Same Docker host, same Ollama instance and address, same model.
- Same Query Monitor build, same probe mu-plugin, same `WP_DEBUG` constants.
- Both containers restarted immediately before the reported chat-page runs, and the two sites'
  runs were **interleaved** (0.5, 0.4.17, 0.5, 0.4.17 …) so machine drift hits both equally.
- The first run of every series is discarded as cold (cold opcache, cold model). It is listed in
  the raw output but never in a median.

**Not held still — differences between the two environments**

- `alpaca10` carries development content: stored conversations, usage receipts, media, a page.
  `ab0417` is a fresh install with WordPress's sample content. This moves *total* query counts on
  shared admin chrome (post/term queries in `admin-header.php`) and the size of 0.5's history
  select. It is why "(a) total DB queries" is marked only partly comparable: 28 vs 29 is a
  one-query difference between two sites whose content differs, and the plugin-attributable part
  is the row below it, not that number.
- 0.4.17 loads a Composer autoloader plus `erusev/parsedown` and `php-science/textrank` at
  runtime on every request. 0.5 never autoloads `vendor/` at runtime; its prefixed dependencies
  are loaded on demand.
- 0.4.17 has no `composer.lock` at the tag, so its dependencies were resolved fresh
  (composer/installers 2.3.0, parsedown 1.8.0, textrank 1.2.4) rather than pinned to what shipped.
- 0.4.17 logs a `_load_textdomain_just_in_time` notice on every admin request, so `wph shot`
  exits 1 on that site every time. That is a 0.4.17 defect, not a measurement failure, and not
  something this branch inherits — 0.5 runs clean.

**Prompt-cache control, and why (b) is reported twice.** Sending the *same* prompt five times
lets Ollama serve it from its prompt cache, which pins the model's contribution near a constant
(~19 ms on 0.5's own timer) and makes the plugin's share visible. Sending a *fresh* prompt each
time is realistic but hands the model a free variable — one 0.5 run generated 69,776 bytes and
took 2.79 s, which says nothing about the plugin. Both are in the table, labelled.

**A measurement artifact that was chased down rather than reported as a finding.** With an
identical prompt every turn, both versions' per-turn query count grew by exactly one per run
(0.5: 66, 67, 68, 69, 70). That is `wp_unique_post_slug()` walking past each previous
conversation whose title, and therefore slug, was the same string. Re-running with a unique
prompt each time held the count flat at 65, which confirms the cause. It is not a scaling defect
in either version; it is the cost of two conversations being given the same name.

---

## Findings, and what was done about each

### 1. Fixed — three DB queries on *every* request the site served

`Settings\Migrate04::needed()` runs on `init` priority 20 on every request (`Plugin::register()`
hooks it there deliberately, so WP-CLI reaches it). Once migration is complete it reads exactly
three options — `alpaca_bot_migrated_04`, `alpaca_bot_migrated_retention`,
`alpaca_bot_migrated_04_conversations` — and all three were written with `autoload = false`. A
non-autoloaded option that exists is one uncached `SELECT` per request, so those three reads were
three DB queries on every admin page, every front-end page view, and every REST request, forever.

Measured, on the admin chat screen, before and after:

```
before (5 warm runs, all 31 queries):  ms 95.0 97.5 95.6 94.3 95.4  -> median 95.4
after  (7 warm runs, all 28 queries):  ms 103.5 95.3 94.7 94.9 98.0 100.6 96.6 -> median 96.6
alpaca_bot_settings reads: 1 before, 1 after. Plugin-attributable queries: 4 before, 1 after.
```

The before and after series are not interleaved with each other (the fix is a code change, not a
site setting), so their medians are not directly comparable — the container was restarted between
them. The query counts are, because they were identical on every run of each series.

and the three reads themselves, still counted by the probe, are still 6 option reads after the
fix — the reads did not go away, their DB cost did.

Fix: the three flags are written `autoload = true`, so they ride in the single `alloptions` query
WordPress already issues. Test: `tests/Unit/Settings/Migrate04Test.php`, "writes the three
completion flags autoloaded, so needed() adds no query once migration is done" — shown failing
before the change (`Failed asserting that false is true`) and passing after.

**What this fix is not.** It did not make the page measurably faster. Total time in SQL on that
page is ~4.8 ms of a ~97 ms request, and the three removed lookups are single-row primary-key
reads: 0.4.17's five plugin queries on the same page cost 0.46–0.75 ms in total, so ~0.1 ms each,
which puts the saving around 0.3 ms — an order of magnitude under the ±5 ms run-to-run spread on
this host. That per-query figure is measured; attributing exactly 0.3 ms to these three
particular queries is an inference from it. The defensible claim is the query count: 31 → 28 on
every request, which matters on a shared host and to anyone reading Query Monitor, not the clock.

**Sites already running a pre-release 0.5 keep the old autoload value**, because nothing rewrites
a flag once it is set. 0.5.0 has not shipped, so every site that upgrades to it runs the
migration for the first time and gets the autoloaded flags. `alpaca10` was re-measured by
deleting its three flags and letting the migration write them again, which is exactly what an
upgrading 0.4 site does.

### 2. Not a regression — 0.5 reads far fewer settings, and the transient once instead of four times

0.4.17 has no single settings option: the throwaway site ended up with 34 separate `alpaca_bot_*`
rows, 28 of them non-autoloaded. On its chat screen it reads 20 of them (`alpaca_bot_api_url` alone seven times);
on one chat turn it reads 37, across 27 distinct options. 0.5 reads its one settings option
**once** on the chat screen and **once** on a turn, which is the target this task was asked to
check, and it holds. It also asks the model-list transient once per request against 0.4.17's
four.

### 3. Not a regression — 0.5's non-stream turn costs ~10 fewer DB queries

65–67 queries per turn against 0.4.17's 75–77, with roughly equal total time in SQL (~50 ms
against ~46 ms — 0.5 issues fewer, larger writes, because it persists the conversation and a
usage receipt).

### 4. Cost of a new feature, not a defect — the ~20 ms end-to-end gap on (b)

With the prompt cache warm, one non-streamed turn was 103 ms on 0.5 against 83 ms on 0.4.17.
0.5's own pipeline timer says 19 ms of its 103 was provider-and-generation, leaving ~85 ms of
WordPress and plugin work; 0.4.17 has no equivalent timer, so **its** split is unknown and the
gap cannot be attributed. What is known is that 0.5's turn does more per turn: it collects
context, checks a rate limit and a token cap, offers three toolkits to the model (which is why
its prompt is ~830 tokens for a five-word question), persists the conversation and writes a
usage receipt. This is filed as cost-of-feature rather than regression because the extra work is
named and intended — but it is not *proven* to be the whole 20 ms, and nothing here isolates it.

### 5. No counterpart — SSE

0.4.17 has no streaming endpoint at all: `git grep -n "event-stream" v0.4.17 -- src assets`
returns nothing. Its only chat path is a blocking POST that returns rendered HTML. So (c) has one
column, not two, and the honest comparison of what a user waits for before seeing a first token
is 0.5's **41 ms** stream first byte (77 ms counting the ticket POST) against 0.4.17's **83 ms**
whole-answer round trip for a one-word reply — a comparison that inverts as soon as the answer is
longer than a word, which is the point of streaming.

### 6. Reported, not fixed — an unrelated 404

The admin chat screen logs one console error for `GET /favicon.ico → 404`. It is the harness
site's missing favicon, not a plugin asset; both sites do it.

---

## Recommended for 0.6

- **Give 0.4.17's turn a comparable timer, or stop comparing turn latency across versions.** The
  most useful missing number in this report is 0.4.17's provider time. Without it, finding 4 is
  an argument rather than a measurement. If turn latency is ever a release gate, the gate should
  be on the pipeline's non-provider component, which 0.5 already reports on every receipt.
- **Consider whether three toolkit schemas belong in every prompt.** ~830 prompt tokens for a
  five-word question is the tool definitions. It is invisible against a warm prompt cache and a
  0.5 B model, and it will not be against a cold cache and a 30 B one. That is a design question
  about default toolkit enablement, not a bug, and it is too large for a release week.
- **`htmx.min.js` is 16.6 KB of the chat screen's 31.1 KB.** 0.5's own JS is already fetch-based
  (`chat.js` drives the turn end to end); htmx is used for the history swap. Whether that one
  interaction is worth more than half the page's script weight is worth asking once, deliberately.

---

## What these numbers do not tell you

- **Nothing about a site under load.** Every measurement is one request at a time on an idle
  developer machine. There is no concurrency, no contention, and no second visitor. The stream
  route holds a PHP worker for the length of a turn; this report says nothing about what happens
  when several people do that at once, which is what `Rest\StreamBudget` exists for and what an
  actual load test would have to answer.
- **Nothing about a site with a persistent object cache.** Both sites ran without one. That is
  the case where non-autoloaded option reads are most expensive, so it flatters finding 1's
  premise; with Redis in front of the options table those three reads would have been cache hits
  and worth close to nothing. The query-count claim (31 → 28) is specific to a plain WordPress.
- **Nothing about large sites.** `alpaca10` has tens of conversations, not thousands. The history
  select is capped at 20 rows by `chat.history_limit`, but nothing here exercises a site with a
  large `wp_posts`, and the conversation store's per-turn cost was not measured against history
  size.
- **Nothing about a cold model.** The first turn after a model is evicted took 2.0–3.3 s on the
  plugin's own timer. That is Ollama loading weights and is identical on both versions; it is
  excluded from every median and it will dominate any real first interaction.
- **Nothing about the front end.** Only the admin chat screen was measured. The `[alpaca_bot]`
  shortcode, its own stylesheet, and the front-end streaming path were not.
- **Nothing about wall-clock differences under ~10 ms.** The run-to-run spread on this host is
  ±5 ms on a quiet page and much wider once Docker or Ollama is doing anything, so any median
  difference smaller than that is noise, including the 96.6 vs 99.8 on the chat page. Read the
  query counts, the option-read counts and the byte counts as the load-bearing numbers here;
  those are deterministic and reproduced identically on every run.
- **Nothing verified about 0.4.17 as it was actually released.** Its dependencies were resolved
  fresh rather than from a lockfile it never had, and it ran on WordPress 7.1 and PHP 8.4, which
  postdate it.
