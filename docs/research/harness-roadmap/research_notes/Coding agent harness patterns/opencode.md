# opencode as a coding agent harness (state as of 2026-11-20)

> Staleness warning for the report writer: OpenCode **2.0** shipped in mid-October 2026 and rewrote the server API, the plugin API and the config schema (the docs call the old shape "V1"). Much of the web (blogs, HN, older tutorials, the brief's own vocabulary such as `tool.execute.before`, `.opencode/plugin`, `permission.bash`, `task`, "Zen") describes **V1**. Below, V2 facts come from opencode.ai/docs fetched 2026-11-20; V1 names are given only where the V2 docs map them. Source pages fetched by a summarising tool, so wording is paraphrased, not verbatim.

## Architecture: client/server split, SDK, OpenAPI

### Takeaway
opencode is a local background **service** with an HTTP API (OpenAPI 3.1) that every surface (TUI, desktop, web, mobile, scripts, SDK) attaches to; 2.0 made that split the whole product. For Alpaca Bot the lesson is "the agent loop is a server with a typed event stream and a permission-reply endpoint; the UI is just one client".

### Cited Findings
- 2.0 launched mid-October 2026 (dates given as Oct 12, 13 or 16 depending on source); a local background service connects terminal, web and desktop; a rebuilt API plus `@opencode/client` lets scripts discover and drive that service; new `@opencode/sdk` embeds opencode in custom apps; 2.0 is incompatible with 1.0 plugins and the server API was redesigned — [AlternativeTo](https://alternativeto.net/news/2026/10/opencode-2-0-officially-launches-with-background-agents-code-mode-and-a-rebuilt-api/); date conflict and Oct 13 X announcement noted in search summary of [The New Stack](https://thenewstack.io/opencode-2-shared-service/) (403 on fetch, not read directly) and [capitalandcompute](https://capitalandcompute.net/blog/opencode-2-0-what-changed/).
- 2.0 headline features: background agents (subagents work while the primary continues; async shell commands) and "Code Mode" (agent writes a script that chains MCP and OpenAPI calls in one execution) — [AlternativeTo](https://alternativeto.net/news/2026/10/opencode-2-0-officially-launches-with-background-agents-code-mode-and-a-rebuilt-api/).
- Server API: OpenAPI 3.1.0 document at `/docs/openapi.json`; described as an experimental HttpApi with 173 operations / 518 schemas — [opencode docs: Server](https://opencode.ai/docs/server/).
- Auth: short-lived single-use pairing code via `POST /api/pair`, redeemed at `GET /auth/connect/{code}`; browsers get a session cookie, JSON clients a session token — [Server](https://opencode.ai/docs/server/). Web access via `opencode pair` gives a one-time link expiring after 5 minutes — [Intro](https://opencode.ai/docs/).
- Session endpoints: list/create `GET|POST /api/session`; read/update/delete `/api/session/{id}`; `POST /api/session/{id}/prompt`; `GET .../message`; `POST .../interrupt`; `GET /api/session/active` — [Server](https://opencode.ai/docs/server/).
- Events: `GET /api/event` streams native and plugin events; documented as *volatile* (slow consumers overflow and fail, no replay after disconnect); a separate experimental `GET /api/experimental/session/{id}/log` reads a durable per-session event log and can follow live — [Server](https://opencode.ai/docs/server/).
- Permissions are first-class API objects: `GET /api/permission/request` (pending), `POST /api/session/{id}/permission/{requestID}/reply`, `/api/permission/saved` for saved approvals — [Server](https://opencode.ai/docs/server/).
- Surfaces: TUI ("works in any shell, editor or remote server"), desktop (macOS/Windows/Linux), mobile (iPhone TestFlight, Android), SDK for scripts/CI — [opencode.ai](https://opencode.ai/); desktop marked BETA in README — [GitHub](https://github.com/anomalyco/opencode). Docker images at `ghcr.io/anomalyco/opencode` — [Intro](https://opencode.ai/docs/).

### Inferences
- Pattern to copy: model each run as a server-side session with (a) a prompt endpoint, (b) an event stream, (c) a durable replayable log, (d) pending-permission objects answered by a reply endpoint. In WordPress this maps to REST routes + a stored event log polled/streamed by wp-admin, which decouples the agent loop from the admin page lifetime.
- Their split between a volatile live stream and a durable log is a useful design note for PHP where SSE connections are fragile.

### Gaps
- Exact port/CORS/mDNS defaults for `opencode serve` in V2 were not in the fetched part of the server page (page is ~300k chars; only first 100k read).
- No primary 2.0 blog post read (The New Stack returned 403); launch date varies by source.

## Extension model

### Takeaway
Everything is a file-or-config-driven extension point: npm/git/local plugins with domain hooks, markdown agents, markdown/JSON commands, skills directories, MCP servers, AGENTS.md instructions, formatters. V2 replaced the flat V1 hook map with per-domain `hook`/`transform` APIs.

### Cited Findings
- Plugin loading: `plugins` array in `opencode.json(c)` accepts npm specs (versioned/scoped), local directories, `file://`, or `{package, options}`; arrays from global `~/.config/opencode/opencode.jsonc`, `./opencode.jsonc`, `./.opencode/opencode.jsonc` are merged; auto-discovery from `.opencode/plugins/` and `~/.config/opencode/plugins/`; `opencode plugin add` accepts npm and git specs; ordered entries with `-` disable, `*` enable-all, prefix wildcards — [Plugins](https://opencode.ai/docs/plugins/).
- Plugin parts: server (`.`/`./server`), TUI (`./tui`), GUI (`./gui-experimental`, adds desktop panels/commands/settings), RPC (`./rpc`); package `@opencode/plugin`; V2 form is `Plugin.define({ id, async setup(ctx) {...} })` — [Build a plugin](https://opencode.ai/docs/build/plugins/). TUI plugins stable with hot reload in 2.0 — [AlternativeTo](https://alternativeto.net/news/2026/10/opencode-2-0-officially-launches-with-background-agents-code-mode-and-a-rebuilt-api/).
- `ctx` is effectively a server client plus plugin-only methods: `ctx.app`, `ctx.location`, `ctx.options`, `ctx.storage` (get/set/remove/scan, plugin-scoped JSON), and domains `agent, provider, model, command, integration, credential, mcp, plugin, reference, session, skill, tool, vcs, worktree, websearch, permission, shell, event, experimental.generate`, each with reads/`transform`/`reload` — [Server plugins](https://opencode.ai/docs/build/plugins/server/).
- V2 hooks: session (`fork`, `prompt`, `context`, `compaction`, `generate`, `title`, `experimental.model.resolve`, `model.request`, `http.request`, `http.response`, experimental ws hooks, `retry`); permission `evaluate` (can set effect and message; configured denies skip it); shell `create.before` (command/cwd/timeout/shell/env); tool `execute.before`, `execute.after` (with `status`) — [Server plugins](https://opencode.ai/docs/build/plugins/server/).
- V1 → V2 map: `tool.execute.before/after` → `ctx.tool.hook("execute.before|after")`; `permission.ask` → `ctx.permission.hook("evaluate")`; `chat.params` → session `context` hook; `chat.headers` → `model.request`/`http.request`; `chat.message` → `prompt`; `event` → `ctx.event.subscribe()`; `shell.env` → `create.before`; `tool`/`tool.definition` → `ctx.tool.transform`; `auth` → `ctx.integration.transform`; `experimental.session.compacting` → `compaction` hook; V1 auto-discovered `.opencode/plugin/` (singular). Some V1 hooks have no V2 equivalent. opencode 1.18.29+ accepts dual V1/V2 exports — [Migrate V1](https://opencode.ai/docs/build/plugins/migrate-v1/).
- Custom tools: registered inside `ctx.tool.transform(editor => editor.add({...}))` with `name`, `description`, JSON Schema `input`, `options` (`namespace`, `codemode`), `execute(input, {progress, signal})` returning `{content}`; namespaced name becomes e.g. `acme_greeting`; registrations are disposable — [Server plugins](https://opencode.ai/docs/build/plugins/server/).
- Agents: modes `primary`, `subagent`, `all`; built-ins `build` (default), `plan` (no edits to project files), `general` (subagent, cannot spawn subagents), `explore` (read/search only), hidden `compaction`, `title`, `summary`. Markdown agents at `~/.config/opencode/agents/<name>.md` or `.opencode/agents/<name>.md` (nested path = namespaced ID); frontmatter = config fields, body = system prompt. V2 fields: `description, mode, model (provider/model#variant), system, permissions, steps, hidden, color, disabled, request`; legacy `temperature, prompt, permission, tools, maxSteps` deprecated. Subagents run in a child session with fresh context in the background via the `subagent` tool and report back when idle; subagents inherit the parent's model unless set — [Agents](https://opencode.ai/docs/agents/).
- Commands: `commands` config entries with `description` + `template`; file-based commands from a `commands/` folder. Skills: `skills` array of dirs/URLs plus auto-discovery in `.opencode/skills/`. Instructions: `instructions` array (paths, globs, URLs) loaded alongside discovered `AGENTS.md`. MCP: `mcp.servers` with `type` (`local` + `command` array; remote/OAuth in MCP guide), global `mcp.timeout`. Formatters: `formatter: true` runs built-in formatters after `write`/`edit`/`patch` — [Config](https://opencode.ai/docs/config/).
- Ecosystem plugins exist at scale (e.g. oh-my-opencode, opencode-dynamic-context-pruning) and had to cut V2 releases — [newreleases oh-my-opencode](https://newreleases.io/project/github/code-yeongyu/oh-my-opencode/release/v2.0.0), [DCP v2.0.0](https://newreleases.io/project/github/Opencode-DCP/opencode-dynamic-context-pruning/release/v2.0.0).

### Inferences
- The WordPress-native analogue is obvious and cheap: `do_action`/`apply_filters` at the same seams (prompt, context/system, model.request, permission.evaluate, tool.execute.before/after, shell/command create.before, compaction) plus a `register_tool` filter. opencode's "transform returns a disposable registration" maps to filter add/remove.
- Markdown-with-frontmatter agents and skills directories are a portable format (shared with Claude Code et al.); a plugin could read them from an uploads/theme dir or a CPT.
- The V1→V2 break shows the cost of an untyped flat hook map; naming hooks by domain from day one avoids a migration.

### Gaps
- LSP integration and themes are not in the V2 config reference fetched; no V2 LSP doc read. (V1 had LSP diagnostics fed back to the model; not re-verified here.)
- Full event-type list is only in the `V2EventEncoded` schema, not enumerated in docs.
- IDE extension not mentioned in any fetched V2 page.

## Permission model

### Takeaway
Ordered allow/ask/deny rules over (action, resource-glob), last match wins, default ask, per-agent rules appended, "always" approvals persisted as project allow rules, plus a deny-only `policies` layer for admins. No sandbox: shell runs with host-user authority.

### Cited Findings
- Rule = `{action, resource, effect}`; effects allow/ask/deny; no match ⇒ `ask` — [Permissions](https://opencode.ai/docs/permissions/).
- V2 action names: `shell` (was `bash`), `subagent` (was `task`), `read`, `edit` (covers edit/write/patch), `glob`, `grep`, `skill`, `question`, `webfetch`, `websearch`, `external_directory`, `execute`, MCP tools as `<server>_<tool>`; `doom_loop` and `lsp` are not V2 actions — [Permissions](https://opencode.ai/docs/permissions/).
- Glob semantics: `*` (crosses `/`), `?`, whole-value match; shell pattern ending ` *` also matches the bare command. Load order lower-priority config → global → agent; last match wins; multi-resource ops: any deny denies, else any ask asks — [Permissions](https://opencode.ai/docs/permissions/).
- Shell: built-in scanner for Bash/Zsh/sh/PowerShell applies rules to every extracted subcommand and `cd` target; unparseable commands error rather than run; docs recommend narrow allowlists over broad denies because directory inference is best-effort — [Permissions](https://opencode.ai/docs/permissions/).
- Defaults: base policy allows everything except asks on `.env`/`.env.*` (allows `.env.example`); `plan` denies edits except under `~/.opencode/plan`; `general` denies questions/subagents; `explore` mostly denied except shell/read/glob/grep/web; `title`/`summary` deny all — [Permissions](https://opencode.ai/docs/permissions/).
- Per-agent rules under `agents.<id>.permissions` are appended after globals; a custom subagent uses its own rules, not a subset of its parent's — [Permissions](https://opencode.ai/docs/permissions/).
- `external_directory` checked before read/edit for paths outside the project; default allows it — [Permissions](https://opencode.ai/docs/permissions/).
- Ask replies: `once`; `always` (saves the tool's proposed patterns as project-scoped allow rules, never overriding configured deny); `reject` (rejects all pending in the session, optional feedback) — [Permissions](https://opencode.ai/docs/permissions/).
- `policies` run after rules and saved approvals, can only tighten (allow/ask → deny); global and Console-managed policies override project config — [Permissions](https://opencode.ai/docs/permissions/).
- No sandbox: shell "runs with the host user's filesystem, process, and network authority" (paraphrase) — [Permissions](https://opencode.ai/docs/permissions/).

### Inferences
- Directly copyable to WP: rules keyed on (ability/tool, resource pattern), last-match-wins, default ask, "always" writes a scoped allow rule, and a site-admin/network "policy" layer that can only deny (fits multisite super-admin). Their default (allow-all) is too loose for wp-admin; Alpaca Bot should invert it.
- `reject` cancelling all pending requests plus feedback-to-model is a good UX detail.

### Gaps
- Whether the permission `evaluate` hook can be used for LLM-based approval was not documented.

## Provider model

### Takeaway
Provider/model catalog comes from models.dev (run by the same company); connect with `/connect`, pick with `/models`; OpenAI-compatible custom providers and auto-discovered local servers (Ollama, LM Studio, vLLM); monetised via an optional first-party gateway (Console per-token, Go $10/month).

### Cited Findings
- Catalog from models.dev; native runtimes for OpenAI, Anthropic, Google, Azure, Bedrock, OpenRouter, xAI plus OpenAI-compatible; a `package` field can point at an npm package or `file://` runtime — [Providers](https://opencode.ai/docs/providers/). Homepage claims "200+ LLM providers through Models.dev, including local models" — [opencode.ai](https://opencode.ai/). (Older "75+ providers via AI SDK" figure is V1-era; V2 provider docs don't name the AI SDK.)
- Local: probes Ollama at `127.0.0.1:11434` and adds models automatically; LM Studio (`:1234/v1`) and vLLM (`:8000/v1`) discovered; vLLM models start with tools disabled; Ollama `settings.baseURL`/`apiKey` override with `{env:...}` substitution — [Providers](https://opencode.ai/docs/providers/).
- Custom OpenAI-compatible: `providers.<id>` with `name`, `env` list, `package: @opencode/ai/providers/openai-compatible`, `settings.baseURL`, `models` map — [Providers](https://opencode.ai/docs/providers/).
- Anomaly also makes models.dev, sst.dev, opennext, openauth; YC-listed company — [Y Combinator](https://www.ycombinator.com/companies/opencode).
- "Zen": opencode.ai/zen now renders an "OpenCode Console" page: 29 models priced per token, "zero retention by default, with some exceptions", region choice, separate Go fixed monthly plan — [opencode.ai/zen](https://opencode.ai/zen). Go is $10/month — [Intro](https://opencode.ai/docs/), [opencode.ai](https://opencode.ai/). Users complain Go has 5-hour and weekly throttles and per-model usage multipliers from early August 2026 — HN comments via search summary ([HN 49242728](https://news.ycombinator.com/item?id=49242728)), not individually verified.
- Anthropic: Claude Pro/Max login support removed citing "Anthropic legal requests" — secondary only ([dev.to](https://dev.to/thedevbrief/opencode-review-2026-the-tool-anthropic-tried-to-block-4h6d)); not verified against a primary.

### Inferences
- Pulling a shared model catalog (models.dev JSON: context window, cost, tool support) instead of hand-maintaining model metadata is copyable for Alpaca Bot; so is per-model "tools disabled" capability flags for local models, which matches Ollama reality.
- Zen appears to have been renamed/folded into "Console" — flag as uncertain.

### Gaps
- Where V2 stores credentials on disk not documented in fetched page.
- No primary for the Zen→Console rename date.

## Context management

### Takeaway
Auto-compaction with configurable token buffer and kept-tail, optional native provider compaction, a hook to customise summaries; filesystem snapshots power undo/redo/revert; sessions can be forked (fork hook) and shared.

### Cited Findings
- `compaction.auto`, `compaction.keep.tokens` (e.g. 15000 recent tokens kept), `compaction.buffer` (e.g. "15%" of window free before triggering); local summary default; native provider compaction opt-in via `settings.compaction.type` (`native`|`summary`) at provider or model level; disabling auto keeps existing checkpoints — [Config](https://opencode.ai/docs/config/).
- A hidden `compaction` agent does the summarising — [Agents](https://opencode.ai/docs/agents/); plugins can intercept via `ctx.session.hook("compaction")` — [Server plugins](https://opencode.ai/docs/build/plugins/server/).
- `snapshots` boolean toggles filesystem snapshots used by undo and revert — [Config](https://opencode.ai/docs/config/); undo/redo marketed on homepage — [opencode.ai](https://opencode.ai/).
- Session `fork` hook exists; `-opencode.fork-notice` disables a notice added to new forks — [Server plugins](https://opencode.ai/docs/build/plugins/server/), [Plugins](https://opencode.ai/docs/plugins/).
- `steps` cap per agent: on final step tools are removed so the model must summarise — [Agents](https://opencode.ai/docs/agents/).
- Third-party claim: Claude Code sends 4.7x more tokens than opencode before the prompt (logging-proxy test) — [daily.dev](https://daily.dev/posts/claude-code-sends-4-7x-more-tokens-than-opencode-before-reading-your-prompt-9m02iom1z), secondary.

### Inferences
- For Alpaca Bot: a token-buffer-triggered compaction with a kept tail, a separate cheap "compaction" agent, and a "last step strips tools" rule are all directly portable. Snapshot-based undo maps to WP revisions / option snapshots before a write tool runs.

### Gaps
- Share docs `/docs/share/` 404s in V2; share-link behaviour (manual/auto/disabled modes in V1) not re-verified for V2.

## UX loop

### Takeaway
One engine, many front-ends (TUI, desktop, web via pairing link, mobile, SDK, GitHub agent). The GitHub agent is a mention-driven Action (`/opencode`, `/oc`).

### Cited Findings
- `opencode github install` sets up the opencode-agent GitHub App, workflow `.github/workflows/opencode.yml`, and secrets; triggers on `/opencode` or `/oc` in issue/PR comments (incl. line comments), plus `issues`, `pull_request` (default prompt = review), `schedule`, `workflow_dispatch`; OIDC-exchanged app token by default or `use_github_token: true` — [GitHub](https://opencode.ai/docs/github/).
- Multiple agents can run in parallel on one project — [opencode.ai](https://opencode.ai/).
- Web via `opencode pair` one-time link — [Intro](https://opencode.ai/docs/).

### Inferences
- The "pair a browser to a running local service with a short-lived code" flow is a good model for connecting a remote Alpaca Bot UI or mobile client to a site.

### Gaps
- IDE extension (V1 had VS Code integration) not documented in fetched V2 pages.

## Distribution and community growth

### Takeaway
~228k GitHub stars, 1,000+ contributors, MIT, every package manager plus Docker and desktop installers; built by Anomaly (SST team), repo moved sst → anomalyco around January 2026; the 2025 name dispute with Charm produced the separate "Crush".

### Cited Findings
- 228.2k stars, 31.0k forks, MIT; installs via curl script, npm/bun/pnpm/yarn (`@opencode/cli`), Homebrew (`anomalyco/tap/opencode-v2` and core formula), Arch/AUR, mise, Nix, desktop cask — [GitHub](https://github.com/anomalyco/opencode). Homepage: 227,782 stars, 1,095 contributors, 20,000+ commits, "over 22M" monthly developers (self-reported, unverified) — [opencode.ai](https://opencode.ai/). V2 needs mise ≥ 2026.10.9; Windows package managers unsupported; Docker images on ghcr — [Intro](https://opencode.ai/docs/).
- sst/opencode now 301-redirects to anomalyco/opencode (noted in a January 17, 2026 PR) — [ccusage PR #812 via search](https://github.com/ryoppippi/ccusage/pull/812); January 2026 rename attributed to Dax Raad only by a community wiki — [aiwiki](https://aiwiki.ai/wiki/opencode/history). No official announcement found.
- Charm dispute (mid-2025): Charm's CEO says Charm made a deal with original author Kujtim Hoxha and that circulating claims were untrue; critics say Dax and Adam (owners of opencode.ai) forked and kept the name; Charm's continuation became Crush (July 2025); original repo archived — [HN 44483251](https://hn.svelte.dev/item/44483251), [rywalker](https://rywalker.com/research/crush) (allegations of rewritten git history are single-source, unverified). Today opencode is the TypeScript codebase, Crush the Go one.
- Adoption drivers cited: open source + any model incl. local, multi-surface, extensibility — [opencode.ai](https://opencode.ai/); "took #1 on Hacker News" — [dev.to review](https://dev.to/max_quimby/opencode-review-the-open-source-ai-coding-agent-that-took-1-on-hacker-news-4ola) (secondary).

### Inferences
- Adoption appears driven by provider neutrality (especially after Anthropic restricted subscriptions in third-party tools), a polished TUI, and an existing SST audience; the community plugin ecosystem (oh-my-opencode) amplified it.

### Gaps
- Download counts (npm/brew) not gathered.
- No primary source for the anomalyco rename rationale.

## Known weaknesses and criticisms

### Takeaway
Security has been the biggest recurring problem (unauthenticated server RCE, upgrade-endpoint RCE, malicious MCP sign-in RCE, often without CVEs), plus memory/CPU bloat, telemetry concerns, plan throttling and a breaking 2.0 migration.

### Cited Findings
- CVE-2026-22812: unauthenticated `POST /session/{id}/shell` allowed command execution; fixed in 1.0.216 — [PT Security dbugs](https://dbugs.ptsecurity.com/vulnerability/PT-2026-2315) (via search summary).
- CVE-2026-22182 command injection, CVSS 7.5, no known fixed release listed — [PT-2026-6965](https://dbugs.ptsecurity.com/vulnerability/PT-2026-6965) (via search summary; may be stale).
- `/global/upgrade` content-type confusion → code injection, GHSA-632h-h47v-g4x4, fixed 1.18.22, no CVE requested — [Datadog Security Labs](https://securitylabs.datadoghq.com/articles/opencode-upgrade-remote-code-execution/).
- Malicious remote MCP server could run code at sign-in; fixed 1.18.33 (Sept 28, 2026), no CVE/advisory, affected 1.0.137–1.18.32 — [The Hacker News](https://thehackernews.com/2026/10/opencode-flaw-lets-malicious-mcp.html).
- July 2026 critique by Luke Wren ("don't use OpenCode": RCE, bash-permission bypass, curl|bash self-upgrade, cache breakage), ~256 points on HN — reported by [labmemo](https://labmemo.com/opencode-security-critique-luke-wren-rce-bash-bypa/); original not read.
- HN complaints: hidden telemetry and code bloat; 1GB+ RAM and 100% CPU reports — [HN 49242728](https://news.ycombinator.com/item?id=49242728) and aggregators (search summary, unverified individually). A user reporting a free-usage loophole met hostile backlash — [Socket](https://www.socket.dev/blog/opencode-free-usage-bypass).
- 2.0 broke V1 plugins and the server API — [AlternativeTo](https://alternativeto.net/news/2026/10/opencode-2-0-officially-launches-with-background-agents-code-mode-and-a-rebuilt-api/). V2 event stream is volatile (no replay) — [Server](https://opencode.ai/docs/server/). `hidden` agents are explicitly "not a security control"; no sandbox — [Agents](https://opencode.ai/docs/agents/), [Permissions](https://opencode.ai/docs/permissions/).

### Inferences
- The lesson for an in-wp-admin harness: every agent-control endpoint must be authenticated and capability-checked from day one (opencode's first server was not), shell/self-update surfaces are the RCE magnets, and MCP OAuth flows need hardening. Default-allow permissions are a liability on shared WP hosting.

### Gaps
- Several CVE details came via search summaries of PT Security pages, not fetched directly; CVE-2026-22182 fix status uncertain.
- Telemetry specifics (what is collected, opt-out) not found in a primary source.
