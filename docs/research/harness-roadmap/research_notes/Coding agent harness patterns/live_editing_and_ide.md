# Live PHP editing (sandbox, preview, apply, rollback) and in-wp-admin IDEs

Research date: 2026-11-20. About 20 search/fetch calls; most claims rest on search-result snippets plus one full fetch of the `wp_edit_theme_plugin_file()` reference page. Items marked **[unverified]** carry a URL that was not opened this session (cited from general knowledge or a search listing only). Treat them as leads, not facts.

## Sandbox / preview: WordPress Playground (WASM PHP)

### Takeaway
Playground is the only "real" isolation story for previewing agent-written PHP inside a browser: the code runs in a WASM PHP in the visitor's tab, never on the host. Its limits are a different database (SQLite), no threads/pcntl, and a copy of the site rather than the site itself. Automattic's Telex uses it this way.

### Cited Findings
- Blueprints can be run from JS with `startPlaygroundWeb()` from `@wp-playground/client` — [Using Blueprints](https://developer.wordpress.org/playground/blueprints/using-blueprints/)
- `PlaygroundClient` controls a Playground iframe. The README example calls `client.writeFile('/index.php', …)` and then `client.run({ scriptPath: '/index.php' })` — [npm @wp-playground/client](https://npmjs.com/package/@wp-playground/client)
- The Blueprint `writeFile` step takes `path` plus `data`. `data` is a string or a resource reference such as `{ "resource": "url", … }` — [Blueprints examples](https://developer.wordpress.org/playground/blueprints/examples/)
- Docs disagree on whether `client.isReady` is a property or a method (`isReady()`). Check the shipped `.d.ts` — [Quick Start](https://developer.wordpress.org/playground/developers/build-an-app/) vs [APIs overview](https://developer.wordpress.org/?p=182551)
- `runPHP` code has to `require '/wordpress/wp-load.php'` before WordPress functions work — [Using Blueprints](https://developer.wordpress.org/playground/blueprints/using-blueprints/) (community guidance in the search summary)
- Networking is on by default on playground.wordpress.net. In the browser build, PHP's network calls go through WebSockets/JS because WASM can only call JS APIs. Blueprints toggle it with `features.networking` — [Playground June 2025 posts](https://make.wordpress.org/playground/2025/06/); [Manage Playground sites programmatically (2026-07-22)](https://make.wordpress.org/playground/2026/07/22/manage-wordpress-playground-sites-programmatically/)
- That 2026 post shows a site created with PHP 8.4 and networking enabled, so PHP 8.4 is a selectable runtime — [make.wordpress.org/playground 2026-07-22](https://make.wordpress.org/playground/2026/07/22/manage-wordpress-playground-sites-programmatically/)
- The database is SQLite through the SQLite Database Integration plugin, because MySQL has no WASM build. The team reports 99%+ directory-plugin and core-unit-test compatibility. Direct MySQL clients do not work. The Node.js build can reach a real MySQL server — [Playground WordPress architecture](https://developer.wordpress.org/playground/developers/architecture/wordpress/); [2025 year in review](https://make.wordpress.org/playground/2025/12/03/wordpress-playground-2025-year-in-review/)
- WASM PHP has no pthreads, so no `pcntl_fork()`. The limitations page also says Fibers are unsupported, but that page is undated and may be stale. Xdebug became "experimental" in 2025 — [Limitations](https://wordpress.github.io/wordpress-playground/limitations/) (the fetch came back empty, so this is from the search snippet); [Oct 2025 roundup](https://make.wordpress.org/playground/2025/10/23/things-you-might-have-missed-about-the-playground-project-over-the-past-few-months/)
- Telex is Automattic's experimental AI block generator, shown at WordCamp US 2025. It generates a block, builds it, previews it in Playground in the browser, then offers a ZIP plugin download or a share link — [TechCrunch 2025-09-02](https://techcrunch.com/2025/09/02/wordpress-shows-off-telex-its-experimental-ai-development-tool/); [Kinsta](https://kinsta.com/blog/wordpress-blocks-telex/)
- Automattic's tips say a Telex block stuck in an error loop can have its "playground state" reset without losing code. In other words, preview state and source are kept separate — [Automattic for Agencies](https://automattic.com/for-agencies/blog/from-concept-to-production-10-tips-for-building-custom-wordpress-blocks-with-telex-ai)
- There is a "Teach your coding agent to write Playground Blueprints" effort (WordPress/agent-skills `blueprint` skill) — [make.wordpress.org/playground tag](https://make.wordpress.org/playground/tag/playground/); [tessl registry](https://tessl.io/registry/skills/github/WordPress/agent-skills/blueprint)
- The Playground VS Code extension bundles Playground to run local WordPress with no PHP or MySQL install. It lives in WordPress/playground-tools — [VS Code extension docs](https://developer.wordpress.org/playground/developers/local-development/vscode-extension/); [README](https://github.com/WordPress/playground-tools/blob/trunk/packages/vscode-extension/README.md)

### Inferences
- How Alpaca Bot could use this: the wp-admin page embeds a Playground iframe. A Blueprint loads core plus the target plugin, with the agent's edited files sent via `writeFile`, and then the admin previews. "Apply" is a separate server-side write of the same diff. The PHP runs in the admin's browser, so a malicious preview cannot touch the production filesystem or DB. One exception: the preview's own outbound network calls, if networking is on. Turn it off for previews.
- Fidelity gap: the preview has no production data (unless you export a DB subset, which is a privacy risk), uses a SQLite dialect, and may lack the extensions or services production has. A green preview does not prove production is safe. It only catches parse errors, fatals and obvious behaviour bugs.
- Loading Playground assets from playground.wordpress.net hands a third-party CDN the site's plugin code. That matters for a "privately hosted" product. Self-host the remote bundle, or document the trade-off.

### Gaps
- I did not confirm the current `@wp-playground/client` version, or whether an iframe embedded under wp-admin's origin needs specific COOP/COEP or cross-origin headers.
- I did not confirm exactly how the "Playground PR previews" GitHub action or Studio expose a sandbox-for-AI API.
- I could not load the limitations page (the fetch was empty).

## Staging copy and shadow-slug patterns

### Takeaway
I found no first-party "shadow plugin" mechanism. The usual patterns are a full clone (WP Staging, multisite sub-site) or a mu-plugin that swaps the active plugin per request. The swap pattern is cheap, but it runs untrusted code in the production PHP process and against the production DB, so it is a preview, not a sandbox.

### Cited Findings
- WordPress filters every option read through `option_{$option}`, so `option_active_plugins` can rewrite the active-plugin list per request. A mu-plugin loads before regular plugins, so it can gate on a signed cookie or query parameter — **[unverified]** [option_{$option} hook](https://developer.wordpress.org/reference/hooks/option_option/)
- Recovery mode already uses the cookie-scoped pattern: a cookie-scoped session pauses an extension for one user while everyone else runs normally — [Fatal Error Recovery Mode in 5.2](https://make.wordpress.org/core/2019/04/16/fatal-error-recovery-mode-in-5-2/)

### Inferences
- Shadow-slug flow: copy `plugins/foo/` to `plugins/foo--alpaca-preview-<id>/`. For requests carrying an HMAC'd, admin-bound, short-TTL cookie, swap `foo/foo.php` for the shadow entry in `option_active_plugins`. Two traps:
  1. Both copies declare the same classes and functions, so never load both in one request.
  2. Shadow code runs `register_activation_hook`, cron and DB writes against production. A buggy or malicious preview mutates real data.
- A full clone (WP Staging, a multisite sub-site, or a separate wp-harness container) gives real isolation of data, but costs disk, DB copy time and URL rewriting.

### Gaps
- I did not fetch WP Staging docs (clone mechanics, table prefix, push-to-live in Pro). There is no source here for its behaviour.

## WordPress built-in safety nets

### Takeaway
Core has three relevant nets. They are useful but leaky:
- **The file editor's loopback "scrape".** It reverts an edit that fatals, but only for PHP files of an *active* plugin or theme, and only when the loopback works.
- **Recovery mode.** It catches fatals after the fact.
- **The 6.3+ updater rollback.** It covers updates only, not arbitrary edits.

### Cited Findings
- `wp_edit_theme_plugin_file()` (since 4.9.0):
  - Requires `edit_plugins` or `edit_themes` plus a nonce, `validate_file()`, an extension allow-list (`wp_get_plugin_file_editable_extensions`), and `is_writable`.
  - The loopback check runs only when `$is_active && 'php' === $extension`. Inactive plugins are not checked.
  - It sets a 60-second transient `scrape_key_<md5(rand())>`, then GETs the editor admin page and, if that passes, `home_url('/')`. Each GET forwards cookies and has a 100-second timeout. It looks for the `###### wp_scraping_result_start:<key> ######` markers and JSON-decodes what sits between them.
  - On failure it restores the file with `file_put_contents($real_file, $previous_content)` and returns `WP_Error('php_error')`.
  - It calls `wp_opcache_invalidate($real_file, true)` after the write and after the rollback.
  - Source: [wp_edit_theme_plugin_file reference](https://developer.wordpress.org/reference/functions/wp_edit_theme_plugin_file/)
- Known failure modes of the scrape:
  - HTTP Basic auth breaks the loopback, so a bad edit is *not* rolled back — [Trac #42102](https://core.trac.wordpress.org/ticket/42102)
  - Multisite loopback URL problems — [Trac #44514](https://core.trac.wordpress.org/ticket/44514)
  - cURL timeouts and security plugins (e.g. miniOrange) block it — [support thread](https://wordpress.org/support/topic/unable-to-communicate-back-with-site-to-check-for-fatal-errors-so-the-php-chang-2/); [miniOrange thread](https://wordpress.org/support/topic/miniorange-kills-theme-editor/)
  - Origin ticket: active plugin edits did not fail gracefully — [Trac #39766 thread](https://lists.wordpress.org/pipermail/wp-trac/2017-October/341332.html)
- Recovery mode (5.2):
  - `WP_Fatal_Error_Handler` is a shutdown handler. It emails the admin a secret-nonce recovery link, at most once per hour (filterable).
  - The recovery cookie pauses the offending extension for that user only.
  - The handler can be replaced by a `fatal-error-handler.php` drop-in, or disabled by `WP_DISABLE_FATAL_ERROR_HANDLER` or the `wp_is_fatal_error_handler_enabled` filter.
  - It slipped from 5.1 because pausing a plugin could bypass its 2FA.
  - Sources: [Recovery mode in 5.2](https://make.wordpress.org/core/2019/04/16/fatal-error-recovery-mode-in-5-2/); [Improved fatal error protection](https://make.wordpress.org/core/2019/03/20/the-improved-fatal-error-protection/); [changeset 44973](https://core.trac.wordpress.org/changeset/44973/trunk/src/wp-includes/class-wp-fatal-error-handler.php)
- Updater rollback:
  - 6.3 moves the old version to `wp-content/upgrade-temp-backup/`, restores it if a manual plugin or theme update fails, and deletes it on success — [New in 6.3: Rollback](https://make.wordpress.org/core/2023/07/11/new-in-6-3-rollback-for-failed-manual-plugin-and-theme-updates/)
  - Auto-update rollback (restore after a fatal is caught post-update, email the admin) is tracked in Trac #58281, closed fixed and targeted at 6.5 (I did not confirm it shipped in 6.6). Testing also reported that a stalled run left `upgrade-temp-backup/plugins` behind and a stuck `auto_updater.lock` — [Trac #58281](https://core.trac.wordpress.org/ticket/58281)
- `DISALLOW_FILE_EDIT` removes the theme and plugin editors. `DISALLOW_FILE_MODS` blocks all installs, updates and edits. `FS_METHOD` forces the `WP_Filesystem` transport (direct, ssh2, ftpext, ftpsockets) — **[unverified]** [Hardening WordPress](https://developer.wordpress.org/advanced-administration/security/hardening/); [wp-config FS constants](https://developer.wordpress.org/advanced-administration/wordpress/wp-config/)

### Inferences
- Alpaca Bot should reuse the scrape idea, not the function:
  1. Write to a staged copy.
  2. Run a loopback against both an admin URL and the front end, with a one-time key.
  3. Promote atomically (rename) only on success.
  4. Keep the previous version for one-click revert.

  The core function's check-after-write leaves a short window where the live file is broken. It is also skipped for inactive plugins and fails open when the loopback is blocked. The fix: fail *closed* (refuse to apply) when the loopback cannot run.
- Honour `DISALLOW_FILE_EDIT`/`DISALLOW_FILE_MODS` as hard stops. Site owners set them precisely to forbid this feature.
- Always `opcache_invalidate()` the changed files (core does). Otherwise stale bytecode makes "applied" and "running" disagree, and a rollback may not take effect until reset.

### Gaps
- I did not confirm what 6.6 changed for auto-update rollback.
- I did not confirm whether 7.x changed `wp_edit_theme_plugin_file`.

## Filesystem jails and isolation for PHP

### Takeaway
Inside one PHP-FPM pool there is no real jail. `open_basedir` and `disable_functions` are hardening, not sandboxes. Real isolation means another process or container (php -l, a php-cli subprocess in a throwaway container, or Playground in the browser).

### Cited Findings
- WASM PHP lacks pcntl and pthreads. This applies to Playground, not host PHP — [Limitations](https://wordpress.github.io/wordpress-playground/limitations/)
- No other primary sources were gathered on open_basedir or disable_functions bypasses this session.

### Inferences
- A cheap pre-flight is `php -l` via `proc_open` on the staged file. It catches parse errors only, and many hosts disable `proc_open`/`exec`. `token_get_all()` or nikic/php-parser in-process gives a parse check with no subprocess, plus an AST to scan for `eval`, `assert`, backticks, `system`/`exec`, `base64_decode`+`eval`, `$_REQUEST` sinks and outbound URLs. That is a heuristic, not a security boundary.
- Git-backed versioning of the plugin dir (a commit before each apply) gives audit and rollback. Hosts often lack the `git` binary. A PHP-side snapshot (zip or copy into `wp-content/alpaca-bot-backups/<id>/` with the web server blocked from serving it) is the portable fallback.

### Gaps
- No sourced material on open_basedir/disable_functions bypasses or on PHP 8.4 specifics. Researching those needs separate time.

## Security risks and incidents

### Takeaway
Arbitrary PHP write is RCE, which is full-site compromise. The WordPress record shows that file managers and AI plugins are among the most attacked code paths. Several AI-plugin CVEs came from *authorization* bugs around agent/MCP endpoints, not from the AI itself.

### Cited Findings
- WP File Manager < 6.9 (CVE-2020-25213):
  - Shipped elFinder's `connector.minimal.php`, which allowed unauthenticated PHP upload and execution (CVSS 9.8). Mass-exploited Aug–Sep 2020, in CISA KEV, Metasploit module public — [NVD](https://nvd.nist.gov/vuln/detail/CVE-2020-25213); [Wordfence](https://wordfence.com/threat-intel/vulnerabilities/wordpress-plugins/wp-file-manager/file-manager-68-arbitrary-file-uploadremote-code-execution)
  - Still exploited in Dec 2020 to drop Kinsing — [Unit 42](https://unit42.paloaltonetworks.com/cve-2020-25213/)
- Advanced File Manager (`file-manager-advanced`) has repeated arbitrary-upload CVEs:
  - CVE-2024-8126 (≤5.2.8), CVE-2024-11391 (≤5.2.10, `class_fma_connector.php`), CVE-2024-13333 (5.2.12–13, Subscriber+) — [Wordfence listing](https://www.wordfence.com/threat-intel/vulnerabilities/wordpress-plugins/file-manager-advanced); [Rapid7 CVE-2024-13333](https://www.rapid7.com/db/vulnerabilities/file-manager-advanced-plugin-cve-2024-13333/)
  - Multi-plugin admin RCE CVE-2026-6382, fixed in 5.4.12 — [Wordfence multi-plugin](https://www.wordfence.com/threat-intel/vulnerabilities/wordpress-plugins/wp-file-manager/multiple-plugins-multiple-versions-authenticated-remote-code-execution)
  - The separate "File Manager Advanced Shortcode" plugin had unauthenticated RCE CVE-2023-2068 with no patch per Patchstack — [Patchstack](https://patchstack.com/database/vulnerability/file-manager-advanced-shortcode/wordpress-file-manager-advanced-shortcode-plugin-2-3-2-unauth-remote-code-execution-vulnerability)
- AI Engine (100k+ sites), an AI chatbot/agent plugin and Alpaca Bot's closest analogue:
  - CVE-2024-6451: admin RCE through a log path whose extension was not validated (log → .php) — [Wordfence](https://www.wordfence.com/threat-intel/vulnerabilities/wordpress-plugins/ai-engine/ai-engine-250-authenticated-admin-remote-code-execution)
  - CVE-2025-7847: Subscriber+ arbitrary file upload via the REST API — [Rapid7](https://www.rapid7.com/db/vulnerabilities/ai-engine-plugin-cve-2025-7847)
  - CVE-2025-12844: Subscriber+ PHAR deserialization — [Wordfence](https://www.wordfence.com/threat-intel/vulnerabilities/wordpress-plugins/ai-engine/ai-engine-318-authenticated-subscriber-php-object-injection-via-phar-deserialization)
  - CVE-2025-5071: Subscriber+ privilege escalation via MCP, CVSS 8.8 — [Wordfence blog 2025-06](https://www.wordfence.com/blog/2025/06/100000-wordpress-sites-affected-by-privilege-escalation-via-mcp-in-ai-engine-wordpress-plugin/)
  - CVE-2025-11749: unauthenticated bearer-token exposure on `/mcp/v1/` when "No-Auth URL" is enabled, CVSS 9.8 — [Wordfence blog 2025-11](https://www.wordfence.com/blog/2025/11/100000-wordpress-sites-affected-by-privilege-escalation-vulnerability-in-ai-engine-wordpress-plugin/); [NVD](https://nvd.nist.gov/vuln/detail/CVE-2025-11749)
  - CVE-2026-8719: in 3.4.9, any valid MCP OAuth token gets MCP access without an admin capability check, CVSS 8.8 — [Wordfence](https://www.wordfence.com/threat-intel/vulnerabilities/wordpress-plugins/ai-engine/ai-engine-349-authenticated-subscriber-privilege-escalation-via-missing-authorization-in-mcp-oauth-bearer-token); [NVD](https://nvd.nist.gov/vuln/detail/CVE-2026-8719)
  - CVE-2026-1400: Editor-level arbitrary upload — [Rapid7](https://www.rapid7.com/db/vulnerabilities/ai-engine-plugin-cve-2026-1400); [Patchstack list](https://patchstack.com/database/Wordpress/Plugin/ai-engine/vulnerabilities)
- Managed hosts with read-only code:
  - WordPress VIP: web containers and `/wp-content` are read-only, and code arrives only through GitHub commits — [VIP plugin incompatibilities](https://docs.wpvip.com/plugins/incompatibilities/); [Add plugins and themes](https://docs.wpvip.com/guidebooks/get-started/adding-plugins-and-themes/)
  - Pantheon: Test and Live lack write access. The workaround is a symlink to `uploads` — [Pantheon known issues](https://docs.pantheon.io/plugins-known-issues)
  - WP Engine Autoscale: read-only filesystem, and products that write code paths are unsupported — [WP Engine Autoscale compatibility](https://developers.wpengine.com/docs/managed-hosting-platform/autoscale/compatibility/)
- WordPress's own agent plumbing:
  - The Abilities API landed in 6.9.
  - The MCP Adapter exposes abilities over MCP, authenticating with application passwords and authorizing through capabilities. It ships no AI itself.
  - Sources: [MCP Adapter intro, 2026-02](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/); [GitHub mcp-adapter](https://github.com/WordPress/mcp-adapter)
  - I found no core ability for writing files.

### Inferences
- Threat model for Alpaca Bot:
  - Prompt injection: chat input, retrieved posts, comments or web pages tell the agent to "add a helper to functions.php". With file-write tools, that is RCE by one paragraph of text. A code-edit tool should never be reachable from a conversation that ingested untrusted content, nor by a non-`manage_options`/`edit_plugins` user.
  - Every diff should need a human approval click bound to a nonce and to a hash of the exact bytes.
- The AI Engine record says the bigger danger is the *endpoint*, not the model: MCP/REST routes with missing capability checks, token leaks, upload sinks and log-path sinks. Every file-touching route needs `current_user_can('edit_plugins')` (which is false under `DISALLOW_FILE_EDIT`), a nonce, an extension and path allow-list resolved with `realpath()` under `WP_PLUGIN_DIR`/theme root, and no write path controllable through settings.
- Audit log: who, prompt id, model, before and after hashes, diff, approval actor and time. Store it outside the editable tree.
- Supply chain: agent-written code may `require` remote URLs or add Composer deps. Flag outbound `wp_remote_*`/`file_get_contents('http…')` and new `require` targets in the diff review.
- Product gating: detect a read-only filesystem (`wp_is_writable(WP_PLUGIN_DIR)`), `DISALLOW_FILE_MODS` and VIP/Pantheon environment constants. Degrade to "propose diff / open PR / download zip" and do not attempt the write.

### Gaps
- I did not find any CVE tied to core's plugin/theme file editor itself (its risk is that it gives an admin RCE by design).
- I did not find documented incidents of an AI coding agent being prompt-injected into writing malicious PHP on a live WordPress site. That is a design-level risk, not a documented case.
- InstaWP's Sept 2026 review of the MCP Adapter mentions a "security default most people miss". I could not see what it is.

## Existing AI plugins that write or edit code

### Takeaway
Shipping products keep AI code out of plugin files. They store it as snippets with activation-time checks and a safe-mode URL (WPCode), or generate it in a browser sandbox and hand over a ZIP (Telex). Neither writes into an existing plugin's PHP on a live server.

### Cited Findings
- WPCode:
  - Test-runs a PHP snippet on activation and blocks activation on a fatal.
  - Auto-deactivates "Run Everywhere" and "Admin Only" snippets that fatal while the admin loads.
  - Offers `?wpcode-safe-mode=1` to run with snippets off.
  - Sources: [WPCode PHP error docs](https://wpcode.com/docs/how-to-debug-php-errors-in-wpcode/); [support thread](https://wordpress.org/support/topic/deactivated-all-snippets/)
  - No docs found on extra safeguards specific to its AI generator.
- Code Snippets (codesnippets.pro) documents a similar recovery and safe-mode flow — [Recover after snippet error](https://codesnippets.pro/blog/recover-wordpress-site-after-snippet-error/)
- Telex: browser-only generation, Playground preview, ZIP export — [TechCrunch](https://techcrunch.com/2025/09/02/wordpress-shows-off-telex-its-experimental-ai-development-tool/)

### Inferences
- Snippet-store is a lower-risk target than editing files: store code in the DB or in `uploads/alpaca-bot/snippets/` behind a deny rule, load it from Alpaca Bot's own loader with a kill switch and safe-mode query arg, and keep editing third-party plugin files out of scope.

### Gaps
- CodeWP and "AI plugin builder" plugins were not researched (out of budget).

## IDE in wp-admin: editor components

### Takeaway
CodeMirror 6 is the right embedded editor for wp-admin: modular, about 50–200 kB, with a merge/diff view. Monaco brings VS Code ergonomics and a strong diff editor, but costs megabytes. Core still ships CodeMirror 5, and I found no ticket for moving to 6.

### Cited Findings
- Core's `wp_enqueue_code_editor()` powers the file editors, the Custom HTML widget and the Customizer. Core moved to the latest CodeMirror **v5** from npm (r61539, Jan 2026, via #12423/#48456). Maintainers said to update v5 before any v6 move, citing back-compat risk. No v6 ticket was found — [Trac #48456](https://core.trac.wordpress.org/ticket/48456); [wp-trac Jan 2026](https://lists.wordpress.org/pipermail/wp-trac/2026-January/556475.html); [changeset 61611](https://core.trac.wordpress.org/changeset/61611)
- Bundle size:
  - Replit measured Monaco and related libraries at 5.01 MB parsed+gzipped, then moved to CodeMirror — [Replit: Betting on CodeMirror](https://replit.com/blog/codemirror)
  - Sourcegraph migrated off Monaco to CodeMirror for modularity — [Sourcegraph](https://sourcegraph.com/blog/migrating-monaco-codemirror)
  - Secondary estimates put CM6 at about 50–200 kB and Monaco at about 2–5 MB — [PkgPulse 2026](https://www.pkgpulse.com/guides/monaco-editor-vs-codemirror-6-vs-sandpack-in-browser-2026)
- Not verified this session:
  - Monaco is MIT, CodeMirror 6 is MIT.
  - CM6 has `@codemirror/merge` for diff/merge views and `@codemirror/lang-php`.
  - Monaco has a built-in `createDiffEditor`.
  - PHP intellisense needs a language server (Intelephense is a proprietary freemium Node server; phpactor is MIT PHP) bridged over WebSocket, e.g. monaco-languageclient, which means a long-running process that shared hosting will not allow.

### Inferences
- For Alpaca Bot, a CM6 merge view (agent diff vs current) inside a React admin page is the minimum viable "IDE". Lint comes from a server-side `php -l` or php-parser check, not LSP.

### Gaps
- Licence, merge-view and LSP-bridge details were not opened this session.
- I could not confirm which editor Playground's built-in code editor uses.

## IDE in wp-admin: full IDEs and file-manager plugins

### Takeaway
code-server, openvscode-server and Theia are separate Node services: at least 1 GB RAM and 2 cores, WebSockets, and their own auth. They are not deployable on shared or managed WordPress hosting and would be a second RCE surface. They make sense only in the self-hosted or dev-container tier (e.g. a wp-harness site), behind a reverse proxy that enforces wp-admin auth. File-manager plugins that embed elFinder have the worst CVE history in the ecosystem.

### Cited Findings
- code-server recommends at least 1 GB RAM and 2 cores, needs WebSockets, uses password auth from `~/.config/code-server/config.yaml`, and is documented behind Caddy or nginx with Upgrade/Connection headers and TLS — [code-server requirements](https://coder.com/docs/code-server/requirements); [v3.5 guide](https://github.com/easychen/code-server/blob/v3.5.0/doc/guide.md) (old)
- The WP File Manager and Advanced File Manager CVEs are listed in the security section above. Both wrap elFinder connectors.

### Inferences
- Embedding behind wp-admin auth means an nginx `auth_request` to a WordPress endpoint that checks the logged-in cookie and capability, or a WP-minted short-lived JWT the proxy verifies. Either way the IDE terminal is a shell as the web user, so the "safety" story ends there.
- vscode.dev with the browser File System Access API edits *local* files, not server files. It does not fit "edit the live site" without a sync agent.

### Gaps
- openvscode-server and Eclipse Theia requirements and auth were not found.
- The WPide plugin's status and CVEs were not researched.
- Resource numbers for shared hosting are inference, not measured.
