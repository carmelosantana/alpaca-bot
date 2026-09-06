# Alpaca Bot 1.0 P3: View Layer, Assets, and Admin Screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the admin chat screen back on the new architecture: htmx 2.0.x behind server-side render components that return strings, `/view/*` fragment routes on the P2 API, a small zero-dependency TypeScript layer (streaming, nonce refresh, copy, highlighting) built with esbuild, a Lucide SVG sprite, a rewritten stylesheet on WP admin variables, and deletion of every legacy file.

**Architecture:** `View\Component` subclasses render HTML strings (never echo) with escaping helpers; `View\Hx` builds every `hx-*` attribute so no template hand-writes htmx. `Rest\ViewController` exposes `/view/chat`, `/view/messages/{id}`, `/view/history`, `/view/models` that call the P2 controllers' logic and wrap results in components. `resources/ts/chat.ts` handles the SSE stream (from P2 Task 4) into the message bubble, heartbeat-driven nonce refresh, copy buttons, and code highlighting; esbuild bundles it to `assets/js/chat.js`. Markdown renders server-side with league/commonmark (prefixed) through a `wp_kses` allowlist.

**Tech Stack:** PHP 8.4, htmx.org 2.0.x (pnpm, copied at build), esbuild 0.28, TypeScript 5.9 (typecheck only), lucide-static 1.41 (sprite built at compile time), league/commonmark 2.10, Pest + Brain\Monkey, Playwright deferred to P5.

**Spec:** `docs/superpowers/specs/2026-09-05-alpaca-bot-1-0-core-refactor.md` (sequencing step 5). Decisions: Kanboard #2942 (htmx + render class), #2972 (assets), #2954 (CSS cleanup), legacy bugs #565 #566 #613 #473 #302.

## Global Constraints

- P1/P2 constraints hold. Branch `1.0`, `composer check` and `pnpm check` green per task.
- No CDN loads; everything ships in `assets/`. `assets/js/*.js`, `assets/css/*.css`, `assets/img/icons.svg` are build outputs committed on release tags only (gitignored on the branch; P5 CI builds them).
- Zero runtime npm deps. `package.json` devDependencies: `esbuild`, `typescript`, `htmx.org`, `lucide-static`. `.npmrc` `ignore-scripts=true`; run `/powerup:supply-chain` before `pnpm add`.
- Every `hx-*` attribute is produced by `View\Hx`; grep for `hx-` outside `src/View/Hx.php` must return nothing.
- Retain the look and feel: same layout (title header with model dropdown, message list, composer at the bottom, history select), same WP admin chrome (`.wrap`, `.page-title-action`, admin color scheme variables).
- Accessibility: every icon button has `aria-label`; live region for streaming output (`aria-live="polite"`); focus returns to the composer after send.

---

### Task 1: Build pipeline (pnpm, esbuild, TypeScript, htmx copy, Lucide sprite)

**Files:**
- Create: `package.json`, `.npmrc`, `tsconfig.json`, `build.mjs`, `scripts/icons.mjs`, `resources/ts/chat.ts` (empty bootstrap), `resources/icons.json`
- Modify: `.gitignore` (`assets/js/*.js`, `assets/js/*.map`, `assets/css/*.css`, `assets/img/icons.svg`, `node_modules/`), `.distignore` (created in P5; note here)

**Interfaces:**
- Produces: `pnpm build` → `assets/js/chat.js`, `assets/js/htmx.min.js`, `assets/img/icons.svg`, `assets/css/alpaca-bot.css` (copied from `resources/css/alpaca-bot.css`); `pnpm watch`; `pnpm typecheck`; `pnpm check` (typecheck + build). Icon ids in the sprite: `lucide-<name>`.

- [ ] **Step 1: package files**

`package.json`:
```json
{
  "name": "alpaca-bot",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "node build.mjs",
    "watch": "node build.mjs --watch",
    "typecheck": "tsc --noEmit",
    "check": "pnpm typecheck && pnpm build"
  },
  "devDependencies": {}
}
```
`.npmrc`: `ignore-scripts=true`. Then, after the supply-chain check:
```bash
pnpm add -D esbuild@0.28 typescript@5.9 htmx.org@2.0 lucide-static@1.41
```

`tsconfig.json`:
```json
{ "compilerOptions": { "target": "es2022", "module": "esnext", "moduleResolution": "bundler", "strict": true, "noEmit": true, "lib": ["es2022", "dom", "dom.iterable"], "types": [] }, "include": ["resources/ts"] }
```

`resources/icons.json`:
```json
["send-horizontal", "image", "image-off", "copy", "check", "square-pen", "refresh-cw", "trash-2", "chevron-down", "wifi-off", "message-square-plus", "circle-alert", "loader-circle", "brain"]
```

