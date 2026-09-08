# Alpaca Bot

<div align="center">
  
### A privately hosted WordPress AI Chatbot
  
  <img src="https://carmelosantana.org/alpacabot/wp-content/uploads/sites/4/2024/02/alpaca-bot-icon-1006.png" alt="Alpaca Bot" width="256px">

  [![Discord](https://img.shields.io/discord/485971823821979648?logo=discord&label=Discord&color=72edad)](https://discord.gg/vWQTHphkVt)
  ![GitHub release (latest by date)](https://img.shields.io/github/v/release/carmelosantana/alpaca-bot?label=Latest%20Release&color=668bf2)
  <a href="https://www.patreon.com/carmelosantana"><img src="https://img.shields.io/badge/Subscribe-Become%20a%20Patreon-826EB4?logo=patreon" alt="Patreon">
  </a>

  **WordPress plugin for quick content creation and workflow automation!**
</div>

---

## 0.5.0 development status

The `develop` branch is a ground-up rewrite of the 0.4 plugin that ships as **0.5.0**, in five phases. 1.0 is reserved for feature complete and fully tested (see `CLAUDE.md`). Everything below
this section describes the 0.5 code on this branch.

- **P1 — foundations, provider, pipeline** (done): settings schema and 0.4 migration, provider
  factory with streaming, usage meter and monthly caps, the chat pipeline and its hooks, and a
  WP-CLI command (`wp alpaca-bot chat|models|usage|settings`).
- **P2 — REST API and settings screen** (done): one namespace, `alpaca-bot/v1` (chat, streaming,
  conversations, models, settings, usage), and the Settings API admin page. Reference, with
  auth, streaming and error examples: [docs/api.md](docs/api.md).
- **P3 — view layer and assets** (done): the admin chat screen, rendered server-side from
  components, with htmx swapping the selects and a small TypeScript bundle driving the streamed
  turn; Lucide icons, a stylesheet on the admin colour variables, and the 0.4 tree deleted.
- **P4 — toolkits, shortcodes, abilities, WordPress AI adapter** (next)
- **P5 — hardening and release**

`readme.txt` (the wordpress.org listing) intentionally keeps describing the shipped 0.4.x release
(`Requires at least: 6.4`, `Requires PHP: 8.1`, `Stable tag: 0.4.17`) until 0.5.0 is tagged. The
plugin file header in `alpaca-bot.php` is what gates the running code and already says PHP 8.4 /
WordPress 6.9. Do not "fix" `readme.txt` on this branch: on wordpress.org, `Requires PHP: 8.4`
next to `Stable tag: 0.4.17` would stop offering 0.4.x updates to exactly the PHP 8.1 sites that
release stays published for.

Spec: [0.5 core refactor](docs/superpowers/specs/2026-09-05-alpaca-bot-1-0-core-refactor.md).
Plans: [P1](docs/superpowers/plans/2026-09-05-alpaca-bot-p1-foundations-provider-pipeline.md),
[P2](docs/superpowers/plans/2026-09-05-alpaca-bot-p2-rest-and-settings.md),
[P3](docs/superpowers/plans/2026-09-05-alpaca-bot-p3-view-layer-and-assets.md),
[P4](docs/superpowers/plans/2026-09-05-alpaca-bot-p4-toolkits-abilities-wp-ai.md),
[P5](docs/superpowers/plans/2026-09-05-alpaca-bot-p5-hardening-and-release.md).

---

**Alpaca Bot** is a chat screen inside WordPress admin, talking to a model you host. Conversations stay on your site, in your own database, and only their author can open them. It runs against [Ollama](https://github.com/ollama/ollama) out of the box, or any OpenAI-compatible endpoint.

### Features

- A chat screen in wp-admin: replies stream in as they are written, with a copy button on every message and code block, "Edit and resend" on your own, and an image attached from the media library for a model that can see.
- Your conversations, stored **privately** on your site (or not at all: the Privacy tab decides) and listed in the screen's history.
- Switch models per conversation; where the site allows it, your pick is remembered as your default.
- A system prompt, per-model overrides (temperature, context window, keep-alive) and a receipt under every reply: model, tokens, time.
- Monthly usage caps, per site and per user, with the meter behind them.
- A REST API under `alpaca-bot/v1` ([docs/api.md](docs/api.md)) and a WP-CLI command.

---

- [Screenshots](#screenshots)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
- [Usage](#usage)
  - [The chat screen](#the-chat-screen)
  - [Settings](#settings)
  - [REST API and WP-CLI](#rest-api-and-wp-cli)
- [Shortcodes](#shortcodes)
- [Support](#support)
- [Funding](#funding)
- [Made Possible By](#made-possible-by)
- [License](#license)

---

## Screenshots

![The chat screen on a new conversation](docs/screenshots/2026-09-08-p3-default-1440-welcome.png)

> A new conversation: the model and history selects in the header, the composer at the foot.

![A reply with a code block](docs/screenshots/2026-09-08-p3-default-1440-code.png)

> A reply with a code block, its copy button, and the receipt under it.

## Requirements

- PHP 8.4+, WordPress 6.9+, an Ollama instance (or any provider php-agents supports)
  - Permalinks enabled

## Installation

1. Download the latest release from the [releases page](https://github.com/carmelosantana/alpaca-bot/releases).
2. Upload the plugin to your WordPress site.
3. Activate the plugin.

A checkout needs `composer install` (which builds `vendor-prefixed/`) and `pnpm install && pnpm build` (the script, stylesheet and icon sprite under `assets/`, which are not committed).

## Setup

1. Install [Ollama](https://github.com/ollama/ollama) on your localhost or server.
2. In your WordPress admin, open `Alpaca Bot > Settings` and, on the Provider tab, enter the endpoint's base URL. For Ollama it ends in `/v1`: `http://localhost:11434/v1`.
3. Click `Save Changes`. The Models tab then lists what the provider serves; pick a default.

⭐️ **[Become a Patreon](https://www.patreon.com/carmelosantana)** and support [Alpaca Bot](https://carmelosantana.org/alpacabot/) development. ⭐️

## Usage

### The chat screen

Click **Alpaca Bot** in the admin menu, below Dashboard and above Posts. The screen is open to every user who can edit posts (filter `alpaca_bot/admin/menu_capability` to change that).

- The **model** select in the header picks the model for this conversation; where the site allows it, your pick is saved as your default. The **history** select opens one of your earlier conversations, and **New chat** starts a fresh one.
- Type in the box at the foot of the screen. **Enter** sends, **Shift+Enter** adds a line, **Escape** clears the box.
- The image button attaches a picture from the media library to your next message, for a model that can see. The largest image the screen takes is the site's own upload limit.
- Replies stream in as they are written. Every message has a **Copy** button, your own have **Edit and resend**, and a code block has its own copy button. Under a reply is its receipt: the model, the tokens it used and how long it took.
- The **Help** tab at the top right of the screen repeats this, and says what became of 0.4's shortcodes.

### Settings

`Alpaca Bot > Settings` (administrators) is one page in six tabs: **Provider** (the endpoint, its key, the timeout), **Models** (the default, temperature, context window, keep-alive, and per-model overrides), **Chat** (system prompt, welcome text, what users may change), **Privacy** (whether conversations and the usage log are stored, and for how long), **Limits** (monthly token caps for the site and per user) and **Tools**. Every field is also readable and writable over the REST API (`GET`/`PUT /settings`).

### REST API and WP-CLI

Everything the screen does is a route under `alpaca-bot/v1`: a turn (`POST /chat`, then its stream as server-sent events), conversations, models, settings and usage. [docs/api.md](docs/api.md) is the reference, with authentication, streaming and error examples. From the command line, `wp alpaca-bot chat|models|usage|settings` does the same.

## Shortcodes

0.5 registers no shortcodes. 0.4's `[alpacabot]` and `[alpacabot_agent]` were removed in the rewrite and return with the toolkits in a later 0.x release.

**If you upgrade a 0.4 site, check your content.** WordPress prints a shortcode nothing registers exactly as written, so a post or page that still contains one shows the shortcode itself to visitors, as plain text, where the generated content used to be. Search your posts and pages for `[alpacabot` and, in each, remove the shortcode or replace it with the text you want shown.

## Support

If you need help or have questions, please join our [Discord](https://discord.gg/vWQTHphkVt) community.

Premium support and video calls are available to our [Patreon](https://www.patreon.com/carmelosantana) subscribers. We can help set up your [Ollama](https://github.com/ollama/ollama) instance, troubleshoot issues, and more.

Patreon's also receive;

- Access to our hosted [Ollama](https:/github.com/ollama/ollama) instances.
- Priority feature requests.
- Early access to new features and releases.
- Video and community support.

Please consider [becoming a Patreon](https://www.patreon.com/carmelosantana) today!

## Funding

If you find this project useful or use it in a commercial environment please consider donating today with one of the following options.

- Bitcoin `bc1qhxu9yf9g5jkazy6h4ux6c2apakfr90g2rkwu45`
- Ethereum `0x9f5D6dd018758891668BF2AC547D38515140460f`
- Patreon [`patreon.com/carmelosantana`](https://www.patreon.com/carmelosantana)

## Made Possible By

- Emma Delaney's [How to Create Your Own ChatGPT in HTML CSS and JavaScript](https://emma-delaney.medium.com/how-to-create-your-own-chatgpt-in-html-css-and-javascript-78e32b70b4be)
- [Lucide](https://lucide.dev) Beautiful & consistent icons - ISC license
- [htmx](https://htmx.org/) High power tools for HTML - 0BSD license
- [league/commonmark](https://commonmark.thephpleague.com/) Markdown parser for PHP - BSD-3-Clause license
- [php-agents](https://github.com/carmelosantana/php-agents) Provider-agnostic AI agents for PHP - MIT license
- [Ollama](https://github.com/ollama/ollama) Get up and running with large language models locally - MIT license

## License

- [GNU General Public License version 2](http://www.gnu.org/licenses/gpl-2.0.html) or later
