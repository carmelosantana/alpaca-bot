# Alpaca Bot 1.0 — core refactor

Wayfinding map: Kanboard #2938 (project 17, Alpaca Bot). Research: `docs/research/2026-09-05-wp-7-ai.md`, `docs/research/2026-09-05-interactive-layer.md`, `docs/research/2026-09-05-user-asks.md`.

## Why

Alpaca Bot 0.4.17 (July 2024) is dormant: 20 active installs, tested to WP 6.5.10 against a current core of 7.1, a hand-rolled Ollama client that is out of spec, HTML woven through PHP behind htmx, two overlapping API surfaces with a shared nonce check, no tests, and unmaintained dependencies. Meanwhile WordPress 7.0 shipped an AI layer (Abilities API, Connectors, WP AI Client) and the in-house `carmelosantana/php-agents` library already provides providers, streaming, tool loops, structured output, embeddings, and vector-store contracts. 1.0 rebuilds the plugin on those two foundations while keeping the look and feel users have.

The user-asks research reframes the product: self-hosting alone no longer differentiates; the loudest asks are consent, cost control, and safe writes; the structural advantage of a local-first plugin is that it can index the site from inside PHP. 1.0 therefore ships governance primitives (caps, receipts) and the context seam, not just a nicer chat window.

## Decisions

| Decision | Choice | Ticket |
| --- | --- | --- |
| PHP floor | 8.4 for 1.0; 0.4.x stays on wordpress.org for 8.1 users | Kanboard #2939 |
| Engine | php-agents; `Api/Ollama.php`, `Api/Cache.php`, `Api/Status.php` deleted | Kanboard #2940 |
| WP 7.0 AI | `WpAiClientProvider` adapter (optional provider); abilities registered via Abilities API; Connectors opt-in only | Kanboard #2940, #2941 |
| Vendor conflicts | strauss prefixing of `vendor/` at build | Kanboard #2939, #2952 |
| UI layer | htmx 2.0.x behind `Chat\View\*` render components; zero-dep TypeScript via esbuild; no wp-scripts | Kanboard #2942 |
| Streaming | raw cURL transport behind an interface (WP_Http cannot stream to a callback) | Kanboard #2942 |
| API | one namespace `alpaca-bot/v1`: JSON resources + thin `/view/*` fragment routes; legacy `/htmx/*` and `/wp/*` retired | Kanboard #2943 |
| Auth | cookie + `X-WP-Nonce` in admin; Application Passwords externally; filterable per-route capabilities; transient rate limits | Kanboard #2943 |
| Options | WP Settings API + one typed schema array; `Utils/Settings.php` deleted | Kanboard #2948 |
| Model options | core set (default model, temperature, num_ctx, keep_alive, system) + per-model overrides; capability-gated tools/think/images | Kanboard #2946 |
| Hooks | `alpaca_bot/*` slash-namespaced pipeline hooks + PHP interfaces (Provider, Toolkit, ContextSource) | Kanboard #2955 |
| Scope | admin chat, REST, `[alpacabot]` shortcode, settings, hooks; agents become php-agents toolkits with a `[alpacabot_agent]` deprecation shim; sibling plugins parked | Kanboard #2959 |
| Deps | php-agents + league/commonmark (prefixed); textrank, sendadf removed | Kanboard #2952 |
| Front-end assets | Lucide SVG sprite built at compile time; prism, hint.css, Material Symbols removed; htmx via pnpm copied at build | Kanboard #2972 |
| Governance | server-side cost caps + per-conversation usage receipts in core | Kanboard #2971 |
| Context | `ContextSource` interface with one free source: post being edited / current screen | Kanboard #2971 |
| Legacy WIP | uncommitted REST conversion dropped; tree reset to origin/main | Kanboard #2945 |

## Changes

### Package layout (target)

```
alpaca-bot.php                 bootstrap: PHP 8.4 guard, autoload, Plugin::boot()
src/Plugin.php                 service wiring, hook registration
src/Provider/                  ProviderFactory (php-agents), WpAiClientProvider adapter, OllamaTransport (streaming cURL)
src/Chat/                      Conversation, Message, Pipeline (before_send → provider → after_receive), UsageMeter
src/Context/                   ContextSourceInterface, CurrentScreenSource
src/Toolkit/                   GetToolkit, SummarizeToolkit, DraftPostToolkit (php-agents ToolkitInterface)
src/Abilities/                 registers chat/summarize/draft as WP abilities
src/Rest/                      Controllers for chat, messages, models, settings; ViewController for htmx fragments
src/Admin/                     Menu, Screen (chat page), Settings (schema + Settings API), Assets (enqueue)
src/View/                      render components returning strings; Hx attribute builder
src/Storage/                   ChatHistory CPT + Log CPT (kept), migrations from 0.4 options
resources/ts/                  chat.ts (streaming, nonce refresh), highlight.ts, icons sprite build
assets/                        build output only (gitignored except committed release build)
tests/                         Pest unit (Brain\Monkey), Integration (WP test suite via harness)
```