`scripts/icons.mjs`:
```js
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const names = JSON.parse(await readFile(new URL('../resources/icons.json', import.meta.url), 'utf8'));
let symbols = '';
for (const name of names) {
  const svg = await readFile(require.resolve(`lucide-static/icons/${name}.svg`), 'utf8');
  const inner = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '').trim();
  symbols += `<symbol id="lucide-${name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</symbol>`;
}
await mkdir(new URL('../assets/img/', import.meta.url), { recursive: true });
await writeFile(new URL('../assets/img/icons.svg', import.meta.url), `<svg xmlns="http://www.w3.org/2000/svg" style="display:none">${symbols}</svg>\n`);
console.log(`icons: ${names.length} symbols`);
```

`build.mjs`:
```js
import { build, context } from 'esbuild';
import { copyFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const watch = process.argv.includes('--watch');
await mkdir('assets/js', { recursive: true });
await mkdir('assets/css', { recursive: true });
await copyFile(require.resolve('htmx.org/dist/htmx.min.js'), 'assets/js/htmx.min.js');
await copyFile('resources/css/alpaca-bot.css', 'assets/css/alpaca-bot.css');
await import('./scripts/icons.mjs');
const opts = { entryPoints: ['resources/ts/chat.ts'], bundle: true, minify: !watch, sourcemap: watch, target: ['es2022'], format: 'iife', outfile: 'assets/js/chat.js', logLevel: 'info' };
if (watch) { const ctx = await context(opts); await ctx.watch(); } else { await build(opts); }
```

`resources/ts/chat.ts` initial content: `export {};` and `resources/css/alpaca-bot.css` initial content: `/* Alpaca Bot admin styles (P3 Task 5) */`.

- [ ] **Step 2: Verify**

Run: `pnpm check`
Expected: typecheck clean; `icons: 14 symbols`; `assets/js/chat.js`, `assets/js/htmx.min.js`, `assets/img/icons.svg`, `assets/css/alpaca-bot.css` exist. `head -c 60 assets/js/htmx.min.js` shows the htmx banner with `2.0.`.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "build: esbuild + TypeScript pipeline, htmx via pnpm, Lucide sprite"
```

---

### Task 2: `View\Hx`, `View\Component`, escaping, markdown

**Files:**
- Create: `src/View/Hx.php`, `src/View/Component.php`, `src/View/Markdown.php`, `src/View/Icon.php`, `tests/Unit/View/HxTest.php`, `tests/Unit/View/MarkdownTest.php`, `tests/Unit/View/ComponentTest.php`

**Interfaces:**
```php
namespace AlpacaBot\View;
final class Hx {
    public static function url(string $viewPath): string;   // rest_url('alpaca-bot/v1/view' . $viewPath)
    /** @param array<string, string|array> $attrs e.g. ['get' => '/history', 'target' => '#ab-history', 'swap' => 'outerHTML', 'trigger' => 'load', 'vals' => [...], 'headers' => [...]] */
    public static function attrs(array $attrs): string;      // ' hx-get="…" hx-target="…"' — escaped; vals/headers JSON-encoded
    public static function formHeaders(): array;             // ['X-WP-Nonce' => wp_create_nonce('wp_rest')]
}
abstract class Component {
    abstract public function render(): string;
    public function __toString(): string;                    // render()
    protected function e(string $s): string;                 // esc_html
    protected function a(string $s): string;                 // esc_attr
    protected function u(string $s): string;                 // esc_url
    protected function t(string $s): string;                 // __( $s, 'alpaca-bot' )
    /** @param array<string, string|null> $attrs */ protected function tag(string $name, array $attrs, string $inner = ''): string;
}
final class Icon { public static function svg(string $name, string $class = ''): string; }   // <svg class="ab-icon …" aria-hidden="true"><use href="#lucide-name"/></svg>
final class Markdown {
    public function __construct(private ?\AlpacaBot\Vendor\League\CommonMark\ConverterInterface $converter = null) {}
    public function toHtml(string $markdown): string;        // commonmark (GFM tables/strikethrough/autolink) → wp_kses(allowlist) ; code blocks keep class="language-x"
    public static function allowedTags(): array;             // filter alpaca_bot/render/allowed_tags
}
```

- [ ] **Step 1: Write the failing tests**

`tests/Unit/View/HxTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\View\Hx;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('rest_url')->alias(fn(string $p) => 'https://alpacabot.wp.test/wp-json/' . $p);
    Functions\when('esc_attr')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    Functions\when('esc_url')->returnArg();
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('wp_create_nonce')->justReturn('n0nce');
});

it('renders hx attributes with the view URL, escaping, and JSON vals', function (): void {
    $s = Hx::attrs(['get' => '/history', 'target' => '#ab-history', 'swap' => 'outerHTML', 'trigger' => 'load, ab:refresh from:body', 'vals' => ['limit' => 5], 'headers' => Hx::formHeaders()]);
    expect($s)->toBe(' hx-get="https://alpacabot.wp.test/wp-json/alpaca-bot/v1/view/history" hx-target="#ab-history" hx-swap="outerHTML" hx-trigger="load, ab:refresh from:body" hx-vals="{&quot;limit&quot;:5}" hx-headers="{&quot;X-WP-Nonce&quot;:&quot;n0nce&quot;}"');
});

it('rejects unknown attributes', function (): void {
    expect(fn() => Hx::attrs(['onclick' => 'x']))->toThrow(InvalidArgumentException::class);
});
```

`tests/Unit/View/MarkdownTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\View\Markdown;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('converts GFM and keeps language classes while stripping scripts', function (): void {
    Functions\when('wp_kses')->alias(fn(string $html, array $allowed) => strip_tags($html, array_map(fn($t) => "<$t>", array_keys($allowed))));
    Filters\expectApplied('alpaca_bot/render/allowed_tags')->once()->andReturnFirstArg();
    $html = (new Markdown())->toHtml("# Hi\n\n```php\necho 1;\n```\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n<script>alert(1)</script> ~~x~~ https://example.test");
    expect($html)->toContain('<h1>Hi</h1>')
        ->toContain('<code class="language-php">')
        ->toContain('<table>')
        ->toContain('<del>x</del>')
        ->toContain('<a href="https://example.test">')
        ->not->toContain('<script>');
});
```

`tests/Unit/View/ComponentTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\View\Component;
use AlpacaBot\View\Icon;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('esc_html')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
    Functions\when('esc_attr')->alias(fn(string $s) => htmlspecialchars($s, ENT_QUOTES));
});

it('tag() escapes attributes and skips nulls', function (): void {
    $c = new class extends Component { public function render(): string { return $this->tag('button', ['class' => 'x', 'aria-label' => 'a"b', 'disabled' => null], $this->e('<hi>')); } };
    expect((string) $c)->toBe('<button class="x" aria-label="a&quot;b">&lt;hi&gt;</button>');
});

it('Icon renders a sprite reference', function (): void {
    expect(Icon::svg('copy', 'ab-icon--sm'))->toBe('<svg class="ab-icon ab-icon--sm" aria-hidden="true" focusable="false"><use href="#lucide-copy"></use></svg>');
});
```

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing classes.

- [ ] **Step 3: Implement**

`src/View/Hx.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\View;

final class Hx
{
    private const ALLOWED = ['get', 'post', 'put', 'delete', 'target', 'swap', 'trigger', 'vals', 'headers', 'indicator', 'include', 'select', 'sync', 'disabled-elt', 'push-url', 'on:', 'swap-oob'];

    public static function url(string $viewPath): string
    {
        return rest_url('alpaca-bot/v1/view' . $viewPath);
    }

    public static function formHeaders(): array
    {
        return ['X-WP-Nonce' => wp_create_nonce('wp_rest')];
    }

    /** @param array<string, string|array> $attrs */
    public static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            $base = str_starts_with($k, 'on:') ? 'on:' : $k;
            if (!in_array($base, self::ALLOWED, true)) {
                throw new \InvalidArgumentException("Unknown htmx attribute: {$k}");
            }
            if (in_array($k, ['get', 'post', 'put', 'delete'], true)) {
                $v = esc_url(self::url((string) $v));
            } elseif (is_array($v)) {
                $v = wp_json_encode($v);
            }
            $out .= sprintf(' hx-%s="%s"', $k, esc_attr((string) $v));
        }
        return $out;
    }
}
```

`src/View/Component.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\View;

abstract class Component
{
    abstract public function render(): string;

    public function __toString(): string
    {
        return $this->render();
    }

    protected function e(string $s): string { return esc_html($s); }
    protected function a(string $s): string { return esc_attr($s); }
    protected function u(string $s): string { return esc_url($s); }
    protected function t(string $s): string { return __($s, 'alpaca-bot'); }

    /** @param array<string, string|null> $attrs */
    protected function tag(string $name, array $attrs, string $inner = ''): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            if ($v === null) {
                continue;
            }
            $a .= sprintf(' %s="%s"', $k, $this->a($v));
        }
        return in_array($name, ['input', 'img', 'br'], true) ? "<{$name}{$a}>" : "<{$name}{$a}>{$inner}</{$name}>";
    }
}
```

`src/View/Icon.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\View;

final class Icon
{
    public static function svg(string $name, string $class = ''): string
    {
        $cls = trim('ab-icon ' . $class);
        return sprintf('<svg class="%s" aria-hidden="true" focusable="false"><use href="#lucide-%s"></use></svg>', esc_attr($cls), esc_attr($name));
    }
}
```

`src/View/Markdown.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\View;

use AlpacaBot\Vendor\League\CommonMark\ConverterInterface;
use AlpacaBot\Vendor\League\CommonMark\Environment\Environment;
use AlpacaBot\Vendor\League\CommonMark\Extension\Autolink\AutolinkExtension;
use AlpacaBot\Vendor\League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use AlpacaBot\Vendor\League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use AlpacaBot\Vendor\League\CommonMark\Extension\Table\TableExtension;
use AlpacaBot\Vendor\League\CommonMark\MarkdownConverter;

final class Markdown
{
    private ConverterInterface $converter;

    public function __construct(?ConverterInterface $converter = null)
    {
        if ($converter === null) {
            $env = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 50]);
            $env->addExtension(new CommonMarkCoreExtension());
            $env->addExtension(new TableExtension());
            $env->addExtension(new StrikethroughExtension());
            $env->addExtension(new AutolinkExtension());
            $converter = new MarkdownConverter($env);
        }
        $this->converter = $converter;
    }

    public function toHtml(string $markdown): string
    {
        $html = (string) $this->converter->convert($markdown);
        return wp_kses($html, self::allowedTags());
    }

    public static function allowedTags(): array
    {
        $attrs = ['class' => true];
        $tags = ['p' => [], 'br' => [], 'strong' => [], 'em' => [], 'del' => [], 'code' => $attrs, 'pre' => $attrs, 'blockquote' => [], 'ul' => [], 'ol' => ['start' => true], 'li' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'hr' => [], 'a' => ['href' => true, 'rel' => true, 'target' => true], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['align' => true], 'td' => ['align' => true], 'img' => ['src' => true, 'alt' => true]];
        return (array) apply_filters('alpaca_bot/render/allowed_tags', $tags);
    }
}
```
Confirm the prefixed extension class paths in `vendor-prefixed/league/commonmark/src/Extension/*` before committing.

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: View component base, Hx attribute builder, icon sprite helper, markdown renderer"
```

---

### Task 3: Chat components

**Files:**
- Create: `src/View/Chat/Shell.php`, `src/View/Chat/Header.php`, `src/View/Chat/MessageBubble.php`, `src/View/Chat/MessageList.php`, `src/View/Chat/Composer.php`, `src/View/Chat/HistorySelect.php`, `src/View/Chat/ModelSelect.php`, `src/View/Chat/Receipt.php`, `src/View/Chat/Notice.php`, `tests/Unit/View/Chat/ComponentsTest.php`

**Interfaces:**
- `Shell(Store $store, ModelCatalog $catalog, ?Conversation $conversation, array $history, string $nonce)` renders the whole page body: `<div class="wrap ab-wrap"><Header/><div id="ab-chat" data-conversation="N" data-stream-url="…" data-rest="…"><MessageList/></div><Composer/></div>` plus the sprite include (`assets/img/icons.svg` inlined once).
- `Header(string $title, ModelSelect $models, HistorySelect $history)`: `<h1 class="wp-heading-inline">` + "New chat" `page-title-action` + selects. `ModelSelect(Model[] $models, string $selected, bool $canChange)` (disabled when not allowed; `hx-post /view/default-model` on change persists user default: closes Kanboard #565). `HistorySelect(array $history, int $current)` with `hx-get /view/messages/{id}` on change targeting `#ab-messages`.
- `MessageBubble(Message $m, Markdown $md, string $userName, string $userAvatar, string $assistantAvatar, bool $streaming = false)`: `<article class="ab-msg ab-msg--user|assistant" data-role>` with avatar, name, content (`.ab-msg__content`, markdown for assistant, escaped text with `nl2br` for user), copy button (`data-action="copy"`), edit/resubmit for user (`data-action="edit"`), and `<Receipt/>` for assistant when usage present. When `$streaming`, content is empty and the article has `data-streaming="1"` and `aria-live="polite"`.
- `MessageList(Message[] $messages, ...)` → `<div id="ab-messages">` with bubbles, or a welcome block (`chat.welcome`) when empty.
- `Composer(Store $store, string $nonce)`: `<form id="ab-form">` with textarea `name="message"`, hidden `conversation_id`, `model`, `images`, `context[post_id]`, image buttons, submit button (`aria-label`, Lucide `send-horizontal`), and `data-action="send"`. The form does not use htmx for sending; `chat.ts` handles it (streaming). `spellcheck` attribute from settings. Hover/focus states for send fixed in CSS (Kanboard #566).
- `Receipt(array $receipt)`: `<footer class="ab-receipt">m · 123 tokens · 1.2 s</footer>`.
- `Notice(string $kind, string $text)`: WP `notice notice-{kind} inline`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/View/Chat/ComponentsTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\Message;
use AlpacaBot\Provider\Model;
use AlpacaBot\View\Chat\Composer;
use AlpacaBot\View\Chat\MessageBubble;
use AlpacaBot\View\Chat\MessageList;
use AlpacaBot\View\Chat\ModelSelect;
use AlpacaBot\View\Chat\Receipt;
use AlpacaBot\View\Markdown;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    foreach (['esc_html', 'esc_attr', 'esc_url', 'esc_textarea', '__', 'wp_kses_post'] as $f) { Functions\when($f)->returnArg(); }
    Functions\when('wp_kses')->alias(fn(string $h) => $h);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('get_option')->justReturn([]);
    Functions\when('selected')->alias(fn($a, $b, $e = true) => $a == $b ? ' selected' : '');
    Functions\when('number_format_i18n')->alias(fn($n) => (string) $n);
});

