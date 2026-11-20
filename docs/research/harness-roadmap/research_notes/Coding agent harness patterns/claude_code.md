# Claude Code as an agent harness (state as of 2026-11-20)

All code.claude.com pages were fetched on 2026-11-20, so they describe the current product. Anthropic engineering blog posts dated 2025 are marked **[2025]** because details may have changed since. Version gates such as "v2.1.295+" show how fast the surface moves: the CLI ships many point releases.

## Extension model: plugins, marketplaces, skills, hooks, commands, subagents, MCP, output styles

### Takeaway
Almost every Claude Code extension point is a **declarative file**: Markdown with YAML frontmatter (skills, commands, subagents, output styles, rules) or JSON (hooks config, `.mcp.json`, `plugin.json`, `marketplace.json`). Code only runs at defined edges: hook commands, MCP and LSP servers, monitors, and `bin/` executables. A plugin is just a directory that bundles these files, and a marketplace is a JSON catalog that points at plugins. That layering (file, then plugin, then marketplace) is what Alpaca Bot could copy most directly.

### Cited Findings

**Plugins (manifest and bundle contents)**
- The manifest lives at `.claude-plugin/plugin.json` and is optional. Without it, components load from the standard layout and the name comes from the marketplace entry or the directory name. `name` is the only required field, and every component is namespaced under it (agent `reviewer` in plugin `deploy-tools` becomes `deploy-tools:reviewer`). — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference)
- Manifest fields: `name`, `displayName`, `version`, `description`, `author`, `homepage`, `repository`, `license`, `keywords`, `metadata`, `defaultEnabled`, `engines` (semver range under `claude-code`), `dependencies` (other plugins, optionally with version), `network.hosts` (declared, not enforced), `userConfig`, `channels`, `skills`, `commands`, `agents`, `hooks`, `mcpServers`, `lspServers`, `outputStyles`, `workflows`, and `experimental.{themes,monitors,evals}`. — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference)
- Standard layout: `skills/<name>/SKILL.md`, `commands/*.md` ("Prefer `skills/` for new plugins"), `agents/*.md`, `hooks/hooks.json`, `.mcp.json`, `.lsp.json`, `output-styles/`, `workflows/*.js`, `themes/`, `monitors/monitors.json`, and `bin/` (on the Bash tool's PATH while the plugin is enabled). A `CLAUDE.md` at the plugin root is *not* loaded; instructions belong in a skill. — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference)
- `userConfig` declares typed options (`string|number|boolean|directory|file`, plus `required`, `default`, `options`, `sensitive`, `min`/`max`) that Claude Code prompts for when the plugin is enabled. Sensitive values go to the OS credential store, and the rest go to `pluginConfigs` in settings. Values are referenced as `${user_config.KEY}` or as the env var `CLAUDE_PLUGIN_OPTION_<KEY>`. Shell-form fields reject `${user_config.*}` to prevent injection. — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference)
- Path variables: `${CLAUDE_PLUGIN_ROOT}` is the installed version's path, which changes on update. `${CLAUDE_PLUGIN_DATA}` is `~/.claude/plugins/data/<id>/`, persists across updates, and is deleted on final uninstall. `${CLAUDE_PROJECT_DIR}` is the project root. Every component path must start with `./` and stay inside the plugin root, and `..` is rejected as path traversal. — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference)
- `claude plugin validate [--strict]` is the authoritative linter. Names that impersonate Anthropic (`claude-*`, `anthropic-*`, `official-claude-*`) are errors. — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference)
- Setting `version` pins users to that version until it changes. — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference)

