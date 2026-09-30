# 0.5.0 admin polish (pre-release) — plan

Kanboard #4337 (relates to #2978). Six small admin-screen fixes made before 0.5.0 is tagged.
Branch `polish`, cut from `origin/develop` at c85c340; the controller pushes to `develop`.
There is no separate spec: Carmelo's brief is the authority and is quoted in each task.

## Global Constraints

- **Release state is frozen.** Do not touch `Version:`, `Plugin::VERSION`, `package.json` version,
  or `readme.txt`'s header block. No tags, no merges to `main`, no `wph svn`. Never write "1.0";
  this line is 0.5.0.
- **Gates** (run before you report DONE): `composer check`, `pnpm check`, and
  `composer test:integration` (runs inside alpaca10's cli container). Report their tails.
- **No new dependencies.** No `pnpm add` / `composer require`.
- **TDD where there is logic.** Write the failing Pest test first, run it and see it fail, then
  implement. Unit tests live in `tests/Unit/Admin/*` and `tests/Unit/View/*`.
- **Commit only the paths you changed**: `git add <path>…`, never `git add -A` / `commit -a`;
  another agent may be writing scratch files. Conventional subject, lower-case, house style
  (`fix(admin): …`, `feat(admin): …`, `test: …`). No attribution trailers. Do not push.
- **Browser checks use `node ~/Projects/wp-harness/bin/wph.js shot alpaca10 <path> … --out f.png`**
  (run with no args for usage). Never use the Claude in-app browser or Claude in Chrome on
  `*.wp.test`. Exit 1 is a real finding (HTTP status, console error, PHP diagnostic, debug.log
  growth). Never touch `alpacabot.wp.test`. alpaca10 serves this worktree; after editing
  `resources/css` or `resources/ts`, run `pnpm build` so `assets/` (git-ignored) is current.
- **False load-bearing comments are the named review target.** This codebase's docblocks carry
  the argument. When you edit near one, re-check that its "because" is still true. Two mechanisms:
  (1) a sentence arguing from a counterfactual about the rejected alternative, or claiming a
  completeness it lacks ("its one caller", "every X", "the only Z"); (2) a sentence that was true
  when written and that your change silently falsifies. Before committing, list in your report
  every sentence you re-checked and any you corrected.

## Task 1: Header spacing matches core admin pages

**Brief:** on `admin.php?page=alpaca-bot` the title + "New chat" row sits closer to the admin bar
than on core screens.

**Measured before (controller, 2026-09-10):** `#wpbody-content h1` `getBoundingClientRect().top`:

| viewport | chat screen | `edit.php?post_type=page` |
| --- | --- | --- |
| 1440x900 | 42 | 42 |
| 782x900 | **116** | 106 |

The chat `.wrap` (`.ab-wrap`, `resources/css/alpaca-bot.css` ~40-60) sets `margin-top: 0;
padding-top: 10px`, moving `.wrap`'s 10px margin inside so its `height: calc(100vh - admin bar -
footer)` is the whole space. At the 782px breakpoint core's own h1 sits flush with the wrap's top
(the margin's effect differs there), so the moved-in padding adds 10px core does not have. Find
the real mechanism before fixing it, and cite it.

Files: `resources/css/alpaca-bot.css`; read `src/View/Chat/Header.php` and `src/Admin/ChatScreen.php`
for context.

**Acceptance (measured, include the output in your report):**
- h1 top on the chat screen equals its value on `edit.php?post_type=page` at `--size 1440x900` and
  at `--size 782x900`, using
  `--eval 'document.querySelector("#wpbody-content h1").getBoundingClientRect().top'`.
- The full-height chat layout is intact at both widths: the composer (`.ab-composer` or whatever
  the shell renders at the foot) stays inside the viewport and the document does not scroll
  (`document.scrollingElement.scrollHeight <= innerHeight`). Measure before and after.
- The front-end shell (`.ab-wrap--front`) is unaffected. Read its block.
- Take `--out` screenshots of the chat screen at both widths and name their paths in the report.

No PHP logic changes, so no unit test; the measurements are the evidence.

## Task 2: Help sidebar asks for support and a star, not issues

`src/Admin/HelpTabs.php` `support()` (~line 179) lists Discord, "Open an issue on GitHub", and
Patreon. Replace the issue item with two asks:

- get support on the WordPress.org forum: `https://wordpress.org/support/plugin/alpaca-bot/`
- star the project on GitHub: `https://github.com/carmelosantana/alpaca-bot`

