=== Alpaca Bot ===  
Contributors: carmelosantana  
Donate link: https://www.patreon.com/carmelosantana  
Tags: ai, large language model, embedding, chatbot, ollama  
Requires at least: 6.4  
Tested up to: 6.4  
Stable tag: 0.4.13  
Requires PHP: 8.1  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  
  
A privately hosted WordPress AI chatbot. Chat with your own hosted LLMs and automate workflows with agents.  
  
== Description ==

Easily draft a post or page from any conversation. Dynamically create new content on the fly or with remote resources collected via `agents`. **Alpaca Bot** offers a familiar chat interface on both desktop and mobile. You can expect a seamless chat experience on any device!

An [Ollama](https://github.com/ollama/ollama) instance is required. [Ollama](https://github.com/ollama/ollama) makes it incredibly easy to self-host large language models locally or in the cloud.

If self-hosting isn't for you, please consider [becoming a Patreon](https://www.patreon.com/carmelosantana) for access to our [hosted](https://status.alpaca.bot/) [Ollama](https://github.com/ollama/ollama) instances and premium [Discord](https://discord.gg/vWQTHphkVt) support.

### Features

- Chose to store conversation history **privately** in your `wp_` database or not at all.
- Use `[agent]` to execute tasks on your behalf, generate dynamic content and more.
- Chat with dozens of pre-trained LLMs or [train your own](https://github.com/ollama/ollama/blob/main/docs/api.md#generate-embeddings).
- Switch conversational model on the fly.
- Create your own custom [system messages](https://github.com/ollama/ollama/blob/main/docs/modelfile.md#system) for highly predictable or formatted responses.

### Usage

#### Text Completion

You have two options to communicate with your AI models;

1. Click **Alpaca Bot** found in the admin menu, below Dashboard and above Posts.
2. **Use the shortcode** `[alpaca]` to generate a response within any post or page.

#### Agents

Use the `[agent]` shortcode to execute tasks on your behalf. Agents are a powerful way to empower your AI models to perform tasks on your behalf.

For example, you can use the `[agent]` shortcode to retrieve content from a remote source. `[agent]`s can interact directly with your models and help summarize a webpage or rewrite content.

##### Example

Basic webpage summarization:

```html
[agent name=summarize model=tinyllama url=https://example.com/]
```

### Shortcodes

#### `[alpaca]` - Chat with Alpaca Bot

*Chat with Alpaca Bot from any post or page.*

##### Attributes

- `model` - The model to use for the text generation. *(optional)*
- `system` - Specifies the [system message](https://github.com/ollama/ollama/blob/main/docs/modelfile.md#system) that will be set in the template. *(optional)*

#### `[agent]` - Execute tasks on your behalf

*Execute tasks via Agents.*

##### Attributes

The following are core attributes that are supported by all agents.

- `name` - The agent to execute.

Agent's communicating with [Ollama](https:/github.com/ollama/ollama) support `[alpaca]` attributes.

#### Caching

Requests can be cached by setting the `cache` attribute. `cache` supports short and long term options.

By default responses are cached to the current post or page.

##### Transient

Numeric values are treated as seconds and will cache the response for the specified duration.

- `cache=60` - Cache the response for 60 seconds.
- `cache=3600` - Cache the response for 1 hour.

##### Post Meta

This is useful for caching responses permanently and associating them with a specific post or page.

- `cache=postmeta` - Cache to current post or page.

##### Option

Use WordPress option storage to cache permanently but not associated with a specific post or page.

This can be useful for sharing responses across multiple pages.

- `cache=option` - Cache to WordPress options.

##### Disable

The following values can disable caching.

- `cache=0` - Disable caching.
- `cache=disable` - Disable caching.
- `cache=false` - Disable caching.

### Core Agents

The following are core agents that are provided by the **Alpaca Bot** plugin.

#### `get`

Retrieve content from a remote source.

##### Attributes

- `url` - The URL to retrieve content from.

#### `summarize`

Summarize remote content.

##### Attributes

- `url` - The URL to summarize.
- `length` - Describe the length of the summary.
- `content` - The type of content we want to summarize.

### Support

If you need help or have questions, please join our [Discord](https://discord.gg/vWQTHphkVt) community.

Premium support and video calls are available to our [Patreon](https://www.patreon.com/carmelosantana) subscribers. We can help setup your [Ollama](https://github.com/ollama/ollama) instance, troubleshoot issues, demonstrate shortcode functionality and more.

Patreon's also receive;

- Access to our hosted [Ollama](https:/github.com/ollama/ollama) instances.
- Priority feature requests.
- Early access to new features and releases.
- Video and community support.

Please consider [becoming a Patreon](https://www.patreon.com/carmelosantana) today!

== Installation ==  
  
### Setup

1. Setup your [Ollama](https://github.com/ollama/ollama) instance in one of the following ways: 
    - Install [Ollama](https://github.com/ollama/ollama) on your localhost or server.
    - **[Become a Patreon](https://www.patreon.com/carmelosantana)** and subscribe to [Alpaca Bot](https://alpaca.bot/) to access our hosted [Ollama](https://github.com/ollama/ollama) instances and premium support.
2. Add your [Ollama](https://github.com/ollama/ollama) API URL to the settings page by navigating to `Alpaca Bot > Settings` in your WordPress admin dashboard.
3. Enter your [Ollama](https://github.com/ollama/ollama) API URL. 
    - Add your [Alpaca Bot](https://app.alpaca.bot/) Username and API Application Password if you are using our hosted service.
4. Click `Save Changes`.

== Screenshots ==  
  
1. Main chat interface with model list, chat history and prompt input.   
2. Chat interface with a conversation history.   
3. Drafting a post from generated responses.   
4. Override system message for custom responses.   
5. Custom assistant settings.   
6. Shortcode examples.