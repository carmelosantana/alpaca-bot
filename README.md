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

## 1.0 development status

The `1.0` branch is a ground-up rewrite of the 0.4 plugin, in five phases. Everything below this
section describes 0.4; it is refreshed as each phase lands.

- **P1 — foundations, provider, pipeline** (in progress): settings schema and 0.4 migration,
  provider factory with streaming, usage meter and monthly caps, the chat pipeline and its hooks,
  and a WP-CLI command (`wp alpaca-bot chat|models|usage|settings`) as the only user surface.
  There is deliberately no admin UI in this phase.
- **P2 — REST API and settings screen**
- **P3 — view layer and assets**: the admin chat page returns here.
- **P4 — toolkits, shortcodes, abilities, WordPress AI adapter**
- **P5 — hardening and release**

`readme.txt` (the wordpress.org listing) intentionally keeps describing the shipped 0.4.x release
(`Requires at least: 6.4`, `Requires PHP: 8.1`, `Stable tag: 0.4.17`) until 1.0 is tagged. The
plugin file header in `alpaca-bot.php` is what gates the running code and already says PHP 8.4 /
WordPress 6.9. Do not "fix" `readme.txt` on this branch: on wordpress.org, `Requires PHP: 8.4`
next to `Stable tag: 0.4.17` would stop offering 0.4.x updates to exactly the PHP 8.1 sites that
release stays published for.

Spec: [1.0 core refactor](docs/superpowers/specs/2026-09-05-alpaca-bot-1-0-core-refactor.md).
Plans: [P1](docs/superpowers/plans/2026-09-05-alpaca-bot-p1-foundations-provider-pipeline.md),
[P2](docs/superpowers/plans/2026-09-05-alpaca-bot-p2-rest-and-settings.md),
[P3](docs/superpowers/plans/2026-09-05-alpaca-bot-p3-view-layer-and-assets.md),
[P4](docs/superpowers/plans/2026-09-05-alpaca-bot-p4-toolkits-abilities-wp-ai.md),
[P5](docs/superpowers/plans/2026-09-05-alpaca-bot-p5-hardening-and-release.md).

---

Easily draft a post or page from any conversation. Dynamically create new content on the fly or with remote resources collected via `agents`. **Alpaca Bot** offers a familiar chat interface on both desktop and mobile. You can expect a seamless chat experience on any device!

