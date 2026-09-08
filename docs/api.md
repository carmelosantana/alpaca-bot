# Alpaca Bot REST API (`alpaca-bot/v1`)

Every route the plugin exposes lives under one namespace, `alpaca-bot/v1`, and rides on the
WordPress REST API: core authenticates the request, core validates the query and body against
each route's schema, and the plugin only decides what a capability is allowed to do. There is no
token or nonce code of the plugin's own.

Every example below was run against a local harness site, `alpaca10.wp.test`, as the `admin`
user with an Application Password, and the output shown is what came back (long model
reasoning is cut with `…`, and the site's private provider URL is replaced with
`http://ollama.example:11434/v1`). Where an example could not be run against a live site it says
so and cites the integration test it comes from.

## 1. Where the API is

`rest_url('alpaca-bot/v1/…')` is the URL, and its shape depends on the site's permalink setting:

| Permalinks | Base URL |
|---|---|
| Pretty (`/%postname%/` etc.) | `https://example.test/wp-json/alpaca-bot/v1/…` |
| Plain (the default) | `https://example.test/index.php?rest_route=/alpaca-bot/v1/…` |

Under plain permalinks the route is already a query string, so every further parameter joins it
with `&` (`…rest_route=/alpaca-bot/v1/conversations&limit=2`), and the pretty form does not
exist: on `alpaca10.wp.test`, which has plain permalinks, `/wp-json/alpaca-bot/v1/models` is a
web-server 404, not a redirect.

```
$ curl -sI https://alpaca10.wp.test/wp-json/alpaca-bot/v1/models | head -2
HTTP/2 404
content-type: text/html; charset=iso-8859-1
```

The examples in this document use the plain form because that is what the harness site has.
Substitute the pretty form on a site that has it. One URL is never yours to build: the
`stream_url` a chat ticket hands back (section 4) is already the right form for the site it came
from. Use it verbatim.

## 2. Authentication

The plugin adds nothing to core's model. Two ways in:

### From the admin (cookie + nonce)

A logged-in browser session sends its cookies; core requires the `wp_rest` nonce alongside them
or it treats the request as anonymous. `wp.apiFetch` does both and also builds the right URL for
the site's permalink form, so it is the shortest path from admin JavaScript:

```js
const models = await wp.apiFetch({ path: '/alpaca-bot/v1/models' });
```

With plain `fetch`, pass the nonce yourself (`wp_create_nonce('wp_rest')` on the server, or
`wpApiSettings.nonce` when `wp-api-fetch` is enqueued):

```js
const res = await fetch(wpApiSettings.root + 'alpaca-bot/v1/chat', {
  method: 'POST',
  credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
  body: JSON.stringify({ message: 'Hello' }),
});
```

### From anywhere else (Application Passwords)

Application Passwords are core's credential for external clients, and they only work over HTTPS.
Create one for the user the client should act as (a profile page has a form for it; WP-CLI is
quicker):

```
$ wp user application-password create admin my-client --porcelain
Xk2m 9pQ4 ...
```

Send it with HTTP Basic auth. The password's spaces are cosmetic; `curl -u` takes it with or
without them.

```
$ curl -s -u 'admin:Xk2m 9pQ4 ...' \
    'https://alpaca10.wp.test/index.php?rest_route=/alpaca-bot/v1/usage'
{"tokens":10158,"requests":18,"month":"2026-09","caps":{"user":0,"site":0}}
```

Without credentials every route is a 401; with credentials for a user who lacks the route's
capability it is a 403. Both carry core's `rest_forbidden` code, so a client has one code to
handle and the status tells it whether authenticating would help.

```
$ curl -s 'https://alpaca10.wp.test/index.php?rest_route=/alpaca-bot/v1/models'
{"code":"rest_forbidden","message":"You are not allowed to do that.","data":{"status":401}}
```

Revoke a password when the client is done with it: `wp user application-password delete admin
<uuid>` (the uuid is in `wp user application-password list admin`).

### Capabilities and the `alpaca_bot/capability/{route}` filters

Each route declares a capability. Chat, streaming, conversations, models and usage need
`edit_posts` (Contributors and up); the settings routes need `manage_options`.

| Filter key | Routes covered | Default |
|---|---|---|
| `chat` | `POST /chat` | `edit_posts` |
| `chat/stream` | `GET /chat/{id}/stream` | `edit_posts` |
| `conversations` | `GET\|DELETE /conversations`, `GET\|DELETE /conversations/{id}` | `edit_posts` |
| `models` | `GET /models` | `edit_posts` |
| `usage` | `GET /usage` | `edit_posts` |
| `settings` | `GET\|PUT /settings` | `manage_options` |
| `settings/schema` | `GET /settings/schema` | `manage_options` |
| `view/messages` | `GET /view/messages/{id}` | `edit_posts` |
| `view/history` | `GET /view/history` | `edit_posts` |
| `view/models` | `GET /view/models` | `edit_posts` |
| `view/default-model` | `POST /view/default-model` | `edit_posts` |
| `view/bubble` | `GET\|POST /view/bubble` | `edit_posts` |

The filter is `alpaca_bot/capability/{key}` with signature `(string $capability,
\WP_REST_Request $request)`, and the key is the route path with its `{id}` segment removed, so
one filter covers a collection and its items. `settings/schema` is its own key: loosening
`settings` for a custom role does not let that role read the schema until you name it too.

