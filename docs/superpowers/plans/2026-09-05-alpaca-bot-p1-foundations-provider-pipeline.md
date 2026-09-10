# Alpaca Bot 1.0 P1: Foundations, Provider, Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a `1.0` branch, replace the plugin's hand-rolled Ollama client with php-agents behind a settings schema, a conversation store, a usage meter with caps, and a message pipeline that streams, verified end to end with a WP-CLI command against `alpacabot.wp.test`.

**Architecture:** `alpaca-bot.php` guards PHP 8.4, loads a strauss-prefixed `vendor-prefixed/` autoloader, and boots `AlpacaBot\Plugin`, which wires services in one place. Settings live in a single option `alpaca_bot_settings` validated by a schema array (one SQL read). `Provider\Factory` builds a php-agents `ProviderInterface` from settings (Ollama by default, filterable). `Chat\Pipeline` turns a user message into provider messages (system prompt + context sources), enforces `Chat\CapPolicy`, streams deltas from `provider->stream()`, and persists to `Chat\ConversationStore` (CPT `chat_history`) and `Chat\UsageMeter` (CPT `chat_log`). Legacy classes stay on disk but are no longer booted; P3 deletes them.

**Tech Stack:** PHP 8.4, WordPress 6.9+/7.x, `carmelosantana/php-agents ^0.15`, `league/commonmark ^2.10` (used in P3, installed now), `brianhenryie/strauss ^0.29` (prefix `AlpacaBot\Vendor\`), Pest 5 + Brain\Monkey 2.7 + Mockery, PHPStan 2 + `szepeviktor/phpstan-wordpress`, Composer 2.10. Site: `alpacabot.wp.test` from the WP Harness (Kanboard #2969).

**Spec:** `docs/superpowers/specs/2026-09-05-alpaca-bot-1-0-core-refactor.md` (sequencing steps 1-2). Research: `docs/research/2026-09-05-wp-7-ai.md`, `docs/research/2026-09-05-interactive-layer.md`.

## Global Constraints

- PHP floor **8.4**; plugin header `Requires PHP: 8.4`, `Requires at least: 6.9`. `declare(strict_types=1)` in every file.
- Runtime composer deps: `carmelosantana/php-agents`, `league/commonmark` only. All vendor code is prefixed by strauss into `vendor-prefixed/` under namespace `AlpacaBot\Vendor\`; the plugin never autoloads `vendor/` at runtime.
- Namespace `AlpacaBot\`, PSR-4 from `src/`. Text domain `alpaca-bot`. Option name `alpaca_bot_settings`. Hooks are slash-namespaced `alpaca_bot/...`.
- No new UI in P1. The admin chat page is intentionally absent until P3; `wp alpaca-bot chat` is the P1 user surface.
- Tests: `composer test` (Pest unit, Brain\Monkey), `composer analyse` (PHPStan level 6), both green at the end of every task. Integration tests arrive in P2.
- Work on branch `1.0` in the alpaca-bot repo; push the branch; open a draft PR titled "1.0" after Task 1 and keep it updated. Conventional commit prefixes.
- Kanboard is the tracker; ticket ids are Kanboard tasks (this plan seeds one ticket on project 17). Never GitHub-issue-link `#N`.
- Real-world verification runs against `https://alpacabot.wp.test` via `wph wp alpacabot -- <args>` (WP Harness, Kanboard #2973 Task 13) with Ollama at `http://host.docker.internal:11434` from inside the container.

---

### Task 1: Branch, composer, prefixing, bootstrap, test scaffold

**Files:**
- Modify: `composer.json`, `alpaca-bot.php`, `.gitignore`, `readme.txt` (header only), `README.md` (requirements line)
- Create: `src/Plugin.php`, `tests/Pest.php`, `tests/Unit/PluginTest.php`, `phpstan.neon.dist`, `phpunit.xml` (Pest reads it), `bin/build-vendor.sh`
- Delete: `composer.lock` from `.gitignore` (the lockfile is now committed)

**Interfaces:**
- Produces: `AlpacaBot\Plugin` with `public static function boot(): self`, `public static function instance(): self`, `public const VERSION = '1.0.0-dev'`, `public function version(): string`, and a `public function register(): void` that adds WP hooks. Later tasks add services to `Plugin::register()`.

- [ ] **Step 1: Branch and lockfile policy**

```bash
cd "/home/carmelo/Projects/Alpaca Bot/wp-alpaca/plugins/alpaca-bot" && git checkout -b 1.0
sed -i '/^composer.lock$/d' .gitignore && printf 'vendor-prefixed/\nnode_modules/\n' >> .gitignore
```

- [ ] **Step 2: composer.json**

Replace the file with:
```json
{
    "name": "carmelosantana/alpaca-bot",
    "description": "A privately hosted WordPress AI chatbot powered by php-agents.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "authors": [{ "name": "Carmelo Santana", "email": "me@carmelosantana.com" }],
    "require": {
        "php": "^8.4",
        "carmelosantana/php-agents": "^0.15.2",
        "league/commonmark": "^2.10"
    },
    "require-dev": {
        "brain/monkey": "^2.7",
        "brianhenryie/strauss": "^0.29",
        "mockery/mockery": "^1.6",
        "pestphp/pest": "^5.1",
        "php-stubs/wordpress-stubs": "^7.1",
        "phpstan/phpstan": "^2.2",
        "szepeviktor/phpstan-wordpress": "^2.0"
    },
    "autoload": { "psr-4": { "AlpacaBot\\": "src/" } },
    "autoload-dev": { "psr-4": { "AlpacaBot\\Tests\\": "tests/" } },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true,
        "allow-plugins": { "pestphp/pest-plugin": true }
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor-prefixed",
            "namespace_prefix": "AlpacaBot\\Vendor\\",
            "classmap_prefix": "AlpacaBot_Vendor_",
            "constant_prefix": "ALPACA_BOT_VENDOR_",
            "packages": ["carmelosantana/php-agents", "league/commonmark"],
            "delete_vendor_packages": false,
            "exclude_from_prefix": { "file_patterns": [] }
        }
    },
    "scripts": {
        "prefix": "strauss",
        "post-install-cmd": ["@prefix"],
        "post-update-cmd": ["@prefix"],
        "test": "pest --colors=always",
        "analyse": "phpstan analyse --memory-limit=1G",
        "check": ["@analyse", "@test"]
    }
}
```

Run the supply-chain skill check (`/powerup:supply-chain`) on the new dependency set, then:
```bash
composer update --no-interaction
ls vendor-prefixed/carmelosantana/php-agents/src/Contract/ProviderInterface.php && grep -m1 '^namespace' vendor-prefixed/carmelosantana/php-agents/src/Contract/ProviderInterface.php
```
Expected: `namespace AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract;` and a `vendor-prefixed/autoload.php`.

- [ ] **Step 3: Write the failing test**

`tests/Pest.php`:
```php
<?php

declare(strict_types=1);

use Brain\Monkey;

uses()->beforeEach(function (): void {
    Monkey\setUp();
})->afterEach(function (): void {
    Monkey\tearDown();
    Mockery::close();
})->in('Unit');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('ALPACA_BOT_FILE')) {
    define('ALPACA_BOT_FILE', dirname(__DIR__) . '/alpaca-bot.php');
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor-prefixed/autoload.php';
```

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true" cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    </testsuites>
    <source><include><directory>src</directory></include></source>
</phpunit>
```

`tests/Unit/PluginTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use Brain\Monkey\Actions;

it('exposes a version and boots once', function (): void {
    Actions\expectAdded('plugins_loaded')->once();
    $a = Plugin::boot();
    $b = Plugin::boot();
    expect($a)->toBe($b)
        ->and($a->version())->toBe(Plugin::VERSION)
        ->and(Plugin::VERSION)->toMatch('/^1\.0\.0/');
});
```

- [ ] **Step 4: Run to verify failure**

Run: `composer test`
Expected: FAIL, `Class "AlpacaBot\Plugin" not found`.

- [ ] **Step 5: Bootstrap and Plugin**

`alpaca-bot.php`:
```php
<?php
/*
Plugin Name: Alpaca Bot
Plugin URI: https://github.com/carmelosantana/alpaca-bot
Description: A privately hosted WordPress AI chatbot. Chat with your own models, ground answers in your site, and keep control of cost.
Version: 1.0.0-dev
Author: Carmelo Santana
Author URI: https://carmelosantana.com/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: alpaca-bot
Requires at least: 6.9
Requires PHP: 8.4
*/

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (PHP_VERSION_ID < 80400) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Alpaca Bot 1.0 requires PHP 8.4 or newer. The plugin is inactive.', 'alpaca-bot') . '</p></div>';
    });
    return;
}

define('ALPACA_BOT_FILE', __FILE__);
define('ALPACA_BOT_DIR', plugin_dir_path(__FILE__));
define('ALPACA_BOT_URL', plugin_dir_url(__FILE__));

$autoload = ALPACA_BOT_DIR . 'vendor-prefixed/autoload.php';
$psr4 = ALPACA_BOT_DIR . 'vendor/autoload.php';
if (!is_readable($autoload) || !is_readable($psr4)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Alpaca Bot: run composer install (dev checkout) or reinstall the release zip.', 'alpaca-bot') . '</p></div>';
    });
    return;
}
require_once $psr4;
require_once $autoload;

\AlpacaBot\Plugin::boot();
```
(The release zip ships `vendor/autoload.php` restricted to the plugin's own PSR-4 map plus `vendor-prefixed/`; P5 builds it with `composer install --no-dev` then strauss.)

`src/Plugin.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot;

final class Plugin
{
    public const VERSION = '1.0.0-dev';
    public const OPTION = 'alpaca_bot_settings';
    public const TEXT_DOMAIN = 'alpaca-bot';

    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct() {}

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            add_action('plugins_loaded', [self::$instance, 'register'], 9);
        }
        return self::$instance;
    }

    public static function instance(): self
    {
        return self::$instance ?? self::boot();
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function register(): void
    {
        // Services are attached here by later tasks, e.g. $this->set(Settings\Store::class, new Settings\Store());
    }

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    /** @template T of object @param class-string<T> $id @return T */
    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Alpaca Bot service not registered: {$id}");
        }
        /** @var T */
        return $this->services[$id];
    }
}
```

`phpstan.neon.dist`:
```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon
parameters:
    level: 6
    paths: [src]
    scanFiles: [alpaca-bot.php]
    scanDirectories: [vendor-prefixed]
    bootstrapFiles: [vendor/php-stubs/wordpress-stubs/wordpress-stubs.php]
