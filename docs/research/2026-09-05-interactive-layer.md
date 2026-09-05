# Interactive layer for the Alpaca Bot chat rewrite

Kanboard #2942 · research · 2026-09-05

## TL;DR

1. **Keep htmx, but pin it to 2.0.x and put a real server-side render/component class behind it** — the current pain is not htmx, it is `Api\Render` echoing raw HTML strings from inside business logic.
2. The **Interactivity API is technically usable in wp-admin since WP 6.6** (hooks added by wordpress-develop#6453) but it has **no streaming story**, its value is server-processed directives on *block* output, and it drags in Script Modules + a build step for a use case it was not designed for.
3. **@wordpress/element + api-fetch + components** is the biggest rewrite, mandates webpack via `wp-scripts`, and `@wordpress/components` renders *block-editor* chrome, not classic `.wrap`/`.page-title-action` admin chrome — it fights the stated constraint rather than serving it.
4. **Streaming is the real decision, and it is orthogonal to all three.** Ollama already streams NDJSON; the blocker is that `wp_remote_request()` cannot stream to a callback, plus PHP/nginx/Apache buffering. Fix that layer first, in PHP, behind an interface.
5. Recommended shape: `Chat\View\*` render components (return strings, never echo) + `Api\Stream\*` transport + a ~200-line zero-dependency TypeScript file compiled with `tsc`/`esbuild` — **no webpack, no wp-scripts**.

---

## 1. Current state (audit of the four named files)

Read at commit-time state of `src/Api/Render.php` (789 lines), `src/Api/Htmx.php` (174), `src/Chat/Screen.php` (213), `assets/js/alpaca-bot.js` (406).

**There is no streaming today, in any form — not SSE, not chunked, not polling.**

- `src/Api/Ollama.php:197` sets `'stream' => apply_filters(Options::appendPrefix('ollama-stream'), false)` as a default parameter. Every chat turn is one blocking request.
- `src/Api/Ollama.php:322-327` uses `wp_remote_request()` with `'timeout' => Options::get('ollama_timeout', 60)`. The whole model response is buffered in PHP, then rendered, then swapped in one go. The spinner (`#indicator`, `assets/img/grid.svg`) is the entire "progress" UX.

**Swaps.** One custom pattern, defined once in `Render::getHxMultiSwapLoadChat()`:

```
hx-ext="multi-swap" hx-swap="multi:#ab-response:beforeend,#chat_id:outerHTML"
```

It depends on a vendored copy of the htmx 1.x `multi-swap` extension (`assets/js/multi-swap.js`), which calls `api.oobSwap(...)`. Bundled htmx reports `version:"1.9.11"` in `assets/js/htmx.min.js`, but `src/AlpacaBot.php:94` enqueues it with the version string `'1.9.10'` — the cache-busting version is already wrong.

**Nonces.** `Chat\Screen::outputChatForm()` puts `hx-headers='{"X-WP-Nonce": "…"}'` on the `<form>`, and `outputChatTextarea()` *also* emits a redundant hidden `chat_wpnonce` field that nothing reads. `Api\Htmx::validatePost()` verifies `$_SERVER['HTTP_X_WP_NONCE']` against `wp_verify_nonce(..., 'wp_rest')` — then re-verifies the same nonce *inside the per-argument loop*, once per allowed argument. It also reads `$_POST` directly instead of `$request->get_param()`, and `Htmx::renderOutput()` calls `$this->validatePost($request_url)` while `validatePost()` declares **no parameters**.

A `wp_rest` nonce is good for two "ticks" of `nonce_life` (default `86400`), i.e. **12–24 hours** ([`wp_nonce_tick()`](https://developer.wordpress.org/reference/functions/wp_nonce_tick/)). A chat screen left open overnight will silently start 403-ing. Nothing in `alpaca-bot.js` refreshes it.

**Layering.** `Api\Render` is the core problem, not htmx:

- It `echo`s everywhere (`outputChatMessage`, `outputDialogStart/End`, `outputTags`, `outputChatHistory`, `outputAdminNotice`) and returns nothing, so nothing is unit-testable and nothing is composable.
- HTML is concatenated as strings *inside* methods that also call `wp_insert_post()`, `update_user_meta()`, `get_post_meta()` and the Ollama client (`outputGenerate()` does model selection, image base64-encoding, history assembly, the HTTP call, rendering **and** persistence, in one 170-line method).
- `Api\Htmx::renderOutput()` does `header_remove('Content-Type')`, re-sets `text/html`, `switch`es on the raw route string, and ends in a bare `exit()` — bypassing `WP_REST_Server`'s response pipeline entirely.
- `Chat\Screen` holds `private object $htmx;` which is actually a `Render` instance — the naming already admits the class is doing two jobs.

**Verdict:** the interactive layer is not what is broken. A rewrite that swaps htmx for React while keeping `Render` would ship the same problem in TypeScript.

---

## 2. The cross-cutting problem: streaming out of a WP REST endpoint

This constrains all three options, so it comes first.

### 2.1 Upstream: Ollama already streams

`/api/generate` and `/api/chat` return "a series of responses" — one JSON object per chunk — unless `stream` is `false`, in which case "the response will be returned as a single response object, rather than a stream of objects" ([Ollama API docs](https://github.com/ollama/ollama/blob/main/docs/api.md)).

### 2.2 The actual blocker is `wp_remote_request()`

`WP_Http::request()`'s `stream` arg means "**Whether to stream to a file.** If set to true and no filename was given, it will be dropped it in the WP temp dir", paired with `filename` ("Filename of the file to write to when streaming") ([WP_Http::request()](https://developer.wordpress.org/reference/classes/wp_http/request/)). There is **no incremental/callback streaming** in the WP HTTP API. To stream tokens you must drop to a raw cURL handle with a write callback (or `fopen()` + `fgets()` on the response stream), which means the transport must be isolated behind an interface rather than left inline in `Ollama::request()`.

### 2.3 Downstream: taking over the REST response

WordPress gives you exactly one supported seam. `rest_pre_serve_request` "Allow[s] sending the request manually – by returning true, the API result will not be sent to the client", receiving `$served, $result, $request, $server` ([hook reference](https://developer.wordpress.org/reference/hooks/rest_pre_serve_request/)). That is the correct place to emit `text/event-stream` or a chunked body — not `header_remove()` + `exit()` as `Htmx::renderOutput()` does now.

### 2.4 Buffering caveats (these bite in production, not in Local)

| Layer | Problem | Mitigation |
|---|---|---|
| PHP | `flush()` "may not be able to override the buffering scheme of the web server and it has no effect on any client-side buffering in the browser" ([php.net/flush](https://www.php.net/manual/en/function.flush.php)) | `ob_flush()` **before** `flush()`; `ob_implicit_flush(1)` |
| PHP | `flush()` "can interfere with output handlers that set and send headers … (e.g. `ob_gzhandler()`)" ([php.net/flush](https://www.php.net/manual/en/function.flush.php)) | unwind all active buffers first; disable `zlib.output_compression` for the route |
| nginx + PHP-FPM | `fastcgi_buffering` defaults to **on**; "When buffering is enabled, nginx receives a response from the FastCGI server as soon as possible, saving it into the buffers" ([ngx_http_fastcgi_module](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_buffering)) | send `X-Accel-Buffering: no` — "Buffering can also be enabled or disabled by passing `yes` or `no` in the `X-Accel-Buffering` response header field" (same doc; identical wording in [ngx_http_proxy_module](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_buffering)) |
| nginx reverse proxy | `proxy_buffering` on by default | same `X-Accel-Buffering: no` header |
| Apache | `mod_deflate`/`mod_gzip` re-buffer the body | disable compression on the streaming route |

`X-Accel-Buffering` is the single highest-value line of code here, and it is a documented nginx feature rather than a hack.

### 2.5 Transport comparison

| | SSE (`text/event-stream`) | Chunked HTML / `multipart/mixed` | Polling |
|---|---|---|---|
| Auth | `EventSource` **cannot set custom headers** — MDN documents only `withCredentials`, no header support ([MDN EventSource](https://developer.mozilla.org/en-US/docs/Web/API/EventSource)). So no `X-WP-Nonce`. Workaround: the REST auth doc explicitly allows the nonce "as a `_wpnonce` data parameter" ([REST auth](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)) — i.e. `?_wpnonce=` in the URL | Normal `fetch()`/XHR → `X-WP-Nonce` header works unchanged | Normal `fetch()` → header works |
| Method | GET only | any | any |
| Client parse | native `EventSource`, or `fetch` + `ReadableStream` | `response.body.getReader()` + `TextDecoder`, or `for await (const chunk of response.body)` ([MDN Using readable streams](https://developer.mozilla.org/en-US/docs/Web/API/Streams_API/Using_readable_streams)) | trivial |
| Worker cost | holds one PHP-FPM child for the whole generation | same | same, unless the generator detaches |
| Buffering exposure | high | high | **none** |

**Polling is not actually cheaper.** WordPress has no persistent worker; something still has to hold the long Ollama request. The only way polling avoids a held connection is to have the *generating* request call `fastcgi_finish_request()` / `ignore_user_abort(true)` and write partials to a transient or post meta that a short polling endpoint reads. That is more moving parts than streaming, not fewer — but it is the only option that survives a hosting environment where output buffering cannot be disabled. **Design for both behind one interface** and let a filter pick.

---

## 3. Option A — htmx behind a proper render class

### 3.1 Version choice: 2.0.x, not 4.x, not 1.x

- htmx 2.0.10 is the current documented stable line; install via `https://cdn.jsdelivr.net/npm/htmx.org@2.0.10/dist/htmx.min.js` or `npm install htmx.org@2.0.10` ([htmx docs](https://htmx.org/docs/)).
- htmx **4.0.0 shipped 2026-08-28**. The team explicitly says "we are not marking 4.0 as `latest` in NPM because we do not want to force-upgrade users" and "htmx 2 will continue to be supported indefinitely so don't feel any pressure to upgrade"; 4.0 sits on `next` until early 2027 ([4.0.0 announcement](https://four.htmx.org/announcements/2026-08-28-htmx-4.0.0-is-released)).
- Staying on 1.9.11 is not an option — extensions were unbundled and legacy `hx-sse`/`hx-ws` attributes removed at 2.0 anyway, so the vendored `multi-swap.js` is already on a dead branch.

### 3.2 What the 1.x → 2.x move costs (all documented)

From the [1.x → 2.x migration guide](https://htmx.org/migration-guide-htmx-1/):

- "All extensions have been removed from the core htmx distribution and are distributed separately." → the vendored `multi-swap.js` needs replacing; `hx-swap-oob` in core covers what this plugin actually uses (one append + one `outerHTML`), so the extension can simply be **deleted**.
- Legacy `hx-sse`/`hx-ws` attributes "are no longer supported" — irrelevant here, the plugin never used them.
- `hx-on="evt: …"` → `hx-on:htmx:before-request="…"` — not used here.
- Smooth scroll is no longer default; `htmx.config.scrollBehavior = 'smooth'` restores it. `alpaca-bot.js` already does its own `scrollIntoView({behavior:'smooth'})`, so this is a no-op.
- IE dropped.

Realistically: **delete `multi-swap.js`, change one `hx-swap` string, bump the file.** That is the entire migration surface.

### 3.3 Streaming with htmx 2

htmx core "does not include built-in streaming or chunked-transfer support" ([htmx docs](https://htmx.org/docs/)). The SSE extension is `htmx-ext-sse` (CDN `https://cdn.jsdelivr.net/npm/htmx-ext-sse@2.2.4`), with `sse-connect`, `sse-swap`, `sse-close`, and `hx-trigger="sse:<message-name>"` ([SSE extension](https://htmx.org/extensions/sse/)). It rides `EventSource`, so it is GET-only and cannot carry `X-WP-Nonce` — you must use `?_wpnonce=`.

That pushes you to a **two-step turn**, which is a better design regardless of framework:

1. `POST /alpaca-bot/v1/chat/turn` (nonce in header, prompt in body) → creates the turn, returns the user bubble HTML + a turn id.
2. `GET /alpaca-bot/v1/chat/turn/{id}/stream?_wpnonce=…` → SSE, appends tokens.

### 3.4 Streaming with htmx 4 (the reason to design for it now)

htmx 4 fixes precisely this. "htmx 4 uses `fetch()` and `ReadableStream` instead of `EventSource`" and therefore "SSE responses can … use any htmx HTTP method, request values, and headers"; `hx-sse:connect` "uses `hx-headers` and `hx-vals`" ([hx-sse for htmx 4](https://four.htmx.org/extensions/sse)). It also adds `hx-multipart`, which streams `multipart/mixed` / `multipart/parallel` with per-part `HX-Target`/`HX-Swap`/`HX-Reswap`/`HX-Trigger` headers and advertises itself with `Accept: text/html, multipart/mixed, multipart/parallel` ([hx-multipart](https://four.htmx.org/extensions/hx-multipart)). That is a native answer to "stream one chat turn that touches several DOM regions" — the thing `multi-swap` was hacked in for.

htmx 4 also removes `hx-ext` entirely ("include extension scripts directly"), makes attribute inheritance explicit via `:inherited`, swaps 4xx/5xx response bodies by default, and adds `<hx-partial>` in place of `hx-swap-oob` ([What's new in htmx 4](https://four.htmx.org/docs/whats-new-in-htmx-4)). The 4xx/5xx swap change is actually *useful* here — `Render::outputAssistantErrorDialog()` currently has to return 200 with an error bubble.

**So: build on 2.0.x, keep every htmx attribute generated in exactly one PHP place, and the 4.x move later is a change to that one class.** Note `hx-headers` in 4 would become `hx-headers:inherited='js:{"X-WP-Nonce": …}'` on the form — which also fixes the stale-nonce bug, because `js:` "compute[s] the headers when the request is made".

### 3.5 Verdict

| | |
|---|---|
| Build tooling | **zero** for htmx itself (vendored file). TS only for the ~200-line glue |
| Admin look | native — you emit `.wrap`, `.page-title-action`, `.notice`, exactly as today |
| Streaming | good on 2.x (two-step + `?_wpnonce`), excellent on 4.x |
| Rewrite size | smallest — the PHP refactor is the work, the client barely changes |
| Risk | htmx 4 landing means 2.x is a "supported but superseded" line for the next couple of years |

---

## 4. Option B — WordPress Interactivity API

### 4.1 It does work in wp-admin now (but only just)

- Introduced in **WP 6.5** ([Interactivity API reference](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/)).
- Trac **#61087 "Interactivity API: Cannot be used from wp-admin"** was filed against 6.5. *(Direct fetch of core.trac returned HTTP 403; the ticket title and existence are confirmed via developer.wordpress.org search indexing — treat the ticket body as unverified.)*
- Fixed by **wordpress-develop#6453**, "Add Interactivity API hooks to wp_admin", listed under completed enhancements in [Gutenberg #60219 (Interactivity API: Iteration for WP 6.6)](https://github.com/WordPress/gutenberg/issues/60219). The PR added `admin_enqueue_scripts` (register script modules) and `admin_print_footer_scripts` (print client interactivity data) ([wordpress-develop#6453](https://github.com/WordPress/wordpress-develop/pull/6453)).
- Script Modules themselves only became admin-usable at **6.6**; `wp_enqueue_script_module()` arrived in 6.5 and prints on `wp_head`/`wp_footer`, which "do not fire in the wp-admin area" ([wp_enqueue_script_module()](https://developer.wordpress.org/reference/functions/wp_enqueue_script_module/)).

**Practical floor: WordPress 6.6.** The plugin currently requires PHP 8.1 and no stated WP floor; this would be a real bump.

### 4.2 It is not blocks-only

The FAQ is unambiguous: asked whether it can be used beyond blocks — *"Absolutely, yes, it is not limited to blocks"* — and points at `wp_interactivity_process_directives()` ([IAPI FAQ](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/iapi-faq/)). That function "processes the interactivity directives contained within the HTML content and updates the markup accordingly", takes `$html`, returns a string, added in 6.5 ([wp_interactivity_process_directives()](https://developer.wordpress.org/reference/functions/wp_interactivity_process_directives/)).

Caveat: for *blocks*, server processing requires `supports.interactivity` in `block.json`, and skipping server processing produces wrong initial markup and "a layout shift when JavaScript finally loads" ([SSR core concept](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/core-concepts/server-side-rendering/)).

### 4.3 The model

Directives: `wp-interactive`, `wp-context`, `wp-bind`, `wp-class`, `wp-style`, `wp-text`, `wp-on`, `wp-watch`, `wp-init`, `wp-run`, `wp-each`, `wp-each-child`; client `store( namespace, { state, actions, callbacks } )`; server `wp_interactivity_state()` (merged into the client store, and "Enables using WordPress APIs like nonces"), `wp_interactivity_config()` for static config ([API reference](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/api-reference/)). Runtime is Preact — chosen because it is "small: 8kB, including hooks and signals" and because React "creates poor developer experience because the logic has to be duplicated" between PHP and JS ([FAQ](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/iapi-faq/)).

### 4.4 Why it is the wrong fit here

- **No streaming story at all.** Nothing in the reference guide, core concepts, or the 6.6 iteration issue addresses streaming; the 6.6 issue lists SSR debug notices and derived-state getters but "no streaming-specific tasks" ([Gutenberg #60219](https://github.com/WordPress/gutenberg/issues/60219)). You would write raw `fetch` + `ReadableStream` inside a `wp-on` action and push tokens into `state` yourself — at which point the API is contributing directive syntax and nothing else.
- **Its headline benefit is server-processed directives on rendered block HTML**, which is the one thing an admin chat screen does not need.
- **It reintroduces a build step**: `@wordpress/interactivity` as an ES module + `wp-scripts build --experimental-modules` for `viewScriptModule` ([@wordpress/scripts](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/)).
- Directive-laden markup would have to survive `Options::getAllowedTags()` / `wp_kses()`, which this codebase leans on heavily.

Where it genuinely wins: the **front-end block/shortcode** surface. Worth keeping as a *separate, later* front-end renderer that shares the PHP view layer — not as the admin chat engine.

---

## 5. Option C — @wordpress/element + api-fetch + components

### 5.1 The pieces

- `@wordpress/element` "builds on top of React and provide[s] a set of utilities to work with React components and React elements", exporting `createElement`, `createRoot`, `useState`, `useEffect`, etc. ([packages-element](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-element/)).
- `@wordpress/api-fetch` wraps `window.fetch`; "the `Promise` return value of `apiFetch` will resolve to the parsed JSON result", and you register the nonce with `apiFetch.use( apiFetch.createNonceMiddleware( nonce ) )` — and notably "You may also assign to this property if you have a fresh nonce value to use", which is the documented hook for the stale-nonce problem. `parse: false` gets you the raw `Response` ([packages-api-fetch](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-api-fetch/)).
- `@wordpress/components` is "a library of generic WordPress components to be used for creating common UI elements shared between screens and features of the WordPress dashboard", and "Many components require the package's CSS stylesheet to be loaded" — the `wp-components` handle ([packages-components](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-components/)).

### 5.2 Streaming

`api-fetch` with `parse: false` gives you the `Response`, so `response.body.getReader()` + `TextDecoder` works ([MDN](https://developer.mozilla.org/en-US/docs/Web/API/Streams_API/Using_readable_streams)), and it is a POST with the nonce header. **This is the cleanest streaming story of the three on paper** — no `EventSource` limitation, no `_wpnonce` in a URL. That is the honest argument for Option C.

### 5.3 Why it still loses on this project's constraints

- **Build tooling.** `wp-scripts` is webpack + Babel + PostCSS + Sass + SVGR + MiniCssExtractPlugin ([packages-scripts](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/)). TypeScript is supported, but via `babel-loader` — type-checking is not part of the build. This is the opposite of "zero-dep, Node-native TS". It also adds a large npm dependency tree to a plugin whose current JS dependency count is zero (everything is vendored), which matters given the project's supply-chain posture.
- **Look and feel.** The docs describe the package as dashboard UI components, but in practice `Button`, `Card`, `Panel`, `SelectControl` render *block-editor* chrome. The admin screens this plugin ships (`.wrap`, `.wp-heading-inline`, `.page-title-action`, `.notice is-dismissible`, `add_thickbox()`, `admin_footer_text`) are classic wp-admin. Mixing the two reads as "a React app bolted into wp-admin", which is exactly the stated constraint's failure mode. Matching classic admin means *not* using most of `@wordpress/components` — at which point its main justification evaporates.
- **Rewrite size.** Every `echo` in `Render` becomes a JSON shape plus a component. The Markdown pipeline (`Parsedown`) and Prism highlighting move client-side or need a new JSON contract. This is a from-scratch UI, not a refactor.

---

## 6. The three delivery surfaces

| | (1) Single admin page | (2) Admin-wide side panel on every screen | (3) Front-end block / shortcode |
|---|---|---|---|
| **A. htmx** | Native. Exactly today's model, minus the `echo` soup | **Best fit.** Hook `admin_footer` — it fires "just after closing the `<div id="wpfooter">` tag and right before `admin_print_footer_scripts`" on admin screens only ([admin_footer](https://developer.wordpress.org/reference/hooks/admin_footer/)) — and print a `<details>`/drawer whose body is a single `hx-get` fetching the panel HTML. **~15 lines of markup and zero JS state.** htmx already loads globally via `admin_enqueue_scripts`. Cost: one extra ~14KB script on every admin screen; gate it behind a capability + user preference | Works: the same view class renders a shortcode/block body; htmx must be enqueued on `wp_enqueue_scripts` too. Bare `<script>` tag on the front end is a real weight/compat consideration |
| **B. Interactivity API** | Works on 6.6+. Needs Script Modules + module build; you hand-call `wp_interactivity_process_directives()` since there is no block | Works mechanically (`admin_enqueue_scripts` + `admin_print_footer_scripts` per wordpress-develop#6453), but every admin screen now loads Preact + the interactivity runtime and its import map. Heavier than htmx for less | **Best fit here.** This is the API's home turf: `supports.interactivity` in `block.json`, `view.js` loads "without blocking the page rendering" ([FAQ](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/iapi-faq/)) |
| **C. React** | Works, and is the conventional choice for a rich SPA-ish screen | Worst fit: a React root + `wp-element` + `wp-components` + the `wp-components` stylesheet on *every* admin page, and `wp-components` CSS is broad enough to risk bleeding into other plugins' screens | Works, but shipping React + `wp-components` CSS to the public front end for a chat widget is heavy |

Notice that no single option wins all three. **A + B is a coherent pairing** (htmx for admin, Interactivity API later for the public block) *provided* the PHP view layer returns strings both can consume. C is the only one that would force a single stack everywhere, and it is the wrong one for (2).

---

## 7. Recommendation

**Option A — htmx 2.0.x, behind a real server-side view layer, with streaming isolated behind a PHP transport interface and a `<200`-line TypeScript glue file built by `esbuild` (or plain `tsc`), no webpack, no `wp-scripts`.**

### Why

1. It is the only option where the *actual* defect (`Api\Render` echoing HTML from inside business logic) gets fixed rather than re-expressed. The client-side change is a rounding error next to the PHP refactor.
2. It is the only option that satisfies "native admin look" by construction — you keep emitting core admin markup and core admin classes.
3. It is the only option with a near-zero build: htmx is a vendored file; the glue is one TypeScript entry compiled with a single zero-config command and no runtime dependencies.
4. The admin-wide side panel (surface 2) is nearly free with htmx and expensive with both alternatives.
5. htmx 4's fetch-based `hx-sse` plus `hx-multipart` are a direct, documented answer to streaming a chat turn into several DOM regions — the exact thing the vendored `multi-swap` hack approximates today.

### Trade-offs, stated plainly

- **You are picking a line that is one major version behind.** htmx 4 is out and 2.x is "supported indefinitely" but superseded. Mitigate by generating every htmx attribute in one class, and by not depending on any 1.x-era extension.
- **htmx 2's SSE cannot carry `X-WP-Nonce`.** You accept a two-step turn and a `?_wpnonce=` query parameter on the GET stream. That parameter form is documented and supported, but it does put a short-lived token in a URL (and therefore potentially in access logs). Scope the nonce action to something narrower than `wp_rest` for the stream route if that matters.
- **No JSON API for free.** With React you would get a machine-readable chat API as a by-product. With htmx you get HTML. `src/Api/Rest.php` already exists separately — keep it as the JSON surface and do not let the two diverge.
- **Front-end block will likely want a second renderer.** Accept this: design the view layer so an Interactivity API front-end can be added later without touching domain code.
- **Streaming may be undeployable on some hosts.** Ship polling as a fallback behind the same interface, selected by a filter, and default to feature-detecting.

### Explicitly rejected

- **Interactivity API for the admin chat** — usable since 6.6, but no streaming story, forces a WP 6.6 floor plus a module build, and its core benefit (server-processed directives on block output) does not apply.
- **React/`@wordpress/components`** — mandates webpack, adds a large npm tree to a currently zero-dependency-JS plugin, and renders block-editor chrome where classic admin chrome is required.

---

## 8. Sketch of the boundaries (names only)

### PHP — domain / transport / view separation

```
AlpacaBot\Chat\
    Conversation              // aggregate: id, mode, model, messages; no HTML, no HTTP
    Message                   // value object: role, content, images, model, created_at
    Turn                      // one user prompt + one assistant reply in flight
    TurnFactory
    ConversationRepository    // interface
    PostConversationRepository// the current chat_history CPT + post meta impl

AlpacaBot\Chat\View\          // every method RETURNS a string; nothing echoes
    ViewInterface
    Renderer                  // composes views, applies wp_kses once, at the edge
    ChatScreenView            // .wrap / heading / hr.wp-header-end
    ChatFormView
    ToolbarView
    ModelSelectView
    HistorySelectView
    MessageView
    MessageToolsView          // copy / regenerate / edit / save-to-post
    TypingBarView
    NoticeView                // .notice is-dismissible
    ErrorView
    MarkdownFormatter         // Parsedown wrapper, isolated

AlpacaBot\Chat\View\Htmx\
    Attributes                // THE single place any hx-* string is produced
    SwapPlan                  // target + swap style, htmx2 oob today / hx-partial later
    StreamFrame               // one SSE event or one multipart part

AlpacaBot\Chat\Surface\
    SurfaceInterface
    AdminPageSurface          // surface (1)
    AdminPanelSurface         // surface (2), hooks admin_footer
    BlockSurface              // surface (3), later
    ShortcodeSurface

AlpacaBot\Api\Stream\
    StreamTransportInterface  // start(Turn), then emit frames
    SseTransport              // text/event-stream
    ChunkedTransport          // htmx 4 hx-multipart / plain chunked HTML
    PollingTransport          // transient-backed fallback
    TransportNegotiator       // filterable; feature-detects, picks one
    OutputGuard               // header_remove, X-Accel-Buffering: no, unwind ob_*, implicit flush
    RestStreamController      // owns the rest_pre_serve_request takeover

AlpacaBot\Api\Ollama\
    OllamaClient              // non-streaming, wp_remote_request (unchanged)
    OllamaStreamClient        // raw cURL + write callback; NDJSON
    NdjsonChunkParser
    ChunkAccumulator          // partial markdown → safe-to-render HTML

AlpacaBot\Api\Rest\
    ChatController            // POST /chat/turn        → creates turn, returns bubble + id
    ChatStreamController      // GET  /chat/turn/{id}/stream
    HistoryController
    ModelsController
    UserSettingsController
    NonceGate                 // one nonce check, per request, not per argument
    RequestPayload            // typed reader over WP_REST_Request; no $_POST
```

`Api\Render` and `Api\Htmx` are deleted. `Chat\Screen` shrinks to `AdminPageSurface` + `ChatScreenView`.

### TypeScript — `assets/src/`, one entry, no framework

```
chat/
    boot.ts                   // wires the below; the only file htmx knows about
    nonce.ts                  // fresh-nonce provider for hx-headers js: / heartbeat
    stream-client.ts          // fetch + ReadableStream reader; SSE and chunked modes
    composer.ts               // textarea, Enter-to-send, abort on Escape
    media.ts                  // wp.media uploader binding
    highlight.ts              // Prism re-highlight on htmx:afterSwap
    scroll.ts                 // autoscroll / stick-to-bottom
    clipboard.ts
types/
    htmx.d.ts
```

Build: one `esbuild` (or `tsc`) invocation → `assets/js/alpaca-bot.js`. Vendored `htmx.min.js` stays a checked-in file with a matching enqueue version. `assets/js/multi-swap.js` is deleted.

---

## Sources

- [htmx 1.x → 2.x Migration Guide](https://htmx.org/migration-guide-htmx-1/)
- [htmx Documentation (2.0.10)](https://htmx.org/docs/)
- [htmx SSE Extension (2.x, `htmx-ext-sse`)](https://htmx.org/extensions/sse/)
- [htmx 4.0.0 has been released!](https://four.htmx.org/announcements/2026-08-28-htmx-4.0.0-is-released)
- [What's New in htmx 4](https://four.htmx.org/docs/whats-new-in-htmx-4)
- [htmx 4 Documentation / extension index](https://four.htmx.org/docs)
- [htmx 4 `hx-sse` extension](https://four.htmx.org/extensions/sse)
- [htmx 4 `hx-multipart` extension](https://four.htmx.org/extensions/hx-multipart)
- [Interactivity API Reference (Block Editor Handbook)](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/)
- [Interactivity API — API Reference (directives, store, `wp_interactivity_state`)](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/api-reference/)
- [Interactivity API — Frequently Asked Questions](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/iapi-faq/)
- [Interactivity API — Server-side rendering core concept](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/core-concepts/server-side-rendering/)
- [`wp_interactivity_process_directives()`](https://developer.wordpress.org/reference/functions/wp_interactivity_process_directives/)
- [Gutenberg #60219 — Interactivity API: Iteration for WP 6.6](https://github.com/WordPress/gutenberg/issues/60219)
- [wordpress-develop #6453 — Add Interactivity API hooks to wp_admin](https://github.com/WordPress/wordpress-develop/pull/6453)
- [Trac #61087 — Interactivity API: Cannot be used from wp-admin](https://core.trac.wordpress.org/ticket/61087) *(fetch returned HTTP 403; title/existence confirmed via index only)*
- [`wp_enqueue_script_module()`](https://developer.wordpress.org/reference/functions/wp_enqueue_script_module/)
- [Script Modules in 6.5 — Make WordPress Core](https://make.wordpress.org/core/2024/03/04/script-modules-in-6-5/)
- [`@wordpress/element`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-element/)
- [`@wordpress/api-fetch`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-api-fetch/)
- [`@wordpress/components`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-components/)
- [`@wordpress/scripts`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/)
- [`rest_pre_serve_request` hook](https://developer.wordpress.org/reference/hooks/rest_pre_serve_request/)
- [`WP_REST_Server::serve_request()`](https://developer.wordpress.org/reference/classes/wp_rest_server/serve_request/)
- [`WP_Http::request()`](https://developer.wordpress.org/reference/classes/wp_http/request/)
- [REST API — Authentication (cookie auth, `X-WP-Nonce`, `_wpnonce`)](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
- [`wp_create_nonce()`](https://developer.wordpress.org/reference/functions/wp_create_nonce/)
- [`wp_nonce_tick()`](https://developer.wordpress.org/reference/functions/wp_nonce_tick/)
- [`admin_footer` hook](https://developer.wordpress.org/reference/hooks/admin_footer/)
- [PHP `flush()`](https://www.php.net/manual/en/function.flush.php)
- [nginx `ngx_http_fastcgi_module` — `fastcgi_buffering` / `X-Accel-Buffering`](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_buffering)
- [nginx `ngx_http_proxy_module` — `proxy_buffering` / `X-Accel-Buffering`](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_buffering)
- [MDN — `EventSource`](https://developer.mozilla.org/en-US/docs/Web/API/EventSource)
- [MDN — Using readable streams](https://developer.mozilla.org/en-US/docs/Web/API/Streams_API/Using_readable_streams)
- [Ollama API documentation](https://github.com/ollama/ollama/blob/main/docs/api.md)
