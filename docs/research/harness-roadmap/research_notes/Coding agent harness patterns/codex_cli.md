# OpenAI Codex CLI as an agent harness (state as of 2026-11-20)

Scope note: Codex's docs moved during 2026. Every `developers.openai.com/codex/*` URL now 308-redirects to `learn.chatgpt.com/docs/*`, and the repo's `docs/*.md` files are now one-line stubs that link out ([repo docs/sandbox.md](https://github.com/openai/codex/blob/main/docs/sandbox.md), [docs/config.md](https://github.com/openai/codex/blob/main/docs/config.md)). Several mechanisms named in the brief (approval policy `untrusted`, `--full-auto`, `[profiles.x]` tables, Landlock) have been **retired or replaced**. The facts below come from the live docs and the live `main` tree as fetched on 2026-11-20.

## Extension model (AGENTS.md, MCP, prompts/slash commands, skills, hooks, plugins, config profiles)

### Takeaway
Codex has seven extension layers, each in a file or directory: AGENTS.md (instructions), `.agents/skills/` (on-demand procedures), `[mcp_servers]` (tools), `hooks.json` (lifecycle interception), plugins (bundles of skills, MCP servers and assets), presets (saved prompts), and layered `config.toml` (settings). Project-scoped layers load only after the user marks the project **trusted**. That trust gate is the key safety idea for an in-wp-admin harness.

### Cited Findings
**AGENTS.md: discovery and layering**
- Global layer: in `~/.codex` (or `CODEX_HOME`), Codex uses `AGENTS.override.md` if it exists and is non-empty, else `AGENTS.md`. Only the first non-empty file at this level is used. — [learn.chatgpt.com: AGENTS.md](https://learn.chatgpt.com/docs/agent-configuration/agents-md)
- Project layer: Codex walks from the project root (usually the Git root) down to the cwd. In each directory it looks for `AGENTS.override.md`, then `AGENTS.md`, then names in `project_doc_fallback_filenames`, and takes at most one file per directory. Files are concatenated root-first, so files nearer the cwd come later and win. — [same](https://learn.chatgpt.com/docs/agent-configuration/agents-md)
- Size cap: concatenation stops when the total reaches `project_doc_max_bytes`, 32 KiB by default. Empty files are skipped. The chain is built once per run (once per TUI session), so edits need a restart. — [same](https://learn.chatgpt.com/docs/agent-configuration/agents-md)
- `/init` writes an AGENTS.md scaffold in the current directory. — [Slash commands](https://learn.chatgpt.com/docs/cli/slash-commands)

**agents.md open standard**
- The site calls it "a README for agents": plain Markdown with no required fields. It is now stewarded by the **Agentic AI Foundation under the Linux Foundation** (footer: "AGENTS.md a Series of LF Projects, LLC"). — [agents.md](https://agents.md/)
- Origin, per the site: a collaboration among OpenAI Codex, Amp, Google Jules, Cursor and Factory. The neutral name was chosen so the format isn't tied to one vendor. — [agents.md](https://agents.md/)
- Adopters listed: Codex, Jules, Gemini CLI, Factory, Aider, goose, opencode, Zed, Warp, VS Code, Devin, Windsurf, UiPath, JetBrains Junie, Amp, Cursor, RooCode, Kilo Code, Phoenix, Semgrep, GitHub Copilot coding agent, Ona and Augment Code. The site claims use by "over 60k open-source projects". — [agents.md](https://agents.md/)
- Precedence rule in the standard: the nearest AGENTS.md wins, and explicit chat prompts override all AGENTS.md files. — [agents.md](https://agents.md/)

**Skills**
- A skill is a directory with a required `SKILL.md` (frontmatter `name` and `description`). Optional parts are `scripts/`, `references/`, `assets/` and `agents/openai.yaml`; the last holds UI metadata, invocation policy such as `policy.allow_implicit_invocation`, and tool dependencies. — [Build skills](https://learn.chatgpt.com/docs/build-skills)
- Discovery paths: `$CWD/.agents/skills`, parent directories up to `$REPO_ROOT/.agents/skills`, `$HOME/.agents/skills`, `/etc/codex/skills` (admin), plus bundled system skills (`skill-creator`, `skill-installer`). Same-named skills are not merged, and symlinks are followed. — [same](https://learn.chatgpt.com/docs/build-skills)
- Progressive disclosure: at start, only names, descriptions and paths load, capped at 2% of the context window (8,000 chars if the window is unknown). `skills.max_context_tokens` raises the cap to at most 10,000 tokens. The full SKILL.md loads only when the skill is used. — [same](https://learn.chatgpt.com/docs/build-skills)
- Invocation: explicit with `/skills` or `$skill-name`, implicit when the description matches the task. Implicit invocation can be turned off per skill. Skills are disabled with `[[skills.config]] path=… enabled=false`, and bundled ones with `[skills.bundled] enabled=false`. — [same](https://learn.chatgpt.com/docs/build-skills)

**Plugins**
- Plugins "distribute reusable skills and can bundle MCP server connections and presentation assets". `/plugins` browses installed and discoverable plugins and toggles them. — [Build skills](https://learn.chatgpt.com/docs/build-skills); [Slash commands](https://learn.chatgpt.com/docs/cli/slash-commands)
- The repo has `plugin`, `plugin-host`, `core-plugins` and `core-plugin-common` crates. — [openai/codex tree, codex-rs/](https://github.com/openai/codex/tree/main/codex-rs)

**Custom prompts / slash commands**
- Built-ins include `/compact`, `/review`, `/resume`, `/fork`, `/init`, `/mcp` (`/mcp login <name>`), `/permissions`, `/skills`, `/plugins`, `/model`, `/diff` and `/status`. User-saved prompts are now **`/presets`**: numbered slots that hold prompts or slash commands. — [Slash commands](https://learn.chatgpt.com/docs/cli/slash-commands)

**MCP (client)**
- Two transports: STDIO (`command`, `args`, `env`, `env_vars`, `cwd`) and Streamable HTTP (`url`, `auth`, `bearer_token_env_var`, `http_headers`, `http_headers_helper`). Servers are added with `codex mcp add <name> --env K=V -- <cmd>` or `[mcp_servers.<name>]` in `~/.codex/config.toml` or a trusted project's `.codex/config.toml`. The desktop app, CLI and IDE extension share this config. — [MCP](https://learn.chatgpt.com/docs/extend/mcp?surface=cli)
- OAuth is the default `auth` (`codex mcp login <name>`, `--no-browser`). Client registration can be automatic, CIMD or DCR. `auth = "chatgpt"` reuses the ChatGPT session. — [same](https://learn.chatgpt.com/docs/extend/mcp?surface=cli)
- Governance keys: `enabled_tools` and `disabled_tools`, `startup_timeout_sec` (10), `tool_timeout_sec` (60), `required`, `default_tools_approval_mode` (`auto`/`prompt`/`writes`/`approve`), and per-tool `tools.<t>.approval_mode` and `output_token_limit`. — [same](https://learn.chatgpt.com/docs/extend/mcp?surface=cli)
- A destructive MCP or app tool call always needs approval when the tool advertises a destructive annotation, unless it also advertises a read annotation. — [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security)

**Codex as an MCP server / embedding**
- The current MCP doc covers only Codex as a client and does not mention `codex mcp-server`. The `main` tree has no top-level `codex-rs/mcp-server` crate. Instead it has `app-server`, `app-server-protocol` (JSON and TypeScript schemas), `app-server-daemon` and `app-server-remote-client`. — [MCP](https://learn.chatgpt.com/docs/extend/mcp?surface=cli); [codex-rs tree](https://github.com/openai/codex/tree/main/codex-rs)

**Hooks and notify**
- Events: `PreToolUse`, `PermissionRequest`, `PostToolUse`, `PreCompact`, `PostCompact`, `UserPromptSubmit`, `SubagentStop`, `Stop`, `PreModelRequest`, `PostModelRequest`, `Interrupt`, `SessionStart`, `SubagentStart` and `SessionEnd`. — [Hooks](https://learn.chatgpt.com/docs/hooks)
- Hooks are configured in `hooks.json` or inline `[hooks]` at the system (`/etc/codex`), user (`$CODEX_HOME`) or trusted-project (`.codex/`) level. Each entry has an event, an optional regex `matcher`, and handlers of type `command` or `mcp_tool`. `prompt` and `agent` handlers are parsed but skipped. The default timeout is 600 s. — [Hooks](https://learn.chatgpt.com/docs/hooks)
- What hooks can do:
  - `PreToolUse` can deny a call, or rewrite its input with `updatedInput`.
  - `PermissionRequest` can allow or deny an approval.
  - `UserPromptSubmit` can block a prompt.
  - `Stop` can keep the agent going.
  - Several events can return `additionalContext`.
  - Exit code 2 blocks, with the reason on stderr.
  - A `PreToolUse` hook that fails lets the tool run (fail-open).
  — [Hooks](https://learn.chatgpt.com/docs/hooks)
- A non-managed hook must be reviewed and trusted through `/hooks` before it runs. Admins can set `allow_managed_hooks_only = true` in `requirements.toml`. — [Hooks](https://learn.chatgpt.com/docs/hooks); [repo docs/config.md](https://github.com/openai/codex/blob/main/docs/config.md)
- The legacy `notify = [...]` external program still exists. It fires only on `agent-turn-complete` and receives a JSON argument. — [Config advanced](https://learn.chatgpt.com/docs/config-file/config-advanced)

**config.toml layering and profiles**
- Precedence, lowest to highest:
  1. Base user config.
  2. Profile file.
  3. Project `.codex/config.toml` files, root to cwd, with the nearest winning.
  4. CLI `-c key=value`.
  Managed `requirements.toml` and MDM settings can enforce values above all of these. — [Config advanced](https://learn.chatgpt.com/docs/config-file/config-advanced)
- **Profiles changed in 0.134.0.** `--profile <name>` now overlays a separate file `~/.codex/<name>.config.toml`. The `[profiles.<name>]` tables and the top-level `profile` key are no longer read. — [Config advanced](https://learn.chatgpt.com/docs/config-file/config-advanced)
- Project config cannot set `openai_base_url`, `model_provider`, `profile`, `notify` or `otel`. In untrusted projects, Codex ignores the project `.codex/` layers, hooks and rules. — [same](https://learn.chatgpt.com/docs/config-file/config-advanced)

### Inferences
- Alpaca Bot could copy several of these directly:
  - **Trust-gated project layers**: site- or plugin-supplied instructions, tools and hooks load only after an admin trusts them.
  - **"Nearest file wins, with a byte cap"** for instruction layering, for example network, then site, then plugin, then page context.
  - **Name-and-description-only skill index under a budget.**
  - **Allow/deny lists and per-tool approval modes** for MCP tools.
  - **Hooks** that can deny, rewrite input or inject context. These map well onto WordPress filters and actions.
- Skills in `.agents/skills/` with `SKILL.md` match the Claude/Anthropic skill format, which suggests the format is converging across vendors (an inference, not a sourced claim).

### Gaps
- Whether `codex mcp-server` (which historically exposed `codex` and `codex-reply` tools) still ships as a CLI subcommand could not be confirmed. The crate is absent from the tree, and the docs are silent.
- The plugin manifest format and the plugin marketplace mechanics were not documented on the pages fetched.
- No exact adoption date for the Linux Foundation stewardship of agents.md was found.

## Permission and sandbox model

### Takeaway
There are two independent axes. The **sandbox mode** sets what commands can technically do: `read-only`, `workspace-write` or `danger-full-access`. The **approval policy** sets when the human is asked: `on-request`, `never` or `granular`. Network is off by default, and enforcement happens at OS level. As of late 2026, `untrusted` and `--full-auto` are retired, `on-failure` no longer appears in the docs, and an optional **auto-review** agent can answer escalation requests in place of the human.

### Cited Findings
- Sandbox modes (`sandbox_mode` / `--sandbox`):
  - `read-only`: Codex can read and run commands but not edit.
  - `workspace-write`: edits and commands within the workspace. This is the default of the "Auto" preset.
  - `danger-full-access`: no sandbox.
  — [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security)
- Approval policies (`approval_policy` / `-a`):
  - `on-request`: commands the sandbox allows run silently; Codex asks before leaving the sandbox or using the network.
  - `never`: for CI.
  - `granular = { sandbox_approval, rules, mcp_elicitations, request_permissions, skill_approval }`: keeps chosen prompt categories interactive and auto-rejects the rest.
  — [same](https://learn.chatgpt.com/docs/agent-approvals-security)
- **`untrusted` is retired** and "can prevent either client from starting". The replacement is `[projects."/path"] trust_level = "untrusted"`. `on-failure` is not mentioned in current docs (likely deprecated; flagged as unverified). — [same](https://learn.chatgpt.com/docs/agent-approvals-security)
- `--full-auto` "is no longer supported". `--approve-for-me` sends approvals to automatic review instead. `--dangerously-bypass-approvals-and-sandbox` (alias `--yolo`) turns off both the sandbox and approvals. — [Non-interactive mode](https://learn.chatgpt.com/docs/non-interactive-mode); [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security)
- Network is **off by default** in local `workspace-write`. It is turned on with `[sandbox_workspace_write] network_access = true`. — [same](https://learn.chatgpt.com/docs/agent-approvals-security)
- An optional `network_proxy` adds per-domain allow and deny lists (`*.x` matches subdomains, `**.x` matches the apex and subdomains, and deny wins). — [same](https://learn.chatgpt.com/docs/agent-approvals-security)
- Web search defaults to `"cached"`, with `"live"`, `"indexed"` and `"disabled"` as the other values. — [same](https://learn.chatgpt.com/docs/agent-approvals-security)
- **Protected paths stay read-only even inside writable roots:**
  - `.git`, including the resolved gitdir.
  - `.agents`, `.codex` and `.aws`.
  This stops the agent from rewriting its own config, skills or hooks. — [same](https://learn.chatgpt.com/docs/agent-approvals-security)
- `sandbox_workspace_write.writable_roots` adds more writable directories. — [Sandboxing](https://learn.chatgpt.com/docs/sandboxing)
- Named **permission profiles** are defined as `[permissions.<name>]` with `extends = ":workspace"` and network/domain subtables. They are selected with `default_permissions` or switched with `/permissions`. — [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security)
- **Auto-review**: with `approvals_reviewer = "auto_review"` (default `"user"`), a reviewer agent judges the following instead of the human:
  - sandbox escalations;
  - network requests;
  - `request_permissions` prompts;
  - app and MCP calls.
  Low and medium risk may proceed, high risk needs enough user authorization, and critical is denied. Parse failures and timeouts fail **closed**. It costs extra model calls. — [same](https://learn.chatgpt.com/docs/agent-approvals-security); [Sandboxing](https://learn.chatgpt.com/docs/sandboxing)
- OS enforcement:
  - macOS: Seatbelt (`sandbox-exec`).
  - Linux and WSL2: **bubblewrap (`bwrap`) plus seccomp**. Codex uses `bwrap` from PATH, or falls back to a bundled helper that needs unprivileged user namespaces.
  - Windows: native sandbox. MXC is selected with `[features] prefer_mxc = true`, with `elevated` and `unelevated` as legacy fallbacks.
  - WSL1 is unsupported from 0.115.
  - In containers that block namespaces or seccomp, the docs advise `danger-full-access` inside a trusted container.
  — [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security); [Sandboxing](https://learn.chatgpt.com/docs/sandboxing)
- **Landlock note (possibly stale)**: the brief's "Landlock/seccomp" was the earlier Linux design. The current docs name bwrap plus seccomp, and the sandboxing page does not mention Landlock. The repo now has `bwrap`, `linux-sandbox`, `mxc-sandbox`, `windows-sandbox-rs` and `windows-sandbox-service` crates. — [Sandboxing](https://learn.chatgpt.com/docs/sandboxing); [codex-rs tree](https://github.com/openai/codex/tree/main/codex-rs)
- `codex sandbox macos|linux|windows [--log-denials] CMD` runs any command under the sandbox for debugging. — [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security)
- Execution-policy "rules" (an `execpolicy` crate with its own doc) allow or deny command prefixes. Project-local rules load only in trusted projects. — [repo docs/execpolicy.md](https://github.com/openai/codex/blob/main/docs/execpolicy.md); [Config advanced](https://learn.chatgpt.com/docs/config-file/config-advanced)
- Cloud tasks run in OpenAI-managed containers. Setup has network access, and the agent phase is offline by default. — [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security)

### Inferences
- For WordPress there is no OS sandbox, but the **two-axis split** still applies. One axis is capability tiers, such as read-only, edit-content and full-admin, enforced in PHP. The other is when to ask: on request, never, or per category.
- **Protected paths** translate to protected options and capabilities the agent can never touch, for example the plugin's own settings, `users`/roles, and the active plugin list.
- **Auto-review that fails closed** is a cheap pattern Alpaca Bot could add later for unattended runs.

### Gaps
- The exact escalation UX was not documented on the pages fetched. That covers how the model calls `request_permissions` or asks to re-run outside the sandbox, and what the prompt shows.
- `on-failure`'s retirement date was not found.

## Context management

### Takeaway
Codex keeps each session as a rollout file on disk. `/resume`, `/fork` and `codex exec resume` can pick a session back up. Compaction, manual or automatic, local or remote, replaces the history with a "handoff summary for another LLM". AGENTS.md is rebuilt only at session start, and the skills index is budget-capped.

### Cited Findings
- `/compact` "summarizes the visible chat to free up context space". `/fork` clones a chat and leaves the original intact. `/resume` reopens a saved chat, with a picker scoped to the current dir, the repo or all dirs. — [Slash commands](https://learn.chatgpt.com/docs/cli/slash-commands)
- The compaction prompt in the repo begins: "You are performing a CONTEXT CHECKPOINT COMPACTION. Create a handoff summary for another LLM that will resume the task". It asks for progress and decisions, constraints and preferences, next steps, and critical data. — [codex-rs/prompts/templates/compact/prompt.md](https://github.com/openai/codex/blob/main/codex-rs/prompts/templates/compact/prompt.md)
- Compactions are counted by type, `remote` or `local`, both manual and automatic, in the `task.compact` metric. `PreCompact` and `PostCompact` hooks exist. — [Config advanced](https://learn.chatgpt.com/docs/config-file/config-advanced); [Hooks](https://learn.chatgpt.com/docs/hooks)
- The auto-compact threshold key `model_auto_compact_token_limit` still appears in source: `core/src/session/context_window.rs`, `models-manager/src/config.rs` and the app-server `Config.ts` schema. — [GitHub code search, openai/codex](https://github.com/search?q=repo%3Aopenai%2Fcodex+model_auto_compact_token_limit&type=code)
- Rollout persistence: `codex exec --ephemeral` skips writing rollout files. The repo has `rollout`, `rollout-trace`, `thread-store` and `state` crates, plus `cli/src/migrate_rollouts.rs` and rollout-compression tests. — [Non-interactive mode](https://learn.chatgpt.com/docs/non-interactive-mode); [codex-rs tree](https://github.com/openai/codex/tree/main/codex-rs)
- `CODEX_HOME` (`~/.codex`) holds `config.toml`, `auth.json`, `history.jsonl`, logs and caches. `[history] persistence = "none"` and `max_bytes` control history. — [Config advanced](https://learn.chatgpt.com/docs/config-file/config-advanced)
- `codex exec resume --last "<prompt>"` and `codex exec resume <SESSION_ID>` continue headless runs. — [Non-interactive mode](https://learn.chatgpt.com/docs/non-interactive-mode)
- AGENTS.md is capped at 32 KiB and built once per session. The skills index is capped at 2% of the window. — [AGENTS.md](https://learn.chatgpt.com/docs/agent-configuration/agents-md); [Build skills](https://learn.chatgpt.com/docs/build-skills)

### Inferences
- The "handoff summary for another LLM" framing is a reusable compaction prompt for Alpaca Bot's conversation store.
- Persisting every turn as an append-only event log (rollout), with resume and fork built on it, maps naturally onto a custom table or CPT.

### Gaps
- I did not verify the current rollout path and filename scheme (historically `~/.codex/sessions/YYYY/MM/DD/rollout-*.jsonl`); the fetched docs do not state it. A `migrate_rollouts.rs` file suggests the format or location has changed.
- I did not find the default auto-compact threshold value or the remote-vs-local compaction semantics.

## UX loop (TUI, exec, cloud, IDE, apply_patch, review)

### Takeaway
One Rust core serves several surfaces: the TUI, headless `codex exec` (JSONL plus schema-constrained output), the IDE extension and desktop app over the `app-server` JSON-RPC protocol, and cloud tasks on chatgpt.com/codex. File edits go through a dedicated, grammar-defined `apply_patch` tool rather than raw shell writes.

### Cited Findings
- The README points to the IDE extension (VS Code, Cursor, Windsurf), the desktop app (`codex app`) and the cloud agent "Codex Web" at chatgpt.com/codex. — [README](https://github.com/openai/codex/blob/main/README.md)
- `codex exec`:
  - `--json` streams JSONL events (`thread.started`, `turn.*`, `item.*`, `error`). Items cover messages, reasoning, commands, file changes, MCP calls, web searches and plan updates.
  - `--output-schema` constrains the final answer to a JSON Schema, and `-o` writes the last message to a file.
  - It is read-only by default and needs a Git repo unless `--skip-git-repo-check` is passed.
  - `--trust` persists project trust.
  — [Non-interactive mode](https://learn.chatgpt.com/docs/non-interactive-mode)
- CI: `openai/codex-action` runs a key-holding proxy, runs Codex with `contents: read`, and opens the PR in a separate job that has no API key. — [Non-interactive mode](https://learn.chatgpt.com/docs/non-interactive-mode)
- `apply_patch` grammar (Lark):
  - `*** Begin Patch` … `*** End Patch` wraps hunks.
  - Hunks are `*** Add File: <path>`, `*** Delete File: <path>`, or `*** Update File: <path>` with an optional `*** Move to:`.
  - Changes use `@@ <context>` anchors and `+`/`-`/space lines, with `*** End of File`.
  There are no line numbers; edits are anchored by context. — [codex-rs/core/assets/tools/apply_patch.lark](https://github.com/openai/codex/blob/main/codex-rs/core/assets/tools/apply_patch.lark)
- `/review` reviews the working tree for issues, and `/diff` shows the git diff including untracked files. — [Slash commands](https://learn.chatgpt.com/docs/cli/slash-commands)
- The TUI exposes `/model` (model and reasoning effort), `/status` (model, approval policy, writable roots, token usage) and `/permissions`. — [Slash commands](https://learn.chatgpt.com/docs/cli/slash-commands)
- An apply_patch sandbox escape ("Overpatch") was reported 2026-08-12 and patched within about 8 days (secondary source). — [CSA research note](https://labs.cloudsecurityalliance.org/wp-content/uploads/2026/09/CSA_research_note_openai_codex_sandbox_escapes_20260922-csa-styled.pdf)

### Inferences
- A structured, context-anchored edit format (rather than "write whole file") is a good fit for agent edits to post content, block markup or theme files in WordPress. It is diffable, reviewable and approval-friendly.
- **Machine-readable event streaming plus schema-constrained final output** is the pattern to copy for Alpaca Bot's REST and Abilities API surface, and for WP-CLI headless runs.

### Gaps
- I did not fetch the cloud-tasks docs (best-of-N attempts, environment setup, how a cloud diff is applied locally), nor the IDE extension or app-server protocol docs.

## Distribution and community growth

### Takeaway
Codex is Apache-2.0, ships as one static binary through several installers, and defaults to **sign-in with an existing ChatGPT plan**: no API key and no extra bill. That default, together with rapid releases and the Feb 2026 desktop app, coincided with self-reported growth from about 1M to over 8M weekly users in H1 2026.

### Cited Findings
- Repo stats via the GitHub API on 2026-11-20:
  - 135,967 stars and 21,567 forks.
  - Apache-2.0 license; created 2025-04-13.
  - About 740 contributors (the contributor endpoint paginates to page 740 with `anon=1`).
  - Latest release `rust-v0.171.0` on 2026-11-20.
  - `open_issues_count` of 28,082, which includes open PRs.
  — [GitHub API repos/openai/codex](https://api.github.com/repos/openai/codex)
- Install options:
  - `curl -fsSL https://chatgpt.com/codex/install.sh | sh` (installs from releases.openai.com, falling back to GitHub Releases), or the PowerShell script on Windows.
  - `npm i -g @openai/codex`.
  - `brew install --cask codex`.
  - musl static Linux and macOS binaries on GitHub Releases.
  - A DotSlash file for pinning the version in repos.
  — [README](https://github.com/openai/codex/blob/main/README.md); [docs/install.md](https://github.com/openai/codex/blob/main/docs/install.md)
- "Sign in with ChatGPT" is recommended so Codex runs as part of Plus, Pro, Business, Edu or Enterprise plans. API keys need "additional setup". — [README](https://github.com/openai/codex/blob/main/README.md)
- Growth figures (OpenAI self-reported, as relayed by the press):
  - More than 1M weekly users in early March 2026. — [Fortune](https://fortune.com/2026/03/04/openai-codex-growth-enterprise-ai-agents/)
  - 3M on Apr 8, with Altman resetting usage limits at each additional million users. — [Business Today](https://www.businesstoday.in/amp/technology/story/openai-codex-celebrates-3-million-weekly-users-ceo-sam-altman-resets-usage-limits-524717-2026-04-08)
  - More than 5M by June 2, up more than 6x since the February desktop app launch. — [Constellation Research](https://constellationr.com/insights/news/openai-touts-broadening-codex-usage-5-million-weekly-active-users)
  - Past 8M after the GPT-5.6 launch in mid-July. — [The New Stack](https://thenewstack.io/gpt-5-6-codex-user-surge/)
- The repo includes an "open source fund" doc. — [docs/open-source-fund.md](https://github.com/openai/codex/blob/main/docs/open-source-fund.md)

### Inferences
- The main drivers appear to be: a zero-marginal-cost sign-in for the existing ChatGPT base, one binary on every channel, a shared config across CLI, IDE and app, and very fast release cadence (about 170 minor versions in about 19 months). For Alpaca Bot, the analogue is to make "bring your existing subscription or a local model (Ollama)" the default path, and to keep install to one click from wordpress.org.
- Weekly-user numbers count all Codex surfaces (CLI, IDE, app, cloud), not the CLI alone.

### Gaps
- No usage figures after mid-July 2026 were found. No CLI-only usage split exists. I did not verify the npm weekly download count.

## Known weaknesses and criticisms

### Takeaway
The criticism is concentrated in three areas: sandbox escape CVEs (2025 and 2026, patched quickly), a late and changing Windows story, and frequent config churn that breaks old setups. Users also report context limits and quality regressions.

### Cited Findings
- **CVE-2025-59532**: in versions 0.2.0–0.38.0, a model-generated cwd could become the writable root, which allowed writes outside the session folder. — [Vulners CVE-2025-59532](https://vulners.com/search/tags/codex%20ide%20extension?skip=-20) (aggregator; check the GitHub advisory)
- In 2026, "Heapjack" (desktop) and "Overpatch" (CLI `apply_patch`) were reported on 2026-08-12 and patched within 8 days. — [Cloud Security Alliance research note, 2026-09-22](https://labs.cloudsecurityalliance.org/wp-content/uploads/2026/09/CSA_research_note_openai_codex_sandbox_escapes_20260922-csa-styled.pdf)
- Pillar Security reported cross-vendor sandbox escapes covering Cursor, Codex, Gemini CLI and Antigravity, and argued that guardrails "can't live inside the agent". — [DevOps.com](https://devops.com/codex-sandbox-escapes-show-why-agent-guardrails-cant-live-inside-the-agent/)
- Simon Willison wrote two investigations of Codex sandbox behaviour (Nov and Dec 2025). — [simonwillison.net 2025-11-09](https://simonwillison.net/2025/Nov/9/codex-sandbox-investigation/)
- Windows: the claim that Codex had no Windows sandbox before the March 2026 Windows launch comes from a secondary source. Docs now name MXC, with elevated and unelevated as legacy modes, which shows the design is still moving. — [GadgetHacks](https://windows.gadgethacks.com/news/openai-codex-windows-sandbox-what-changed-after-early-security-failures/); [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security)
- Breaking config churn:
  - Setting `approval_policy = "untrusted"` "can prevent either client from starting".
  - `--full-auto` was removed.
  - `[profiles.*]` stopped being read in 0.134.0.
  - Docs moved domains twice (repo, then developers.openai.com, then learn.chatgpt.com).
  — [Agent approvals & security](https://learn.chatgpt.com/docs/agent-approvals-security); [Config advanced](https://learn.chatgpt.com/docs/config-file/config-advanced); [Non-interactive mode](https://learn.chatgpt.com/docs/non-interactive-mode)
- `PreToolUse` hook failures fail open, so the tool still runs. — [Hooks](https://learn.chatgpt.com/docs/hooks)
- User complaints of degradation ("slow, unreliable") appear on the OpenAI community forum. — [community.openai.com thread](https://community.openai.com/t/codex-is-rapidly-degrading-please-take-this-seriously/1365336)
- A study of 3,864 bugs across Codex, Claude Code and Gemini CLI exists (secondary, not read in full). — [codex.danielvaughan.com](https://codex.danielvaughan.com/2026/04/19/engineering-pitfalls-ai-coding-tools-3864-bugs-codex-claude-gemini/)

### Inferences
- Lesson for Alpaca Bot: keep config keys stable and deprecate with warnings rather than startup failures. Also make the policy hooks that guard destructive actions fail closed, not open.

### Gaps
- I did not read the HN threads directly, so HN sentiment is unverified. The issue-tracker volume (about 28k open issues plus PRs) suggests a heavy triage load, but no source analyses it.
