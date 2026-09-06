# Alpaca Bot 1.0 P4: Toolkits, Shortcodes, Abilities, WP AI Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-express the old "agents" as php-agents toolkits behind an `Assistant` agent with a tool loop, restore the `[alpacabot]` and `[alpacabot_agent]` shortcodes on the new pipeline, register the bot's capabilities as WordPress Abilities (so the official MCP Adapter and the WP AI Client can call them), add a `WpAiClientProvider` adapter selectable in settings on WordPress 7.0+, and generate `docs/hooks.md` from docblocks.

**Architecture:** `Toolkit\*` classes implement php-agents `ToolkitInterface` and are collected through the `alpaca_bot/toolkits` filter. `Chat\Assistant extends AbstractAgent` carries instructions from settings and the toolkits; `Chat\Pipeline` gains a tools mode: when the model advertises tool capability and at least one toolkit is enabled, it runs the agent loop (observing `agent.text_delta` to keep streaming) instead of raw `provider->stream()`. Mutating tools only propose (create drafts) and return a confirmation link. `Abilities\Register` wraps chat/summarize/draft-post as abilities when `wp_register_ability()` exists. `Provider\WpAiClientProvider` implements `ProviderInterface` over the core AI client and is chosen when `provider.kind = wp-ai`.

**Tech Stack:** PHP 8.4, php-agents 0.15 (`AbstractAgent`, `Tool`, `ToolResult`, `ToolkitInterface`, `SplObserver` events), WordPress Abilities API (6.9+), WP AI Client (7.0+, `wp-includes/php-ai-client`), Pest + Brain\Monkey, integration suite from P2.

**Spec:** `docs/superpowers/specs/2026-09-05-alpaca-bot-1-0-core-refactor.md` (sequencing steps 6-7). Research: `docs/research/2026-09-05-wp-7-ai.md`. Decisions: Kanboard #2959 (scope), #2955 (hooks), #2970 (MCP outbound via abilities), #2940 (WP AI adapter).

> **Corrections from P1 execution (2026-09-05):** `OllamaProvider` is `final extends OpenAICompatibleProvider` with a hardcoded API key; `provider.api_key` is applied by a `BearerHttpClient` decorator, never by swapping providers (swapping loses `OllamaProvider::formatTools()`, which strips JSON-Schema keywords Ollama rejects, so toolkits in this plan must keep the Ollama provider). `models()` returns `ModelDefinition[]`, not strings. Multimodal input is `UserMessage::withImages(string $text, string[] $paths)`. Verify every php-agents call against `vendor-prefixed/carmelosantana/php-agents/src` before coding.

## Global Constraints

- P1-P3 constraints hold. Branch `1.0`, `composer check`, `composer test:integration`, `pnpm check` green per task.
- Toolkit tools never mutate published content: `draft_post` creates `post_status=draft` only; anything else is out of 1.0 (spec non-goals).
- Every tool call is recorded on the assistant `Message` (`meta['tool_calls'][] = {name, arguments, result_excerpt, ok}`) so receipts and the log show what ran.
- Abilities and the WP AI adapter must degrade silently: no fatal when `wp_register_ability` or the AI client classes are absent; unit tests cover both branches with `function_exists`/`class_exists` stubs.
- wordpress.org guideline 8 (no arbitrary code execution): no tool executes model-generated code; `web_fetch` respects `toolkits.user_agent`, 5 s timeout, 1 MB cap, and blocks private/loopback hosts via `wp_http_validate_url()`.

---

### Task 1: Toolkit contracts, `WebFetchToolkit`, `SummarizeToolkit`, `DraftPostToolkit`