```php
// Let Authors chat and read their own history, but keep settings to administrators.
foreach (['chat', 'chat/stream', 'conversations', 'models', 'usage'] as $route) {
    add_filter("alpaca_bot/capability/{$route}", static fn(string $cap, \WP_REST_Request $r): string => 'publish_posts', 10, 2);
}

// Tighten one route per request: only administrators may delete the whole history.
add_filter('alpaca_bot/capability/conversations', static function (string $cap, \WP_REST_Request $r): string {
    return $r->get_method() === 'DELETE' && !str_contains($r->get_route(), '/conversations/') ? 'manage_options' : $cap;
}, 10, 2);
```

Return a capability name. Only a non-empty, non-numeric string is honoured; anything else
(`true`, `false`, `null`, a number) is ignored and the route's declared capability is checked
instead. That rule exists because `current_user_can('1')` is a legacy user-level check that
every Contributor passes, so a filter that returned a boolean by mistake would otherwise open
the route rather than close it.

## 3. Routes

| Method | Route | Capability | Rate limited |
|---|---|---|---|
| `POST` | `/chat` | `edit_posts` | yes (`chat` bucket) |
| `GET` | `/chat/{conversation}/stream?token=…` | `edit_posts` | no (the ticket was) |
| `GET` | `/conversations?limit=` | `edit_posts` | no |
| `DELETE` | `/conversations` | `edit_posts` | no |
| `GET` | `/conversations/{id}` | `edit_posts` | no |
| `DELETE` | `/conversations/{id}` | `edit_posts` | no |
| `GET` | `/models?refresh=` | `edit_posts` | yes (`chat` bucket) |
| `GET` | `/settings?reveal=` | `manage_options` | no |
| `PUT` | `/settings` | `manage_options` | no |
| `GET` | `/settings/schema` | `manage_options` | no |
| `GET` | `/usage?user=` | `edit_posts` (`user=all` needs `manage_options`) | no |
| `GET` | `/view/messages/{id}` | `edit_posts` | no |
| `GET` | `/view/history?conversation_id=` | `edit_posts` | no |
| `GET` | `/view/models?refresh=` | `edit_posts` | yes (`chat` bucket) |
| `POST` | `/view/default-model` | `edit_posts` | no |
| `GET` | `/view/bubble?role=&streaming=` | `edit_posts` | no |
| `POST` | `/view/bubble` | `edit_posts` | no |

Every response is JSON except a redeemed stream, which is `text/event-stream`, and the `/view/*`
fragments, which are `text/html` (section 3, "The `/view/*` fragments"). In the examples,
`$B` is the base URL and `$PW` the Application Password:

```
B='https://alpaca10.wp.test/index.php?rest_route=/alpaca-bot/v1'
PW='Xk2m 9pQ4 ...'
```

### `POST /chat`

One turn through the chat pipeline as the current user.

Body (JSON): `message` (string; may be omitted for an images-only turn), `conversation_id`
(integer, 0 or omitted starts a new conversation), `model` (string, empty for the site
default), `images` (array of `data:` URLs), `context` (object, passed to the context
collectors), `stream` (boolean, default false).

```
$ curl -s -u "admin:$PW" -H 'Content-Type: application/json' \
    -d '{"message":"Reply with exactly three words."}' "$B/chat"
{"conversation_id":163,"message":{"role":"assistant","content":"Boring is right.","model":"qwen3-vl:2b","usage":{"prompt_tokens":16,"completion_tokens":3776},"created":1788736235,"images":[],"meta":{"reasoning":"Hmm, the user asked me to reply with exactly three words. …"}},"receipt":{"user_id":1,"model":"qwen3-vl:2b","prompt_tokens":16,"completion_tokens":3776,"total_tokens":3792,"duration_ms":14941,"conversation_id":163,"log_id":164,"created":1788736235},"contexts":[]}
```

The 200 body is `{conversation_id, message, receipt, contexts}`:

- `message` is the assistant reply: `{role, content, model, usage: {prompt_tokens,
  completion_tokens}, created, images, meta}`. `message.meta.duration_ms` is the turn's
  wall time, the same number as `receipt.duration_ms`, kept on the stored reply so a reloaded
  transcript can show it. A thinking model (the site's default here,
  `qwen3-vl:2b`, is one) returns its reasoning in `message.meta.reasoning`; those tokens are
  completion tokens and count against the monthly caps, which is why a three-word answer above
  cost 3,776 of them.
- `receipt` is the usage row the turn wrote: `{user_id, model, prompt_tokens,
  completion_tokens, total_tokens, duration_ms, conversation_id, log_id, created}`.
- `contexts` lists the context sources folded into the system prompt (`[]` when the request
  carried no `context`).

Two site settings change what this route does with a body it accepted, and neither shows up as
an error. Read them from `GET /settings` (or `GET /settings/schema` for the labels) before you
build a UI on top of this route:

- **`chat.user_can_change_model`** (default on). With it off, `model` is *ignored*: the turn runs
  on `models.default` and the answer is a 200, not a 400. Only a `model` the catalog does not
  list is ever refused, and only while the setting is on. `message.model` in the reply is the
  authority on what actually ran — show that, not what you asked for.

  ```
  $ curl -s -u "admin:$PW" -H 'Content-Type: application/json' \
      -d '{"message":"Say only: ok","model":"minicpm-v4.6:1b"}' "$B/chat"     # setting off
  {"conversation_id":182,"message":{…,"model":"qwen3-vl:2b",…},"receipt":{…,"model":"qwen3-vl:2b",…}, …}
  ```