Keep the existing `self::link()` helper and escaping (`esc_html__`, `__` into `link()`). Keep
Discord and Patreon. Update the intro sentence and the method docblock ("plus the issue tracker")
so neither describes the old list. Update `tests/Unit/Admin/HelpTabsTest.php` (the test named
"…points support at Discord, Patreon and the issue tracker"). It must assert both new hrefs and
assert that `/issues` is gone. Write the test change first and watch it fail.

`git grep -n 'alpaca-bot/issues'` for other copies. Change only `src/`. Report anything elsewhere
(readme.txt body, docs) without editing it.

## Task 3: Cell padding around the model name in the per-model overrides table

Settings › Models › "Per-model overrides": the model-name cells (`<th scope="row">`) touch the
table's left edge. The table is built in `SettingsPage::renderOverrides()` and already carries
`class="widefat striped"`. It renders inside a Settings API `.form-table` row. The likely cause is
core's `.form-table th { padding: 20px 10px 20px 0 }` (and `.form-table td` rules) cascading into
the nested table. Confirm the cause with `wph shot --eval getComputedStyle(...)` first.

Fix so the nested table's cells get core's own `widefat` cell padding. Prefer core classes and
markup over new CSS where they fit. If a CSS rule is unavoidable, scope it to this table
(`resources/css`, whichever stylesheet the settings screen loads, or add a class the stylesheet
can target). Check which stylesheet the Settings screen enqueues (`src/Admin/Assets.php`).

**Acceptance:** at `--size 1440x900` and `--size 782x900` on
`/wp-admin/admin.php?page=alpaca-bot-settings&tab=models`, the first tbody `th`'s computed
`padding-left` equals a core `widefat` cell's (measure one, e.g. `.widefat td` on
`edit.php?post_type=page` or core's value from `wp-admin/css/common.css`). Report the numbers and
screenshot both widths (`--full` is long; crop with `--eval`/scroll, or screenshot the viewport
after `--scroll` to the table). If markup changes, TDD it in `tests/Unit/Admin/SettingsPageTest.php`.

## Task 4: Traced SVG of the alpaca logo (ComfyUI)

Controller-run asset task, done outside the repo and reviewed visually. The traced file is
committed in Task 5. Output: `alpaca-bot.svg`: black line art, specks and white background
removed, tight viewBox, `fill="currentColor"`, optimised only with a tool already installed.

## Task 5: Assistant avatar defaults to the alpaca logo; drop icon-80.png

`src/View/Chat/Participants.php:24-25` falls back to `assets/img/icon-80.png` (a black speech
bubble) when `chat.assistant_avatar` is empty. That fallback feeds `.ab-welcome__avatar`
(MessageList.php:33, 128px, `border-radius: 50%`) and every assistant bubble (MessageBubble.php:46,
36px, round). Switch the default to the alpaca logo: the Task 4 SVG committed as
`assets/img/alpaca-bot.svg` (the controller supplies the file path and the choice between the SVG
and the PNG in the dispatch). TDD: update `tests/Unit/View/Chat/ParticipantsTest.php` and the two
`icon-80` assertions in `tests/Unit/View/Chat/ComponentsTest.php` first.

Delete `assets/img/icon-80.png` only after `git grep -n icon-80` shows nothing in shipped code.
`bin/build-zip.sh:66` lists it among the paths the zip must contain; replace that entry with the
new file. `docs/reviews/2026-09-09-performance-baseline.md` is a dated measurement record; leave it.
Check the Participants docblock ("else the plugin's own icon") still says something true.

