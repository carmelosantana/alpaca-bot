# Final whole-branch fix wave — report

Base `d75f643` → HEAD `9983476`, branch `1.0` (worktree `angry-borg-d97763`). Eight findings, eight commits
plus one test-cleanup commit. Nothing pushed; PR untouched.

| Commit | Finding |
| --- | --- |
| `41c1416` test(chat): request a model other than the default in the empty-catalog test | F7 |
| `6d2743a` fix(bootstrap): never load vendor/autoload.php at runtime; own PSR-4 loader for AlpacaBot\ | F1 + F8 |
| `60c9856` feat(chat): bound the transcript sent to the model with chat.context_messages | F2 |
| `5670b85` fix(chat): keep the 0.4 messages meta after converting it, never delete it | F3 |
| `76b5146` fix(settings): run the 0.4 migration on init so WP-CLI triggers it | F4 |
| `c7c223b` docs(readme): keep readme.txt on the shipped 0.4 requirements until 1.0 is tagged | F5 |
| `c07b25c` ci: unit job on PHP 8.4 (composer install, composer check, bootstrap guard) | F6 |
| `9983476` test(chat): drop the metadata_exists stubs the store no longer calls | cleanup after F3 |

## Final `composer check` at HEAD (raw, colours stripped)

```
Note: Using configuration file .../phpstan.neon.dist.
 22/22 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors
   PASS  Tests\Unit\Chat\CapPolicyTest
  ...
   PASS  Tests\Unit\Settings\SchemaTest

   PASS  Tests\Unit\Settings\StoreTest

  Tests:    193 passed (1133 assertions)
  Duration: 1.00s
```

193 `✓` lines; `grep -ciE "risky|skipped|warn|deprecat|incomplete"` over the full output: 0.
(Was 190 passed / 1107 assertions at `d75f643`.)

---

## F1 — runtime autoloads `vendor/` (Important)

### What I inspected in `vendor/composer/`

The finding is correct, and slightly understated for a dev checkout.