An [Ollama](https://github.com/ollama/ollama) instance is required. [Ollama](https://github.com/ollama/ollama) makes it incredibly easy to self-host large language models locally or in the cloud.

### Features

- Chose to store conversation history **privately** in your `wp_` database or not at all.
- Use `[alpacabot_agent]` to execute tasks on your behalf, generate dynamic content and more.
- Chat with dozens of pre-trained LLMs or [train your own](https://github.com/ollama/ollama/blob/main/docs/api.md#generate-embeddings).
- Switch conversational model on the fly.
- Create your own custom [system messages](https://github.com/ollama/ollama/blob/main/docs/modelfile.md#system) for highly predictable or formatted responses.

---

- [Screenshots](#screenshots)
  - [Chat Interface](#chat-interface)
  - [Custom Assistants](#custom-assistants)
  - [Dynamic Content Generation](#dynamic-content-generation)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
- [Usage](#usage)
  - [Text Completion](#text-completion)
  - [Agents](#agents)
    - [Example](#example)
- [Shortcodes](#shortcodes)
  - [`[alpacabot]` - Chat with Alpaca Bot](#alpacabot---chat-with-alpaca-bot)
    - [Attributes](#attributes)
  - [`[alpacabot_agent]` - Execute tasks on your behalf](#alpacabot_agent---execute-tasks-on-your-behalf)
    - [Attributes](#attributes-1)
  - [Caching](#caching)
    - [Transient](#transient)
    - [Post Meta](#post-meta)
    - [Option](#option)
    - [Disable](#disable)
- [Core Agents](#core-agents)
  - [`get`](#get)
    - [Attributes](#attributes-2)
  - [`summarize`](#summarize)
    - [Attributes](#attributes-3)
- [Support](#support)
- [Funding](#funding)
- [Made Possible By](#made-possible-by)
- [License](#license)

---

## Screenshots

### Chat Interface

![Alpaca Bot](https://carmelosantana.org/alpacabot/wp-content/uploads/sites/4/2024/03/screenshot-1.png)

> Main chat interface with model list, chat history and prompt input.

![Chat interface with a conversation history](https://carmelosantana.org/alpacabot/wp-content/uploads/sites/4/2024/03/screenshot-2.png)

> Chat interface with a conversation history.

![Draft to post](https://carmelosantana.org/alpacabot/wp-content/uploads/sites/4/2024/03/screenshot-3.png)

> Drafting a post from generated responses.

### Custom Assistants

![Custom assistant](https://carmelosantana.org/alpacabot/wp-content/uploads/sites/4/2024/03/screenshot-4.png)

> Override `system` message for custom responses.

![Assistant tab](https://carmelosantana.org/alpacabot/wp-content/uploads/sites/4/2024/03/screenshot-5.png)

> Custom assistant settings.

### Dynamic Content Generation

![Shortcodes](https://carmelosantana.org/alpacabot/wp-content/uploads/sites/4/2024/03/screenshot-6.png)

> Shortcode examples.

## Requirements

- PHP 8.4+, WordPress 6.9+, an Ollama instance (or any provider php-agents supports)
  - Permalinks enabled

## Installation

1. Download the latest release from the [releases page](https://github.com/carmelosantana/alpaca-bot/releases).
2. Upload the plugin to your WordPress site.
3. Activate the plugin.

## Setup

1. Install [Ollama](https://github.com/ollama/ollama) on your localhost or server.
2. Add your [Ollama](https://github.com/ollama/ollama) API URL to the settings page by navigating to `Alpaca Bot > Settings` in your WordPress admin dashboard.
3. Enter your [Ollama](https://github.com/ollama/ollama) API URL.
4. Click `Save Changes`.

⭐️ **[Become a Patreon](https://www.patreon.com/carme$$losantana)** and support [Alpaca Bot](https://carmelosantana.org/alpacabot/) development. ⭐️

## Usage

### Text Completion

You have two options to communicate with your AI models;

1. Click **Alpaca Bot** found in the admin menu, below Dashboard and above Posts.
2. **Use the shortcode** `[alpacabot]` to generate a response within any post or page.

### Agents

Use the `[alpacabot_agent]` shortcode to execute tasks on your behalf. Agents are a powerful way to empower your AI models to perform tasks on your behalf.

For example, you can use the `[alpacabot_agent]` shortcode to retrieve content from a remote source. `[alpacabot_agent]`s can interact directly with your models and help summarize a webpage or rewrite content.

#### Example

Basic webpage summarization:

`[alpacabot_agent name=summarize model=tinyllama url=https://example.com/]`

## Shortcodes

### `[alpacabot]` - Chat with Alpaca Bot

*Chat with Alpaca Bot from any post or page.*

#### Attributes

- `model` - The model to use for the text generation. *(optional)*
- `system` - Specifies the [system message](https://github.com/ollama/ollama/blob/main/docs/modelfile.md#system) that will be set in the template. *(optional)*

### `[alpacabot_agent]` - Execute tasks on your behalf

*Execute tasks via Agents.*

#### Attributes

The following are core attributes that are supported by all agents.

- `name` - The agent to execute.

Agent's communicating with [Ollama](https:/github.com/ollama/ollama) support `[alpacabot]` attributes.

### Caching

Requests can be cached by setting the `cache` attribute. `cache` supports short and long term options.

By default responses are cached to the current post or page.

#### Transient

Numeric values are treated as seconds and will cache the response for the specified duration.

- `cache=60` - Cache the response for 60 seconds.
- `cache=3600` - Cache the response for 1 hour.

#### Post Meta

This is useful for caching responses permanently and associating them with a specific post or page.

- `cache=postmeta` - Cache to current post or page.

#### Option

Use WordPress option storage to cache permanently but not associated with a specific post or page.

This can be useful for sharing responses across multiple pages.

- `cache=option` - Cache to WordPress options.

#### Disable

The following values can disable caching.

- `cache=0` - Disable caching.
- `cache=disable` - Disable caching.
- `cache=false` - Disable caching.

## Core Agents

The following are core agents that are provided by the **Alpaca Bot** plugin.

### `get`

Retrieve content from a remote source.

#### Attributes

- `url` - The URL to retrieve content from.

### `summarize`

Summarize remote content.

#### Attributes

- `url` - The URL to summarize.
- `length` - Describe the length of the summary.
- `content` - The type of content we want to summarize.

## Support

If you need help or have questions, please join our [Discord](https://discord.gg/vWQTHphkVt) community.

Premium support and video calls are available to our [Patreon](https://www.patreon.com/carmelosantana) subscribers. We can help  setup your [Ollama](https://github.com/ollama/ollama) instance, troubleshoot issues, demonstrate shortcode functionality and more.

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
- Google [Material Design Icons](https://material.io/resources/icons/?style=baseline) - Apache-2.0 license
- [Hint.css](https://github.com/chinchang/hint.css) A CSS only tooltip library - MIT license
- [TextRank](https://github.com/DavidBelicza/PHP-Science-TextRank) Automatic text summarization for PHP - MIT license
- [Ollama](https://github.com/ollama/ollama) Get up and running with large language models locally - MIT license
- [Parsedown](https://github.com/erusev/parsedown) A better Markdown parser - MIT license

## License

- [GNU General Public License version 2](http://www.gnu.org/licenses/gpl-2.0.html) or later