it('renders an assistant bubble with markdown, copy button, and receipt', function (): void {
    $m = new Message('assistant', "Hello **you**", 'llama3.2', ['prompt_tokens' => 5, 'completion_tokens' => 7], 0, [], ['duration_ms' => 1200]);
    $html = (new MessageBubble($m, new Markdown(), 'Carmelo', '/u.png', '/a.png'))->render();
    expect($html)->toContain('class="ab-msg ab-msg--assistant"')->toContain('<strong>you</strong>')->toContain('data-action="copy"')->toContain('12 tokens')->toContain('aria-label=');
});

it('renders a user bubble escaped with edit action', function (): void {
    $m = new Message('user', "<b>x</b>\nline2");
    Functions\when('esc_html')->alias(fn(string $s) => htmlspecialchars($s));
    $html = (new MessageBubble($m, new Markdown(), 'Carmelo', '/u.png', '/a.png'))->render();
    expect($html)->toContain('&lt;b&gt;x&lt;/b&gt;<br>')->toContain('data-action="edit"')->not->toContain('<b>x</b>');
});

it('renders a streaming placeholder', function (): void {
    $html = (new MessageBubble(new Message('assistant', ''), new Markdown(), 'C', '/u.png', '/a.png', true))->render();
    expect($html)->toContain('data-streaming="1"')->toContain('aria-live="polite"');
});