**Files:**
- Create: `src/Toolkit/Registry.php`, `src/Toolkit/WebFetchToolkit.php`, `src/Toolkit/SummarizeToolkit.php`, `src/Toolkit/DraftPostToolkit.php`, `tests/Unit/Toolkit/RegistryTest.php`, `tests/Unit/Toolkit/WebFetchToolkitTest.php`, `tests/Unit/Toolkit/DraftPostToolkitTest.php`
- Modify: `src/Settings/Schema.php` (add `toolkits.enabled` array field, default `['web_fetch', 'summarize', 'draft_post']`, section `toolkits`, rendered as checkboxes in P2's settings page via a `checkbox-list` type handled in `Admin\Fields`), `src/Plugin.php`

**Interfaces:**
```php
namespace AlpacaBot\Toolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
final class Registry {
    public function __construct(private Store $store) {}
    public function register(string $id, ToolkitInterface $toolkit): void;
    /** @return array<string, ToolkitInterface> enabled only; filter alpaca_bot/toolkits (array, int $userId) */ public function enabled(int $userId): array;
    /** @return string[] */ public function ids(): array;
}
final class WebFetchToolkit implements ToolkitInterface { public function __construct(private Store $store, private ?\Closure $http = null) {} }   // tool web_fetch(url:string) → ToolResult::success(text ≤ 8000 chars) | error
final class SummarizeToolkit implements ToolkitInterface { public function __construct(private Pipeline $pipeline, private int $userId) {} }   // tool summarize(text:string, length?:enum[short,medium,long]) → runs pipeline->complete with a system prompt, no history, returns text
final class DraftPostToolkit implements ToolkitInterface { public function __construct(private int $userId) {} }   // tool draft_post(title:string, content:string, post_type?:enum[post,page]) → creates draft with wp_insert_post (author = userId, capability current_user_can('edit_posts')) → ToolResult::json({id, edit_url, status:'draft'})
```

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Toolkit/RegistryTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('returns only enabled toolkits and applies the filter', function (): void {
    Functions\when('get_option')->justReturn(['toolkits.enabled' => ['a']]);
    $a = Mockery::mock(ToolkitInterface::class);
    $b = Mockery::mock(ToolkitInterface::class);
    Filters\expectApplied('alpaca_bot/toolkits')->once()->andReturnFirstArg();
    $r = new Registry(new Store());
    $r->register('a', $a);
    $r->register('b', $b);
    expect($r->enabled(3))->toBe(['a' => $a])->and($r->ids())->toBe(['a', 'b']);
});
```

`tests/Unit/Toolkit/WebFetchToolkitTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Settings\Store;
use AlpacaBot\Toolkit\WebFetchToolkit;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('get_option')->justReturn(['toolkits.user_agent' => 'UA/1']);
    Functions\when('wp_strip_all_tags')->alias(fn(string $s) => strip_tags($s));
    Functions\when('__')->returnArg();
});

it('fetches a public URL with the configured user agent and strips HTML', function (): void {
    Functions\expect('wp_http_validate_url')->once()->with('https://example.test/a')->andReturn('https://example.test/a');
    Functions\expect('wp_safe_remote_get')->once()->withArgs(fn(string $url, array $args): bool => $args['user-agent'] === 'UA/1' && $args['timeout'] === 5 && $args['limit_response_size'] === 1048576)->andReturn(['body' => '<html><body><h1>Hi</h1><script>x</script><p>there</p></body></html>', 'response' => ['code' => 200]]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_body')->alias(fn(array $r) => $r['body']);
    Functions\when('wp_remote_retrieve_response_code')->alias(fn(array $r) => $r['response']['code']);
    $tool = (new WebFetchToolkit(new Store()))->tools()[0];
    expect($tool->name())->toBe('web_fetch');
    $res = $tool->execute(['url' => 'https://example.test/a']);
    expect($res->isError())->toBeFalse()->and($res->content())->toBe("Hi\n\nthere");
});

it('refuses private or invalid urls', function (): void {
    Functions\expect('wp_http_validate_url')->once()->andReturn(false);
    $tool = (new WebFetchToolkit(new Store()))->tools()[0];
    expect($tool->execute(['url' => 'http://127.0.0.1/'])->isError())->toBeTrue();
});
```
(Check `Tool::execute()` and `ToolResult::isError()/content()` accessor names in `vendor-prefixed/carmelosantana/php-agents/src/Tool/` before writing; adjust to the real names.)

`tests/Unit/Toolkit/DraftPostToolkitTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\DraftPostToolkit;
use Brain\Monkey\Functions;

it('creates a draft for the acting user and returns the edit link', function (): void {
    Functions\expect('current_user_can')->with('edit_posts')->andReturn(true);
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_kses_post')->returnArg();
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p): bool => $p['post_status'] === 'draft' && $p['post_author'] === 3 && $p['post_type'] === 'post' && $p['post_title'] === 'T')->andReturn(55);
    Functions\when('get_edit_post_link')->justReturn('https://x/wp-admin/post.php?post=55&action=edit');
    Functions\when('is_wp_error')->justReturn(false);
    $tool = (new DraftPostToolkit(3))->tools()[0];
    $res = $tool->execute(['title' => 'T', 'content' => '<p>Body</p>']);
    expect($res->isError())->toBeFalse()->and(json_decode($res->content(), true))->toMatchArray(['id' => 55, 'status' => 'draft']);
});