- `vendor/composer/autoload_psr4.php` (dev install): 48 unprefixed namespaces, including
  `CarmeloSantana\PHPAgents\`, `League\CommonMark\`, `Symfony\Component\HttpClient\`,
  `Symfony\Contracts\HttpClient\`, `Psr\Log\`, `Psr\Container\`, `Nette\`, `Mockery\`, `Pest\`, `PhpParser\`.
- `vendor/composer/autoload_files.php` (dev install): 19 eagerly required files —
  `symfony/deprecation-contracts/function.php` (defines global `trigger_deprecation()`), polyfill bootstraps
  for mbstring, ctype, intl-grapheme, intl-normalizer, php85, php80, `symfony/string/Resources/functions.php`,
  plus `mockery/mockery/library/helpers.php`, `phpstan/phpstan/bootstrap.php` (registers the PHPStan phar
  autoloader), Pest's `Functions.php`/`Pest.php`, Brain Monkey's `inc/api.php`.
- After `composer install --no-dev` (run via `bin/build-vendor.sh`, see F8): `autoload_psr4.php` still carries
  13 unprefixed namespaces — `Symfony\Contracts\HttpClient\`, `Symfony\Component\HttpClient\`, `Psr\Log\`,
  `Psr\EventDispatcher\`, `Psr\Container\`, `League\CommonMark\`, `CarmeloSantana\PHPAgents\` among them — and
  `autoload_files.php` still requires `symfony/deprecation-contracts/function.php` and
  `symfony/polyfill-php80/bootstrap.php` unconditionally (via `autoload_real.php`'s `$requireFile` loop). So
  the "ship `vendor/autoload.php` restricted to the plugin's map" mitigation would fatal the moment those
  packages were removed from the zip. Confirmed, not assumed.
- `vendor-prefixed/composer/autoload_psr4.php`: 12 namespaces, every one under `AlpacaBot\Vendor\`.
  `vendor-prefixed/composer/autoload_files.php`: 2 files — the deprecation function (strauss renamed it to
  `alpacabot_vendor_trigger_deprecation()`, verified in the file) and the php80 polyfill bootstrap (returns
  at the top on PHP ≥ 8.0). `autoload_real.php` there is classmap-authoritative and its ClassLoader is the
  prefixed `AlpacaBot\Vendor\Composer\Autoload\ClassLoader`.

### What changed

`alpaca-bot.php` no longer requires `vendor/autoload.php`. It registers a ten-line `spl_autoload_register`
closure mapping `AlpacaBot\` (excluding `AlpacaBot\Vendor\`) onto `src/`, then requires
`vendor-prefixed/autoload.php`, then boots. The readiness guard checks only `vendor-prefixed/autoload.php`
and its notice names that directory. `vendor/autoload.php` stays dev/test only; `tests/Pest.php` was
already requiring both explicitly and is untouched. Chosen over adding the plugin map to the strauss
target because it is zero-config, has no dependence on strauss's autoloader-generation behaviour, and is
readable in one glance.

### What the runtime now loads

Exactly two autoloaders: the prefixed Composer ClassLoader (classmap-authoritative, `AlpacaBot\Vendor\*`
only) and the `src/` closure. Two eagerly required files, both prefixed/guarded as above. Nothing from
`vendor/`.

### Load verification — `bin/check-bootstrap.php`

New script: defines `ABSPATH`, stubs `plugin_dir_path`, `plugin_dir_url`, `add_action`, `esc_html__`,
requires `alpaca-bot.php`, and asserts eleven things. It never loads `vendor/autoload.php` itself.

RED — run against the `d75f643` bootstrap (`git stash` of my `alpaca-bot.php`), `php bin/check-bootstrap.php`:

```
ok   AlpacaBot\Plugin resolves (src/ loader)
ok   AlpacaBot\Chat\Pipeline resolves (nested namespace)
ok   AlpacaBot\Vendor\...PHPAgents\Message\UserMessage resolves (vendor-prefixed/)
ok   AlpacaBot\Vendor\League\CommonMark\CommonMarkConverter resolves (vendor-prefixed/)
FAIL unprefixed CarmeloSantana\PHPAgents\Message\UserMessage does NOT resolve
FAIL unprefixed League\CommonMark\CommonMarkConverter does NOT resolve
FAIL unprefixed Symfony\Component\HttpClient\HttpClient does NOT resolve
FAIL unprefixed Psr\Log\LoggerInterface does NOT resolve
FAIL Composer\Autoload\ClassLoader (vendor/) is NOT loaded
FAIL unprefixed trigger_deprecation() is NOT defined
ok   Plugin::boot() hooked plugins_loaded at 9

autoloaders registered: 3 (AlpacaBot\Vendor\Composer\Autoload\ClassLoader::loadClass, Composer\Autoload\ClassLoader::loadClass, PHPStan\PharAutoloader::loadClass)
exit=1
```

GREEN — same command at HEAD (dev install, and again after `--no-dev`, and again in the clean clone under F6):

```
ok   AlpacaBot\Plugin resolves (src/ loader)
ok   AlpacaBot\Chat\Pipeline resolves (nested namespace)
ok   AlpacaBot\Vendor\...PHPAgents\Message\UserMessage resolves (vendor-prefixed/)
ok   AlpacaBot\Vendor\League\CommonMark\CommonMarkConverter resolves (vendor-prefixed/)
ok   unprefixed CarmeloSantana\PHPAgents\Message\UserMessage does NOT resolve
ok   unprefixed League\CommonMark\CommonMarkConverter does NOT resolve
ok   unprefixed Symfony\Component\HttpClient\HttpClient does NOT resolve
ok   unprefixed Psr\Log\LoggerInterface does NOT resolve
ok   Composer\Autoload\ClassLoader (vendor/) is NOT loaded
ok   unprefixed trigger_deprecation() is NOT defined
ok   Plugin::boot() hooked plugins_loaded at 9