**Marketplaces**
- A marketplace is a directory or repo with `.claude-plugin/marketplace.json`. It requires `name`, `owner`, and `plugins[]`, and each entry has `name` and `source`. Users run `claude plugin marketplace add <owner>/<repo>|<url>|<path>`, then `claude plugin install <plugin>@<marketplace>`. Inside a session the equivalents are `/plugin marketplace add` and `/plugin`. — [Create a marketplace](https://code.claude.com/docs/en/plugin-marketplaces)
- Source types: relative path, `github`, `git-subdir` (monorepo), `url` (any git), `archive` (HTTPS zip), `npm`, and `command` (a directory produced by running a command locally). Git sources can be pinned to `ref` or `sha`. — [Create a marketplace](https://code.claude.com/docs/en/plugin-marketplaces)
- Official marketplace names such as `claude-plugins-official` are reserved, and adding a third-party marketplace with one is refused. Admins can require marketplaces on every machine. — [Create a marketplace](https://code.claude.com/docs/en/plugin-marketplaces)
- Anthropic also runs a curated directory that plugins are submitted to. It reads `icon`, `documentationUrl`, `supportUrl`, `privacyPolicyUrl`, and `termsOfServiceUrl` from `plugin.json`. — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference)

**Skills (progressive disclosure)**
- A skill is a directory containing `SKILL.md` (YAML frontmatter plus Markdown). Frontmatter fields: `name`, `description`, `when_to_use`, `argument-hint`, `arguments`, `disable-model-invocation`, `user-invocable`, `allowed-tools`, `disallowed-tools`, `model`, `effort`, `context: fork`, `agent`, `background`, `hooks`, `paths` (glob activation), and `shell`. — [Skills](https://code.claude.com/docs/en/skills)
- Lazy loading: only name and description are always in context. The body loads on invocation, and supporting files load only when `SKILL.md` references them. The combined description and `when_to_use` text is truncated at **1,536 characters** in the listing. Keep `SKILL.md` under 500 lines and move the rest to supporting files. Scripts are executed, not loaded. — [Skills](https://code.claude.com/docs/en/skills)
- `disable-model-invocation: true` removes the description from context, so only the user can trigger the skill. `user-invocable: false` hides it from the `/` menu, so only the model can trigger it. — [Skills](https://code.claude.com/docs/en/skills)
- Skill locations, in collision priority order: enterprise (managed), personal `~/.claude/skills`, project `.claude/skills`, nested `<subdir>/.claude/skills`, and plugin `skills/`. Plugin skills are namespaced (`/plugin:skill`), so they never collide. — [Skills](https://code.claude.com/docs/en/skills)
- Slash commands are merged into skills. `.claude/commands/*.md` still works, but a skill wins on a name clash. — [Skills](https://code.claude.com/docs/en/skills)
- Skills follow the **Agent Skills open standard** ([agentskills.io](https://agentskills.io)). Portable frontmatter is limited to `name`, `description`, `license`, `compatibility`, `metadata`, and `allowed-tools`; the other fields are Claude Code extensions. — [Skills](https://code.claude.com/docs/en/skills)
- **[2025]** The design comes from a three-level progressive-disclosure model: (1) name and description preloaded into the system prompt, (2) the SKILL.md body read when relevant, (3) bundled files opened only when needed. Bundled scripts let the agent run deterministic code without loading it into context. The post warns that malicious skills could exfiltrate data, so skills should be audited. Published Oct 16 2025; open standard announced Dec 18 2025. — [Anthropic Engineering: Agent Skills](https://www.anthropic.com/engineering/equipping-agents-for-the-real-world-with-agent-skills)

**Hooks (the code edge)**
- There are 33 events. By group:
  - Session: `SessionStart`, `SessionEnd`, `Setup`.
  - Turn: `UserPromptSubmit`, `UserPromptExpansion`, `Stop`, `StopFailure`.
  - Tool: `PreToolUse`, `PermissionRequest`, `PermissionDenied`, `PostToolUse`, `PostToolUseFailure`, `PostToolBatch`.
  - Subagents and tasks: `SubagentStart`, `SubagentStop`, `TaskCreated`, `TaskCompleted`, `TeammateIdle`.
  - Compaction and model: `PreCompact`, `PostCompact`, `PreModelSwitch`, `PostModelSwitch`.
  - MCP elicitation: `Elicitation`, `ElicitationResult`.
  - Environment and files: `InstructionsLoaded`, `ConfigChange`, `CwdChanged`, `DirectoryAdded`, `FileChanged`, `WorktreeCreate`, `WorktreeRemove`.
  - Other: `Notification`, `MessageDisplay`.

  — [Hooks reference](https://code.claude.com/docs/en/hooks)
- Handler types:
  - `command`: shell, with stdin JSON, exit code, and stdout.
  - `http`: POSTs the JSON.
  - `mcp_tool`: calls a tool on a configured MCP server.
  - `prompt`: a single-turn LLM judgment.
  - `agent`: a verifier subagent; experimental.

  Default timeouts are 600s for command, http, and mcp_tool, 30s for prompt, and 60s for agent. Matching hooks run in parallel. — [Hooks reference](https://code.claude.com/docs/en/hooks)
- Matchers are exact names or `|` lists. Any other character makes the matcher an unanchored JS regex. MCP tools are named `mcp__<server>__<tool>`. The `if` field takes a permission-rule string such as `Bash(rm *)`, but it is "best-effort" and "not a security boundary". — [Hooks reference](https://code.claude.com/docs/en/hooks)
- Exit-code contract: **0** means success, and stdout is parsed as JSON if it is a single object. **2** means a blocking error, and stderr is the reason. **Any other code is non-blocking**; exit 1 does *not* block. Exit 2 blocks on PreToolUse, UserPromptSubmit, Stop/SubagentStop (forcing the agent to keep working), PreCompact, ConfigChange, and others. It does not block PostToolUse, SessionStart, or Notification. — [Hooks reference](https://code.claude.com/docs/en/hooks)
- JSON output:
  - Universal fields: `continue` (false stops Claude), `stopReason`, `systemMessage`, `suppressOutput`.
  - Top-level `decision: "block"` plus `reason` on several events.
  - `hookSpecificOutput.permissionDecision`: `allow|deny|ask|defer`, with a reason.
  - `updatedInput` on PreToolUse, which rewrites tool input. Its full semantics were in the truncated part of the page.
  - `additionalContext`, injected as a system reminder and capped at 10,000 chars.

  A PreToolUse `allow` cannot override another hook's block, and a silent exit 0 does not approve a call. — [Hooks reference](https://code.claude.com/docs/en/hooks)
- Hooks are **fail-open by default**: timeouts and crashes proceed. `onFailure: "block"` (v2.1.295+) makes an enforcement hook fail closed. — [Hooks reference](https://code.claude.com/docs/en/hooks)
- Hooks can be configured in user, project, and local settings, managed policy, a plugin's `hooks/hooks.json`, skill frontmatter (lives for the session), or subagent frontmatter (lives while the subagent runs). They merge across levels, and lower levels cannot remove managed hooks. `allowManagedHooksOnly` and `allowedHttpHookUrls` give admins lockdown. — [Hooks reference](https://code.claude.com/docs/en/hooks)

**Subagents**
- A subagent is a Markdown file with frontmatter in `.claude/agents/` (project), `~/.claude/agents/` (user), a plugin's `agents/`, managed settings, or the `--agents` CLI flag. Priority runs managed > CLI > project > user > plugin. Only `name` and `description` are required, and the body becomes the system prompt. — [Subagents](https://code.claude.com/docs/en/sub-agents)
- Frontmatter fields: `tools` (allowlist), `disallowedTools`, `model` (`sonnet|opus|haiku|fable|<id>|inherit`), `permissionMode`, `skills` (preloaded), `hooks`, `memory` (`user|project|local`, giving the subagent its own MEMORY.md), `isolation: worktree`, `background`, `maxTurns`, `mcpServers`, and `effort`. **Plugin subagents ignore `hooks`, `mcpServers`, and `permissionMode` for security.** — [Subagents](https://code.claude.com/docs/en/sub-agents)
- Built-in subagents: Explore (read-only; skips CLAUDE.md and the git snapshot to stay cheap), Plan (read-only), and general-purpose. Nesting is allowed up to 3 levels by default (`CLAUDE_CODE_MAX_SUBAGENT_SPAWN_DEPTH`). "Agent teams" is a separate feature for coordinating multiple sessions. — [Subagents](https://code.claude.com/docs/en/sub-agents)

**MCP**
- Transports: stdio, HTTP (recommended for remote), SSE (deprecated), and WebSocket. Scopes: local (default, in `~/.claude.json`), project (`.mcp.json`, committed), and user. Precedence runs local > project > user > plugin > claude.ai connectors, with `managedMcpServers` above all of them. Definitions are not merged field by field. — [MCP](https://code.claude.com/docs/en/mcp)
- Project `.mcp.json` servers need interactive approval, and an untrusted clone cannot approve its own servers. **But** in `-p` and SDK runs the prompt can't appear, so project servers load without asking. — [MCP](https://code.claude.com/docs/en/mcp)
- OAuth 2.0 is supported for remote servers, and `headersHelper` can mint auth headers. `${VAR:-default}` expands in `.mcp.json`, and credential vars such as `ANTHROPIC_API_KEY` read as empty in remote URLs and headers. — [MCP](https://code.claude.com/docs/en/mcp)

**Output styles**
- Output styles are Markdown files (`output-styles/` in plugins) and are switchable with `/output-style`. — [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference); [Headless](https://code.claude.com/docs/en/headless)

### Inferences
- Claude Code splits extension into *context injection* (skills, rules, CLAUDE.md, output styles: text, lazily loaded) and *enforcement* (hooks, permissions: code or rules evaluated by the harness). In WordPress terms, skills, subagents, and commands map to file-based or CPT-based Markdown definitions. Hooks map naturally onto WP's own `apply_filters`/`do_action`: `pre_tool_use` as a filter that can return a deny or a rewritten input, and `post_tool_use` as an action.
- Namespacing every component under the plugin name, plus a validator CLI, is what makes a large third-party ecosystem safe to merge. An Alpaca Bot add-on API should require a namespace and ship a validator from day one.
- The hook exit-code contract (0 = ok, 2 = block, other = warn and proceed) is an easy-to-copy protocol. The documented pitfall is that fail-open defaults let a broken policy hook silently allow actions.

### Gaps
- The full `updatedInput` semantics and the `PermissionRequest` decision object were in the truncated part of the hooks page.
- I did not fetch the status line docs. It is a user-configured command whose stdout renders a status bar, but that is from memory and unverified here.
- I did not fetch the output-styles page; only its existence and file location are confirmed.

## Permission and sandbox model

### Takeaway
Safety is layered. The layers are:
- Permission modes that set the baseline.
- deny > ask > allow rules with tool-specific matchers.
- A strict settings hierarchy where managed policy wins and arrays merge.
- Workspace trust for repo-supplied config.
- An optional OS-level sandbox (Seatbelt, or bubblewrap plus a proxy) that cuts prompts by letting sandboxed commands auto-run.
- Checkpoints, so file edits can be undone.

### Cited Findings
- The modes are:
  - `default` (labelled "Manual"): prompts before most edits, shell commands, and network requests.
  - `acceptEdits`: auto-accepts edits plus `mkdir`/`touch`/`mv`/`cp` in the working dirs.
  - `plan`: read-only exploration.
  - `auto`: a background classifier reviews actions instead of the user.
  - `dontAsk`: auto-denies anything that would prompt.
  - `bypassPermissions`: skips prompts except for the actions no mode auto-approves. "Only use this mode in isolated environments like containers or VMs."

  `permissions.disableBypassPermissionsMode` and `disableAutoMode` let admins forbid these modes. — [Permissions](https://code.claude.com/docs/en/permissions)
- The default tier: reads, Grep, and a fixed read-only Bash set (`ls`, `cat`, `grep`, `find`, read-only `git`, etc.) need no approval. Bash, edits, WebFetch, and WebSearch prompt. "Yes, don't ask again" persists per repo and command for Bash, but only until session end for edits. — [Permissions](https://code.claude.com/docs/en/permissions)
- Rules are evaluated **deny, then ask, then allow**, and the first match wins regardless of specificity. An allow cannot carve an exception out of a deny. A bare tool name in deny (`Bash`) removes the tool from the model's context entirely. — [Permissions](https://code.claude.com/docs/en/permissions)
- Rule syntax examples: `Bash(npm run build)` (exact), `Bash(npm run *)` (wildcard; the space before `*` matters), `Read(./.env)`, `Read(~/...)`, `Read(//abs/...)` (gitignore semantics), `WebFetch(domain:*.example.com)`, `mcp__server__tool`, and `Agent(Explore)`. — [Permissions](https://code.claude.com/docs/en/permissions); [Subagents](https://code.claude.com/docs/en/sub-agents)
- Bash matching is shell-aware:
  - Deny and ask rules match any subcommand, including ones in `$()`, subshells, and loops.
  - Wrappers such as `timeout`, `nice`, and `nohup` are stripped before matching.
  - Redirect targets and `tee` targets are checked against Edit rules.
  - Matching on `Bash(command:...)` is ignored as bypassable.

  — [Permissions](https://code.claude.com/docs/en/permissions)
- Settings precedence, highest first: **managed** (file, MDM, or server-managed from the claude.ai console), command line `--settings`, project local `.claude/settings.local.json`, shared project `.claude/settings.json`, user `~/.claude/settings.json`. List keys like `permissions.allow` **merge across scopes** rather than override. A few restrictive keys are honored from any scope even against managed settings. — [Settings](https://code.claude.com/docs/en/settings)
- Workspace trust: `permissions.allow`, `additionalDirectories`, `extraKnownMarketplaces`, and most `env` in a committed `.claude/settings.json` wait until the user trusts the folder, while deny and ask rules apply immediately. `defaultMode: auto|bypassPermissions` is ignored from project and local files. — [Settings](https://code.claude.com/docs/en/settings)
- Sandbox:
  - OS-enforced and covers Bash, PowerShell, and Monitor commands plus their children. It uses Seatbelt on macOS and bubblewrap plus socat on Linux/WSL2, and is off by default (`/sandbox` turns it on).
  - Writes are limited to the working dir, `$TMPDIR`, and added dirs. Protected paths (`.claude`, hooks, `.mcp.json`, shell rc files, `.git/hooks`) stay write-denied. Reads are broad by default, including `~/.ssh`, unless restricted.
  - Network goes through a local proxy with an allowlist that starts empty. Non-HTTP tools can't connect, and local-only addresses are refused.
  - File tools, MCP servers, and hooks run **outside** the sandbox.

  — [Sandboxing](https://code.claude.com/docs/en/sandboxing)
- If a sandboxed command fails, Claude may retry with `dangerouslyDisableSandbox`. That retry is routed through the permission mode, and `allowUnsandboxedCommands: false` disables it. Built on the open-source `@anthropic-ai/sandbox-runtime`. — [Sandboxing](https://code.claude.com/docs/en/sandboxing)
- **[2025]** Motivation was "approval fatigue". Internal use showed sandboxing cut permission prompts by **84%**. Claude Code on the web keeps git credentials outside the sandbox and uses a git proxy that checks a scoped credential and the target branch before attaching the real token. Published Oct 20 2025. — [Anthropic Engineering: sandboxing](https://www.anthropic.com/engineering/claude-code-sandboxing)
- Checkpoints:
  - Every user prompt that starts a turn snapshots files changed by Claude's edit tools. The last 100 checkpoints are kept, and snapshots are swept after about 30 days.
  - `/rewind` (or Esc Esc) can restore code, conversation, or both, or summarize from or up to a point.
  - **Not tracked:** files changed only by Bash, external edits, most subagent edits, and symlinked or hard-linked files. "Not a replacement for version control."

  — [Checkpointing](https://code.claude.com/docs/en/checkpointing)

### Inferences
- For a WP plugin, the closest analogues are: modes stored as a user/site option; deny > ask > allow rules keyed by ability name plus argument pattern; a precedence stack of network-admin (managed) > site option > user meta, with list merging; and "trust" gating for config that arrives inside third-party add-ons.
- The 84% prompt reduction shows that isolation, not more prompts, is what makes autonomy usable. In WP there is no OS sandbox. The equivalents are dry-run or staging copies, capability-scoped application passwords, and DB transaction or revision-based rewind. Revisions already give "checkpoint" semantics for posts, but options and table writes would need explicit snapshotting. That mirrors Claude Code's gap where Bash changes aren't tracked.

### Gaps
- I did not fetch the auto-mode classifier internals (permission-modes page), and the list of "actions no mode auto-approves" is unverified.
- No current number for the prompt reduction beyond the 2025 84% figure.

## Context management

### Takeaway
Claude Code treats context as a budget:
- Always-on content stays small: CLAUDE.md under 200 lines, the first 200 lines or 25KB of auto memory, skill and tool *names/descriptions* only.
- Everything else loads on demand: subdirectory CLAUDE.md, path-scoped rules, skill bodies, deferred MCP tools.
- Big outputs get spilled to files.
- Subagents isolate heavy reads.
- Compaction re-injects a defined set of durable sources.

### Cited Findings
- CLAUDE.md hierarchy:
  - Managed (org), user `~/.claude/CLAUDE.md`, and project `./CLAUDE.md` or `./.claude/CLAUDE.md`, plus `CLAUDE.local.md`. Files above the cwd load at launch.
  - Subdirectory CLAUDE.md files load **on demand** when Claude reads or edits a file there.
  - `@path` imports are allowed to a maximum depth of four hops. Imports don't reduce context cost.
  - Target is under 200 lines per file.

  — [Memory](https://code.claude.com/docs/en/memory)
- `.claude/rules/*.md` support `paths:` frontmatter, so they load only when matching files are touched. User-level rules live in `~/.claude/rules/`. — [Memory](https://code.claude.com/docs/en/memory)
- AGENTS.md is read when no CLAUDE.md exists (v2.1.277+), which makes the repo portable across agents. Setting `claude-md-and-agents-md` loads both. — [Memory](https://code.claude.com/docs/en/memory)
- Auto memory: Claude writes its own notes to `~/.claude/projects/<project>/memory/`, shared across worktrees of the same repo. The first 200 lines or 25KB of `MEMORY.md` load every session. Memory is context, not enforced config; "use a PreToolUse hook" to enforce. — [Memory](https://code.claude.com/docs/en/memory)
- Compaction:
  - Automatic near the window limit. `/compact [focus]` and `/autocompact <tokens>` are manual controls, and `/clear` resets between tasks.
  - **Survives (re-injected):** system prompt, project-root CLAUDE.md and unscoped rules, auto memory, skill descriptions, plan-mode plan, a fresh git status, up to 5 most-recently-modified files (each up to 5K tokens), and invoked skill bodies (5K tokens each, 25K total, oldest dropped first). SessionStart hooks with matcher `compact` re-run.
  - **Summarized away:** hook-added context, path-scoped rules, and nested CLAUDE.md.

  — [Context window](https://code.claude.com/docs/en/context-window)
- Illustrative startup budget: system prompt about 4,200 tokens, MEMORY.md 680, MCP tool names 120, skill descriptions 450, project CLAUDE.md 1,800. A subagent's 9,890 tokens of reads came back as a 420-token summary. — [Context window](https://code.claude.com/docs/en/context-window)
- MCP tool search is on by default: only tool names and server instructions load at start, and definitions are fetched on demand. Description caps are 4,096 chars upfront and 16,384 chars when searched. — [MCP](https://code.claude.com/docs/en/mcp)
- Tool-result truncation:
  - MCP output defaults to a 25,000-token max (`MAX_MCP_OUTPUT_TOKENS`), with a warning above 10,000.
  - Text results over 50,000 chars are saved to a file instead of inlined.
  - Hook `additionalContext` is capped at 10,000 chars, with overflow written to a file and a 2,000-char preview.
  - Piped stdin to `-p` is capped at 10MB.

  — [MCP](https://code.claude.com/docs/en/mcp); [Hooks](https://code.claude.com/docs/en/hooks); [Headless](https://code.claude.com/docs/en/headless)
- Subagents start with fresh context and return only a final summary, which keeps verbose logs and search results out of the main thread. — [Subagents](https://code.claude.com/docs/en/sub-agents)

### Inferences
- For Alpaca Bot, the transferable rules are:
  - Keep the always-loaded "site instructions" small.
  - List abilities and skills by name and description only, and load bodies on demand.
  - Cap tool results and spill them to an attachment or transient with a preview.
  - Define explicitly what is re-injected after a summarize step.
  - Use sub-runs, such as a background job that returns a summary, for bulk content scans.

### Gaps
- I did not fetch the exact auto-compaction thresholds per model; the doc points to the model-config page.

## UX loop: plan mode, tasks, diffs, interrupt, headless, IDEs, web

### Takeaway
One agent loop is exposed through many surfaces: terminal, VS Code and JetBrains, the desktop app, the web (cloud sandboxes), and headless `-p` / Agent SDK. They share the same settings files, so extensions written once work everywhere.

### Cited Findings
- Plan mode is a permission mode: Claude reads files and runs read-only commands but doesn't edit. The plan it writes is re-injected after compaction. — [Permissions](https://code.claude.com/docs/en/permissions); [Context window](https://code.claude.com/docs/en/context-window)
- Permission prompts let the user add a comment ("Tab to amend"), choose "Yes, and don't ask again" (which writes an allow rule to `settings.local.json`), or switch to auto mode. — [Permissions](https://code.claude.com/docs/en/permissions); [Settings](https://code.claude.com/docs/en/settings)
- Messages typed while Claude works are queued mid-turn. Esc Esc opens rewind. — [Checkpointing](https://code.claude.com/docs/en/checkpointing)
- Headless mode: `claude -p "<prompt>"` with:
  - `--output-format text|json|stream-json`; JSON includes `total_cost_usd`.
  - `--json-schema` for `structured_output`.
  - `--allowedTools` in rule syntax, `--permission-mode`, `--permission-prompts none`.
  - `--continue` / `--resume <id>`, `--append-system-prompt`.
  - `--bare`, which skips auto-discovered hooks, skills, plugins, MCP, memory, and CLAUDE.md; it is recommended for scripts and will become the default.

  The stream has `system/init` with `plugin_errors` and `mcp_server_errors` for CI gating. — [Headless](https://code.claude.com/docs/en/headless)
- The Agent SDK exposes "the same tools, agent loop, and context management that power Claude Code" as a CLI and as Python and TypeScript packages, with tool-approval callbacks (`canUseTool`). GitHub Actions and GitLab CI integrations are built on it. — [Headless](https://code.claude.com/docs/en/headless)
- The same settings files serve the terminal, VS Code, JetBrains, and the desktop app. Cloud (web) sessions run on a fresh clone, read only the committed project settings and server-managed settings, and keep credentials outside the sandbox. — [Settings](https://code.claude.com/docs/en/settings); [Anthropic Engineering: sandboxing](https://www.anthropic.com/engineering/claude-code-sandboxing)

### Inferences
- A WP equivalent of `-p`/SDK would be a WP-CLI command plus a REST endpoint driving the same loop with JSON output and a schema option. That makes Alpaca Bot scriptable in CI or cron and lets the same add-ons run headless.

### Gaps
- I did not fetch the todo/task-list tool docs or the diff UI docs; behavior is known from usage but not cited here.

## Distribution and community growth

### Takeaway
Growth came from a free, scriptable CLI, then an SDK that let others embed the harness, then file-based extension formats that are trivially shareable through git (skills, plugins, marketplaces), and finally an open skills standard. The Anthropic repos on GitHub now show very large adoption numbers.

### Cited Findings
- GitHub stats, live from the GitHub API on 2026-11-20:

  | Repo | Stars | Forks | Open issues | Created |
  | --- | --- | --- | --- | --- |
  | `anthropics/claude-code` | 156,412 | 25,065 | 16,497 | 2025-02-22 |
  | `anthropics/skills` | 197,926 | 23,182 | — | 2025-09-22 |
  | `anthropics/claude-plugins-official` | 47,789 | — | — | 2025-11-20 |
  | `anthropics/claude-agent-sdk-python` | 8,599 | — | — | — |
  | `anthropics/claude-agent-sdk-typescript` | 1,893 | — | — | — |
  | `anthropics/sandbox-runtime` | 7,200 | — | — | — |
  | `anthropics/claude-code-action` | 10,026 | — | — | — |

  — [GitHub API: anthropics/claude-code](https://github.com/anthropics/claude-code); [anthropics/skills](https://github.com/anthropics/skills); [anthropics/claude-plugins-official](https://github.com/anthropics/claude-plugins-official)
- The official marketplace's `.claude-plugin/marketplace.json` lists **317 plugin entries** (counted on 2026-11-20). — [claude-plugins-official marketplace.json](https://github.com/anthropics/claude-plugins-official/blob/main/.claude-plugin/marketplace.json)
- Older third-party counts disagree and are superseded: 134k claude-code stars as of June 23 2026 ([Augment Code](https://www.augmentcode.com/learn/claude-code-134k-stars-terminal-agent)); 144 catalogued plugins in April 2026 ([AI Tools Radar](https://woojinahn.github.io/ai-tools-radar/claude-code/)).
- Community directories exist, for example [awesome-claude-code](https://github.com/jqueryscript/awesome-claude-code). A dev.to post describes a third-party Claude Code plugin reaching 200K stars ([dev.to](https://dev.to/hiroki-ii-ai/how-a-claude-code-plugin-racked-up-200k-github-stars-what-ecc-teaches-us-about-ai-coding-in-2026-25f8)); I did not verify that.
- Adoption mechanisms built into the docs:
  - One-command marketplace add.
  - `userConfig` prompts, so users never hand-edit JSON.
  - Plugin `dependencies` and `engines`.
  - Admin-required marketplaces.
  - Submission to Anthropic's directory.
  - "Suggest plugins by relevance".
  - The Agent Skills standard, which makes skills portable to other tools.

  — [Create a marketplace](https://code.claude.com/docs/en/plugin-marketplaces); [Plugin manifest reference](https://code.claude.com/docs/en/plugins-reference); [Skills](https://code.claude.com/docs/en/skills)

### Inferences
- The `skills` repo out-starring the CLI itself suggests the shareable *content* format, not the binary, drove community energy. For Alpaca Bot, a public repo of example skills or abilities plus a marketplace JSON format (installable from GitHub zip URLs, much like WP plugin updaters already work) is the cheapest ecosystem lever.

### Gaps
- I did not fetch the install path docs (native installer vs npm), so they are not cited here.
- No primary user counts or revenue figures.
- I found no primary source on "what drove adoption" beyond these mechanics.

## Known weaknesses and criticisms

### Takeaway
The most credible criticism concerns harness-induced quality regressions and usage-limit drain in spring 2026, which Anthropic acknowledged in a postmortem. The docs themselves also document structural weak spots:
- Fail-open hooks.
- Best-effort Bash matching.
- Checkpoints that miss Bash, subagent, and external edits.
- Repo config that auto-runs under `-p`.
- A sandbox that is off by default and doesn't cover MCP, hooks, or file tools.
- A fast-moving, version-gated surface.

### Cited Findings
- The Anthropic postmortem (Apr 23 2026) names three harness-level causes of about two months of degraded quality:
  1. Default effort was lowered from high to medium (Mar 4 to Apr 7).
  2. A caching bug dropped prior reasoning every turn (Mar 26 to Apr 10, fixed in v2.1.101). It also likely drained usage limits faster.
  3. A verbosity-limiting system prompt caused a 3% eval drop (Apr 16 to 20).

  Process fixes: per-model evals and ablations for every system-prompt change, soak periods and gradual rollouts, staff dogfooding of public builds, and a usage-limit reset. — [Anthropic postmortem](https://www.anthropic.com/engineering/april-23-postmortem); [Simon Willison](https://simonwillison.net/b/9426)
- Usage-limit complaints in March 2026; Anthropic acknowledged users hitting limits "way faster than expected". — [MacRumors](https://www.macrumors.com/2026/03/26/claude-code-users-rapid-rate-limit-drain-bug/); [DevClass](https://www.devclass.com/ai-ml/2026/04/01/anthropic-admits-claude-code-users-hitting-usage-limits-way-faster-than-expected/5213575)
- Doc-acknowledged limits:
  - Hooks fail open, and the `if` filter is "not a security boundary". — [Hooks](https://code.claude.com/docs/en/hooks)
  - Bash deny rules don't match a program by path or inside `sh -c`, so they need pairing with the sandbox. — [Permissions](https://code.claude.com/docs/en/permissions)
  - `-p` runs execute project hooks and `.mcp.json` servers "even in a folder you've never trusted". — [Headless](https://code.claude.com/docs/en/headless)
  - The sandbox breaks docker, watchman, and SSH git on macOS, and the proxy doesn't inspect TLS. — [Sandboxing](https://code.claude.com/docs/en/sandboxing)
  - Checkpoints miss Bash and subagent edits. — [Checkpointing](https://code.claude.com/docs/en/checkpointing)
- The `anthropics/claude-code` repo has 16,497 open issues (2026-11-20), a signal of support load. — [GitHub](https://github.com/anthropics/claude-code)

### Inferences
- Lessons for Alpaca Bot:
  - Harness changes (system prompt, default effort, context pruning) can degrade quality as much as a model change, so they need evals and staged rollout.
  - Enforcement extensions should fail closed by default for deny-type policies.
  - Headless or cron runs must not silently trust add-on config.

### Gaps
- Most other criticism found (25% instruction-miss rates, "Claude is losing users") comes from low-quality blogs or aggregators and is not included.
- I found no reliable reporting specifically on hook or plugin security incidents.
