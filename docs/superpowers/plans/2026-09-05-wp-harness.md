# WP Harness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A zero-dependency TypeScript CLI + MCP server (`wph`) that spins up trusted-HTTPS WordPress sites at `<name>.wp.test` from a `wp-site.json`, with wp-cli, mounts, magic admin login, and a first site `alpacabot.wp.test` serving the Alpaca Bot working tree.

**Architecture:** One long-lived router stack (Traefik v3 + Mailpit + phpMyAdmin) on a shared Docker network terminates TLS with a single mkcert wildcard cert and routes by hostname. Each site is its own generated compose project (`wordpress` + `mariadb` + a `cli` profile service) under `~/Sites/<name>/.harness/`, rendered from `wp-site.json` merged with an uncommitted override. The CLI and the MCP stdio server are two front doors over the same command functions; only `site destroy` and `db import` demand a confirm flag.

**Tech Stack:** Node 22.23 (native TypeScript type-stripping, `node:test`, `node:util.parseArgs`, `node:child_process`), pnpm 11, TypeScript 5.9 (typecheck only), Docker 29 + Compose v5, Traefik v3.5, `wordpress:php8.4-apache` / `wordpress:cli-php8.4`, MariaDB 11.4, mkcert 1.4, dnsmasq, systemd-resolved.

**Spec:** `docs/superpowers/specs/2026-09-05-wp-harness.md` (in the alpaca-bot repo until Task 1 moves it; afterwards `docs/superpowers/specs/2026-09-05-wp-harness.md` in `carmelosantana/wp-harness`). Research: `docs/research/2026-09-05-harness-prior-art.md`.

## Global Constraints

- Base domain is `wp.test`; a site's hostname is exactly one label under it (`alpacabot.wp.test`). Never `.local`.
- One mkcert wildcard cert for `*.wp.test` + `wp.test`, served only by the router; sites never own certs.
- Runtime dependencies: **none**. Dev dependency: `typescript` only. `.npmrc` has `ignore-scripts=true`.
- All TypeScript must be erasable syntax (no `enum`, no `namespace`, no parameter properties); relative imports carry the `.ts` extension. `tsconfig` sets `erasableSyntaxOnly: true`.
- Sites live in `~/Sites/<name>/`; global config in `~/.config/wp-harness/config.json`; router in `~/Sites/.router/`.
- Shared Docker network name: `wp-harness`. Compose project name per site: `wph-<name>`.
- `site destroy` and `db import` require `--confirm` (CLI) / `confirm: true` (MCP). Every other command is unguarded.
- Privileged steps (apt, mkcert -install, dnsmasq/resolved drop-ins) run only inside `wph dns install --yes` and `wph cert install`; they print each command before running it. `doctor` never installs anything.
- Tests run with `pnpm test` (`node --test "tests/**/*.test.ts"`); typecheck with `pnpm typecheck` (`tsc --noEmit`). Every task ends green on both.
- Commits: small, one per task step that lands code; conventional prefixes (`feat:`, `test:`, `docs:`, `chore:`).
- Kanboard is the tracker: this plan seeds one ticket on project 136 with one milestone subtask per task. Ticket ids are Kanboard tasks, never GitHub issues.

---

### Task 1: Bootstrap the `wp-harness` repo

**Files:**
- Create: `~/Projects/wp-harness/package.json`, `tsconfig.json`, `.npmrc`, `.gitignore`, `bin/wph.js`, `src/cli.ts`, `src/version.ts`, `tests/cli.test.ts`, `README.md`
- Move (from the alpaca-bot repo): `docs/superpowers/specs/2026-09-05-wp-harness.md`, `docs/research/2026-09-05-harness-prior-art.md`, `docs/superpowers/plans/2026-09-05-wp-harness.md`

**Interfaces:**
- Produces: `VERSION` constant (`src/version.ts`), `main(argv: string[]): Promise<number>` in `src/cli.ts` returning the process exit code.

- [ ] **Step 1: Create the directory and package files**

```bash
mkdir -p ~/Projects/wp-harness && cd ~/Projects/wp-harness && git init -b main
```

`package.json`:
```json
{
  "name": "wp-harness",
  "version": "0.1.0",
  "description": "Local WordPress dev harness: trusted-HTTPS sites at <name>.wp.test from a wp-site.json, with a CLI and an MCP server.",
  "type": "module",
  "private": true,
  "bin": { "wph": "./bin/wph.js" },
  "engines": { "node": ">=22.18" },
  "scripts": {
    "test": "node --test \"tests/**/*.test.ts\"",
    "typecheck": "tsc --noEmit",
    "check": "pnpm typecheck && pnpm test"
  },
  "license": "MIT"
}
```

`.npmrc`:
```
ignore-scripts=true
```

`tsconfig.json`:
```json
{
  "compilerOptions": {
    "target": "es2022",
    "module": "nodenext",
    "moduleResolution": "nodenext",
    "strict": true,
    "noEmit": true,
    "allowImportingTsExtensions": true,
    "erasableSyntaxOnly": true,
    "verbatimModuleSyntax": true,
    "types": ["node"],
    "skipLibCheck": true
  },
  "include": ["src", "tests", "bin"]
}
```

`.gitignore`:
```
node_modules/
*.log
```

- [ ] **Step 2: Add the only dev dependencies**

Run the supply-chain check first (`/powerup:supply-chain` skill), then:
```bash
pnpm add -D typescript@5.9 @types/node@22
```
Expected: `node_modules/` created, `pnpm-lock.yaml` written, no lifecycle scripts run.

- [ ] **Step 3: Write the failing CLI smoke test**

`tests/cli.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { main } from '../src/cli.ts';

test('wph --version prints the package version and exits 0', async () => {
  const out: string[] = [];
  const code = await main(['--version'], { write: (s) => out.push(s) });
  assert.equal(code, 0);
  assert.match(out.join(''), /^wph 0\.1\.0\n$/);
});

test('unknown command exits 2 with usage', async () => {
  const out: string[] = [];
  const code = await main(['bogus'], { write: (s) => out.push(s) });
  assert.equal(code, 2);
  assert.match(out.join(''), /usage: wph/);
});
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `pnpm test`
Expected: FAIL, `Cannot find module '../src/cli.ts'`.

- [ ] **Step 5: Write the minimal CLI**

`src/version.ts`:
```ts
export const VERSION = '0.1.0';
```

`src/cli.ts`:
```ts
import { VERSION } from './version.ts';

export interface Io {
  write: (s: string) => void;
}

export const USAGE = `usage: wph <command> [options]

commands:
  --version            print version
  doctor               check prerequisites
`;

export type CommandFn = (args: string[], io: Io) => Promise<number>;

export const commands: Record<string, CommandFn> = {};

export async function main(argv: string[], io: Io = { write: (s) => process.stdout.write(s) }): Promise<number> {
  const [cmd, ...rest] = argv;
  if (cmd === '--version' || cmd === '-v') {
    io.write(`wph ${VERSION}\n`);
    return 0;
  }
  const fn = cmd ? commands[cmd] : undefined;
  if (!fn) {
    io.write(USAGE);
    return 2;
  }
  return fn(rest, io);
}
```

`bin/wph.js`:
```js
#!/usr/bin/env node
import { main } from '../src/cli.ts';
process.exitCode = await main(process.argv.slice(2));
```

```bash
chmod +x bin/wph.js
```

- [ ] **Step 6: Run tests and typecheck to verify they pass**

Run: `pnpm check`
Expected: typecheck clean; 2 tests pass.

- [ ] **Step 7: Move the docs from the alpaca-bot repo**

```bash
cd ~/Projects/wp-harness && mkdir -p docs/superpowers/specs docs/superpowers/plans docs/research
A="/home/carmelo/Projects/Alpaca Bot/wp-alpaca/plugins/alpaca-bot"
cp "$A/docs/superpowers/specs/2026-09-05-wp-harness.md" docs/superpowers/specs/
cp "$A/docs/research/2026-09-05-harness-prior-art.md" docs/research/
cp "$A/docs/superpowers/plans/2026-09-05-wp-harness.md" docs/superpowers/plans/
```
Then in the alpaca-bot repo, on a branch `chore/move-harness-docs`, `git rm` the three files, replace each with a one-line pointer file of the same name containing `Moved to carmelosantana/wp-harness (same path).`, commit, push, open a PR.

- [ ] **Step 8: README and first commit**

`README.md`:
```markdown
# wp-harness

Local WordPress dev harness. `wph site create alpacabot` gives you `https://alpacabot.wp.test` with trusted TLS, wp-cli, and your plugin directories mounted. Also an MCP server so an agent can do the same.

- Spec: docs/superpowers/specs/2026-09-05-wp-harness.md
- Plan: docs/superpowers/plans/2026-09-05-wp-harness.md

## Requirements
Node >= 22.18, pnpm, Docker + Compose v2, and on Linux: mkcert, libnss3-tools, dnsmasq (installed by `wph dns install --yes`).

## Install
    pnpm install && pnpm link --global   # puts `wph` on PATH
```

```bash
git add -A && git commit -m "chore: bootstrap wp-harness CLI skeleton with docs"
gh repo create carmelosantana/wp-harness --private --source . --push
pnpm link --global && wph --version
```
Expected: `wph 0.1.0`.

---

### Task 2: Global config

**Files:**
- Create: `src/config.ts`, `tests/config.test.ts`

**Interfaces:**
- Produces:
  ```ts
  interface HarnessConfig { tld: string; sitesDir: string; routerDir: string; network: string; defaults: { php: string; wp: string; db: { engine: 'mariadb'; version: string } } }
  function defaultConfig(home?: string): HarnessConfig
  function configPath(home?: string): string        // <home>/.config/wp-harness/config.json
  function expandHome(p: string, home?: string): string
  async function loadConfig(home?: string): Promise<HarnessConfig>   // defaults deep-merged with file if present
  ```

- [ ] **Step 1: Write the failing tests**

`tests/config.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defaultConfig, configPath, expandHome, loadConfig } from '../src/config.ts';

test('defaultConfig points at ~/Sites and wp.test', () => {
  const c = defaultConfig('/home/u');
  assert.equal(c.tld, 'wp.test');
  assert.equal(c.sitesDir, '/home/u/Sites');
  assert.equal(c.routerDir, '/home/u/Sites/.router');
  assert.equal(c.network, 'wp-harness');
  assert.equal(c.defaults.php, '8.4');
  assert.equal(c.defaults.wp, 'latest');
  assert.deepEqual(c.defaults.db, { engine: 'mariadb', version: '11.4' });
});

test('expandHome replaces a leading ~', () => {
  assert.equal(expandHome('~/x/y', '/home/u'), '/home/u/x/y');
  assert.equal(expandHome('/abs', '/home/u'), '/abs');
});

test('loadConfig returns defaults when no file exists', async () => {
  const home = await mkdtemp(join(tmpdir(), 'wph-'));
  assert.deepEqual(await loadConfig(home), defaultConfig(home));
});

