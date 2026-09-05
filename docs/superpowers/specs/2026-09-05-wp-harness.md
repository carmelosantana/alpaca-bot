# WP Harness — local WordPress dev harness

Wayfinding map: Kanboard #2961 (project 136, WP Harness). Research: `docs/research/2026-09-05-harness-prior-art.md`. This spec is parked in the alpaca-bot repo until the harness repo exists; moving it (and the research file) is the harness's first commit.

## Why

The previous setup was hand-edited docker-compose files with absolute Mac paths, plaintext secrets, and port juggling; Local WP gave a good UX but too much bulk and too little control. Every WordPress project on this machine needs the same thing: a site at a trusted HTTPS name, with the right PHP and WP versions, the project's plugin/theme directories mounted, wp-cli at hand, and an agent able to do all of it on request. Alpaca Bot 1.0 is blocked on it (Kanboard #2969 blocks #2944, #2947, #2953).

## Decisions

| Decision | Choice | Ticket |
| --- | --- | --- |
| Topology | one global Traefik router on :80/:443, hostname-routed to per-site compose projects (wordpress + mariadb, optional redis/mailpit); shared phpmyadmin/mailpit on the router network | Kanboard #2962 |
| Domain | `wp.test` base; sites are exactly one label: `alpacabot.wp.test`; `.local` retired (mDNS/nsswitch + RFC 6762) | Kanboard #2963 |
| DNS | dnsmasq wildcard `*.wp.test` on Linux (systemd-resolved stub); `/etc/resolver/test` on macOS | Kanboard #2963 |
| TLS | one mkcert wildcard cert for `*.wp.test` + `wp.test` served by the router; `libnss3-tools` installed before `mkcert -install` | Kanboard #2963 |
| Site definition | `~/Sites/<name>/wp-site.json` + uncommitted `wp-site.override.json` deep-merged; compose rendered into `~/Sites/<name>/.harness/`; data volumes beside it | Kanboard #2964 |
| Tooling | one TypeScript package (pnpm, zero-dep where possible) exposing a CLI and an MCP stdio server | Kanboard #2965 |
| Safety | `destroy` and `db import` require an explicit confirm flag; everything else unguarded | Kanboard #2965 |
| SVN | break-glass helpers `svn.status/diff/commit-assets/tag` with confirm; credentials from keychain/secret file, never printed | Kanboard #2966 |
| Secrets | rotate SMTP password and WPMDB key found in the old compose files; delete those files | Kanboard #2968 |

## Changes

### Repo: `carmelosantana/wp-harness` (new)

```
package.json            bin: wph ; mcp entry
src/cli.ts              command parser (node:util parseArgs), no deps
src/mcp.ts              MCP stdio server over the same command layer
src/commands/           site.create|start|stop|destroy|list, mount, wp, logs, db.export|import, cert.install, open-admin, svn.*
src/render/             wp-site.json → compose.yml + traefik labels + wp-config extras
src/router/             global traefik compose, network, cert mount
templates/              base compose fragments per service, wp-cli container
schema/wp-site.schema.json
```

### `wp-site.json`

```json
{
  "name": "alpacabot",
  "wp": "latest",
  "php": "8.4",
  "mounts": {
    "plugins": { "alpaca-bot": "~/Projects/Alpaca Bot/wp-alpaca/plugins/alpaca-bot" },
    "themes": {},
    "mu-plugins": {}
  },
  "services": { "redis": true, "mailpit": true },
  "config": { "WP_DEBUG": true, "WP_DEBUG_LOG": true },
  "admin": { "user": "admin", "email": "me@carmelosantana.com" },
  "seed": { "blueprint": null, "sql": null },
  "env": { "OLLAMA_API_URL": "http://host.docker.internal:11434" }
}
```

### Commands (CLI verbs = MCP tools)

| Verb | Does |
| --- | --- |
| `site create <name> [--from wp-site.json]` | scaffolds `~/Sites/<name>`, renders compose, brings it up, installs WP via wp-cli, activates mounted plugins, prints the URL |
| `site start/stop/list/status` | compose lifecycle; list shows name, URL, PHP, WP, running |
| `site destroy <name> --confirm` | down -v and removes the directory |
| `mount <site> plugin|theme|mu <name> <path>` | edits wp-site.json, re-renders, restarts php |
| `wp <site> -- <args>` | wp-cli passthrough in the site's container |
| `logs <site> [service]` | tail |
| `db export <site> [file]` / `db import <site> <file> --confirm` | via wp-cli |
| `cert install` | mkcert root + wildcard, router restart |
| `open-admin <site>` | magic-login URL (wp-cli `user session` or a one-time mu-plugin) |
| `svn status/diff/commit-assets/tag <slug> --confirm` | break-glass |
| `doctor` | checks docker, ports 80/443, dnsmasq, mkcert, libnss3-tools, resolver |

### Router

`~/Sites/.router/compose.yml`: traefik with file provider for the wildcard cert, docker provider for labels, `wp-harness` external network. `doctor` verifies `resolvectl query x.wp.test` resolves to 127.0.0.1.

## Non-goals

- Replacing DDEV/Lando for non-WordPress projects.
- Windows support.
- Remote/shared environments; this is single-machine local only.
- A GUI.

## Sequencing

1. Create the repo; move this spec and the research file; `doctor` command (verifies prerequisites, installs nothing).
2. Router + cert + DNS: `cert install`, `doctor` green, `https://wp.test` answers.
3. `site create` end to end with the default template; `open-admin` works.
4. Mounts + `wp` passthrough; seed `alpacabot.wp.test` with the Alpaca working tree (Kanboard #2969).
5. MCP stdio server over the same commands; register it in Claude Code; agent creates a site from a sentence.
6. Secrets rotation and deletion of the old compose files (Kanboard #2968).
7. `svn.*` break-glass tools.
8. macOS pass: `/etc/resolver/test`, mkcert on the MacBook.
