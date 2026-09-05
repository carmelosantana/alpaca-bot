# WP Harness — prior art: what to copy, what to skip

_Research for Kanboard #2967 · 2026-09-05 · Carmelo Santana_
_Scope: local WordPress dev harness on Linux, Docker, TS+pnpm tooling, MCP-driven._

## TL;DR

1. **Copy DDEV's shape wholesale**: one global Traefik router owning :80/:443, hostname-routed to per-project compose stacks, plus mkcert-signed certs the browser already trusts. That is the whole "Local UX without Local's bulk".
2. **Copy wp-env's config ergonomics**: a small committed JSON file (`core`/`phpVersion`/`mappings`/`config`/`lifecycleScripts`) plus an uncommitted `.override.json` — but fix its two warts (no TLS, and non-merging override semantics).
3. **Drop `.local`.** RFC 6762 Appendix G explicitly recommends against it, and on this box `a.wp.local` already fails to resolve (Avahi + `mdns4_minimal [NOTFOUND=return]` in nsswitch, mDNS off in systemd-resolved). Use **`.test`** — RFC 2606 reserved, wildcard-able via dnsmasq, and it transfers to macOS via `/etc/resolver/test`.
4. **Cert strategy: one mkcert wildcard** for `*.wp.test` + `wp.test`, served by the shared router — not DDEV's per-project certs, not Local's per-site self-signed certs.
5. **Playground/wp-now/Studio are a fast lane, not the engine** (WASM PHP + SQLite, no MySQL, no real webserver). Steal their blueprints and cwd-detection; keep Docker+MariaDB as the harness.

---

## 1. `@wordpress/env` (wp-env)

### Config schema (`.wp-env.json`)

Documented fields: `core`, `phpVersion`, `plugins[]`, `themes[]`, `port` (8888), `testsPort` (8889, deprecated), `config` (wp-config constants), `mappings`, `mysqlPort`, `phpmyadmin`, `phpmyadminPort`, `multisite`, `lifecycleScripts`, and an `env` block for per-environment overrides. ([docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/))

- **Mounting**: `plugins`/`themes` accept relative paths, absolute paths, `owner/repo` GitHub refs, SSH repos, and ZIP URLs. Everything in `plugins` is **auto-activated**; the docs tell you to use `mappings` instead when you don't want that. `mappings` maps arbitrary WP paths → local paths, e.g. `"wp-content/mu-plugins": "./path/to/local/mu-plugins"`. ([docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/))
- **Version pinning**: `core` takes a repo ref (`"WordPress/WordPress#6.8"`), `phpVersion` a string (`"8.3"`); both have env-var equivalents `WP_ENV_CORE` / `WP_ENV_PHP_VERSION`. ([docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/))
- **Lifecycle scripts**: `afterStart`, `afterReset`, `afterCleanup`, `afterDestroy`; each overridable via `WP_ENV_LIFECYCLE_SCRIPT_{EVENT}`. Docs note they run on both fresh and existing environments, so they must be idempotent. ([docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/))
- **wp-cli**: `wp-env run cli wp user list`; containers are `mysql`, `wordpress`, `cli`, `composer`, `phpmyadmin`. `--env-cwd` sets the working dir; `--` escapes flags that collide with wp-env's own. ([docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/))
- **Overrides**: `.wp-env.override.json` is the private, uncommitted layer — but **only `config` and `mappings` merge**; `plugins`/`themes` are replaced wholesale. ([docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/))

### Limitations