test('loadConfig overlays the file on defaults', async () => {
  const home = await mkdtemp(join(tmpdir(), 'wph-'));
  await mkdir(join(home, '.config/wp-harness'), { recursive: true });
  await writeFile(configPath(home), JSON.stringify({ sitesDir: '~/Dev', defaults: { php: '8.3' } }));
  const c = await loadConfig(home);
  assert.equal(c.sitesDir, join(home, 'Dev'));
  assert.equal(c.defaults.php, '8.3');
  assert.equal(c.defaults.wp, 'latest');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `pnpm test`
Expected: FAIL, cannot find `../src/config.ts`.

- [ ] **Step 3: Implement**

`src/config.ts`:
```ts
import { homedir } from 'node:os';
import { join } from 'node:path';
import { readFile } from 'node:fs/promises';

export interface HarnessConfig {
  tld: string;
  sitesDir: string;
  routerDir: string;
  network: string;
  defaults: { php: string; wp: string; db: { engine: 'mariadb'; version: string } };
}

export function expandHome(p: string, home: string = homedir()): string {
  return p === '~' ? home : p.startsWith('~/') ? join(home, p.slice(2)) : p;
}

export function defaultConfig(home: string = homedir()): HarnessConfig {
  const sitesDir = join(home, 'Sites');
  return {
    tld: 'wp.test',
    sitesDir,
    routerDir: join(sitesDir, '.router'),
    network: 'wp-harness',
    defaults: { php: '8.4', wp: 'latest', db: { engine: 'mariadb', version: '11.4' } },
  };
}

export function configPath(home: string = homedir()): string {
  return join(home, '.config', 'wp-harness', 'config.json');
}

export async function loadConfig(home: string = homedir()): Promise<HarnessConfig> {
  const base = defaultConfig(home);
  let raw: string;
  try {
    raw = await readFile(configPath(home), 'utf8');
  } catch {
    return base;
  }
  const file = JSON.parse(raw) as Partial<HarnessConfig> & { defaults?: Partial<HarnessConfig['defaults']> };
  const sitesDir = file.sitesDir ? expandHome(file.sitesDir, home) : base.sitesDir;
  return {
    tld: file.tld ?? base.tld,
    sitesDir,
    routerDir: file.routerDir ? expandHome(file.routerDir, home) : join(sitesDir, '.router'),
    network: file.network ?? base.network,
    defaults: {
      php: file.defaults?.php ?? base.defaults.php,
      wp: file.defaults?.wp ?? base.defaults.wp,
      db: { ...base.defaults.db, ...(file.defaults?.db ?? {}) },
    },
  };
}
```

- [ ] **Step 4: Verify green, commit**

Run: `pnpm check` — Expected: all pass.
```bash
git add -A && git commit -m "feat: global config with ~/.config/wp-harness/config.json overlay"
```

---

### Task 3: Site config (`wp-site.json` + override merge + validation + JSON Schema)

**Files:**
- Create: `src/site-config.ts`, `schema/wp-site.schema.json`, `tests/site-config.test.ts`

**Interfaces:**
- Consumes: `HarnessConfig` from Task 2.
- Produces:
  ```ts
  interface SiteConfig {
    name: string; php: string; wp: string;
    db: { engine: 'mariadb'; version: string };
    mounts: { plugins: Record<string, string>; themes: Record<string, string>; 'mu-plugins': Record<string, string> };
    services: { redis: boolean };
    constants: Record<string, string | number | boolean>;
    admin: { user: string; email: string };
    activate: { plugins: string[]; theme: string | null };
    env: Record<string, string>;
  }
  class SiteConfigError extends Error
  function deepMerge<T extends object>(base: T, over: unknown): T
  function validateSite(raw: unknown, cfg: HarnessConfig): SiteConfig   // fills defaults, throws SiteConfigError
  function siteDir(cfg: HarnessConfig, name: string): string            // <sitesDir>/<name>
  function harnessDir(cfg: HarnessConfig, name: string): string         // <sitesDir>/<name>/.harness
  function hostFor(cfg: HarnessConfig, name: string): string            // <name>.<tld>
  async function loadSite(cfg: HarnessConfig, name: string): Promise<SiteConfig>
  async function writeSite(cfg: HarnessConfig, site: SiteConfig): Promise<void>
  function newSite(cfg: HarnessConfig, name: string, admin?: Partial<SiteConfig['admin']>): SiteConfig
  ```

- [ ] **Step 1: Write the failing tests**

`tests/site-config.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defaultConfig } from '../src/config.ts';
import { deepMerge, validateSite, SiteConfigError, loadSite, writeSite, newSite, hostFor, siteDir } from '../src/site-config.ts';

const cfg = defaultConfig('/home/u');

test('deepMerge merges nested objects and replaces arrays', () => {
  const out = deepMerge({ a: { b: 1, c: 2 }, arr: [1], s: 'x' }, { a: { c: 3 }, arr: [2, 3] });
  assert.deepEqual(out, { a: { b: 1, c: 3 }, arr: [2, 3], s: 'x' });
});

test('validateSite fills defaults from HarnessConfig', () => {
  const s = validateSite({ name: 'alpacabot' }, cfg);
  assert.equal(s.php, '8.4');
  assert.equal(s.wp, 'latest');
  assert.deepEqual(s.mounts, { plugins: {}, themes: {}, 'mu-plugins': {} });
  assert.deepEqual(s.admin, { user: 'admin', email: 'admin@alpacabot.wp.test' });
  assert.deepEqual(s.activate, { plugins: [], theme: null });
  assert.equal(s.services.redis, false);
});

test('validateSite rejects names that are not one DNS label', () => {
  for (const bad of ['a.b', 'Has Caps', '-lead', 'x'.repeat(64), '']) {
    assert.throws(() => validateSite({ name: bad }, cfg), SiteConfigError, bad);
  }
});

test('validateSite rejects unknown php versions and bad mount paths', () => {
  assert.throws(() => validateSite({ name: 'ok', php: '7.4' }, cfg), SiteConfigError);
  assert.throws(() => validateSite({ name: 'ok', mounts: { plugins: { 'a b': '/x' } } }, cfg), SiteConfigError);
});

test('hostFor and siteDir', () => {
  assert.equal(hostFor(cfg, 'alpacabot'), 'alpacabot.wp.test');
  assert.equal(siteDir(cfg, 'alpacabot'), '/home/u/Sites/alpacabot');
});

test('loadSite deep-merges wp-site.override.json and expands ~ in mounts', async () => {
  const home = await mkdtemp(join(tmpdir(), 'wph-'));
  const c = defaultConfig(home);
  await mkdir(join(c.sitesDir, 'demo'), { recursive: true });
  await writeFile(join(c.sitesDir, 'demo', 'wp-site.json'), JSON.stringify({ name: 'demo', mounts: { plugins: { p: '~/proj/p' } }, constants: { WP_DEBUG: true } }));
  await writeFile(join(c.sitesDir, 'demo', 'wp-site.override.json'), JSON.stringify({ constants: { SCRIPT_DEBUG: true }, env: { OLLAMA_API_URL: 'http://host.docker.internal:11434' } }));
  const s = await loadSite(c, 'demo');
  assert.equal(s.mounts.plugins.p, join(home, 'proj/p'));
  assert.deepEqual(s.constants, { WP_DEBUG: true, SCRIPT_DEBUG: true });
  assert.equal(s.env.OLLAMA_API_URL, 'http://host.docker.internal:11434');
});

test('writeSite round-trips through loadSite', async () => {
  const home = await mkdtemp(join(tmpdir(), 'wph-'));
  const c = defaultConfig(home);
  const s = newSite(c, 'rt', { email: 'me@example.test' });
  await writeSite(c, s);
  assert.deepEqual(await loadSite(c, 'rt'), s);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `pnpm test` — Expected: FAIL, cannot find `../src/site-config.ts`.

- [ ] **Step 3: Implement**

`src/site-config.ts`:
```ts
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { expandHome, type HarnessConfig } from './config.ts';

export interface SiteConfig {
  name: string;
  php: string;
  wp: string;
  db: { engine: 'mariadb'; version: string };
  mounts: { plugins: Record<string, string>; themes: Record<string, string>; 'mu-plugins': Record<string, string> };
  services: { redis: boolean };
  constants: Record<string, string | number | boolean>;
  admin: { user: string; email: string };
  activate: { plugins: string[]; theme: string | null };
  env: Record<string, string>;
}

export class SiteConfigError extends Error {}

const LABEL = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;
const PHP_VERSIONS = new Set(['8.1', '8.2', '8.3', '8.4', '8.5']);
const SLUG = /^[a-z0-9][a-z0-9._-]*$/;

function isObject(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

export function deepMerge<T extends object>(base: T, over: unknown): T {
  if (!isObject(over)) return base;
  const out: Record<string, unknown> = { ...(base as Record<string, unknown>) };
  for (const [k, v] of Object.entries(over)) {
    const b = out[k];
    out[k] = isObject(b) && isObject(v) ? deepMerge(b, v) : v;
  }
  return out as T;
}

export function hostFor(cfg: HarnessConfig, name: string): string {
  return `${name}.${cfg.tld}`;
}
export function siteDir(cfg: HarnessConfig, name: string): string {
  return join(cfg.sitesDir, name);
}
export function harnessDir(cfg: HarnessConfig, name: string): string {
  return join(siteDir(cfg, name), '.harness');
}

function mountMap(raw: unknown, kind: string, home?: string): Record<string, string> {
  if (raw === undefined) return {};
  if (!isObject(raw)) throw new SiteConfigError(`mounts.${kind} must be an object`);
  const out: Record<string, string> = {};
  for (const [slug, path] of Object.entries(raw)) {
    if (!SLUG.test(slug)) throw new SiteConfigError(`mounts.${kind}: bad slug "${slug}"`);
    if (typeof path !== 'string' || path.length === 0) throw new SiteConfigError(`mounts.${kind}.${slug}: path must be a string`);
    out[slug] = expandHome(path, home);
  }
  return out;
}

export function validateSite(raw: unknown, cfg: HarnessConfig, home?: string): SiteConfig {
  if (!isObject(raw)) throw new SiteConfigError('wp-site.json must be an object');
  const name = raw.name;
  if (typeof name !== 'string' || !LABEL.test(name)) throw new SiteConfigError(`name must be one lowercase DNS label, got ${JSON.stringify(name)}`);
  const php = typeof raw.php === 'string' ? raw.php : cfg.defaults.php;
  if (!PHP_VERSIONS.has(php)) throw new SiteConfigError(`php must be one of ${[...PHP_VERSIONS].join(', ')}`);
  const wp = typeof raw.wp === 'string' ? raw.wp : cfg.defaults.wp;
  const dbRaw = isObject(raw.db) ? raw.db : {};
  const mounts = isObject(raw.mounts) ? raw.mounts : {};
  const services = isObject(raw.services) ? raw.services : {};
  const admin = isObject(raw.admin) ? raw.admin : {};
  const activate = isObject(raw.activate) ? raw.activate : {};
  const constants = isObject(raw.constants) ? raw.constants : {};
  for (const [k, v] of Object.entries(constants)) {
    if (!/^[A-Z][A-Z0-9_]*$/.test(k) || !['string', 'number', 'boolean'].includes(typeof v)) throw new SiteConfigError(`constants.${k}: must be an UPPER_CASE key with a string, number, or boolean value`);
  }
  const env = isObject(raw.env) ? raw.env : {};
  for (const [k, v] of Object.entries(env)) {
    if (typeof v !== 'string') throw new SiteConfigError(`env.${k} must be a string`);
  }
  return {
    name,
    php,
    wp,
    db: { engine: 'mariadb', version: typeof dbRaw.version === 'string' ? dbRaw.version : cfg.defaults.db.version },
    mounts: {
      plugins: mountMap(mounts.plugins, 'plugins', home),
      themes: mountMap(mounts.themes, 'themes', home),
      'mu-plugins': mountMap(mounts['mu-plugins'], 'mu-plugins', home),
    },
    services: { redis: services.redis === true },
    constants: constants as SiteConfig['constants'],
    admin: {
      user: typeof admin.user === 'string' ? admin.user : 'admin',
      email: typeof admin.email === 'string' ? admin.email : `admin@${hostFor(cfg, name)}`,
    },
    activate: {
      plugins: Array.isArray(activate.plugins) ? activate.plugins.filter((p): p is string => typeof p === 'string') : [],
      theme: typeof activate.theme === 'string' ? activate.theme : null,
    },
    env: env as Record<string, string>,
  };
}

export function newSite(cfg: HarnessConfig, name: string, admin: Partial<SiteConfig['admin']> = {}): SiteConfig {
  return validateSite({ name, admin }, cfg);
}

async function readJsonIfExists(path: string): Promise<unknown> {
  try {
    return JSON.parse(await readFile(path, 'utf8'));
  } catch (e) {
    if ((e as NodeJS.ErrnoException).code === 'ENOENT') return undefined;
    throw new SiteConfigError(`${path}: ${(e as Error).message}`);
  }
}

export async function loadSite(cfg: HarnessConfig, name: string, home?: string): Promise<SiteConfig> {
  const dir = siteDir(cfg, name);
  const base = await readJsonIfExists(join(dir, 'wp-site.json'));
  if (base === undefined) throw new SiteConfigError(`no wp-site.json in ${dir}`);
  const over = await readJsonIfExists(join(dir, 'wp-site.override.json'));
  const merged = over === undefined ? base : deepMerge(base as object, over);
  return validateSite(merged, cfg, home);
}

export async function writeSite(cfg: HarnessConfig, site: SiteConfig): Promise<void> {
  const dir = siteDir(cfg, site.name);
  await mkdir(dir, { recursive: true });
  await writeFile(join(dir, 'wp-site.json'), JSON.stringify(site, null, 2) + '\n');
}
```

`schema/wp-site.schema.json` (documentation for editors; the TS validator is the enforcer):
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://github.com/carmelosantana/wp-harness/schema/wp-site.schema.json",
  "title": "wp-site.json",
  "type": "object",
  "required": ["name"],
  "properties": {
    "name": { "type": "string", "pattern": "^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$", "description": "One DNS label; the site is https://<name>.wp.test" },
    "php": { "type": "string", "enum": ["8.1", "8.2", "8.3", "8.4", "8.5"], "default": "8.4" },
    "wp": { "type": "string", "default": "latest", "description": "latest or a WordPress version like 6.8.1" },
    "db": { "type": "object", "properties": { "engine": { "const": "mariadb" }, "version": { "type": "string", "default": "11.4" } } },
    "mounts": {
      "type": "object",
      "properties": {
        "plugins": { "type": "object", "additionalProperties": { "type": "string" } },
        "themes": { "type": "object", "additionalProperties": { "type": "string" } },
        "mu-plugins": { "type": "object", "additionalProperties": { "type": "string" } }
      }
    },
    "services": { "type": "object", "properties": { "redis": { "type": "boolean", "default": false } } },
    "constants": { "type": "object", "additionalProperties": { "type": ["string", "number", "boolean"] } },
    "admin": { "type": "object", "properties": { "user": { "type": "string", "default": "admin" }, "email": { "type": "string" } } },
    "activate": { "type": "object", "properties": { "plugins": { "type": "array", "items": { "type": "string" } }, "theme": { "type": ["string", "null"] } } },
    "env": { "type": "object", "additionalProperties": { "type": "string" } }
  }
}
```

- [ ] **Step 4: Verify green, commit**

Run: `pnpm check` — Expected: all pass.
```bash
git add -A && git commit -m "feat: wp-site.json loader with override merge, validation, and JSON schema"
```

---

### Task 4: Site compose renderer

**Files:**
- Create: `src/render/compose.ts`, `tests/render-compose.test.ts`

**Interfaces:**
- Consumes: `SiteConfig`, `HarnessConfig`, `hostFor`.
- Produces:
  ```ts
  function wordpressImage(site: SiteConfig): string      // wordpress:php8.4-apache | wordpress:6.8.1-php8.4-apache
  function cliImage(site: SiteConfig): string            // wordpress:cli-php8.4 | wordpress:cli-6.8.1-php8.4
  function renderConfigExtra(constants: SiteConfig['constants']): string   // PHP define() lines
  function renderSiteCompose(site: SiteConfig, cfg: HarnessConfig): string // YAML text
  const MAGIC_LOGIN_MOUNT = '/var/www/html/wp-content/mu-plugins/wph-magic-login.php'
  ```

- [ ] **Step 1: Write the failing tests**

`tests/render-compose.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { defaultConfig } from '../src/config.ts';
import { validateSite } from '../src/site-config.ts';
import { wordpressImage, cliImage, renderConfigExtra, renderSiteCompose } from '../src/render/compose.ts';

const cfg = defaultConfig('/home/u');

test('image tags follow the docker-library naming', () => {
  assert.equal(wordpressImage(validateSite({ name: 'a' }, cfg)), 'wordpress:php8.4-apache');
  assert.equal(wordpressImage(validateSite({ name: 'a', wp: '6.8.1', php: '8.3' }, cfg)), 'wordpress:6.8.1-php8.3-apache');
  assert.equal(cliImage(validateSite({ name: 'a' }, cfg)), 'wordpress:cli-php8.4');
  assert.equal(cliImage(validateSite({ name: 'a', wp: '6.8.1' }, cfg)), 'wordpress:cli-6.8.1-php8.4');
});

test('renderConfigExtra emits typed define() lines', () => {
  assert.equal(
    renderConfigExtra({ WP_DEBUG: true, WP_MEMORY_LIMIT: '256M', WP_POST_REVISIONS: 5 }),
    "define('WP_DEBUG', true);\ndefine('WP_MEMORY_LIMIT', '256M');\ndefine('WP_POST_REVISIONS', 5);\n",
  );
});

test('renderSiteCompose wires router labels, mounts, env, cli profile, and the shared network', () => {
  const site = validateSite({
    name: 'alpacabot',
    mounts: { plugins: { 'alpaca-bot': '/home/u/Projects/alpaca-bot' } },
    services: { redis: true },
    env: { OLLAMA_API_URL: 'http://host.docker.internal:11434' },
    constants: { WP_DEBUG: true },
  }, cfg);
  const y = renderSiteCompose(site, cfg);
  assert.match(y, /^name: wph-alpacabot$/m);
  assert.match(y, /image: wordpress:php8\.4-apache/);
  assert.match(y, /traefik\.http\.routers\.wph-alpacabot\.rule=Host\(`alpacabot\.wp\.test`\)/);
  assert.match(y, /traefik\.http\.routers\.wph-alpacabot\.tls=true/);
  assert.match(y, /traefik\.docker\.network=wp-harness/);
  assert.match(y, /- \/home\/u\/Projects\/alpaca-bot:\/var\/www\/html\/wp-content\/plugins\/alpaca-bot/);
  assert.match(y, /\/home\/u\/Sites\/\.router\/mu-plugins\/wph-magic-login\.php:\/var\/www\/html\/wp-content\/mu-plugins\/wph-magic-login\.php:ro/);
  assert.match(y, /OLLAMA_API_URL: "http:\/\/host\.docker\.internal:11434"/);
  assert.match(y, /define\('WP_DEBUG', true\);/);
  assert.match(y, /image: mariadb:11\.4/);
  assert.match(y, /image: redis:7-alpine/);
  assert.match(y, /profiles: \[cli\]/);
  assert.match(y, /host\.docker\.internal:host-gateway/);
  assert.match(y, /wp-harness:\n    external: true/);
  assert.match(y, /WORDPRESS_SMTP_HOST: mailpit/);
});

test('renderSiteCompose omits redis when disabled', () => {
  const y = renderSiteCompose(validateSite({ name: 'plain' }, cfg), cfg);
  assert.doesNotMatch(y, /redis/);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `pnpm test` — Expected: FAIL, cannot find `../src/render/compose.ts`.

- [ ] **Step 3: Implement**

`src/render/compose.ts`:
```ts
import { join } from 'node:path';
import type { HarnessConfig } from '../config.ts';
import { hostFor, type SiteConfig } from '../site-config.ts';

export const MAGIC_LOGIN_MOUNT = '/var/www/html/wp-content/mu-plugins/wph-magic-login.php';
const DB = { name: 'wordpress', user: 'wordpress', password: 'wordpress' };

export function wordpressImage(site: SiteConfig): string {
  return site.wp === 'latest' ? `wordpress:php${site.php}-apache` : `wordpress:${site.wp}-php${site.php}-apache`;
}
export function cliImage(site: SiteConfig): string {
  return site.wp === 'latest' ? `wordpress:cli-php${site.php}` : `wordpress:cli-${site.wp}-php${site.php}`;
}

function phpLiteral(v: string | number | boolean): string {
  return typeof v === 'string' ? `'${v.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'` : String(v);
}

export function renderConfigExtra(constants: SiteConfig['constants']): string {
  return Object.entries(constants).map(([k, v]) => `define('${k}', ${phpLiteral(v)});\n`).join('');
}

function yamlStr(s: string): string {
  return JSON.stringify(s);
}

function mountLines(site: SiteConfig, cfg: HarnessConfig): string[] {
  const lines: string[] = ['      - wp:/var/www/html'];
  for (const [kind, map] of Object.entries(site.mounts) as [keyof SiteConfig['mounts'], Record<string, string>][]) {
    for (const [slug, path] of Object.entries(map)) lines.push(`      - ${path}:/var/www/html/wp-content/${kind}/${slug}`);
  }
  lines.push(`      - ${join(cfg.routerDir, 'mu-plugins', 'wph-magic-login.php')}:${MAGIC_LOGIN_MOUNT}:ro`);
  return lines;
}

function envLines(site: SiteConfig): string[] {
  const base: Record<string, string> = {
    WORDPRESS_DB_HOST: 'db',
    WORDPRESS_DB_USER: DB.user,
    WORDPRESS_DB_PASSWORD: DB.password,
    WORDPRESS_DB_NAME: DB.name,
    WORDPRESS_SMTP_HOST: 'mailpit',
    WORDPRESS_SMTP_PORT: '1025',
    ...site.env,
  };
  const lines = Object.entries(base).map(([k, v]) => `      ${k}: ${yamlStr(v)}`);
  const extra = renderConfigExtra(site.constants);
  if (extra) lines.push('      WORDPRESS_CONFIG_EXTRA: |', ...extra.trimEnd().split('\n').map((l) => `        ${l}`));
  return lines;
}

export function renderSiteCompose(site: SiteConfig, cfg: HarnessConfig): string {
  const project = `wph-${site.name}`;
  const host = hostFor(cfg, site.name);
  const mounts = mountLines(site, cfg).join('\n');
  const env = envLines(site).join('\n');
  const redis = site.services.redis
    ? `
  redis:
    image: redis:7-alpine
    restart: unless-stopped
`
    : '';
  return `# Generated by wph from wp-site.json. Do not edit; edit wp-site.json and run: wph site start ${site.name}
name: ${project}
services:
  wordpress:
    image: ${wordpressImage(site)}
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
${env}
    volumes:
${mounts}
    networks: [default, wp-harness]
    extra_hosts: ["host.docker.internal:host-gateway"]
    labels:
      - traefik.enable=true
      - traefik.docker.network=${cfg.network}
      - traefik.http.routers.${project}.rule=Host(\`${host}\`)
      - traefik.http.routers.${project}.entrypoints=websecure
      - traefik.http.routers.${project}.tls=true
      - traefik.http.services.${project}.loadbalancer.server.port=80
  db:
    image: mariadb:${site.db.version}
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: ${DB.name}
      MARIADB_USER: ${DB.user}
      MARIADB_PASSWORD: ${DB.password}
      MARIADB_ROOT_PASSWORD: root
    volumes:
      - db:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 20
${redis}  cli:
    image: ${cliImage(site)}
    profiles: [cli]
    user: "33:33"
    depends_on:
      db:
        condition: service_healthy
    environment:
${env}
    volumes:
${mounts}
    networks: [default, wp-harness]
    extra_hosts: ["host.docker.internal:host-gateway"]
volumes:
  wp: {}
  db: {}
networks:
  wp-harness:
    external: true
`;
}
```

- [ ] **Step 4: Verify green, commit**

Run: `pnpm check` — Expected: all pass.
```bash
git add -A && git commit -m "feat: render a per-site compose project with router labels and cli profile"
```

---

### Task 5: Process runner, router renderer, and `wph router up|down|status`

**Files:**
- Create: `src/exec.ts`, `src/render/router.ts`, `src/commands/router.ts`, `templates/mu-plugins/wph-magic-login.php` (placeholder content written in Task 9; here it is the real file so the router dir is complete), `tests/exec.test.ts`, `tests/render-router.test.ts`
- Modify: `src/cli.ts` (register `router`)

**Interfaces:**
- Produces:
  ```ts
  // src/exec.ts
  interface RunResult { code: number; stdout: string; stderr: string }
  interface RunOpts { cwd?: string; input?: string; inherit?: boolean; env?: Record<string, string> }
  async function run(cmd: string, args: string[], opts?: RunOpts): Promise<RunResult>
  async function compose(dir: string, args: string[], opts?: RunOpts): Promise<RunResult>   // docker compose in dir
  async function which(cmd: string): Promise<string | null>
  // src/render/router.ts
  function renderRouterCompose(cfg: HarnessConfig): string
  function renderTlsDynamic(): string
  // src/commands/router.ts
  async function ensureNetwork(cfg: HarnessConfig): Promise<void>
  async function writeRouterFiles(cfg: HarnessConfig): Promise<void>   // compose.yml, dynamic/tls.yml, mu-plugins/, certs/ dir
  async function routerUp(cfg: HarnessConfig): Promise<RunResult>
  async function routerDown(cfg: HarnessConfig): Promise<RunResult>
  async function routerStatus(cfg: HarnessConfig): Promise<{ running: boolean; certPresent: boolean }>
  ```

- [ ] **Step 1: Write the failing tests**

`tests/exec.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { run, which } from '../src/exec.ts';

test('run captures stdout, stderr, and exit code', async () => {
  const r = await run('sh', ['-c', 'echo out; echo err 1>&2; exit 3']);
  assert.equal(r.code, 3);
  assert.equal(r.stdout, 'out\n');
  assert.equal(r.stderr, 'err\n');
});

test('run passes stdin when input is given', async () => {
  const r = await run('cat', [], { input: 'hello' });
  assert.equal(r.stdout, 'hello');
});

test('which finds sh and misses a nonsense binary', async () => {
  assert.ok(await which('sh'));
  assert.equal(await which('definitely-not-a-binary-xyz'), null);
});
```

`tests/render-router.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { defaultConfig } from '../src/config.ts';
import { renderRouterCompose, renderTlsDynamic } from '../src/render/router.ts';

test('router compose binds 80/443, uses the docker provider on wp-harness, and exposes mail/pma/router hosts', () => {
  const y = renderRouterCompose(defaultConfig('/home/u'));
  assert.match(y, /image: traefik:v3\.5/);
  assert.match(y, /container_name: wp-harness-router/);
  assert.match(y, /--providers\.docker\.exposedbydefault=false/);
  assert.match(y, /--providers\.docker\.network=wp-harness/);
  assert.match(y, /--providers\.file\.directory=\/etc\/traefik\/dynamic/);
  assert.match(y, /"80:80"/);
  assert.match(y, /"443:443"/);
  assert.match(y, /Host\(`router\.wp\.test`\)/);
  assert.match(y, /Host\(`mail\.wp\.test`\)/);
  assert.match(y, /Host\(`pma\.wp\.test`\)/);
  assert.match(y, /PMA_ARBITRARY: "1"/);
  assert.match(y, /wp-harness:\n    external: true/);
});

test('tls dynamic config names the wildcard files', () => {
  const y = renderTlsDynamic();
  assert.match(y, /certFile: \/etc\/traefik\/certs\/wildcard\.pem/);
  assert.match(y, /keyFile: \/etc\/traefik\/certs\/wildcard-key\.pem/);
  assert.match(y, /defaultCertificate:/);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `pnpm test` — Expected: FAIL on missing modules.

- [ ] **Step 3: Implement `src/exec.ts`**

```ts
import { spawn } from 'node:child_process';

export interface RunResult { code: number; stdout: string; stderr: string }
export interface RunOpts { cwd?: string; input?: string; inherit?: boolean; env?: Record<string, string> }

export function run(cmd: string, args: string[], opts: RunOpts = {}): Promise<RunResult> {
  return new Promise((resolve, reject) => {
    const child = spawn(cmd, args, {
      cwd: opts.cwd,
      env: { ...process.env, ...(opts.env ?? {}) },
      stdio: [opts.input === undefined ? (opts.inherit ? 'inherit' : 'ignore') : 'pipe', opts.inherit ? 'inherit' : 'pipe', opts.inherit ? 'inherit' : 'pipe'],
    });
    let stdout = '';
    let stderr = '';
    child.stdout?.on('data', (d: Buffer) => { stdout += d.toString(); });
    child.stderr?.on('data', (d: Buffer) => { stderr += d.toString(); });
    child.on('error', reject);
    child.on('close', (code) => resolve({ code: code ?? 1, stdout, stderr }));
    if (opts.input !== undefined) child.stdin?.end(opts.input);
  });
}

export function compose(dir: string, args: string[], opts: RunOpts = {}): Promise<RunResult> {
  return run('docker', ['compose', ...args], { ...opts, cwd: dir });
}

export async function which(cmd: string): Promise<string | null> {
  const r = await run('sh', ['-c', `command -v ${JSON.stringify(cmd)}`]);
  return r.code === 0 ? r.stdout.trim() : null;
}
```

- [ ] **Step 4: Implement `src/render/router.ts`**

```ts
import type { HarnessConfig } from '../config.ts';

export function renderRouterCompose(cfg: HarnessConfig): string {
  const t = cfg.tld;
  return `# Generated by wph. Do not edit; run: wph router up
name: wph-router
services:
  traefik:
    image: traefik:v3.5
    container_name: wp-harness-router
    restart: unless-stopped
    command:
      - --providers.docker=true
      - --providers.docker.exposedbydefault=false
      - --providers.docker.network=${cfg.network}
      - --providers.file.directory=/etc/traefik/dynamic
      - --providers.file.watch=true
      - --entrypoints.web.address=:80
      - --entrypoints.web.http.redirections.entrypoint.to=websecure
      - --entrypoints.web.http.redirections.entrypoint.scheme=https
      - --entrypoints.websecure.address=:443
      - --api.dashboard=true
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./certs:/etc/traefik/certs:ro
      - ./dynamic:/etc/traefik/dynamic:ro
    networks: [wp-harness]
    labels:
      - traefik.enable=true
      - traefik.http.routers.wph-dashboard.rule=Host(\`router.${t}\`)
      - traefik.http.routers.wph-dashboard.entrypoints=websecure
      - traefik.http.routers.wph-dashboard.tls=true
      - traefik.http.routers.wph-dashboard.service=api@internal
  mailpit:
    image: axllent/mailpit:latest
    container_name: wp-harness-mailpit
    restart: unless-stopped
    networks: [wp-harness]
    labels:
      - traefik.enable=true
      - traefik.http.routers.wph-mail.rule=Host(\`mail.${t}\`)
      - traefik.http.routers.wph-mail.entrypoints=websecure
      - traefik.http.routers.wph-mail.tls=true
      - traefik.http.services.wph-mail.loadbalancer.server.port=8025
  phpmyadmin:
    image: phpmyadmin:latest
    container_name: wp-harness-pma
    restart: unless-stopped
    environment:
      PMA_ARBITRARY: "1"
    networks: [wp-harness]
    labels:
      - traefik.enable=true
      - traefik.http.routers.wph-pma.rule=Host(\`pma.${t}\`)
      - traefik.http.routers.wph-pma.entrypoints=websecure
      - traefik.http.routers.wph-pma.tls=true
      - traefik.http.services.wph-pma.loadbalancer.server.port=80
networks:
  wp-harness:
    external: true
`;
}

export function renderTlsDynamic(): string {
  return `tls:
  certificates:
    - certFile: /etc/traefik/certs/wildcard.pem
      keyFile: /etc/traefik/certs/wildcard-key.pem
  stores:
    default:
      defaultCertificate:
        certFile: /etc/traefik/certs/wildcard.pem
        keyFile: /etc/traefik/certs/wildcard-key.pem
`;
}
```

Note for the phpMyAdmin server field: sites' DB hosts are reachable from the router network as `wph-<name>-db-1`, user `wordpress` / `wordpress`.

- [ ] **Step 5: Implement `src/commands/router.ts` and register the CLI command**

`src/commands/router.ts`:
```ts
import { access, copyFile, mkdir, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { HarnessConfig } from '../config.ts';
import { compose, run, type RunResult } from '../exec.ts';
import { renderRouterCompose, renderTlsDynamic } from '../render/router.ts';

const TEMPLATES = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'templates');

async function exists(p: string): Promise<boolean> {
  try { await access(p); return true; } catch { return false; }
}

export async function ensureNetwork(cfg: HarnessConfig): Promise<void> {
  const r = await run('docker', ['network', 'inspect', cfg.network]);
  if (r.code !== 0) await run('docker', ['network', 'create', cfg.network]);
}

export async function writeRouterFiles(cfg: HarnessConfig): Promise<void> {
  await mkdir(join(cfg.routerDir, 'certs'), { recursive: true });
  await mkdir(join(cfg.routerDir, 'dynamic'), { recursive: true });
  await mkdir(join(cfg.routerDir, 'mu-plugins'), { recursive: true });
  await writeFile(join(cfg.routerDir, 'compose.yml'), renderRouterCompose(cfg));
  await writeFile(join(cfg.routerDir, 'dynamic', 'tls.yml'), renderTlsDynamic());
  await copyFile(join(TEMPLATES, 'mu-plugins', 'wph-magic-login.php'), join(cfg.routerDir, 'mu-plugins', 'wph-magic-login.php'));
}

export async function routerUp(cfg: HarnessConfig): Promise<RunResult> {
  await ensureNetwork(cfg);
  await writeRouterFiles(cfg);
  return compose(cfg.routerDir, ['up', '-d']);
}

export async function routerDown(cfg: HarnessConfig): Promise<RunResult> {
  return compose(cfg.routerDir, ['down']);
}

export async function routerStatus(cfg: HarnessConfig): Promise<{ running: boolean; certPresent: boolean }> {
  const r = await run('docker', ['inspect', '-f', '{{.State.Running}}', 'wp-harness-router']);
  return { running: r.code === 0 && r.stdout.trim() === 'true', certPresent: await exists(join(cfg.routerDir, 'certs', 'wildcard.pem')) };
}
```

`templates/mu-plugins/wph-magic-login.php` (final content; Task 9 tests it):
```php
<?php
/**
 * Plugin Name: WP Harness magic login
 * Description: One-time login links minted by `wph open-admin`. Only ever mounted into wp-harness dev sites.
 */
add_action('init', function (): void {
    if (empty($_GET['wph_login'])) {
        return;
    }
    $token = sanitize_text_field(wp_unslash($_GET['wph_login']));
    if (!preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
        wp_die('Invalid login link.');
    }
    $user_id = get_transient('wph_login_' . $token);
    delete_transient('wph_login_' . $token);
    if (!$user_id) {
        wp_die('Invalid or expired login link.');
    }
    wp_set_auth_cookie((int) $user_id, true);
    wp_safe_redirect(admin_url());
    exit;
});
```

Register in `src/cli.ts`: add to `commands`:
```ts
import { loadConfig } from './config.ts';
import { routerUp, routerDown, routerStatus } from './commands/router.ts';

commands.router = async (args, io) => {
  const cfg = await loadConfig();
  const sub = args[0];
  if (sub === 'up') { const r = await routerUp(cfg); io.write(r.code === 0 ? `router up: https://router.${cfg.tld} https://mail.${cfg.tld} https://pma.${cfg.tld}\n` : r.stderr); return r.code; }
  if (sub === 'down') { const r = await routerDown(cfg); io.write(r.code === 0 ? 'router down\n' : r.stderr); return r.code; }
  if (sub === 'status') { const s = await routerStatus(cfg); io.write(`running: ${s.running}\ncert: ${s.certPresent ? 'present' : 'missing (run: wph cert install)'}\n`); return 0; }
  io.write('usage: wph router up|down|status\n');
  return 2;
};
```
and add `router up|down|status` to `USAGE`.

- [ ] **Step 6: Verify green, then smoke the router for real**

Run: `pnpm check` — Expected: all pass.
Run: `wph router up && wph router status && docker ps --filter name=wp-harness`
Expected: three containers running (`wp-harness-router`, `wp-harness-mailpit`, `wp-harness-pma`); `cert: missing` (expected until Task 7); `curl -k https://localhost -H 'Host: router.wp.test' -o /dev/null -w '%{http_code}\n'` prints `200` or `401`.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: process runner, router stack renderer, and wph router up/down/status"
```

---

### Task 6: `wph doctor`

**Files:**
- Create: `src/commands/doctor.ts`, `tests/doctor.test.ts`
- Modify: `src/cli.ts`

**Interfaces:**
- Produces:
  ```ts
  interface Check { name: string; ok: boolean; detail: string; fix?: string }
  interface DoctorDeps { which: (c: string) => Promise<string | null>; run: typeof run; exists: (p: string) => Promise<boolean> }
  async function doctor(cfg: HarnessConfig, deps?: DoctorDeps): Promise<Check[]>
  function formatChecks(checks: Check[]): string
  ```

- [ ] **Step 1: Write the failing tests**

`tests/doctor.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { defaultConfig } from '../src/config.ts';
import { doctor, formatChecks } from '../src/commands/doctor.ts';

const cfg = defaultConfig('/home/u');

test('doctor reports every prerequisite with a fix hint when missing', async () => {
  const checks = await doctor(cfg, {
    which: async (c) => (c === 'docker' ? '/usr/bin/docker' : null),
    run: async (cmd, args) => {
      const key = [cmd, ...args].join(' ');
      if (key.startsWith('docker compose version')) return { code: 0, stdout: 'Docker Compose version v5.5.1\n', stderr: '' };
      if (key.startsWith('docker network inspect')) return { code: 1, stdout: '', stderr: 'not found' };
      if (key.startsWith('resolvectl query')) return { code: 1, stdout: '', stderr: 'No appropriate name servers' };
      if (key.startsWith('ss ')) return { code: 0, stdout: '', stderr: '' };
      return { code: 1, stdout: '', stderr: '' };
    },
    exists: async () => false,
  });
  const byName = Object.fromEntries(checks.map((c) => [c.name, c]));
  assert.equal(byName.docker.ok, true);
  assert.equal(byName.compose.ok, true);
  assert.equal(byName.mkcert.ok, false);
  assert.match(byName.mkcert.fix ?? '', /apt install mkcert libnss3-tools/);
  assert.equal(byName.dnsmasq.ok, false);
  assert.equal(byName.dns.ok, false);
  assert.match(byName.dns.fix ?? '', /wph dns install --yes/);
  assert.equal(byName.network.ok, false);
  assert.equal(byName.cert.ok, false);
  assert.match(byName.cert.fix ?? '', /wph cert install/);
  assert.equal(byName.ports.ok, true);
  assert.equal(byName.router.ok, false);
});

test('formatChecks renders one line per check', () => {
  const s = formatChecks([{ name: 'docker', ok: true, detail: '/usr/bin/docker' }, { name: 'mkcert', ok: false, detail: 'missing', fix: 'sudo apt install mkcert' }]);
  assert.equal(s, 'ok    docker   /usr/bin/docker\nFAIL  mkcert   missing\n        fix: sudo apt install mkcert\n');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `pnpm test` — Expected: FAIL on missing module.

- [ ] **Step 3: Implement**

`src/commands/doctor.ts`:
```ts
import { access } from 'node:fs/promises';
import { join } from 'node:path';
import type { HarnessConfig } from '../config.ts';
import { run as realRun, which as realWhich } from '../exec.ts';

export interface Check { name: string; ok: boolean; detail: string; fix?: string }
export interface DoctorDeps {
  which: (c: string) => Promise<string | null>;
  run: typeof realRun;
  exists: (p: string) => Promise<boolean>;
}

const realDeps: DoctorDeps = {
  which: realWhich,
  run: realRun,
  exists: async (p) => { try { await access(p); return true; } catch { return false; } },
};

export async function doctor(cfg: HarnessConfig, deps: DoctorDeps = realDeps): Promise<Check[]> {
  const checks: Check[] = [];
  const bin = async (name: string, fix: string) => {
    const p = await deps.which(name);
    checks.push({ name, ok: p !== null, detail: p ?? 'missing', fix: p ? undefined : fix });
  };
  await bin('docker', 'install Docker Engine: https://docs.docker.com/engine/install/');
  const compose = await deps.run('docker', ['compose', 'version']);
  checks.push({ name: 'compose', ok: compose.code === 0, detail: compose.stdout.trim() || 'missing', fix: compose.code === 0 ? undefined : 'install the docker-compose-plugin package' });
  await bin('mkcert', 'sudo apt install mkcert libnss3-tools');
  await bin('certutil', 'sudo apt install libnss3-tools   # Firefox/Chromium trust store');
  await bin('dnsmasq', 'wph dns install --yes   # installs dnsmasq and the resolver drop-ins');
  const q = await deps.run('resolvectl', ['query', `doctor-probe.${cfg.tld}`]);
  const resolves = q.code === 0 && /127\.0\.0\.1/.test(q.stdout);
  checks.push({ name: 'dns', ok: resolves, detail: resolves ? `*.${cfg.tld} -> 127.0.0.1` : `*.${cfg.tld} does not resolve`, fix: resolves ? undefined : 'wph dns install --yes' });
  const ports = await deps.run('ss', ['-ltnH', 'sport = :80', 'or', 'sport = :443']);
  const busy = ports.stdout.trim().split('\n').filter(Boolean).filter((l) => !/wp-harness/.test(l));
  const routerHolds = /docker/.test(ports.stdout) || busy.length === 0;
  checks.push({ name: 'ports', ok: routerHolds, detail: busy.length === 0 ? '80/443 free or held by the router' : busy.join(' | '), fix: routerHolds ? undefined : 'stop whatever holds :80/:443 (e.g. a system nginx/apache)' });
  const net = await deps.run('docker', ['network', 'inspect', cfg.network]);
  checks.push({ name: 'network', ok: net.code === 0, detail: net.code === 0 ? cfg.network : `${cfg.network} missing`, fix: net.code === 0 ? undefined : 'wph router up' });
  const cert = await deps.exists(join(cfg.routerDir, 'certs', 'wildcard.pem'));
  checks.push({ name: 'cert', ok: cert, detail: cert ? join(cfg.routerDir, 'certs', 'wildcard.pem') : 'wildcard cert missing', fix: cert ? undefined : 'wph cert install' });
  const router = await deps.run('docker', ['inspect', '-f', '{{.State.Running}}', 'wp-harness-router']);
  const up = router.code === 0 && router.stdout.trim() === 'true';
  checks.push({ name: 'router', ok: up, detail: up ? 'wp-harness-router running' : 'router not running', fix: up ? undefined : 'wph router up' });
  return checks;
}

export function formatChecks(checks: Check[]): string {
  const w = Math.max(...checks.map((c) => c.name.length));
  return checks.map((c) => `${c.ok ? 'ok  ' : 'FAIL'}  ${c.name.padEnd(w)}   ${c.detail}\n${c.fix ? `        fix: ${c.fix}\n` : ''}`).join('');
}
```

Register in `src/cli.ts`:
```ts
import { doctor, formatChecks } from './commands/doctor.ts';
commands.doctor = async (_args, io) => {
  const checks = await doctor(await loadConfig());
  io.write(formatChecks(checks));
  return checks.every((c) => c.ok) ? 0 : 1;
};
```

- [ ] **Step 4: Verify green, run it for real, commit**

Run: `pnpm check` — Expected: pass.
Run: `wph doctor` — Expected on this box today: docker/compose/ports/network/router ok; mkcert, certutil, dnsmasq, dns, cert FAIL with fix lines. Exit code 1.
```bash
git add -A && git commit -m "feat: wph doctor checks docker, compose, mkcert, dns, ports, network, cert, router"
```

---

### Task 7: `wph cert install` and `wph dns install --yes`

**Files:**
- Create: `src/commands/cert.ts`, `src/commands/dns.ts`, `tests/dns.test.ts`
- Modify: `src/cli.ts`

**Interfaces:**
- Produces:
  ```ts
  // cert.ts
  async function certInstall(cfg: HarnessConfig, deps?: { run: typeof run; write: (s: string) => void }): Promise<number>
  // dns.ts
  function dnsmasqConf(tld: string): string
  function resolvedDropIn(): string
  function dnsInstallPlan(tld: string): { path: string; content: string }[] & { commands: string[][] }
  async function dnsInstall(cfg: HarnessConfig, opts: { yes: boolean }, deps?: { run: typeof run; write: (s: string) => void }): Promise<number>
  ```

- [ ] **Step 1: Write the failing tests**

`tests/dns.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dnsmasqConf, resolvedDropIn, dnsInstallPlan, dnsInstall } from '../src/commands/dns.ts';
import { defaultConfig } from '../src/config.ts';

test('dnsmasq config answers the wildcard on 127.0.0.2 without touching the stub resolver', () => {
  const c = dnsmasqConf('wp.test');
  assert.match(c, /^address=\/wp\.test\/127\.0\.0\.1$/m);
  assert.match(c, /^listen-address=127\.0\.0\.2$/m);
  assert.match(c, /^bind-interfaces$/m);
  assert.match(c, /^no-resolv$/m);
});

test('resolved drop-in routes only the tld to dnsmasq', () => {
  const d = resolvedDropIn('wp.test');
  assert.match(d, /^\[Resolve\]$/m);
  assert.match(d, /^DNS=127\.0\.0\.2$/m);
  assert.match(d, /^Domains=~wp\.test$/m);
});

test('the install plan writes the two files then installs and restarts', () => {
  const plan = dnsInstallPlan('wp.test');
  assert.deepEqual(plan.files.map((f) => f.path), ['/etc/dnsmasq.d/wp-harness.conf', '/etc/systemd/resolved.conf.d/wp-harness.conf']);
  assert.deepEqual(plan.commands, [
    ['sudo', 'apt-get', 'install', '-y', 'dnsmasq', 'mkcert', 'libnss3-tools'],
    ['sudo', 'systemctl', 'enable', '--now', 'dnsmasq'],
    ['sudo', 'systemctl', 'restart', 'dnsmasq'],
    ['sudo', 'systemctl', 'restart', 'systemd-resolved'],
  ]);
});

test('dnsInstall without --yes prints the plan and runs nothing', async () => {
  const ran: string[] = [];
  const out: string[] = [];
  const code = await dnsInstall(defaultConfig('/home/u'), { yes: false }, { run: async (c, a) => { ran.push([c, ...a].join(' ')); return { code: 0, stdout: '', stderr: '' }; }, write: (s) => out.push(s) });
  assert.equal(code, 0);
  assert.deepEqual(ran, []);
  assert.match(out.join(''), /sudo apt-get install -y dnsmasq mkcert libnss3-tools/);
  assert.match(out.join(''), /re-run with --yes/);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `pnpm test` — Expected: FAIL on missing module.

- [ ] **Step 3: Implement `src/commands/dns.ts`**

```ts
import type { HarnessConfig } from '../config.ts';
import { run as realRun } from '../exec.ts';

export function dnsmasqConf(tld: string): string {
  return `# wp-harness: wildcard *.${tld} -> 127.0.0.1. systemd-resolved forwards only ~${tld} here.
listen-address=127.0.0.2
bind-interfaces
no-resolv
address=/${tld}/127.0.0.1
`;
}

export function resolvedDropIn(tld: string): string {
  return `# wp-harness: route *.${tld} to the local dnsmasq on 127.0.0.2
[Resolve]
DNS=127.0.0.2
Domains=~${tld}
`;
}

export interface DnsPlan { files: { path: string; content: string }[]; commands: string[][] }

export function dnsInstallPlan(tld: string): DnsPlan {
  return {
    files: [
      { path: '/etc/dnsmasq.d/wp-harness.conf', content: dnsmasqConf(tld) },
      { path: '/etc/systemd/resolved.conf.d/wp-harness.conf', content: resolvedDropIn(tld) },
    ],
    commands: [
      ['sudo', 'apt-get', 'install', '-y', 'dnsmasq', 'mkcert', 'libnss3-tools'],
      ['sudo', 'systemctl', 'enable', '--now', 'dnsmasq'],
      ['sudo', 'systemctl', 'restart', 'dnsmasq'],
      ['sudo', 'systemctl', 'restart', 'systemd-resolved'],
    ],
  };
}

export async function dnsInstall(
  cfg: HarnessConfig,
  opts: { yes: boolean },
  deps: { run: typeof realRun; write: (s: string) => void } = { run: realRun, write: (s) => process.stdout.write(s) },
): Promise<number> {
  const plan = dnsInstallPlan(cfg.tld);
  deps.write('wph dns install will run, in order:\n');
  for (const f of plan.files) deps.write(`  write ${f.path} (via sudo tee)\n`);
  for (const c of plan.commands) deps.write(`  ${c.join(' ')}\n`);
  if (!opts.yes) {
    deps.write('nothing executed; re-run with --yes to apply.\n');
    return 0;
  }
  for (const f of plan.files) {
    const dir = f.path.slice(0, f.path.lastIndexOf('/'));
    let r = await deps.run('sudo', ['mkdir', '-p', dir], { inherit: false });
    if (r.code !== 0) { deps.write(r.stderr); return r.code; }
    r = await deps.run('sudo', ['tee', f.path], { input: f.content });
    if (r.code !== 0) { deps.write(r.stderr); return r.code; }
    deps.write(`wrote ${f.path}\n`);
  }
  for (const [cmd, ...args] of plan.commands) {
    deps.write(`$ ${cmd} ${args.join(' ')}\n`);
    const r = await deps.run(cmd, args, { inherit: true });
    if (r.code !== 0) return r.code;
  }
  const q = await deps.run('resolvectl', ['query', `probe.${cfg.tld}`]);
  const ok = q.code === 0 && /127\.0\.0\.1/.test(q.stdout);
  deps.write(ok ? `probe.${cfg.tld} -> 127.0.0.1\n` : `probe.${cfg.tld} still does not resolve:\n${q.stdout}${q.stderr}`);
  return ok ? 0 : 1;
}
```

Why the file is written before `apt-get install`: the Ubuntu `dnsmasq` package starts the service on install; with `listen-address=127.0.0.2` + `bind-interfaces` already in place it does not collide with systemd-resolved's stub on 127.0.0.53.

- [ ] **Step 4: Implement `src/commands/cert.ts`**

```ts
import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import type { HarnessConfig } from '../config.ts';
import { run as realRun } from '../exec.ts';

export async function certInstall(
  cfg: HarnessConfig,
  deps: { run: typeof realRun; write: (s: string) => void } = { run: realRun, write: (s) => process.stdout.write(s) },
): Promise<number> {
  const certs = join(cfg.routerDir, 'certs');
  await mkdir(certs, { recursive: true });
  deps.write('$ mkcert -install   (installs the mkcert root CA into the system and NSS trust stores; may ask for sudo)\n');
  let r = await deps.run('mkcert', ['-install'], { inherit: true });
  if (r.code !== 0) return r.code;
  const args = ['-cert-file', join(certs, 'wildcard.pem'), '-key-file', join(certs, 'wildcard-key.pem'), `*.${cfg.tld}`, cfg.tld];
  deps.write(`$ mkcert ${args.join(' ')}\n`);
  r = await deps.run('mkcert', args, { inherit: true });
  if (r.code !== 0) return r.code;
  const restart = await deps.run('docker', ['restart', 'wp-harness-router']);
  deps.write(restart.code === 0 ? 'router restarted with the new certificate\n' : 'router not running yet; it will pick the cert up on: wph router up\n');
  return 0;
}
```

Register both in `src/cli.ts`:
```ts
import { certInstall } from './commands/cert.ts';
import { dnsInstall } from './commands/dns.ts';
commands.cert = async (args, io) => (args[0] === 'install' ? certInstall(await loadConfig(), { run, write: io.write }) : (io.write('usage: wph cert install\n'), 2));
commands.dns = async (args, io) => (args[0] === 'install' ? dnsInstall(await loadConfig(), { yes: args.includes('--yes') }, { run, write: io.write }) : (io.write('usage: wph dns install [--yes]\n'), 2));
```
(add `import { run } from './exec.ts';` and the two lines to `USAGE`).

- [ ] **Step 5: Verify green, then apply for real on this machine**

Run: `pnpm check` — Expected: pass.
Run: `wph dns install` — Expected: prints the plan, executes nothing.
Run: `wph dns install --yes` — Expected: prompts for sudo once, writes both files, installs packages, ends with `probe.wp.test -> 127.0.0.1`.
Run: `wph cert install` — Expected: mkcert root installed (Firefox/Chromium NSS included because certutil is present), `~/Sites/.router/certs/wildcard.pem` + `wildcard-key.pem` written, router restarted.
Run: `wph doctor` — Expected: every line `ok`, exit 0.
Run: `curl -s -o /dev/null -w '%{http_code} %{ssl_verify_result}\n' https://mail.wp.test` — Expected: `200 0` (trusted TLS, no `-k`).

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: wph dns install (dnsmasq + resolved drop-ins) and wph cert install (mkcert wildcard)"
```

---

### Task 8: `wph site create|start|stop|status|list|destroy`

**Files:**
- Create: `src/commands/site.ts`, `tests/site.test.ts`
- Modify: `src/cli.ts`

**Interfaces:**
- Consumes: `renderSiteCompose`, `loadSite`, `writeSite`, `newSite`, `harnessDir`, `hostFor`, `compose`, `run`, `routerStatus`.
- Produces:
  ```ts
  interface SiteStatus { name: string; url: string; php: string; wp: string; running: boolean; installed: boolean }
  interface SiteDeps { compose: typeof compose; run: typeof run; sleep: (ms: number) => Promise<void>; write: (s: string) => void }
  async function renderSite(cfg, name): Promise<string>                  // writes .harness/compose.yml, returns path
  async function wp(cfg, name, args: string[], deps?): Promise<RunResult> // docker compose run --rm cli wp <args>
  async function siteStart(cfg, name, deps?): Promise<number>
  async function siteStop(cfg, name, deps?): Promise<number>
  async function siteStatus(cfg, name, deps?): Promise<SiteStatus>
  async function siteList(cfg, deps?): Promise<SiteStatus[]>
  async function siteCreate(cfg, name, opts: { from?: string; email?: string }, deps?): Promise<number>
  async function siteDestroy(cfg, name, opts: { confirm: boolean }, deps?): Promise<number>
  function generatePassword(): string   // 24 chars [A-Za-z0-9]
  ```

- [ ] **Step 1: Write the failing tests**

`tests/site.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defaultConfig } from '../src/config.ts';
import { generatePassword, renderSite, siteCreate, siteDestroy, siteStatus, type SiteDeps } from '../src/commands/site.ts';

function fakeDeps(log: string[], answers: Record<string, { code: number; stdout: string }> = {}): SiteDeps {
  const ok = { code: 0, stdout: '', stderr: '' };
  const lookup = (key: string) => {
    const hit = Object.keys(answers).find((k) => key.startsWith(k));
    return hit ? { ...ok, ...answers[hit] } : ok;
  };
  return {
    compose: async (_dir, args) => { const k = `compose ${args.join(' ')}`; log.push(k); return lookup(k); },
    run: async (cmd, args) => { const k = `${cmd} ${args.join(' ')}`; log.push(k); return lookup(k); },
    sleep: async () => {},
    write: (s) => log.push(`out: ${s.trimEnd()}`),
  };
}

test('generatePassword is 24 alphanumerics', () => {
  assert.match(generatePassword(), /^[A-Za-z0-9]{24}$/);
});

test('renderSite writes .harness/compose.yml from wp-site.json', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  const log: string[] = [];
  await siteCreate(cfg, 'demo', {}, fakeDeps(log, { 'compose run --rm cli wp core is-installed': { code: 1, stdout: '' } }));
  const y = await readFile(join(cfg.sitesDir, 'demo', '.harness', 'compose.yml'), 'utf8');
  assert.match(y, /Host\(`demo\.wp\.test`\)/);
  assert.equal(await renderSite(cfg, 'demo'), join(cfg.sitesDir, 'demo', '.harness', 'compose.yml'));
});

test('siteCreate brings the stack up, waits for wp-config, installs core, activates, and prints the password', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  const log: string[] = [];
  const code = await siteCreate(cfg, 'demo', { email: 'me@example.test' }, fakeDeps(log, { 'compose run --rm cli wp core is-installed': { code: 1, stdout: '' } }));
  assert.equal(code, 0);
  assert.ok(log.includes('compose up -d'));
  assert.ok(log.some((l) => l.startsWith('compose exec -T wordpress test -f /var/www/html/wp-config.php')));
  const install = log.find((l) => l.startsWith('compose run --rm cli wp core install'));
  assert.ok(install);
  assert.match(install, /--url=https:\/\/demo\.wp\.test/);
  assert.match(install, /--admin_user=admin/);
  assert.match(install, /--admin_email=me@example\.test/);
  assert.match(install, /--skip-email/);
  const pw = await readFile(join(cfg.sitesDir, 'demo', '.harness', 'admin-password'), 'utf8');
  assert.match(pw, /^[A-Za-z0-9]{24}\n$/);
  assert.ok(log.some((l) => l.startsWith('out: https://demo.wp.test')));
});

test('siteCreate refuses an existing site', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  const log: string[] = [];
  assert.equal(await siteCreate(cfg, 'demo', {}, fakeDeps(log, { 'compose run --rm cli wp core is-installed': { code: 1, stdout: '' } })), 0);
  assert.equal(await siteCreate(cfg, 'demo', {}, fakeDeps(log)), 1);
});

test('siteDestroy needs confirm, then downs with volumes and removes the dir', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  const log: string[] = [];
  await siteCreate(cfg, 'demo', {}, fakeDeps(log, { 'compose run --rm cli wp core is-installed': { code: 1, stdout: '' } }));
  assert.equal(await siteDestroy(cfg, 'demo', { confirm: false }, fakeDeps(log)), 2);
  assert.ok(!log.includes('compose down -v'));
  assert.equal(await siteDestroy(cfg, 'demo', { confirm: true }, fakeDeps(log)), 0);
  assert.ok(log.includes('compose down -v --remove-orphans'));
  await assert.rejects(readFile(join(cfg.sitesDir, 'demo', 'wp-site.json')));
});

test('siteStatus reports running and installed', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  const log: string[] = [];
  await siteCreate(cfg, 'demo', {}, fakeDeps(log, { 'compose run --rm cli wp core is-installed': { code: 1, stdout: '' } }));
  const s = await siteStatus(cfg, 'demo', fakeDeps(log, { 'compose ps': { code: 0, stdout: '{"Service":"wordpress","State":"running"}\n' } }));
  assert.deepEqual(s, { name: 'demo', url: 'https://demo.wp.test', php: '8.4', wp: 'latest', running: true, installed: true });
});
```

- [ ] **Step 2: Run to verify failure**

Run: `pnpm test` — Expected: FAIL on missing module.

- [ ] **Step 3: Implement**

`src/commands/site.ts`:
```ts
import { randomBytes } from 'node:crypto';
import { access, mkdir, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { HarnessConfig } from '../config.ts';
import { compose as realCompose, run as realRun, type RunResult } from '../exec.ts';
import { renderSiteCompose } from '../render/compose.ts';
import { harnessDir, hostFor, loadSite, newSite, siteDir, validateSite, writeSite, type SiteConfig } from '../site-config.ts';

export interface SiteStatus { name: string; url: string; php: string; wp: string; running: boolean; installed: boolean }
export interface SiteDeps {
  compose: typeof realCompose;
  run: typeof realRun;
  sleep: (ms: number) => Promise<void>;
  write: (s: string) => void;
}
const realDeps: SiteDeps = {
  compose: realCompose,
  run: realRun,
  sleep: (ms) => new Promise((r) => setTimeout(r, ms)),
  write: (s) => process.stdout.write(s),
};

const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
export function generatePassword(): string {
  return Array.from(randomBytes(24), (b) => ALNUM[b % ALNUM.length]).join('');
}

async function exists(p: string): Promise<boolean> {
  try { await access(p); return true; } catch { return false; }
}

export async function renderSite(cfg: HarnessConfig, name: string): Promise<string> {
  const site = await loadSite(cfg, name);
  const dir = harnessDir(cfg, name);
  await mkdir(dir, { recursive: true });
  const path = join(dir, 'compose.yml');
  await writeFile(path, renderSiteCompose(site, cfg));
  return path;
}

export async function wp(cfg: HarnessConfig, name: string, args: string[], deps: SiteDeps = realDeps): Promise<RunResult> {
  return deps.compose(harnessDir(cfg, name), ['run', '--rm', 'cli', 'wp', ...args]);
}

async function waitForWpConfig(cfg: HarnessConfig, name: string, deps: SiteDeps): Promise<boolean> {
  for (let i = 0; i < 60; i++) {
    const r = await deps.compose(harnessDir(cfg, name), ['exec', '-T', 'wordpress', 'test', '-f', '/var/www/html/wp-config.php']);
    if (r.code === 0) return true;
    await deps.sleep(2000);
  }
  return false;
}

export async function siteStart(cfg: HarnessConfig, name: string, deps: SiteDeps = realDeps): Promise<number> {
  await renderSite(cfg, name);
  const r = await deps.compose(harnessDir(cfg, name), ['up', '-d']);
  if (r.code !== 0) { deps.write(r.stderr); return r.code; }
  deps.write(`https://${hostFor(cfg, name)}\n`);
  return 0;
}

export async function siteStop(cfg: HarnessConfig, name: string, deps: SiteDeps = realDeps): Promise<number> {
  const r = await deps.compose(harnessDir(cfg, name), ['stop']);
  if (r.code !== 0) deps.write(r.stderr);
  return r.code;
}

export async function siteStatus(cfg: HarnessConfig, name: string, deps: SiteDeps = realDeps): Promise<SiteStatus> {
  const site = await loadSite(cfg, name);
  const ps = await deps.compose(harnessDir(cfg, name), ['ps', '--format', 'json']);
  const running = ps.stdout.split('\n').filter(Boolean).some((l) => { try { const j = JSON.parse(l); return j.Service === 'wordpress' && j.State === 'running'; } catch { return false; } });
  const installed = running ? (await wp(cfg, name, ['core', 'is-installed'], deps)).code === 0 : false;
  return { name, url: `https://${hostFor(cfg, name)}`, php: site.php, wp: site.wp, running, installed };
}

export async function siteList(cfg: HarnessConfig, deps: SiteDeps = realDeps): Promise<SiteStatus[]> {
  let names: string[] = [];
  try { names = (await readdir(cfg.sitesDir)).filter((n) => !n.startsWith('.')); } catch { return []; }
  const out: SiteStatus[] = [];
  for (const n of names) if (await exists(join(cfg.sitesDir, n, 'wp-site.json'))) out.push(await siteStatus(cfg, n, deps));
  return out;
}

export async function siteCreate(cfg: HarnessConfig, name: string, opts: { from?: string; email?: string }, deps: SiteDeps = realDeps): Promise<number> {
  if (await exists(join(siteDir(cfg, name), 'wp-site.json'))) { deps.write(`site ${name} already exists at ${siteDir(cfg, name)}\n`); return 1; }
  const site: SiteConfig = opts.from
    ? validateSite({ ...JSON.parse(await readFile(opts.from, 'utf8')), name }, cfg)
    : newSite(cfg, name, opts.email ? { email: opts.email } : {});
  await writeSite(cfg, site);
  const code = await siteStart(cfg, name, deps);
  if (code !== 0) return code;
  if (!(await waitForWpConfig(cfg, name, deps))) { deps.write('timed out waiting for wp-config.php; check: wph logs ' + name + '\n'); return 1; }
  if ((await wp(cfg, name, ['core', 'is-installed'], deps)).code !== 0) {
    const password = generatePassword();
    const r = await wp(cfg, name, ['core', 'install', `--url=https://${hostFor(cfg, name)}`, `--title=${name}`, `--admin_user=${site.admin.user}`, `--admin_password=${password}`, `--admin_email=${site.admin.email}`, '--skip-email'], deps);
    if (r.code !== 0) { deps.write(r.stdout + r.stderr); return r.code; }
    await writeFile(join(harnessDir(cfg, name), 'admin-password'), password + '\n', { mode: 0o600 });
  }
  const plugins = [...new Set([...Object.keys(site.mounts.plugins), ...site.activate.plugins])];
  if (plugins.length) await wp(cfg, name, ['plugin', 'activate', ...plugins], deps);
  if (site.activate.theme) await wp(cfg, name, ['theme', 'activate', site.activate.theme], deps);
  const pw = (await readFile(join(harnessDir(cfg, name), 'admin-password'), 'utf8')).trim();
  deps.write(`https://${hostFor(cfg, name)}/wp-admin  user: ${site.admin.user}  password: ${pw}\n`);
  return 0;
}

export async function siteDestroy(cfg: HarnessConfig, name: string, opts: { confirm: boolean }, deps: SiteDeps = realDeps): Promise<number> {
  if (!opts.confirm) { deps.write(`refusing to destroy ${name}: this deletes containers, volumes, and ${siteDir(cfg, name)}. Re-run with --confirm.\n`); return 2; }
  if (await exists(join(harnessDir(cfg, name), 'compose.yml'))) await deps.compose(harnessDir(cfg, name), ['down', '-v', '--remove-orphans']);
  await rm(siteDir(cfg, name), { recursive: true, force: true });
  deps.write(`destroyed ${name}\n`);
  return 0;
}
```

Register in `src/cli.ts`:
```ts
import { parseArgs } from 'node:util';
import { siteCreate, siteDestroy, siteList, siteStart, siteStatus, siteStop } from './commands/site.ts';
commands.site = async (args, io) => {
  const cfg = await loadConfig();
  const { values, positionals } = parseArgs({ args, allowPositionals: true, options: { from: { type: 'string' }, email: { type: 'string' }, confirm: { type: 'boolean', default: false } } });
  const [sub, name] = positionals;
  const deps = { compose, run, sleep: (ms: number) => new Promise<void>((r) => setTimeout(r, ms)), write: io.write };
  if (sub === 'list') { for (const s of await siteList(cfg, deps)) io.write(`${s.running ? 'up  ' : 'down'}  ${s.name.padEnd(16)} ${s.url}  php ${s.php}  wp ${s.wp}${s.installed ? '' : '  (not installed)'}\n`); return 0; }
  if (!name) { io.write('usage: wph site create|start|stop|status|destroy <name> [--from file] [--email addr] [--confirm]\n'); return 2; }
  if (sub === 'create') return siteCreate(cfg, name, { from: values.from, email: values.email }, deps);
  if (sub === 'start') return siteStart(cfg, name, deps);
  if (sub === 'stop') return siteStop(cfg, name, deps);
  if (sub === 'status') { const s = await siteStatus(cfg, name, deps); io.write(JSON.stringify(s, null, 2) + '\n'); return 0; }
  if (sub === 'destroy') return siteDestroy(cfg, name, { confirm: values.confirm ?? false }, deps);
  io.write('usage: wph site create|start|stop|status|destroy <name>\n');
  return 2;
};
```
(add `import { compose, run } from './exec.ts';` and the usage lines.)

- [ ] **Step 4: Verify green, then create a real throwaway site**

Run: `pnpm check` — Expected: pass.
Run: `wph site create scratch` — Expected: images pull, DB healthy, WP installed, prints `https://scratch.wp.test/wp-admin  user: admin  password: …`.
Run: `curl -s -o /dev/null -w '%{http_code}\n' https://scratch.wp.test/` — Expected: `200`.
Run: `wph site list` — Expected: `up    scratch   https://scratch.wp.test  php 8.4  wp latest`.
Run: `wph site destroy scratch` — Expected: refusal, exit 2. Then `wph site destroy scratch --confirm` — Expected: `destroyed scratch`; `docker volume ls | grep wph-scratch` prints nothing.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: wph site create/start/stop/status/list/destroy"
```

---

### Task 9: `wph open-admin` (magic login)

**Files:**
- Create: `src/commands/open-admin.ts`, `tests/open-admin.test.ts`
- Modify: `src/cli.ts`

**Interfaces:**
- Consumes: `wp()` from Task 8, the mu-plugin from Task 5.
- Produces: `async function openAdmin(cfg, name, opts: { open: boolean }, deps?: SiteDeps): Promise<number>` and `function loginUrl(host: string, token: string): string`.

- [ ] **Step 1: Write the failing test**

`tests/open-admin.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defaultConfig } from '../src/config.ts';
import { writeSite, newSite } from '../src/site-config.ts';
import { loginUrl, openAdmin } from '../src/commands/open-admin.ts';

test('loginUrl', () => {
  assert.equal(loginUrl('demo.wp.test', 'abc'), 'https://demo.wp.test/?wph_login=abc');
});

test('openAdmin mints a transient for the admin user via wp eval and prints the URL', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  await writeSite(cfg, newSite(cfg, 'demo'));
  const log: string[] = [];
  const code = await openAdmin(cfg, 'demo', { open: false }, {
    compose: async (_d, args) => { log.push(args.join(' ')); return args.includes('user') ? { code: 0, stdout: '1\n', stderr: '' } : { code: 0, stdout: 'TOKEN0123456789abcdefTOKEN0123456\n', stderr: '' }; },
    run: async () => ({ code: 0, stdout: '', stderr: '' }),
    sleep: async () => {},
    write: (s) => log.push(`out: ${s}`),
  });
  assert.equal(code, 0);
  assert.ok(log.some((l) => l.startsWith('run --rm cli wp user get admin --field=ID')));
  assert.ok(log.some((l) => l.includes('wp eval') && l.includes('set_transient') && l.includes("'wph_login_'")));
  assert.ok(log.some((l) => l.startsWith('out: https://demo.wp.test/?wph_login=')));
});
```

- [ ] **Step 2: Run to verify failure** — `pnpm test` FAILs on missing module.

- [ ] **Step 3: Implement**

`src/commands/open-admin.ts`:
```ts
import type { HarnessConfig } from '../config.ts';
import { hostFor, loadSite } from '../site-config.ts';
import { wp, type SiteDeps } from './site.ts';