### Behavior

- **Chat round-trip**: `POST /alpaca-bot/v1/chat` accepts `{conversation_id, message, model?, options?}`; returns JSON `{message_id, conversation_id}` and streams tokens on `GET /alpaca-bot/v1/chat/{id}/stream` as SSE (raw cURL transport; PHP output buffering disabled for that route; Apache/nginx caveats documented). The htmx page uses `/view/chat/{id}/stream` which wraps the same stream as HTML fragments.
- **Pipeline hooks** (all filters unless noted): `alpaca_bot/provider` (ProviderInterface), `alpaca_bot/toolkits` (array), `alpaca_bot/context` (ContextSource[]), `alpaca_bot/message/before_send`, `alpaca_bot/message/after_receive`, `alpaca_bot/render/message`, `alpaca_bot/capability/{route}`, action `alpaca_bot/usage/recorded`.
- **Governance**: per-user and site-wide monthly token caps enforced server-side before the provider call; every response records `{prompt_tokens, completion_tokens, model, duration_ms, cost_estimate}` on the message; the chat page shows a receipt per response and the settings page shows month-to-date.
- **Nonce lifecycle**: heartbeat-driven refresh; expired nonce disables the form and shows a WP notice (closes Kanboard #302).
- **Migration**: `0.4.x` options are read once and written into the new schema; chat history CPT unchanged.
- **Abilities**: `alpaca-bot/chat`, `alpaca-bot/summarize`, `alpaca-bot/draft-post` registered with capability checks so the WordPress MCP Adapter and WP AI Client `using_abilities()` can call them.

### Removed

`src/Api/*` (Htmx, Ollama, Render, Rest-legacy, Cache, Status, Base), `src/Utils/Settings.php`, `src/Utils/Options.php` (replaced by schema), `src/Help.php` (folded into settings help tabs), `assets/js/htmx*.js`, `prism*`, `hint*.css`, `materialsymbolsoutlined.*`, `multi-swap.js`, `php-science/textrank`, `carmelosantana/sendadf`, `erusev/parsedown`.

## Non-goals

- Admin-wide side panel, front-end block, MCP client toolkit, paid add-ons: separate spec (`2026-09-05-alpaca-bot-surfaces-and-extensions.md`).
- Migrating sibling plugins (assistants, accounts, api-proxy, postd, content-amp, demo, ai-agents, embeddings-pizza, vector-store).
- Any redesign of the visual language: same layout, same WP admin chrome, polished.
- Building an MCP transport of our own.
- Multi-user shared chat sessions, typing indicators (folded from Kanboard #197/#198 into 1.1+).

## Sequencing

1. **Foundations** (blocked by harness Kanboard #2969): PHP 8.4 guard, composer with php-agents + commonmark, strauss, esbuild pipeline, Pest scaffold, CI unit job. First failing test: provider factory returns OllamaProvider.
2. **Provider + pipeline**: ProviderFactory, OllamaTransport streaming, UsageMeter, caps, Pipeline hooks. Delete `Api/Ollama.php`.
3. **REST**: JSON controllers with capability filters and rate limits; Application Password auth test; delete legacy routes.
4. **Settings**: schema + Settings API; migration from 0.4 options; per-model overrides table.
5. **View layer**: `Chat\View` components + Hx builder; `/view/*` routes; chat.ts (streaming, nonce refresh, copy button); Lucide sprite; CSS cleanup (Kanboard #2954).
6. **Toolkits + Abilities**: Get/Summarize/DraftPost toolkits; `[alpacabot]` + `[alpacabot_agent]` shim; abilities registration.
7. **WP AI adapter**: `WpAiClientProvider`, provider picker in settings when WP ≥ 7.0.
8. **Hardening**: security audit (Kanboard #2944), performance measurement (Kanboard #2947), deep code review (Kanboard #2953), legacy bugs 565/566/613/473/302.
9. **Release**: per `2026-09-05-alpaca-bot-release-and-quality-gates.md`.