```

`bin/build-vendor.sh`:
```bash
#!/usr/bin/env bash
# Rebuild vendor-prefixed/ from a clean production install (what the release zip ships).
set -euo pipefail
cd "$(dirname "$0")/.."
composer install --no-dev --no-interaction --optimize-autoloader
composer prefix
composer dump-autoload --no-dev --optimize
```
`chmod +x bin/build-vendor.sh`.

- [ ] **Step 6: Verify green**

Run: `composer check`
Expected: PHPStan `No errors`; Pest `1 passed`.

- [ ] **Step 7: Headers, README line, commit, draft PR**

`readme.txt` header: `Requires at least: 6.9`, `Requires PHP: 8.4`, `Stable tag: 0.4.17` (unchanged until release). `README.md` Requirements: `PHP 8.4+, WordPress 6.9+, an Ollama instance (or any provider php-agents supports)`.
```bash
git add -A && git commit -m "feat!: 1.0 bootstrap on PHP 8.4 with php-agents, strauss prefixing, and Pest scaffold"
git push -u origin 1.0
gh pr create --draft --base main --head 1.0 --title "Alpaca Bot 1.0" --body "Tracking PR for the 1.0 rewrite. Spec: docs/superpowers/specs/2026-09-05-alpaca-bot-1-0-core-refactor.md. Kanboard project 17."
```

---

### Task 2: Settings schema, store, and 0.4 migration

**Files:**
- Create: `src/Settings/Schema.php`, `src/Settings/Store.php`, `src/Settings/Migrate04.php`, `tests/Unit/Settings/SchemaTest.php`, `tests/Unit/Settings/StoreTest.php`, `tests/Unit/Settings/Migrate04Test.php`
- Modify: `src/Plugin.php` (`register()` attaches `Settings\Store` and runs migration on `admin_init` once)

**Interfaces:**
- Produces:
  ```php
  namespace AlpacaBot\Settings;
  final class Schema {
      /** @return array<string, array{type:'string'|'integer'|'number'|'boolean'|'array'|'select', default:mixed, section:string, label:string, description?:string, options?:array<string,string>, min?:int|float, max?:int|float, sanitize?:callable}> */
      public static function fields(): array;
      /** @return array<string, array{label:string, description:string}> */
      public static function sections(): array;
      public static function defaults(): array;                 // key => default
      public static function sanitize(array $input): array;     // full, validated settings array; unknown keys dropped
  }
  final class Store {
      public function __construct(private ?array $cache = null) {}
      public function all(): array;                             // defaults merged with option (one get_option call, memoized)
      public function get(string $key, mixed $default = null): mixed;   // dotted keys: 'provider.base_url'
      public function set(string $key, mixed $value): void;     // updates option
      public function replace(array $settings): void;
      public function modelOverrides(string $model): array;     // per-model overrides merged over global model options
  }
  final class Migrate04 {
      public const FLAG = 'alpaca_bot_migrated_04';
      public function __construct(private Store $store) {}
      public function needed(): bool;
      public function run(): array;                             // returns the migrated settings
  }
  ```
- Settings keys (dotted): `provider.kind` (`ollama`|`wp-ai`, default `ollama`), `provider.base_url` (default `http://localhost:11434/v1`), `provider.api_key` (string, default ''), `provider.timeout` (int, 60), `models.default` (string, ''), `models.temperature` (number, 0.7), `models.num_ctx` (int, 8192), `models.keep_alive` (string, `5m`), `models.overrides` (array: model => {temperature?, num_ctx?, keep_alive?, system?}), `chat.system_prompt` (string), `chat.welcome` (string), `chat.placeholder` (string), `chat.user_can_change_model` (bool, true), `chat.history_limit` (int, 20), `chat.spellcheck` (bool, true), `chat.assistant_avatar` (string URL), `privacy.save_history` (bool, true), `privacy.usage_log` (bool, true), `governance.site_monthly_tokens` (int, 0 = unlimited), `governance.user_monthly_tokens` (int, 0), `toolkits.user_agent` (string, `AlpacaBot/0.5 (+https://github.com/carmelosantana/alpaca-bot)`).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Settings/SchemaTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Settings\Schema;

it('has defaults for every field and a section for each', function (): void {
    $fields = Schema::fields();
    $sections = Schema::sections();
    expect($fields)->toHaveKeys(['provider.kind', 'provider.base_url', 'models.default', 'models.temperature', 'models.num_ctx', 'models.keep_alive', 'models.overrides', 'chat.system_prompt', 'chat.history_limit', 'privacy.save_history', 'privacy.usage_log', 'governance.site_monthly_tokens', 'governance.user_monthly_tokens', 'toolkits.user_agent']);
    foreach ($fields as $key => $f) {
        expect($f)->toHaveKeys(['type', 'default', 'section', 'label'], $key);
        expect($sections)->toHaveKey($f['section'], $key);
    }
    expect(Schema::defaults()['provider.base_url'])->toBe('http://localhost:11434/v1')
        ->and(Schema::defaults()['models.temperature'])->toBe(0.7);
});

it('sanitizes by type, clamps ranges, and drops unknown keys', function (): void {
    $out = Schema::sanitize([
        'provider.base_url' => ' http://ollama:11434/v1/ ',
        'models.temperature' => '9',
        'models.num_ctx' => '-5',
        'chat.user_can_change_model' => '0',
        'provider.kind' => 'bogus',
        'models.overrides' => ['llama3.2' => ['temperature' => '0.2', 'junk' => 1]],
        'nope' => 'x',
    ]);
    expect($out['provider.base_url'])->toBe('http://ollama:11434/v1')
        ->and($out['models.temperature'])->toBe(2.0)
        ->and($out['models.num_ctx'])->toBe(512)
        ->and($out['chat.user_can_change_model'])->toBeFalse()
        ->and($out['provider.kind'])->toBe('ollama')
        ->and($out['models.overrides'])->toBe(['llama3.2' => ['temperature' => 0.2]])
        ->and($out)->not->toHaveKey('nope')
        ->and($out['chat.history_limit'])->toBe(20);
});
```

`tests/Unit/Settings/StoreTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

it('reads the option once and merges defaults', function (): void {
    Functions\expect('get_option')->once()->with(Plugin::OPTION, [])->andReturn(['models.default' => 'llama3.2']);
    $s = new Store();
    expect($s->get('models.default'))->toBe('llama3.2')
        ->and($s->get('models.temperature'))->toBe(0.7)
        ->and($s->get('missing', 'dflt'))->toBe('dflt');
    $s->all();
});

it('set writes the sanitized full array', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('update_option')->once()->withArgs(function (string $name, array $value): bool {
        return $name === Plugin::OPTION && $value['models.num_ctx'] === 4096 && $value['provider.kind'] === 'ollama';
    })->andReturn(true);
    (new Store())->set('models.num_ctx', '4096');
});

it('modelOverrides merges per-model values over globals', function (): void {
    Functions\when('get_option')->justReturn(['models.temperature' => 0.5, 'models.overrides' => ['qwen3:8b' => ['temperature' => 0.1, 'num_ctx' => 32768]]]);
    $s = new Store();
    expect($s->modelOverrides('qwen3:8b'))->toBe(['temperature' => 0.1, 'num_ctx' => 32768, 'keep_alive' => '5m'])
        ->and($s->modelOverrides('other'))->toBe(['temperature' => 0.5, 'num_ctx' => 8192, 'keep_alive' => '5m']);
});
```

`tests/Unit/Settings/Migrate04Test.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

it('maps 0.4 options into the new schema and appends /v1 to the base url', function (): void {
    $legacy = [
        'alpaca_bot_api_url' => 'http://host.docker.internal:11434',
        'alpaca_bot_default_model' => 'llama3.2',
        'alpaca_bot_default_system' => 'You are helpful.',
        'alpaca_bot_default_temperature' => '0.3',
        'alpaca_bot_default_num_ctx' => '4096',
        'alpaca_bot_chat_history_save' => '1',
        'alpaca_bot_chat_response_log' => '',
        'alpaca_bot_chat_history_limit' => '10',
        'alpaca_bot_user_can_change_model' => '1',
        'alpaca_bot_user_agent' => 'Custom UA',
        'alpaca_bot_default_assistant_welcome_message' => 'Hi!',
    ];
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $legacy[$k] ?? ($k === 'alpaca_bot_settings' ? [] : $d));
    $written = null;
    Functions\when('update_option')->alias(function (string $k, mixed $v) use (&$written): bool { if ($k === 'alpaca_bot_settings') { $written = $v; } return true; });
    $m = new Migrate04(new Store());
    expect($m->needed())->toBeTrue();
    $out = $m->run();
    expect($out['provider.base_url'])->toBe('http://host.docker.internal:11434/v1')
        ->and($out['models.default'])->toBe('llama3.2')
        ->and($out['chat.system_prompt'])->toBe('You are helpful.')
        ->and($out['models.temperature'])->toBe(0.3)
        ->and($out['models.num_ctx'])->toBe(4096)
        ->and($out['privacy.save_history'])->toBeTrue()
        ->and($out['privacy.usage_log'])->toBeFalse()
        ->and($out['chat.history_limit'])->toBe(10)
        ->and($out['toolkits.user_agent'])->toBe('Custom UA')
        ->and($out['chat.welcome'])->toBe('Hi!')
        ->and($written)->toBe($out);
});