it('model select disables when users cannot change and marks selection', function (): void {
    $html = (new ModelSelect([new Model('a', 'a'), new Model('b', 'b', vision: true)], 'b', false))->render();
    expect($html)->toContain('<select')->toContain('disabled')->toContain('<option value="b" selected')->toContain('hx-post="/wp-json/alpaca-bot/v1/view/default-model"');
});

it('message list shows the welcome block when empty', function (): void {
    Functions\when('get_option')->justReturn(['chat.welcome' => 'Hey there']);
    $html = (new MessageList([], new Markdown(), new Store(), 'C', '/u.png', '/a.png'))->render();
    expect($html)->toContain('id="ab-messages"')->toContain('Hey there')->toContain('ab-welcome');
});

it('composer carries hidden fields, spellcheck, and no hx attributes', function (): void {
    Functions\when('get_option')->justReturn(['chat.spellcheck' => false, 'chat.placeholder' => 'Ask']);
    $html = (new Composer(new Store(), 'n', 0, 'llama3.2', 12))->render();
    expect($html)->toContain('id="ab-form"')->toContain('spellcheck="false"')->toContain('placeholder="Ask"')->toContain('name="conversation_id" value="0"')->toContain('name="context[post_id]" value="12"')->toContain('data-action="send"')->not->toContain('hx-');
});

it('receipt formats tokens and seconds', function (): void {
    expect((new Receipt(['model' => 'm', 'total_tokens' => 1234, 'duration_ms' => 2345]))->render())->toContain('m')->toContain('1234 tokens')->toContain('2.3 s');
});
```

- [ ] **Step 2: Run to verify failure** — FAIL on missing classes.

- [ ] **Step 3: Implement** the nine components. Representative implementations (the rest follow the same pattern with `tag()`, `Icon::svg()`, and `Hx::attrs()`):

`src/View/Chat/MessageBubble.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Chat\Message;
use AlpacaBot\View\Component;
use AlpacaBot\View\Icon;
use AlpacaBot\View\Markdown;

final class MessageBubble extends Component
{
    public function __construct(private Message $m, private Markdown $md, private string $userName, private string $userAvatar, private string $assistantAvatar, private bool $streaming = false) {}

    public function render(): string
    {
        $assistant = $this->m->role === 'assistant';
        $name = $assistant ? ($this->m->model !== '' ? $this->m->model : $this->t('Assistant')) : $this->userName;
        $content = $this->streaming ? '' : ($assistant ? $this->md->toHtml($this->m->content) : nl2br($this->e($this->m->content), false));
        $actions = $this->tag('button', ['type' => 'button', 'class' => 'ab-msg__action', 'data-action' => 'copy', 'aria-label' => $this->t('Copy message')], Icon::svg('copy'));
        if (!$assistant) {
            $actions .= $this->tag('button', ['type' => 'button', 'class' => 'ab-msg__action', 'data-action' => 'edit', 'aria-label' => $this->t('Edit and resend')], Icon::svg('square-pen'));
        }
        $receipt = $assistant && $this->m->usage !== null ? (new Receipt(['model' => $this->m->model, 'total_tokens' => ($this->m->usage['prompt_tokens'] ?? 0) + ($this->m->usage['completion_tokens'] ?? 0), 'duration_ms' => (int) ($this->m->meta['duration_ms'] ?? 0)]))->render() : '';
        $inner = $this->tag('img', ['class' => 'ab-msg__avatar', 'src' => $assistant ? $this->assistantAvatar : $this->userAvatar, 'alt' => ''])
            . $this->tag('div', ['class' => 'ab-msg__body'],
                $this->tag('header', ['class' => 'ab-msg__meta'], $this->tag('span', ['class' => 'ab-msg__name'], $this->e($name)) . $this->tag('span', ['class' => 'ab-msg__actions'], $actions))
                . $this->tag('div', ['class' => 'ab-msg__content', 'aria-live' => $this->streaming ? 'polite' : null], $content)
                . $receipt);
        return $this->tag('article', ['class' => 'ab-msg ab-msg--' . ($assistant ? 'assistant' : 'user'), 'data-role' => $this->m->role, 'data-streaming' => $this->streaming ? '1' : null], $inner);
    }
}
```

`src/View/Chat/Composer.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Settings\Store;
use AlpacaBot\View\Component;
use AlpacaBot\View\Icon;