- **`privacy.save_history`** (default on). With it off nothing is persisted, so *every* turn
  answers `conversation_id: 0` and `receipt.conversation_id: 0`, a streamed turn's `stream_url`
  always names `/chat/0/stream`, and `GET /conversations` stays empty however much you chat.
  Sending a 0 back as `conversation_id` starts another new conversation rather than continuing
  one; there is no continuation to be had on such a site, and each turn stands alone. A client
  that builds a history list should check this setting rather than read an empty list as "no
  chats yet".

  ```
  $ curl -s -u "admin:$PW" "$B/conversations" | jq length                     # setting off
  4
  $ curl -s -u "admin:$PW" -H 'Content-Type: application/json' \
      -d '{"message":"Say only: ok"}' "$B/chat"
  {"conversation_id":0,…,"receipt":{…,"conversation_id":0,…}, …}
  $ curl -s -u "admin:$PW" "$B/conversations" | jq length                     # the turn left nothing
  4
  ```

An empty turn (no `message`, no `images`) is a 400 in the route's own words; the pipeline's
refusals (a model the catalog does not list, an image that is not a base64 PNG, JPEG, GIF or
WebP data URL, images whose decoded bytes together pass the site's allowance, a
`conversation_id` that is not yours) are 400 too, with the pipeline's message:

```
$ curl -s -u "admin:$PW" -H 'Content-Type: application/json' -d '{}' "$B/chat"
{"code":"alpaca_bot_bad_request","message":"The message is empty.","data":{"status":400}}
```

With `"stream": true` nothing runs yet. The answer is a 202 ticket:

```
$ curl -s -u "admin:$PW" -H 'Content-Type: application/json' \
    -d '{"message":"Name one colour. One word only.","model":"minicpm-v4.6:1b","stream":true}' "$B/chat"
{"conversation_id":0,"token":"5FYrSY7ONThOQWOk4n8jVGyaAufsHTVb","stream_url":"https:\/\/alpaca10.wp.test\/index.php?rest_route=%2Falpaca-bot%2Fv1%2Fchat%2F0%2Fstream&token=5FYrSY7ONThOQWOk4n8jVGyaAufsHTVb"}
```

The ticket's `conversation_id` is the one you sent, so it is 0 whenever the turn is not
continuing a conversation you named — and the URL then says `/chat/0/stream`. The real id
arrives in the stream's `start` event. Two different things produce that 0, and only the `start`
event tells them apart: a new conversation, which gets a real id there; or a site with
`privacy.save_history` off, where the `start` event says 0 too and there is no conversation to
go back to. The ticket is a 120-second, single-use transient bound to the user who asked; the
rate limit was counted on this POST, not on the stream. Section 4 covers reading it.

### `GET /chat/{conversation}/stream?token=…`

Redeems a ticket and streams the turn as server-sent events (section 4). Open `stream_url` as
you received it. The route answers a refusal as JSON before any frame is written:

| Case | Answer |
|---|---|
| No `token` parameter | 400 `rest_missing_callback_param` (core) |
| Token unknown, expired, another user's, for another conversation, or already redeemed | 403 `rest_forbidden` "Invalid or expired stream token." |
| Not logged in | 401 `rest_forbidden` |
| `HEAD` | 405 `alpaca_bot_method_not_allowed` with `Allow: GET` (core routes a HEAD to the GET handler; the handler refuses it before the ticket is touched, so a probe neither runs nor spends the turn) |
| Any other method | 404 `rest_no_route` (core: no such route for that method) |

```
$ curl -sI -u "admin:$PW" "$STREAM_URL" | grep -iE '^(HTTP|allow)'
HTTP/2 405
allow: GET

$ curl -s -u "admin:$PW" "$STREAM_URL"     # a second time, after the stream below
{"code":"rest_forbidden","message":"Invalid or expired stream token.","data":{"status":403}}
```

### `GET /conversations` and `GET /conversations/{id}`

The current user's conversations, newest first, as `[{id, title, created}]`. `limit` is 1-200;
0 or omitted uses the site's `chat.history_limit` setting (what the history screen shows).
Outside that range is core's 400.

```
$ curl -s -u "admin:$PW" "$B/conversations&limit=2"
[{"id":163,"title":"Reply with exactly three words","created":1788736220},{"id":10,"title":"Say only the word: receipt","created":1788719256}]

$ curl -s -u "admin:$PW" "$B/conversations&limit=201"
{"code":"rest_invalid_param","message":"Invalid parameter(s): limit","data":{"status":400,"params":{"limit":"limit must be between 0 (inclusive) and 200 (inclusive)"},"details":{"limit":{"code":"rest_out_of_bounds","message":"limit must be between 0 (inclusive) and 200 (inclusive)","data":null}}}}
```

One conversation is `{id, title, created, mode, messages: Message[]}`, each message in the
shape `POST /chat` returns:

```
$ curl -s -u "admin:$PW" "$B/conversations/163"
{"id":163,"title":"Reply with exactly three words","created":1788736220,"mode":"chat","messages":[{"role":"user","content":"Reply with exactly three words.","model":"qwen3-vl:2b","usage":null,"created":1788736220,"images":[],"meta":{}},{"role":"assistant","content":"Boring is right.","model":"qwen3-vl:2b","usage":{"prompt_tokens":16,"completion_tokens":3776},"created":1788736235,"images":[],"meta":{"reasoning":"Hmm, the user asked me to reply with exactly three words. …"}}]}
```

`meta` is always an object, `{}` when there is none. A completed assistant reply carries
`meta.duration_ms` (the samples above predate it). A reply cut short by a client that
disconnected mid-stream is stored with `meta.partial: true`.