it('is not needed when the flag is set or no legacy option exists', function (): void {
    Functions\when('get_option')->alias(fn(string $k, mixed $d = false) => $k === Migrate04::FLAG ? '1' : $d);
    expect((new Migrate04(new Store()))->needed())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test` — Expected: FAIL, classes not found.

- [ ] **Step 3: Implement `Schema`**

`src/Settings/Schema.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

final class Schema
{
    public static function sections(): array
    {
        return [
            'provider' => ['label' => __('Provider', 'alpaca-bot'), 'description' => __('Where models run. Ollama by default; WordPress AI providers when WordPress 7.0+ has them registered.', 'alpaca-bot')],
            'models' => ['label' => __('Models', 'alpaca-bot'), 'description' => __('Default model and generation options, with per-model overrides.', 'alpaca-bot')],
            'chat' => ['label' => __('Chat', 'alpaca-bot'), 'description' => __('What users see and can change in the chat screen.', 'alpaca-bot')],
            'privacy' => ['label' => __('Privacy', 'alpaca-bot'), 'description' => __('What is stored in your database.', 'alpaca-bot')],
            'governance' => ['label' => __('Limits', 'alpaca-bot'), 'description' => __('Server-enforced monthly token caps. 0 means unlimited.', 'alpaca-bot')],
            'toolkits' => ['label' => __('Tools', 'alpaca-bot'), 'description' => __('Settings for built-in tools.', 'alpaca-bot')],
        ];
    }

    public static function fields(): array
    {
        return [
            'provider.kind' => ['type' => 'select', 'default' => 'ollama', 'section' => 'provider', 'label' => __('Provider', 'alpaca-bot'), 'options' => ['ollama' => 'Ollama', 'wp-ai' => __('WordPress AI provider', 'alpaca-bot')]],
            'provider.base_url' => ['type' => 'string', 'default' => 'http://localhost:11434/v1', 'section' => 'provider', 'label' => __('Base URL', 'alpaca-bot'), 'description' => __('OpenAI-compatible endpoint. For Ollama this ends in /v1.', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeUrl']],
            'provider.api_key' => ['type' => 'string', 'default' => '', 'section' => 'provider', 'label' => __('API key', 'alpaca-bot'), 'description' => __('Optional. Sent as a Bearer token.', 'alpaca-bot')],
            'provider.timeout' => ['type' => 'integer', 'default' => 60, 'section' => 'provider', 'label' => __('Timeout (seconds)', 'alpaca-bot'), 'min' => 5, 'max' => 600],
            'models.default' => ['type' => 'string', 'default' => '', 'section' => 'models', 'label' => __('Default model', 'alpaca-bot')],
            'models.temperature' => ['type' => 'number', 'default' => 0.7, 'section' => 'models', 'label' => __('Temperature', 'alpaca-bot'), 'min' => 0, 'max' => 2],
            'models.num_ctx' => ['type' => 'integer', 'default' => 8192, 'section' => 'models', 'label' => __('Context window (tokens)', 'alpaca-bot'), 'min' => 512, 'max' => 1048576],
            'models.keep_alive' => ['type' => 'string', 'default' => '5m', 'section' => 'models', 'label' => __('Keep alive', 'alpaca-bot'), 'description' => __('How long Ollama keeps the model loaded, e.g. 5m, 1h, -1.', 'alpaca-bot')],
            'models.overrides' => ['type' => 'array', 'default' => [], 'section' => 'models', 'label' => __('Per-model overrides', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeOverrides']],
            'chat.system_prompt' => ['type' => 'string', 'default' => '', 'section' => 'chat', 'label' => __('System prompt', 'alpaca-bot')],
            'chat.welcome' => ['type' => 'string', 'default' => __('How can I help?', 'alpaca-bot'), 'section' => 'chat', 'label' => __('Welcome message', 'alpaca-bot')],
            'chat.placeholder' => ['type' => 'string', 'default' => __('Message Alpaca Bot', 'alpaca-bot'), 'section' => 'chat', 'label' => __('Input placeholder', 'alpaca-bot')],
            'chat.user_can_change_model' => ['type' => 'boolean', 'default' => true, 'section' => 'chat', 'label' => __('Users can change model', 'alpaca-bot')],
            'chat.history_limit' => ['type' => 'integer', 'default' => 20, 'section' => 'chat', 'label' => __('Conversations shown in history', 'alpaca-bot'), 'min' => 1, 'max' => 200],
            'chat.spellcheck' => ['type' => 'boolean', 'default' => true, 'section' => 'chat', 'label' => __('Spellcheck the input', 'alpaca-bot')],
            'chat.assistant_avatar' => ['type' => 'string', 'default' => '', 'section' => 'chat', 'label' => __('Assistant avatar URL', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeUrl']],
            'privacy.save_history' => ['type' => 'boolean', 'default' => true, 'section' => 'privacy', 'label' => __('Save conversations', 'alpaca-bot')],
            'privacy.usage_log' => ['type' => 'boolean', 'default' => true, 'section' => 'privacy', 'label' => __('Keep a usage log (tokens, model, duration)', 'alpaca-bot')],
            'governance.site_monthly_tokens' => ['type' => 'integer', 'default' => 0, 'section' => 'governance', 'label' => __('Site-wide monthly token cap', 'alpaca-bot'), 'min' => 0, 'max' => PHP_INT_MAX],
            'governance.user_monthly_tokens' => ['type' => 'integer', 'default' => 0, 'section' => 'governance', 'label' => __('Per-user monthly token cap', 'alpaca-bot'), 'min' => 0, 'max' => PHP_INT_MAX],
            'toolkits.user_agent' => ['type' => 'string', 'default' => 'AlpacaBot/0.5 (+https://github.com/carmelosantana/alpaca-bot)', 'section' => 'toolkits', 'label' => __('User agent for fetch tools', 'alpaca-bot')],
        ];
    }

    public static function defaults(): array
    {
        return array_map(static fn(array $f): mixed => $f['default'], self::fields());
    }

    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::fields() as $key => $f) {
            $raw = array_key_exists($key, $input) ? $input[$key] : $f['default'];
            $out[$key] = isset($f['sanitize']) ? ($f['sanitize'])($raw, $f) : self::coerce($raw, $f);
        }
        return $out;
    }

    private static function coerce(mixed $raw, array $f): mixed
    {
        switch ($f['type']) {
            case 'boolean':
                return in_array($raw, [true, 1, '1', 'true', 'on', 'yes'], true);
            case 'integer':
                $v = (int) $raw;
                return max((int) ($f['min'] ?? PHP_INT_MIN), min((int) ($f['max'] ?? PHP_INT_MAX), $v));
            case 'number':
                $v = (float) $raw;
                return max((float) ($f['min'] ?? -INF), min((float) ($f['max'] ?? INF), $v));
            case 'select':
                return array_key_exists((string) $raw, $f['options']) ? (string) $raw : $f['default'];
            case 'array':
                return is_array($raw) ? $raw : $f['default'];
            case 'string':
            default:
                return is_scalar($raw) ? trim((string) $raw) : $f['default'];
        }
    }

    public static function sanitizeUrl(mixed $raw, array $f): string
    {
        $v = is_scalar($raw) ? rtrim(trim((string) $raw), '/') : '';
        return $v === '' ? (string) $f['default'] : $v;
    }

    public static function sanitizeOverrides(mixed $raw, array $f): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $allowed = ['temperature' => 'number', 'num_ctx' => 'integer', 'keep_alive' => 'string', 'system' => 'string'];
        $out = [];
        foreach ($raw as $model => $opts) {
            if (!is_string($model) || $model === '' || !is_array($opts)) {
                continue;
            }
            $clean = [];
            foreach ($allowed as $k => $type) {
                if (!array_key_exists($k, $opts)) {
                    continue;
                }
                $clean[$k] = match ($type) {
                    'number' => max(0.0, min(2.0, (float) $opts[$k])),
                    'integer' => max(512, (int) $opts[$k]),
                    default => trim((string) $opts[$k]),
                };
            }
            if ($clean !== []) {
                $out[$model] = $clean;
            }
        }
        return $out;
    }
}
```

- [ ] **Step 4: Implement `Store` and `Migrate04`**

`src/Settings/Store.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

use AlpacaBot\Plugin;

final class Store
{
    public function __construct(private ?array $cache = null) {}

    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(Plugin::OPTION, []);
            $this->cache = array_merge(Schema::defaults(), is_array($stored) ? $stored : []);
        }
        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $next = $this->all();
        $next[$key] = $value;
        $this->replace($next);
    }

    public function replace(array $settings): void
    {
        $clean = Schema::sanitize($settings);
        update_option(Plugin::OPTION, $clean);
        $this->cache = $clean;
    }

    public function modelOverrides(string $model): array
    {
        $global = ['temperature' => (float) $this->get('models.temperature'), 'num_ctx' => (int) $this->get('models.num_ctx'), 'keep_alive' => (string) $this->get('models.keep_alive')];
        $over = $this->get('models.overrides', []);
        return array_merge($global, is_array($over[$model] ?? null) ? $over[$model] : []);
    }
}
```

`src/Settings/Migrate04.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

final class Migrate04
{
    public const FLAG = 'alpaca_bot_migrated_04';

    /** @var array<string, string> legacy option (without prefix) => new dotted key */
    private const MAP = [
        'api_url' => 'provider.base_url',
        'api_password' => 'provider.api_key',
        'ollama_timeout' => 'provider.timeout',
        'default_model' => 'models.default',
        'default_temperature' => 'models.temperature',
        'default_num_ctx' => 'models.num_ctx',
        'default_system' => 'chat.system_prompt',
        'default_assistant_welcome_message' => 'chat.welcome',
        'default_assistant_prompt_placeholder' => 'chat.placeholder',
        'user_can_change_model' => 'chat.user_can_change_model',
        'chat_history_limit' => 'chat.history_limit',
        'spellcheck' => 'chat.spellcheck',
        'default_avatar' => 'chat.assistant_avatar',
        'chat_history_save' => 'privacy.save_history',
        'chat_response_log' => 'privacy.usage_log',
        'user_agent' => 'toolkits.user_agent',
    ];

    public function __construct(private Store $store) {}

    public function needed(): bool
    {
        if (get_option(self::FLAG, false)) {
            return false;
        }
        return get_option('alpaca_bot_api_url', null) !== null || get_option('alpaca_bot_default_model', null) !== null;
    }

    public function run(): array
    {
        $next = $this->store->all();
        foreach (self::MAP as $legacy => $key) {
            $value = get_option('alpaca_bot_' . $legacy, null);
            if ($value === null || $value === false) {
                continue;
            }
            if ($key === 'provider.base_url') {
                $value = rtrim((string) $value, '/');
                $value = str_ends_with($value, '/v1') ? $value : $value . '/v1';
            }
            $next[$key] = $value;
        }
        $this->store->replace($next);
        update_option(self::FLAG, '1', false);
        return $this->store->all();
    }
}
```

Wire in `Plugin::register()`:
```php
$store = new Settings\Store();
$this->set(Settings\Store::class, $store);
add_action('admin_init', static function () use ($store): void {
    $m = new Settings\Migrate04($store);
    if ($m->needed()) {
        $m->run();
    }
});
```

- [ ] **Step 5: Verify green, commit**

Run: `composer check` — Expected: pass.
```bash
git add -A && git commit -m "feat: settings schema, single-option store, and 0.4 migration"
```

---

### Task 3: Provider factory and model catalog

**Files:**
- Create: `src/Provider/Factory.php`, `src/Provider/ModelCatalog.php`, `src/Provider/Model.php`, `tests/Unit/Provider/FactoryTest.php`, `tests/Unit/Provider/ModelCatalogTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Consumes: `Settings\Store`; php-agents `AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface`, `...\Provider\OllamaProvider`.
- Produces:
  ```php
  namespace AlpacaBot\Provider;
  final class Factory {
      public function __construct(private Store $store) {}
      public function make(?string $model = null): ProviderInterface;   // applies filter alpaca_bot/provider (ProviderInterface, string $model, Store)
      public function baseUrl(): string;                                 // OLLAMA_API_URL constant (with /v1 appended) wins over settings
  }
  final class Model { public function __construct(public string $id, public string $label, public bool $tools = false, public bool $vision = false, public bool $thinking = false) {} public function toArray(): array; }
  final class ModelCatalog {
      public const TRANSIENT = 'alpaca_bot_models';
      public function __construct(private Factory $factory) {}
      /** @return Model[] */ public function all(bool $refresh = false): array;   // cached 5 minutes; filter alpaca_bot/models
      public function find(string $id): ?Model;
      public function defaultId(Store $store): string;                            // settings default or first model
  }
  ```

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Provider/FactoryTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Provider\Factory;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('builds an OllamaProvider from settings with the default model', function (): void {
    Functions\when('get_option')->justReturn(['models.default' => 'llama3.2', 'provider.base_url' => 'http://ollama:11434/v1']);
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturnFirstArg();
    $p = (new Factory(new Store()))->make();
    expect($p)->toBeInstanceOf(OllamaProvider::class)->and($p->getModel())->toBe('llama3.2');
});

it('lets the alpaca_bot/provider filter replace the provider', function (): void {
    Functions\when('get_option')->justReturn([]);
    $fake = Mockery::mock(ProviderInterface::class);
    Filters\expectApplied('alpaca_bot/provider')->once()->andReturn($fake);
    expect((new Factory(new Store()))->make('x'))->toBe($fake);
});

it('prefers the OLLAMA_API_URL constant and appends /v1', function (): void {
    if (!defined('OLLAMA_API_URL')) {
        define('OLLAMA_API_URL', 'http://host.docker.internal:11434');
    }
    Functions\when('get_option')->justReturn([]);
    expect((new Factory(new Store()))->baseUrl())->toBe('http://host.docker.internal:11434/v1');
});
```

`tests/Unit/Provider/ModelCatalogTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\Model;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use Brain\Monkey\Functions;

it('lists models from the provider, caches them, and flags capabilities by name', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('get_transient')->once()->with(ModelCatalog::TRANSIENT)->andReturn(false);
    Functions\expect('set_transient')->once()->withArgs(fn(string $k, array $v, int $ttl): bool => $k === ModelCatalog::TRANSIENT && $ttl === 300 && count($v) === 2);
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('models')->once()->andReturn(['llama3.2:latest', 'llava:7b']);
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('make')->andReturn($provider);
    $models = (new ModelCatalog($factory))->all();
    expect($models)->toHaveCount(2)
        ->and($models[0])->toBeInstanceOf(Model::class)
        ->and($models[0]->id)->toBe('llama3.2:latest')
        ->and($models[0]->tools)->toBeTrue()
        ->and($models[1]->vision)->toBeTrue();
});

it('uses the cached list and resolves the default id', function (): void {
    Functions\when('get_option')->justReturn(['models.default' => 'qwen3:8b']);
    Functions\when('get_transient')->justReturn([['id' => 'qwen3:8b', 'label' => 'qwen3:8b', 'tools' => true, 'vision' => false, 'thinking' => true]]);
    $factory = Mockery::mock(Factory::class);
    $factory->shouldNotReceive('make');
    $c = new ModelCatalog($factory);
    expect($c->find('qwen3:8b')?->thinking)->toBeTrue()->and($c->defaultId(new Store()))->toBe('qwen3:8b')->and($c->find('nope'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing classes.

- [ ] **Step 3: Implement**

`src/Provider/Factory.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;

final class Factory
{
    public function __construct(private Store $store) {}

    public function baseUrl(): string
    {
        $url = defined('OLLAMA_API_URL') ? (string) constant('OLLAMA_API_URL') : (string) $this->store->get('provider.base_url');
        $url = rtrim($url, '/');
        return str_ends_with($url, '/v1') ? $url : $url . '/v1';
    }

    public function make(?string $model = null): ProviderInterface
    {
        $model = $model ?: (string) $this->store->get('models.default');
        $apiKey = (string) $this->store->get('provider.api_key');
        $provider = $apiKey === ''
            ? new OllamaProvider(model: $model, baseUrl: $this->baseUrl(), numCtx: (int) $this->store->get('models.num_ctx'))
            : new OpenAICompatibleProvider(model: $model, baseUrl: $this->baseUrl(), apiKey: $apiKey);
        /** @var ProviderInterface $filtered */
        $filtered = apply_filters('alpaca_bot/provider', $provider, $model, $this->store);
        return $filtered;
    }
}
```
(Check `OpenAICompatibleProvider`'s constructor parameter names in `vendor-prefixed/carmelosantana/php-agents/src/Provider/OpenAICompatibleProvider.php` before committing; adjust the named arguments if they differ. The `wp-ai` provider kind is wired in P4.)

`src/Provider/Model.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

final class Model
{
    public function __construct(public string $id, public string $label, public bool $tools = false, public bool $vision = false, public bool $thinking = false) {}

    public static function fromId(string $id): self
    {
        $n = strtolower($id);
        $vision = (bool) preg_match('/llava|vision|moondream|minicpm-v|gemma3|qwen.*vl|bakllava/', $n);
        $tools = !(bool) preg_match('/embed|llava|moondream|bakllava/', $n);
        $thinking = (bool) preg_match('/qwen3|deepseek-r1|gpt-oss|magistral|think/', $n);
        return new self($id, $id, $tools, $vision, $thinking);
    }

    public static function fromArray(array $a): self
    {
        return new self((string) $a['id'], (string) ($a['label'] ?? $a['id']), (bool) ($a['tools'] ?? false), (bool) ($a['vision'] ?? false), (bool) ($a['thinking'] ?? false));
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'tools' => $this->tools, 'vision' => $this->vision, 'thinking' => $this->thinking];
    }
}
```

`src/Provider/ModelCatalog.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Provider;

use AlpacaBot\Settings\Store;

final class ModelCatalog
{
    public const TRANSIENT = 'alpaca_bot_models';
    private const TTL = 300;

    public function __construct(private Factory $factory) {}

    /** @return Model[] */
    public function all(bool $refresh = false): array
    {
        $cached = $refresh ? false : get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return array_map(Model::fromArray(...), $cached);
        }
        $ids = [];
        try {
            foreach ($this->factory->make('')->models() as $m) {
                $ids[] = is_array($m) ? (string) ($m['id'] ?? $m['name'] ?? '') : (string) $m;
            }
        } catch (\Throwable) {
            $ids = [];
        }
        $models = array_values(array_filter(array_map(Model::fromId(...), array_filter($ids)), static fn(Model $m): bool => !str_contains($m->id, 'embed')));
        /** @var Model[] $models */
        $models = apply_filters('alpaca_bot/models', $models);
        if ($models !== []) {
            set_transient(self::TRANSIENT, array_map(static fn(Model $m): array => $m->toArray(), $models), self::TTL);
        }
        return $models;
    }

    public function find(string $id): ?Model
    {
        foreach ($this->all() as $m) {
            if ($m->id === $id) {
                return $m;
            }
        }
        return null;
    }

    public function defaultId(Store $store): string
    {
        $wanted = (string) $store->get('models.default');
        if ($wanted !== '' && $this->find($wanted) !== null) {
            return $wanted;
        }
        $all = $this->all();
        return $all[0]->id ?? $wanted;
    }
}
```

Wire in `Plugin::register()` after the store: `$factory = new Provider\Factory($store); $this->set(Provider\Factory::class, $factory); $this->set(Provider\ModelCatalog::class, new Provider\ModelCatalog($factory));`

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: php-agents provider factory with alpaca_bot/provider filter and cached model catalog"
```

---

### Task 4: Conversation store on the `chat_history` CPT

**Files:**
- Create: `src/Chat/Message.php`, `src/Chat/Conversation.php`, `src/Chat/ConversationStore.php`, `tests/Unit/Chat/ConversationStoreTest.php`
- Modify: `src/Plugin.php` (register CPT here; legacy `Chat\Post` no longer booted)

**Interfaces:**
- Produces:
  ```php
  namespace AlpacaBot\Chat;
  final class Message {
      public function __construct(public string $role, public string $content, public string $model = '', public ?array $usage = null, public int $created = 0, public array $images = [], public array $meta = []) {}
      public static function fromArray(array $a): self;   // accepts the 0.4 shape too: {model, message:{role:int|string, content}}
      public function toArray(): array;
  }
  final class Conversation {
      public function __construct(public int $id, public int $userId, public string $title, /** @var Message[] */ public array $messages = [], public string $mode = 'chat', public int $created = 0) {}
      public function append(Message $m): void;
      public function last(): ?Message;
  }
  final class ConversationStore {
      public const POST_TYPE = 'chat_history';
      public const META_MESSAGES = 'ab_messages';        // new key; legacy key 'messages' is read and converted once
      public function __construct(private Store $store) {}
      public function registerPostType(): void;
      public function create(int $userId, string $title = ''): Conversation;
      public function load(int $id, int $userId): ?Conversation;   // null if missing or owned by someone else
      public function save(Conversation $c): void;                 // no-op when privacy.save_history is false and $c->id === 0
      /** @return array<int, array{id:int, title:string, created:int}> */ public function listFor(int $userId, int $limit): array;
      public function delete(int $id, int $userId): bool;
  }
  ```

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Chat/ConversationStoreTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\when('current_time')->justReturn(1_700_000_000);
    Functions\when('wp_generate_uuid4')->justReturn('uuid');
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_trim_words')->alias(fn(string $s, int $n = 8) => implode(' ', array_slice(explode(' ', $s), 0, $n)));
});

it('converts the 0.4 message shape', function (): void {
    $m = Message::fromArray(['model' => 'llama3.2', 'message' => ['role' => 7, 'content' => 'hi']]);
    expect($m->role)->toBe('user')->and($m->content)->toBe('hi')->and($m->model)->toBe('llama3.2');
    $a = Message::fromArray(['model' => 'llama3.2', 'message' => ['role' => 'assistant', 'content' => 'hello'], 'eval_count' => 12, 'prompt_eval_count' => 5]);
    expect($a->role)->toBe('assistant')->and($a->usage)->toBe(['prompt_tokens' => 5, 'completion_tokens' => 12]);
});

it('creates a post and saves messages under ab_messages', function (): void {
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p): bool => $p['post_type'] === 'chat_history' && $p['post_author'] === 3 && $p['post_status'] === 'private')->andReturn(42);
    Functions\expect('update_post_meta')->once()->withArgs(fn(int $id, string $k, array $v): bool => $id === 42 && $k === ConversationStore::META_MESSAGES && $v[0]['role'] === 'user' && $v[1]['role'] === 'assistant');
    Functions\expect('wp_update_post')->once()->withArgs(fn(array $p): bool => $p['ID'] === 42 && $p['post_title'] === 'What is WordPress' && $p['post_excerpt'] === 'WordPress is a CMS.')->andReturn(42);
    $s = new ConversationStore(new Store());
    $c = $s->create(3);
    expect($c->id)->toBe(42);
    $c->append(new Message('user', 'What is WordPress?'));
    $c->append(new Message('assistant', 'WordPress is a CMS.', 'llama3.2'));
    $s->save($c);
});

it('loads only the owner\'s conversation and reads legacy meta once', function (): void {
    $post = (object) ['ID' => 42, 'post_author' => '3', 'post_title' => 'T', 'post_type' => 'chat_history', 'post_date_gmt' => '2024-01-01 00:00:00'];
    Functions\when('get_post')->justReturn($post);
    Functions\expect('get_post_meta')->with(42, ConversationStore::META_MESSAGES, true)->andReturn('');
    Functions\expect('get_post_meta')->with(42, 'messages', true)->andReturn([['model' => 'm', 'message' => ['role' => 3, 'content' => 'q']], ['model' => 'm', 'message' => ['role' => 'assistant', 'content' => 'a']]]);
    Functions\expect('get_post_meta')->with(42, 'chat_mode_generate', true)->andReturn('');
    Functions\expect('update_post_meta')->once()->with(42, ConversationStore::META_MESSAGES, Mockery::type('array'));
    Functions\expect('delete_post_meta')->once()->with(42, 'messages');
    $s = new ConversationStore(new Store());
    $c = $s->load(42, 3);
    expect($c)->toBeInstanceOf(Conversation::class)->and($c->messages)->toHaveCount(2)->and($c->messages[0]->role)->toBe('user');
    expect($s->load(42, 9))->toBeNull();
});

it('does not persist when history saving is off and the conversation is new', function (): void {
    Functions\when('get_option')->justReturn(['privacy.save_history' => false]);
    Functions\expect('wp_insert_post')->never();
    $s = new ConversationStore(new Store());
    $c = $s->create(3);
    expect($c->id)->toBe(0);
    $c->append(new Message('user', 'x'));
    $s->save($c);
});

it('lists the user\'s conversations newest first with a limit', function (): void {
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_history' && $q['author'] === 3 && $q['numberposts'] === 5 && $q['orderby'] === 'date' && $q['order'] === 'DESC' && $q['post_status'] === 'private')
        ->andReturn([(object) ['ID' => 2, 'post_title' => 'B', 'post_date_gmt' => '2024-01-02 00:00:00'], (object) ['ID' => 1, 'post_title' => 'A', 'post_date_gmt' => '2024-01-01 00:00:00']]);
    expect((new ConversationStore(new Store()))->listFor(3, 5))->toBe([['id' => 2, 'title' => 'B', 'created' => 1704153600], ['id' => 1, 'title' => 'A', 'created' => 1704067200]]);
});
```

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing classes.

- [ ] **Step 3: Implement**

`src/Chat/Message.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

final class Message
{
    public function __construct(
        public string $role,
        public string $content,
        public string $model = '',
        public ?array $usage = null,
        public int $created = 0,
        public array $images = [],
        public array $meta = [],
    ) {}

    public static function fromArray(array $a): self
    {
        if (isset($a['message']) && is_array($a['message'])) { // 0.4 shape
            $role = $a['message']['role'] ?? 'user';
            $role = is_string($role) && in_array($role, ['user', 'assistant', 'system', 'tool'], true) ? $role : 'user';
            $usage = isset($a['eval_count']) || isset($a['prompt_eval_count'])
                ? ['prompt_tokens' => (int) ($a['prompt_eval_count'] ?? 0), 'completion_tokens' => (int) ($a['eval_count'] ?? 0)]
                : null;
            return new self($role, (string) ($a['message']['content'] ?? ''), (string) ($a['model'] ?? ''), $usage, (int) ($a['created'] ?? 0), (array) ($a['message']['images'] ?? []));
        }
        return new self((string) ($a['role'] ?? 'user'), (string) ($a['content'] ?? ''), (string) ($a['model'] ?? ''), isset($a['usage']) && is_array($a['usage']) ? $a['usage'] : null, (int) ($a['created'] ?? 0), (array) ($a['images'] ?? []), (array) ($a['meta'] ?? []));
    }

    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content, 'model' => $this->model, 'usage' => $this->usage, 'created' => $this->created, 'images' => $this->images, 'meta' => $this->meta];
    }
}
```

`src/Chat/Conversation.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

final class Conversation
{
    /** @param Message[] $messages */
    public function __construct(public int $id, public int $userId, public string $title, public array $messages = [], public string $mode = 'chat', public int $created = 0) {}

    public function append(Message $m): void
    {
        if ($m->created === 0) {
            $m->created = (int) current_time('timestamp', true);
        }
        $this->messages[] = $m;
    }

    public function last(): ?Message
    {
        return $this->messages[array_key_last($this->messages)] ?? null;
    }
}
```

`src/Chat/ConversationStore.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

final class ConversationStore
{
    public const POST_TYPE = 'chat_history';
    public const META_MESSAGES = 'ab_messages';
    private const META_LEGACY = 'messages';

    public function __construct(private Store $store) {}

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'delete_with_user' => true,
            'supports' => ['title', 'excerpt', 'author'],
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
        ]);
    }

    public function create(int $userId, string $title = ''): Conversation
    {
        $c = new Conversation(0, $userId, $title, [], 'chat', (int) current_time('timestamp', true));
        if (!$this->store->get('privacy.save_history')) {
            return $c;
        }
        $id = wp_insert_post(['post_type' => self::POST_TYPE, 'post_author' => $userId, 'post_status' => 'private', 'post_title' => $title !== '' ? $title : __('New chat', 'alpaca-bot'), 'post_name' => wp_generate_uuid4()], true);
        $c->id = is_int($id) ? $id : 0;
        return $c;
    }

    public function load(int $id, int $userId): ?Conversation
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE || (int) $post->post_author !== $userId) {
            return null;
        }
        $raw = get_post_meta($id, self::META_MESSAGES, true);
        if (!is_array($raw)) {
            $legacy = get_post_meta($id, self::META_LEGACY, true);
            $raw = is_array($legacy) ? array_map(static fn(array $m): array => Message::fromArray($m)->toArray(), $legacy) : [];
            if ($legacy !== '' && $legacy !== false) {
                update_post_meta($id, self::META_MESSAGES, $raw);
                delete_post_meta($id, self::META_LEGACY);
            }
        }
        $mode = get_post_meta($id, 'chat_mode_generate', true) ? 'generate' : 'chat';
        return new Conversation($id, $userId, (string) $post->post_title, array_map(Message::fromArray(...), $raw), $mode, (int) strtotime($post->post_date_gmt . ' UTC'));
    }

    public function save(Conversation $c): void
    {
        if ($c->id === 0) {
            return;
        }
        update_post_meta($c->id, self::META_MESSAGES, array_map(static fn(Message $m): array => $m->toArray(), $c->messages));
        $first = $c->messages[0] ?? null;
        $last = $c->last();
        if ($first !== null) {
            $title = $c->title !== '' && $c->title !== __('New chat', 'alpaca-bot') ? $c->title : wp_trim_words(sanitize_text_field(rtrim($first->content, '?.!')), 8, '');
            wp_update_post(['ID' => $c->id, 'post_title' => $title, 'post_excerpt' => $last !== null ? wp_trim_words(sanitize_text_field($last->content), 30, '…') : '']);
        }
    }

    public function listFor(int $userId, int $limit): array
    {
        $posts = get_posts(['post_type' => self::POST_TYPE, 'author' => $userId, 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'post_status' => 'private']);
        return array_map(static fn(object $p): array => ['id' => (int) $p->ID, 'title' => (string) $p->post_title, 'created' => (int) strtotime($p->post_date_gmt . ' UTC')], $posts);
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->load($id, $userId) !== null && wp_delete_post($id, true) !== false;
    }
}
```

Wire in `Plugin::register()`: `$conversations = new Chat\ConversationStore($store); $this->set(Chat\ConversationStore::class, $conversations); add_action('init', [$conversations, 'registerPostType']);`

Note: existing 0.4 rows have `post_status=publish` and no `post_author` on some sites; `load()` checks author strictly, so P5's upgrade routine (or Migrate04 extension) sets `post_author` from the first legacy user-role id and `post_status=private`. Add that to `Migrate04::run()` now:
```php
foreach (get_posts(['post_type' => 'chat_history', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $pid) {
    $legacy = get_post_meta((int) $pid, 'messages', true);
    $author = is_array($legacy) && is_int($legacy[0]['message']['role'] ?? null) ? (int) $legacy[0]['message']['role'] : 0;
    wp_update_post(['ID' => (int) $pid, 'post_status' => 'private'] + ($author > 0 ? ['post_author' => $author] : []));
}
```
and add a unit test in `Migrate04Test` asserting `wp_update_post` is called with `post_status => private` and `post_author => 7` for a legacy post whose first message role is `7`.

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: conversation store on chat_history with legacy message migration"
```

---

### Task 5: Usage meter and cap policy

**Files:**
- Create: `src/Chat/UsageMeter.php`, `src/Chat/CapPolicy.php`, `src/Chat/CapExceeded.php`, `tests/Unit/Chat/UsageMeterTest.php`, `tests/Unit/Chat/CapPolicyTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Produces:
  ```php
  namespace AlpacaBot\Chat;
  final class UsageMeter {
      public const POST_TYPE = 'chat_log';
      public function __construct(private Store $store) {}
      public function registerPostType(): void;
      /** @return int post id or 0 when logging is off */
      public function record(int $userId, string $model, int $promptTokens, int $completionTokens, int $durationMs, int $conversationId = 0): int;  // fires action alpaca_bot/usage/recorded (array $receipt)
      public function monthTotal(?int $userId = null): int;   // tokens this calendar month (UTC); site-wide when null; cached in transient per (user, month) and invalidated by record()
      /** @return array{tokens:int, requests:int, month:string} */ public function monthSummary(?int $userId = null): array;
  }
  final class CapExceeded extends \RuntimeException { public function __construct(public string $scope, public int $limit, public int $used) {} }
  final class CapPolicy {
      public function __construct(private Store $store, private UsageMeter $meter) {}
      public function assertAllowed(int $userId): void;   // throws CapExceeded; filter alpaca_bot/cap/allowed (bool, userId, scope, limit, used)
  }
  ```
- Receipt array shape: `['user_id' => int, 'model' => string, 'prompt_tokens' => int, 'completion_tokens' => int, 'total_tokens' => int, 'duration_ms' => int, 'conversation_id' => int, 'log_id' => int, 'created' => int]`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Chat/UsageMeterTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('current_time')->justReturn(1_725_000_000); // 2024-08-30
    Functions\when('gmdate')->alias(fn(string $f, ?int $t = null) => date($f, $t ?? 1_725_000_000));
});

it('records a chat_log post with token meta and fires the receipt action', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('wp_insert_post')->once()->withArgs(fn(array $p): bool => $p['post_type'] === 'chat_log' && $p['post_author'] === 3 && $p['meta_input']['prompt_tokens'] === 10 && $p['meta_input']['completion_tokens'] === 20 && $p['meta_input']['total_tokens'] === 30 && $p['meta_input']['model'] === 'm' && $p['meta_input']['duration_ms'] === 1234)->andReturn(77);
    Functions\expect('delete_transient')->twice();
    Actions\expectDone('alpaca_bot/usage/recorded')->once()->withArgs(fn(array $r): bool => $r['log_id'] === 77 && $r['total_tokens'] === 30);
    expect((new UsageMeter(new Store()))->record(3, 'm', 10, 20, 1234, 5))->toBe(77);
});

it('skips the post but still fires the action when the usage log is off', function (): void {
    Functions\when('get_option')->justReturn(['privacy.usage_log' => false]);
    Functions\expect('wp_insert_post')->never();
    Functions\when('delete_transient')->justReturn(true);
    Actions\expectDone('alpaca_bot/usage/recorded')->once();
    expect((new UsageMeter(new Store()))->record(3, 'm', 1, 1, 1))->toBe(0);
});

it('sums this month\'s tokens per user via a single query and caches it', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('get_transient')->once()->with('alpaca_bot_usage_3_2024-08')->andReturn(false);
    Functions\expect('get_posts')->once()->withArgs(fn(array $q): bool => $q['post_type'] === 'chat_log' && $q['author'] === 3 && $q['date_query'][0]['after'] === '2024-08-01 00:00:00' && $q['fields'] === 'ids' && $q['numberposts'] === -1)->andReturn([1, 2]);
    Functions\expect('get_post_meta')->with(1, 'total_tokens', true)->andReturn('30');
    Functions\expect('get_post_meta')->with(2, 'total_tokens', true)->andReturn('12');
    Functions\expect('set_transient')->once()->with('alpaca_bot_usage_3_2024-08', ['tokens' => 42, 'requests' => 2], 3600);
    expect((new UsageMeter(new Store()))->monthTotal(3))->toBe(42);
});
```

`tests/Unit/Chat/CapPolicyTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Chat\CapPolicy;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Settings\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

it('allows when caps are 0', function (): void {
    Functions\when('get_option')->justReturn([]);
    $meter = Mockery::mock(UsageMeter::class);
    $meter->shouldNotReceive('monthTotal');
    (new CapPolicy(new Store(), $meter))->assertAllowed(3);
    expect(true)->toBeTrue();
});

it('throws for the user cap before the site cap', function (): void {
    Functions\when('get_option')->justReturn(['governance.user_monthly_tokens' => 100, 'governance.site_monthly_tokens' => 1000]);
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->andReturnFirstArg();
    $meter = Mockery::mock(UsageMeter::class);
    $meter->shouldReceive('monthTotal')->with(3)->once()->andReturn(150);
    $meter->shouldReceive('monthTotal')->with(null)->never();
    expect(fn() => (new CapPolicy(new Store(), $meter))->assertAllowed(3))->toThrow(CapExceeded::class);
});

it('the filter can override a block', function (): void {
    Functions\when('get_option')->justReturn(['governance.site_monthly_tokens' => 10]);
    Filters\expectApplied('alpaca_bot/cap/allowed')->once()->andReturn(true);
    $meter = Mockery::mock(UsageMeter::class);
    $meter->shouldReceive('monthTotal')->andReturn(999);
    (new CapPolicy(new Store(), $meter))->assertAllowed(3);
    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing classes.

- [ ] **Step 3: Implement**

`src/Chat/UsageMeter.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

final class UsageMeter
{
    public const POST_TYPE = 'chat_log';
    private const TTL = 3600;

    public function __construct(private Store $store) {}

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'delete_with_user' => true,
            'supports' => ['title', 'author', 'custom-fields'],
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
        ]);
    }

    public function record(int $userId, string $model, int $promptTokens, int $completionTokens, int $durationMs, int $conversationId = 0): int
    {
        $now = (int) current_time('timestamp', true);
        $total = $promptTokens + $completionTokens;
        $logId = 0;
        if ($this->store->get('privacy.usage_log')) {
            $id = wp_insert_post([
                'post_type' => self::POST_TYPE,
                'post_status' => 'private',
                'post_author' => $userId,
                'post_title' => sprintf('%s · %d tokens', $model, $total),
                'meta_input' => ['model' => $model, 'prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens, 'total_tokens' => $total, 'duration_ms' => $durationMs, 'conversation_id' => $conversationId],
            ], true);
            $logId = is_int($id) ? $id : 0;
        }
        delete_transient($this->key($userId, $now));
        delete_transient($this->key(null, $now));
        $receipt = ['user_id' => $userId, 'model' => $model, 'prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens, 'total_tokens' => $total, 'duration_ms' => $durationMs, 'conversation_id' => $conversationId, 'log_id' => $logId, 'created' => $now];
        do_action('alpaca_bot/usage/recorded', $receipt);
        return $logId;
    }

    public function monthTotal(?int $userId = null): int
    {
        return $this->monthSummary($userId)['tokens'];
    }

    public function monthSummary(?int $userId = null): array
    {
        $now = (int) current_time('timestamp', true);
        $month = gmdate('Y-m', $now);
        $key = $this->key($userId, $now);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached + ['month' => $month];
        }
        $q = ['post_type' => self::POST_TYPE, 'post_status' => 'private', 'numberposts' => -1, 'fields' => 'ids', 'date_query' => [['after' => $month . '-01 00:00:00', 'inclusive' => true, 'column' => 'post_date_gmt']]];
        if ($userId !== null) {
            $q['author'] = $userId;
        }
        $ids = get_posts($q);
        $tokens = 0;
        foreach ($ids as $id) {
            $tokens += (int) get_post_meta((int) $id, 'total_tokens', true);
        }
        $summary = ['tokens' => $tokens, 'requests' => count($ids)];
        set_transient($key, $summary, self::TTL);
        return $summary + ['month' => $month];
    }

    private function key(?int $userId, int $now): string
    {
        return 'alpaca_bot_usage_' . ($userId ?? 'site') . '_' . gmdate('Y-m', $now);
    }
}
```

`src/Chat/CapExceeded.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

final class CapExceeded extends \RuntimeException
{
    public function __construct(public string $scope, public int $limit, public int $used)
    {
        parent::__construct(sprintf('%s monthly token cap reached (%d of %d).', $scope === 'user' ? 'Your' : 'The site', $used, $limit));
    }
}
```

`src/Chat/CapPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Settings\Store;

final class CapPolicy
{
    public function __construct(private Store $store, private UsageMeter $meter) {}

    public function assertAllowed(int $userId): void
    {
        foreach (['user' => $userId, 'site' => null] as $scope => $who) {
            $limit = (int) $this->store->get("governance.{$scope}_monthly_tokens", 0);
            if ($limit <= 0) {
                continue;
            }
            $used = $this->meter->monthTotal($who);
            $allowed = $used < $limit;
            $allowed = (bool) apply_filters('alpaca_bot/cap/allowed', $allowed, $userId, $scope, $limit, $used);
            if (!$allowed) {
                throw new CapExceeded($scope, $limit, $used);
            }
        }
    }
}
```

Wire in `Plugin::register()`: `$meter = new Chat\UsageMeter($store); $this->set(Chat\UsageMeter::class, $meter); add_action('init', [$meter, 'registerPostType']); $this->set(Chat\CapPolicy::class, new Chat\CapPolicy($store, $meter));`

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: usage meter on chat_log with monthly caps and receipt action"
```

---

### Task 6: Context sources

**Files:**
- Create: `src/Context/ContextSourceInterface.php`, `src/Context/Context.php`, `src/Context/CurrentScreenSource.php`, `src/Context/Collector.php`, `tests/Unit/Context/CollectorTest.php`, `tests/Unit/Context/CurrentScreenSourceTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Produces:
  ```php
  namespace AlpacaBot\Context;
  final class Context { public function __construct(public string $id, public string $label, public string $text, public array $meta = []) {} public function toArray(): array; }
  interface ContextSourceInterface {
      public function id(): string;
      /** @param array<string,mixed> $request  e.g. ['screen' => 'post', 'post_id' => 12] as sent by the client  @return Context[] */
      public function collect(int $userId, array $request): array;
  }
  final class CurrentScreenSource implements ContextSourceInterface {}   // id 'current-screen'; when post_id is given and current_user_can('edit_post', id): title + excerpt-limited content (first 4000 chars, tags stripped)
  final class Collector {
      /** @param ContextSourceInterface[] $sources */ public function __construct(private array $sources = []) {}
      public function add(ContextSourceInterface $s): void;
      /** @return Context[] */ public function collect(int $userId, array $request): array;   // filter alpaca_bot/context (Context[], userId, request); sources filtered by alpaca_bot/context/sources
      public static function asSystemBlock(array $contexts): string;   // "Context:\n## <label>\n<text>\n\n..." or '' when empty
  }
  ```

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Context/CollectorTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Context\Collector;
use AlpacaBot\Context\Context;
use AlpacaBot\Context\ContextSourceInterface;
use Brain\Monkey\Filters;

it('collects from every source and applies the alpaca_bot/context filter', function (): void {
    $a = new class implements ContextSourceInterface { public function id(): string { return 'a'; } public function collect(int $u, array $r): array { return [new Context('a:1', 'A', 'alpha')]; } };
    $b = new class implements ContextSourceInterface { public function id(): string { return 'b'; } public function collect(int $u, array $r): array { return []; } };
    Filters\expectApplied('alpaca_bot/context/sources')->once()->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/context')->once()->andReturnFirstArg();
    $c = new Collector([$a, $b]);
    $out = $c->collect(1, []);
    expect($out)->toHaveCount(1)->and($out[0]->text)->toBe('alpha');
    expect(Collector::asSystemBlock($out))->toBe("Context:\n## A\nalpha");
    expect(Collector::asSystemBlock([]))->toBe('');
});
```

`tests/Unit/Context/CurrentScreenSourceTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Context\CurrentScreenSource;
use Brain\Monkey\Functions;

it('returns the post being edited when the user can edit it', function (): void {
    Functions\expect('current_user_can')->with('edit_post', 12)->andReturn(true);
    Functions\when('get_post')->justReturn((object) ['ID' => 12, 'post_title' => 'Hello', 'post_content' => '<p>Body</p>', 'post_type' => 'post', 'post_status' => 'draft']);
    Functions\when('wp_strip_all_tags')->alias(fn(string $s) => strip_tags($s));
    $out = (new CurrentScreenSource())->collect(1, ['screen' => 'post', 'post_id' => '12']);
    expect($out)->toHaveCount(1)->and($out[0]->id)->toBe('current-screen:post:12')->and($out[0]->label)->toBe('Editing: Hello (post, draft)')->and($out[0]->text)->toBe('Body');
});

it('returns nothing without a post id or permission', function (): void {
    expect((new CurrentScreenSource())->collect(1, ['screen' => 'dashboard']))->toBe([]);
    Functions\expect('current_user_can')->with('edit_post', 5)->andReturn(false);
    expect((new CurrentScreenSource())->collect(1, ['post_id' => 5]))->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing classes.

- [ ] **Step 3: Implement**

`src/Context/Context.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

final class Context
{
    public function __construct(public string $id, public string $label, public string $text, public array $meta = []) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'text' => $this->text, 'meta' => $this->meta];
    }
}
```

`src/Context/ContextSourceInterface.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

interface ContextSourceInterface
{
    public function id(): string;

    /** @param array<string, mixed> $request @return Context[] */
    public function collect(int $userId, array $request): array;
}
```

`src/Context/CurrentScreenSource.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

final class CurrentScreenSource implements ContextSourceInterface
{
    private const MAX_CHARS = 4000;

    public function id(): string
    {
        return 'current-screen';
    }

    public function collect(int $userId, array $request): array
    {
        $postId = (int) ($request['post_id'] ?? 0);
        if ($postId <= 0 || !current_user_can('edit_post', $postId)) {
            return [];
        }
        $post = get_post($postId);
        if (!$post) {
            return [];
        }
        $text = trim(wp_strip_all_tags((string) $post->post_content));
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . '…';
        }
        return [new Context("current-screen:{$post->post_type}:{$postId}", sprintf('Editing: %s (%s, %s)', $post->post_title, $post->post_type, $post->post_status), $text, ['post_id' => $postId])];
    }
}
```

`src/Context/Collector.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

final class Collector
{
    /** @param ContextSourceInterface[] $sources */
    public function __construct(private array $sources = []) {}

    public function add(ContextSourceInterface $s): void
    {
        $this->sources[] = $s;
    }

    /** @return Context[] */
    public function collect(int $userId, array $request): array
    {
        /** @var ContextSourceInterface[] $sources */
        $sources = apply_filters('alpaca_bot/context/sources', $this->sources, $userId, $request);
        $out = [];
        foreach ($sources as $s) {
            foreach ($s->collect($userId, $request) as $c) {
                $out[] = $c;
            }
        }
        /** @var Context[] */
        return apply_filters('alpaca_bot/context', $out, $userId, $request);
    }

    /** @param Context[] $contexts */
    public static function asSystemBlock(array $contexts): string
    {
        if ($contexts === []) {
            return '';
        }
        return "Context:\n" . implode("\n\n", array_map(static fn(Context $c): string => "## {$c->label}\n{$c->text}", $contexts));
    }
}
```

Wire: `$this->set(Context\Collector::class, new Context\Collector([new Context\CurrentScreenSource()]));`

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: ContextSource interface, collector, and current-screen source"
```

---

### Task 7: The pipeline

**Files:**
- Create: `src/Chat/Delta.php`, `src/Chat/Result.php`, `src/Chat/Pipeline.php`, `tests/Unit/Chat/PipelineTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Produces:
  ```php
  namespace AlpacaBot\Chat;
  final class Delta { public function __construct(public string $text, public string $reasoning = '') {} }
  final class Result { public function __construct(public Conversation $conversation, public Message $reply, public array $receipt, public array $contexts) {} }
  final class Pipeline {
      public function __construct(private Store $store, private Provider\Factory $factory, private ModelCatalog $catalog, private ConversationStore $conversations, private UsageMeter $meter, private CapPolicy $caps, private Collector $collector) {}
      /**
       * @param array{conversation_id?:int, model?:string, images?:string[], context?:array<string,mixed>, system?:string} $options
       * @return \Generator<int, Delta, mixed, Result>   yields deltas while streaming; the generator's return value is the Result
       */
      public function send(int $userId, string $text, array $options = []): \Generator;
      public function complete(int $userId, string $text, array $options = []): Result;   // drains send()
      public function buildMessages(Conversation $c, string $model, array $contexts, ?string $systemOverride = null): array;   // php-agents MessageInterface[]
  }
  ```
- Hooks fired, in order: filter `alpaca_bot/system_prompt` (string, Conversation, model) → filter `alpaca_bot/message/before_send` (string text, Conversation, options) → action `alpaca_bot/chat/started` (Conversation, model) → deltas → filter `alpaca_bot/message/after_receive` (Message reply, Conversation) → action `alpaca_bot/chat/completed` (Result).
- Errors: `CapExceeded` propagates before any provider call; provider exceptions are wrapped in `\RuntimeException('Provider error: ...')` after firing action `alpaca_bot/chat/failed` (Throwable, Conversation).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Chat/PipelineTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapPolicy;
use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Delta;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\Result;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Context\Collector;
use AlpacaBot\Context\Context;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\Model;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

function pipelineWith(ProviderInterface $provider, array $settings = [], array $contexts = []): array
{
    Functions\when('get_option')->justReturn($settings + ['models.default' => 'llama3.2']);
    Functions\when('current_time')->justReturn(1_725_000_000);
    Functions\when('__')->returnArg();
    $store = new Store();
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('make')->with('llama3.2')->andReturn($provider);
    $catalog = Mockery::mock(ModelCatalog::class);
    $catalog->shouldReceive('find')->andReturn(new Model('llama3.2', 'llama3.2', true));
    $catalog->shouldReceive('defaultId')->andReturn('llama3.2');
    $conversations = Mockery::mock(ConversationStore::class);
    $conversations->shouldReceive('create')->andReturn(new Conversation(42, 3, 'New chat'));
    $conversations->shouldReceive('save')->once();
    $meter = Mockery::mock(UsageMeter::class);
    $caps = Mockery::mock(CapPolicy::class);
    $caps->shouldReceive('assertAllowed')->with(3)->once();
    $collector = Mockery::mock(Collector::class);
    $collector->shouldReceive('collect')->andReturn($contexts);
    return [new Pipeline($store, $factory, $catalog, $conversations, $meter, $caps, $collector), $meter, $conversations];
}

it('streams deltas, persists both messages, records usage, and returns a Result', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('stream')->once()->withArgs(function (array $messages, array $tools, array $options): bool {
        return $messages[0] instanceof SystemMessage && str_contains($messages[0]->content(), 'Be brief') && $messages[1] instanceof UserMessage && $messages[1]->content() === 'Hi there' && $options['temperature'] === 0.7;
    })->andReturn((function () { yield new Response('Hel'); yield new Response('lo', usage: new Usage(5, 2, 7)); })());
    [$pipeline, $meter] = pipelineWith($provider, ['chat.system_prompt' => 'Be brief']);
    $meter->shouldReceive('record')->once()->withArgs(fn(int $u, string $m, int $p, int $c, int $d, int $cid): bool => $u === 3 && $m === 'llama3.2' && $p === 5 && $c === 2 && $cid === 42)->andReturn(9);
    Filters\expectApplied('alpaca_bot/message/before_send')->once()->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/message/after_receive')->once()->andReturnFirstArg();
    Actions\expectDone('alpaca_bot/chat/completed')->once();
    $gen = $pipeline->send(3, 'Hi there');
    $deltas = [];
    foreach ($gen as $d) { $deltas[] = $d; }
    $result = $gen->getReturn();
    expect($deltas)->toHaveCount(2)->and($deltas[0])->toBeInstanceOf(Delta::class)->and($deltas[0]->text)->toBe('Hel')
        ->and($result)->toBeInstanceOf(Result::class)
        ->and($result->reply->content)->toBe('Hello')->and($result->reply->role)->toBe('assistant')->and($result->reply->usage)->toBe(['prompt_tokens' => 5, 'completion_tokens' => 2])
        ->and($result->conversation->messages)->toHaveCount(2)->and($result->conversation->messages[0]->content)->toBe('Hi there')
        ->and($result->receipt['log_id'])->toBe(9);
});

it('injects context as a system block and honors per-model overrides', function (): void {
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldReceive('stream')->once()->withArgs(fn(array $m, array $t, array $o): bool => str_contains($m[0]->content(), "## Editing: Hello") && $o['temperature'] === 0.1 && $o['num_ctx'] === 32768)->andReturn((function () { yield new Response('ok'); })());
    [$pipeline, $meter] = pipelineWith($provider, ['models.overrides' => ['llama3.2' => ['temperature' => 0.1, 'num_ctx' => 32768]]], [new Context('c', 'Editing: Hello', 'Body')]);
    $meter->shouldReceive('record')->once()->andReturn(0);
    $r = $pipeline->complete(3, 'Summarize', ['context' => ['post_id' => 12]]);
    expect($r->contexts)->toHaveCount(1)->and($r->reply->content)->toBe('ok');
});

it('does not call the provider when the cap is exceeded', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\when('__')->returnArg();
    $provider = Mockery::mock(ProviderInterface::class);
    $provider->shouldNotReceive('stream');
    $caps = Mockery::mock(CapPolicy::class);
    $caps->shouldReceive('assertAllowed')->andThrow(new \AlpacaBot\Chat\CapExceeded('user', 10, 12));
    $p = new Pipeline(new Store(), Mockery::mock(Factory::class), Mockery::mock(ModelCatalog::class), Mockery::mock(ConversationStore::class), Mockery::mock(UsageMeter::class), $caps, Mockery::mock(Collector::class));
    expect(fn() => $p->complete(3, 'x'))->toThrow(\AlpacaBot\Chat\CapExceeded::class);
});
```

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing classes.

- [ ] **Step 3: Implement**

`src/Chat/Delta.php` and `src/Chat/Result.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

final class Delta
{
    public function __construct(public string $text, public string $reasoning = '') {}
}
```
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

final class Result
{
    /** @param \AlpacaBot\Context\Context[] $contexts */
    public function __construct(public Conversation $conversation, public Message $reply, public array $receipt, public array $contexts) {}
}
```

`src/Chat/Pipeline.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

use AlpacaBot\Context\Collector;
use AlpacaBot\Provider\Factory;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\MessageInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\AssistantMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\SystemMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Message\UserMessage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;

final class Pipeline
{
    public function __construct(
        private Store $store,
        private Factory $factory,
        private ModelCatalog $catalog,
        private ConversationStore $conversations,
        private UsageMeter $meter,
        private CapPolicy $caps,
        private Collector $collector,
    ) {}

    public function complete(int $userId, string $text, array $options = []): Result
    {
        $gen = $this->send($userId, $text, $options);
        foreach ($gen as $_) {
        }
        return $gen->getReturn();
    }

    /** @return \Generator<int, Delta, mixed, Result> */
    public function send(int $userId, string $text, array $options = []): \Generator
    {
        $this->caps->assertAllowed($userId);
        $conversationId = (int) ($options['conversation_id'] ?? 0);
        $conversation = $conversationId > 0 ? $this->conversations->load($conversationId, $userId) : null;
        $conversation ??= $this->conversations->create($userId);
        $model = (string) ($options['model'] ?: $this->catalog->defaultId($this->store));
        if (!$this->store->get('chat.user_can_change_model') && !empty($options['model'])) {
            $model = $this->catalog->defaultId($this->store);
        }
        $text = (string) apply_filters('alpaca_bot/message/before_send', $text, $conversation, $options);
        $conversation->append(new Message('user', $text, $model, null, 0, (array) ($options['images'] ?? [])));
        $contexts = $this->collector->collect($userId, (array) ($options['context'] ?? []));
        $messages = $this->buildMessages($conversation, $model, $contexts, $options['system'] ?? null);
        $modelOptions = $this->store->modelOverrides($model);
        $providerOptions = ['temperature' => (float) $modelOptions['temperature'], 'num_ctx' => (int) $modelOptions['num_ctx'], 'keep_alive' => (string) $modelOptions['keep_alive']];
        do_action('alpaca_bot/chat/started', $conversation, $model);
        $started = microtime(true);
        $content = '';
        $reasoning = '';
        $prompt = 0;
        $completion = 0;
        try {
            foreach ($this->factory->make($model)->stream($messages, [], $providerOptions) as $chunk) {
                /** @var Response $chunk */
                if ($chunk->content !== '' || $chunk->reasoning !== '') {
                    $content .= $chunk->content;
                    $reasoning .= $chunk->reasoning;
                    yield new Delta($chunk->content, $chunk->reasoning);
                }
                if ($chunk->usage !== null) {
                    $prompt = max($prompt, $chunk->usage->promptTokens);
                    $completion = max($completion, $chunk->usage->completionTokens);
                }
            }
        } catch (\Throwable $e) {
            do_action('alpaca_bot/chat/failed', $e, $conversation);
            throw new \RuntimeException('Provider error: ' . $e->getMessage(), 0, $e);
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $reply = new Message('assistant', $content, $model, ['prompt_tokens' => $prompt, 'completion_tokens' => $completion], 0, [], $reasoning !== '' ? ['reasoning' => $reasoning] : []);
        /** @var Message $reply */
        $reply = apply_filters('alpaca_bot/message/after_receive', $reply, $conversation);
        $conversation->append($reply);
        $this->conversations->save($conversation);
        $logId = $this->meter->record($userId, $model, $prompt, $completion, $durationMs, $conversation->id);
        $receipt = ['user_id' => $userId, 'model' => $model, 'prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion, 'duration_ms' => $durationMs, 'conversation_id' => $conversation->id, 'log_id' => $logId, 'created' => $reply->created];
        $result = new Result($conversation, $reply, $receipt, $contexts);
        do_action('alpaca_bot/chat/completed', $result);
        return $result;
    }

    /** @return MessageInterface[] */
    public function buildMessages(Conversation $c, string $model, array $contexts, ?string $systemOverride = null): array
    {
        $system = $systemOverride ?? (string) ($this->store->modelOverrides($model)['system'] ?? '') ?: (string) $this->store->get('chat.system_prompt');
        $system = (string) apply_filters('alpaca_bot/system_prompt', $system, $c, $model);
        $block = Collector::asSystemBlock($contexts);
        $systemText = trim($system . ($block !== '' ? "\n\n" . $block : ''));
        $out = [];
        if ($systemText !== '') {
            $out[] = new SystemMessage($systemText);
        }
        foreach ($c->messages as $m) {
            $out[] = match ($m->role) {
                'assistant' => new AssistantMessage($m->content),
                'system' => new SystemMessage($m->content),
                default => $m->images === [] ? new UserMessage($m->content) : new UserMessage(array_merge([['type' => 'text', 'text' => $m->content]], array_map(static fn(string $img): array => ['type' => 'image', 'image' => $img], $m->images))),
            };
        }
        return $out;
    }
}
```
Verify the multimodal `UserMessage` array shape against `vendor-prefixed/carmelosantana/php-agents/src/Message/UserMessage.php` and `docs/PROVIDERS.md` § Image input before committing; adjust the array keys to match.

Wire in `Plugin::register()`:
```php
$this->set(Chat\Pipeline::class, new Chat\Pipeline($store, $factory, $this->get(Provider\ModelCatalog::class), $conversations, $meter, $this->get(Chat\CapPolicy::class), $this->get(Context\Collector::class)));
```

- [ ] **Step 4: Verify green, commit**

Run: `composer check` — pass.
```bash
git add -A && git commit -m "feat: streaming chat pipeline with hooks, caps, context, persistence, and receipts"
```

---

### Task 8: WP-CLI command and real-world verification

**Files:**
- Create: `src/Cli/ChatCommand.php`, `tests/Unit/Cli/ChatCommandTest.php`
- Modify: `src/Plugin.php`, `README.md` (add a "1.0 dev status" section)

**Interfaces:**
- Produces: `wp alpaca-bot chat <message> [--model=<id>] [--conversation=<id>] [--user=<id>] [--json]`, `wp alpaca-bot models`, `wp alpaca-bot usage [--user=<id>]`, `wp alpaca-bot settings [<key>] [<value>]`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Cli/ChatCommandTest.php`:
```php
<?php

declare(strict_types=1);

use AlpacaBot\Chat\Conversation;
use AlpacaBot\Chat\Delta;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\Result;
use AlpacaBot\Cli\ChatCommand;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Settings\Store;

it('streams deltas to the writer and prints a receipt', function (): void {
    $pipeline = Mockery::mock(Pipeline::class);
    $pipeline->shouldReceive('send')->with(1, 'hello', ['model' => '', 'conversation_id' => 0])->andReturn((function () {
        yield new Delta('Hi ');
        yield new Delta('you');
        return new Result(new Conversation(5, 1, 'Hello'), new Message('assistant', 'Hi you', 'm'), ['total_tokens' => 7, 'duration_ms' => 20, 'model' => 'm', 'conversation_id' => 5, 'log_id' => 1, 'prompt_tokens' => 5, 'completion_tokens' => 2, 'user_id' => 1, 'created' => 0], []);
    })());
    $out = [];
    $cmd = new ChatCommand($pipeline, Mockery::mock(ModelCatalog::class), Mockery::mock(UsageMeter::class), new Store([]), fn(string $s) => $out[] = $s);
    $cmd->chat(['hello'], ['user' => '1']);
    expect(implode('', $out))->toBe("Hi you\n\n[m · 7 tokens · 20 ms · conversation 5]\n");
});
```

- [ ] **Step 2: Run to verify failure** — `composer test` FAILs on missing class.

- [ ] **Step 3: Implement**

`src/Cli/ChatCommand.php`:
```php
<?php

declare(strict_types=1);

namespace AlpacaBot\Cli;

use AlpacaBot\Chat\Pipeline;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\Settings\Store;

/**
 * Alpaca Bot from the command line.
 */
final class ChatCommand
{
    /** @var callable(string):void */
    private $write;

    public function __construct(private Pipeline $pipeline, private ModelCatalog $catalog, private UsageMeter $meter, private Store $store, ?callable $write = null)
    {
        $this->write = $write ?? static function (string $s): void { fwrite(STDOUT, $s); };
    }

    /**
     * Send a message and stream the reply.
     *
     * ## OPTIONS
     * <message>
     * : The message.
     * [--model=<id>]
     * [--conversation=<id>]
     * [--user=<id>]
     * : Defaults to the first administrator.
     * [--json]
     */
    public function chat(array $args, array $assoc): void
    {
        $userId = $this->userId($assoc);
        $gen = $this->pipeline->send($userId, (string) ($args[0] ?? ''), ['model' => (string) ($assoc['model'] ?? ''), 'conversation_id' => (int) ($assoc['conversation'] ?? 0)]);
        $json = isset($assoc['json']);
        foreach ($gen as $delta) {
            if (!$json) {
                ($this->write)($delta->text);
            }
        }
        $r = $gen->getReturn();
        if ($json) {
            ($this->write)(json_encode(['conversation_id' => $r->conversation->id, 'reply' => $r->reply->toArray(), 'receipt' => $r->receipt], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            return;
        }
        ($this->write)(sprintf("\n\n[%s · %d tokens · %d ms · conversation %d]\n", $r->receipt['model'], $r->receipt['total_tokens'], $r->receipt['duration_ms'], $r->receipt['conversation_id']));
    }

    /** List models the provider reports. */
    public function models(array $args, array $assoc): void
    {
        foreach ($this->catalog->all(true) as $m) {
            ($this->write)(sprintf("%-40s %s%s%s\n", $m->id, $m->tools ? 'tools ' : '', $m->vision ? 'vision ' : '', $m->thinking ? 'thinking' : ''));
        }
    }

    /** This month's usage. [--user=<id>] */
    public function usage(array $args, array $assoc): void
    {
        $s = $this->meter->monthSummary(isset($assoc['user']) ? (int) $assoc['user'] : null);
        ($this->write)(sprintf("%s: %d tokens over %d requests\n", $s['month'], $s['tokens'], $s['requests']));
    }

    /** Read or write a setting. [<key>] [<value>] */
    public function settings(array $args, array $assoc): void
    {
        if (!isset($args[0])) {
            ($this->write)(json_encode($this->store->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            return;
        }
        if (isset($args[1])) {
            $this->store->set($args[0], $args[1]);
        }
        ($this->write)(json_encode($this->store->get($args[0]), JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function userId(array $assoc): int
    {
        if (isset($assoc['user'])) {
            return (int) $assoc['user'];
        }
        $admins = function_exists('get_users') ? get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']) : [];
        return (int) ($admins[0] ?? 1);
    }
}
```

Wire in `Plugin::register()`:
```php
if (defined('WP_CLI') && constant('WP_CLI')) {
    \WP_CLI::add_command('alpaca-bot', new Cli\ChatCommand($this->get(Chat\Pipeline::class), $this->get(Provider\ModelCatalog::class), $meter, $store));
}
```
(PHPStan: add `wp-cli/wp-cli` stubs via `php-stubs/wp-cli-stubs` to require-dev and `bootstrapFiles`.)

- [ ] **Step 4: Verify green, then run it against alpacabot.wp.test**

Run: `composer check` — pass.
Real (requires Kanboard #2969 done and Ollama running on the host with at least one model pulled):
```bash
wph wp alpacabot -- plugin activate alpaca-bot
wph wp alpacabot -- alpaca-bot settings provider.base_url http://host.docker.internal:11434/v1
wph wp alpacabot -- alpaca-bot models
wph wp alpacabot -- alpaca-bot settings models.default "$(wph wp alpacabot -- alpaca-bot models | head -1 | awk '{print $1}')"
wph wp alpacabot -- alpaca-bot chat "Reply with exactly: pipeline ok"
wph wp alpacabot -- alpaca-bot usage
wph wp alpacabot -- post list --post_type=chat_history --post_status=private --fields=ID,post_title
```
Expected: models listed; the chat streams `pipeline ok` followed by a receipt line; usage shows tokens > 0 and 1 request; one private `chat_history` post titled from the prompt.

Also verify the 0.4 migration: `wph wp alpacabot -- option add alpaca_bot_api_url http://host.docker.internal:11434 && wph wp alpacabot -- option delete alpaca_bot_migrated_04 && wph wp alpacabot -- eval 'do_action("admin_init");' && wph wp alpacabot -- alpaca-bot settings provider.base_url` → `"http://host.docker.internal:11434/v1"`.

- [ ] **Step 5: README status section, commit, push**

Add to `README.md` under the title: a "1.0 development status" list linking the spec and the five plans, stating that the admin UI returns in P3.
```bash
git add -A && git commit -m "feat: wp alpaca-bot chat/models/usage/settings commands" && git push
```
Comment the verification output on the Kanboard ticket for this plan and close it. P2 (REST + settings) may start.

---

## Not in this plan

- REST API, settings admin page, streaming over HTTP (P2). Admin chat UI, assets (P3). Toolkits, shortcodes, abilities, WP AI adapter (P4). CI, Plugin Check, release (P5).
- Deleting legacy `src/Api/*`, `src/Utils/*`, `src/Help.php`, `src/Agents*`, `src/Chat/Screen.php`, `src/Log/Post.php`, `src/Chat/Post.php`, `src/Define.php`, `src/AlpacaBot.php`: P3 Task 6, once nothing references them.

## Self-review

- **Spec coverage (steps 1-2):** PHP 8.4 guard (T1), php-agents + prefixing (T1), Pest scaffold + CI unit job deferred to P5 (noted), provider factory + streaming (T3, T7), usage meter + caps (T5), pipeline hooks (T7), ContextSource seam (T6), settings schema + migration (T2), conversation CPT kept with migration (T4).
- **Placeholders:** none. Two "verify against vendored source before committing" notes exist (OpenAICompatibleProvider ctor names, multimodal UserMessage shape); both name the exact file to read.
- **Type consistency:** `Store::get/modelOverrides` used identically in T3/T5/T7; `Message(role, content, model, usage, created, images, meta)` order matches T4/T7/T8; `Pipeline::send()` returns a Generator whose return is `Result`, consumed the same way in T7 tests and T8; receipt keys identical in T5 (`UsageMeter`) and T7.