final class Composer extends Component
{
    public function __construct(private Store $store, private string $nonce, private int $conversationId, private string $model, private int $postId = 0) {}

    public function render(): string
    {
        $textarea = $this->tag('textarea', ['name' => 'message', 'id' => 'ab-message', 'rows' => '1', 'required' => 'required', 'placeholder' => (string) $this->store->get('chat.placeholder'), 'spellcheck' => $this->store->get('chat.spellcheck') ? 'true' : 'false', 'aria-label' => $this->t('Message')]);
        $hidden = $this->tag('input', ['type' => 'hidden', 'name' => 'conversation_id', 'value' => (string) $this->conversationId])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'model', 'value' => $this->model])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'images', 'value' => ''])
            . $this->tag('input', ['type' => 'hidden', 'name' => 'context[post_id]', 'value' => (string) $this->postId])
            . $this->tag('input', ['type' => 'hidden', 'name' => '_wpnonce', 'value' => $this->nonce]);
        $buttons = $this->tag('button', ['type' => 'button', 'class' => 'ab-btn ab-btn--icon', 'data-action' => 'image', 'aria-label' => $this->t('Attach an image')], Icon::svg('image'))
            . $this->tag('button', ['type' => 'button', 'class' => 'ab-btn ab-btn--icon', 'data-action' => 'image-remove', 'aria-label' => $this->t('Remove image'), 'hidden' => 'hidden'], Icon::svg('image-off'))
            . $this->tag('button', ['type' => 'submit', 'class' => 'ab-btn ab-btn--send', 'data-action' => 'send', 'aria-label' => $this->t('Send')], Icon::svg('send-horizontal'));
        return $this->tag('form', ['id' => 'ab-form', 'class' => 'ab-composer', 'autocomplete' => 'off'], $hidden . $this->tag('div', ['class' => 'ab-composer__row'], $textarea . $this->tag('div', ['class' => 'ab-composer__buttons'], $buttons)) . $this->tag('p', ['class' => 'ab-composer__status', 'role' => 'status', 'aria-live' => 'polite'], ''));
    }
}
```

`src/View/Chat/ModelSelect.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\View\Chat;

use AlpacaBot\Provider\Model;
use AlpacaBot\View\Component;
use AlpacaBot\View\Hx;

final class ModelSelect extends Component
{
    /** @param Model[] $models */
    public function __construct(private array $models, private string $selected, private bool $canChange) {}

    public function render(): string
    {
        $opts = '';
        foreach ($this->models as $m) {
            $opts .= sprintf('<option value="%s"%s>%s%s</option>', $this->a($m->id), selected($this->selected, $m->id, false), $this->e($m->label), $m->vision ? ' 👁' : '');
        }
        $hx = $this->canChange ? Hx::attrs(['post' => '/default-model', 'trigger' => 'change', 'target' => '#ab-status', 'swap' => 'innerHTML', 'headers' => Hx::formHeaders()]) : '';
        return sprintf('<label class="screen-reader-text" for="ab-model">%s</label><select id="ab-model" name="model" class="ab-select"%s%s>%s</select>', $this->e($this->t('Model')), $this->canChange ? '' : ' disabled', $hx, $opts);
    }
}
```

`Receipt`, `Notice`, `HistorySelect`, `MessageList`, `Header`, `Shell` follow the same shape. `Shell::render()` inlines `file_get_contents(ALPACA_BOT_DIR . 'assets/img/icons.svg')` once, outputs `<div class="wrap ab-wrap">`, header, `<div id="ab-chat" data-conversation="…" data-rest="…" data-history-limit="…">` containing `<div id="ab-status"></div>` and the message list, then the composer. `HistorySelect` uses `Hx::attrs(['get' => '/messages/0', 'trigger' => 'change', 'target' => '#ab-messages', 'swap' => 'outerHTML'])` with a `data-url-template` so `chat.ts` swaps the id (htmx can't template URLs from select values), or simpler: each `<option>` carries `data-id`, and `chat.ts` sets `hx-get` before the request via the `htmx:configRequest` event.

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: chat view components (shell, bubbles, composer, selects, receipt)"
```

---

### Task 4: View routes, admin screen, asset enqueue, default-model persistence

**Files:**
- Create: `src/Rest/ViewController.php`, `src/Admin/ChatScreen.php`, `src/Admin/Assets.php`, `src/Chat/UserPrefs.php`, `tests/Unit/Rest/ViewControllerTest.php`, `tests/Integration/ViewRoutesTest.php`
- Modify: `src/Plugin.php` (replace the placeholder chat renderer; register view controller)