export function loginUrl(host: string, token: string): string {
  return `https://${host}/?wph_login=${token}`;
}

const MINT = "$t = wp_generate_password(32, false); set_transient('wph_login_' . $t, (int) $argv[0] ?? 0, 300); echo $t;";

export async function openAdmin(cfg: HarnessConfig, name: string, opts: { open: boolean }, deps?: SiteDeps): Promise<number> {
  const site = await loadSite(cfg, name);
  const id = await wp(cfg, name, ['user', 'get', site.admin.user, '--field=ID'], deps);
  if (id.code !== 0) { (deps?.write ?? process.stdout.write.bind(process.stdout))(id.stdout + id.stderr); return id.code; }
  const uid = id.stdout.trim();
  const mint = await wp(cfg, name, ['eval', MINT.replace('$argv[0] ?? 0', uid)], deps);
  if (mint.code !== 0) { (deps?.write ?? process.stdout.write.bind(process.stdout))(mint.stdout + mint.stderr); return mint.code; }
  const url = loginUrl(hostFor(cfg, name), mint.stdout.trim());
  (deps?.write ?? process.stdout.write.bind(process.stdout))(url + '\n');
  if (opts.open) await (deps?.run ?? (await import('../exec.ts')).run)('xdg-open', [url]);
  return 0;
}
```

Register: `commands['open-admin'] = async (args, io) => (args[0] ? openAdmin(await loadConfig(), args[0], { open: !args.includes('--print') }, { compose, run, sleep: (ms) => new Promise((r) => setTimeout(r, ms)), write: io.write }) : (io.write('usage: wph open-admin <site> [--print]\n'), 2));`

- [ ] **Step 4: Verify green and for real**

Run: `pnpm check` — pass.
Run: `wph site create scratch && wph open-admin scratch --print` — Expected: a URL; `curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' "<that url>"` prints `302 https://scratch.wp.test/wp-admin/`; a second curl of the same URL returns `500`-page text `Invalid or expired login link.` (token is single-use). `wph site destroy scratch --confirm`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: wph open-admin mints one-time login links via the harness mu-plugin"
```

---

### Task 10: `wph mount`, `wph wp`, `wph logs`

**Files:**
- Create: `src/commands/mount.ts`, `tests/mount.test.ts`
- Modify: `src/cli.ts`

**Interfaces:**
- Produces: `async function mount(cfg, name, kind: 'plugin'|'theme'|'mu', slug: string, path: string, deps?: SiteDeps): Promise<number>` (edits `wp-site.json`, re-renders, `compose up -d`, activates plugin), plus CLI passthroughs `wph wp <site> -- <args>` (uses `wp()` with `inherit`-style output) and `wph logs <site> [service] [--tail N]`.

- [ ] **Step 1: Write the failing test**

`tests/mount.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defaultConfig } from '../src/config.ts';
import { writeSite, newSite, loadSite } from '../src/site-config.ts';
import { mount } from '../src/commands/mount.ts';