autoloaders registered: 2 (AlpacaBot\Vendor\Composer\Autoload\ClassLoader::loadClass, closure in .../alpaca-bot.php)
exit=0
```

PHPStan still scans `alpaca-bot.php` (`scanFiles`); `composer analyse` → `[OK] No errors`.

## F8 — `bin/build-vendor.sh` ran strauss twice (Minor, promoted)

Now: `composer install --no-dev --no-interaction` (post-install-cmd → `@prefix`, once), then
`php bin/check-bootstrap.php`, then a reminder that `vendor/` is a `--no-dev` install. The
`composer prefix` and `dump-autoload --no-dev --optimize` steps are gone: the first was the duplicate, the
second optimised an autoloader the runtime no longer loads. Run end to end:

```
$ bash bin/build-vendor.sh
Package operations: 0 installs, 0 updates, 62 removals
...
> @php bin/strauss
[notice] Building dependency list...
...
[notice] Done
ok   AlpacaBot\Plugin resolves (src/ loader)
... (11 ok)
autoloaders registered: 2 (...)
vendor/ is now a --no-dev install; run 'composer install' to get the dev tools back.
```

Then `composer install` restored the dev tools and `composer check` was green again.

## F2 — unbounded transcript replay (Important)

Read `src/Api/Render.php:591-593` and `src/Define.php:129-135` first. 0.4 sliced the stored transcript with
`array_slice($messages_raw, -Options::getPlaceholder('chat_history_limit'))` when the limit was `> 0`, then
appended the new user turn. Two things the finding got slightly wrong, for the record: the shipped 0.4
default was the field's `placeholder`, **5**, not 20 (`Define.php:134`); and `Options::getPlaceholder()` does
`$value ? $value : placeholder`, so a stored `0` fell back to 5 at runtime — the documented "0 sends all"
never actually worked in 0.4. I implemented the documented semantics as asked (0 = everything) and took the
reviewer's 20 as the default; 5 was tuned for 2024-era `num_ctx` 2048 and 8192 is now the schema default.

Changes:
- `Settings\Schema`: `chat.context_messages` — integer, default 20, min 0, max 1000, section `chat`, label
  "Messages sent to the model", description spelling out "the new one included" and "0 sends the whole
  conversation".
- `Chat\Pipeline::assemble()`: `$limit = (int) $this->store->get('chat.context_messages')`, iterate
  `$limit > 0 ? array_slice($c->messages, -$limit) : $c->messages`. The system message is built before the
  loop from the prompt + context block, never from the stored turns, so the slice cannot drop it. The stored
  transcript is untouched (bound is on what is sent).
- `Settings\Migrate04::MAP`: `chat_history_limit` now lands on `chat.context_messages` instead of
  `chat.history_limit`. **This departs from the letter of R7**, which kept `chat.history_limit` as the target
  because no field with the true 0.4 meaning existed; R7's stated reason (carry the intent, don't drop it) is
  served exactly by the new field. `chat.history_limit` now starts at its 1.0 default on upgrade. Flagged for
  the controller.

Tests (written first):
- `PipelineTest`: "sends only the most recent chat.context_messages turns, keeps the system prompt, and stores
  the whole conversation" (limit 2, 4 stored turns + new one → model sees `[System 'Be brief', Assistant 'A2',
  User 'Q3']`; conversation holds 6; the `ab_messages` write holds all 6) and "sends the whole transcript when
  chat.context_messages is 0".
- `SchemaTest`: default 20, `'0'` → 0, `'-3'` → 0, `'x'` → 20.
- `Migrate04Test`: `chat.context_messages` = 10, `chat.history_limit` = 20.

RED (`vendor/bin/pest --filter 'context_messages|chat_history_limit|appends /v1'`):

```
  ⨯ it sends only the most recent chat.context_messages turns, keeps th… 0.21s
  ✓ it sends the whole transcript when chat.context_messages is 0
  ⨯ it maps 0.4 options into the new schema and appends /v1 to the base… 0.06s
  ⨯ it bounds the transcript sent to the model with chat.context_messages, 20 by default, 0 allowed for everything
   FAILED  Tests\Unit\Chat\PipelineTest > it sends only the most recent chat…
  Failed asserting that two arrays are identical.
   FAILED  Tests\Unit\Settings\Migrate04Test > it maps 0.4 options into the…
  Failed asserting that null is identical to 10.
   FAILED  Tests\Unit\Settings\SchemaTest > it bounds the transcript sent to…
  Failed asserting that null is identical to 20.
  Tests:    3 failed, 1 passed (16 assertions)
