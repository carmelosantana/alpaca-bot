# Alpaca Bot — surfaces and extensions (1.1, 1.2, add-ons)

Wayfinding map: Kanboard #2938. Depends on `2026-09-05-alpaca-bot-1-0-core-refactor.md`; nothing here starts before 1.0 ships.

## Why

1.0 rebuilds the single admin chat page. The next value is reach and grounding: talk to the bot from any admin screen with that screen's context, expose the same chat on the front end, let the bot use tools beyond what ships in the zip, and let the site's own content ground answers. The user-asks research ranks locally grounded RAG with server-side ingestion, safe writes, and cost governance above every capability ask; it also warns that MCP transport plumbing is where support goes to die.

## Decisions

| Decision | Choice | Ticket |
| --- | --- | --- |
| Surfaces | 1.0 admin page → 1.1 admin-wide side panel → 1.2 front-end block/shortcode | Kanboard #2956, #2957 |
| Page context | `get_current_screen()`-driven ContextSource: post being edited, list-table query, settings page | Kanboard #2956 |
| MCP outbound | 1.0 registers abilities; the official WordPress MCP Adapter exposes them; no transport of ours | Kanboard #2970 |
| MCP inbound | 1.1 adds an MCP client toolkit to php-agents; allowlist UI for user-connected servers | Kanboard #2970 |
| Free vs paid | free: current-post/screen context, caps, receipts; paid: site-wide RAG, external vector stores | Kanboard #2971 |
| Extension seam | `ContextSourceInterface` (core) + php-agents `VectorStoreInterface` / `EmbeddingProviderInterface` | Kanboard #2971 |
| Licensing | Lemon Squeezy licenses; small update endpoint for add-on zips | Kanboard #2971 |
| Writes | propose → diff → confirm → undo for any toolkit that mutates site data | research #2958 |

## Changes

### 1.1 admin-wide panel

- `admin_footer` renders a collapsed panel on every admin screen (capability-gated); one `hx-get` loads the chat fragment from `/view/panel`; state (open/closed, active conversation) in user meta.
- `CurrentScreenSource` gathers: screen id, post id/type/title/excerpt when editing, current list-table filters, current settings section. Sent as structured context, shown to the user as chips they can remove before sending (consent by default).
- Multi-user and typing indicators (Kanboard #197/#198 legacy) considered here only if the panel becomes shared; default is per-user.

### 1.1 MCP client toolkit

- `McpToolkit` in php-agents: connects to a user-configured MCP server (stdio not applicable; HTTP/SSE), lists tools, maps them to php-agents Tool objects. Alpaca Bot adds a settings tab: server URL, auth header, allowlist of tool names, per-tool "requires confirm" flag.
- Every tool call is logged with arguments and result size; mutating tools go through the propose/diff/confirm flow.

### 1.2 front-end

- Block `alpaca-bot/chat` (server-rendered, htmx) plus the `[alpacabot]` shortcode on the same view route. Guest policy: off by default; when on, per-IP transient rate limits, separate cap bucket, no history persistence unless logged in.
- Front-end assets are a separate, smaller bundle.

### Add-on 1: Site Knowledge (paid)

- Implements `ContextSourceInterface` with retrieval over an index of posts/pages/CPTs built by a WP-Cron or Action Scheduler ingestion job running inside PHP (no crawler, no 403s from the site's own WAF).
- Storage: default in-DB vector table via php-agents `VectorStoreInterface`; optional qdrant/pgvector drivers. Embeddings via `OllamaEmbeddingProvider` or `OpenAIEmbeddingProvider`.
- Settings: which post types, include drafts?, chunk size, reindex button, index status; per-answer citations rendered as links.
- Seed: the parked `vector-store` and `embeddings-pizza` siblings.

### Add-on 2: Site Actions (paid, later)

- Toolkits that create/update posts, terms, options, with the propose/diff/confirm/undo flow and an audit log CPT.

## Non-goals

- Building or hosting an MCP server transport (OAuth, DCR, remote connectors).
- A front-end customer-support widget product.
- AI SEO writer features.
- Multi-site tenancy beyond what WP multisite gives for free.

## Sequencing

1. 1.1 panel (needs only 1.0's ContextSource seam).
2. 1.1 MCP client toolkit in php-agents (library work first, plugin UI second).
3. Site Knowledge add-on (revenue; validates the seam and licensing).
4. 1.2 front-end block.
5. Site Actions add-on.