test('mount adds the path to wp-site.json, re-renders, restarts, and activates plugins', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  await writeSite(cfg, newSite(cfg, 'demo'));
  const log: string[] = [];
  const deps = { compose: async (_d: string, a: string[]) => { log.push(a.join(' ')); return { code: 0, stdout: '', stderr: '' }; }, run: async () => ({ code: 0, stdout: '', stderr: '' }), sleep: async () => {}, write: () => {} };
  assert.equal(await mount(cfg, 'demo', 'plugin', 'alpaca-bot', '/home/u/p/alpaca-bot', deps), 0);
  const site = await loadSite(cfg, 'demo');
  assert.equal(site.mounts.plugins['alpaca-bot'], '/home/u/p/alpaca-bot');
  const y = await readFile(join(cfg.sitesDir, 'demo', '.harness', 'compose.yml'), 'utf8');
  assert.match(y, /\/home\/u\/p\/alpaca-bot:\/var\/www\/html\/wp-content\/plugins\/alpaca-bot/);
  assert.ok(log.includes('up -d'));
  assert.ok(log.includes('run --rm cli wp plugin activate alpaca-bot'));
});

test('mount rejects a bad kind', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  await writeSite(cfg, newSite(cfg, 'demo'));
  // @ts-expect-error bad kind on purpose
  assert.equal(await mount(cfg, 'demo', 'widget', 'x', '/x'), 2);
});
```

- [ ] **Step 2: Run to verify failure** — `pnpm test` FAILs on missing module.

- [ ] **Step 3: Implement**

`src/commands/mount.ts`:
```ts
import { resolve } from 'node:path';
import type { HarnessConfig } from '../config.ts';
import { loadSite, writeSite } from '../site-config.ts';
import { siteStart, wp, type SiteDeps } from './site.ts';