**Interfaces:**
- `Chat\UserPrefs`: `defaultModel(int $userId): string` / `setDefaultModel(int $userId, string $model): void` (user meta `alpaca_bot_default_model`); the pipeline's model resolution in `Pipeline::send()` consults it when `options['model']` is empty (small P1 modification: add `?UserPrefs` constructor arg, default null).
- `Rest\ViewController` routes (`edit_posts`, `text/html; charset=utf-8` responses via `WP_REST_Response` with data string and a `rest_pre_serve_request` hook that echoes strings when `X-Alpaca-Bot-View: 1` is set, same mechanism as P2 streaming): `GET /view/messages/{id}` → `MessageList` for the conversation (404 when not owned); `GET /view/history` → `HistorySelect`; `GET /view/models?refresh=1` → `ModelSelect`; `POST /view/default-model` `{model}` → `Notice` "Default model saved"; `GET /view/bubble?role=assistant&streaming=1` → an empty streaming bubble (used by `chat.ts` before the SSE begins) and `POST /view/bubble` `{role, content, model, usage, duration_ms}` → a rendered bubble (used after `done` to replace the streamed raw text with markdown-rendered HTML).
- `Admin\Assets::enqueue(string $hook)`: only on `toplevel_page_alpaca-bot`: `wp_enqueue_script('alpaca-bot-htmx', …/htmx.min.js, [], '2.0.x', true)`, `wp_enqueue_script('alpaca-bot-chat', …/chat.js, ['alpaca-bot-htmx', 'wp-api-fetch', 'heartbeat'], VERSION, true)`, `wp_enqueue_style('alpaca-bot', …/alpaca-bot.css, [], VERSION)`, `wp_localize_script('alpaca-bot-chat', 'alpacaBot', ['rest' => rest_url('alpaca-bot/v1'), 'nonce' => wp_create_nonce('wp_rest'), 'i18n' => [...], 'offline' => __('You are offline…')])`; `wp_enqueue_media()` for the image picker. Version strings come from `filemtime` in dev (`WP_DEBUG`) and `Plugin::VERSION` otherwise.
- `Admin\ChatScreen::render()`: builds `Shell` with the current user's latest conversation (or none), history list, models, and `post_id` from `?post=` query when present; echoes it.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Rest/ViewControllerTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Rest\ViewController;
use AlpacaBot\Settings\Store;
use AlpacaBot\View\Markdown;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    foreach (['esc_html', 'esc_attr', 'esc_url', 'esc_textarea', '__', 'sanitize_text_field'] as $f) { Functions\when($f)->returnArg(); }
    Functions\when('wp_kses')->alias(fn(string $h) => $h);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('get_option')->justReturn([]);
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'C', 'ID' => 3]);
    Functions\when('get_avatar_url')->justReturn('/u.png');
    Functions\when('selected')->justReturn('');
    Functions\when('number_format_i18n')->alias(fn($n) => (string) $n);
});

it('returns an HTML message list for an owned conversation and 404 otherwise', function (): void {
    $store = Mockery::mock(ConversationStore::class);
    $store->shouldReceive('load')->with(5, 3)->andReturn(new Conversation(5, 3, 'T', [new Message('user', 'q'), new Message('assistant', 'a', 'm')]));
    $store->shouldReceive('load')->with(6, 3)->andReturn(null);
    $c = new ViewController($store, new Store(), Mockery::mock(ModelCatalog::class), new Markdown(), Mockery::mock(UserPrefs::class));
    $res = $c->messages(new WP_REST_Request('GET', '/x', ['id' => '5']));
    expect($res)->toBeInstanceOf(WP_REST_Response::class)->and($res->headers['X-Alpaca-Bot-View'])->toBe('1')->and($res->get_data())->toContain('id="ab-messages"')->toContain('ab-msg--assistant');
    expect($c->messages(new WP_REST_Request('GET', '/x', ['id' => '6'])))->toBeInstanceOf(WP_Error::class);
});

it('persists the default model and returns a notice', function (): void {
    $prefs = Mockery::mock(UserPrefs::class);
    $prefs->shouldReceive('setDefaultModel')->once()->with(3, 'llama3.2');
    $c = new ViewController(Mockery::mock(ConversationStore::class), new Store(), Mockery::mock(ModelCatalog::class), new Markdown(), $prefs);
    $res = $c->defaultModel(new WP_REST_Request('POST', '/x', ['model' => 'llama3.2']));
    expect($res->get_data())->toContain('notice-success')->toContain('Default model saved');
});
```

`tests/Integration/ViewRoutesTest.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

final class ViewRoutesTest extends TestCase
{
    public function test_view_routes_return_html_fragments(): void
    {
        $this->asAdmin();
        $res = $this->rest('GET', '/view/history');
        $this->assertSame(200, $res->get_status());
        $this->assertStringContainsString('<select', $res->get_data());
        $this->assertSame('1', $res->get_headers()['X-Alpaca-Bot-View']);
        $res = $this->rest('GET', '/view/bubble', ['role' => 'assistant', 'streaming' => '1']);
        $this->assertStringContainsString('data-streaming="1"', $res->get_data());
    }

