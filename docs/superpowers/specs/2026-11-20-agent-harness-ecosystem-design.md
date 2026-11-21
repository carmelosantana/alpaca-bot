# Alpaca Bot as an agent harness: ecosystem design

_2026-11-20 · research and design only, no feature code · base: `develop` at 0.7.0_

This doc covers four things: what successful coding harnesses do, what Alpaca Bot's extension seams are missing, a set of small companion plugins, and a ranked list of what to build first. The full research report, with a citation on every claim, is in
[`docs/research/harness-roadmap/reports/Coding agent harness patterns.md`](../../research/harness-roadmap/reports/Coding%20agent%20harness%20patterns.md).
The raw notes, one file per subject, are in `docs/research/harness-roadmap/research_notes/`.

Nothing here touches the version line. Each item names the minor it would land in only as a suggestion. "1.0" stays reserved.

## 1. What makes the four harnesses work

| Harness | Core size | How it is extended | Safety model | Growth lever |
| --- | --- | --- | --- | --- |
| **Claude Code** | Large core | Almost entirely declarative files: skills (`SKILL.md`, only name and description held in context), subagents and commands as markdown with frontmatter, `plugin.json` plus marketplaces, hooks over 33 events (exit 2 or `permissionDecision` blocks; fails open unless `onFailure: "block"`) ([hooks](https://code.claude.com/docs/en/hooks), [plugins](https://code.claude.com/docs/en/plugins-reference), [skills](https://code.claude.com/docs/en/skills)) | Six permission modes; deny > ask > allow rules; settings precedence managed > local > project > user; project allow rules only apply once the folder is trusted; opt-in OS sandbox for bash ([permissions](https://code.claude.com/docs/en/permissions), [sandboxing](https://code.claude.com/docs/en/sandboxing)) | The skills repo has more stars than the CLI (198k vs 156k); 317 plugins in the official marketplace |
| **Codex CLI** | Medium Rust core | AGENTS.md (now a Linux Foundation standard; nearest file wins, 32 KiB cap) ([agents.md](https://agents.md/)), skills under `.agents/skills/` (index capped at 2% of context), about 14 hook events that can deny or rewrite, MCP client ([hooks](https://learn.chatgpt.com/docs/hooks)) | Sandbox (what the agent *can* do) kept separate from approval policy (when it must *ask*); network off by default; protected paths (`.git`, `.codex`, `.agents`) read-only even in a writable workspace; project config, hooks and MCP servers load only after a trust gate; an optional auto-reviewer that fails closed ([sandboxing](https://learn.chatgpt.com/docs/sandboxing), [approvals](https://learn.chatgpt.com/docs/agent-approvals-security)) | Signing in with a ChatGPT plan; single binary; 136k stars. Two sandbox escapes in 2026, one through `apply_patch` ([CSA note](https://labs.cloudsecurityalliance.org/wp-content/uploads/2026/09/CSA_research_note_openai_codex_sandbox_escapes_20260922-csa-styled.pdf)) |
| **pi** | Deliberately tiny: four tools, short prompt | TypeScript extensions on about 40 events (`tool_call` can block); "pi packages" bundle extensions, skills, prompt templates and themes from npm or git; even MCP ships as a *built-in extension you can switch off* ([extensions](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md), [mcp](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/mcp.md)) | No approval prompts; isolation (a container or VM) is the boundary; project trust decides whether a folder's extensions load ([security](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/security.md)) | 123k stars, thousands of community packages, embedded by OpenClaw through its SDK. The cost: many uneven, "vibe-coded" extensions |
| **opencode** | Medium; client/server | 2.0 (Oct 2026) plugins: `Plugin.define({id, setup(ctx)})` with grouped hooks (`session.prompt`, `session.compaction`, `permission.evaluate`, `tool.execute.before/after`); agents as markdown, each with its own model, prompt, permissions and step cap; skills, commands, MCP ([plugins](https://opencode.ai/docs/build/plugins/), [agents](https://opencode.ai/docs/agents/)) | allow/ask/deny rules over (action, pattern), last match wins, default ask; "always" saves a scoped allow rule; a policy layer can only tighten; **no sandbox, and the base policy allows nearly everything** ([permissions](https://opencode.ai/docs/permissions/)) | Every surface (TUI, desktop, web, SDK) is a client of one OpenAPI server; 228k stars. The cost: an unauthenticated server endpoint gave shell access (CVE-2026-22812), and the 2.0 change broke every v1 plugin |

### Patterns that carry over to Alpaca Bot

1. **Small core; first-party features shipped as extensions you can switch off** (pi, opencode built-in agents). Alpaca Bot already half-does this: `web_fetch`, `summarize`, `draft_post` and MCP are toolkits behind `toolkits.enabled`. Companion plugins should use exactly the same seam the built-ins use.
2. **Declarative before code** (Claude Code). Skills, commands and agents are markdown or JSON, not PHP. In WordPress terms, a companion that only adds a skill or a persona should not have to write a PHP class. A CPT row or a `SKILL.md` file in a plugin folder is enough.
3. **One blocking pre-tool hook, one post-tool hook** (every harness). This is the seam approvals, audit, redaction and rate limits all hang from. Alpaca Bot has `alpaca_bot/mcp/called` for MCP only, and nothing for built-in or ability tools.
4. **Keep the "can do" axis apart from the "must ask" axis** (Codex). Capabilities and Access rows already answer "can". Nothing answers "must ask": there is no approval state in a turn.
5. **Lazy disclosure** (skills index, MCP tool search, pi codemode). Only names and descriptions go in the prompt; the body loads on demand. This matters more for Alpaca Bot than for a CLI, because local Ollama models have small context windows.
6. **Trust gates and protected paths** (Codex, Claude Code). Never let the agent edit its own config or extension code. For WordPress, that means the agent cannot change Alpaca Bot's settings, `wp-config.php`, mu-plugins or any `alpaca-bot*` plugin directory.
7. **Fail closed where a hook guards safety** (Codex auto-reviewer, Claude Code's `onFailure: "block"`). Alpaca Bot already does this for its filters: a bad return is dropped or raised to a floor.
8. **A stable, versioned extension contract** (opencode's 2.0 break and pi's 1.6 render break are the warnings). No competing WordPress AI plugin offers a documented add-on contract, so that is the opening.
9. **WordPress already ships most of the hook pipeline.** WP 7.1's ability lifecycle filters (`wp_pre_execute_ability`, `wp_ability_permission_result`, `wp_ability_execute_result`, `wp_ability_invoked`) are the pre/post tool hooks, for *any* agent ([7.1 lifecycle filters](https://make.wordpress.org/core/2026/07/29/new-execution-lifecycle-filters-for-the-abilities-api-in-wordpress-7-1/)). Alpaca Bot should treat abilities as its tool contract and hook these filters, not invent a parallel pipeline.

Competition: the canonical AI plugin (1.5.0) already has an MCP Access screen and lists a Site Agent for 1.6.0. Alpaca Bot's edge has to be the private, local-model harness with a documented add-on contract, not "an agent in wp-admin".

What not to copy: opencode's allow-everything default, pi's "isolation is your problem" stance (a WordPress site has no container around a turn), and anything that runs a long-lived Node process next to PHP.

## 2. Extension-point audit (`develop` @ 0.7.0)

### What already exists

| Seam | Where | Verdict |
| --- | --- | --- |
| `alpaca_bot/toolkits` filter | `src/Toolkit/Registry.php:131` | Works, but a toolkit added here gets **no Settings entry, no Access row, no UI**, and the companion has to implement `AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface`, a Strauss-prefixed vendor interface. That is not a public contract: a php-agents bump can break every companion. |
| Abilities allowlist (`AbilitiesToolkit`) | `src/Toolkit/AbilitiesToolkit.php` | **The best seam we have.** Any plugin that registers a WP ability can be ticked under Settings › Tools and the model gets it, under the ability's own permission check, run as the turn's user, with nested calls fenced by the allowlist. This is the companion contract to promote. |
| `alpaca_bot/abilities` | `src/Abilities/Register.php:266` | Fine. |
| `alpaca_bot/context/sources`, `alpaca_bot/context` | `src/Context/Collector.php` | Fine for always-on context. No lazy or on-demand variant exists. |
| `alpaca_bot/system_prompt`, `message/before_send`, `message/after_receive` | `src/Chat/Pipeline.php` | Fine. |
| `alpaca_bot/chat/started`, `chat/completed`, `chat/failed`, `usage/recorded`, `mcp/called` | Pipeline, UsageMeter, McpToolkit | Fine for analytics and audit of turns. No per-tool equivalent except MCP. |
| `alpaca_bot/provider`, `alpaca_bot/models` | `src/Provider/` | Fine. A provider companion can be written today. |
| `alpaca_bot/rest/controllers` | `src/Plugin.php:338` | Fine; it gives a companion the namespace, capability filters and rate limit. |
| `alpaca_bot/capability/*`, `rate_limit`, `cap/allowed`, `stream/*`, `render/allowed_tags` | Access, RateLimit, CapPolicy, StreamBudget, Markdown | Fine. |

### Gaps, in dependency order

| # | Gap | Why a companion needs it | Shape (suggested) | Size |
| --- | --- | --- | --- | --- |
| G1 | **No boot signal or API version** | A companion cannot know Alpaca Bot is loaded, or which contract it speaks. `Requires Plugins` takes slugs only, with no version and no load-order guarantee ([6.5 dev note](https://make.wordpress.org/core/2024/03/05/introducing-plugin-dependencies-in-wordpress-6-5/)). | `do_action('alpaca_bot/loaded', string $apiVersion)` at the end of `Plugin::register()`, plus an `ALPACA_BOT_API` constant. Document that `alpaca_bot/*` hooks follow `apply_filters_deprecated` for one minor before removal. | XS |
| G2 | **No per-tool hooks for built-in or ability tools** | Approvals, audit, redaction and per-agent narrowing all need them. Today only MCP calls are observable. | Filter `alpaca_bot/tool/before_call` (`null\|WP_Error $verdict, string $toolkitId, string $tool, array $args, int $userId, Conversation $c`): a WP_Error refuses the call and the model reads its message. Action `alpaca_bot/tool/called` (same args plus `ToolResult`). Fire both from one wrapper around every toolkit's tools, so MCP, abilities and built-ins share a path; keep `mcp/called` as an alias. For abilities, *also* honour core's 7.1 `wp_pre_execute_ability`, so a guard written for any agent applies. The floor is WP 6.9, where those core filters don't exist, so our own hook is the guarantee and the core filters are a bonus on 7.1+. | S |
| G3 | **No "ask" state in a turn** | Codex's split between "can" and "must ask". Without it, destructive tools can only be on or off. | `before_call` may return a `Pending` verdict. The pipeline then ends the turn with a `tool_approval_required` frame carrying a signed ticket, and `POST /chat/{id}/approve` resumes the turn. Same pattern as the stream ticket. | M |
| G4 | **Third-party toolkits get no row and no switch** | A companion toolkit is invisible on Settings › Tools and Access, so the admin cannot see it or gate it. | `do_action('alpaca_bot/register_toolkits', Registrar $r)`, where `Registrar::add(id, toolkit, label, description, defaultCapability)` produces the `toolkits.enabled` checkbox and the `tool.{id}` Access row. Schema and Access defaults then read the registrar. Keep the `alpaca_bot/toolkits` filter as the "last word". | S–M |
| G5 | **The toolkit interface is vendor-prefixed** | Companions would be compiled against `AlpacaBot\Vendor\…`. | Prefer abilities (no interface needed). For turn-aware tools (streaming, nested model calls), publish a thin `AlpacaBot\Api\Tool` interface (name, description, JSON-schema input, `run(array, Turn): string\|WP_Error`) and adapt it internally. | S |
| G6 | **No JS extension points in the chat UI** | Composer buttons, tool-call cards, message renderers, slash-command menus. | Use `@wordpress/hooks` (core's `wp-hooks` script, added as a dependency of our bundles): `alpacaBot.composer.actions`, `alpacaBot.message.render`, `alpacaBot.toolCall.render`, and actions `alpacaBot.turn.started` / `alpacaBot.turn.completed`. Expose a `ready` promise from `boot.ts`. | S |
| G7 | **No commands or prompt templates** | `/summarize-this`, `/draft-from-outline`: the cheapest declarative extension. | Filter `alpaca_bot/commands` (`name => {description, template, toolkits?, model?}`) plus `GET /commands`; the composer reads it (G6). | S |
| G8 | **No agents or personas** | opencode and Claude Code agents: a prompt, a tool allowlist, a model and a step cap, chosen per turn. | Filter `alpaca_bot/agents`; the turn option `agent` narrows `Registry::enabled()` and sets the system prompt, model and max iterations. Narrowing only: an agent can never widen what the user's rows allow. | M |
| G9 | **No lazy skills** | Small local contexts. | A `skills` toolkit: names and descriptions go into the system prompt, and the `load_skill(name)` tool returns the body. The source is a filter `alpaca_bot/skills`, so a companion can read `SKILL.md` files ([Agent Skills](https://agentskills.io)) from plugin folders. | S |
| G10 | **No conversation lifecycle hooks beyond a turn** | Memory, export and retention companions. | Actions `alpaca_bot/conversation/saved` and `alpaca_bot/conversation/deleted`. | XS |
| G11 | **No compaction** | Long chats on 8k–32k local models. | Filter `alpaca_bot/history` over the messages sent to the provider, with a default that trims oldest-first by token estimate. A companion can then summarise instead of trim (opencode's `session.compaction`, pi's `session_before_compact`). | S–M |
| G12 | **No "what's installed" view** | Admins need to see who added which tool, source or command. Settings › Access already shows "code changed this row". | A read-only "Extensions" section listing registrar entries, context sources, commands, agents and the owning plugin (from `debug_backtrace` at registration, or a declared `plugin` field). | S |

Abilities need no new seam: core 6.9–7.1 already provides registration, REST, a JS client and lifecycle filters, and Alpaca Bot already consumes them.

## 3. Live plugin editing with a sandbox

Editing PHP on a live site is remote code execution by design. The prior art is grim: WP File Manager's elFinder connector (CVE-2020-25213, mass-exploited, in CISA's Known Exploited Vulnerabilities list) ([NVD](https://nvd.nist.gov/vuln/detail/CVE-2020-25213)). AI Engine, the closest analogue to Alpaca Bot, had an admin RCE through its log path, a Subscriber file upload, and three MCP authorization failures, one of them unauthenticated with CVSS 9.8 ([CVE-2025-11749](https://nvd.nist.gov/vuln/detail/CVE-2025-11749), [Wordfence](https://www.wordfence.com/blog/2025/06/100000-wordpress-sites-affected-by-privilege-escalation-via-mcp-in-ai-engine-wordpress-plugin/)). None of those were the model's fault; they were unguarded endpoints. Add prompt injection, where a fetched page tells the agent to write a backdoor, and any agent with a write tool plus `web_fetch` in one turn is one bad page away from planting PHP.

| Option | How | Isolation | Verdict |
| --- | --- | --- | --- |
| **A. Playground preview** | Load the edited plugin into WordPress Playground (WASM PHP 8.4, SQLite) in an iframe in wp-admin, using `@wp-playground/client` and a Blueprint (`writeFile`, `activatePlugin`, `runPHP`). Automattic's Telex uses this loop: generate, preview in Playground, download ([Playground](https://developer.wordpress.org/playground/developers/build-an-app/), [Telex](https://kinsta.com/blog/wordpress-blocks-telex/)). | **Real.** The code runs in the admin's browser, never on the host. | Best preview. Caveats: SQLite, not the site's data; networking on by default (turn it off); loading from playground.wordpress.net sends the code to a third-party CDN (self-host the bundle or ask first). |
| **B. Staged write + loopback + atomic swap** | Write to a staging copy, run `php -l`, run core's file-editor style loopback scrape ([`wp_edit_theme_plugin_file`](https://developer.wordpress.org/reference/functions/wp_edit_theme_plugin_file/)) against the staged copy, then `rename()` into place and invalidate opcache; keep a git or zip snapshot for one-click rollback. | **None at runtime.** Only checks that it doesn't fatal. | Needed for "apply". Core's own check fails *open* when loopbacks are blocked (Basic auth, Trac [#42102](https://core.trac.wordpress.org/ticket/42102); multisite, [#44514](https://core.trac.wordpress.org/ticket/44514)), so refuse to apply when the loopback can't run. |
| **C. Shadow slug** | Copy to `plugin-slug--alpaca-preview`; swap it for the real one per request via an `option_active_plugins` filter in an mu-plugin, gated by an admin cookie. | None; shares the database. | Lets the admin click through real data. Fragile: same-named classes collide, and activation hooks don't fire. A distant third. |
| **D. Filesystem jail** | `open_basedir` and `disable_functions` on the write tool's process. | Partial, and it is the host's setting, not ours. | Not available from a plugin on shared hosting. |
| **E. Recovery mode** | Core 5.2 pauses an extension that fatals, for the admin's session, and emails a link ([dev note](https://make.wordpress.org/core/2019/04/16/fatal-error-recovery-mode-in-5-2/)). | After the fact. | A backstop only. |

Hard constraints for any design:

- Respect `DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS`, and detect a read-only filesystem. WP VIP, Pantheon Test/Live and WP Engine Autoscale are read-only. There, fall back to "here is the diff / zip / PR" and never try anything cleverer.
- **Scope the agent to plugins it owns.** It writes only under `wp-content/plugins/alpaca-workbench-*` (or one named workspace), never Alpaca Bot itself, never mu-plugins, never `wp-config.php`, never another vendor's plugin. That is Codex's protected-paths rule.
- `manage_options` *and* a separate capability (`alpaca_bot_edit_code`) granted to nobody by default. Every apply is a G3 approval, every write a G2 audit row, and no turn that has `web_fetch` or MCP in it may also hold the write tool (taint by toolkit).
- The agent proposes a patch; a human applies it. No "auto-apply" mode, ever.

**Recommendation:** A + B + scoping, as a separate companion plugin (`alpaca-bot-workbench`) that is never in core. WPCode's snippet model (store code as data, test-run on activation, auto-deactivate on fatal, `?wpcode-safe-mode=1`) is the lower-risk shape if Carmelo only wants "small site tweaks" rather than plugin development ([WPCode](https://wpcode.com/docs/how-to-debug-php-errors-in-wpcode/)).

## 4. An IDE inside WordPress

| Option | Weight | Fit |
| --- | --- | --- |
| **CodeMirror 6** (+ `@codemirror/merge`) | about 50–200 kB | **Pick this.** It has a merge/diff view, a PHP mode, is MIT-licensed, and Replit and Sourcegraph both moved to it from Monaco ([Replit](https://replit.com/blog/codemirror), [Sourcegraph](https://sourcegraph.com/blog/migrating-monaco-codemirror)). Core still ships CodeMirror 5 via `wp_enqueue_code_editor` with no v6 ticket found, so bundle our own. |
| Monaco | about 5 MB gzipped | VS Code feel, but heavy for wp-admin, and LSP still needs a server. |
| openvscode-server / code-server / Theia | A Node service, ≥1 GB RAM, WebSockets, a shell as the web user | Only for a self-hosted dev box or devcontainer. Out of scope for a wp.org plugin; it is a separate product. |
| Playground + its VS Code extension | Browser | Good for "open this plugin in a sandbox"; pairs with option A above ([VS Code extension](https://developer.wordpress.org/playground/developers/local-development/vscode-extension/)). |

How it ties into chat: the IDE is a **review surface, not an editor-first app**. The agent's write tool returns a proposed patch (Codex's context-anchored `apply_patch` format is a good wire shape, but parse it defensively: it was one of Codex's 2026 escape routes). The chat renders a tool-call card (G6), "Open diff" shows the CodeMirror merge view, and the buttons are *Preview in Playground*, *Apply* (B, behind G3) and *Discard*. The same diff panel works for post content, which is a low-risk first customer: "show me what `draft_post` would change".

## 5. Companion plugin ideas

Each is a standalone plugin with `Requires Plugins: alpaca-bot` that checks the `ALPACA_BOT_API` constant from G1. Size: XS < 1 day, S ≤ 3 days, M ≤ 2 weeks, L more than that.

| Plugin | What it does | Size | Risk | Needs |
| --- | --- | --- | --- | --- |
| **alpaca-bot-audit** | One row per tool, ability or MCP call (who, turn, tool, arguments hash, result size, verdict) in a CPT or WP Activity Log; a receipts viewer next to Usage. | S | Low (a privacy setting for arguments) | G2 (`mcp/called` works today) |
| **alpaca-bot-approvals** | "Ask before" rules per tool or ability, keyed on annotations (`destructive`) *and* an admin list, because annotations are only hints; approve or deny in the chat UI; hooks `wp_pre_execute_ability` so it also guards other agents (the MCP Adapter). | M | Medium (it is a security control, so it must fail closed) | G2, G3, G6 |
| **alpaca-bot-skills** | Loads `SKILL.md` folders from active plugins and themes (`/alpaca-skills/*`) and an admin CPT; lazy-loads them through `load_skill`. | S | Low | G9 (could prototype today through `alpaca_bot/toolkits` + `alpaca_bot/system_prompt`) |
| **alpaca-bot-site-memory** | A site-level "AGENTS.md" (admin-edited house style and facts) plus opt-in per-user memory, as a context source. | XS–S | Low (per-user privacy) | None: `alpaca_bot/context/sources` exists |
| **alpaca-bot-commands** | Slash commands and prompt templates as a CPT, shown in the composer menu. | S | Low | G6, G7 |
| **alpaca-bot-agents** | Personas as a CPT: prompt, model, toolkit allowlist, step cap; a picker in the composer. | M | Low (narrow-only) | G6, G8 |
| **alpaca-bot-webhooks** | `chat/completed` and `tool/called` → Slack, Discord or a generic webhook, sent from WP-Cron. | XS | Low (outbound URL, address-pinned like `web_fetch`) | None |
| **alpaca-bot-scheduler** | Run a saved prompt or agent on WP-Cron ("weekly drafts digest"), with results stored as a conversation. | M | Medium (unattended token spend, which caps already bound) | G8 optional |
| **alpaca-bot-site-doctor** | Read-only diagnostic abilities: Site Health results, active plugins and versions, the tail of `debug.log` (redacted), cron queue. | S | Medium (information disclosure; `manage_options` only) | None: abilities |
| **alpaca-bot-woo** | WooCommerce abilities: order lookup, product draft, stock report. | M | Medium (customer PII) | None: abilities |
| **alpaca-bot-diff** | The CodeMirror 6 merge panel as a reusable chat card (posts first, code later). | M | Low | G6 |
| **alpaca-bot-workbench** | Sandboxed plugin authoring (§3: Playground preview, staged apply, rollback, scoped paths). | L | **High** | G2, G3, G6, diff, audit |
| alpaca-bot-kb | Retrieval over posts (embeddings, possibly the AI plugin's table). | M–L | Medium | `context/sources`; overlaps the core AI plugin's roadmap, so wait and see |

Several of these need **no core change at all** (site-memory, webhooks, site-doctor, woo). They can ship as soon as Carmelo wants them, and they make good tests of whether the abilities-first contract is enough.

## 6. Ranked recommendation

1. **Extension API minor (core): G1 + G2 + G4 + G6, with G10 as a rider.** All small. They turn "filter the array and hope" into a contract, and every companion below needs at least one of them. G5 is a docs decision here ("companions ship abilities"), not code. *Size about M in total; risk low; no new user-facing behaviour.*
2. **alpaca-bot-audit.** The first companion: it proves G2 end to end, it is the trust story the AI Engine CVEs say this category lacks, and it is tiny.
3. **alpaca-bot-site-memory + alpaca-bot-skills.** Cheap, high leverage for small local models, and built on open formats (AGENTS.md, Agent Skills). site-memory needs no core change and can ship first.
4. **G3 + alpaca-bot-approvals.** The "must ask" axis. It unlocks destructive abilities safely and is the prerequisite for anything that writes code.
5. **G7/G8 + commands + agents.** The UX layer, once the hooks exist.
6. **alpaca-bot-diff**, starting with post edits.
7. **alpaca-bot-workbench, last**, and only if 1–6 have landed and Carmelo still wants plugin authoring on a live site. Otherwise, ship its Playground-only half (preview and download a zip, never apply).

## Open questions for Carmelo

- Does the companion contract become **"ship WordPress abilities"** (no Alpaca-specific PHP interface) with `AlpacaBot\Api\Tool` only for turn-aware tools, or does Alpaca Bot publish a full toolkit API?
- Do companions live in their own repos (one plugin per repo, like the Kanboard suite) or in one monorepo with a sync script?
- Is live plugin editing in scope at all for a wp.org plugin, or is the workbench a Playground-only preview-and-download tool?