const KINDS = { plugin: 'plugins', theme: 'themes', mu: 'mu-plugins' } as const;
export type MountKind = keyof typeof KINDS;

export async function mount(cfg: HarnessConfig, name: string, kind: MountKind, slug: string, path: string, deps?: SiteDeps): Promise<number> {
  const key = KINDS[kind];
  if (!key) { (deps?.write ?? process.stdout.write.bind(process.stdout))(`kind must be plugin|theme|mu, got ${String(kind)}\n`); return 2; }
  const site = await loadSite(cfg, name);
  site.mounts[key][slug] = resolve(path);
  await writeSite(cfg, site);
  const code = await siteStart(cfg, name, deps);
  if (code !== 0) return code;
  if (kind === 'plugin') await wp(cfg, name, ['plugin', 'activate', slug], deps);
  if (kind === 'theme') await wp(cfg, name, ['theme', 'activate', slug], deps);
  return 0;
}
```

Register in `src/cli.ts`:
```ts
import { mount, type MountKind } from './commands/mount.ts';
import { harnessDir } from './site-config.ts';
commands.mount = async (args, io) => { const [site, kind, slug, path] = args; if (!path) { io.write('usage: wph mount <site> plugin|theme|mu <slug> <path>\n'); return 2; } return mount(await loadConfig(), site, kind as MountKind, slug, path, deps(io)); };
commands.wp = async (args, io) => { const [site, ...rest] = args; if (!site) { io.write('usage: wph wp <site> -- <wp-cli args>\n'); return 2; } const cfg = await loadConfig(); const a = rest[0] === '--' ? rest.slice(1) : rest; return (await compose(harnessDir(cfg, site), ['run', '--rm', 'cli', 'wp', ...a], { inherit: true })).code; };
commands.logs = async (args, io) => { const { values, positionals } = parseArgs({ args, allowPositionals: true, options: { tail: { type: 'string', default: '200' } } }); const [site, service] = positionals; if (!site) { io.write('usage: wph logs <site> [service] [--tail N]\n'); return 2; } const cfg = await loadConfig(); return (await compose(harnessDir(cfg, site), ['logs', '--tail', values.tail ?? '200', ...(service ? [service] : [])], { inherit: true })).code; };
```
and hoist a helper `const deps = (io: Io) => ({ compose, run, sleep: (ms: number) => new Promise<void>((r) => setTimeout(r, ms)), write: io.write });` used by `site`, `open-admin`, and `mount`.

- [ ] **Step 4: Verify green and for real, commit**

Run: `pnpm check` — pass.
Run: `wph site create scratch && wph wp scratch -- option get siteurl` — Expected: `https://scratch.wp.test`. `wph logs scratch wordpress --tail 5` prints Apache lines. `wph site destroy scratch --confirm`.
```bash
git add -A && git commit -m "feat: wph mount, wp passthrough, and logs"
```