A conversation that is not yours reads as missing, so its existence is not leaked:

```
$ curl -s -u "admin:$PW" "$B/conversations/999999"
{"code":"alpaca_bot_not_found","message":"Conversation not found.","data":{"status":404}}
```

### `DELETE /conversations/{id}` and `DELETE /conversations`

```
$ curl -s -u "admin:$PW" -X DELETE "$B/conversations/163"
{"deleted":true}
```

Deleting one that is not yours (or is gone) is the same 404 as reading it. `DELETE
/conversations` removes every conversation of the current user, however many, and answers
`{"deleted": n}`; from `ChatRoutesTest::test_delete_collection_removes_every_conversation_of_the_user_only`
(not run against the live site, which had history worth keeping):

```
{"deleted":2}
```

### `GET /models`

The chat models the configured provider offers, `[{id, label, tools, vision, thinking}]`, with
the site's default model in the `X-Alpaca-Bot-Default-Model` header so the body stays a plain
list. `refresh=1` bypasses the five-minute catalog cache and asks the provider again. An
unreachable provider is an empty list, not an error.

```
$ curl -si -u "admin:$PW" "$B/models"
HTTP/2 200
content-type: application/json; charset=UTF-8
x-alpaca-bot-default-model: qwen3-vl:2b

[{"id":"qwen3.8:latest","label":"qwen3.8:latest","tools":true,"vision":true,"thinking":true},{"id":"minicpm-v4.5:8b","label":"minicpm-v4.5:8b","tools":true,"vision":true,"thinking":false}, …]
```

(62 models on the harness site; the list is cut.) This route shares the chat rate limit
(section 6): a client that lists models as often as it chats never notices, a loop does.

### `GET /settings`, `PUT /settings`, `GET /settings/schema`

Administrators only. `GET /settings` is the whole `alpaca_bot_settings` option, every schema key
with defaults filled in. The one secret, `provider.api_key`, reads back as `••••` when a key is
stored and `""` when none is:

```
$ curl -s -u "admin:$PW" "$B/settings"
{"provider.kind":"ollama","provider.base_url":"http:\/\/ollama.example:11434\/v1","provider.api_key":"","provider.timeout":60,"models.default":"qwen3-vl:2b","models.temperature":0.7,"models.num_ctx":8192,"models.keep_alive":"5m","models.overrides":[],"chat.system_prompt":"","chat.welcome":"How can I help?","chat.placeholder":"Message Alpaca Bot","chat.user_can_change_model":true,"chat.context_messages":20,"chat.history_limit":20,"chat.spellcheck":true,"chat.assistant_avatar":"","privacy.save_history":true,"privacy.usage_log":true,"privacy.usage_retention_days":0,"governance.site_monthly_tokens":0,"governance.user_monthly_tokens":0,"toolkits.enabled":["web_fetch","summarize","draft_post"],"toolkits.user_agent":"AlpacaBot\/0.5 (+https:\/\/github.com\/carmelosantana\/alpaca-bot)"}
```

