# Performance baseline — 0.5.0-dev vs 0.4.17

Task 6b of the P5 hardening-and-release plan (Kanboard #2947). Measured 2026-09-09/10 on one
machine, both legs interleaved on the same host at the same time wherever the numbers are
compared.

- **0.5.0-dev** — branch `develop`, commit at measurement time `6356bb9` plus the fixes this
  report describes, bind-mounted into `alpaca10.wp.test`.
- **0.4.17** — the `v0.4.17` tag checked out into a throwaway git worktree and bind-mounted into
  a throwaway harness site `ab0417.wp.test`, built from `blueprints/alpacabot.wp-site.json`.
  **Both were destroyed after each run.** `alpacabot.wp.test` was never touched.

**Revised 2026-09-10.** Round 1 declared the (b) provider/plugin split "not measurable"; it was
measurable, and it has now been measured on both versions — see
[The (b) split](#the-b-split-provider-time-against-plugin-time), which is the answer to the one
question round 1 left open. The throwaway 0.4.17 site and worktree were stood up for it and
destroyed again. This revision also corrects the (d) totals (the eleven 0.4.17 rows did not sum to
their stated total), makes the gzip column consistent, states both sites' active-plugin sets, and
records a second `Migrate04` fix. Every number in every table has been re-added.

Model for every chat measurement: **`qwen2.5-coder:0.5b`** (0.4 GB, the smallest model pulled on
the Ollama at `192.168.1.141:11434`), chosen so provider time is as small as it can be made — a
choice the (b) split turns out to complicate, since the amount that model generates is itself
the thing that moves. Both sites pointed at that same Ollama. In round 2 the model is named in
each request rather than left to the site's stored default, because `alpaca10`'s per-user model
preference had drifted to a larger model between the rounds.

---

## The table

| Metric | 0.5.0-dev | 0.4.17 | Comparable? |
| --- | --- | --- | --- |
| **(a) Chat page — total DB queries** | **28** (all 7 warm runs) | **29** (all 7 warm runs) | Partly — see method |
| (a) Chat page — plugin option *reads* | **7** — 5 plugin options (the four migration flags and the settings row) + the model transient's 2 rows | **20** — 6 plugin options (`api_url` alone 7×) + the model transient's 2 rows, read 4× | Yes |
| (a) Chat page — `alpaca_bot_settings` reads | **1** ✅ | n/a — 0.4 has no single settings option; the throwaway site holds 34 separate `alpaca_bot_*` rows, 28 of them non-autoloaded | — |
| (a) Chat page — model-list transient | **1 get, 1 hit** | **4 gets, 4 hits** | Yes |
| (a) Chat page — server wall time, 7 warm runs (ms) | 103.5, 95.3, 94.7, 94.9, 98.0, 100.6, 96.6 → **median 96.6** | 99.2, 96.4, 100.3, 104.3, 99.8, 96.0, 100.3 → **median 99.8** | Yes, but the two medians are inside the spread |
| (a) Chat page — total time in SQL (ms) | median **4.79** | median **4.70** | Yes |
| **(b) One non-stream turn, warm prompt cache, 5 runs (ms)** | 113, 89, 103, 94, 120 → **median 103** | 83, 83, 88, 90, 83 → **median 83** | End-to-end yes; the split is below |
| (b) …provider time inside the turn (round 2, 20 turns each) | median **27.7** | median **13.4** | Yes — [The (b) split](#the-b-split-provider-time-against-plugin-time) |
| (b) …WordPress + plugin time (same turns, total minus provider) | median **87.3** | median **86.8** | Yes — the two are the same |
| (b) One non-stream turn, fresh prompt each time, 5 runs (ms) | 299, 284, 102, 162, 97 → **median 162** | 97, 96, 101, 90, 89 → **median 96** | No — the model generated different amounts on each side |
| (b) Server-side DB queries per non-stream turn | **65–67** | **75–77** | Yes |
| (b) Plugin option reads per non-stream turn | **17** (`alpaca_bot_settings` read **1**) | **37**, across 27 distinct options | Yes |
| **(c) SSE first byte from opening the stream, 5 runs (ms)** | 41, 40, 41, 44, 40 → **median 41** | **n/a — 0.4.17 has no streaming endpoint** | No counterpart |
| (c) SSE first byte from pressing send (ticket POST + stream), 5 runs (ms) | 87, 77, 74, 85, 74 → **median 77** | n/a | No counterpart |
| (c) Ticket POST alone, 5 runs (ms) | 46, 37, 33, 42, 34 → **median 37** (19 DB queries) | n/a | No counterpart |
| **(d) Admin chat screen asset weight, as sent over the wire** | **31,106 B** (31.1 KB) | **3,194,369 B** (3,194.4 KB, 3.19 MB) | Yes |
| (d) …excluding the 3 MB icon font 0.4.17 ships | 31,106 B (31.1 KB) | 147,637 B (147.6 KB) | Yes |

### (d) broken down, per file

Raw and gzip columns are `stat -c%s <file>` and `gzip -9 -c < <file> | wc -c`, both re-derived for
this revision. The redirect (`< file`, not `gzip -c file`) matters: given a filename gzip stores it
in the header, which adds `strlen(basename) + 1` bytes and is not a size anything sends. The 0.4.17
column below was already in the redirect form; the 0.5 column was not, and its five gzip figures
are restated here in it. "Over the wire" is `PerformanceResourceTiming.encodedBodySize` read from
the loaded chat screen, so it is what the harness's Apache actually sent, and it is the only column
the totals and the headline are taken from. KB is 1,000 bytes and MB 1,000,000 throughout.

**0.5.0-dev — everything the admin chat screen loads**

| File | Raw | gzip -9 | Over the wire |
| --- | ---: | ---: | ---: |
| `assets/js/htmx.min.js` (2.0.10) | 51,238 | 16,527 | 16,606 |
| `assets/js/chat.js` | 14,605 | 6,403 | 6,403 |
| `assets/css/alpaca-bot.css` | 12,134 | 3,798 | 3,800 |
| `assets/img/icon-80.png` (menu icon, also in 0.4.17) | 4,297 | — (already compressed) | 4,297 |
| `assets/img/icons.svg` (sprite) | 2,967 | 863 | **0 — inlined into the HTML**, so it is compressed with the page rather than fetched |
| **Total fetched** | 82,274 (the four that are) | | **31,106** |

`assets/css/alpaca-bot-shortcode.css` (900 raw / 504 gz) exists but is a front-end shortcode
asset; it is not loaded on the admin chat screen and is not in the total.

**Two of those rows have moved since the measurement.** The table above is the build of
2026-09-09 and the wire column can only be re-taken on the live site, so it is left as measured.
What has changed is raw size, which is exact and tool-independent: task 6d's C5 fix added the
front-end block to `resources/css/alpaca-bot.css` (12,134 → 14,209) and C3's fix added the
`sent` guard to `resources/ts/chat.ts` (`assets/js/chat.js`, 14,605 → 14,619). The four fetched
files' raw total is therefore 84,363 rather than 82,274, +2,089 B, all of it compressible text;
`htmx.min.js` and `icon-80.png` are untouched. That leaves the (d) comparison and every
conclusion drawn from it standing — 0.5 is still two orders of magnitude under 0.4.17 — and it
is the number to re-measure, not to re-derive, if the wire figure is wanted again.

**0.4.17 — everything its admin chat screen loads**

| File | Raw | gzip -9 | Over the wire |
| --- | ---: | ---: | ---: |
| `assets/css/materialsymbolsoutlined.woff2` | 3,046,732 | 3,045,487 | **3,046,732** (a woff2 is already compressed; the server sent it whole) |
| `assets/js/prism.min.js` | 120,698 | 40,430 | 40,838 |
| `assets/img/alpaca-bot-512.png` | 75,211 | — (already compressed) | 75,211 |
| `assets/js/htmx.min.js` (1.9.10) | 48,036 | 15,623 | 15,690 |
| `assets/js/alpaca-bot.js` | 10,784 | 3,134 | 3,139 |
| `assets/css/alpaca-bot.css` | 12,898 | 3,322 | 3,358 |
| `assets/css/hint.min.css` | 7,218 | 1,600 | 1,601 |
| `assets/img/icon-80.png` | 4,297 | — (already compressed) | 4,297 |
| `assets/img/grid.svg` | 2,051 | 306 | 2,051 |
| `assets/css/prism-default.min.css` | 3,209 | 1,119 | 1,121 |
| `assets/css/materialsymbolsoutlined.css` | 607 | 331 | 331 |
| **Total fetched** | 3,331,741 | | **3,194,369** |

The 3 MB font is not a theoretical cost: the browser fetched it on the chat screen, and the
resource-timing entry is quoted above. Replacing it with a 2,967-byte inline SVG sprite is where
essentially all of 0.5's asset win comes from.

Every column above was re-added for this revision. The first revision's 0.4.17 total said
3,194,984 B against eleven rows that sum to 3,194,369 — 615 B that no row accounts for. Each
individual figure was right; only the addition was wrong, and it propagated into the headline
(3,194.9 KB, and "148.3 KB" excluding the font). The corrected sums are 3,194,369 B and
147,637 B, and 0.5's 31,106 B was and is correct.

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

For round 2 (the (b) split below) that probe was rewritten and re-mounted byte-identical on both
sites. It keeps the `pre_option` counting, `get_num_queries()`, the SQL timings, peak memory and
wall time, drops the two named transient counters (round 2 re-measures neither), and adds a
provider bracket for each version: `pre_http_request` + `http_api_debug`, which time 0.4.17's
`wp_remote_request()` to Ollama and record every leg with its URL, and a decorator added through
0.5's own documented `alpaca_bot/provider` filter, which times the `chat()`/`stream()` call the
pipeline makes. Neither plugin was modified. Note that `pre_option` counting is by option *name*,
so a plugin transient without a persistent object cache is counted through its two option rows
(`_transient_x` and `_transient_timeout_x`) — that is what the round-1 numbers meant too, and
the option-read rows in the table are on that definition. Both sites were destroyed or unmounted
again afterwards.

**Browser driving.** `node ~/Projects/wp-harness/bin/wph.js shot <site> <path> …` for every page
load and every latency probe; latency probes ran as `--eval` of an async IIFE doing `fetch()`
from the logged-in admin chat screen, so each request carried the real cookie and REST nonce.

**Held still between the two legs**

- WP 7.1, PHP 8.4.25, MariaDB 11.4.13, plain permalinks, no persistent object cache — verified
  identical on both sites by `wp eval`.
- Same Docker host, same Ollama instance and address, same model.
- Same Query Monitor build, same probe mu-plugin, same `WP_DEBUG` constants.
- **The same set of active plugins**, verified by `get_option('active_plugins')` on both:
  `query-monitor/query-monitor.php` and `alpaca-bot/alpaca-bot.php`, and nothing else. Both also
  carried the same two must-use files (the harness's `wph-magic-login.php` and the probe) and
  Query Monitor's `db.php` drop-in. `akismet` and `hello` were installed and **inactive** on
  both, and `plugin-check` installed and inactive on `alpaca10` only; an inactive plugin is not
  loaded. So the 28-vs-29 row is not a plugin-set difference — the difference is the site
  content, below.
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
  exits 1 on that site every time. Round 2 found two more of its own on that leg: a
  `PHP Deprecated: AlpacaBot\Api\Render::__construct(): Implicitly marking parameter $request as
  nullable` once per process, and a `PHP Warning: Undefined variable $image` at
  `v0.4.17:src/Api/Render.php:645` on **every chat turn**. All three are 0.4.17 defects on
  PHP 8.4, not measurement failures, and none is inherited — 0.5 runs clean on both sites'
  screens and every turn.

**Prompt-cache control, and why (b) is reported twice.** Sending the *same* prompt five times
lets Ollama serve the prompt from its cache, which removes prompt evaluation from the comparison.
Round 1 said it also "pins the model's contribution near a constant (~19 ms)"; round 2 measured
that directly over 20 turns and **it does not** — 0.5's provider leg ranged 16.9 ms to 25 s on an
identical prompt, because a warm prompt cache constrains what goes *in*, not how much the model
chooses to generate. 0.4.17's did hold near constant (13.0–13.8 ms in 19 of 20 turns), which is
what made round 1's impression plausible. Sending a *fresh* prompt each time removes the cache as
well and is in the table too, labelled. Medians, not means, are used throughout for this reason.

**A measurement artifact that was chased down rather than reported as a finding.** With an
identical prompt every turn, both versions' per-turn query count grew by exactly one per run
(0.5: 66, 67, 68, 69, 70). That is `wp_unique_post_slug()` walking past each previous
conversation whose title, and therefore slug, was the same string. Re-running with a unique
prompt each time held the count flat at 65, which confirms the cause. It is not a scaling defect
in either version; it is the cost of two conversations being given the same name.

---

## The (b) split: provider time against plugin time

The first revision of this report called this split "not measurable — 0.4.17 has no equivalent
timer". That was wrong, and it was the report's own most useful missing number. 0.4.17 calls
Ollama through `wp_remote_request()` (`v0.4.17:src/Api/Ollama.php:327`), so WordPress's own
`pre_http_request` and `http_api_debug` bracket its provider leg exactly, from a mu-plugin, with
no change to the plugin. It was one filter away. The throwaway site and worktree were stood back
up on 2026-09-10 and the measurement was made; both are gone again.

**What was bracketed on each side.** These are two different seams, because the two versions do
not share a transport, and saying so is the point:

- **0.4.17** — `pre_http_request` (immediately before the transport runs) to `http_api_debug`
  (immediately after the response is parsed), filtered to the leg whose URL is the Ollama host.
  This bracket also contains WordPress's own HTTP API work — argument parsing, the Requests
  library, its filters — which counts *against* 0.4.17, not for it.
- **0.5.0-dev** — a decorator added through the plugin's own documented `alpaca_bot/provider`
  filter, timing the `chat()`/`stream()` call the pipeline makes. 0.5 does **not** use the WP HTTP
  API for the provider: `Provider\Factory::ollama()` builds a php-agents `OllamaProvider` over
  Symfony HttpClient, so no `http_api_debug` fires on this side and an HTTP-hook bracket alone
  would have measured one of the two legs, not both. The filter is the equivalent seam.

Both measure wall time the plugin spent inside its provider call, with the instrument each version
admits — but they are **not cut at the same point**, and the difference runs one way. The 0.4.17
bracket starts after its request headers and body are built and stops before `json_decode`; the 0.5
bracket is outside php-agents' own request construction, `formatTools()`, and SSE parsing. So a few
milliseconds of 0.5's plugin work are counted as provider time, and the residual bias flatters the
"identical plugin halves" result rather than causing it. The gap being measured is 14 ms, so this
would have to be an order of magnitude larger than it plausibly is to change the conclusion — but it
is a bias, not a wash, and the number to distrust first if this is ever re-run.

Total is the probe's own request wall time on `shutdown`, so total and provider come off the same
clock in the same process and **plugin time is total minus provider**, not a third measurement.

The probe itself was a throwaway mu-plugin and is not in this repository, so the one link in this
chain a reader cannot check is exactly what the decorator bracketed. Anyone re-running this should
write the probe into the repo first.

Protocol: the same prompt every turn ("Reply with the single word: hi") against a warm Ollama
prompt cache, model `qwen2.5-coder:0.5b` on both, four interleaved passes per version
(0.5, 0.4.17, 0.5, 0.4.17), six turns per pass, the first turn of each pass discarded as cold —
**20 measured turns per version**. Every one of them is listed, rounded to 0.1 ms; the medians are
of the unrounded values, so 0.4.17's total median (99.9) sits just under the midpoint of the two
rounded middle entries.

### 0.5.0-dev — 20 turns (ms)

| | values | median |
| --- | --- | ---: |
| total (server) | 25138.3, 146.3, 125.0, 104.0, 83.2, 149.8, 93.2, 105.2, 120.2, 107.8, 118.8, 116.0, 100.7, 116.1, 178.3, 134.4, 151.8, 113.7, 108.1, 128.9 | **117.4** |
| provider | 25049.8, 56.3, 38.4, 16.9, 19.1, 60.8, 20.0, 17.2, 17.6, 40.0, 17.9, 20.1, 18.0, 27.8, 86.7, 50.7, 72.5, 26.8, 27.6, 41.4 | **27.7** |
| WordPress + plugin | 88.6, 90.0, 86.6, 87.1, 64.2, 89.0, 73.1, 88.1, 102.7, 67.7, 100.9, 95.9, 82.7, 88.2, 91.7, 83.7, 79.3, 86.8, 80.5, 87.5 | **87.3** |

### 0.4.17 — 20 turns (ms)

| | values | median |
| --- | --- | ---: |
| total (server) | 104.8, 100.5, 99.5, 100.4, 103.7, 102.3, 78.5, 94.8, 99.1, 97.5, 104.0, 103.9, 95.9, 83.5, 85.0, 103.7, 114.9, 93.8, 121.9, 96.3 | **99.9** |
| provider | 13.3, 13.0, 13.3, 13.0, 13.4, 13.2, 13.4, 13.5, 13.7, 13.5, 13.5, 13.5, 13.3, 13.7, 13.3, 13.8, 16.4, 13.5, 13.0, 13.4 | **13.4** |
| WordPress + plugin | 91.5, 87.6, 86.2, 87.4, 90.3, 89.1, 65.2, 81.3, 85.4, 84.0, 90.5, 90.4, 82.7, 69.8, 71.8, 90.0, 98.5, 80.2, 108.9, 82.8 | **86.8** |

### What the gap is

**The plugin halves are the same: 87.3 ms against 86.8 ms.** Half a millisecond apart, on a host
whose run-to-run spread is ±5 ms, across two versions that share no chat code. The whole
difference between the two turns is the provider: 27.7 ms against 13.4 ms.

That answers the question the first revision left open. 0.5's turn is not slower because 0.5 does
more WordPress work per turn. It does more work in ways that are visible elsewhere in this report
— it collects context, checks a rate limit and a token cap, persists a conversation and writes a
usage receipt — and it does all of that in the same wall time as 0.4.17, on *fewer* DB queries
(median 67 per turn against 73 in this round; the earlier round's 65–67 against 75–77, with the
`wp_unique_post_slug()` walk described below inflating the spread of both). The receipt, the rate
limit and the context collection are, measurably, free at this scale.

### Why 0.5's provider leg is longer, tested rather than argued

0.4.17's provider column is almost a constant: nineteen of twenty turns are 13.0–13.8 ms. 0.5's
ranges from 16.9 ms to 25 s. That is not jitter — it is the model generating different amounts,
and it is caused by 0.5's prompt.

Token counts were captured from the response's own receipt on passes 3 and 4 only; passes 1 and 2
predate that and are timings alone. Three measurements, in order:

1. **Prompt size.** Every 0.5 turn reported `prompt_tokens: 826` for a five-word question. With
   the three default toolkits switched off (`toolkits.enabled` set to `[]` and put straight back
   afterwards) the same question is **36** prompt tokens. The 790 tokens are the tool schemas.
2. **Generated size.** With toolkits on, the ten measured turns of passes 3 and 4 reported
   `completion_tokens` 2, 2, 3, 7, 7, 7, 13, 16, 30, 34, and their two discarded cold turns 16 and
   **840** — a 0.5 B model handed three tool schemas answers with tool-call-shaped noise instead
   of "hi". With toolkits off, `completion_tokens` was **2 on every single turn**. (The 25 s turn
   in pass 1 is almost certainly the same behaviour taken further, but its token count was not
   recorded, so that is an inference and not one of these measurements.)
3. **Time follows generated size, not prompt size.** The pipeline's own timer against
   `completion_tokens`, same passes: 2 → 18 ms, 2 → 18, 3 → 20, 7 → 27, 7 → 28, 7 → 28, 13 → 42,
   16 → 51, 30 → 73, 34 → 87, and the cold 840 → 1748. A line through those is about 14 ms fixed
   plus 2.1 ms per generated token, and the 840-token turn sits on it (1748 / 840 = 2.08). The one
   point off it is the other cold turn, 16 → 111. Time to the first streamed chunk is flat by
   comparison — median 15.2 ms over the 20 turns with toolkits on, 11.4 ms over the 5 with them
   off — so against a warm cache the 826-token prompt itself costs on the order of 4 ms and
   everything above that is generation.

**The control.** Five measured turns with the toolkits off, same site, same model, same prompt:
provider 16.8, 14.5, 12.7, 13.7, 13.5 → median **13.7 ms**, against 0.4.17's 13.4 ms over 20.
Total 112.8, 129.1, 92.9, 104.7, 101.2 → median **104.7 ms**, against 0.4.17's 99.9 ms. Five turns
is a small sample and the medians should not be read to a decimal; what it establishes is that the
provider leg moves from 27.7 ms to the same order as 0.4.17's when the schemas are removed, and
that `completion_tokens` stops varying.

So: **the gap — 17.5 ms of median total in this round, ~20 ms in round 1 — is provider time, and
the plugin's share of it is the decision to offer three tool schemas on every turn by default.**
Switch that one setting off and 0.5's provider leg is indistinguishable from 0.4.17's.

What is measured and what is inferred, explicitly:

- *Measured*: every millisecond in the three tables; the 826/36 prompt-token counts; the
  `completion_tokens` per turn; the pipeline timer against those counts; the toolkits-off control.
- *Inferred*: that the reason a 0.5 B model emits tool-call noise is the schemas' presence rather
  than some other consequence of a longer prompt. The control does not separate "the schemas" from
  "790 more tokens of anything", because nothing here sent 790 tokens of filler instead. A larger
  or instruction-tuned model may not behave this way at all; nothing here tested one.
- *Not measured*: any of this under a cold prompt cache, which is where the 790 tokens would also
  cost prompt-evaluation time rather than ~4 ms.

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

The reads did not go away; their DB cost did. `needed()` still calls `get_option()` for each
flag on every request, and the probe still counts each of those as a logical read — which is why
the plugin option-read row for the chat page went *up* (to 7 with the fourth flag added below),
while the query count went down.

Fix: the flags are written `autoload = true`, so they ride in the single `alloptions` query
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

**A site that already finished migrating was not reached by that fix**, because nothing rewrites
a flag once it is set: each `*Pending()` returns false the moment its row exists, so `run()`'s
writes are unreachable there and the row keeps whatever autoload value a pre-release 0.5 gave it.
Only pre-release 0.5 sites can be in that state — `git grep migrated_04 v0.4.17` is empty, so a
genuine 0.4 → 0.5.0 upgrader creates the rows fresh — but `alpaca10` itself was one of them, and
so is every tester's site.

**Also fixed, therefore**: a fourth flag, `alpaca_bot_migrated_flag_autoload`, carries a one-time
`wp_set_options_autoload()` over the other three, last in `run()`. It is itself autoloaded, so a
settled site's `needed()` is now four `alloptions` reads and still no query — measured, not
assumed: the admin chat screen is **28 queries** on every warm run with the fourth flag in place,
the same 28 as before it, with plugin option reads at 7 instead of 6.
`tests/Integration/Migrate04AutoloadTest.php` asserts the effect against a real options table
rather than the argument handed to a mock — the `autoload` column WordPress actually wrote, read
against `wp_autoload_values_to_autoload()` — on a fresh install and on a seeded pre-release site.
Both cases fail without the repair: the seeded pre-release site with `Failed asserting that an array
contains 'off'`, the fresh install with `contains null`, on a different row.

### 2. Not a regression — 0.5 reads far fewer settings, and the transient once instead of four times

0.4.17 has no single settings option: the throwaway site ended up with 34 separate `alpaca_bot_*`
rows, 28 of them non-autoloaded. On its chat screen it makes 20 reads against 6 distinct options (`alpaca_bot_api_url` alone seven
times), plus 8 reads of the model transient's two rows;
on one chat turn it reads 37, across 27 distinct options. 0.5 reads its one settings option
**once** on the chat screen and **once** on a turn, which is the target this task was asked to
check, and it holds. It also asks the model-list transient once per request against 0.4.17's
four.

### 3. Not a regression — 0.5's non-stream turn costs ~10 fewer DB queries

65–67 queries per turn against 0.4.17's 75–77, with roughly equal total time in SQL (~50 ms
against ~46 ms — 0.5 issues fewer, larger writes, because it persists the conversation and a
usage receipt).

### 4. Not a plugin regression — the ~20 ms gap on (b) is provider time, and it is the default toolkits

Measured, not argued: see [The (b) split](#the-b-split-provider-time-against-plugin-time). Over
20 turns per version, WordPress-and-plugin time is **87.3 ms on 0.5 against 86.8 ms on 0.4.17** —
the same number — and the whole difference sits in the provider leg, 27.7 ms against 13.4 ms.
0.5's provider leg is longer because its prompt carries the three default tool schemas (826
prompt tokens against 36 without them), and a 0.5 B model handed three schemas answers a five-word
question with 2 to 840 tokens of tool-call-shaped noise instead of one word; provider time tracks
that token count at roughly 2 ms each. With the toolkits off, 0.5's provider leg is 13.7 ms
against 0.4.17's 13.4 ms.

So the extra work 0.5 does per turn — context collection, rate limit, token cap, conversation
persistence, usage receipt — costs nothing measurable here, and does it on fewer DB queries. The
cost of the new feature set is real but it is paid at the model, by the toolkit schemas, and it is
one setting. That makes finding 2 of "Recommended for 0.6" concrete rather than speculative.

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

- **If turn latency is ever a release gate, gate on the non-provider component.** 0.5 already
  reports it on every receipt, and the split above shows why the end-to-end number is the wrong
  gate: it moves with how many tokens a model chose to emit, which is not a property of this
  plugin. (The first revision listed 0.4.17's missing provider time here as a 0.6 item. It was
  measurable all along, it has been measured, and it is in the split above.)
- **Decide whether three toolkit schemas belong in every prompt by default.** 826 prompt tokens
  for a five-word question is the tool definitions, and on the smallest model available they are
  the entire measured latency difference from 0.4.17 (finding 4): they roughly double the turn's
  provider time by making the model generate noise. Against a cold cache and a 30 B model the
  prompt cost would land too, on top. This is a design question about default enablement, not a
  bug, and it is too large for a release week — but it is now backed by a control, not a worry.
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
- **Nothing about one model against another.** The (b) split's causal half rests on
  `qwen2.5-coder:0.5b` reacting to three tool schemas by emitting tool-call-shaped noise. The
  arithmetic — plugin time is total minus provider — holds for any model; the conclusion "the gap
  is the toolkits" is measured only for this one, which was chosen for being the smallest
  available and is the most likely of any to be confused by a schema. A 30 B model may show the
  cost as prompt evaluation instead, or not at all.
- **Nothing about wall-clock differences under ~10 ms.** The run-to-run spread on this host is
  ±5 ms on a quiet page and much wider once Docker or Ollama is doing anything, so any median
  difference smaller than that is noise, including the 96.6 vs 99.8 on the chat page. That cuts
  both ways for the (b) split: 87.3 vs 86.8 ms of plugin time is a claim that the two are **not
  distinguishable here**, not a claim that 0.5 is 0.5 ms faster. Read the query counts, the
  option-read counts and the byte counts as the load-bearing numbers here; those are
  deterministic and reproduced identically on every run.
- **Nothing verified about 0.4.17 as it was actually released.** Its dependencies were resolved
  fresh rather than from a lockfile it never had, and it ran on WordPress 7.1 and PHP 8.4, which
  postdate it.