---

### Task 11: `wph db export|import`

**Files:**
- Create: `src/commands/db.ts`, `tests/db.test.ts`
- Modify: `src/cli.ts`

**Interfaces:**
- Produces: `async function dbExport(cfg, name, file: string, deps?: SiteDeps): Promise<number>` (runs `wp db export -` and writes the file) and `async function dbImport(cfg, name, file: string, opts: { confirm: boolean }, deps?: SiteDeps): Promise<number>` (copies the file into the `cli` container via stdin: `compose run --rm -T cli wp db import -`).

- [ ] **Step 1: Write the failing test**

`tests/db.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defaultConfig } from '../src/config.ts';
import { writeSite, newSite } from '../src/site-config.ts';
import { dbExport, dbImport } from '../src/commands/db.ts';

function deps(log: string[], stdout = '') {
  return { compose: async (_d: string, a: string[], o?: { input?: string }) => { log.push(a.join(' ') + (o?.input ? ` <<${o.input.length}` : '')); return { code: 0, stdout, stderr: '' }; }, run: async () => ({ code: 0, stdout: '', stderr: '' }), sleep: async () => {}, write: (s: string) => log.push(`out: ${s.trimEnd()}`) };
}

test('dbExport writes wp db export output to the file', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  await writeSite(cfg, newSite(cfg, 'demo'));
  const out = join(cfg.sitesDir, 'dump.sql');
  const log: string[] = [];
  assert.equal(await dbExport(cfg, 'demo', out, deps(log, '-- dump\n')), 0);
  assert.equal(await readFile(out, 'utf8'), '-- dump\n');
  assert.ok(log.includes('run --rm -T cli wp db export -'));
});

test('dbImport refuses without confirm and streams the file with it', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  await writeSite(cfg, newSite(cfg, 'demo'));
  const f = join(cfg.sitesDir, 'in.sql');
  await writeFile(f, 'SELECT 1;\n');
  const log: string[] = [];
  assert.equal(await dbImport(cfg, 'demo', f, { confirm: false }, deps(log)), 2);
  assert.equal(await dbImport(cfg, 'demo', f, { confirm: true }, deps(log)), 0);
  assert.ok(log.includes('run --rm -T cli wp db import - <<10'));
});
```

- [ ] **Step 2: Run to verify failure** — `pnpm test` FAILs on missing module.

- [ ] **Step 3: Implement**

`src/commands/db.ts`:
```ts
import { readFile, writeFile } from 'node:fs/promises';
import type { HarnessConfig } from '../config.ts';
import { harnessDir } from '../site-config.ts';
import type { SiteDeps } from './site.ts';
import { compose as realCompose } from '../exec.ts';

const out = (deps?: SiteDeps) => deps?.write ?? process.stdout.write.bind(process.stdout);

export async function dbExport(cfg: HarnessConfig, name: string, file: string, deps?: SiteDeps): Promise<number> {
  const r = await (deps?.compose ?? realCompose)(harnessDir(cfg, name), ['run', '--rm', '-T', 'cli', 'wp', 'db', 'export', '-']);
  if (r.code !== 0) { out(deps)(r.stderr); return r.code; }
  await writeFile(file, r.stdout);
  out(deps)(`exported ${name} -> ${file} (${r.stdout.length} bytes)\n`);
  return 0;
}

export async function dbImport(cfg: HarnessConfig, name: string, file: string, opts: { confirm: boolean }, deps?: SiteDeps): Promise<number> {
  if (!opts.confirm) { out(deps)(`refusing to import into ${name}: this overwrites its database. Re-run with --confirm.\n`); return 2; }
  const sql = await readFile(file, 'utf8');
  const r = await (deps?.compose ?? realCompose)(harnessDir(cfg, name), ['run', '--rm', '-T', 'cli', 'wp', 'db', 'import', '-'], { input: sql });
  if (r.code !== 0) { out(deps)(r.stdout + r.stderr); return r.code; }
  out(deps)(`imported ${file} -> ${name}\n`);
  return 0;
}
```

