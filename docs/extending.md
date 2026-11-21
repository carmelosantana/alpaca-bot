# Extending Alpaca Bot

How a companion plugin builds on Alpaca Bot: a small plugin of its own that adds a tool, a
guard, an audit trail or a chat UI feature, without patching the core. Every PHP hook named here
is in [hooks.md](hooks.md), generated from the call sites with its parameters. This page says
which one to use for which job, and lists the chat UI's JavaScript hooks, which hooks.md does not
cover.

## The contract and its version

| What | Detail |
| --- | --- |
| Boot signal | `alpaca_bot/loaded` (action) fires once at the end of `plugins_loaded` priority 9, with `int $apiVersion`. Hook it from your main file. If it never fires, Alpaca Bot is not active. |
| Version | `AlpacaBot\Plugin::API_VERSION`, currently `1`. In the browser, `window.alpacaBotChat.apiVersion`. It moves only when a hook, the registrar or a JavaScript hook changes in a way a companion would have to change for, independently of the plugin's own version. |
| Dependency header | `Requires Plugins: alpaca-bot`. WordPress takes a slug only, with no version and no load order, so check `$apiVersion` as well. |
| Deprecation | A hook that is renamed or retired keeps firing through `apply_filters_deprecated()` / `do_action_deprecated()` for at least one minor release before it goes. |

```php
add_action('alpaca_bot/loaded', static function (int $api): void {
    if ($api < 1) {
        return; // older Alpaca Bot: stand down, perhaps with an admin notice
    }
    // safe to use everything below
});
```

## Decision: companions ship abilities (G5)

_Decided 2026-11-20, for the extension API that lands after 0.7._

**A companion that gives the model a tool registers a WordPress ability. It does not implement an
Alpaca Bot interface.** Alpaca Bot already offers any ability an administrator ticks under
Settings › Tools to the model (the abilities toolkit). It runs the ability as the turn's user,
through the ability's own `permission_callback`, input and output schemas, and on WordPress 7.1+
core's execution filters (`wp_pre_execute_ability`, `wp_ability_invoked` and the rest). The same
ability then also reaches WP-CLI, the REST API, the MCP Adapter and every other agent on the
site, so the companion is useful without Alpaca Bot too.

Why not a toolkit API:

- Alpaca Bot's toolkit interface, `ToolkitInterface`, comes from php-agents, which Strauss
  prefixes into `AlpacaBot\Vendor\…`. A companion compiled against that name breaks whenever
  php-agents changes, which is not a contract Alpaca Bot can keep.
- Abilities are core's, versioned by WordPress, and already carry what an agent needs: a JSON
  schema, a permission check and annotations.

The registrar below exists for the one case abilities cannot cover: a tool that needs the turn
itself (to stream, or to call the model again the way `summarize` does). Use it only for that,
and accept that it rides on the prefixed interface. No `AlpacaBot\Api\Tool` wrapper is planned
until a real companion needs one.

## PHP: which hook for which job

| Job | Use | Notes |
| --- | --- | --- |
| Give the model a tool | `wp_register_ability()` on `wp_abilities_api_init` | Ticked by an administrator under Settings › Tools. Gate it in its `permission_callback`. |
| A tool that needs the turn | `alpaca_bot/register_toolkits` (action, handed a `Toolkit\Registrar`) | `$toolkits->add($id, $toolkit, $label, $description, $capability)`. You get a box under Enabled tools, off until ticked, and a `tool.{id}` row under Settings › Access, which `alpaca_bot/capability/tool/{id}` filters. `$id` is a lower-case word; a taken one is refused with `_doing_it_wrong()`. |
| Approve, refuse or audit a tool call | `alpaca_bot/tool/before_call` (filter) and `alpaca_bot/tool/called` (action) | Covers every tool the model calls, whichever toolkit offered it. Return a `WP_Error` (or `false`) to refuse; the error's message is what the model reads. `tool/called` gets the result and the refusal. For abilities on WordPress 7.1+, core's `wp_pre_execute_ability` applies as well, and also covers callers other than Alpaca Bot. |
| Remote MCP calls | `alpaca_bot/mcp/called` | Kept from 0.6. MCP calls also fire `tool/called`. |
| Feed the model site context | `alpaca_bot/context/sources`, `alpaca_bot/context` | Add a `ContextSourceInterface`. |
| Shape a turn | `alpaca_bot/message/before_send`, `alpaca_bot/system_prompt`, `alpaca_bot/message/after_receive` | |
| React to a turn | `alpaca_bot/chat/started`, `chat/completed`, `chat/failed`, `usage/recorded` | Run anything slow from WP-Cron. |
| Keep per-conversation data | `alpaca_bot/conversation/saved`, `alpaca_bot/conversation/deleted` | `deleted` fires when the owner deletes one, not when a failed turn takes back its empty shell. |
| Another model backend | `alpaca_bot/provider`, `alpaca_bot/models` | |
| REST routes in the plugin's namespace | `alpaca_bot/rest/controllers` | Add a `Rest\Controller` subclass. |
| Take a tool away from a user | `alpaca_bot/toolkits` | The last word over one turn's toolkits. |

