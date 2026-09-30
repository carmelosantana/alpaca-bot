# MCP and Abilities: the ground an Alpaca Bot 0.6 "MCP toolkit" stands on

Research note, 2026-09-15. Read-only. Every claim is cited with the version it was checked at.
Core source was read at the `7.0` and `7.1` tags of `github.com/WordPress/WordPress` (fetched with
`gh api .../contents/<path>?ref=<tag>`), so a `7.1 file:line` citation means that tag. Release dates
come from the GitHub releases API (`gh api repos/<r>/releases`), not from summaries.

Tag commit dates: 6.9 = 2025-12-02, 7.0 = 2026-05-20, 7.1 = 2026-08-19 (`gh api repos/WordPress/WordPress/commits/<tag>`).

---

## 1. Abilities API in core

### When it landed

- Merged in **WordPress 6.9**. The dev note is [Abilities API in WordPress 6.9](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/) (2025-11-10), and the Trac ticket is [#64098](https://core.trac.wordpress.org/ticket/64098). The tree at tag 6.9 has `wp-includes/abilities-api.php` and `wp-includes/abilities-api/` (checked by listing `wp-includes?ref=6.9`).
- The feature-plugin repo `WordPress/abilities-api` is **archived**. Its last release was v0.5.0-rc on 2025-11-14 (GitHub API: `archived=true`). Core is now the only source.
- 7.0 added a client-side (JS) Abilities API: [Client-Side Abilities API in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/).

### Public PHP surface (at 7.1)

| Function | 7.1 `abilities-api.php` line |
| --- | --- |
| `wp_register_ability( string $name, array $args ): ?WP_Ability` | :290 (refuses unless `doing_action('wp_abilities_api_init')`, :291) |
| `wp_unregister_ability`, `wp_has_ability`, `wp_get_ability` | :336, :369, :401 |
| `wp_get_abilities( array $args = array() )`, where `$args` is new in 7.1 | :498 |
| `wp_register_ability_category( string $slug, array $args )` | :645 (refuses unless `doing_action('wp_abilities_api_categories_init')`, :646) |
| `wp_unregister_ability_category`, `wp_has_ability_category`, `wp_get_ability_category`, `wp_get_ability_categories` | :690, :722, :754, :785 |

The registry refuses to build before `init` (7.1 `class-wp-abilities-registry.php:283`) and fires `wp_abilities_api_init` on first use (:313).

### Hooks

| Hook | Since | 7.1 location |
| --- | --- | --- |
| `wp_abilities_api_init`, `wp_abilities_api_categories_init` (actions) | 6.9 | registry :313 |
| `wp_register_ability_args` (filter) | 6.9 | registry :139 |
| `wp_before_execute_ability`, `wp_after_execute_ability` (actions; 7.1 adds a trailing `WP_Ability` argument) | 6.9 | `class-wp-ability.php` :852, :875 |
| `wp_pre_execute_ability` (short-circuit filter) | **7.1** | :809 |
| `wp_ability_normalize_input` | **7.1** | :508 |
| `wp_ability_validate_input`, `wp_ability_validate_output` | **7.1** | :566, :748 |
| `wp_ability_permission_result` | **7.1** | :652 |
| `wp_ability_execute_result` | **7.1** | :701 |
| `wp_ability_invoked` (action, fires first in `execute()`) | **7.1** | :783 |
| `wp_get_abilities_item_include`, `wp_get_abilities_result` | **7.1** | `abilities-api.php` :549, :572 |

At 7.0, `class-wp-ability.php` has only the two execute actions (:645, :666) and `abilities-api.php` has no filters (grep of the 7.0 files). Sources for the 7.1 hooks: the lifecycle filters dev note ([2026-07-29](https://make.wordpress.org/core/2026/07/29/new-execution-lifecycle-filters-for-the-abilities-api-in-wordpress-7-1/), Trac #64989), [Abilities API improvements in 7.1](https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/) (validation filters #64311, `wp_ability_invoked` #65248, typed query-string input for REST runs), and the [`wp_get_abilities()` filtering note](https://make.wordpress.org/core/2026/08/05/filtering-registered-abilities-with-wp_get_abilities-in-wordpress-7-1/).

### `meta` keys

- **`annotations`**: `readonly`, `destructive`, `idempotent`, each `bool|null` and defaulting to `null` (7.1 `class-wp-ability.php:46-58`). Core documents them as "hints for tooling and documentation" (:162-163). The REST run route turns them into an HTTP method: `readonly` requires GET, `destructive` plus `idempotent` requires DELETE, and everything else requires POST (7.1 `class-wp-rest-abilities-v1-run-controller.php:110` onward). Core has no `openWorld` annotation, while MCP does (`openWorldHint`).
- **`show_in_rest`**: in 6.9/7.0 it defaults to false (7.0 `class-wp-ability.php:330`). In 7.1 it defaults to `public` when that is set (7.1 :370: `show_in_rest ?? public ?? DEFAULT_SHOW_IN_REST`).
- **`public`**: new in **7.1**. It is validated as a bool (:346) and defaults to false (:37, :371). Dev note: [A unified public exposure flag](https://make.wordpress.org/core/2026/08/04/a-unified-public-exposure-flag-for-abilities-in-wordpress-7-1/) (Trac #65568). Channel flags win over it. In 7.0, `meta` goes through `wp_parse_args` (7.0 :325-333), so an unknown `public` key is **kept, but core does nothing with it**.
- **`mcp.public`** (and `mcp.type` = tool/resource/prompt): an **MCP Adapter** convention, not a core key (see section 2).

### REST (`wp-abilities/v1`, 6.9+)

Three controllers share the namespace `wp-abilities/v1` (7.1 controllers :27): `abilities` (list and single), `abilities/<name>/run` (`WP_REST_Server::ALLMETHODS`, permission callback `check_ability_permissions`, run controller :63-66), and `categories`.

### Core abilities

6.9 shipped `core/get-site-info`, `core/get-user-info`, and `core/get-environment-info`. 7.1 aligned their schemas, added `fields` input, and made `core/get-user-info` `public` ([7.1 improvements note](https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/)). A [merge proposal (2026-07-02)](https://make.wordpress.org/core/2026/07/02/merge-proposal-expanding-wordpress-core-abilities/) and search-result summaries mention `core/read-settings`, `core/read-content`, and `core/read-users` for 7.1. **UNVERIFIED** whether those shipped in 7.1: `wp-includes/abilities.php` at 7.1 was not read.

### Checking `src/Abilities/Register.php` against core

| Register.php claim | Verdict |
| --- | --- |
| `wp_register_ability()` and `wp_register_ability_category()` refuse outside their hooks; both hooks fire after `init` (:23-26) | Correct. 7.1 `abilities-api.php:291`, `:646`; registry `:283`, `:313`. |
| 6.9 put the API and the hook in core (:27-28) | Correct. The tree at tag 6.9 has `abilities-api.php`. |
| Run route returns our WP_Error and restamps the status, "7.1 run-controller.php:172-176, inside check_ability_permissions(), the callback registered at :66" (:84-86) | Correct. `check_permissions()` is at :172, the restamp/return at :173-176, `check_ability_permissions` at :143, the registration at :66. At 7.0 the same block is at :169-173. |
| `WP_Ability::execute()` withholds the message, "WP 7.1 class-wp-ability.php:824-839, the same code at WP 7.0 :619-632" (:88-91) | 7.1 is correct (`check_permissions` :824 … `WP_Error` closes :839). **7.0 is two lines short**: the block runs :619-634 (the `WP_Error(` closes at :634, `}` at :635). It is also only *nearly* the same code: 7.0 wraps the name in `esc_html()` in the message (:633) and 7.1 does not. |
| "core's `wp_ability_permission_result` filter exists for exactly that" (:47-49) | **Holds only on 7.1+.** That filter is new in 7.1 (:652; Trac #64989). The plugin's floor is 6.9, and on 6.9/7.0 no such filter exists, so on those sites the capabilities are not filterable at all. |
| "`meta.public` … `public` (7.1) is what the MCP Adapter keys on; an ability without it is … invisible to MCP" (:113-115) | **Partly off.** (a) The adapter ≥0.6.0 reads `meta.public` from `get_meta()` itself, and 7.0 keeps the key, so the flag works for MCP on 7.0 too. It is not a 7.1-only behaviour. (b) An ability without `public` is still visible if it sets `meta.mcp.public = true` (`McpAbilityExposure::is_meta_public()`, adapter trunk :55-67). (c) Before adapter 0.6.0, `public` did nothing and only `mcp.public` counted. |
| Annotation keys `readonly` / `destructive` / `idempotent` (:274) | Correct keys (7.1 :46-58). A consequence the docblock doesn't mention: with `readonly=false`, the core run route requires POST for all three abilities. |
| The 0.6 wayfinding brief said Register sets `meta.mcp.public` | **The code sets `meta.public` (plus `show_in_rest`), not `meta.mcp.public`** (Register.php:271-275). |

---

## 2. WordPress MCP Adapter (`WordPress/mcp-adapter`)

- **Latest release: v0.6.1, 2026-08-13** (a packaging fix). v0.6.0 was 2026-08-12 and v0.5.0 was 2026-04-15. There is also a rolling `ci-artifacts` pre-release (2026-08-27) (GitHub releases API). trunk was active on 2026-09-14. License GPL-2.0-or-later.
- **Distribution: a WordPress plugin** (`mcp-adapter.php` header: Version 0.6.1, Requires at least 6.9, Tested up to 7.1, Requires PHP 7.4). It is also Composer `wordpress/mcp-adapter` (`"type": "wordpress-plugin"`). Deps are `wordpress/php-mcp-schema ^0.1.0` and `automattic/jetpack-autoloader ^5.0`.
  - **Bundling it as a library is now deprecated.** PR [#288](https://github.com/WordPress/mcp-adapter/pull/288), merged 2026-09-01 and not yet released, adds a `_doing_it_wrong` plus an admin notice when a plugin bundles `Core\McpAdapter`, "in favor of a canonical plugin", "in preparation of release on w.org".
  - **Not on wordpress.org yet**: `api.wordpress.org/plugins/info/1.2/?slug=mcp-adapter` returned "Plugin not found." on 2026-09-15.
  - **Not in core**: no MCP code in the 7.1 tree. The developer blog introduction is [From Abilities to AI Agents](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/).
- **Transports** (README, trunk):
  - STDIO via `wp mcp-adapter serve [--server=<id>] [--user=<id|login|email>]` (docs/guides/cli-usage.md:22-28).
  - Streamable HTTP at `/wp-json/mcp/mcp-adapter-default-server`, with sessions (`Mcp-Session-Id`) stored per user. There are session filters (`mcp_adapter_session_max_per_user`, default 32; `mcp_adapter_session_inactivity_timeout`, default a day) (docs/guides/default-server.md:102-190).
  - A pluggable `McpTransportInterface`.
- **Protocol versions: `2025-11-25`, `2025-06-18`, `2024-11-05` only** (`includes/Core/McpVersionNegotiator.php:30-33`, trunk). It does **not** speak the stateless `2026-07-28` revision (see section 5).
- **Auth model**: each server has a transport `permission_callback` that defaults to `is_user_logged_in()`, and exceptions fall back to that default (docs/guides/transport-permissions.md:3, :77). Each ability's own `permission_callback` then runs as that user ("two-layer security", :138). HTTP callers therefore log in the usual WordPress REST ways: an application password or a cookie plus nonce. The adapter tree has **no OAuth code** (no `oauth`/`auth` paths in the trunk tree), and WordPress/ai issue [#923](https://github.com/WordPress/ai/issues/923) (2026-08-10) says agents "authenticate with a user's credentials, typically an application password". A third-party guide's claim that the adapter "manages the OAuth 2.1 handshake" is **not supported by the source**.
- **What gets exposed**: abilities are private by default. `McpAbilityExposure::is_meta_public()` checks an explicit `meta.mcp.public` first, then falls back to `meta.public === true`, and fails closed on malformed `meta.mcp` (trunk :32-67; v0.6.0 notes). The **default server** exposes only three meta-tools (`mcp-adapter/discover-abilities`, `get-ability-info`, `execute-ability`) and keeps `tools/list` at three schemas. Public abilities with `mcp.type` resource or prompt are auto-exposed as resources or prompts. **Custom servers** (hook `mcp_adapter_init`) register each listed ability as its own tool. `mcp_adapter_create_default_server` turns the default server off (default-server.md:9-79).
- **Client role: server only.** Issue [#156 "Add MCP client support to the adapter"](https://github.com/WordPress/mcp-adapter/issues/156) (opened 2026-03-26) is still **open**. It proposes building on [`Automattic/php-mcp-client`](https://github.com/Automattic/php-mcp-client), either bundled or moved to a standalone `wordpress/mcp-client` package. gziolo (2026-03-30) says the AI team already set the direction that WordPress "should operate as both server and client" and "the question is how, not whether." `WordPress/mcp-client` and `WordPress/php-mcp-client` do not exist (GitHub 404). WordPress/ai issue [#37 "MCP usage across features and request routing"](https://github.com/WordPress/ai/issues/37) (2025-10-06) is open with no implementation. **There is no core or feature-plugin MCP client as of 2026-09-15.**

---

## 3. MCP client in PHP

| Package | Latest | License | PHP | Client transports | Client OAuth | Weight |
| --- | --- | --- | --- | --- | --- | --- |
| **`mcp/sdk`** ([modelcontextprotocol/php-sdk](https://github.com/modelcontextprotocol/php-sdk), official; PHP Foundation plus Symfony) | **v0.8.1, 2026-08-29** (Packagist) | Apache-2.0 in composer.json; the README says new code is Apache-2.0 and existing code MIT | `^8.1` | STDIO and HTTP (streamable; SSE response buffering) (docs/client/transports.md). Both protocol eras, including `2026-07-28` (README:8-10; `src/Client/Stateless/`) | **No client-side OAuth flow.** OAuth is server-side only (`src/Server/Transport/Http/OAuth/*`). Clients pass static headers such as `Authorization: Bearer` (transports.md:38). Official **Tier 3 (experimental)**: client conformance 20% (10/50), "38 of 39 scored auth scenarios fail" (docs/sdk-tier.md, audit of 2026-08-19). Pre-1.0 under Symfony's experimental policy (README:16) | 16 direct requires, about 23 packages transitively (Packagist latest-version walk, approximate): `opis/json-schema`, `phpdocumentor/reflection-docblock` (+ type-resolver, phpstan/phpdoc-parser, webmozart/assert, doctrine/deprecations), `symfony/uid` (+ polyfill-uuid), 8 PSR interfaces. **`php-http/discovery` is a Composer plugin**, and a PSR-18 client must also be installed (guzzle7-adapter, symfony/http-client, …) |
| **`automattic/php-mcp-client`** ([repo](https://github.com/Automattic/php-mcp-client)) | v0.1.0, 2026-03-05. Last push 2026-06-02, 0 stars | GPL-2.0-or-later | `>=7.4` | STDIO (`proc_open`) and HTTP (`ext-curl`). Protocol **2025-11-25** only (README:5) | Static bearer header only (README:84); no OAuth mentioned | Tiny: `psr/log` plus **`wordpress/php-mcp-schema: dev-trunk` from a VCS repository** (README:32-38), which is not installable from Packagist without extra config |
| `php-mcp/client` | 1.0.1, 2025-05-06. Dormant (last push 2025-05-07) | MIT | 8.1 (UNVERIFIED; not in the composer.json excerpt read) | STDIO and legacy **HTTP+SSE** (README), not streamable HTTP | None | ReactPHP stack, about 16 packages (react/http, socket, dns, event-loop, …). Superseded: its server sibling became the base of `mcp/sdk` (php-sdk README:137) |

**`carmelosantana/php-agents`**:

- **No MCP support.**
  - In the installed v0.15.2 (`vendor/carmelosantana/php-agents/src`) the only "mcp" hits are unrelated: `memcpy` in `Runtime/LlamaCpp/FfiLlamaCppNativeApi.php:871,932`, and the Claude CLI adapter passing `--strict-mcp-config` to *suppress* MCP (`Provider/Cli/ClaudeCliVendorAdapter.php:21,55`).
  - GitHub code search over the repo returns only those files plus `docs/PROVIDERS.md:552` (same CLI flag) and a test.
  - v0.15.2 (2026-09-04) is the latest release; no newer tag exists. No branch is MCP-related (branches: `chore_adds-json-string-support`, `chore_refactor-model-def`, `feat_claude-cli`, `feat_extend-system-prompt`, `feat_llama-cpp`, `feat_param-validation-metadata`, `main`).
- Its own requires are PHP `^8.4`, `symfony/http-client ^7 || ^8` (a PSR-18 implementation that `mcp/sdk` discovery would find), and `psr/log ^3`.
- `vendor/` and `vendor-prefixed/` exist only in a built checkout (after `composer install`), not in a fresh worktree.

---

## 4. WP AI Client (core since 7.0)

- **In core since 7.0.** Tag 7.0 has `wp-includes/ai-client.php` (`wp_supports_ai()`, `wp_ai_client_prompt()`), `wp-includes/ai-client/`, and the bundled `wp-includes/php-ai-client/`; 6.9 has none of these (tree listings). Sources: [merge proposal 2026-02-03](https://make.wordpress.org/core/2026/02/03/proposal-for-merging-wp-ai-client-into-wordpress-7-0/) and [Introducing the AI Client in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) (Felix Arntz, 2026-03-24). Provider keys live in the **Connectors API** (Settings > Connectors), per that note. The feature repo `WordPress/wp-ai-client` is archived (last release 0.4.0, 2026-03-01). The upstream `wordpress/php-ai-client` is at 1.4.0 (2026-07-15).
- **It does consume Abilities as function calls** (7.0 and 7.1):
  - `WP_AI_Client_Prompt_Builder::using_abilities( ...$abilities )` turns each `WP_Ability` into a `FunctionDeclaration` named `wpab__<ns>__<name>`, with the input schema passed through `wp_prepare_json_schema_for_client()` (7.1 `class-wp-ai-client-prompt-builder.php:227-268`; the same method is at 7.0 :237).
  - `WP_AI_Client_Ability_Function_Resolver` takes an explicit allow-list, runs `$ability->execute()` for allowed calls, and returns `ability_not_allowed` for anything else (7.1 resolver :17-21, :33, :93-137, :187).
  - The resolver is an allow-list, not an agent loop. The caller runs the model→call→response loop, presumably with `has_ability_calls()` / `execute_abilities()`. The dev note doesn't document this surface; the code is the only source.
- **No MCP relationship.** `php-ai-client`'s requirements doc says it "MUST NOT include any common AI features beyond the actual AI client (e.g. no MCP, no agents)" (docs/REQUIREMENTS.md:63). The 7.1 `ai-client/` files contain no "mcp". The link to MCP is indirect: both consume Abilities.
- The HTTP adapters, notably `class-wp-ai-client-http-client.php` (a PSR-18-style client over the WP HTTP API, judging by the name), are in 7.1 `wp-includes/ai-client/adapters/`. **UNVERIFIED** whether it implements `Psr\Http\Client\ClientInterface` in a way `mcp/sdk` could reuse. It was not read, and it sits in core's (unprefixed) php-ai-client namespace.

---

## 5. MCP spec: what a WordPress plugin *calling* remote servers must handle

- **Current revision: `2026-07-28`** ([versioning](https://modelcontextprotocol.io/specification/versioning)). The previous one was `2025-11-25`. [Changelog](https://modelcontextprotocol.io/specification/2026-07-28/changelog) highlights:
  - **Stateless.** The `initialize` handshake and `Mcp-Session-Id` are gone. Every request carries its protocol version, client capabilities, and client info in `_meta`. Servers MUST implement `server/discover`, and a version mismatch returns `UnsupportedProtocolVersionError` (SEP-2575, SEP-2567).
  - `subscriptions/listen` replaces the GET stream. SSE resumability (`Last-Event-ID`) is removed. Results carry a required `resultType`.
  - **Multi Round-Trip Requests** (`input_required`) replace server-initiated sampling, elicitation, and roots (SEP-2322).
  - Streamable HTTP POSTs need `Mcp-Method` / `Mcp-Name` headers, and there is an `x-mcp-header` parameter mirror (SEP-2243). `tools/list` results carry `ttlMs` / `cacheScope` (SEP-2549).
  - **Deprecated:** Roots, Sampling, Logging, HTTP+SSE, and OAuth Dynamic Client Registration (in favour of Client ID Metadata Documents).
  - A client in 2026 has to handle **both eras**: 2025-11-25 servers (session plus handshake, which the WordPress adapter itself speaks) and 2026-07-28 servers.
- **Authorization** ([2026-07-28 authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization)):
  - Optional overall. HTTP transports SHOULD conform; STDIO SHOULD NOT and should use environment credentials.
  - Clients are OAuth 2.1 clients. They MUST use RFC 9728 Protected Resource Metadata for discovery, MUST support both RFC 8414 and OIDC discovery, MUST use PKCE and the RFC 8707 `resource` parameter on both requests, and MUST validate `iss` (RFC 9207) before redeeming a code.
  - Registration is CIMD (SHOULD), pre-registration, or DCR (deprecated). Credentials MUST be keyed by issuer and not reused across authorization servers.
  - Refresh tokens MUST be kept confidential in storage. Tokens MUST be sent in the `Authorization` header, never the query string, and a client MUST NOT send a server any token not issued by that server's authorization server.
  - For a plugin this means: an OAuth redirect/callback route in wp-admin, per-server encrypted token storage, and a UI to re-authorize on step-up (403 `insufficient_scope`).
- **Tool annotations**: `readOnlyHint` / `destructiveHint` / `idempotentHint` / `openWorldHint`. "Clients **MUST** consider tool annotations to be untrusted unless they come from trusted servers" ([tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools)).
  - There SHOULD be a human in the loop who can deny invocations.
  - Clients SHOULD confirm sensitive operations, show inputs before a call, validate results before passing them to the LLM, use timeouts, and log calls.
  - Tool names are only unique per server, so aggregators SHOULD prefix them. The server `name` is not unique and must not be used for that.
- **Security** ([security best practices](https://modelcontextprotocol.io/specification/2026-07-28/basic/security_best_practices)):
  - **SSRF**: "MCP clients deployed to a server **MUST** consider SSRF risks" in OAuth discovery. A malicious server can point `resource_metadata`, `authorization_servers`, or token endpoints at `169.254.169.254`, RFC 1918 ranges, or localhost. The spec wants HTTPS only, private/link-local ranges blocked, every redirect hop validated, DNS-rebinding TOCTOU handled, and an egress proxy considered. On WordPress: use `wp_safe_remote_*()` (its `http_request_host_is_external` check). An admin-typed server URL is itself an SSRF vector on multi-author sites.
  - **Authorization URL validation**: only http(s), reject `javascript:`/`data:`, and never shell out.
  - **Mix-up attacks** (validate `iss`), **scope minimization**, **token passthrough** (a server-side concern), and **state-handle hijacking**.
- **Prompt injection via tool descriptions and results**: the spec's guidance is the untrusted-annotations rule and "validate tool results before passing to LLM". The **specific "tool poisoning" framing (instructions hidden in descriptions, rug-pull redefinition) is not a named section in the spec pages read. It is UNVERIFIED against a primary source here**, though it follows from the above. A practical mitigation: pin or hash each tool definition when an admin approves it, and re-prompt if it changes (`tools/list` now carries `ttlMs` for re-fetching).
- **Credential storage**: the spec only requires confidentiality. WordPress core has no secrets vault. The 7.0 Connectors API stores AI *provider* keys, and whether it can store arbitrary MCP bearer or OAuth tokens is **UNVERIFIED**.

---

## Implications for an Alpaca Bot MCP toolkit

What exists: server side, the plugin's abilities are **already** reachable by MCP clients when the MCP Adapter plugin is active (`meta.public`). Client side, **nothing exists**: not in core, not in the adapter, not in php-agents. The only PHP SDK with 2026-07-28 support has no client OAuth and is Tier 3.

**Shape A. "Curated abilities" (server-side only, smallest).** Grow the plugin's own ability set: read and search site content, conversation history, usage receipts, per-model settings. Mark them `public` / `mcp.type` correctly, and document an MCP Adapter recipe, or register a custom `mcp_adapter_init` server (e.g. `alpaca-bot`) with direct tools instead of the three meta-tools.
- *Pros:* no new dependency. It rides core plus the canonical adapter plugin (PR #288 forbids bundling it), and reuses Register.php's permission, rate-limit, and toolkit-switch machinery. Also available on 6.9/7.0.
- *Cons:* not a "toolkit" in the php-agents sense. Nothing new for the bot's own model. Auth stays application passwords. Depends on a plugin that is not yet on wordpress.org.
- Worth fixing along the way: the 7.0 line range and the `wp_ability_permission_result` floor claim in Register.php. Also consider annotations; `readonly: true` where true would let the run route use GET.

**Shape B. "Abilities as a php-agents toolkit" (in-process, no MCP on the wire).** A `ToolkitInterface` that wraps an admin-chosen allow-list of *any* site abilities (`wp_get_abilities()`, filtered by category or `meta` on 7.1), so Alpaca Bot's own model can call them. This mirrors core's `WP_AI_Client_Ability_Function_Resolver` allow-list (7.1 resolver :17-21), but inside the Pipeline, so it covers Ollama and every provider php-agents supports.
- *Pros:* zero dependencies. It works on 6.9+. Every call goes through `WP_Ability::execute()`, so permission callbacks, input/output validation, and the 7.1 `wp_ability_invoked` audit hook apply. The admin-wide panel is a natural place for the allow-list UI. Any MCP-exposed plugin ability is usable by the bot for free.
- *Cons:* it doesn't reach external MCP servers. Descriptions come from other plugins and are model-facing, so the untrusted-annotations rule still applies, even locally. Some abilities may be destructive, so the allow-list plus confirmation matters.

**Shape C. "Remote MCP servers as toolkits" (a real MCP client).** An admin registers remote MCP servers (URL plus credential). Each approved server's tools become a php-agents toolkit, namespaced by server.
- *Pros:* this is what "MCP toolkit" most plausibly means to users, and nothing else in the WordPress ecosystem ships it (adapter #156 is open).
- *Cons, all significant:*
  - **SDK choice.** `mcp/sdk` v0.8.1 is Tier 3 and pre-1.0 with no client OAuth. It adds about 23 packages, including a Composer plugin, which have to be Strauss-prefixed alongside php-agents and must not clash with core's bundled php-http stack. `automattic/php-mcp-client` is small but depends on a dev-trunk VCS package and only speaks 2025-11-25. Writing a minimal client on php-agents' Symfony HttpClient means owning both protocol eras.
  - **Auth.** A full OAuth 2.1 client (PRM discovery, PKCE, RFC 8707, `iss` checks, CIMD, step-up) is a project of its own. A realistic 0.6 scope is bearer or static header only, with OAuth deferred.
  - **Security surface.** SSRF (server URLs and OAuth metadata), encrypted token storage that WordPress doesn't provide, tool-definition pinning and change re-approval, per-call confirmation for non-read-only tools, and result sanitization before the model sees it.
  - **Runtime.** STDIO means `proc_open` from PHP-FPM, which is unsuitable for most hosts, so it should be HTTP only.
  - **Timing.** Waiting for WordPress's own `wordpress/mcp-client` decision (#156) could save rework.

**Recommendation (for the brainstorm, not decided):** ship **B plus the Shape A cleanup** as the 0.6 "MCP toolkit". It is dependency-free and uses the same Abilities that MCP exposes, which fits the "admin-wide panel" half of the milestone. Then prototype **C** behind a flag with bearer-only HTTP against 2025-11-25 and 2026-07-28 servers, and revisit the SDK choice when `mcp/sdk` reaches Tier 2 or 1.0 or WordPress settles #156.