Register: `commands.db = async (args, io) => { const { values, positionals } = parseArgs({ args, allowPositionals: true, options: { confirm: { type: 'boolean', default: false } } }); const [sub, site, file] = positionals; const cfg = await loadConfig(); if (sub === 'export' && site) return dbExport(cfg, site, file ?? `${site}-${new Date().toISOString().slice(0, 10)}.sql`, deps(io)); if (sub === 'import' && site && file) return dbImport(cfg, site, file, { confirm: values.confirm ?? false }, deps(io)); io.write('usage: wph db export <site> [file] | wph db import <site> <file> --confirm\n'); return 2; };`

- [ ] **Step 4: Verify green and for real, commit**

Run: `pnpm check` — pass. Real: `wph site create scratch && wph db export scratch /tmp/s.sql && wph db import scratch /tmp/s.sql --confirm && wph site destroy scratch --confirm`. Expected: export byte count printed, import succeeds.
```bash
git add -A && git commit -m "feat: wph db export/import with confirm gate on import"
```

---

### Task 12: MCP stdio server (`wph mcp`) and Claude Code registration

**Files:**
- Create: `src/mcp/protocol.ts`, `src/mcp/tools.ts`, `src/mcp.ts`, `tests/mcp-protocol.test.ts`, `tests/mcp-tools.test.ts`
- Modify: `src/cli.ts` (`mcp` command), `README.md`

**Interfaces:**
- Produces:
  ```ts
  // protocol.ts — zero-dep JSON-RPC 2.0 over newline-delimited stdio, MCP 2025-06-18 subset
  interface ToolDef { name: string; description: string; inputSchema: Record<string, unknown>; handler: (args: Record<string, unknown>) => Promise<string> }
  interface Rpc { jsonrpc: '2.0'; id?: number | string | null; method?: string; params?: unknown; result?: unknown; error?: { code: number; message: string } }
  function createServer(tools: ToolDef[], info?: { name: string; version: string }): { handle: (msg: Rpc) => Promise<Rpc | null> }
  async function serveStdio(tools: ToolDef[]): Promise<void>
  // tools.ts
  function buildTools(cfg: HarnessConfig, deps: SiteDeps): ToolDef[]
  ```

- [ ] **Step 1: Write the failing tests**

`tests/mcp-protocol.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from '../src/mcp/protocol.ts';

const echo = { name: 'echo', description: 'echo text', inputSchema: { type: 'object', properties: { text: { type: 'string' } }, required: ['text'] }, handler: async (a: Record<string, unknown>) => String(a.text) };

test('initialize returns protocol version, capabilities, and server info', async () => {
  const s = createServer([echo], { name: 'wp-harness', version: '0.1.0' });
  const r = await s.handle({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 't', version: '0' } } });
  assert.deepEqual(r, { jsonrpc: '2.0', id: 1, result: { protocolVersion: '2025-06-18', capabilities: { tools: {} }, serverInfo: { name: 'wp-harness', version: '0.1.0' } } });
});

test('notifications get no response', async () => {
  const s = createServer([echo]);
  assert.equal(await s.handle({ jsonrpc: '2.0', method: 'notifications/initialized' }), null);
});

test('tools/list and tools/call', async () => {
  const s = createServer([echo]);
  const list = await s.handle({ jsonrpc: '2.0', id: 2, method: 'tools/list' });
  assert.deepEqual((list?.result as { tools: unknown[] }).tools, [{ name: 'echo', description: 'echo text', inputSchema: echo.inputSchema }]);
  const call = await s.handle({ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'echo', arguments: { text: 'hi' } } });
  assert.deepEqual(call?.result, { content: [{ type: 'text', text: 'hi' }], isError: false });
});

test('handler errors become isError results; unknown methods become -32601', async () => {
  const s = createServer([{ ...echo, handler: async () => { throw new Error('boom'); } }]);
  const call = await s.handle({ jsonrpc: '2.0', id: 4, method: 'tools/call', params: { name: 'echo', arguments: {} } });
  assert.deepEqual(call?.result, { content: [{ type: 'text', text: 'boom' }], isError: true });
  const bad = await s.handle({ jsonrpc: '2.0', id: 5, method: 'nope' });
  assert.equal(bad?.error?.code, -32601);
});
```

`tests/mcp-tools.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defaultConfig } from '../src/config.ts';
import { buildTools } from '../src/mcp/tools.ts';

test('the tool surface matches the spec and destroy/db_import require confirm', async () => {
  const cfg = defaultConfig(await mkdtemp(join(tmpdir(), 'wph-')));
  const log: string[] = [];
  const tools = buildTools(cfg, { compose: async (_d, a) => { log.push(a.join(' ')); return { code: 0, stdout: '', stderr: '' }; }, run: async () => ({ code: 0, stdout: '', stderr: '' }), sleep: async () => {}, write: () => {} });
  assert.deepEqual(tools.map((t) => t.name).sort(), ['cert_install', 'db_export', 'db_import', 'doctor', 'logs', 'mount', 'open_admin', 'router_up', 'site_create', 'site_destroy', 'site_list', 'site_start', 'site_status', 'site_stop', 'wp'].sort());
  const destroy = tools.find((t) => t.name === 'site_destroy')!;
  assert.deepEqual((destroy.inputSchema as { required: string[] }).required, ['name', 'confirm']);
  const text = await destroy.handler({ name: 'nothing', confirm: false });
  assert.match(text, /refusing/);
  assert.ok(!log.includes('down -v --remove-orphans'));
});
```

- [ ] **Step 2: Run to verify failure** — `pnpm test` FAILs on missing modules.

- [ ] **Step 3: Implement `src/mcp/protocol.ts`**

```ts
import { createInterface } from 'node:readline';

export interface ToolDef {
  name: string;
  description: string;
  inputSchema: Record<string, unknown>;
  handler: (args: Record<string, unknown>) => Promise<string>;
}
export interface Rpc {
  jsonrpc: '2.0';
  id?: number | string | null;
  method?: string;
  params?: unknown;
  result?: unknown;
  error?: { code: number; message: string };
}

export const PROTOCOL_VERSION = '2025-06-18';

export function createServer(tools: ToolDef[], info: { name: string; version: string } = { name: 'wp-harness', version: '0.0.0' }) {
  const byName = new Map(tools.map((t) => [t.name, t]));
  async function handle(msg: Rpc): Promise<Rpc | null> {
    const isNotification = msg.id === undefined;
    const reply = (result: unknown): Rpc => ({ jsonrpc: '2.0', id: msg.id ?? null, result });
    const fail = (code: number, message: string): Rpc => ({ jsonrpc: '2.0', id: msg.id ?? null, error: { code, message } });
    switch (msg.method) {
      case 'initialize':
        return reply({ protocolVersion: PROTOCOL_VERSION, capabilities: { tools: {} }, serverInfo: info });
      case 'ping':
        return reply({});
      case 'tools/list':
        return reply({ tools: tools.map(({ name, description, inputSchema }) => ({ name, description, inputSchema })) });
      case 'tools/call': {
        const p = (msg.params ?? {}) as { name?: string; arguments?: Record<string, unknown> };
        const tool = p.name ? byName.get(p.name) : undefined;
        if (!tool) return fail(-32602, `unknown tool ${String(p.name)}`);
        try {
          const text = await tool.handler(p.arguments ?? {});
          return reply({ content: [{ type: 'text', text }], isError: false });
        } catch (e) {
          return reply({ content: [{ type: 'text', text: (e as Error).message }], isError: true });
        }
      }
      default:
        if (isNotification) return null;
        return fail(-32601, `method not found: ${String(msg.method)}`);
    }
  }
  return { handle };
}

export async function serveStdio(tools: ToolDef[], info?: { name: string; version: string }): Promise<void> {
  const server = createServer(tools, info);
  const rl = createInterface({ input: process.stdin, crlfDelay: Infinity });
  for await (const line of rl) {
    if (!line.trim()) continue;
    let msg: Rpc;
    try { msg = JSON.parse(line) as Rpc; } catch { process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id: null, error: { code: -32700, message: 'parse error' } }) + '\n'); continue; }
    const res = await server.handle(msg);
    if (res) process.stdout.write(JSON.stringify(res) + '\n');
  }
}
```

- [ ] **Step 4: Implement `src/mcp/tools.ts`**

```ts
import type { HarnessConfig } from '../config.ts';
import { harnessDir } from '../site-config.ts';
import { doctor, formatChecks } from '../commands/doctor.ts';
import { routerUp } from '../commands/router.ts';
import { certInstall } from '../commands/cert.ts';
import { siteCreate, siteDestroy, siteList, siteStart, siteStatus, siteStop, wp, type SiteDeps } from '../commands/site.ts';
import { openAdmin } from '../commands/open-admin.ts';
import { mount, type MountKind } from '../commands/mount.ts';
import { dbExport, dbImport } from '../commands/db.ts';
import type { ToolDef } from './protocol.ts';

const NAME = { type: 'string', pattern: '^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$', description: 'site name (one DNS label under wp.test)' };

function capture(deps: SiteDeps): { deps: SiteDeps; text: () => string } {
  const buf: string[] = [];
  return { deps: { ...deps, write: (s) => buf.push(s) }, text: () => buf.join('') };
}

export function buildTools(cfg: HarnessConfig, base: SiteDeps): ToolDef[] {
  const withCapture = (fn: (d: SiteDeps) => Promise<number | string>) => async () => {
    const { deps, text } = capture(base);
    const r = await fn(deps);
    return typeof r === 'string' ? r : text() || `exit ${r}`;
  };
  const obj = (properties: Record<string, unknown>, required: string[] = []) => ({ type: 'object', properties, required, additionalProperties: false });
  return [
    { name: 'doctor', description: 'Check harness prerequisites (docker, compose, mkcert, dns, ports, network, cert, router). Installs nothing.', inputSchema: obj({}), handler: async () => formatChecks(await doctor(cfg)) },
    { name: 'router_up', description: 'Start the shared Traefik router, Mailpit, and phpMyAdmin.', inputSchema: obj({}), handler: async () => { const r = await routerUp(cfg); return r.code === 0 ? `router up: https://router.${cfg.tld} https://mail.${cfg.tld} https://pma.${cfg.tld}` : r.stderr; } },
    { name: 'cert_install', description: 'Install the mkcert root CA and generate the *.wp.test wildcard certificate (may prompt for sudo on the host).', inputSchema: obj({}), handler: withCapture((d) => certInstall(cfg, { run: d.run, write: d.write })) },
    { name: 'site_list', description: 'List sites with URL, PHP, WP, and running state.', inputSchema: obj({}), handler: async () => JSON.stringify(await siteList(cfg, base), null, 2) },
    { name: 'site_status', description: 'Status of one site.', inputSchema: obj({ name: NAME }, ['name']), handler: async (a) => JSON.stringify(await siteStatus(cfg, String(a.name), base), null, 2) },
    { name: 'site_create', description: 'Create and start a site at https://<name>.wp.test, install WordPress, and return the admin password.', inputSchema: obj({ name: NAME, email: { type: 'string' }, from: { type: 'string', description: 'path to a wp-site.json to copy' } }, ['name']), handler: (a) => withCapture((d) => siteCreate(cfg, String(a.name), { email: a.email as string | undefined, from: a.from as string | undefined }, d))() },
    { name: 'site_start', description: 'Re-render and start a site.', inputSchema: obj({ name: NAME }, ['name']), handler: (a) => withCapture((d) => siteStart(cfg, String(a.name), d))() },
    { name: 'site_stop', description: 'Stop a site (keeps data).', inputSchema: obj({ name: NAME }, ['name']), handler: (a) => withCapture((d) => siteStop(cfg, String(a.name), d))() },
    { name: 'site_destroy', description: 'DESTRUCTIVE: remove a site, its containers, volumes, and directory. Requires confirm=true.', inputSchema: obj({ name: NAME, confirm: { type: 'boolean' } }, ['name', 'confirm']), handler: (a) => withCapture((d) => siteDestroy(cfg, String(a.name), { confirm: a.confirm === true }, d))() },
    { name: 'mount', description: 'Mount a host directory as a plugin, theme, or mu-plugin and activate it.', inputSchema: obj({ name: NAME, kind: { type: 'string', enum: ['plugin', 'theme', 'mu'] }, slug: { type: 'string' }, path: { type: 'string' } }, ['name', 'kind', 'slug', 'path']), handler: (a) => withCapture((d) => mount(cfg, String(a.name), a.kind as MountKind, String(a.slug), String(a.path), d))() },
    { name: 'wp', description: 'Run a wp-cli command inside the site. args is the argv after "wp".', inputSchema: obj({ name: NAME, args: { type: 'array', items: { type: 'string' } } }, ['name', 'args']), handler: async (a) => { const r = await wp(cfg, String(a.name), (a.args as string[]) ?? [], base); return (r.stdout + r.stderr).slice(0, 20000) || `exit ${r.code}`; } },
    { name: 'logs', description: 'Tail container logs for a site.', inputSchema: obj({ name: NAME, service: { type: 'string', enum: ['wordpress', 'db', 'redis'] }, tail: { type: 'integer', default: 200 } }, ['name']), handler: async (a) => { const r = await base.compose(harnessDir(cfg, String(a.name)), ['logs', '--tail', String(a.tail ?? 200), ...(a.service ? [String(a.service)] : [])]); return (r.stdout + r.stderr).slice(-20000); } },
    { name: 'open_admin', description: 'Mint a one-time admin login URL (valid 5 minutes, single use).', inputSchema: obj({ name: NAME }, ['name']), handler: (a) => withCapture((d) => openAdmin(cfg, String(a.name), { open: false }, d))() },
    { name: 'db_export', description: 'Export the site database to a .sql file on the host.', inputSchema: obj({ name: NAME, file: { type: 'string' } }, ['name', 'file']), handler: (a) => withCapture((d) => dbExport(cfg, String(a.name), String(a.file), d))() },
    { name: 'db_import', description: 'DESTRUCTIVE: import a .sql file, overwriting the site database. Requires confirm=true.', inputSchema: obj({ name: NAME, file: { type: 'string' }, confirm: { type: 'boolean' } }, ['name', 'file', 'confirm']), handler: (a) => withCapture((d) => dbImport(cfg, String(a.name), String(a.file), { confirm: a.confirm === true }, d))() },
  ];
}
```

`src/mcp.ts`:
```ts
import { loadConfig } from './config.ts';
import { compose, run } from './exec.ts';
import { buildTools } from './mcp/tools.ts';
import { serveStdio } from './mcp/protocol.ts';
import { VERSION } from './version.ts';

export async function mcpMain(): Promise<number> {
  const cfg = await loadConfig();
  await serveStdio(buildTools(cfg, { compose, run, sleep: (ms) => new Promise((r) => setTimeout(r, ms)), write: () => {} }), { name: 'wp-harness', version: VERSION });
  return 0;
}
```

Register: `commands.mcp = async () => (await import('./mcp.ts')).mcpMain();`

- [ ] **Step 5: Verify green, then register with Claude Code**

Run: `pnpm check` — pass.
Run: `printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"0"}}}' '{"jsonrpc":"2.0","method":"notifications/initialized"}' '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | wph mcp` — Expected: two JSON lines, the second listing 15 tools.
```bash
claude mcp add --scope user wp-harness -- wph mcp
claude mcp list
```
Expected: `wp-harness: wph mcp - ✔ Connected`.

README: add a "MCP" section with the `claude mcp add` line and the tool table.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: zero-dependency MCP stdio server exposing the harness commands as tools"
```