- **No TLS.** HTTPS support has been an open Gutenberg issue since July 2018 ([#8211](https://github.com/WordPress/gutenberg/issues/8211), still open, "Needs Dev"); a PR ([#53959](https://github.com/WordPress/gutenberg/pull/53959)) proposed bring-your-own cert + `mod_ssl` but the maintainer view was that generating and trusting a self-signed cert is a power-user chore. That is exactly the gap our harness closes.
- **Port-based URLs** (`http://localhost:8888`), so N concurrent projects means N hand-assigned ports.
- Default creds `admin`/`password` everywhere.

### Copy / skip

| Copy | Skip |
| --- | --- |
| Committed JSON schema with explicit `core`/`phpVersion` pinning | Port-based addressing (`localhost:8888`) |
| `mappings` as the mount primitive (path-in-WP → path-on-host) | Auto-activating everything in `plugins` |
| `config` block → wp-config constants | Non-merging `.override.json` semantics — deep-merge every key instead |
| `.override.json` private layer | `testsPort` / dual-environment split (deprecated upstream) |
| `lifecycleScripts` with an idempotency contract | Shipping without TLS |
| Source strings that accept path \| `owner/repo` \| zip | — |

---

## 2. DDEV

### Topology

`ddev-router` is a **single global Traefik container** that owns :80/:443, inspects the request hostname, and proxies to the right project's `ddev-webserver`. That is what lets many projects share the standard ports. Per project you get `ddev-webserver` (nginx/apache + PHP-FPM) and `ddev-dbserver`. ([architecture](https://docs.ddev.com/en/stable/users/usage/architecture/))

### DNS

DDEV publishes a real internet wildcard: `*.ddev.site` resolves to `127.0.0.1`. No hosts-file edits in the happy path. Fallback is `sudo`-editing `/etc/hosts` when DNS rebinding protection blocks loopback answers (Fritz!Box et al.) or when offline — and **wildcards don't work in a hosts file**, so offline means enumerating every hostname. ([networking](https://docs.ddev.com/en/stable/users/usage/networking/), [blog](https://ddev.com/blog/ddev-name-resolution-wildcards/))

`project_tld` lets you swap `ddev.site` for your own domain, with `use_dns_when_possible: false` forcing the hosts-file path — but the docs say "it's easiest to work with DDEV using the default setting." ([config](https://docs.ddev.com/en/stable/users/configuration/config/))

Nice security detail worth stealing: hosts-file manipulation was split out of the main Go binary into a minimal `ddev-hostname` binary (v1.24.7) on least-privilege grounds — the big binary no longer runs elevated. ([blog](https://ddev.com/blog/ddev-hostname-security-improvements/))

### TLS

One-time `mkcert -install` creates a local CA and installs it into the system trust store; DDEV then signs per-hostname certs from that CA and the router presents them. **On Linux you need `libnss3-tools` first** or Firefox/Chromium won't pick up the CA. v1.25.2+ ships `ddev utility tls-diagnose`. ([blog](https://ddev.com/blog/ddev-local-trusted-https-certificates/), [search summary](https://docs.ddev.com/en/stable/users/install/configuring-browsers/))

### Project config (`.ddev/config.yaml`)

`name`, `type` (`wordpress`), `docroot`, `php_version` (5.6–8.5), `webserver_type`, `database` (engine+version), `nodejs_version`, `composer_version`, `xdebug_enabled`, `performance_mode` (Mutagen), `upload_dirs`, `web_environment`, `hooks`, `additional_hostnames` (adds `<name>.<project_tld>`), `additional_fqdns` (adds arbitrary FQDNs, hosts-file + cert), `router_http_port`/`router_https_port`, `omit_containers`. ([config](https://docs.ddev.com/en/stable/users/configuration/config/))

### Hooks

Events: `pre/post-start`, `pre/post-stop`, `pre/post-import-db`, `pre/post-import-files`, `pre/post-composer`, `pre/post-exec`, `pre/post-pull`, `pre/post-push`, `pre/post-snapshot`, `pre/post-config`, `pre/post-share`. Task types: `exec` (in container), `exec-host` (on host — the only kind allowed at `pre-start`, since containers aren't up), `composer`. The docs' own WordPress example runs `wp config create` + `wp core install` on `post-start` and `wp search-replace` on `post-import-db`. ([hooks](https://docs.ddev.com/en/stable/users/configuration/hooks/))

### Commands / extensibility

`ddev start|stop|restart` (with `--reset-database`, `--seed-snapshot`), `ddev describe`, `ddev list`, `ddev wp <args>` (wp-cli passthrough in the web container), `ddev exec`, `ddev ssh`, `ddev launch` (+ `--mailpit`), `ddev snapshot` / `snapshot restore` (xtrabackup/mariabackup), `ddev import-db` / `export-db`, `ddev share` (ngrok/cloudflared), `ddev add-on get|list|update`. Add-ons and custom services are just `docker-compose.*.yaml` files dropped in `.ddev/` and merged. ([commands](https://docs.ddev.com/en/stable/users/usage/commands/), [add-ons](https://docs.ddev.com/en/stable/users/extend/additional-services/))

Linux bonus: Mutagen is only default on macOS/Windows — native bind mounts are fast enough on Linux, so we can skip sync entirely. ([FAQ](https://docs.ddev.com/en/stable/users/usage/faq/))

### Copy / skip

| Copy | Skip |
| --- | --- |
| Global Traefik router owning :80/:443, hostname-routed | Dependence on **internet** DNS for the wildcard (offline = hosts-file enumeration) |
| mkcert local CA → trusted browser certs; `libnss3-tools` prerequisite documented up front | Per-project cert generation (one wildcard is enough for us) |
| Per-project YAML/JSON with `php_version`, `database`, `docroot`, `type` | `additional_fqdns` (docs themselves flag it as confusing) |
| Hooks with `exec` vs `exec-host` distinction and the pre-start constraint | The Go monolith + its surface area (dozens of subcommands) |
| `ddev wp` passthrough as a first-class command | `ddev share` / tunnels (out of scope) |
| `docker-compose.*.yaml` merge as the escape hatch — **generated compose, hand-editable override** | Discouraging custom TLDs — we need one |
| DB snapshots as a named, restorable primitive | Mutagen/NFS performance layer (unnecessary on Linux) |
| Least-privilege split for the one thing that needs root (hosts/certs) | — |

---

## 3. Lando

- **Recipes**: `.lando.yml` with `recipe: wordpress` and `config:` keys `php` (5.3–8.5), `webroot`, `database` (MariaDB/MySQL/Postgres), `via` (apache/nginx), `composer`, `xdebug`. ([wordpress recipe](https://docs.lando.dev/plugins/wordpress/index.html))
- **Tooling**: the recipe exposes `lando wp`, `lando composer`, `lando db-import` — and crucially, `tooling:` is a user-extensible map from a CLI verb to a command-in-a-service. That's the best idea in Lando: **the project defines its own verbs.**
- **Proxy**: Traefik-based; `*.lndo.site` is a real internet wildcard → 127.0.0.1. Binds :80/:443, falling back to :8888/:444 if taken, and binds to `127.0.0.1` by default for safety (`bindAddress` to change). Per-service hostnames are declared in a `proxy:` block, including wildcards, subpaths and fully custom domains — custom domains mean you own the DNS. ([proxy](https://docs.lando.dev/core/v3/proxy.html))
- **Certs**: Lando generates **its own CA** per configured domain and signs wildcard certs; `ssl: true` on a service adds HTTPS routes. Only single left-most wildcards (`*.domain.tld`) are reliably auto-generated. ([proxy](https://docs.lando.dev/core/v3/proxy.html))

### Copy / skip

| Copy | Skip |
| --- | --- |
| **`tooling:` — project-declared verbs mapped into containers.** This is the natural backing for MCP tools too | Rolling our own CA — mkcert already solves system+NSS trust |
| `proxy:` block: explicit hostname→service routing, multiple hostnames per service | `*.lndo.site` internet-DNS dependency (same flaw as ddev.site) |
| Port fallback when :80/:443 are taken; bind to `127.0.0.1` by default | The recipe abstraction — hard to escape once you outgrow it |
| Confirming the one-label wildcard limit shapes the hostname scheme | — |

---

## 4. Local (localwp.com)

Advertised features: one-click WordPress install, **Site Blueprints** ("save any site as a Blueprint to re-use later"), Live Links (shareable persistent URLs), **Mailpit** intercepting PHP sendmail, automatic self-signed TLS, one-click site shell with WP-CLI, Xdebug + VS Code, hot-swap between nginx/Apache and between PHP versions, plus paid-ish extras (Cloud Backups to Dropbox/Drive, MagicSync differential deploy, Image Optimizer, Link Checker, Instant Reload). ([features](https://localwp.com/features/))

- **One-click admin**: logs you into wp-admin with one click and offers a **dropdown of every admin user** on the site; any admin added in wp-admin shows up automatically. Deliberately disabled over Live Links for security. ([docs](https://localwp.com/help-docs/local-features/using-one-click-admin/))
- **SSL**: sites use the **`.local` TLD**, with per-site **self-signed** certs and a "Trust" button on the Site Overview. Caveats in their own docs: macOS Big Sur+ blocks automatic trusting (manual Keychain dance); browser behaviour varies ("try Firefox", or the `thisisunsafe` Chrome incantation); and **Local does not rewrite the DB from `http://` to `https://` for you.** ([SSL in Local](https://localwp.com/help-docs/advanced/ssl-in-local/))
- **Bulk**: Electron desktop app plus bundled OS-level PHP, MySQL, nginx and Apache binaries per environment. That's where the weight comes from — it isn't containers, it's a shipped userland per site. ([features](https://localwp.com/features/))

### Copy / skip

| Copy | Skip |
| --- | --- |
| **Site list as the home screen** — every site, its URL, its state, in one view | Electron shell + bundled OS-level PHP/MySQL/nginx per environment |
| **One-click admin with a user dropdown** — implement as a mu-plugin issuing one-time login URLs, exposed as an MCP tool | Per-site **self-signed** certs — use one mkcert-CA-signed wildcard instead |
| **Blueprints**: save a configured site as a reusable template | **`.local` TLD** — see §6; this is the single thing not to copy |
| Mailpit bundled and reachable per site | Manual "Trust" button UX (a proper CA makes it a one-time global step) |
| One-click site shell with `wp` already on PATH | Leaving `siteurl`/`home` on `http://` after enabling TLS — do the search-replace |
| Hot-swap PHP version as a first-class, low-ceremony action | Proprietary cloud add-ons (backups, MagicSync, Live Links) |

---

## 5. wp-now / Playground CLI / Studio

- **wp-now is deprecated** (announced 2026-06-08) in favour of `@wp-playground/cli`. ([announcement](https://make.wordpress.org/playground/2026/06/08/wp-now-is-deprecated-migrate-to-playground-cli/))
- **Playground CLI**: `npx @wp-playground/cli@latest start` auto-detects whether the cwd is a plugin, theme, `wp-content`, or full WP install. Flags: `--wp=6.8 --php=8.3`, `--blueprint=./blueprint.json`, `--skip-browser`, `--reset`; `server` for advanced control. Sites persist under `~/.wordpress-playground/sites/<path-hash>/`, hashed from the **cwd**, not the mounted path. ([announcement](https://make.wordpress.org/playground/2026/06/08/wp-now-is-deprecated-migrate-to-playground-cli/))
- **Runtime**: WASM PHP + **SQLite**, no MySQL, browser-sandbox semantics, ephemeral by default. Blueprints are JSON provisioning files. ([Playground docs](https://developer.wordpress.org/playground/))
- **Studio** (Automattic): Electron desktop app **powered by WordPress Playground**, so same WASM/SQLite engine; macOS/Windows/Linux, x64 + ARM. Studio CLI ships as `npm i -g wp-studio` with `studio site create|list|status|start|stop`, `studio blueprint list|use <slug>`, `studio preview create|list|update|delete` (previews expire after 7 days). "Studio Code" is their agentic WP builder. ([repo](https://github.com/automattic/studio), [product](https://developer.wordpress.com/studio/), [CLI docs](https://developer.wordpress.com/docs/developer-tools/studio/cli/))

### What they cannot do

No MySQL/MariaDB (SQLite driver only), no real nginx/Apache, no meaningful TLS story, no arbitrary container services (Redis, Elasticsearch, a mail catcher of your own), and object-cache/DB-level plugin behaviour will diverge from production. Playground's own docs list SQLite-only, ephemeral storage and sandbox limits explicitly. ([Playground docs](https://developer.wordpress.org/playground/))

### Copy / skip

| Copy | Skip |
| --- | --- |
| **cwd implies the project** — `cd plugins/alpaca-bot && harness up` should Just Work | Using WASM PHP + SQLite as the harness engine |
| **Blueprint JSON** as declarative provisioning, and a blueprint gallery/registry | Ephemeral-by-default storage |
| `--wp=` / `--php=` as direct, obvious flags | Playground as the *only* runtime |
| Content-addressed site state dir keyed by project path | Studio's Electron shell (but note Studio Code as the agentic competitor) |
| Keep Playground CLI available as a **throwaway fast lane** (`harness scratch`) for "does this reproduce on 6.9/PHP 8.4?" | — |

---

## 6. TLD + DNS + TLS on Linux — recommendation

### The `.local` problem is real and already present on this machine

RFC 6762 §3 reserves `.local` for link-local Multicast DNS. Appendix G is unusually blunt: "Using '.local' as a private top-level domain conflicts with Multicast DNS and may cause problems for users… **we recommend against using '.local' as a private Unicast DNS top-level domain**", and offers `.intranet`, `.internal`, `.private`, `.corp`, `.home`, `.lan` as alternatives that have been used "without the problems caused by trying to reuse '.local.'". ([RFC 6762](https://www.rfc-editor.org/rfc/rfc6762.txt))

systemd-resolved implements exactly that: "Multi-label names with the domain suffix '.local' are resolved using MulticastDNS on all local interfaces where MulticastDNS is enabled", they are **not** routed to unicast DNS servers unless explicitly configured as routing/search domains, and the man page adds "it is generally recommended to avoid defining '.local' in a DNS server, as RFC6762 reserves this domain for exclusive MulticastDNS use." ([systemd-resolved(8)](https://man7.org/linux/man-pages/man8/systemd-resolved.service.8.html))

Measured on this workstation (2026-09-05):

```
$ resolvectl query a.wp.local
a.wp.local: resolve call failed: No appropriate name servers or networks for name found

$ getent hosts a.wp.local        # rc=2, no result
$ resolvectl status | head -2
Global
         Protocols: -LLMNR -mDNS -DNSOverTLS DNSSEC=no/unsupported

$ grep ^hosts: /etc/nsswitch.conf
hosts:          files mdns4_minimal [NOTFOUND=return] dns

$ systemctl is-active avahi-daemon
active
```

Two independent blockers stack up: mDNS is *disabled* in systemd-resolved (so `.local` has no resolver at all there), and glibc NSS routes `.local` to Avahi's `mdns4_minimal` with `[NOTFOUND=return]`, which **short-circuits before `dns`** — so a `.local` name that Avahi doesn't know never reaches a unicast resolver, no matter what dnsmasq is told. `files` does precede it, so hand-written `/etc/hosts` entries would still work — but hosts files can't hold wildcards, which kills the "just make a new site" flow. **`<name>.wp.local` is the wrong base.** (DDEV hit this too: it originally used `.ddev.local` and moved to `.ddev.site`; the change request is [issue #834](https://github.com/ddev/ddev/issues/834).)

### The recommendation: `.test`, with `.localhost` as the zero-config fallback

**`*.wp.test`** — RFC 2606 reserves `.test` "for private testing of existing DNS related code… without fear of conflicts with current or future actual TLD names in the global DNS." It will never be delegated, and nothing in the OS special-cases it. ([RFC 2606](https://www.rfc-editor.org/rfc/rfc2606.txt)) Currently `x.wp.test` returns NXDOMAIN here, i.e. a clean slate.

Wire it up with a local dnsmasq plus a systemd-resolved routing domain:

```
# /etc/dnsmasq.d/wp-harness.conf
address=/wp.test/127.0.0.1
listen-address=127.0.0.1
port=5353            # stay off :53; systemd-resolved's stub owns 127.0.0.53:53

# /etc/systemd/resolved.conf.d/wp-harness.conf
[Resolve]
DNS=127.0.0.1:5353
Domains=~wp.test
```

The `~` prefix makes `wp.test` a **routing-only** domain: queries under it go to dnsmasq, everything else keeps using the normal upstream. ([resolved.conf(5)](https://man7.org/linux/man-pages/man5/resolved.conf.5.html), [systemd-resolved(8)](https://man7.org/linux/man-pages/man8/systemd-resolved.service.8.html)) One root-privileged install step, then wildcard forever — no per-site `/etc/hosts` churn, and no dependency on an internet wildcard zone like `ddev.site`/`lndo.site` (so it works offline, on a plane, behind a rebinding-protection router).

**Zero-config fallback: `*.wp.localhost`.** systemd-resolved synthesizes it with no setup at all — the man page: "The hostnames 'localhost' and 'localhost.localdomain' as well as any hostname ending in '.localhost' or '.localhost.localdomain' are resolved to the IP addresses 127.0.0.1 and ::1." Verified here:

```
$ resolvectl query foo.localhost
foo.localhost: 127.0.0.1    -- link: lo
-- Data from: synthetic
```

Chrome/Firefox also treat `*.localhost` as loopback per RFC 6761. So `harness` should support `--tld localhost` for a no-sudo first run, and `.test` as the default once the one-time install step is done. Caveat to keep in mind: `localhost` is a *secure context* in browsers regardless of TLS, so some HTTPS-only bugs will hide there — another reason `.test` is the default.

### Cert strategy

`mkcert -install` creates a local CA and installs it into the system store **and** the NSS stores used by Firefox/Chromium — which on Linux requires `certutil` (`libnss3-tools`) to be installed *first*. ([mkcert](https://github.com/FiloSottile/mkcert), [DDEV blog](https://ddev.com/blog/ddev-local-trusted-https-certificates/)) mkcert is not installed on this box yet — installing it is a prerequisite task.

Generate **one** cert covering `"*.wp.test" "wp.test"` and mount it into the router. TLS wildcards match exactly one label, so `alpaca.wp.test` is covered while `a.b.wp.test` is not — that constraint (Lando documents the same "single left-most wildcard" rule) should be baked into the naming rule: **sites are always exactly one label under the base.** Never copy `rootCA-key.pem` anywhere; mkcert's README warns it "gives complete power to intercept secure requests from your machine."

### Does this transfer to macOS?

- **`.test` + wildcard: yes.** The dnsmasq half is identical; instead of a resolved drop-in you write `/etc/resolver/test` containing `nameserver 127.0.0.1` (+ `port 5353`). Same shape, different plumbing file.
- **mkcert: yes.** It installs into the System keychain and NSS on macOS too; CAROOT defaults to `~/Library/Application Support/mkcert`.
- **`.localhost` fallback: probably not** — the synthesis above is a *systemd-resolved* feature; macOS's system resolver does not synthesize `*.localhost`, only browsers do. Treat the fallback as Linux-only until verified on a Mac. _(Low confidence — not tested; flagged rather than asserted.)_
- **`.local`: worse on macOS**, since Bonjour/mDNSResponder is core to the OS — RFC 6762 Appendix G notes the `.local` special treatment has shipped since Mac OS 9. Local's own choice of `.local` is a legacy wart, not a model.

---

## 7. MCP prior art

- **`WordPress/mcp-adapter`** is the official, canonical package: it bridges the new **Abilities API** to MCP, exposing WordPress plugin/theme/core abilities as MCP tools, resources and prompts. WP Core 6.9 lands the Abilities API. ([repo](https://github.com/WordPress/mcp-adapter), [make/ai post](https://make.wordpress.org/ai/2025/07/17/mcp-adapter/))
- **`Automattic/wordpress-mcp` is archived** (2026-01-19) and points at `mcp-adapter`. ([repo](https://github.com/Automattic/wordpress-mcp), [deprecation issue](https://github.com/Automattic/wordpress-mcp/issues/104)) The community **`mcp-wp` org was archived 2026-05-06** and is unmaintained. ([org](https://github.com/mcp-wp))
- **`docker/mcp-gateway`** — a `docker mcp` CLI plugin and gateway that runs MCP servers as isolated containers with secret injection and centralized tool discovery; GA late 2025. Interesting as a *distribution/isolation* model for shipping our server, not as a replacement for it. ([repo](https://github.com/docker/mcp-gateway), [Docker blog](https://www.docker.com/blog/mcp-toolkit-gateway-explained/))
- **Studio Code** (Automattic) is the closest competitor to the agentic angle: an AI agent that creates themes, installs plugins, edits content and runs WP-CLI against Studio sites. ([product](https://developer.wordpress.com/studio/))

**The gap this project fills.** `mcp-adapter` is an *inside-the-site* MCP — it talks about posts, blocks, abilities. Nothing established covers the *outside-the-site* surface: create/destroy/start a site, mount a plugin dir, pin PHP/WP, run wp-cli, snapshot the DB, tail logs, read the mail catcher. Those compose cleanly — our harness MCP provisions the site, and `mcp-adapter` (once installed into it) drives its content. Don't rebuild the latter.

---

## 8. Recommended shape

**Topology.** One long-lived shared *edge* stack, owned by the harness and started on demand: Traefik (:80/:443, Docker provider, watching container labels), Mailpit, and optionally a single shared MariaDB with one database per site. Each site is its own tiny compose project on a shared user network (`wp-edge`): a `wordpress:php8.3-fpm`-family container plus nginx, or a single `wordpress:apache` image for simplicity, labelled `traefik.http.routers.<site>.rule=Host("<site>.wp.test")`. This is DDEV's architecture minus the Go monolith — the router is the only thing that ever binds a privileged port, and adding a site is "write a labelled compose file and `up` it." Bind-mount plugin/theme dirs straight from `~/Projects/...` with no sync layer; on Linux that's fast enough that DDEV itself doesn't enable Mutagen. Keep the compose files **generated** (that's the whole point — the owner already did the hand-edited-compose era) but drop a `docker-compose.override.yml` escape hatch per site, DDEV-style, so the generator never becomes a cage.

**Config schema.** Two layers. A global `~/.config/wp-harness/config.json` holding `tld` (default `wp.test`), `sitesDir`, router ports, and stack defaults (`php`, `wp`, `db`). Per site, a committed `wp-site.json` next to the project:

```jsonc
{
  "$schema": "https://…/wp-site.schema.json",
  "name": "alpaca",                       // → https://alpaca.wp.test
  "hostnames": ["shop"],                  // extra one-label hosts under the base
  "php": "8.3",
  "wp": "6.8",
  "multisite": false,
  "db": { "engine": "mariadb", "version": "11.4" },
  "mounts": {                             // wp-env's `mappings`, renamed
    "wp-content/plugins/alpaca-bot": "../../plugins/alpaca-bot",
    "wp-content/themes": "./themes"
  },
  "activate": { "plugins": ["alpaca-bot"], "theme": "twentytwentyfive" },
  "constants": { "WP_DEBUG": true, "SCRIPT_DEBUG": true },
  "hooks": {                              // DDEV's events, wp-env's idempotency contract
    "postStart": ["wp plugin activate alpaca-bot"],
    "postDbImport": ["wp search-replace https://prod.example https://alpaca.wp.test"]
  },
  "xdebug": false
}
```

with an uncommitted `wp-site.local.json` that **deep-merges every key** (fixing wp-env's `plugins`-doesn't-merge trap). A `blueprints/` dir of named templates — a `wp-site.json` plus an optional seed SQL — gives us Local's Blueprints and Playground's blueprint gallery in one primitive. Everything is JSON with a published JSON Schema, so the MCP server, the CLI and the editor all validate against the same source of truth.

**Cert strategy.** One-time, guided, and explicit about its two root-requiring steps: install `libnss3-tools`, run `mkcert -install`, install the dnsmasq + `resolved.conf.d` drop-ins. Then generate a single wildcard cert for `*.wp.test` + `wp.test` into a shared volume and point Traefik's file provider at it; sites never generate certs of their own. Hostnames are therefore always exactly one label under the base (TLS wildcards match one label only). Rotation is "regenerate the one cert and restart the router." Follow DDEV's least-privilege lesson: the privileged work lives in a tiny, auditable `harness doctor --install` path, never in the daemon or the MCP server. Offer `--tld localhost` for a zero-privilege first run.

**MCP surface.** A zero-dependency TypeScript stdio server that shells out to `docker compose` and the harness CLI — same verbs, two front doors, which is Lando's `tooling:` idea taken to its conclusion. Tools: `site.list`, `site.create` (from blueprint), `site.start` / `stop` / `destroy`, `site.describe` (URL, admin URL, PHP/WP versions, container health), `site.adminLoginLink` (a mu-plugin minting one-time login URLs — Local's one-click admin, as a tool), `wp.cli` (run wp-cli, capped output), `db.snapshot` / `db.restore`, `logs.tail`, `mail.list` (Mailpit API). Ship it as a plain stdio server first; `docker/mcp-gateway` is the later distribution story if it needs sandboxing. Keep `@wp-playground/cli` wired in as `harness scratch` for throwaway "does this reproduce on WP 6.9 / PHP 8.4?" checks — it starts in seconds and costs nothing, and it is the right tool for exactly the cases where MySQL fidelity doesn't matter.

---

## Sources

**wp-env**
- https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/
- https://github.com/WordPress/gutenberg/issues/8211
- https://github.com/WordPress/gutenberg/pull/53959

**DDEV**
- https://docs.ddev.com/en/stable/users/usage/architecture/
- https://docs.ddev.com/en/stable/users/usage/networking/
- https://docs.ddev.com/en/stable/users/configuration/config/
- https://docs.ddev.com/en/stable/users/configuration/hooks/
- https://docs.ddev.com/en/stable/users/usage/commands/
- https://docs.ddev.com/en/stable/users/extend/additional-services/
- https://docs.ddev.com/en/stable/users/usage/faq/
- https://docs.ddev.com/en/stable/users/install/configuring-browsers/
- https://ddev.com/blog/ddev-name-resolution-wildcards/
- https://ddev.com/blog/ddev-local-trusted-https-certificates/
- https://ddev.com/blog/ddev-hostname-security-improvements/
- https://github.com/ddev/ddev/issues/834

**Lando**
- https://docs.lando.dev/plugins/wordpress/index.html
- https://docs.lando.dev/core/v3/proxy.html

**Local**
- https://localwp.com/features/
- https://localwp.com/help-docs/
- https://localwp.com/help-docs/advanced/ssl-in-local/
- https://localwp.com/help-docs/local-features/using-one-click-admin/

**Playground / wp-now / Studio**
- https://developer.wordpress.org/playground/
- https://make.wordpress.org/playground/2026/06/08/wp-now-is-deprecated-migrate-to-playground-cli/
- https://github.com/WordPress/playground-tools/blob/trunk/packages/wp-now/README.md
- https://github.com/automattic/studio
- https://developer.wordpress.com/studio/
- https://developer.wordpress.com/docs/developer-tools/studio/cli/

**TLD / DNS / TLS**
- https://www.rfc-editor.org/rfc/rfc6762.txt (§3 and Appendix G)
- https://www.rfc-editor.org/rfc/rfc2606.txt (§2, `.test`)
- https://datatracker.ietf.org/doc/html/rfc6762
- https://man7.org/linux/man-pages/man8/systemd-resolved.service.8.html
- https://man7.org/linux/man-pages/man5/resolved.conf.5.html
- https://github.com/FiloSottile/mkcert
- https://sixfeetup.com/blog/local-development-with-wildcard-dns-on-linux
- Local measurements on this workstation, 2026-09-05: `resolvectl query foo.localhost` / `a.wp.local` / `a.wp.test`, `resolvectl status`, `getent hosts`, `/etc/nsswitch.conf`, `systemctl is-active avahi-daemon` (avahi-daemon 0.8 active), `docker --version` (29.8.0), ports 80/443 free.

**MCP**
- https://github.com/WordPress/mcp-adapter
- https://make.wordpress.org/ai/2025/07/17/mcp-adapter/
- https://github.com/Automattic/wordpress-mcp
- https://github.com/Automattic/wordpress-mcp/issues/104
- https://github.com/mcp-wp
- https://github.com/docker/mcp-gateway
- https://www.docker.com/blog/mcp-toolkit-gateway-explained/