it('never publishes and refuses without capability', function (): void {
    Functions\expect('current_user_can')->with('edit_posts')->andReturn(false);
    $tool = (new DraftPostToolkit(3))->tools()[0];
    expect($tool->execute(['title' => 'T', 'content' => 'x', 'post_status' => 'publish'])->isError())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure** — FAIL on missing classes.

- [ ] **Step 3: Implement**

`src/Toolkit/Registry.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

final class Registry
{
    /** @var array<string, ToolkitInterface> */
    private array $toolkits = [];

    public function __construct(private Store $store) {}

    public function register(string $id, ToolkitInterface $toolkit): void
    {
        $this->toolkits[$id] = $toolkit;
    }

    public function ids(): array
    {
        return array_keys($this->toolkits);
    }

    /** @return array<string, ToolkitInterface> */
    public function enabled(int $userId): array
    {
        $enabled = (array) $this->store->get('toolkits.enabled', []);
        $out = array_filter($this->toolkits, static fn(string $id): bool => in_array($id, $enabled, true), ARRAY_FILTER_USE_KEY);
        /** @var array<string, ToolkitInterface> */
        return apply_filters('alpaca_bot/toolkits', $out, $userId);
    }
}
```

`src/Toolkit/WebFetchToolkit.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

final class WebFetchToolkit implements ToolkitInterface
{
    private const MAX_CHARS = 8000;

    public function __construct(private Store $store) {}

    public function tools(): array
    {
        return [new Tool(
            name: 'web_fetch',
            description: 'Fetch a public web page and return its readable text (HTML stripped, truncated to 8000 characters).',
            parameters: [new StringParameter('url', 'Absolute http(s) URL', required: true)],
            callback: fn(array $args): ToolResult => $this->fetch((string) ($args['url'] ?? '')),
        )];
    }

    public function guidelines(): string
    {
        return 'Use web_fetch only for URLs the user provided or clearly asked you to look up. Quote sparingly and cite the URL.';
    }

    private function fetch(string $url): ToolResult
    {
        $safe = wp_http_validate_url($url);
        if ($safe === false) {
            return ToolResult::error(__('That URL is not allowed (private, local, or malformed).', 'alpaca-bot'));
        }
        $res = wp_safe_remote_get($safe, ['user-agent' => (string) $this->store->get('toolkits.user_agent'), 'timeout' => 5, 'redirection' => 3, 'limit_response_size' => 1048576]);
        if (is_wp_error($res)) {
            return ToolResult::error($res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code >= 400) {
            return ToolResult::error(sprintf(__('HTTP %d from %s', 'alpaca-bot'), $code, $safe));
        }
        $html = (string) wp_remote_retrieve_body($res);
        $html = (string) preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#</(p|div|h[1-6]|li|tr|br)>#i', "\n\n", $html);
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", wp_strip_all_tags($html)));
        return ToolResult::success(mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) . '…' : $text);
    }
}
```

`src/Toolkit/DraftPostToolkit.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

final class DraftPostToolkit implements ToolkitInterface
{
    public function __construct(private int $userId) {}

    public function tools(): array
    {
        return [new Tool(
            name: 'draft_post',
            description: 'Create a DRAFT post or page for the user to review. Never publishes.',
            parameters: [
                new StringParameter('title', 'Post title', required: true),
                new StringParameter('content', 'Post body as HTML or Markdown', required: true),
                new EnumParameter('post_type', 'post or page', values: ['post', 'page'], required: false),
            ],
            callback: fn(array $args): ToolResult => $this->draft($args),
        )];
    }

    public function guidelines(): string
    {
        return 'When the user asks to write, create, or draft content for the site, use draft_post so they can review it in the editor. Tell them it is a draft.';
    }

    private function draft(array $args): ToolResult
    {
        if (!current_user_can('edit_posts')) {
            return ToolResult::error(__('You cannot create posts.', 'alpaca-bot'));
        }
        $type = in_array($args['post_type'] ?? 'post', ['post', 'page'], true) ? $args['post_type'] : 'post';
        $id = wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_author' => $this->userId, 'post_title' => sanitize_text_field((string) $args['title']), 'post_content' => wp_kses_post((string) $args['content'])], true);
        if (is_wp_error($id)) {
            return ToolResult::error($id->get_error_message());
        }
        return ToolResult::json(['id' => $id, 'status' => 'draft', 'post_type' => $type, 'edit_url' => (string) get_edit_post_link($id, 'raw')]);
    }
}
```

`SummarizeToolkit` builds a `Tool('summarize', …, [StringParameter text, EnumParameter length])` whose callback calls `$this->pipeline->complete($this->userId, $text, ['system' => "Summarize the following text in a {$length} paragraph. Return only the summary.", 'conversation_id' => 0, 'ephemeral' => true])` — add `ephemeral` handling to `Pipeline::send()` (skip `ConversationStore` create/save when true; usage still recorded) with a unit test.

`Plugin::register()`: `$registry = new Toolkit\Registry($store); $registry->register('web_fetch', new Toolkit\WebFetchToolkit($store)); $registry->register('draft_post', new Toolkit\DraftPostToolkit(get_current_user_id())); $registry->register('summarize', new Toolkit\SummarizeToolkit($this->get(Chat\Pipeline::class), get_current_user_id())); $this->set(Toolkit\Registry::class, $registry);` (the user id is resolved lazily inside the toolkits when 0 at boot: use `get_current_user_id()` at call time instead of construction; adjust constructors to take a `callable(): int` if boot runs before auth).

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: toolkit registry with web_fetch, summarize, and draft_post toolkits"
```

---

### Task 2: `Assistant` agent and the pipeline's tools mode

**Files:**
- Create: `src/Chat/Assistant.php`, `src/Chat/AgentStreamObserver.php`, `tests/Unit/Chat/AssistantTest.php`, `tests/Unit/Chat/PipelineToolsTest.php`
- Modify: `src/Chat/Pipeline.php`, `src/Plugin.php`

**Interfaces:**
```php
namespace AlpacaBot\Chat;
final class Assistant extends \AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Agent\AbstractAgent {
    public function __construct(ProviderInterface $provider, private string $instructionsText, int $maxIter = 6) { parent::__construct($provider, $maxIter); }
    public function instructions(): string;   // instructionsText + toolkit guidelines (AbstractAgent appends? verify in vendored source; if not, append here)
    public function name(): string;           // 'Alpaca Bot'
}
final class AgentStreamObserver implements \SplObserver {
    /** @param callable(Delta):void $onDelta @param callable(array):void $onToolCall */ public function __construct(callable $onDelta, callable $onToolCall) {}
    public function update(\SplSubject $subject, ?string $event = null, mixed $data = null): void;   // 'agent.text_delta' → onDelta(new Delta($data)); 'agent.tool_call'/'agent.tool_result'/'agent.tool_error' → onToolCall([...])
}
```
- `Pipeline::send()` change: after building messages, `$toolkits = $this->toolkits?->enabled($userId) ?? []; $model = $this->catalog->find($modelId); if ($toolkits !== [] && $model?->tools) { run agent } else { stream }`. Agent run: `$agent = new Assistant($provider, $systemText); foreach ($toolkits as $tk) $agent->addToolkit($tk); $agent->attach(new AgentStreamObserver(fn(Delta $d) => yield…))` — generators cannot yield from a callback, so buffer: the observer pushes deltas into a `\SplQueue`; the pipeline runs the agent inside a fiber (`new \Fiber(fn() => $agent->run(new UserMessage($text), $history))`), and after each `Fiber::suspend()` triggered by the observer, drains the queue and yields. The observer calls `\Fiber::suspend()` when inside a fiber. `Output` gives `content`, `usage`, `toolResults`, `iterations`; tool calls recorded into `Message::$meta['tool_calls']`.
- History: `$history = new Conversation()` (php-agents) built from prior `Message`s excluding the just-appended user message.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Chat/PipelineToolsTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\Delta;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Toolkit\Registry;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolCall;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

it('runs the agent loop when toolkits are enabled and the model supports tools, streaming text and recording tool calls', function (): void {
    $calls = 0;
    $provider = Mockery::mock(ProviderInterface::class);
    // First turn: model calls the tool; second turn: final answer. Shape per php-agents: chat() used by the loop; stream() when the loop streams — verify which AbstractAgent::run() calls in the vendored source and mock accordingly.
    $provider->shouldReceive('getModel')->andReturn('llama3.2');
    $provider->shouldReceive('chat')->andReturnUsing(function () use (&$calls): Response {
        $calls++;
        return $calls === 1 ? new Response('', [new ToolCall('c1', 'echo_tool', ['text' => 'ping'])]) : new Response('pong received', usage: new Usage(4, 3, 7));
    });
    $toolkit = new class implements ToolkitInterface {
        public function tools(): array { return [new Tool('echo_tool', 'echo', [new StringParameter('text', 't')], fn(array $a): ToolResult => ToolResult::success('echo:' . $a['text']))]; }
        public function guidelines(): string { return 'Use echo_tool.'; }
    };
    $registry = Mockery::mock(Registry::class);
    $registry->shouldReceive('enabled')->andReturn(['echo' => $toolkit]);
    [$pipeline, $meter] = pipelineWith($provider, [], [], $registry);   // extend the P1 helper with an optional Registry argument
    $meter->shouldReceive('record')->once()->withArgs(fn(int $u, string $m, int $p, int $c): bool => $p === 4 && $c === 3)->andReturn(1);
    $gen = $pipeline->send(3, 'ping the tool');
    $text = '';
    foreach ($gen as $d) { expect($d)->toBeInstanceOf(Delta::class); $text .= $d->text; }
    $r = $gen->getReturn();
    expect($r->reply->content)->toBe('pong received')
        ->and($r->reply->meta['tool_calls'][0])->toMatchArray(['name' => 'echo_tool', 'ok' => true])
        ->and($r->reply->meta['tool_calls'][0]['result_excerpt'])->toBe('echo:ping');
});
```
`tests/Unit/Chat/AssistantTest.php` asserts `instructions()` contains the system text and each toolkit's `guidelines()`, and `name()` is `Alpaca Bot`.

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement** `Assistant`, `AgentStreamObserver`, and the `Pipeline` branch described above (Fiber + SplQueue). Read `vendor-prefixed/carmelosantana/php-agents/src/Agent/AbstractAgent.php` first to confirm: whether `run()` uses `chat()` or `stream()`, the exact `notify()` event names (`agent.text_delta`, `agent.tool_call`, `agent.tool_result`, `agent.tool_error`, `agent.done`), the `Output` fields, and whether toolkit guidelines are auto-appended to the system prompt. Record the answers as comments at the top of `Assistant.php`.

- [ ] **Step 4: Verify green, real check, commit**

Run: `composer check` — pass.
Real (Ollama with a tools-capable model such as `qwen3:8b` or `llama3.1`): in the chat screen ask "Fetch https://wordpress.org/news/ and tell me the latest headline" → the reply streams, the receipt shows a tool badge (add `tool_calls` count to `Receipt` markup: `· 1 tool`), and `wph wp alpacabot -- post meta get <conversation_id> ab_messages` shows `tool_calls` on the assistant message. Ask "Draft a post titled Hello Alpaca with a two-sentence intro" → a draft appears in Posts.
```bash
git add -A && git commit -m "feat: Assistant agent with tool loop streaming through the pipeline"
```

---

### Task 3: Shortcodes `[alpacabot]` and the `[alpacabot_agent]` shim

**Files:**
- Create: `src/Shortcodes/Chat.php`, `src/Shortcodes/AgentShim.php`, `tests/Unit/Shortcodes/ChatTest.php`, `tests/Unit/Shortcodes/AgentShimTest.php`, `tests/Integration/ShortcodesTest.php`
- Modify: `src/Plugin.php`, `src/Admin/Assets.php` (front-end enqueue when the shortcode renders), `readme.txt` (shortcode docs)

**Interfaces:**
- `[alpacabot prompt="…" model="…" system="…" temperature="…" format="text|markdown" cache="1h|off"]` → when `prompt` is given: server-side completion (`Pipeline::complete` with `ephemeral => true`), output cached in a transient keyed by attributes + post id when `cache` is set (default `1h`), rendered as markdown or escaped text; capability: viewer must be logged in with `edit_posts` unless filter `alpaca_bot/shortcode/allow_guests` returns true (guest rendering only serves the cached output, never triggers generation). When `prompt` is absent: renders the chat `Shell` (logged-in `edit_posts` users only in 1.0; shows a login notice otherwise) and enqueues assets.
- `[alpacabot_agent name="get|summarize" url="…" length="…"]` → maps to `web_fetch` (+ `summarize`) through the pipeline with `ephemeral => true`, and emits `_doing_it_wrong()` once per request with the replacement `[alpacabot prompt="Summarize {url}" …]` hint. Same caching and capability rules.

- [ ] **Step 1: Write the failing tests** for: attribute parsing/defaults, cached path returns without calling the pipeline, guest path never generates, shim maps `name=summarize url=…` to a prompt beginning with `Summarize` and calls `_doing_it_wrong`. Integration: `do_shortcode('[alpacabot prompt="x"]')` as an admin with the fake provider from P2 returns rendered text; as a logged-out visitor returns the login notice.
- [ ] **Step 2: Run to verify failure** — FAIL.
- [ ] **Step 3: Implement** both classes; register via `add_shortcode` in `Plugin::register()`; front-end assets enqueued from the shortcode handler with `wp_enqueue_script/style` (same handles as admin).
- [ ] **Step 4: Verify green + real check** (create a page with both shortcodes on alpacabot.wp.test; view logged in and logged out), commit:

```bash
git add -A && git commit -m "feat: [alpacabot] shortcode on the new pipeline and [alpacabot_agent] deprecation shim"
```

---

### Task 4: Abilities API registration

**Files:**
- Create: `src/Abilities/Register.php`, `tests/Unit/Abilities/RegisterTest.php`, `tests/Integration/AbilitiesTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- On `wp_abilities_api_init` (verify the exact hook name in `wp-includes/abilities-api` of the site; fall back to `init` priority 20 if absent), when `function_exists('wp_register_ability')`, register:
  - `alpaca-bot/chat`: input `{message:string, conversation_id?:int, model?:string}`, output `{conversation_id:int, reply:string, receipt:object}`, `permission_callback` → `current_user_can('edit_posts')`, `execute_callback` → `Pipeline::complete`.
  - `alpaca-bot/summarize`: input `{text:string, length?:string}`, output `{summary:string}`.
  - `alpaca-bot/draft-post`: input `{title, content, post_type?}`, output `{id, edit_url}`, permission `edit_posts`.
  - Each with `label`, `description`, `category` `alpaca-bot`, `meta.show_in_rest => true`, `meta.annotations => ['readonly' => false, 'destructive' => false, 'idempotent' => false]`. Filter `alpaca_bot/abilities` on the definitions array.
- `Register::definitions(): array` is pure (testable); `Register::register(): void` calls `wp_register_ability($id, $args)`.

- [ ] **Step 1: Write the failing tests**: `definitions()` returns the three ids with the shapes above; `register()` is a no-op when `function_exists('wp_register_ability')` is false (Brain\Monkey `Functions\when('function_exists')` cannot stub core `function_exists`; instead inject a `callable $exists` dependency defaulting to `'function_exists'`), and calls `wp_register_ability` three times otherwise. Integration (runs only when the site has the Abilities API: `if (!function_exists('wp_register_ability')) $this->markTestSkipped()`): `wp_get_ability('alpaca-bot/chat')` is registered and `->execute(['message' => 'hi'])` returns the fake provider's reply; `GET /wp-abilities/v1/abilities` lists them.
- [ ] **Step 2: Run to verify failure** — FAIL.
- [ ] **Step 3: Implement** per the interface; read the site's `wp-includes/abilities-api/` (or the Abilities API plugin) for the exact `wp_register_ability()` argument keys (`label`, `description`, `input_schema`, `output_schema`, `execute_callback`, `permission_callback`, `meta`) and mirror them.
- [ ] **Step 4: Verify** `composer check && composer test:integration`; real: with the WordPress MCP Adapter plugin installed on alpacabot.wp.test (`wph wp alpacabot -- plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate` or the directory slug if published), `curl -u admin:<app-pw> https://alpacabot.wp.test/wp-json/mcp/mcp -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'` lists `alpaca-bot/chat`. Commit:

```bash
git add -A && git commit -m "feat: register chat, summarize, and draft-post as WordPress abilities"
```

---

### Task 5: `WpAiClientProvider` adapter and provider picker

**Files:**
- Create: `src/Provider/WpAiClientProvider.php`, `src/Provider/WpAiAvailability.php`, `tests/Unit/Provider/WpAiClientProviderTest.php`
- Modify: `src/Provider/Factory.php` (kind `wp-ai`), `src/Settings/Schema.php` (`provider.wp_ai_model` string, shown only when WP AI is available), `src/Admin/SettingsPage.php` (hide the `wp-ai` option when unavailable, description explains), `src/Plugin.php`

**Interfaces:**
```php
namespace AlpacaBot\Provider;
final class WpAiAvailability { public static function available(): bool; /* class_exists('WP_AI_Client_Prompt_Builder') || function_exists('wp_ai_client_prompt') */ public static function providers(): array; /* registry → [id => label] */ }
final class WpAiClientProvider implements ProviderInterface {
    public function __construct(private string $model, private ?\Closure $promptFactory = null) {}   // promptFactory returns the core prompt builder; injectable for tests
    public function chat(array $messages, array $tools = [], array $options = []): Response;   // maps SystemMessage → system instruction, history via with_history(), UserMessage → prompt text/images; temperature via the builder's generation config when supported; returns Response(content, model, usage when available)
    public function stream(array $messages, array $tools = [], array $options = []): iterable;   // core has no WP-level streaming in 7.0: yields a single Response from chat()
    public function structured(array $messages, string $schema, array $options = []): mixed;   // as_json_response(json_decode($schema))
    public function models(): array;      // from the core registry for the configured provider
    public function isAvailable(): bool;  // WpAiAvailability::available()
    public function getModel(): string;   public function withModel(string $model): static;
}
```

- [ ] **Step 1: Write the failing tests** with an injected fake prompt builder (a small anonymous class exposing the same fluent methods the adapter calls: `using_system_instruction`, `with_history`, `using_model`, `generate_text`, `as_json_response`), asserting message mapping, single-chunk `stream()`, `withModel()` immutability, and that `chat()` throws a `ProviderException` (php-agents) when the client is unavailable.
- [ ] **Step 2: Run to verify failure** — FAIL.
- [ ] **Step 3: Implement**. Before writing, read `wp-includes/php-ai-client/` and `wp-includes/ai-client.php` on alpacabot.wp.test (WP 7.x) and copy the real method names into the adapter; the research doc (`docs/research/2026-09-05-wp-7-ai.md` § 3) lists `wp_ai_client_prompt()`, `WP_AI_Client_Prompt_Builder`, `with_history()`, `as_json_response()`, `using_abilities()`. `Factory::make()` returns `new WpAiClientProvider($this->store->get('provider.wp_ai_model'))` when `provider.kind === 'wp-ai'` and `WpAiAvailability::available()`, else falls back to Ollama with an admin notice.
- [ ] **Step 4: Verify** `composer check`; real: install a core provider plugin on alpacabot.wp.test (`ai-provider-for-ollama` from the directory, configured to the same Ollama), switch Settings → Provider to "WordPress AI provider", chat: reply arrives (non-streamed, one chunk), receipt present. Switch back to Ollama: streaming resumes. Commit:

```bash
git add -A && git commit -m "feat: WP AI Client provider adapter selectable when WordPress 7.0+ providers exist"
```

---

### Task 6: Hooks reference generated from docblocks

**Files:**
- Create: `bin/hooks-doc.php`, `docs/hooks.md`
- Modify: every `apply_filters`/`do_action` call site in `src/` gets a docblock immediately above it in the WordPress style:
  ```php
  /**
   * Filters the provider used for a chat turn.
   *
   * @since 1.0.0
   * @param ProviderInterface $provider Provider built from settings.
   * @param string            $model    Model id.
   * @param Store             $store    Settings.
   */
  $provider = apply_filters('alpaca_bot/provider', $provider, $model, $this->store);
  ```
- `bin/hooks-doc.php` scans `src/**/*.php` with a regex for `(apply_filters|do_action)\('alpaca_bot/[^']+'` preceded by a docblock, and writes `docs/hooks.md` (table of hook, type, params, file:line, description). `composer docs:hooks` runs it; CI (P5) fails if the output differs from the committed file.
- Expected hook list (must all appear): `alpaca_bot/provider`, `alpaca_bot/models`, `alpaca_bot/system_prompt`, `alpaca_bot/message/before_send`, `alpaca_bot/message/after_receive`, `alpaca_bot/chat/started`, `alpaca_bot/chat/completed`, `alpaca_bot/chat/failed`, `alpaca_bot/usage/recorded`, `alpaca_bot/cap/allowed`, `alpaca_bot/context`, `alpaca_bot/context/sources`, `alpaca_bot/toolkits`, `alpaca_bot/capability/{route}`, `alpaca_bot/rate_limit`, `alpaca_bot/rest/controllers`, `alpaca_bot/render/allowed_tags`, `alpaca_bot/admin/menu_capability`, `alpaca_bot/abilities`, `alpaca_bot/shortcode/allow_guests`.

- [ ] **Step 1:** write `bin/hooks-doc.php` and a Pest test that runs it against a fixture file and checks the table row.
- [ ] **Step 2:** add docblocks, run `composer docs:hooks`, confirm every hook above is present.
- [ ] **Step 3:** commit and push:

```bash
git add -A && git commit -m "docs: hooks reference generated from docblocks" && git push
```
Comment verification on the Kanboard ticket; close it. P5 may start.

---

## Not in this plan

- MCP client toolkit (1.1), admin-wide panel (1.1), front-end block with guests (1.2), Site Knowledge add-on (RAG) and licensing: `2026-09-05-alpaca-bot-surfaces-and-extensions.md`.
- Connectors API credential use: opt-in only; not implemented in 1.0 beyond the WP AI provider adapter reading whatever the core registry has.

## Self-review

- **Spec coverage (steps 6-7):** toolkits (T1), agent loop + streaming (T2), shortcodes + shim (T3), abilities (T4), WP AI adapter + picker (T5), hooks docs (T6). Governance additions: tool calls recorded on messages (T2), draft-only writes (T1).
- **Placeholders:** none. Three tasks require reading vendored/core source for exact names before coding; each names the file and what to look for.
- **Type consistency:** `ToolResult::success/error/json` per php-agents; `Registry::enabled(int)` used in T2's pipeline branch; `Pipeline::complete(...['ephemeral' => true])` introduced in T1 and reused in T3/T4; `Delta`/`Result`/`Message` from P1.