---

### Task 13: Seed `alpacabot.wp.test` (Kanboard #2969)

**Files:**
- Create: `~/Sites/alpacabot/wp-site.json` (outside the repo), `blueprints/alpacabot.wp-site.json` (in the repo, the committed template), `docs/sites.md`

- [ ] **Step 1: Write the blueprint**

`blueprints/alpacabot.wp-site.json`:
```json
{
  "$schema": "../schema/wp-site.schema.json",
  "name": "alpacabot",
  "php": "8.4",
  "wp": "latest",
  "mounts": {
    "plugins": { "alpaca-bot": "~/Projects/Alpaca Bot/wp-alpaca/plugins/alpaca-bot" }
  },
  "services": { "redis": false },
  "constants": { "WP_DEBUG": true, "WP_DEBUG_LOG": true, "WP_DEBUG_DISPLAY": false, "SCRIPT_DEBUG": true, "OLLAMA_API_URL": "http://host.docker.internal:11434" },
  "admin": { "user": "admin", "email": "me@carmelosantana.com" },
  "activate": { "plugins": ["alpaca-bot"], "theme": null },
  "env": {}
}
```

- [ ] **Step 2: Create the site**

Run: `wph site create alpacabot --from blueprints/alpacabot.wp-site.json`
Expected: prints `https://alpacabot.wp.test/wp-admin  user: admin  password: …`.

- [ ] **Step 3: Verify the plugin activated and the chat page loads**

Run: `wph wp alpacabot -- plugin list --status=active --field=name` — Expected: includes `alpaca-bot`. (If activation failed because `vendor/` is missing, run `composer install` in the plugin dir first: the plugin fatals without its autoloader.)
Run: `wph open-admin alpacabot --print` then open the URL; navigate to Alpaca Bot in the admin menu. Expected: the chat screen renders. With Ollama running on the host (`curl -s localhost:11434/api/tags | head -c 100`), the model list populates.
Run: `wph site status alpacabot` — Expected: `running: true, installed: true`.

- [ ] **Step 4: Document and commit**

`docs/sites.md`: one paragraph per blueprint (name, mounts, what to check), starting with alpacabot.
```bash
git add -A && git commit -m "feat: alpacabot blueprint and site docs"
```
Then on Kanboard #2969: comment the URL and the verification output, close it. The alpaca-bot tasks Kanboard #2944, #2947, #2953 are now unblocked.

---

### Task 14: Rotate and remove secrets from the old compose files (Kanboard #2968)

**Files:**
- Delete: `~/Projects/Alpaca Bot/wp-alpaca/docker-compose.yml`, `~/Projects/Alpaca Bot/wp-alpaca/ollama-wp-dev.yml`
- These files are not in any git repo (`wp-alpaca/` is a Syncthing folder); the deletion is a filesystem action.

- [ ] **Step 1: Inventory the secrets (read only)**

Run: `grep -nE 'PASS|LICENCE|PASSWORD' ~/Projects/Alpaca\ Bot/wp-alpaca/docker-compose.yml ~/Projects/Alpaca\ Bot/wp-alpaca/ollama-wp-dev.yml`
Expected: an SMTP password for `noreply@carmelosantana.cloud` (Rackspace `secure.emailsrvr.com`), a WPMDB licence key, and a MySQL password.

- [ ] **Step 2: Human step: rotate**

Use `mattpocock-skills:wizard` to generate a wizard that walks Carmelo through: (a) changing the mailbox password in the Rackspace control panel, (b) regenerating the WP Migrate DB Pro licence key at deliciousbrains.com, (c) confirming nothing else references either value (`grep -rn 'Wu9_gE0pBp\|8fb7844d' ~/Projects ~/Work ~/PowerBank 2>/dev/null`). The agent never enters credentials.

- [ ] **Step 3: Delete the files and verify**

```bash
rm ~/Projects/Alpaca\ Bot/wp-alpaca/docker-compose.yml ~/Projects/Alpaca\ Bot/wp-alpaca/ollama-wp-dev.yml
grep -rn 'Wu9_gE0pBp\|8fb7844d' ~/Projects ~/Work ~/PowerBank 2>/dev/null || echo clean
```
Expected: `clean`. Comment the outcome on Kanboard #2968 and close it.

---

### Task 15: `wph svn status|diff|commit-assets|tag` (break-glass)

**Files:**
- Create: `src/commands/svn.ts`, `tests/svn.test.ts`
- Modify: `src/cli.ts`, `src/mcp/tools.ts` (four `svn_*` tools, all with `confirm` required except `status`/`diff`)

**Interfaces:**
- Produces:
  ```ts
  function svnDir(slug: string, home?: string): string   // ~/Projects/WordPress/wordpress.org/<slug>-svn
  async function svnStatus(slug, deps?): Promise<string>
  async function svnDiff(slug, deps?): Promise<string>
  async function svnCommitAssets(slug, assetsDir: string, message: string, opts: { confirm: boolean }, deps?): Promise<number>
  async function svnTag(slug, version: string, trunkDir: string, opts: { confirm: boolean }, deps?): Promise<number>
  ```
  Credentials: `svn` is invoked with `--non-interactive`; username/password come from `~/.config/wp-harness/svn.json` (`{ "username": "…", "password": "…" }`, mode 0600, written by hand) and are passed as `--username`/`--password` arguments that are never echoed by the tool (the `$` command echo prints `--password ****`).

- [ ] **Step 1: Write the failing tests**

`tests/svn.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { svnDir, svnCommitAssets, svnTag, redact } from '../src/commands/svn.ts';

const creds = { username: 'carmelosantana', password: 'sekret' };

test('svnDir', () => {
  assert.equal(svnDir('alpaca-bot', '/home/u'), '/home/u/Projects/WordPress/wordpress.org/alpaca-bot-svn');
});

test('redact hides the password in echoed commands', () => {
  assert.equal(redact(['svn', 'ci', '--username', 'u', '--password', 'sekret', '-m', 'x']), 'svn ci --username u --password **** -m x');
});

test('commit-assets refuses without confirm; with confirm it copies, adds, and commits', async () => {
  const ran: string[][] = [];
  const deps = { run: async (c: string, a: string[]) => { ran.push([c, ...a]); return { code: 0, stdout: '', stderr: '' }; }, write: () => {}, creds: async () => creds };
  assert.equal(await svnCommitAssets('alpaca-bot', '/repo/.wordpress-org', 'Update screenshots', { confirm: false }, deps, '/home/u'), 2);
  assert.deepEqual(ran, []);
  assert.equal(await svnCommitAssets('alpaca-bot', '/repo/.wordpress-org', 'Update screenshots', { confirm: true }, deps, '/home/u'), 0);
  assert.deepEqual(ran[0], ['svn', 'update', '/home/u/Projects/WordPress/wordpress.org/alpaca-bot-svn/assets']);
  assert.deepEqual(ran[1], ['rsync', '-a', '--delete', '/repo/.wordpress-org/', '/home/u/Projects/WordPress/wordpress.org/alpaca-bot-svn/assets/']);
  assert.deepEqual(ran[2].slice(0, 3), ['svn', 'add', '--force']);
  assert.ok(ran.at(-1)?.includes('--password') && ran.at(-1)?.includes('sekret'));
  assert.ok(ran.at(-1)?.includes('Update screenshots'));
});

test('tag copies trunk to tags/<version> and commits', async () => {
  const ran: string[][] = [];
  const deps = { run: async (c: string, a: string[]) => { ran.push([c, ...a]); return { code: 0, stdout: '', stderr: '' }; }, write: () => {}, creds: async () => creds };
  assert.equal(await svnTag('alpaca-bot', '1.0.0', '/build/trunk', { confirm: true }, deps, '/home/u'), 0);
  assert.ok(ran.some((r) => r[0] === 'rsync' && r.includes('/home/u/Projects/WordPress/wordpress.org/alpaca-bot-svn/trunk/')));
  assert.ok(ran.some((r) => r[0] === 'svn' && r[1] === 'copy' && r.some((x) => x.endsWith('/tags/1.0.0'))));
  assert.ok(ran.at(-1)?.some((x) => x.includes('Tagging version 1.0.0')));
});
```

- [ ] **Step 2: Run to verify failure** — `pnpm test` FAILs on missing module.

- [ ] **Step 3: Implement**

`src/commands/svn.ts`:
```ts
import { readFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { run as realRun, type RunResult } from '../exec.ts';

export interface SvnDeps { run: typeof realRun; write: (s: string) => void; creds: () => Promise<{ username: string; password: string }> }

export function svnDir(slug: string, home: string = homedir()): string {
  return join(home, 'Projects', 'WordPress', 'wordpress.org', `${slug}-svn`);
}

export function redact(argv: string[]): string {
  return argv.map((a, i) => (argv[i - 1] === '--password' ? '****' : a)).join(' ');
}

async function readCreds(): Promise<{ username: string; password: string }> {
  const p = join(homedir(), '.config', 'wp-harness', 'svn.json');
  try { return JSON.parse(await readFile(p, 'utf8')); } catch { throw new Error(`missing ${p}: write {"username":"…","password":"…"} with mode 0600`); }
}

const realDeps: SvnDeps = { run: realRun, write: (s) => process.stdout.write(s), creds: readCreds };

async function sh(deps: SvnDeps, argv: string[]): Promise<RunResult> {
  deps.write(`$ ${redact(argv)}\n`);
  const [c, ...a] = argv;
  const r = await deps.run(c, a);
  if (r.code !== 0) deps.write(r.stdout + r.stderr);
  return r;
}

export async function svnStatus(slug: string, deps: SvnDeps = realDeps, home?: string): Promise<string> {
  return (await deps.run('svn', ['status', svnDir(slug, home)])).stdout;
}
export async function svnDiff(slug: string, deps: SvnDeps = realDeps, home?: string): Promise<string> {
  return (await deps.run('svn', ['diff', svnDir(slug, home)])).stdout;
}

export async function svnCommitAssets(slug: string, assetsDir: string, message: string, opts: { confirm: boolean }, deps: SvnDeps = realDeps, home?: string): Promise<number> {
  if (!opts.confirm) { deps.write('refusing: svn commit-assets publishes to wordpress.org. Re-run with --confirm.\n'); return 2; }
  const assets = join(svnDir(slug, home), 'assets');
  const { username, password } = await deps.creds();
  for (const argv of [
    ['svn', 'update', assets],
    ['rsync', '-a', '--delete', assetsDir.replace(/\/?$/, '/'), assets + '/'],
    ['svn', 'add', '--force', '--quiet', assets],
  ]) { const r = await sh(deps, argv); if (r.code !== 0) return r.code; }
  const missing = await deps.run('sh', ['-c', `svn status ${JSON.stringify(assets)} | awk '/^!/{print $2}'`]);
  for (const f of missing.stdout.split('\n').filter(Boolean)) { const r = await sh(deps, ['svn', 'rm', '--quiet', f]); if (r.code !== 0) return r.code; }
  return (await sh(deps, ['svn', 'commit', '--non-interactive', '--username', username, '--password', password, '-m', message, assets])).code;
}

export async function svnTag(slug: string, version: string, trunkDir: string, opts: { confirm: boolean }, deps: SvnDeps = realDeps, home?: string): Promise<number> {
  if (!opts.confirm) { deps.write('refusing: svn tag publishes a release to wordpress.org. Re-run with --confirm.\n'); return 2; }
  const root = svnDir(slug, home);
  const trunk = join(root, 'trunk');
  const tag = join(root, 'tags', version);
  const { username, password } = await deps.creds();
  for (const argv of [
    ['svn', 'update', root],
    ['rsync', '-a', '--delete', '--exclude', '.svn', trunkDir.replace(/\/?$/, '/'), trunk + '/'],
    ['svn', 'add', '--force', '--quiet', trunk],
  ]) { const r = await sh(deps, argv); if (r.code !== 0) return r.code; }
  const missing = await deps.run('sh', ['-c', `svn status ${JSON.stringify(trunk)} | awk '/^!/{print $2}'`]);
  for (const f of missing.stdout.split('\n').filter(Boolean)) { const r = await sh(deps, ['svn', 'rm', '--quiet', f]); if (r.code !== 0) return r.code; }
  let r = await sh(deps, ['svn', 'copy', trunk, tag]);
  if (r.code !== 0) return r.code;
  r = await sh(deps, ['svn', 'commit', '--non-interactive', '--username', username, '--password', password, '-m', `Tagging version ${version}`, root]);
  return r.code;
}
```

CLI: `commands.svn = async (args, io) => { const { values, positionals } = parseArgs({ args, allowPositionals: true, options: { confirm: { type: 'boolean', default: false }, message: { type: 'string', short: 'm' } } }); const [sub, slug, a, b] = positionals; const deps = { run, write: io.write, creds: readCredsExported }; if (sub === 'status' && slug) { io.write(await svnStatus(slug, deps)); return 0; } if (sub === 'diff' && slug) { io.write(await svnDiff(slug, deps)); return 0; } if (sub === 'commit-assets' && slug && a) return svnCommitAssets(slug, a, values.message ?? 'Update assets', { confirm: values.confirm ?? false }, deps); if (sub === 'tag' && slug && a && b) return svnTag(slug, a, b, { confirm: values.confirm ?? false }, deps); io.write('usage: wph svn status|diff <slug> | commit-assets <slug> <dir> -m msg --confirm | tag <slug> <version> <trunkDir> --confirm\n'); return 2; };` (export `readCreds` from svn.ts as `readCredsExported`.)

MCP: add `svn_status`, `svn_diff` (inputs: `slug`), `svn_commit_assets` (`slug`, `dir`, `message`, `confirm` required), `svn_tag` (`slug`, `version`, `trunkDir`, `confirm` required) to `buildTools`, and update the tool-name assertion in `tests/mcp-tools.test.ts`.

- [ ] **Step 4: Verify green and dry-run for real, commit**

Run: `pnpm check` — pass.
Run: `wph svn status alpaca-bot` — Expected: `svn status` output of the existing checkout (needs `svn` installed: `sudo apt install subversion`). Do **not** run `commit-assets` or `tag` against the real slug in this task.
```bash
git add -A && git commit -m "feat: wph svn break-glass helpers with confirm gates and redacted credentials"
git push
```

---

## Not in this plan

- macOS pass (`/etc/resolver/test`, mkcert on the MacBook): spec sequencing step 8; a follow-up plan once the Linux harness is in daily use.
- `harness scratch` via `@wp-playground/cli` (research suggestion): revisit after Task 13 if throwaway checks are wanted.
- Per-site `docker-compose.override.yml` escape hatch: add when the first site needs a service the schema lacks.

## Self-review

- **Spec coverage:** topology (T5), domain + DNS + TLS (T7), site schema + override (T3), commands table (T6, T8-T11, T15), MCP (T12), doctor (T6), secrets (T14), first site (T13), router services incl. phpMyAdmin and Mailpit (T5). All spec sequencing steps 1-7 have a task; step 8 is explicitly deferred.
- **Placeholders:** none; every step has code or an exact command with expected output.
- **Type consistency:** `SiteDeps` shape `{ compose, run, sleep, write }` is used identically in T8-T12; `wp()` signature `(cfg, name, args, deps?)` matches T9/T10/T12 callers; `hostFor(cfg, name)` order matches T3; `harnessDir` imported from `site-config.ts` everywhere.