```

(The `0` case passes before the change by construction — no bound is "everything" — and guards the
implementation from the other side.) GREEN after the change: `4 passed`, then `composer check`
`193 passed (1126 assertions)` at that commit.

## F3 — lossy conversion then irreversible delete of 0.4 `messages` (Important)

Chosen: **leave `messages` in place**, no backup key. Same bytes either way, zero extra writes, `Migrate04`
keeps reading the key it already reads to recover `post_author`, and P3 — which owns the sweep of every
0.4 leftover — removes it in one place. `readMessages()` no longer calls `delete_post_meta` or
`metadata_exists` at all: the conversion is purely additive. That also closes the parked Task 4 finding
(the unconditional delete on the `ab_messages`-exists path) by removing the path. `META_LEGACY`'s docblock
records the decision and the lossiness; `owned()`'s docblock names the ordering dependency (its author check
keeps `load()` off an author-0 row until `Migrate04::migrateConversations()` has claimed it, so it must stay
ahead of every read and keep rejecting author 0).

Tests: five existing `ConversationStoreTest` cases rewritten to expect `delete_post_meta` never /
`metadata_exists` never, `$deletes` = 0 on the round trip, and the "stale blob beside ab_messages" case now
"not read, not converted, not deleted".

RED (`vendor/bin/pest tests/Unit/Chat/ConversationStoreTest.php`):

```
  ⨯ it loads only the owner's conversation, converts legacy meta once a… 0.03s
  ⨯ it reads ab_messages directly once converted and never looks at the legacy key again
  ⨯ it leaves a legacy blob sitting beside ab_messages alone: not read, not converted, not deleted
  ⨯ it round-trips a legacy transcript through convert, write and re-read
  ⨯ it skips non-array legacy entries while converting, as 0.4 did
  Method delete_post_meta(<Any Arguments>) from Mockery_0 should be called
  Method metadata_exists(<Any Arguments>) from Mockery_0 should be called
  Method metadata_exists(<Any Arguments>) from Mockery_0 should be called
  Failed asserting that 1 is identical to 0.
  Method delete_post_meta(<Any Arguments>) from Mockery_0 should be called
  Tests:    5 failed, 21 passed (112 assertions)
```

GREEN: `26 passed (118 assertions)`; `composer check` `193 passed (1126 assertions)`.

## F4 — migration only on `admin_init` (Important)

`Plugin::register()` now hooks the migration closure on `init` at priority **20** — after both post types
register at 10, so the conversation batch's `get_posts(post_type => chat_history)` runs against a
registered type. WP-CLI fires `init`. The ledger's `__()` constraint is about `plugins_loaded`;
`_load_textdomain_just_in_time` only objects when `!did_action('init') && !doing_action('init')`, so a
priority-20 `init` callback is fine (noted in the code comment).

Tests (`PluginTest`): the register() test now captures the `init` closure at priority 20 and asserts
`admin_init` is never added; the WP-CLI test additionally captures the same closure in the process that
defines `WP_CLI`, runs it under 0.4 options (`api_url`, `default_model`) and asserts the store then reads
`http://ollama.internal:11434/v1` and `qwen3:8b`.

RED (`vendor/bin/pest tests/Unit/PluginTest.php`):

```
  ✓ it exposes a version and boots once                                  0.11s
  ⨯ it registers the settings store, provider factory, model catalog, c… 0.05s
  ⨯ it registers the wp alpaca-bot command when WP-CLI is the running p… 0.01s
   FAILED  Tests\Unit\PluginTest > it registers the settings store, p…  Error
  Failed asserting that null is an instance of class Closure.
  Tests:    2 failed, 1 passed (26 assertions)
```

GREEN: `3 passed (29 assertions)`; `composer check` `193 passed (1133 assertions)`.