    public function test_default_model_is_saved_in_user_meta(): void
    {
        $uid = $this->asAdmin();
        $this->rest('POST', '/view/default-model', ['model' => 'qwen3:8b']);
        $this->assertSame('qwen3:8b', get_user_meta($uid, 'alpaca_bot_default_model', true));
    }
}
```

- [ ] **Step 2: Run to verify failure** — FAIL on missing classes.

- [ ] **Step 3: Implement** `UserPrefs` (two user-meta methods), `ViewController` (routes table with `capability => 'edit_posts'`; each callback builds the component and returns `self::html(string)` which sets the `X-Alpaca-Bot-View` header; a `serve()` hook identical in shape to P2's `StreamController::serve()` that sends `Content-Type: text/html; charset=utf-8` and echoes the string), `Admin\ChatScreen`, and `Admin\Assets` per the interface block above. In `Plugin::register()`: replace the placeholder renderer with `[new Admin\ChatScreen(...), 'render']`, `add_action('admin_enqueue_scripts', [new Admin\Assets(), 'enqueue'])`, register `ViewController` in `controllers()` and its `serve` on `rest_pre_serve_request`. Add the `UserPrefs` fallback to `Pipeline::send()` (P1 file): `$model = $options['model'] ?: ($this->prefs?->defaultModel($userId) ?: $this->catalog->defaultId($this->store));` with a matching unit test in `PipelineTest`.

- [ ] **Step 4: Verify green, real check, commit**

Run: `composer check && composer test:integration` — pass.
Real: `wph open-admin alpacabot` → Alpaca Bot menu → the chat screen renders with header, model select, history select, welcome block, composer; no console errors; `view-source` shows no `hx-` outside generated attributes; changing the model select shows the "Default model saved" notice and survives reload (Kanboard #565).
```bash
git add -A && git commit -m "feat: view fragment routes, admin chat screen, asset enqueue, per-user default model"
```

---

### Task 5: `chat.ts`: streaming, nonce refresh, copy, highlight, offline, images

**Files:**
- Create: `resources/ts/chat.ts`, `resources/ts/stream.ts`, `resources/ts/highlight.ts`, `resources/ts/nonce.ts`, `resources/ts/dom.ts`, `tests/ts/highlight.test.ts`, `tests/ts/stream.test.ts`
- Modify: `package.json` (`"test": "node --test \"tests/ts/**/*.test.ts\""`, `check` includes test)

**Interfaces (TS):**
- `stream.ts`: `export async function* readSse(res: Response): AsyncGenerator<{event: string; data: unknown}>` parses `event:`/`data:` frames from a `fetch` body; `export function parseFrames(chunk: string, carry: string): {frames: {event: string; data: string}[]; carry: string}` (pure, tested).
- `highlight.ts`: `export function highlight(code: string, lang: string): string` → escaped HTML with `<span class="tok-kw|tok-str|tok-cmt|tok-num|tok-fn">` for `php, js, ts, json, bash, css, html, sql, python` using per-language regex tables; unknown languages return escaped text. `export function decorate(root: ParentNode): void` finds `pre > code[class*="language-"]`, applies `highlight`, and injects a copy button with `data-action="copy-code"` (Kanboard #613).
- `nonce.ts`: `export function watchNonce(onNonce: (n: string) => void): void` hooks `wp.heartbeat` (`heartbeat-tick` → `data.rest_nonce` when present; the PHP side adds `rest_nonce` via the `heartbeat_received` filter in `Admin\Assets`) and replaces `alpacaBot.nonce`, the composer's `_wpnonce`, and `hx-headers` on the form (Kanboard #302). If a request returns 403 `rest_cookie_invalid_nonce`, the UI disables the composer and shows a `Notice` asking to reload.
- `chat.ts` (entry): wires `#ab-form` submit → optimistic user bubble (`POST /view/bubble`) → `POST /chat {stream:true}` → fetch `stream_url` with `X-WP-Nonce` → append deltas to a streaming bubble fetched from `GET /view/bubble?role=assistant&streaming=1` → on `done`, replace with `POST /view/bubble` render and dispatch `htmx.trigger(document.body, 'ab:refresh')` so the history select reloads; `error` → `Notice`. Also: Enter sends / Shift+Enter newline; Escape clears; `data-action="copy"|"edit"|"image"|"image-remove"|"copy-code"`; `navigator.onLine` + `offline`/`online` events toggle a `wifi-off` notice and disable send (Kanboard #473); auto-scroll while streaming unless the user scrolled up; `wp.media` picker for images (base64 data URL into `images`).

- [ ] **Step 1: Write the failing TS tests**

`tests/ts/stream.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseFrames } from '../../resources/ts/stream.ts';

test('parses complete frames and carries partials', () => {
  const a = parseFrames('event: delta\ndata: {"text":"a"}\n\nevent: de', '');
  assert.deepEqual(a.frames, [{ event: 'delta', data: '{"text":"a"}' }]);
  assert.equal(a.carry, 'event: de');
  const b = parseFrames('lta\ndata: {"text":"b"}\n\n', a.carry);
  assert.deepEqual(b.frames, [{ event: 'delta', data: '{"text":"b"}' }]);
  assert.equal(b.carry, '');
});
```

`tests/ts/highlight.test.ts`:
```ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { highlight } from '../../resources/ts/highlight.ts';

test('highlights php keywords, strings, comments and escapes html', () => {
  const out = highlight('<?php // hi\n$x = "a<b"; return $x;', 'php');
  assert.match(out, /<span class="tok-cmt">\/\/ hi<\/span>/);
  assert.match(out, /<span class="tok-str">&quot;a&lt;b&quot;<\/span>/);
  assert.match(out, /<span class="tok-kw">return<\/span>/);
  assert.ok(!out.includes('<b'));
});

test('unknown language is escaped only', () => {
  assert.equal(highlight('a < b', 'brainfuck'), 'a &lt; b');
});
```
Node's type-stripping runs these directly (`node --test "tests/ts/**/*.test.ts"`); the modules under test must not import DOM-only code at module top level (`highlight.ts` and `stream.ts` are pure; `decorate()` and `readSse()` touch the DOM/Response only when called).

- [ ] **Step 2: Run to verify failure** — `pnpm test` FAILs on missing modules.

