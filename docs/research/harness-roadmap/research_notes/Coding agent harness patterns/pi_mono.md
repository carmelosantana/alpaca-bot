# pi (pi-mono / earendil-works/pi): minimal coding agent, small core plus extensions

Researched 2026-11-20. Current release is **v1.6.0 (2026-11-20)**. Read the staleness notes first.

**Staleness and identity notes (they matter for every section below):**
- The repo moved from `badlogic/pi-mono` to **`earendil-works/pi`**, and the npm scope moved from `@mariozechner/*` to **`@earendil-works/*`** in 0.73/0.74 (2026-05-07). The old URL redirects. Sources: [GitHub API repo record](https://github.com/earendil-works/pi); [CHANGELOG 0.74.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md).
- **Earendil (Armin Ronacher's company) acquired pi in April 2026.** Zechner joined Earendil and leads technical direction. See [Zechner, "I've sold out" (2026-04-08)](https://mariozechner.at/posts/2026-04-08-ive-sold-out/) and [Ronacher (2026-04-08)](https://lucumr.pocoo.org/2026/4/8/mario-and-earendil/).
- **pi 1.0 shipped on 2026-10-01** ([CHANGELOG 1.0.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)).
- **The "no MCP" stance is out of date.** Since 0.99.0 (2026-09-29), MCP, codemode and tool_search ship as *built-in extensions* ([CHANGELOG 0.99.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)). Any source dated before late September 2026 that says "pi has no MCP" is stale. That includes Zechner's Nov 2025 post, Ronacher's Jan 2026 post and some 2026 reviews.

## Design philosophy: why so few tools, a tiny prompt, and no MCP, subagents, plan mode or todos

### Takeaway
Zechner built pi because Claude Code kept changing its prompt and tools under him. He wanted full control over what enters context and a clean, inspectable session format. Every left-out feature has a stated reason: it costs context, it hides what the agent is doing, or models already do it well with bash and files. The answer to "I need X" is "have pi write an extension, or install a package." That stance survives in 1.x, with one notable reversal on MCP.

### Cited Findings
- **Motivation.** Claude Code's system prompt and tools changed every release, which broke his workflows. He wanted to control context exactly and to get a clean, documented session format. — [Zechner, "What I learned building an opinionated and minimal coding agent" (2025-11-30)](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Four tools: read, write, edit, bash.** Read-only grep, find and ls exist but are off by default. The reasoning is that models already know bash and are trained on similar read/write/edit schemas. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/). The 1.x docs still say the default enabled tools are `read`, `bash`, `edit` and `write`, configurable with `defaultTools` and `--tools +x,-y`. — [docs/cli.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/cli.md)
- **System prompt plus tool definitions come in under about 1,000 tokens.** The argument is that frontier models are RL-trained enough to know what a coding agent is, so a 10k-token prompt is unnecessary. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/). Ronacher calls it the shortest system prompt of any agent he knows. — [Ronacher, "Pi" (2026-01-31)](https://lucumr.pocoo.org/2026/1/31/pi/)
- **Why no MCP (original reasoning).** Popular servers load big tool schemas into every session: Playwright MCP is about 13.7k tokens and Chrome DevTools MCP about 18k. He preferred CLI tools with READMEs that the agent reads on demand, and named mcporter as a fallback. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/). Ronacher adds a second reason: MCP tools load at session start, so reloading them breaks the prompt cache or confuses the model. — [Ronacher 2026-01-31](https://lucumr.pocoo.org/2026/1/31/pi/)
- **Why no subagents.** They are black boxes with poor context transfer. Gather context in a separate session that produces an artifact instead. The one exception he allows is spawning pi through bash, for example for a review. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Why no plan mode.** Use a PLAN.md file, which can be edited, versioned and shared across sessions. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Why no built-in todos.** Models get confused tracking todo state, so a checkbox TODO.md is used instead. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Why no background bash.** Use tmux, which is observable and lets you join the session. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Current README wording.** Pi ships "powerful defaults" but skips subagents and plan mode on purpose. Users ask Pi to build what they need, or install a package. Tagline: "Adapt Pi to your workflows, not the other way around." — [README](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/README.md)
- **Evidence offered.** He ran Terminal-Bench 2.0 with Opus 4.5 and pointed to Terminus 2, a tmux-only agent, scoring competitively as support for minimalism. He also conceded that benchmarks don't capture real use. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **The MCP reversal.** 0.99.0 "Added codemode, tool search, and MCP support as built-in extensions" — [CHANGELOG 0.99.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md). Earendil's stated reasons are that the protocol matured and pi needed a similar execution environment anyway. — [trendingtopics.eu on Pi 1.0 (2026-10-02)](https://www.trendingtopics.eu/pi-1-0-earendil-en/) (secondary source)

### Inferences
- The reversal kept the original *token* objection intact. MCP tools default to `codemode` exposure, which means they are not declared to the model at all and are found on demand (see the extension section). In practice this answers "MCP is acceptable if its schemas don't sit in context."
- Even the features pi added, MCP and codemode, were shipped as *built-in extensions* that can be turned off (`-builtin:mcp`), not as core changes. For a small core with companion plugins this is the closest analogue: first-party features packaged as removable plugins.

### Gaps
- I found no post by Zechner himself explaining the MCP reversal. The only explanation is secondary press quoting Earendil.
- I did not measure the current 1.x system prompt size. The "<1,000 tokens" figure is from Nov 2025. With codemode on, one GPT-5.6 request was about 3,300 tokens ([CHANGELOG 1.0.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)), so the prompt has grown once optional features are enabled.

## Extension model: hooks, tools, commands, UI, skills, templates, themes, packages and MCP

### Takeaway
Extensions are in-process TypeScript modules: a factory that receives `pi: ExtensionAPI`. They can register tools, commands, shortcuts, flags, providers, virtual models, MCP servers, renderers and full TUI components. They can also subscribe to roughly 40 lifecycle events and block or rewrite behaviour. Skills, prompt templates and themes are lighter resources. A "pi package" bundles any of these and installs from npm, git or a local path. Extensions run with full process permissions and are gated only by a project-trust prompt.

### Cited Findings
- **Shape.** `export default function (pi: ExtensionAPI) { pi.on(...); pi.registerTool(...); pi.registerCommand(...) }`. Tool parameters are TypeBox schemas. A tool's `execute(toolCallId, params, signal, onUpdate, ctx)` returns `{content, details}`, so the model-facing output and the UI/state `details` are kept apart. — [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **Capabilities listed in the docs:** custom tools, event interception (block or modify tool calls, inject context, custom compaction), user prompts through `ctx.ui` (select, confirm, input, notify), full custom TUI components through `ctx.ui.custom()`, slash commands, session persistence through `pi.appendEntry()`, custom rendering, and registering providers and MCP servers. — [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **Example uses the docs suggest.** Permission gates such as confirming `rm -rf`, git checkpointing per turn, path protection, custom compaction, and stateful tools such as todo lists. In other words, the features left out of core are expected to come back as extensions. — [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **Event names, from the doc's table of contents:**
  - Startup and resources: `project_trust`, `resources_discover`, `mcp_servers_change`.
  - Session: `session_start`, `session_info_changed`, `session_before_switch`, `session_before_fork`, `session_before_compact`/`session_compact`/`session_compact_failed`, `session_before_tree`/`session_tree`, `session_shutdown`.
  - Agent: `before_agent_start`, `agent_start`/`agent_end`/`agent_before_settle`/`agent_settled`, `ui_prompt_start`/`end`, `turn_start`/`turn_end`, `message_start`/`update`/`end`, `tool_execution_start`/`update`/`end`, `context`, `context_with_system`, `before_provider_headers`, `before_provider_request`, `after_provider_response`, `provider_stream_event`, `cache_warming_decision`.
  - Model: `model_select`, `thinking_level_select`.
  - Tool: `tool_call` (can return `{block, reason}`) and `tool_result`.
  - Other: `user_bash` and `input`.
  - Source: [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **API methods:**
  - Messaging and session: `pi.sendMessage`, `sendUserMessage`, `appendEntry`, `setSessionName`, `setLabel`.
  - Commands and input: `registerCommand`, `registerShortcut`, `registerFlag`.
  - Rendering: `registerMessageRenderer`, `registerEntryRenderer`, `registerToolRenderer`, `registerMarkdownTransformer`.
  - Tools: `getActiveTools`/`setActiveTools`.
  - Models: `setModel`, `registerProvider`/`unregisterProvider`, `registerVirtualModel`.
  - MCP: `registerMcpServer`.
  - Other: `pi.exec`, `pi.events` (an inter-extension bus).
  - Command contexts can also call `ctx.newSession`, `fork`, `navigateTree`, `switchSession`, `compact` and `reload`.
  - Source: [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **Built-in tools can be overridden.** Registering a tool named `read`, `bash`, `edit`, `write` and so on replaces the built-in one silently. `--no-builtin-tools` starts with extension tools only. Rendering falls back to the built-in renderer slot by slot. — [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **State that works with branching.** Extensions should keep state in tool-result `details` and rebuild it from `ctx.sessionManager.getBranch()` on `session_start` and `session_tree`. `appendEntry` is for durable data the model should not see. — [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **Discovery and hot reload.** Extensions are found in `~/.pi/agent/extensions/` (global) and `.pi/extensions/` (project, only after trust is granted), either as single files, `*/index.ts`, or a `package.json` with a `pi.extensions` field. `/reload` hot-reloads them. The doc opens with "Pi can create extensions. Ask it to build one for your use case." — [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md). Ronacher describes the loop: the agent writes an extension, reloads it, tests it and iterates, and pi ships docs and examples so it can extend itself. — [Ronacher 2026-01-31](https://lucumr.pocoo.org/2026/1/31/pi/)
- **Pi packages.** Install with `pi install npm:@x/y@1.0.0 | git:github.com/u/r@v1 | ./local`. Try one without installing with `pi -e npm:...`. Manage with `pi list`, `pi remove`, `pi update --extensions`. A package can use conventional `extensions/ skills/ prompts/ themes/` directories or a `pi` key in `package.json`, and conventionally has the `keywords: ["pi-package"]` keyword. Versioned npm specs and git tags are pinned. A package that fails to install produces a warning and is skipped, so pi keeps running. — [docs/packages.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/packages.md)
- **Skills** follow the Agent Skills spec (agentskills.io): a `SKILL.md` with frontmatter, plus `scripts/`, `references/` and `assets/`. Only the name and description are advertised to the model, and the body loads on demand. — [docs/skills.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/skills.md). Prompt templates are Markdown that expands from `/template`. Themes are JSON. — [docs/how-pi-works.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/how-pi-works.md)
- **Input pipeline order:** extension `/commands` first, then `input` handlers, then `/skill:name` and template expansion, then `before_agent_start`, then the agent loop. — [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **Built-in extensions.** `builtin:mcp`, `builtin:llama.cpp`, `builtin:codemode` and `builtin:tool-search` load by default. `-builtin:mcp` disables one, and `--no-extensions` disables them all. — [docs/settings.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/settings.md)
- **How MCP works today:**
  - Configuration lives in `~/.pi/agent/mcp.json` and `.pi/mcp.json` (the latter only after trust), managed with `pi mcp add/list` and `/mcp`. Transports are stdio and streamable HTTP; SSE is rejected. OAuth is supported.
  - Each server has an `exposure` setting:
    - `codemode` (default): not declared to the model; called from JavaScript scripts in a QuickJS sandbox.
    - `deferred`: declared after a `tool_search` hit.
    - `direct`: declared like a built-in tool.
    - `hidden`: registered but unreachable.
  - Tools are named `mcp__<server>__<tool>`.
  - Source: [docs/mcp.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/mcp.md)
- **Codemode.** The model writes JavaScript that calls `tools.<name>()` in a QuickJS sandbox with no Node, filesystem or network access. Only the script's output reaches the model, which makes parallel calls and filtering of large results possible. Undeclared tools are found with `searchTools()` (BM25) and `describeTool()`. Listed declarations are capped at 3,000 estimated tokens. — [docs/codemode.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/codemode.md)
- **Per-tool exposure is an extension API too:** `exposure` (`direct`, `model-only`, `codemode`, `deferred`, `hidden`), `namespace`, `outputSchema`, and `ctx.executeTool()` for nested calls. — [CHANGELOG 0.99.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)
- **Community extensions referenced in the changelog and posts:** pi-web-access, pi-subagents and pi-interactive-shell. Ronacher's own are `/answer`, `/todos`, `/review`, `/control` and `/files`. — [CHANGELOG 1.6.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md); [Ronacher 2026-01-31](https://lucumr.pocoo.org/2026/1/31/pi/)

### Inferences
- Patterns that carry over to Alpaca Bot's companion plugins:
  - One registration API covers tools, commands, UI and providers. In WordPress terms, a hook-and-filter surface owned by the core.
  - A `{content, details}` split: content goes to the model, details go to the UI and state.
  - Lifecycle events that can *block or rewrite* (`tool_call` → `{block}`). This is where permission gates live.
  - Branch-aware state rebuilt from the session.
  - Per-tool exposure tiers, so add-on tools don't inflate every prompt.
  - A built-in extension can be switched off like a third-party one.
- Pi has no sandbox or permission layer between core and extensions. A WordPress host would likely want capability checks, which pi deliberately leaves out.

### Gaps
- I did not count events exactly. The list above comes from the headings in extensions.md.
- I found no official registry or curated index of extensions. Ecosystem size is under "Community growth".

## Provider abstraction (pi-ai)

### Takeaway
pi-ai is a unified streaming LLM API. A `Models` collection holds provider objects, each owning its catalog, its auth and its stream behaviour. Underneath sit a handful of wire protocols. It does cross-provider handoff, tracks tokens and cost per message, supports abort and continue-after-abort, and ships a fake provider for tests.

### Cited Findings
- **Provider list.** About 35 built-in providers, including OpenAI, Azure, OpenAI Codex (ChatGPT subscription OAuth), Anthropic, Google, Vertex, Mistral, Groq, Cerebras, xAI, OpenRouter, Vercel AI Gateway, Bedrock, GitHub Copilot (OAuth), DeepSeek, Moonshot, MiniMax, Qwen, Fireworks and Hugging Face, plus "any OpenAI-compatible API" such as Ollama, vLLM and LM Studio. — [packages/ai/README.md](https://github.com/earendil-works/pi/blob/main/packages/ai/README.md)
- **Wire protocols.** Providers share API implementations: `anthropic-messages`, `openai-responses`, and `openai-completions` for xAI, Groq, Cerebras, OpenRouter and most others. Each provider is a factory with its own subpath import, so it can be tree-shaken. — [packages/ai/README.md](https://github.com/earendil-works/pi/blob/main/packages/ai/README.md). The original framing was that four APIs cover nearly every provider. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Cross-provider handoff.** User and tool-result messages pass through unchanged. Assistant messages from the same provider, API and model keep their thinking blocks. When the model changes, thinking blocks are dropped, because signatures and encrypted reasoning only work with the model that produced them. — [packages/ai/README.md](https://github.com/earendil-works/pi/blob/main/packages/ai/README.md). In Nov 2025 the approach was to convert thinking to tagged text on a best-effort basis; current docs say it is dropped, so the behaviour changed. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Cost.** `usage.cost.total` holds the provider-billed cost where one exists (for example OpenRouter), otherwise an estimate from the catalog rates. `usage.cost.totalFromProvider` flags which it is. — [packages/ai/README.md](https://github.com/earendil-works/pi/blob/main/packages/ai/README.md). Zechner warned that the tracking is "for personal use, not billing" because some providers report usage only at the end of a stream, which goes wrong on abort. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Why he built his own.** He avoided the Vercel AI SDK to get more control, a smaller surface and better tool-calling against self-hosted models. Tools return an LLM part and a UI part, and arguments are validated with TypeBox and AJV. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Virtual models** (0.99+). Extensions can route each request to a different physical model, and `/session` shows cost per physical model. — [CHANGELOG 0.99.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)

### Inferences
- Alpaca Bot's PHP agent library plays the pi-ai role. The ideas worth copying are a provider-owned catalog plus auth, a handful of shared wire protocols, a cost field that says whether it was billed or estimated, and a rule for stripping thinking blocks on handoff.

### Gaps
- I did not verify a precise provider count beyond the README list.

## Permission and sandbox stance

### Takeaway
pi runs in YOLO mode: no per-tool approval, full OS permissions. The documented safety story is isolation (a container, VM or sandbox), not prompts. The only built-in gate is *project trust*, which decides whether a folder's `.pi/` resources (extensions, MCP, settings) load. Approval prompts are something you add with an extension.

### Cited Findings
- **YOLO by default.** Zechner calls other agents' permission prompts largely "security theater": an agent that can read private data, run code and reach the network can't be made safe that way. Use a container instead. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **The current docs keep the stance.** Pi "does not ask for approval before every tool call". Watching the transcript, granting trust and reviewing changes "do not create a security boundary". Running entirely inside a container, VM or sandbox "is usually the strongest practical option". — [docs/security.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/security.md)
- **Project trust.** A trust decision is needed when `.pi/settings.json`, `.pi/mcp.json`, `.pi/extensions|skills|prompts|themes`, `.pi/SYSTEM.md` or `APPEND_SYSTEM.md`, or `.agents/skills` exist. The decision comes from a CLI flag, then an extension's `project_trust` handler, then a saved decision in `~/.pi/agent/trust.json`, then the `defaultProjectTrust` setting (default `"ask"`). Context files (AGENTS.md, CLAUDE.md) load regardless of trust. — [docs/security.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/security.md)
- **Isolation recipes.** Plain Docker, Docker Sandboxes (a credential proxy), OpenShell, and the Gondolin extension, which puts only the built-in tools in a local micro-VM. — [docs/containerization.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/containerization.md)
- **Permission gates are extension territory.** The docs' quick-start example is a `tool_call` handler that confirms `rm -rf`. — [docs/extensions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/extensions.md)
- **Security-report scope.** Prompt injection, the lack of a built-in sandbox, and user-installed extension behaviour are generally outside the boundary. — [docs/security.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/security.md)

### Inferences
- Copying pi's stance directly into a WordPress plugin is risky. A shared host has no container boundary, and WordPress has a native capability model. What does transfer is the *mechanism*: one blockable `tool_call` event that permission plugins hook into, plus a trust gate for third-party add-ons.

### Gaps
- None material.

## Context management: session trees, compaction and context files

### Takeaway
Sessions are append-only JSONL trees. You branch in place (`/tree`), fork (`/fork`) or clone (`/clone`), and only the active branch is sent to the model. Leaving a branch can summarize it into the one you enter. Auto-compaction inserts a summary entry while keeping the original entries. The system prompt is the base instructions plus discovered context files, and can be replaced or appended with `.pi/SYSTEM.md` or `APPEND_SYSTEM.md`.

### Cited Findings
- **Session tree.** Sessions are JSONL, each entry has an id and a parentId, and the "current entry" defines the active branch. Continuing from an earlier entry adds a branch to the same file. Fork and clone copy history into new files. — [docs/how-pi-works.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/how-pi-works.md); [docs/sessions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/sessions.md)
- **Branch summarization.** Pi can summarize the branch you leave and attach the summary to the one you enter. — [docs/sessions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/sessions.md). Ronacher's use: branch off to fix a broken tool, then rewind and summarize, so the main context stays clean. — [Ronacher 2026-01-31](https://lucumr.pocoo.org/2026/1/31/pi/)
- **Compaction.**
  - Triggers when `contextTokens > contextWindow - reserveTokens`, with a default reserve of 16,384, or on `/compact [instructions]`.
  - Checks run between turns, after tool results are appended. A provider overflow error triggers one compact-and-retry.
  - Summarization requests disable prompt-cache writes.
  - Extensions can hook `session_before_compact` to customize the summary.
  - Source: [docs/compaction.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/compaction.md)
- **In early versions** Zechner reported hundreds of exchanges in one session without compaction. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Context files.** `AGENTS.override.md`, `AGENTS.md` and `CLAUDE.md` are loaded. `.pi/SYSTEM.md` and `APPEND_SYSTEM.md` replace or extend the prompt, and are trust-gated. — [docs/security.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/security.md). Global and project AGENTS.md is the only addition to the prompt, and it can replace the prompt entirely. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/)
- **Session controls.** Steering messages are inserted after the current turn, and follow-ups after pending work. Abort returns queued messages to the editor. — [docs/how-pi-works.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/how-pi-works.md). `/export` writes HTML or JSONL. `/share` publishes to a gist or a Radius artifact. — [docs/sessions.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/sessions.md)

### Inferences
- The tree-with-active-branch model makes it cheap for an add-on to keep state per branch. It is a strong argument for an append-only conversation store with parent pointers, rather than a flat message list.

### Gaps
- I did not read the full session-format.md for entry types.

## UX loop: TUI, print/JSON/RPC/SDK modes and embedding

### Takeaway
One agent and session engine sits behind four interfaces: an interactive TUI, print and JSON one-shot runs, JSONL RPC over stdio, and an in-process TypeScript SDK (`createAgentSession`). The SDK is how OpenClaw embeds pi.

### Cited Findings
- **Modes.** Interactive TUI, print (prints the final response), JSON (events as JSONL), RPC (JSONL commands on stdin, events on stdout), and SDK. "All interfaces use the same agent and session mechanisms." — [docs/how-pi-works.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/how-pi-works.md)
- **SDK.** `import { createAgentSession } from "@earendil-works/pi-coding-agent"` gives in-process control for Node or Bun hosts. RPC is for other languages, process isolation and IDEs, with a typed `RpcClient` for TypeScript. RPC can also drive extension UI requests (rpc-extension-ui.md). — [docs/sdk.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/sdk.md); [docs/rpc.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/rpc.md)
- **TUI.** pi-tui originally rendered inline with differential rendering: retained-mode components, diff from the first changed line, synchronized output to limit flicker. — [Zechner 2025-11-30](https://mariozechner.at/posts/2025-11-30-pi-coding-agent/). In 1.0 the default became fullscreen, and `tuiMode: "regular"` restores scrollback. — [CHANGELOG 1.0.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)
- **OpenClaw** embeds the pi SDK: it imports `createAgentSession()` rather than using RPC, and depends on pi-ai, pi-agent-core, pi-coding-agent and pi-tui. — [DEV Community, "Inside OpenClaw"](https://dev.to/jiade/inside-openclaw-how-the-worlds-fastest-growing-ai-agent-actually-works-under-the-hood-4p5n) (secondary). The README cites OpenClaw as a real-world integration. — [README](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/README.md). Ronacher: OpenClaw, his Telegram bot and Zechner's "mom" bot are all built on pi. — [Ronacher 2026-01-31](https://lucumr.pocoo.org/2026/1/31/pi/)
- **Monorepo packages today:** `agent`, `ai`, `chord`, `codemode`, `coding-agent`, `durable`, `env`, `evals`, `mcp`, `schema`, `telemetry`, `tui`. — [GitHub contents API, earendil-works/pi/packages](https://github.com/earendil-works/pi/tree/main/packages). "Pi Durable", an experimental long-running agent framework, launched with 1.0. — [trendingtopics.eu](https://www.trendingtopics.eu/pi-1-0-earendil-en/)

### Inferences
- The layering is the model for Alpaca Bot: provider lib, then agent loop, then harness, then UIs. With one engine behind several front ends (TUI, RPC, SDK), the admin panel, front-end chat and REST/MCP can share a single session and agent core.

### Gaps
- I did not fetch the pi-agent-core README. OpenClaw's own docs were not checked directly.

## Community growth and distribution

### Takeaway
Pi grew from a personal tool (Nov 2025) to one of the largest agent repos. It has about 123k stars, about 15.9k forks, about 26.8M npm downloads a month for the new package name, and more than 14k npm packages tagged `pi-package`. Much of the attention came through OpenClaw. It is now owned by Earendil.

### Cited Findings
- **GitHub, as of 2026-11-20:** 123,033 stars, 15,873 forks, 277 open issues. The repo was created 2025-08-09. — [GitHub API, earendil-works/pi](https://github.com/earendil-works/pi). Press reported "over 45,000" stars in May 2026. — [trendingtopics.eu](https://www.trendingtopics.eu/pi-1-0-earendil-en/)
- **npm downloads, 2026-10-21 to 2026-11-19:** 26,805,441 for `@earendil-works/pi-coding-agent`, plus 1,732,708 for the legacy `@mariozechner/pi-coding-agent`. — [npm downloads API](https://api.npmjs.org/downloads/point/last-month/@earendil-works/pi-coding-agent). Caveat: this includes OpenClaw and CI installs, so it is not a user count.
- **npm packages with the `pi-package` keyword:** 14,069 by registry search total. — [npm registry search](https://registry.npmjs.org/-/v1/search?text=keywords:pi-package&size=1). Caveat: npm's search total can be fuzzy, and many are probably trivial or personal. Press reported "over 2,000 third-party extensions by May." — [trendingtopics.eu](https://www.trendingtopics.eu/pi-1-0-earendil-en/)
- **Earendil claims** "hundreds of thousands" of weekly users. The press notes this is unverified. — [trendingtopics.eu](https://www.trendingtopics.eu/pi-1-0-earendil-en/)
- **The Pi 1.0 HN thread** drew about 1,689 points and 610 comments. — [HN item 49926069](https://news.ycombinator.com/item?id=49926069)
- **Ownership and governance.** Earendil owns pi and its trademark. Zechner, Ronacher and Colin decide direction and merges. The core stays MIT permanently. Future tiers are planned as Fair Source add-ons and proprietary enterprise/cloud features, none built as of April. There is no CLA. — [Zechner 2026-04-08](https://mariozechner.at/posts/2026-04-08-ive-sold-out/). Earendil's backers reportedly include founders of n8n, OpenClaw, Revolut, Sentry and Slack. — [trendingtopics.eu](https://www.trendingtopics.eu/pi-1-0-earendil-en/) (secondary)
- **Contribution policy.** New issues and PRs from new contributors are closed automatically and reviewed daily. — [README](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/README.md)
- **Notable adopters:** OpenClaw (formerly Clawdbot), which embeds the SDK; Armin Ronacher, who uses it almost exclusively. — [Ronacher 2026-01-31](https://lucumr.pocoo.org/2026/1/31/pi/); [eWeek](https://www.eweek.com/news/openclaw-pi-coding-agent-ai-tools-neuron/). Earendil's own products, the Lefos email agent and Radius, are integrated into pi's `/login` and `/share`. — [CHANGELOG 1.0.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md); [trendingtopics.eu](https://www.trendingtopics.eu/pi-1-0-earendil-en/)

### Inferences
- The ecosystem grew *because* the core was small and agents could write extensions themselves. The package count is the clearest evidence that a minimal core plus an easy, agent-authorable plugin format scales.

### Gaps
- I found no named enterprise adopters. I found no curated "awesome-pi" list with quality signals.

## Known weaknesses and criticisms

### Takeaway
The criticisms are uneven extension quality ("vibe-coded"), the minimal core pushing maintenance onto users, TUI rendering bugs, weaker results with small local models, and creeping scope (MCP, codemode, Radius and other commercial hooks after the Earendil acquisition). The YOLO default is a structural risk that the project explicitly leaves to the user.

### Cited Findings
- **Extension quality.** A long-time user on HN said most plugins feel like personal vibe-coded projects rather than maintained tools. — HN commentary via search summary ([hn.nuxt.dev mirror 48847407](https://hn.nuxt.dev/item/48847407)). Low confidence: I did not read the original comment.
- **Maintenance burden.** A one-prompt tool still inherits ongoing maintenance, with compaction, worktrees and clipboard bugs cited. — [HN Pi 1.0 thread](https://news.ycombinator.com/item?id=49926069) (user boredtofears)
- **TUI bugs.** Reasoning text blinks in GNU screen and Terminal.app. Scroll jumps to the top during reasoning. — [HN Pi 1.0 thread](https://news.ycombinator.com/item?id=49926069). The 1.6.0 changelog still lists many TUI and rendering fixes. — [CHANGELOG 1.6.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)
- **Small models.** A local Qwen model hallucinated MCP tool calls. — [HN Pi 1.0 thread](https://news.ycombinator.com/item?id=49926069) (victor_pudeyev). On the other hand, the small prompt helps weak hardware and local models. — same thread (FacelessJim)
- **Visibility from promotion.** One commenter attributes the visibility to heavy promotion. — [HN Pi 1.0 thread](https://news.ycombinator.com/item?id=49926069) (brandall10)
- **Token use.** One HN commenter measured 20k tokens on pi against 5.3k for their own minimal setup on a simple task. This is anecdotal. — via search summary of [hn.svelte.dev 48847407](https://hn.svelte.dev/item/48847407). Low confidence: not verified.
- **Scope creep and drift from minimalism (my framing, sourced facts).**
  - 1.x adds built-in MCP, codemode, tool search, virtual models, classifier and image models, Radius login and share, telemetry (`enableInstallTelemetry` defaults to true) and a fullscreen default. — [CHANGELOG](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md); [docs/settings.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/settings.md)
  - The changelog runs to 6,700 lines, with versions jumping 0.50 → 0.99 → 1.6 in roughly ten months. — [CHANGELOG](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)
- **No security boundary.** Extensions run in-process with full permissions, and prompt injection is out of scope for security reports. — [docs/security.md](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/security.md)

### Inferences
- The lesson for Alpaca Bot: a tiny core needs a *first-party* set of well-maintained companion plugins, or users meet the long tail of unmaintained ones first. Pi responded by bundling built-in extensions; Alpaca Bot's planned standalone companion plugins match that shape.
- The churn rate is a warning about how stable an extension API is. Pi broke extension render contracts in 1.6.0 (`render()` now returns a readonly array). — [CHANGELOG 1.6.0](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/CHANGELOG.md)

### Gaps
- Most criticism comes from HN comments and secondary blogs. I found no systematic critical review. Large parts of the HN 1.0 thread (about 230k characters) were not read.