## F5 — `readme.txt` requirements vs `Stable tag: 0.4.17` (Important)

`readme.txt`: `Requires at least: 6.4`, `Requires PHP: 8.1` (the values at `80d7c25`, verified with
`git show 80d7c25:readme.txt`); `Stable tag: 0.4.17` and `Tested up to: 6.5.5` untouched. `alpaca-bot.php`'s
header stays `6.9` / `8.4`. `README.md`'s "1.0 development status" section now has a paragraph saying
`readme.txt` deliberately describes the shipped 0.4.x release until 1.0 is tagged and why it must not be
"fixed". No test (documentation).

## F6 — no CI (Important)

`.github/workflows/ci.yml`: one job on `ubuntu-latest`, triggers `push` to `main`/`1.0` and `pull_request`,
`permissions: contents: read`, `actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803` (v6.1.0,
`persist-credentials: false`), `shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240` (2.37.2,
PHP 8.4, no coverage, composer v2), then `composer install --no-interaction --no-progress`, `composer check`,
`php bin/check-bootstrap.php`. No `pull_request_target`, no cache, no secrets. Both SHAs were resolved with
`git ls-remote --tags` and confirmed as commit objects through the GitHub API (checkout: 2026-07-16
"backport fixes to releases-v6"; setup-php: 2026-06-08 "Bump version to 2.37.2"); both are weeks old, past
any release-age quarantine. `powerup:supply-chain` was consulted for the Actions rules.

Confirmation the steps work in this repo's shape: fresh `git clone --branch 1.0` of the worktree into the
scratchpad (no `vendor/`, no `vendor-prefixed/`), then the three steps verbatim:

```
=== clean clone: c07b25c [1.0]; vendor? no; vendor-prefixed? no; .github? ci.yml
=== step: composer install --no-interaction --no-progress ===
Package operations: 76 installs, 0 updates, 0 removals
Package operations: 57 installs, 0 updates, 0 removals      <- tools/strauss nested manifest
> @php bin/strauss
[notice] Done
=== step: composer check ===
 [OK] No errors
  Tests:    193 passed (1133 assertions)
  Duration: 0.93s
=== step: php bin/check-bootstrap.php ===
ok   Plugin::boot() hooked plugins_loaded at 9
autoloaders registered: 2 (...)
exit=0
```

Not added (kept small): zizmor, CODEOWNERS on `.github/`, a Composer cache. Worth a P5 line.

## F7 — vacuous assertion (Minor, promoted)

`PipelineTest` "lets a requested model through when the catalog is unreachable" now requests `qwen3:8b`
and asserts `$h->model` is `qwen3:8b` (harness default is `llama3.2`). Proven to discriminate by
temporarily inserting `if ($listed === []) { return $this->catalog->defaultId($this->store); }` into
`Pipeline::model()`:

```
  ⨯ it lets a requested model through when the catalog is unreachable,…  0.22s
  Failed asserting that two strings are identical.
  -'qwen3:8b'
  +'llama3.2'
  at tests/Unit/Chat/PipelineTest.php:374
  Tests:    1 failed (10 assertions)
```

Mutant reverted (`git checkout -- src/Chat/Pipeline.php`, tree clean); test passes against the shipped code.

## Self-review notes

- `alpaca-bot.php` still parses on PHP < 8.4 (the version guard's notice must render): the loader closure
  uses only typed params and `str_starts_with`, which are parse-safe on 7.x and never executed there.
- The `AlpacaBot\Tests\` namespace also matches the `src/` loader prefix; nothing at runtime references it
  and `is_file()` fails closed. Pest never loads `alpaca-bot.php`, so the suite has one loader set.
- `chat.context_messages` slices raw stored turns, so a transcript can start on an assistant turn — exactly
  what 0.4's `array_slice` did. Not smoothed on purpose (YAGNI, and matches the old behaviour).
- Deferred Minors from the final review were not touched.
- The git status snapshot at session start named a branch `claude/angry-borg-d97763`; the worktree is on
  `1.0` (`git worktree list`). All commits are on `1.0`.