- [ ] **Step 3: Implement** `stream.ts` (`parseFrames` splits on `\n\n`, each frame's lines parsed for `event:`/`data:`; `readSse` uses `res.body.getReader()` + `TextDecoder` and yields `{event, data: JSON.parse(data)}`), `highlight.ts` (escape first, then apply ordered regexes per language: comments, strings, keywords, numbers; build the output by tokenizing with a single combined regex per language so spans never nest), `nonce.ts`, `dom.ts` (`$`, `$$`, `el()` helpers and `notice(kind, text)` that injects the same markup as `View\Chat\Notice` into `#ab-status`), and `chat.ts` as described. Keep the total under ~600 lines; no libraries.

PHP side for the heartbeat nonce (in `Admin\Assets`):
```php
add_filter('heartbeat_received', static function (array $response, array $data): array {
    if (!empty($data['alpaca_bot_nonce'])) {
        $response['alpaca_bot_nonce'] = wp_create_nonce('wp_rest');
    }
    return $response;
}, 10, 2);
```
and `chat.ts` sends `alpaca_bot_nonce: 1` on `heartbeat-send`.

- [ ] **Step 4: Verify**

Run: `pnpm check` (typecheck + tests + build) — pass; `composer check` — pass.
Real, on alpacabot.wp.test with Ollama running: send "Write a PHP function that adds two numbers": the reply streams token by token, code block gets highlighted with a copy button, receipt appears, history select gains the new conversation; reload restores it; press Escape clears the box; toggle DevTools offline → wifi-off notice, send disabled; back online → enabled. Leave the tab open for 25 hours is not practical, so test nonce refresh by running `wph wp alpacabot -- eval 'update_option("alpaca_bot_test", 1);'` no — instead set `add_filter('nonce_life', fn() => 60)` temporarily via `wph wp alpacabot -- eval-file` in an mu-plugin and confirm the heartbeat swaps the nonce within two minutes and sends keep working.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: chat.ts streaming client with nonce refresh, highlighting, copy, offline handling, image picker"
```

---

### Task 6: Stylesheet rewrite on WP admin variables

**Files:**
- Create: `resources/css/alpaca-bot.css` (replaces the 647-line file's intent)
- Delete: `assets/css/hint.css`, `assets/css/hint.min.css`, `assets/css/materialsymbolsoutlined.css`, `assets/css/materialsymbolsoutlined.woff2`, `assets/css/prism-default*.css`, `assets/js/prism*.js`, `assets/js/htmx.js` (unminified), `assets/js/multi-swap.js`, `assets/js/alpaca-bot.js`, `assets/img/grid.svg`

- [ ] **Step 1: Write the stylesheet** (target under 400 lines) using: `--ab-*` custom properties mapped to WP admin colors (`--wp-admin-theme-color`, `#f0f0f1` surfaces, `#1d2327` text, `#c3c4c7` borders), layout: `.ab-wrap` full-height flex column (`calc(100vh - 32px - 65px)` minus admin bar and header, responsive under `782px`), `.ab-msg` grid (avatar 36px + body), `.ab-msg__content` typography (code blocks `pre` with `background:#1d2327;color:#f0f0f1;overflow:auto;border-radius:4px`, `.tok-*` colors), `.ab-composer` sticky bottom with auto-growing textarea, `.ab-btn--send` states `:hover`, `:focus-visible`, `:disabled` (Kanboard #566), `.ab-icon` `width:1.25em;height:1.25em;vertical-align:-0.25em`, `[data-streaming="1"] .ab-msg__content::after` blinking caret, tooltips via `[aria-label]:hover::after` on `.ab-btn--icon` only, `.ab-welcome`, `.ab-receipt` (`color:#646970;font-size:12px`), reduced-motion media query, and `body.admin-color-*` compatibility by never hardcoding the accent color.
- [ ] **Step 2: Verify** visually on alpacabot.wp.test at 1440px and 390px widths, with the "Modern", "Light", and default admin color schemes; screenshots into `docs/screenshots/2026-xx-p3-*.png` for the PR.
- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "style: rewrite admin stylesheet on WP admin variables; drop hint.css, Material Symbols, prism"
```

---

### Task 7: Delete legacy code and finish the branch state

**Files:**
- Delete: `src/AlpacaBot.php`, `src/Define.php`, `src/Help.php`, `src/ApiDocs.php` (if present), `src/Agents.php`, `src/Agents/*` (P4 recreates as toolkits), `src/Api/*`, `src/Chat/Post.php`, `src/Chat/Screen.php`, `src/Log/Post.php`, `src/Utils/*`, `examples/`, `_org/` (moved to `.wordpress-org/` in P5), `_builds/`
- Modify: `README.md` (usage section describes the new screen), `readme.txt` (description + FAQ mention 1.0 changes), `composer.json` (nothing new)

- [ ] **Step 1: Delete and grep**

```bash
git rm -r src/AlpacaBot.php src/Define.php src/Help.php src/Agents.php src/Agents src/Api src/Chat/Post.php src/Chat/Screen.php src/Log src/Utils examples _builds 2>/dev/null; git rm -r --cached _org 2>/dev/null; true
grep -rn "AlpacaBot\\\\Api\|Utils\\\\Options\|Utils\\\\Settings\|ALPACA_BOT_DIR_URL\|ALPACA_BOT_DIR_PATH\|material-symbols\|hint--" src resources || echo "no legacy references"
```
Expected: `no legacy references`.

- [ ] **Step 2: Help tab** — re-add the three help tabs (Chat, Shortcodes, Support) from the old `Help.php` as `Admin\HelpTabs` on the chat and settings screens (`current_screen` action, `add_help_tab`), with the shortcode text updated for P4's names. Unit test asserts `add_help_tab` is called three times.
- [ ] **Step 3: Verify** `composer check && composer test:integration && pnpm check` and a full manual pass of the chat screen and settings page. Run `wph wp alpacabot -- plugin deactivate alpaca-bot && wph wp alpacabot -- plugin activate alpaca-bot` to confirm activation is clean (no notices in `wp-content/debug.log`).
- [ ] **Step 4: Commit and push**

```bash
git add -A && git commit -m "refactor!: remove legacy Api, Utils, Define, Agents, and asset files" && git push
```
Comment verification (screenshots + command output) on the Kanboard ticket and close it. P4 may start.

---

## Not in this plan

- Toolkits, `[alpacabot]`/`[alpacabot_agent]` shortcodes, abilities, WP AI adapter (P4). Playwright e2e, CI build of assets, `.distignore` (P5). Admin-wide side panel and front-end block (1.1/1.2 spec).

## Self-review

- **Spec coverage (step 5):** View layer + Hx builder (T2, T3), `/view/*` routes (T4), chat.ts streaming + nonce refresh + copy (T5), Lucide sprite (T1), CSS cleanup on WP variables (T6), legacy deletion (T7), bugs #565 (T4), #566 (T6), #613 (T5), #473 (T5), #302 (T5).
- **Placeholders:** none; T3 shows three full components and specifies the rest by contract, T5 and T6 list concrete behaviors and measurements. Both are implementable without inventing interfaces.
- **Type consistency:** `Message` fields from P1; `Receipt` array keys match P1's receipt; `Hx::attrs` keys match usage in `ModelSelect`/`HistorySelect`; `X-Alpaca-Bot-View` marker used consistently in T4 tests and controller; TS `parseFrames` signature identical in test and interface.