(`provider.base_url` is the site's own value.) What this route answers is what is *stored*, which
for two keys is not the same as what the plugin *uses*:

- `provider.base_url` loses to a non-empty `OLLAMA_API_URL` constant — see the rule below the
  `PUT` examples. There is nothing in this response that says the constant is set, so a client
  that shows the base URL is showing a value the provider may be ignoring. (The settings screen
  does say so, under the field.)
- `chat.user_can_change_model` and `privacy.save_history` change what `POST /chat` does with a
  body it accepts, silently; section 3's `POST /chat` covers both.

`?reveal=1` answers the raw key instead. The response is not cacheable — WordPress sends its own
no-cache headers on any REST response to a logged-in user, and this route sets `no-store` on the
reveal itself so the guarantee still holds on a site that has filtered core's headers off:

```
$ curl -si -u "admin:$PW" "$B/settings&reveal=1" | grep -iE '^(HTTP|cache-control)'
HTTP/2 200
cache-control: no-cache, must-revalidate, max-age=0, no-store, private
```

(That is core's string, which already contains `no-store`; it is the same on `/settings` without
the flag. Filter `rest_send_nocache_headers` to false and the plain `GET` answers no
`Cache-Control` at all while `?reveal=1` answers `cache-control: no-store` — the route's own.)

`PUT /settings` is a partial update: send the keys you are changing, as a JSON body of dotted
keys, and the reply is the whole array as stored (masked). Values go through the schema on the
way in: unknown keys are dropped, numbers clamped to their range, types coerced.

```
$ curl -s -u "admin:$PW" -H 'Content-Type: application/json' -X PUT \
    -d '{"models.temperature": 0.8}' "$B/settings"
{"provider.kind":"ollama","provider.base_url":"http:\/\/ollama.example:11434\/v1","provider.api_key":"","provider.timeout":60,"models.default":"qwen3-vl:2b","models.temperature":0.8,"models.num_ctx":8192, …}
```

The body must be JSON. PHP rewrites a dot in a form-field or query-string name to an
underscore before WordPress sees it, so `models.temperature=0.8` arrives as `models_temperature`
and matches nothing; rather than answer 200 having changed nothing, a PUT that names no schema
key is a 400:

```
$ curl -s -u "admin:$PW" -X PUT -d 'models.temperature=0.8' "$B/settings"
{"code":"alpaca_bot_bad_request","message":"No settings were sent. Send a JSON body of dotted keys, e.g. {\"models.temperature\": 0.7}.","data":{"status":400}}
```

Rules worth knowing before you write:

- **The API key** has three spellings on the way in: `""` clears it, `"••••"` (the mask, also
  in the schema route's `mask`) keeps what is stored, any other string replaces it. A value that
  is not a string at all (`null`, an array) keeps the stored key too, and the reply shows the
  mask so you can see it did. A PUT never reveals the key, whatever its body says.

  ```
  $ curl -s -u "admin:$PW" -H 'Content-Type: application/json' -X PUT -d '{"provider.api_key": "sk-test-1234"}' "$B/settings" | jq -c '{"provider.api_key"}'
  {"provider.api_key": "••••"}
  $ curl -s -u "admin:$PW" -H 'Content-Type: application/json' -X PUT -d '{"provider.api_key": "••••", "models.temperature": 0.7}' "$B/settings" | jq -c '{"provider.api_key", "models.temperature"}'
  {"provider.api_key": "••••", "models.temperature": 0.7}
  $ curl -s -u "admin:$PW" "$B/settings&reveal=1" | jq -r '."provider.api_key"'
  sk-test-1234
  $ curl -s -u "admin:$PW" -H 'Content-Type: application/json' -X PUT -d '{"provider.api_key": ""}' "$B/settings" | jq -c '{"provider.api_key"}'
  {"provider.api_key": ""}
  ```

- **`models.overrides` replaces wholesale.** It is a map of model id to `{temperature?,
  num_ctx?, keep_alive?, system?}`; a PUT of the map is the whole map, and a model left out is
  gone. To change one model, read the map, edit it, send it back. A blank or missing cell means
  "inherit the global value", never "empty". `sanitizeOverrides()` drops anything else.

- **Line endings are normalised.** Every string field goes through `Schema::coerce()`, which
  rewrites `\r\n` and a bare `\r` to `\n`, so a client that PUTs `"a\r\nb"` into
  `chat.system_prompt` reads back `"a\nb"` in that same response. Textareas in a browser post
  CRLF, and a prompt that round-trips through both surfaces would otherwise grow a `\r` per line
  per save. Compare against the reply, not against what you sent.

- **`provider.base_url` may not be what the provider uses.** A non-empty `OLLAMA_API_URL`
  constant (typically in `wp-config.php`) wins over the option, silently: the setting still
  reads and writes, and the provider ignores it. The harness site has the constant set, which is
  why a PUT of an unreachable URL there still chats.

- **`toolkits.enabled` is a list of ids, replaced wholesale.** The built-in toolkits the
  assistant may use, by the ids the schema route lists under the field's `options`
  (`web_fetch`, `summarize`, `draft_post`; all three by default). What is stored is the
  subset of those ids you sent, in the schema's order: an id it does not know is dropped, a
  duplicate is one entry, and `[]` switches every tool off. A value that is not a list at all
  stores `[]` rather than the default, since the default switches everything on. The field's
  `type` is `checkbox-list`, which a form renders as one checkbox per option.

  ```
  $ curl -s -u "admin:$PW" -H 'Content-Type: application/json' -X PUT -d '{"toolkits.enabled": ["draft_post", "bogus", "web_fetch"]}' "$B/settings" | jq -c '{"toolkits.enabled"}'
  {"toolkits.enabled":["web_fetch","draft_post"]}
  ```

- **`privacy.usage_retention_days`** (0-3650, 0 = keep forever) drives a daily cron event,
  `alpaca_bot/usage/cleanup`, that deletes usage receipts (`chat_log` rows) older than the
  window. It never touches conversations. A receipt counts toward the monthly caps until its
  month ends, so keep this at 31 or more while a cap is set. A site upgraded with receipts
  already in place gets 0 written on upgrade; only a fresh install takes the default of 90.

`GET /settings/schema` is the field list a client renders a form from, `{sections, fields,
mask}`; a secret field is flagged `secret: true` and server-side sanitize callables are left
out. Three of the 24 fields:

```
$ curl -s -u "admin:$PW" "$B/settings/schema"
{"sections":{"provider":{"label":"Provider","description":"Where models run. Ollama by default; WordPress AI providers when WordPress 7.0+ has them registered."}, …},"fields":{"provider.api_key":{"type":"string","default":"","section":"provider","label":"API key","description":"Optional. Sent as a Bearer token.","secret":true},"models.temperature":{"type":"number","default":0.7,"section":"models","label":"Temperature","min":0,"max":2},"privacy.usage_retention_days":{"type":"integer","default":90,"section":"privacy","label":"Keep usage receipts for (days)","description":"A daily cleanup deletes receipts older than this. 0 keeps them forever; 3650 (ten years) is the most, and a larger number is stored as 3650. Conversations are never touched. A receipt is counted toward the caps until its month ends, so keep this at 31 or more while a cap is set.","min":0,"max":3650}, …},"mask":"••••"}
```

### `GET /usage`

This calendar month's token spend, `{tokens, requests, month, caps}`. `user=me` (the default)
is the caller's own figures, the ones the per-user cap is measured against; `user=all` is the
whole site's and needs `manage_options`. `caps.user` is always present; `caps.site` only for
administrators. 0 means no cap.

```
$ curl -s -u "admin:$PW" "$B/usage"
{"tokens":10158,"requests":18,"month":"2026-09","caps":{"user":0,"site":0}}

$ curl -s -u "admin:$PW" "$B/usage&user=all"
{"tokens":10158,"requests":18,"month":"2026-09","caps":{"user":0,"site":0}}

$ curl -s -u "admin:$PW" "$B/usage&user=7"
{"code":"rest_invalid_param","message":"Invalid parameter(s): user","data":{"status":400,"params":{"user":"user is not one of me and all."},"details":{"user":{"code":"rest_not_in_enum","message":"user is not one of me and all.","data":null}}}}
```

(The harness site has one user, so `me` and `all` agree.) An Editor asking for `user=all` gets a
403 and sees `caps: {user}` only, per `SettingsRoutesTest::test_usage_route_reports_the_month`.

### The `/view/*` fragments

The chat screen (section 7) is server-rendered, and these routes render its pieces again on
demand: htmx swaps the selects, and the screen's script asks for the bubbles. They are for the
screen. A client that wants data reads the JSON routes above; these answer HTML, escaped where
it is built, under `Content-Type: text/html; charset=utf-8` and an `X-Alpaca-Bot-View: 1`
header. An error is still core's JSON error shape.

| Route | Answers | Parameters |
|---|---|---|
| `GET /view/messages/{id}` | The transcript (`#ab-messages`) of one of your conversations; 404 for anyone else's, as `/conversations/{id}` | |
| `GET /view/history` | The history select (`#ab-history`) | `conversation_id`: the open conversation, selected; one the `chat.history_limit` cut is listed with its own title; one that is not yours renders as a new chat |
| `GET /view/models` | The model select (`#ab-model`) on your effective model | `refresh` (boolean): ask the provider again, as `/models` |
| `POST /view/default-model` | An inline admin notice; stores `model` as your default (Kanboard #565) | `model` (string, required). 403 while `chat.user_can_change_model` is off, whatever the select says |
| `GET /view/bubble` | An empty bubble for the screen to stream into | `role` (`user`\|`assistant`, default `assistant`), `streaming` (boolean: a polite live region) |
| `POST /view/bubble` | A finished bubble, an assistant's content rendered as markdown; a user turn with its images is the optimistic bubble the screen shows while the turn runs | `role` (required), `content`, `model`, `usage` (`{prompt_tokens, completion_tokens}` or null), `duration_ms`, `images` (array of `data:` URLs; a user turn only) |

Your effective model is the one you last chose in the select (stored as user meta
`alpaca_bot_default_model`) while the site lets users choose and the provider still lists it,
else the site's `models.default`. The same rule picks the model for a `POST /chat` that names
none, so the model the screen shows is the model the turn runs on.

```
$ curl -si -u "admin:$PW" "$B/view/history" | grep -iE '^(HTTP|content-type|x-alpaca)'
HTTP/2 200
content-type: text/html; charset=utf-8
x-alpaca-bot-view: 1

$ curl -s -u "admin:$PW" -d 'model=qwen3-vl:2b' "$B/view/default-model"
<div class="notice notice-success inline"><p>Default model saved.</p></div>

$ curl -s -u "admin:$PW" -H 'Content-Type: application/json' \
    -d '{"role":"assistant","content":"Hello **you**","model":"qwen3-vl:2b","usage":{"prompt_tokens":16,"completion_tokens":3776},"duration_ms":14941}' "$B/view/bubble"
<article class="ab-msg ab-msg--assistant" data-role="assistant">…<div class="ab-msg__content"><p>Hello <strong>you</strong></p>
</div><footer class="ab-receipt">qwen3-vl:2b · 3,792 tokens · 14.9 s</footer></div></article>

$ curl -s -u "admin:$PW" "$B/view/messages/999999"
{"code":"alpaca_bot_not_found","message":"Conversation not found.","data":{"status":404}}
```

With `chat.user_can_change_model` off:

```
$ curl -s -u "admin:$PW" -d 'model=qwen3-vl:2b' "$B/view/default-model"
{"code":"rest_forbidden","message":"This site does not let users change the model.","data":{"status":403}}
```

`?_envelope=1` rebuilds the response, so the view mark is lost with it and the fragment comes
back as the JSON envelope's `body` string; the fragments are not meant to be enveloped.

## 4. Streaming (server-sent events)

Two requests: `POST /chat` with `"stream": true` for a ticket, then `GET` on its `stream_url`.
Once the ticket is redeemed the response is `text/event-stream` and each frame is an `event:`
line, one `data:` line of JSON, and a blank line:

```
$ curl -sN -i -u "admin:$PW" "$STREAM_URL"
HTTP/2 200
cache-control: no-cache
content-type: text/event-stream; charset=utf-8
x-accel-buffering: no

event: start
data: {"conversation_id":169,"model":"minicpm-v4.6:1b"}

event: delta
data: {"text":"","reasoning":"First"}

event: delta
data: {"text":"","reasoning":","}

…

event: delta
data: {"text":"orange","reasoning":""}

event: done
data: {"conversation_id":169,"message":{"role":"assistant","content":"orange","model":"minicpm-v4.6:1b","usage":{"prompt_tokens":18,"completion_tokens":283},"created":1788736395,"images":[],"meta":{"reasoning":"First, the user says: \"Name one colour. One word only.\" …"}},"receipt":{"user_id":1,"model":"minicpm-v4.6:1b","prompt_tokens":18,"completion_tokens":283,"total_tokens":301,"duration_ms":5282,"conversation_id":169,"log_id":170,"created":1788736395},"contexts":[]}
```

| Event | Data | When |
|---|---|---|
| `start` | `{conversation_id, model}` | Once the conversation exists (created on the spot for a ticket that named 0), before any text. This is where a new conversation's id arrives. |
| `delta` | `{text, reasoning}` | One per fragment. A thinking model sends its reasoning as `reasoning` deltas with empty `text` first, then the answer as `text`. |
| `done` | The exact body a direct `POST /chat` answers with: `{conversation_id, message, receipt, contexts}` | The turn finished. The connection closes after it. |
| `error` | `{code, message, data}`, the same JSON body a non-streaming error would carry | The turn was refused or failed. A refusal (cap exceeded, bad request) is an `error` frame alone with no `start`; a provider that fails mid-reply sends its deltas first, then `error`. The connection closes after it. |

A client that disconnects mid-stream is not refunded: the server notices at the next write,
stores what was sent so far with `message.meta.partial: true`, and records a receipt for the
tokens that arrived.

`EventSource` can read this, but it cannot send headers, so it only works from a cookie session
on the same origin (the token in the URL is the only credential a ticket needs beyond that).
From anywhere else, read the body with `fetch`. This reader was run with Node against the
harness site and printed the `start` and `done` frames and the streamed text between them:

```js
// Reads one stream: pass the ticket's stream_url verbatim, and whatever auth you use for
// the JSON routes (a Basic header here; `credentials: 'same-origin'` plus X-WP-Nonce in a browser).
async function readStream(streamUrl, headers, onEvent) {
  const res = await fetch(streamUrl, { headers });
  if (!res.headers.get('content-type')?.startsWith('text/event-stream')) {
    throw new Error(`refused: ${res.status} ${await res.text()}`); // a JSON error body
  }
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    let end;
    while ((end = buffer.indexOf('\n\n')) !== -1) {   // frames are blank-line separated
      const frame = buffer.slice(0, end);
      buffer = buffer.slice(end + 2);
      let event = 'message', data = '';
      for (const line of frame.split('\n')) {
        if (line.startsWith('event:')) event = line.slice(6).trim();
        else if (line.startsWith('data:')) data += line.slice(5).trim();
      }
      onEvent(event, JSON.parse(data));
    }
  }
}

const ticket = await (await fetch(`${B}/chat`, {
  method: 'POST',
  headers: { ...auth, 'Content-Type': 'application/json' },
  body: JSON.stringify({ message: 'Name one animal. One word only.', stream: true }),
})).json();

await readStream(ticket.stream_url, auth, (event, payload) => {
  if (event === 'delta') process.stdout.write(payload.text);
  else console.log(`\n[${event}]`, payload);
});
```

Output of that run (the `done` payload cut):

```
[start] {"conversation_id":165,"model":"minicpm-v4.6:1b"}
cat
[done] {"conversation_id":165,"message":{"role":"assistant","content":"cat", …
```

Behind nginx, `X-Accel-Buffering: no` is already on the response; behind anything else that
buffers whole responses (a CDN, some PHP-FPM setups with `output_buffering` forced on), frames
arrive together at the end. The server side is `Rest\Sse::prepareOutput()`.

## 5. Errors

Every error is core's JSON error shape, `{code, message, data: {status, …}}`, and on the stream
route the same object is the `error` frame's data.

| Status | Code | When | Extra `data` |
|---|---|---|---|
| 400 | `alpaca_bot_bad_request` | An empty turn; a model the catalog does not list; an image that is not a `data:` URL; a `conversation_id` that is not yours; a settings PUT naming no schema key | |
| 400 | `rest_invalid_param`, `rest_missing_callback_param` | Core's schema validation: `limit` out of 0-200, `user` not `me`/`all`, `refresh` not a boolean, a stream GET with no `token` | `params`, `details` |
| 401 | `rest_forbidden` | Not authenticated (no cookie+nonce, no Application Password) | |
| 402 | `alpaca_bot_cap_exceeded` | The monthly token cap is spent (`governance.user_monthly_tokens` or `governance.site_monthly_tokens`) | `scope` (`user`/`site`); `limit` and `used` only when `scope` is `user` |
| 403 | `rest_forbidden` | Authenticated but lacking the capability; a stream token that is not yours, spent, or expired; a default model posted while `chat.user_can_change_model` is off | |
| 404 | `alpaca_bot_not_found` | A conversation that does not exist or is not yours | |
| 404 | `rest_no_route` | Core: no such route for that method (e.g. `POST` on the stream route) | |
| 405 | `alpaca_bot_method_not_allowed` | `HEAD` on the stream route (`Allow: GET`) | |
| 429 | `alpaca_bot_rate_limited` | The `chat` bucket is spent for this minute; `Retry-After` header holds the seconds until it turns over | `retry_after` (same number as the header) |
| 502 | `alpaca_bot_provider_error` | The model provider failed or could not be built | `detail` (the raw provider error, administrators only) |

The 402, with the per-user cap set to 1 token for the run:

```
$ curl -s -u "admin:$PW" -H 'Content-Type: application/json' -d '{"message":"hi"}' "$B/chat"
{"code":"alpaca_bot_cap_exceeded","message":"Your monthly token cap has been reached (9957 of 1 tokens).","data":{"status":402,"scope":"user","limit":1,"used":9957}}
```

When it is the site's cap, the message and the data both stop at saying so: the site's cap and
its month-to-date total are what `GET /usage` withholds from anyone but an administrator, and
anyone who may chat can reach this error. Per `ErrorsTest`:

```
{"code":"alpaca_bot_cap_exceeded","message":"The site's monthly token cap has been reached.","data":{"status":402,"scope":"site"}}
```

The 429, produced by looping `GET /models` (see section 6 for the loop):

```
HTTP/2 429
retry-after: 10
{"code":"alpaca_bot_rate_limited","message":"Too many requests. Try again shortly.","data":{"status":429,"retry_after":10}}
```

The 502 could not be produced against the harness site (its provider URL is pinned by
`OLLAMA_API_URL`, section 3); these bodies are what
`ChatRoutesTest::test_a_provider_failure_is_a_502_and_leaves_no_empty_conversation` asserts for
an Editor and then an administrator, for a provider host that does not resolve:

```
{"code":"alpaca_bot_provider_error","message":"The model provider could not complete the request.","data":{"status":502}}
{"code":"alpaca_bot_provider_error","message":"The model provider could not complete the request.","data":{"status":502,"detail":"Provider error: Could not resolve host: ollama-gateway.internal for \"http://ollama-gateway.internal:11434/v1/chat/completions\"."}}
```

The message and status are fixed; the raw provider text names the endpoint, so it goes to the
debug log (under `WP_DEBUG`) and to `data.detail` only for a user who may read the settings
anyway. A failed first turn leaves no empty conversation behind.

## 6. Rate limit

`POST /chat` and `GET /models` share one fixed-window counter, the `chat` bucket: 30 hits per
user per UTC calendar minute by default, kept in a transient. A refused hit still counts, so
hammering past the limit does not refill the bucket. `GET /chat/{id}/stream` is deliberately
not limited: the POST that issued its ticket was, and the ticket can be redeemed once.

A user without the capability is refused before a bucket is touched. A route a site has opened
to visitors through its capability filter is limited per client address (a salted hash of
`REMOTE_ADDR`, never a forwarding header) rather than under user 0, so one script cannot spend
every visitor's allowance.

Filter `alpaca_bot/rate_limit` with signature `(int $perMinute, int $userId, string $bucket)`:

```php
// 120 a minute for administrators, 10 for everyone else.
add_filter('alpaca_bot/rate_limit', static function (int $perMinute, int $userId, string $bucket): int {
    return user_can($userId, 'manage_options') ? 120 : 10;
}, 10, 3);
```

The floor is 1: a filter that returns 0, `false` or nothing does not take the route down, it
makes every request but one a 429. To close a route, return a capability nobody holds from its
capability filter instead.

To see the limit, loop past it. On the harness site the 30th request in the minute was the one
refused (an earlier chat in the same minute had already used a hit):

```
$ for i in $(seq 1 40); do
    code=$(curl -s -o body.json -D head.txt -w '%{http_code}' -u "admin:$PW" "$B/models")
    printf '%s:%s ' "$i" "$code"; [ "$code" = 429 ] && break
  done; echo; grep -i retry-after head.txt; cat body.json
1:200 2:200 3:200 … 29:200 30:429
retry-after: 10
{"code":"alpaca_bot_rate_limited","message":"Too many requests. Try again shortly.","data":{"status":429,"retry_after":10}}
```

## 7. The admin surface

The plugin's menu slug is `alpaca-bot`: `admin.php?page=alpaca-bot` is the chat screen and
`admin.php?page=alpaca-bot-settings&tab={provider|models|chat|privacy|governance|toolkits}`
the settings page, one Schema section per tab, saved through core's `options.php` with the same
`Schema::sanitize()` the REST route uses. Settings is always `manage_options`.

The chat screen's capability is `edit_posts` through `alpaca_bot/admin/menu_capability`, with
signature `(string $capability)` — no request, since a menu is built once per admin load. It is
filtered separately from the REST routes, so a site can open the screen to a role without the
API or the reverse:

```php
add_filter('alpaca_bot/admin/menu_capability', static fn(string $cap): string => 'publish_posts');
```

The same rule §2 gives for the REST capability filters applies here, for the same reason: only a
non-empty, non-numeric string is honoured, and anything else (`true`, `false`, `null`, a number)
is ignored in favour of `edit_posts`. `__return_true` is the trap this closes — it is the obvious
thing to reach for when a menu will not appear, and `(string) true` is `'1'`, which
`current_user_can()` reads as the legacy `level_1` check rather than as a capability. Stock
`edit_posts` and `level_1` cover the same roles, so on a default site the swap would show no
symptom at all; on a site that has narrowed the capability, or that has roles built from user
levels, it silently replaces the answer with a different question. To widen the screen, name a
capability.

The bare chat screen is a new chat. `admin.php?page=alpaca-bot&conversation={id}` opens one of
your own (anyone else's, or a missing one, is a new chat again), and `&post={id}` names the post
the screen was opened from, which rides on the first turn as its context. The screen's requests
are the `/view/*` fragments (section 3) and `POST /chat`; its model select posts your choice to
`/view/default-model` on change, and the screen opens on that choice next time.

## 8. Adding routes

`alpaca_bot/rest/controllers` receives the plugin's `Rest\Controller` instances on
`rest_api_init`. Append a subclass to register routes in the same namespace with the same
permission callback, capability filters and (with `'rate_limit' => true`) the same limiter;
anything that is not a `Rest\Controller` is dropped.

```php
add_filter('alpaca_bot/rest/controllers', static function (array $controllers): array {
    $controllers[] = new class extends \AlpacaBot\Rest\Controller {
        public function routes(): array
        {
            return [['path' => '/ping', 'methods' => 'GET', 'capability' => 'read', 'callback' => static fn() => new \WP_REST_Response(['pong' => true])]];
        }
    };
    return $controllers;
});
```

The route key for that filter is `ping`, so `alpaca_bot/capability/ping` applies to it.
