# Alpaca Bot

<div align="center">
  
  ### 🌟 A privately hosted WordPress AI agent.💡
  
  <img src="https://alpaca.bot/wp-content/uploads/2024/02/alpaca-bot-icon-1006.png" alt="Alpaca Bot" width="256px">

  [![Discord](https://img.shields.io/discord/1198290062316683275?logo=discord&label=Discord)](https://discord.gg/DqAUPAVhnR)
</div>

*WordPress plugin for quick content creation and code completion! ✨Train✨ models and provide your editors with a conversational interface to your private LLM.*

---

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
- [Funding](#funding)
- [Made Possible By](#made-possible-by)
- [License](#license)

---

## Features

- Store conversation history **privately** in your `wp_` database.
- Chat with dozens of pre-trained LLMs or train your own.
- Switch conversational model on the fly.

## Requirements

- Access to [Ollama](https://github.com/jmorganca/ollama) API
- PHP `^8.1`
- WordPress `^6.0`
  - Permalinks enabled

## Installation

1. Download the latest release from the [releases page](https://github.com/carmelosantana/alpaca-bot/releases).
2. Upload the plugin to your WordPress site.
3. Activate the plugin.

## Setup

1. Setup your [Ollama](https://github.com/ollama/ollama) instance in one of the following ways:
   - Install [Ollama](https://github.com/ollama/ollama) on your localhost or server.
   - ⭐️ **Subscribe** to [Alpaca Bot](https://alpaca.bot/) for premium API features and accelerated GPU processing. 🚀
   - Check out [Ollama Press](https://ollama.press/) for CPU only pool processing.
2. Add your Ollama API URL to the settings page.
   1. Navigate to `Alpaca Bot > Settings` in your WordPress admin dashboard.
   2. Enter your Ollama API URL.
      1. Add your [Alpaca Bot](https://alpaca.bot/) API Token if you are using the hosted service.
   3. Click `Save Changes`.

## Funding

If you find this project useful or use it in a commercial environment please consider donating today with one of the following options.

- Bitcoin `bc1qhxu9yf9g5jkazy6h4ux6c2apakfr90g2rkwu45`
- Ethereum `0x9f5D6dd018758891668BF2AC547D38515140460f`

## Made Possible By

- [Ollama](https://github.com/ollama/ollama) and the research behind these great open source [Large Language Models](https://ollama.ai/library) (LLMs).
- [Emma Delaney](https://emma-delaney.medium.com/how-to-create-your-own-chatgpt-in-html-css-and-javascript-78e32b70b4be) *How to Create Your Own ChatGPT in HTML CSS and JavaScript*
- [Hint.css](https://github.com/chinchang/hint.css) A CSS only tooltip library for your lovely websites.

## License

- The code is licensed [GNU General Public License version 2](http://www.gnu.org/licenses/gpl-2.0.html) or later.
- The documentation is licensed [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/).
