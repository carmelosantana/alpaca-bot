# WordPress-native AI agent ecosystem and extension primitives (as of 2026-11-20)

Scope: what an agent-harness plugin (Alpaca Bot) can build on in core and the surrounding ecosystem, and how other AI plugins expose extension points, so that small companion plugins can plug into it. Research was run on 2026-11-20 using WebSearch/WebFetch (about 17 calls). Most facts come from make.wordpress.org and developer.wordpress.org. Items marked **[unverified this session]** are cited from canonical URLs but were not re-fetched.

Release context: WordPress 7.1 shipped on 2026-08-19, and a 7.1.1 maintenance cycle started in September 2026 — [7.1 release party schedule](https://make.wordpress.org/core/2026/07/03/wordpress-7-1-release-party-schedule/), [7.1.1 schedule](https://make.wordpress.org/core/2026/09/02/wordpress-7-1-1-release-schedule/). The 7.0 Field Guide is dated 2026-05-14 — [7.0 Field Guide](https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/).

## Q1. Abilities API (core since 6.9): registration, schema, permissions, meta, REST, JS, and changes in 7.0/7.1

### Takeaway
The Abilities API is now the extension surface for agent tools in WordPress. Each ability is a namespaced unit with a JSON Schema in/out contract, a `permission_callback`, annotation hints, and a `public`/`show_in_rest` exposure flag. 7.0 added a JS client. 7.1 added a full execution-lifecycle filter pipeline (pre-execute short-circuit, input normalization, permission override, result filtering, validation filters). That pipeline is the natural place for a harness to put guardrails, approval gates, caching, and receipts.

### Cited Findings
**Registration (6.9)**
- Signature is `wp_register_ability( string $name, array $args ): WP_Ability|null`. Names are lowercase, namespaced and slash-separated (e.g. `my-plugin/my-ability`). Required args are `label`, `description`, `category`, `execute_callback` and `permission_callback`. Optional args are `input_schema`, `output_schema` (JSON Schema), `meta` and `ability_class` (which must extend `WP_Ability`). Introduced in 6.9.0 — [wp_register_ability reference](https://developer.wordpress.org/reference/functions/wp_register_ability/)
- `meta.annotations` holds `readonly`, `destructive` and `idempotent`, each `bool|null` and described as hints for tooling. `meta.public` (default false) marks the ability as available to clients such as REST, MCP or AI agents. `meta.show_in_rest` defaults to the value of `public` and overrides it when set explicitly — [wp_register_ability reference](https://developer.wordpress.org/reference/functions/wp_register_ability/)
- Abilities must be registered on `wp_abilities_api_init`. Registering elsewhere triggers `_doing_it_wrong()` and returns null. Categories go through `wp_register_ability_category()` on `wp_abilities_api_categories_init` and must exist before any ability that references them. Related functions: `wp_unregister_ability()`, `WP_Abilities_Registry::get_instance()` — [wp_register_ability reference](https://developer.wordpress.org/reference/functions/wp_register_ability/)
- A core changeset expanded the annotation docs: destructive means the ability "may change its environment", and idempotent means repeated calls with the same args have no extra effect — [changeset 61086](https://core.trac.wordpress.org/changeset/61086)
- Core 6.9 ships `core/get-site-info`, `core/get-user-info` and `core/get-environment-info` — [MCP Adapter intro (developer blog)](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)
- The existing filter `wp_register_ability_args` can rewrite another plugin's (or core's) registration args, for example to opt core abilities into MCP — [MCP Adapter intro](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)

**REST (`/wp-json/wp-abilities/v1`)**
- `GET /abilities` takes `page`, `per_page` (default 50, max 100) and `category`. Also available: `GET /categories`, `GET /categories/{slug}`, `GET /{namespace}/{ability}`, and `GET|POST|DELETE /{namespace}/{ability}/run`. Every endpoint requires an authenticated user. `run` is further gated by `permission_callback`. Application passwords are recommended for external access. Abilities without `show_in_rest` return `rest_ability_not_found` — [REST API endpoints](https://developer.wordpress.org/apis/abilities-api/rest-api/)
- Method rules: readonly uses GET, input-taking non-readonly uses POST, destructive uses DELETE. GET/DELETE pass `input` as URL-encoded JSON, and POST sends a JSON body — [REST API endpoints](https://developer.wordpress.org/apis/abilities-api/rest-api/). **Conflict:** the 7.0 JS dev note says DELETE applies to `destructive` *plus* `idempotent`, and everything else non-readonly goes to POST — [Client-side Abilities API in 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/)

**7.0: JS client**
- `@wordpress/abilities` holds pure state with no server dependency. `@wordpress/core-abilities` fetches server abilities and categories from `/wp-abilities/v1/` into the store. The store name is `core/abilities`. The API is `registerAbilityCategory`, `registerAbility({name,label,description,category,callback,input_schema,output_schema,permissionCallback,meta.annotations})`, `executeAbility(name,input)` (validate, permission, run, validate output; errors like `ability_invalid_input` and `ability_permission_denied`), `getAbilities(filter)`, `getAbility`, and `unregisterAbility`. Core enqueues `core-abilities` on admin pages. Client-side abilities (e.g. navigate or insert blocks) run in the browser — [Client-side Abilities API in 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/)
- A reader comment on that post reports that `sanitize_callback` can block `executeAbility()`, with a proposed fix in Gutenberg PR #77029. This is a reader claim and not confirmed — [same post](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/)

**7.1: execution lifecycle filters (dev note 2026-07-29)**
- Order of execution:
  1. `wp_pre_execute_ability( $pre, $name, $input, $ability )` runs before normalization, validation and permission. Any non-`$pre` return short-circuits; intended uses are caching, rate limiting, maintenance mode, **approval workflows** and mocks.
  2. `wp_ability_normalize_input( $input, $name, $ability )` can return a `WP_Error`, which defaults to REST 400.
  3. `wp_ability_permission_result( $permission, $name, $input, $ability )` runs after `permission_callback`. It applies to REST and WP-CLI too, and returning `true` can override a denial.
  4. `wp_before_execute_ability` (action) fires.
  5. The `execute_callback` runs.
  6. `wp_ability_execute_result( $result, $name, $input, $ability )` can redact, enrich, convert or recover, but the result must still match `output_schema`.
  7. `validate_output` runs.
  8. `wp_after_execute_ability` (action) fires.

  Source: [New execution lifecycle filters in 7.1](https://make.wordpress.org/core/2026/07/29/new-execution-lifecycle-filters-for-the-abilities-api-in-wordpress-7-1/)

**7.1: other improvements (dev note 2026-07-31)**
- `wp_ability_validate_input` and `wp_ability_validate_output` take `( $is_valid, $value, $ability_name )` and run after JSON Schema validation. They return true or a `WP_Error`, and `false` becomes a generic error. REST-style `validate_callback` and `sanitize_callback` schema keywords are **not executed** by the Abilities API — [Abilities API improvements in 7.1](https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/)
- New action `wp_ability_invoked( $name, $input, $ability )` fires at the very start of `execute()`, for every call including cached, short-circuited, **approval-pending** and failed ones. It receives raw input, and the post warns against logging sensitive data — [same](https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/). **Inconsistency:** the companion lifecycle post says no new actions were added in its scope. The two posts cover different tickets, so both may be true.
- `wp_before_execute_ability` and `wp_after_execute_ability` now pass the `WP_Ability` instance as a trailing argument (#65248). This is backward compatible — [same](https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/)
- REST GET/DELETE `run` input is coerced to the schema types (`limit=10` becomes an int, `ids=1,2,3` becomes an int array). `core/get-user-info` gained fields and a `fields` enum input, and carries the `public` flag. Core ability outputs gained titles and descriptions — [same](https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/)

### Inferences
- The 7.1 pipeline maps directly onto harness concepts. `wp_ability_invoked` and `wp_after_execute_ability` can feed receipts. `wp_pre_execute_ability` can serve as an approval gate or dry-run cache. `wp_ability_permission_result` can apply per-agent or per-conversation capability narrowing. `wp_ability_execute_result` can redact output. Because these are core filters, companion plugins could write guardrails that apply to *any* agent, not only Alpaca Bot. Alpaca Bot should hook them itself rather than invent a parallel tool pipeline.
- The annotations are only "hints". A harness that wants destructive-action confirmation must enforce it itself, for example in `wp_pre_execute_ability`. It should not trust third-party annotations blindly.
- A companion plugin's minimal contract can be: "register abilities in your own category on `wp_abilities_api_init`". Alpaca Bot can then discover them through the registry or `GET /wp-abilities/v1/abilities?category=`.

### Gaps
- I did not confirm whether `meta.public` arrived in 6.9 or 7.1. The current reference page documents it, but one plugin readme implies 6.9/7.0 used only `show_in_rest` plus `mcp.public` ([search snippet, Activity Log for MCP / others](https://wordpress.org/plugins/activity-log-for-mcp/)). The 7.1 post mentions "the new `public` meta flag", which suggests it is 7.1. Check against trac before setting a floor.
- I did not find whether 7.1 added any core approval/confirmation primitive beyond the "approval-pending" wording in the `wp_ability_invoked` description.
- I did not find whether `WP_AI_Client_Prompt_Builder` passes abilities as function declarations natively. The 7.0 AI client post says only that it "integrates with the Abilities API".

## Q2. AI Client (7.0), the AI plugin (formerly AI Experiments), and the MCP Adapter

### Takeaway
Core 7.0 ships a provider-agnostic `wp_ai_client_prompt()` builder, with provider plugins and a Settings > Connectors screen for keys. The canonical "AI" plugin (renamed from AI Experiments) is the reference consumer, and the MCP Adapter turns abilities into MCP tools. Other plugins plug into MCP either by flagging abilities public or by creating their own server on `mcp_adapter_init`.

### Cited Findings
**AI Client (core 7.0)**
- `wp_ai_client_prompt()` returns `WP_AI_Client_Prompt_Builder`.
  - Generation methods: `generate_text()`, `generate_texts($n)`, `generate_image(s)()`, `generate_result()`, and `generate_*_result()` variants that return a `GenerativeAiResult` with `getTokenUsage()` and `getProviderMetadata()`.
  - Input methods: `with_text`, `with_file`, `with_history`.
  - Configuration methods: `using_system_instruction`, `using_temperature`, `using_max_tokens`, `using_model_preference` (an ordered list with fallback).
  - Output methods: `as_json_response`.
  - Errors come back as `WP_Error`.

  Source: [Introducing the AI Client in 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- Core bundles no providers. The official provider plugins are AI Provider for Anthropic, Google and OpenAI. Providers registered with the php-ai-client registry get Settings > Connectors integration for keys. `is_supported_for_text_generation()` and siblings are deterministic and make no API call. Filter: `wp_ai_client_prevent_prompt( $prevent, $builder )`. The client-side JS API requires an admin-level capability by default. The underlying SDK is `wordpress/php-ai-client` (camelCase, exceptions) — [same](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- Trac #64938 reports that `wp_ai_client_prompt()` does not pass the event dispatcher, so the SDK's before/after-generate events do not fire in 7.0 — [Trac #64938](https://core.trac.wordpress.org/ticket/64938). Fix status was not checked.
- The merge proposal is at [Proposal for merging WP AI Client into 7.0](https://make.wordpress.org/core/2026/02/03/proposal-for-merging-wp-ai-client-into-wordpress-7-0/)

**AI plugin (wordpress.org/plugins/ai; formerly "AI Experiments")**
- The release cadence runs from 0.2.0 (2026-01-20, Abilities Explorer) through 0.8.0 (2026-04-23) and 1.1–1.2 (June–July) to **1.3.0 (2026-08-18), 1.4.0 (2026-10-05) and 1.5.0 (2026-10-29)** — [AI release tag](https://make.wordpress.org/ai/tag/ai-release/), [0.2.0 post](https://make.wordpress.org/ai/2026/01/21/whats-new-in-ai-experiments-0-2-0-20-jan-2026/)
- 1.3.0:
  - Abilities: `ai/get-post-details`, `ai/get-post-terms`, `core/read-settings`, `core/read-users` and `core/read-content`. These are now behind an opt-in "Custom Abilities" toggle that controls external exposure (Abilities API and MCP).
  - New public `log_ai_request()` lets MCP servers write to the AI Request Log.
  - Per-ability filters for system instructions, prompts, builders and model preferences.
  - `AI_Service` and `get_ai_service()` are deprecated in favour of `wp_ai_client_prompt()`.

  Source: [AI release tag](https://make.wordpress.org/ai/tag/ai-release/), [1.3.0 post](https://make.wordpress.org/ai/2026/08/18/whats-new-in-ai-1-3-0/)
- 1.4.0 renamed abilities to `core/content-query`, `core/users-query` and `core/settings-get`, keeping deprecated aliases that 1.5.0 removed. It also added a `wpai_embeddings` vector table and the `Vector_Math` and `Vector_Ranker` classes — [AI release tag](https://make.wordpress.org/ai/tag/ai-release/)
- 1.5.0:
  - The "MCP Access" experiment installs the MCP Adapter plugin and adds a Tools > MCP Access screen for choosing which abilities agents can use.
  - A WebMCP experiment exposes the block editor to browser agents.
  - Embeddings sync in the background via cron.
  - New JS filter `ai.abilitiesExplorer.fields`.
  - The **Site Agent** is listed as planned work for 1.6.0, alongside "AI Workspace".

  Source: [AI release tag](https://make.wordpress.org/ai/tag/ai-release/)

**MCP Adapter (WordPress/mcp-adapter)**
- It converts abilities into MCP tools (mostly), resources (read-only data) or prompts. Abilities are private by default, and setting `meta.public` or `meta.mcp.public` to true exposes them. The default server `mcp-adapter-default-server` exposes three meta-tools: `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info` and `mcp-adapter/execute-ability`. Public abilities are reached through these, not listed individually. HTTP transport lives at `/wp-json/mcp/mcp-adapter-default-server`, and STDIO runs via WP-CLI. Pluggable interfaces: `McpTransportInterface`, `McpErrorHandlerInterface` and `McpObservabilityHandlerInterface`. Permissions apply at two levels, per-server transport auth and per-ability `permission_callback`. The latest version referenced in the README is v0.7.2 — [mcp-adapter README](https://raw.githubusercontent.com/WordPress/mcp-adapter/trunk/README.md), [repo](https://github.com/WordPress/mcp-adapter)
- Custom servers:
  - Install with `composer require wordpress/mcp-adapter` and call `McpAdapter::instance()`.
  - On the `mcp_adapter_init` action, call `create_server()` with: server id, REST namespace and route, name, description, version, transports (`HttpTransport`), error and observability handlers, and an explicit list of ability names.
  - Abilities listed on a custom server do not need `mcp.public`.
  - STDIO runs as `wp mcp-adapter serve --server=… --user=…`.
  - HTTP clients go through the `@automattic/mcp-wordpress-remote` proxy.
  - Auth defaults to application passwords (`WP_API_USERNAME`/`WP_API_PASSWORD`). Self-hosted sites have no core OAuth option.
  - Security guidance: least-privilege caps, a dedicated low-cap MCP user, read-only abilities for internet-facing endpoints, and no `__return_true` on destructive abilities.

  Source: [From Abilities to AI Agents (developer blog, 2026-02)](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)
- The adapter is vendored by large plugins (e.g. Elementor ships `vendor/wordpress/mcp-adapter`, and so does the Bluehost plugin) — [jsDelivr Elementor trunk copy](https://fastly.jsdelivr.net/wp/plugins/elementor/trunk/vendor/wordpress/mcp-adapter/README.md), [Bluehost copy of default-server guide](https://infowetrust.com/wp-content/plugins/bluehost-wordpress-plugin/vendor/wordpress/mcp-adapter/docs/guides/default-server.md)

### Inferences
- Multiple plugins vendoring the adapter means several copies can be loaded on one site. Alpaca Bot should detect an existing adapter instance and avoid shipping a conflicting copy. This is a version-skew risk.
- The default-server meta-tool pattern (discover, get-info, execute) is a context-economical way to expose many companion abilities. Alpaca Bot's own MCP server could mirror it, or create a dedicated server listing only Alpaca-approved abilities.
- `log_ai_request()` in the AI plugin is a competing "AI request log". Alpaca Bot receipts could optionally write to it when it is present, for interop.

### Gaps
- I could not confirm the exact `create_server()` parameter order, how annotations map to MCP `readOnlyHint`/`destructiveHint`, or the `meta.mcp.type` key for resources and prompts; the README summary did not cover them. Check the repo docs (`docs/guides/`).
- I did not confirm whether the MCP Adapter has been merged into core or remains a canonical plugin/package as of 7.1. Every source treats it as a plugin or Composer package.

## Q3. Add-on ecosystem patterns in WordPress (registries, dependencies, versioning, deprecation)

### Takeaway
Core gives dependency *presence* (`Requires Plugins`, 6.5+) but not versions or load order. Mature ecosystems combine a bootstrap action fired by the host, a registration class or base class, and filters for lighter hooks, with version checks done in code. Deprecation uses `_deprecated_hook` and `apply_filters_deprecated`/`do_action_deprecated`.

### Cited Findings
- `Requires Plugins:` takes comma-separated wordpress.org slugs only (no `dir/file.php`). A wp.org-hosted dependent may only depend on wp.org plugins, while off-.org plugins may declare either, but there is no install link for third-party dependencies. Dependents cannot activate until dependencies are active. Dependencies cannot be deactivated or deleted while dependents exist. Circular dependencies are detected and blocked. **There are no version constraints and no load-order guarantee**, so authors should still use `function_exists`/`class_exists`/version checks. The filter `wp_plugin_dependencies_slug` can remap slugs, e.g. to a `-pro` variant. MU-plugins as dependencies and theme dependencies are unsupported. Dependencies are never auto-installed. WP-CLI does not block install but does block activation — [Introducing plugin dependencies in 6.5](https://make.wordpress.org/core/2024/03/05/introducing-plugin-dependencies-in-wordpress-6-5/)
- Gravity Forms add-on framework **[unverified this session]**: add-ons extend `GFAddOn`/`GFFeedAddOn`/`GFPaymentAddOn`. They register on the `gform_loaded` action via `GFAddOn::register( 'ClassName' )`, declare `$_min_gravityforms_version`, and get settings pages, feeds, and uninstall handling for free — [GF Add-On Framework docs](https://docs.gravityforms.com/add-on-framework/)
- WooCommerce extensions **[unverified this session]**: a `WC requires at least` / `WC tested up to` header is shown in the admin, Woo declares feature compatibility via `FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ )` on `before_woocommerce_init`, and integrations extend `WC_Integration` registered through the `woocommerce_integrations` filter — [Woo developer docs](https://developer.woocommerce.com/docs/)
- ACF **[unverified this session]**: custom field types extend `acf_field` and register on the `acf/include_field_types` action, with `acf_register_field_type()`; options pages and blocks use `acf_add_options_page()` and `acf_register_block_type()` — [ACF docs: creating a new field type](https://www.advancedcustomfields.com/resources/creating-a-new-field-type/)
- Jetpack modules **[unverified this session]**: these are internal modules with header comments, toggled via `Jetpack::activate_module()`, and the `jetpack_get_available_modules` filter can hide them. Jetpack is a host for its own modules rather than an open third-party registry — [Jetpack GitHub](https://github.com/Automattic/jetpack)
- WP-CLI packages **[unverified this session]**: these register commands via `WP_CLI::add_command()` and are installed with `wp package install`, and plugins can register commands conditionally when `defined( 'WP_CLI' )` — [WP-CLI commands cookbook](https://make.wordpress.org/cli/handbook/guides/commands-cookbook/)
- Deprecation APIs **[unverified this session]**: `apply_filters_deprecated( $hook, $args, $version, $replacement, $message )` and `do_action_deprecated()` (both since 4.6) call `_deprecated_hook()`, which only fires a notice when the hook actually has callbacks. `_deprecated_function()` and `_doing_it_wrong()` round out the set — [apply_filters_deprecated](https://developer.wordpress.org/reference/functions/apply_filters_deprecated/), [_deprecated_hook](https://developer.wordpress.org/reference/functions/_deprecated_hook/)
- Core's own new registries (Abilities, Block Bindings sources, Interactivity stores) follow the pattern "registry singleton plus a `wp_register_*` function plus a dedicated `*_init` action, with `_doing_it_wrong` outside it". Abilities is an example — [wp_register_ability reference](https://developer.wordpress.org/reference/functions/wp_register_ability/)
- The AI plugin's own versioning practice is to rename abilities with deprecated aliases for one minor release, then remove them (1.4 to 1.5) — [AI release tag](https://make.wordpress.org/ai/tag/ai-release/)

### Inferences
- A recommended host shape for Alpaca Bot:
  - Fire `do_action( 'alpaca_bot_init', $registry )` (or `alpaca_bot_register_toolkits`) once the host is ready.
  - Expose an `Alpaca_Bot::api_version()`/`ALPACA_BOT_API_VERSION` constant so companions can check versions themselves, since `Requires Plugins` cannot.
  - Accept registrations as value objects or base classes, as GFAddOn does.
  - Prefer plain Abilities with an Alpaca category for tools, so companions also work with the MCP Adapter and other agents. Reserve Alpaca-specific hooks for harness concepts that core lacks: toolkits/bundles, receipts, conversation context, approval UI.
- Companions on wp.org can use `Requires Plugins: alpaca-bot` because Alpaca Bot is on wp.org. Version gating must happen at runtime.

### Gaps
- The GFAddOn, Woo, ACF, Jetpack, WP-CLI and deprecation-API specifics above come from established knowledge and were not re-fetched this session. A spot-check of the linked docs is advised before quoting signatures.

## Q4. Other WordPress AI agent plugins and their extensibility

### Takeaway
The extensible players today are AI Engine (filters plus its own MCP server and tool registry), the canonical AI plugin (abilities plus filters), AI Services (provider registry, now overlapping core), WordPress.com's hosted MCP (closed, Abilities-based), and Novamira (a code-exec MCP for dev/staging). A small cottage industry of companion plugins already exists around the Abilities and MCP registry: governance toggles, MCP activity logs, and ability packs. That shows companion-plugin demand is real.

### Cited Findings
- **AI Engine (Meow Apps):** markets itself as "Chatbot, AI Framework & MCP". It turns the site into an MCP server, and extra MCP tool sets cover plugins, themes, DB, Polylang and WooCommerce. The listing describes tools as curated and permission-aware, not arbitrary code execution — [wordpress.org/plugins/ai-engine](https://wordpress.org/plugins/ai-engine/), [GitHub](https://github.com/jordymeow/ai-engine)
- AI Engine filters are reported in a support thread, **not official docs**: `mwai_mcp_tools`, `mwai_mcp_callback`, `mwai_functions_list` (the documented one, expecting `Meow_MWAI_Query_Function` objects, buildable via `::fromJson()`), `mwai_function_call_max_depth`, `mwai_chatbot_reply`, `mwai_chatbot_query`, `mwai_chatbot_params` and `mwai_chatbot_blocks`. The thread also reports a `mwai_mcp_callback` argument-count mismatch between MCP (5 args) and Workspace (4 args) — [search result: WP.org support thread](https://wordpress.org/support/?p=18978595). The official docs are at ai.thehiddendocs.com, which I did not fetch.
- **AI Services (Felix Arntz):**
  - Version 0.7.4, tested up to 7.0.9, requires WP 6.0+ and PHP 7.2+.
  - Services: Anthropic, Google, Mistral, OpenAI, Perplexity, xAI and browser built-in AI.
  - Usable from PHP, JS, REST and WP-CLI, with function calling.
  - Custom services extend `Abstract_AI_Service`, `With_API_Client_Trait` or `Generic_AI_API_Client` (for OpenAI-compatible providers).
  - Filters: `ai_services_model_params`, `pre_option_ais_{service}_api_key`, `ai_services_chatbot_enabled`, `ai_services_request_timeout` and `ai_services_skip_api_request`.
  - The FAQ says it is "not a Core feature plugin" and does not discuss the 7.0 AI Client. Its last-updated date is inconsistent on the page.

  Source: [wordpress.org/plugins/ai-services](https://wordpress.org/plugins/ai-services/)
- **WordPress.com / Automattic:**
  - A hosted MCP server launched read-only in Oct 2025 and gained write abilities on 2026-03-20 (posts, pages, comments).
  - It is opt-in at wordpress.com/me/mcp, with per-operation toggles and OAuth 2.1, and is available on paid plans.
  - The Claude connector lists 60+ abilities, works for paid WP.com and Jetpack-connected sites, and says "every change is confirmed by you".
  - Jetpack AI moved to the AI Client SDK.

  Sources: [TechCrunch 2026-03-20](https://www.techcrunch.com/2026/03/20/wordpress-com-now-lets-ai-agents-write-and-publish-posts-and-more/), [Claude WordPress.com connector](https://claude.com/connectors/wordpress-com), [Automattic: WordPress, the OS of the agentic web (2026-04-21)](https://automattic.com/2026/04/21/wordpress-operating-system-agentic-web/). The ability counts come from secondary press and are unconfirmed.
- **Novamira:**
  - A free, AGPL-3.0 MCP server plugin that gives external agents (Claude, Cursor, Codex) PHP execution, filesystem and DB reach.
  - Distributed off wp.org (site and GitHub), with a Pro tier (Memory, builder expertise).
  - Reviewers position it for dev/staging only.
  - Tool counts conflict: 5 per the official page, 20 per InstaWP's review.

  Sources: [novamira.ai](https://www.novamira.ai/), [WP Mayor](https://wpmayor.com/novamira-ai-wordpress-mcp-plugin/), [InstaWP review](https://instawp.com/novamira-review/)
- **Companion/governance plugins already on wp.org:**
  - AbiliZen toggles which registered abilities are exposed and logs MCP calls (user, ability, success) — [AbiliZen](https://wordpress.org/plugins/abilizen-ai-abilities/)
  - Activity Log for MCP logs REST requests that carry an `Mcp-Session-Id` header and registers its own MCP server (`activity-log-for-mcp-server`). Ability counts differ by version (7 or 10) — [Activity Log for MCP](https://wordpress.org/plugins/activity-log-for-mcp/)
  - MCP Tracker is an admin-only log of MCP requests to the Abilities API — [MCP Tracker](https://wordpress.org/plugins/mcp-tracker/)
  - Others: "Enable Abilities for MCP" ([es-co listing](https://es-co.wordpress.org/plugins/?p=291619)), "HLB Ability Registry for MCP" ([ja listing](https://ja.wordpress.org/plugins/hlb-ability-registry-mcp/)), Agenistrator (admin agent) ([listing](https://wordpress.org/plugins/agenistrator/))
- Elementor and Bluehost vendor the MCP Adapter, so mainstream plugins are exposing abilities — [Elementor vendor copy](https://fastly.jsdelivr.net/wp/plugins/elementor/trunk/vendor/wordpress/mcp-adapter/README.md)

### Inferences
- No competitor exposes a first-class *toolkit/add-on marketplace* with versioned host APIs. AI Engine's extensibility is filter-based and loosely documented. This leaves a gap Alpaca Bot can fill with a documented companion contract built on Abilities.
- Governance and logging companions (expose toggles, MCP logs) already exist separately. Alpaca Bot's receipts and capabilities overlap them, so interop is worth considering: read core lifecycle hooks rather than only Alpaca-internal events.

### Gaps
- Elementor AI's extension hooks, the specifics of Jetpack AI's extension points, and any "Agentic WordPress" community initiative beyond Automattic's April 2026 essay were not researched in depth, for lack of budget.
- I did not confirm whether AI Engine has a formal paid add-on marketplace. The listing mentions Pro and MCP tool sets, but I found no third-party add-on directory.

## Q5. Guardrails: capabilities, nonces, application passwords, REST permissions, audit logging, attribution

### Takeaway
Agent actions in WordPress are attributed to a real WP user. MCP and REST clients authenticate as a user via application passwords (or OAuth on WP.com), cookie+nonce covers same-origin JS, and every ability gates on its own `permission_callback`. The 7.1 lifecycle hooks are now the clean, core-level place to log and gate agent actions. Agent-specific audit logging is handled by small plugins keyed on MCP session headers rather than by the big audit-log plugins.

### Cited Findings
- All `wp-abilities/v1` endpoints require authentication. Application passwords are recommended for external clients and cookie auth for same-origin requests. Execution is gated per ability by `permission_callback` — [REST API endpoints](https://developer.wordpress.org/apis/abilities-api/rest-api/)
- MCP clients act as logged-in WP users via application passwords, or WP-CLI `--user` for STDIO. The recommendations are a dedicated limited-capability user and least-privilege `permission_callback`s. Self-hosted sites have no built-in OAuth, and WP.com uses OAuth 2.1 — [MCP Adapter intro](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)
- `wp_ability_permission_result` applies to REST and WP-CLI checks and can add rules or override denials. `wp_ability_invoked` fires on every attempt, including failed and approval-pending ones, which makes it suitable for complete audit trails, with care around raw-input secrets — [lifecycle filters](https://make.wordpress.org/core/2026/07/29/new-execution-lifecycle-filters-for-the-abilities-api-in-wordpress-7-1/), [7.1 improvements](https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/)
- The AI Client's client-side JS requires a high-privilege capability (admin by default), and `wp_ai_client_prevent_prompt` can veto prompts site-wide — [AI Client 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- MCP attribution in practice: Activity Log for MCP identifies agent traffic by the `Mcp-Session-Id` header — [Activity Log for MCP](https://wordpress.org/plugins/activity-log-for-mcp/). miniOrange describes logging both the agent identity and the WP user, logging policy decisions, and redacting sensitive data before storage — [miniOrange blog](https://www.miniorange.com/blog/log-monitor-ai-agent-activity-wordpress/)
- The AI plugin's `log_ai_request()` gives MCP servers a shared AI Request Log — [AI release tag](https://make.wordpress.org/ai/tag/ai-release/)
- Meta caps and nonces **[unverified this session, canonical docs]**: `map_meta_cap` maps primitive caps such as `edit_post` plus an ID to role caps, and `current_user_can( 'edit_post', $id )` is the correct check inside a `permission_callback`. `wp_create_nonce`/`check_ajax_referer` and the REST `X-WP-Nonce` header protect cookie-authenticated requests — [Roles and Capabilities](https://developer.wordpress.org/plugins/users/roles-and-capabilities/), [REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
- Application passwords (core since 5.6) **[unverified this session]**: per-user, revocable, record last-used IP and date, and work only over HTTPS by default (`wp_is_application_passwords_available` filter) — [Application Passwords integration guide](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)

### Inferences
- A WordPress-native "receipt" can be a record keyed on `wp_ability_invoked` (start), `wp_ability_permission_result` (decision) and `wp_after_execute_ability`/`wp_ability_execute_result` (outcome). Each record carries user ID, app-password UUID or MCP session ID, ability name, annotations and redacted I/O. A companion "receipts to WP Activity Log/Stream" bridge plugin is a natural add-on.
- Per-agent capability narrowing can be done by having Alpaca Bot run tools as the chatting user, then intersect with an allow-list in `wp_ability_permission_result`. This never widens permissions, so it avoids returning `true`.

### Gaps
- I found no source showing **WP Activity Log** (Melapress) or **Stream** natively logging Abilities API or MCP executions as of Nov 2026. The search returned only smaller MCP-specific loggers. Check the Melapress changelog.
- I found no core concept of "agent identity" separate from the WP user (beyond app-password UUIDs). Attribution of *which agent* acted relies on plugin-level conventions such as MCP session headers.