An `<img>` does not inherit CSS `color`, so `currentColor` in the file renders as black. Verify the
logo is legible in the welcome block and in an assistant bubble: `wph shot` the chat screen, and a
conversation with an assistant reply (open one from the history select, or send a short prompt;
user 1's default model is `ministral-3:3b`). Do both in the default admin colour scheme and a dark
one (`wp user meta update 1 admin_color midnight` through `node ~/Projects/wp-harness/bin/wph.js
wp alpaca10 …`, or check `wph` usage). Restore `fresh` afterwards.

**Carmelo's decision (2026-09-10, ~12:40; this supersedes the earlier off-white-disc decision and
the traced SVG):** the default avatar is the designer's illustrated alpaca on the brand's cream
(`#f7e8c8`): `/home/carmelo/Projects/Alpaca Bot/alpaca-bot-brand/avatar/alpaca-bot-512-cream.png`.
It is shown round-cropped in the designer's mockup
`/home/carmelo/Projects/Alpaca Bot/alpaca-bot-brand/mockups/wp-admin-chat-1440.png`. The cream is
baked into the image, so there is no CSS disc and no default-only scoping: the existing
`border-radius: 50%` crop is the whole treatment, and a site's own avatar renders exactly as before.
Ship it as a PNG sized for its largest use. The welcome image is 128px, so 256×256 covers 2× screens.
Name it for what it is (e.g. `assets/img/alpaca-bot-avatar.png`), and downscale and compress it with
a tool already on the machine (Python PIL is available). Add no dependency. The traced
`alpaca-bot.svg` does not ship.

## Task 6: A unique admin menu icon

`src/Admin/Menu.php:49` passes `dashicons-format-chat` to `add_menu_page()`. **The designer's final
mark ships (2026-09-10; it supersedes the interim D2).** Copy
`/home/carmelo/Projects/Alpaca Bot/alpaca-bot-brand/svg/menu-icon-20.min.svg.txt` byte for byte to
`assets/img/menu-icon.svg`. It is 594 bytes, with `viewBox="0 0 100 100"` and `fill="currentColor"`
on the root, and no hard-coded colours. Any square viewBox scales, so the 20×20 viewBox wording below
is superseded. Do **not** use `svg/menu-icon-20.svg`: it carries a ~12 KB C2PA metadata blob. Use
the designer's `wordpress/menu-icon.php` as reference only, because its comment gives a false reason:
"WordPress … recolours it per admin colour scheme, because the SVG is base64 and uses
fill=\"currentColor\"". Core's svg-painter recolours an SVG menu icon by rewriting the `fill`
attributes in the decoded data URI. Read `wp-admin/js/svg-painter.js` on alpaca10 and state what it
actually does, including whether the root's `fill="currentColor"` is the attribute it rewrites.
Verify the icon recolours: `wph shot` the sidebar at rest, on hover and when current, in `fresh` and
`light`. Pass `assets/img/menu-icon.svg` to `add_menu_page()` as a `data:image/svg+xml;base64,…`
URI. The file is the single source of truth: either read and encode it at registration, or embed it
with a test proving the embedded string decodes to the file's bytes. Pick one and say why. TDD in
`tests/Unit/Admin/MenuTest.php`. If the file is read at runtime, add it to `bin/build-zip.sh`'s
must-contain list.

## Task 7: wordpress.org listing art from the brand package

Carmelo approved this (2026-09-10): replace the plugin icon and banner in `.wordpress-org/` with the
designer's. Source: `/home/carmelo/Projects/Alpaca Bot/alpaca-bot-brand/`, namely
`png/icon-128x128.png`, `png/icon-256x256.png` (cream ground, 17% padding) and
`mockups/banner-1544x500.png`. The designer's `wordpress/README.md` says `resources/wordpress-org/`;
this repo's directory is `.wordpress-org/`. Its current files are `banner-1544x500.jpg`,
`banner-772x250.jpg`, `icon-128x128.png` and `icon-256x256.png`. Look at `.github/workflows/release.yml`
and the 10up deploy action it pins to learn how the directory reaches SVN (it is rsynced wholesale).

- Icons: overwrite the two PNGs. Check their sizes are exact.
- Banner: wordpress.org reads `banner-772x250.(png|jpg)` and `banner-1544x500.(png|jpg)`. Ship both
  sizes; derive the 772×250 by downscaling the 1544×500 with PIL. Remove the old `.jpg` pair so the
  directory cannot hold two files for one slot. Keep each file reasonably small (compress the PNGs,
  or use a high-quality JPEG if a PNG is enormous) and report the sizes.
- Do not touch `screenshot-*.png`. Report that they now show the old menu icon and avatar.
- Nothing here publishes. The directory reaches wordpress.org only when a `v*` tag runs `release.yml`,
  and no tag is made in this plan.
- No unit test applies. The evidence is `file`/`identify`-style dimensions, byte sizes, and a
  side-by-side preview PNG of old and new.