```php
// Refuse draft_post outside office hours, and log every call.
add_filter('alpaca_bot/tool/before_call', static function (mixed $verdict, string $toolkit, string $tool) {
    if ($toolkit === 'draft_post' && (int) wp_date('G') >= 18) {
        return new WP_Error('after_hours', 'Drafting is closed after 6pm. Tell the user to try tomorrow.');
    }
    return $verdict;
}, 10, 5);

add_action('alpaca_bot/tool/called', static function (string $toolkit, string $tool, array $args, int $userId, $result, ?WP_Error $refusal): void {
    error_log(sprintf('alpaca-bot: user %d %s %s', $userId, $refusal ? 'was refused' : 'called', $tool));
}, 10, 6);
```

## JavaScript: the chat UI

The chat screen, the admin drawer, the block editor sidebar and the `[alpacabot]` shortcode all
run the same chat, and it fires `alpacaBot.*` hooks through core's `wp-hooks` script
(`window.wp.hooks`). Enqueue your script with `wp-hooks` as a dependency and listen with
`wp.hooks.addAction()` / `addFilter()`.

| Hook | Kind | Arguments | When |
| --- | --- | --- | --- |
| `alpacaBot.ready` | action | `{ apiVersion, form, shell }` | A chat has booted: the composer `form` (#ab-form) and its `.ab-wrap` shell. In the drawer and the editor sidebar this happens when the chat is first opened. |
| `alpacaBot.chat.request` | filter | `body`, `{ form }` | The `POST /chat` body (`message`, `conversation_id`, `model`, `images`, `context`) just before it is sent. Return an object; anything else is ignored. `stream` is always `true`. |
| `alpacaBot.turn.started` | action | `{ conversationId, text }` | The server has accepted the turn and is streaming it. A new chat's `conversationId` is `0`. |
| `alpacaBot.turn.completed` | action | `{ conversationId, message, receipt }`, `element \| null` | The reply is finished. `element` is its rendered message, or `null` when the user had already switched conversation. |
| `alpacaBot.turn.failed` | action | `{ conversationId, message }` | The stream ended with an error frame. |
| `alpacaBot.message.rendered` | action | `element` | A finished reply was rendered, or an htmx swap brought messages in (a history switch). It fires once per `.ab-msg`, so decorate messages here. |

A script that may run after the chat has booted awaits `window.alpacaBotChat.ready`, a promise
for the first chat's `{ apiVersion, form, shell }`, instead of listening for `alpacaBot.ready`.
The chat's own bundles create `window.alpacaBotChat` as they load, so depend on the chat's handle
too (`alpaca-bot-chat`; `alpaca-bot-drawer` on other admin screens; `alpaca-bot-editor` in the block editor).

```js
wp.hooks.addFilter('alpacaBot.chat.request', 'my-kb', (body) => ({
  ...body,
  context: { ...body.context, kb: 'faq' },
}));
window.alpacaBotChat?.ready.then(({ form }) => {
  // add a composer button, a hidden field, a keyboard shortcut…
});
```

A filter's answer is checked: an object filter that returns a non-object, or a string filter
that returns another type, is ignored, so a broken listener cannot break the chat.
