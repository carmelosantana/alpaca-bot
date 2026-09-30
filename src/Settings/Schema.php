<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

use AlpacaBot\Access;
use AlpacaBot\Plugin;
use AlpacaBot\Toolkit\AbilitiesToolkit;

/**
 * The single source of truth for what lives in the `alpaca_bot_settings` option.
 *
 * Keys are dotted `section.name` strings. The label/description metadata is
 * consumed by the settings screen that arrives in a later phase.
 *
 * SECRETS are the fields whose stored value must never be shown back or lost by accident
 * (today the provider API key). They read out as MASK wherever they are shown, and a write that
 * carries MASK back means "keep what is stored"; sanitize() owns that rule so every writer of the
 * option (the REST route, the Settings API's sanitize callback, Store) resolves it the same way
 * and none can store the literal mask as the key.
 *
 * An MCP server's header value is a secret too, but a nested one, and SECRETS is a list of
 * top-level keys. sanitizeMcpServers() applies the same three-valued rule to it, with MASK kept as
 * MASK because this class has no stored value to put there: the value is kept out of this
 * option. Mcp\ServerSettings takes it out on every update_option(), keeps it in Mcp\Secrets, and
 * leaves MASK or '' in the row; add_option() on its own is the exception its docblock names.
 *
 * @phpstan-type Field array{type:'string'|'integer'|'number'|'boolean'|'array'|'select'|'checkbox-list', default:mixed, section:string, label:string, description?:string, options?:array<string,string>, min?:int|float, max?:int|float, sanitize?:callable(mixed, array<string, mixed>): mixed}
 */
final class Schema
{
    /** What a secret reads back as when one is stored, and what a writer sends to leave it alone. */
    public const MASK = '••••';

    /** @var list<string> the fields sanitize() applies the mask rule to */
    public const SECRETS = ['provider.api_key'];

    /** @var list<string> the `array` fields whose value is a list; every other `array` field is a map */
    public const LISTS = ['toolkits.abilities', 'toolkits.mcp_servers'];

    /**
     * An MCP server's id: a lowercase letter, then up to 23 of `[a-z0-9_]`. It starts with a letter
     * because an id made only of digits becomes an int key in the `access.mcp` map, which neither
     * sanitizeAccessMcp() nor the Access tab can hold, so that server would stay administrators
     * only with no row to change it. `\z` and not `$`, because `$` also matches before a final
     * LF. With no `.` and no `/` in it, no two ids share the filter name Access builds from
     * `mcp.<id>`.
     */
    private const MCP_ID = '/^' . self::MCP_ID_PATTERN . '\z/';

    /**
     * MCP_ID without its anchors, for a pattern that has anchors of its own: the id group of
     * `GET /view/mcp-tools/{id}` (Rest\ViewController). It holds no parenthesis, which
     * Controller::routeKey() needs of a route's group.
     */
    public const MCP_ID_PATTERN = '[a-z][a-z0-9_]{0,23}';

    /**
     * An MCP server's tool-name prefix: 1 to 16 characters, lowercase letters and digits with
     * single underscores between them, starting with a letter. The model sees a tool as
     * ToolName::fit('<prefix>__<name>'), and a tool name has to start with a letter or `_`
     * (Toolkit\ToolName), so a prefix starting with a digit would have every tool of that server
     * renamed. No `__` inside and no `_` at the end, so the first `__` of such a name is where the
     * prefix ends: fit() leaves `[a-z0-9_]` as it is and cuts only past its 55th character, and
     * `<prefix>__` is at most 18. Two servers, whose prefixes differ (sanitizeMcpServers()), can
     * then never give two tools one name, where `trk` with `_x` and `trk_` with `x` would both be
     * `trk___x`. SchemaTest checks that by brute force.
     */
    private const MCP_TOOL_PREFIX = '/^(?=.{1,16}\z)[a-z](?:_?[a-z0-9])*\z/';

    /**
     * The prefix no server may take: AbilitiesToolkit names every ability `ability__<namespace>__<name>`
     * (AbilitiesToolkit::toolName()), so a server under it could name a tool as an ability is
     * named. The abilities are the only built-in tools whose names hold `__`: `web_fetch`,
     * `summarize`, `draft_post` and the agent's `done` hold none, so no fitted MCP name can be one
     * of them.
     */
    public const RESERVED_PREFIX = 'ability';

    /**
     * Force tools on for one model: the stored value of `models.overrides[<m>][tools]` that
     * offers the enabled toolkits whatever the catalogue says (Provider\Model::$tools). One of
     * the two values sanitizeOverrides() accepts for that cell; the third state, inherit, is not
     * stored at all, so an absent key and a blank cell both leave the catalogue's word standing.
     * Read on the turn by Store::toolsOverride(), never baked into the model catalog.
     *
     * @since 0.5.0
     */
    public const TOOLS_ON = 'on';

    /**
     * Force tools off for one model: the other stored value of `models.overrides[<m>][tools]`,
     * and the one this setting exists for — a model that advertises tools and then writes the
     * call out as prose. It beats the catalogue whatever the catalogue says, a provider that
     * declares `tools` included; that is the case it exists for. Same wire format and same
     * inherit rule as TOOLS_ON above.
     *
     * @since 0.5.0
     */
    public const TOOLS_OFF = 'off';

    /** @return array<string, array{label:string, description:string}> */
    public static function sections(): array
    {
        return [
            'provider' => ['label' => __('Provider', 'alpaca-bot'), 'description' => __('Where models run. Ollama by default; WordPress AI providers when WordPress 7.0+ has them registered.', 'alpaca-bot')],
            'models' => ['label' => __('Models', 'alpaca-bot'), 'description' => __('Default model and generation options, with per-model overrides.', 'alpaca-bot')],
            'chat' => ['label' => __('Chat', 'alpaca-bot'), 'description' => __('What users see and can change in the chat screen.', 'alpaca-bot')],
            'privacy' => ['label' => __('Privacy', 'alpaca-bot'), 'description' => __('What is stored in your database. Message content lives only in saved conversations; a usage receipt never contains it.', 'alpaca-bot')],
            'governance' => ['label' => __('Limits', 'alpaca-bot'), 'description' => __('Server-enforced monthly token caps, counted from the usage receipts. 0 means unlimited. Completion tokens include the reasoning a thinking model produces before its answer (it comes back as message.meta.reasoning), so a thinking model spends a cap faster than its visible reply suggests: a short answer can cost several hundred reasoning tokens first.', 'alpaca-bot')],
            'toolkits' => ['label' => __('Tools', 'alpaca-bot'), 'description' => __('Settings for built-in tools.', 'alpaca-bot')],
            'access' => ['label' => __('Access', 'alpaca-bot'), 'description' => __('Who may use each part of Alpaca Bot. Each row is the capability that is checked, and the default the matching alpaca_bot/capability/* filter receives, so a capability named in code wins over this tab.', 'alpaca-bot')],
        ];
    }

    /** @return array<string, Field> */
    public static function fields(): array
    {
        return [
            // Both options are always offered, whatever this WordPress has: hiding `wp-ai` when
            // the AI client is absent would show Ollama selected on a site whose stored value is
            // `wp-ai`, and the next save of this tab would silently make that the setting. The
            // stored value stands; the factory falls back and an admin notice names the fallback.
            'provider.kind' => ['type' => 'select', 'default' => 'ollama', 'section' => 'provider', 'label' => __('Provider', 'alpaca-bot'), 'description' => __('Ollama talks to your Ollama server directly, using the settings below, and streams replies as they are produced. WordPress AI provider routes every turn through the AI client built into WordPress 7.0 and later, to whichever AI provider plugin this site has installed and configured (Settings → Connectors); the settings below are not used, the models offered are that provider\'s, and a reply arrives whole rather than streamed, since the WordPress client does not stream.', 'alpaca-bot'), 'options' => ['ollama' => 'Ollama', 'wp-ai' => __('WordPress AI provider', 'alpaca-bot')]],
            'provider.base_url' => ['type' => 'string', 'default' => 'http://localhost:11434/v1', 'section' => 'provider', 'label' => __('Base URL', 'alpaca-bot'), 'description' => __('OpenAI-compatible endpoint. For Ollama this ends in /v1.', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeUrl']],
            'provider.api_key' => ['type' => 'string', 'default' => '', 'section' => 'provider', 'label' => __('API key', 'alpaca-bot'), 'description' => __('Optional. Sent as a Bearer token.', 'alpaca-bot')],
            'provider.timeout' => ['type' => 'integer', 'default' => 60, 'section' => 'provider', 'label' => __('Timeout (seconds)', 'alpaca-bot'), 'description' => __('How long one request may wait for the provider before it fails. The first request after a restart loads the model from cold, and a large model can take longer than the 60 seconds default to load; raise this if that first request times out and the next one works. Leave it low otherwise, so a provider that has stopped answering fails quickly instead of holding every chat open.', 'alpaca-bot'), 'min' => 5, 'max' => 600],
            'models.default' => ['type' => 'string', 'default' => '', 'section' => 'models', 'label' => __('Default model', 'alpaca-bot'), 'description' => __('Used when a request names no model. A thinking model (qwen3, deepseek-r1, gpt-oss) reasons before it answers; the reasoning is returned as message.meta.reasoning and its tokens count as completion tokens under the monthly caps.', 'alpaca-bot')],
            'models.temperature' => ['type' => 'number', 'default' => 0.7, 'section' => 'models', 'label' => __('Temperature', 'alpaca-bot'), 'min' => 0, 'max' => 2],
            'models.num_ctx' => ['type' => 'integer', 'default' => 8192, 'section' => 'models', 'label' => __('Context window (tokens)', 'alpaca-bot'), 'description' => __('Ollama\'s num_ctx, sent with every request when the provider is Ollama. It has no effect with the WordPress AI provider, whose context window is the AI provider plugin\'s to set.', 'alpaca-bot'), 'min' => 512, 'max' => 1048576],
            'models.keep_alive' => ['type' => 'string', 'default' => '5m', 'section' => 'models', 'label' => __('Keep alive', 'alpaca-bot'), 'description' => __('How long Ollama keeps the model loaded, e.g. 5m, 1h, -1.', 'alpaca-bot')],
            'models.overrides' => ['type' => 'array', 'default' => [], 'section' => 'models', 'label' => __('Per-model overrides', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeOverrides']],
            'chat.system_prompt' => ['type' => 'string', 'default' => '', 'section' => 'chat', 'label' => __('System prompt', 'alpaca-bot')],
            'chat.welcome' => ['type' => 'string', 'default' => __('How can I help?', 'alpaca-bot'), 'section' => 'chat', 'label' => __('Welcome message', 'alpaca-bot')],
            'chat.placeholder' => ['type' => 'string', 'default' => __('Message Alpaca Bot', 'alpaca-bot'), 'section' => 'chat', 'label' => __('Input placeholder', 'alpaca-bot')],
            'chat.user_can_change_model' => ['type' => 'boolean', 'default' => true, 'section' => 'chat', 'label' => __('Users can change model', 'alpaca-bot')],
            'chat.context_messages' => ['type' => 'integer', 'default' => 20, 'section' => 'chat', 'label' => __('Messages sent to the model', 'alpaca-bot'), 'description' => __('The most recent messages of the conversation sent with each request, the new one included. 0 sends the whole conversation. Fewer messages cost fewer tokens per turn but lose older context.', 'alpaca-bot'), 'min' => 0, 'max' => 1000],
            'chat.history_limit' => ['type' => 'integer', 'default' => 20, 'section' => 'chat', 'label' => __('Conversations shown in history', 'alpaca-bot'), 'min' => 1, 'max' => 200],
            'chat.spellcheck' => ['type' => 'boolean', 'default' => true, 'section' => 'chat', 'label' => __('Spellcheck the input', 'alpaca-bot')],
            'chat.assistant_avatar' => ['type' => 'string', 'default' => '', 'section' => 'chat', 'label' => __('Assistant avatar URL', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeUrl']],
            'privacy.save_history' => ['type' => 'boolean', 'default' => true, 'section' => 'privacy', 'label' => __('Save conversations', 'alpaca-bot')],
            'privacy.usage_log' => ['type' => 'boolean', 'default' => true, 'section' => 'privacy', 'label' => __('Record the model and conversation on usage receipts', 'alpaca-bot'), 'description' => __('A usage receipt is always written for every reply, on or off, so the monthly caps keep working: it always holds the token counts, the duration, the size in bytes of what the tools handed back and the user who asked, and never the messages themselves or what a tool returned. On, the receipt also records the model name and a link to the conversation. Off, it holds those numbers only.', 'alpaca-bot')],
            'privacy.usage_retention_days' => ['type' => 'integer', 'default' => 90, 'section' => 'privacy', 'label' => __('Keep usage receipts for (days)', 'alpaca-bot'), 'description' => __('A daily cleanup deletes receipts older than this. 0 keeps them forever; 3650 (ten years) is the most, and a larger number is stored as 3650. Conversations are never touched. A receipt is counted toward the caps until its month ends, so keep this at 31 or more while a cap is set.', 'alpaca-bot'), 'min' => 0, 'max' => 3650],
            'governance.site_monthly_tokens' => ['type' => 'integer', 'default' => 0, 'section' => 'governance', 'label' => __('Site-wide monthly token cap', 'alpaca-bot'), 'min' => 0, 'max' => PHP_INT_MAX],
            'governance.user_monthly_tokens' => ['type' => 'integer', 'default' => 0, 'section' => 'governance', 'label' => __('Per-user monthly token cap', 'alpaca-bot'), 'min' => 0, 'max' => PHP_INT_MAX],
            // One list, not a boolean per toolkit: a toolkit is enabled by the id it registers
            // under (Toolkit\Registry), so a later release adds one by adding an option here and
            // nothing else changes shape. The schema is the single place that knows what the
            // built-ins are called. The default names web_fetch, summarize and draft_post, the
            // ones 0.5 shipped on; `abilities` is offered and left off, because what it can do is
            // whatever the abilities an administrator ticks can do.
            'toolkits.enabled' => ['type' => 'checkbox-list', 'default' => ['web_fetch', 'summarize', 'draft_post'], 'section' => 'toolkits', 'label' => __('Enabled tools', 'alpaca-bot'), 'description' => __('What the assistant may do besides answer. A tool that is off is not offered to the model at all.', 'alpaca-bot'), 'options' => ['web_fetch' => __('Fetch a web page the user names', 'alpaca-bot'), 'summarize' => __('Summarize text', 'alpaca-bot'), 'draft_post' => __('Draft a post or page (never publishes)', 'alpaca-bot'), 'abilities' => __('Call the site\'s WordPress abilities you tick', 'alpaca-bot')]],
            'toolkits.user_agent' => ['type' => 'string', 'default' => 'AlpacaBot/' . Plugin::VERSION . ' (+https://github.com/carmelosantana/alpaca-bot)', 'section' => 'toolkits', 'label' => __('User agent for fetch tools', 'alpaca-bot'), 'description' => __('Sent with every request web_fetch makes. The default names the installed version, but every save of the settings stores this field with the rest, so after the first save it keeps what it held then and a later version\'s default does not replace it. Empty it to send the installed version\'s default.', 'alpaca-bot')],
            'toolkits.abilities' => ['type' => 'array', 'default' => [], 'section' => 'toolkits', 'label' => __('Abilities the model may call', 'alpaca-bot'), 'description' => __('The WordPress abilities that WordPress and other plugins register on this site. Each one you tick becomes a tool of the abilities tool, which is offered only while it is on under Enabled tools, and only to users its Access row admits. Two ticked abilities that would reach the model under the same tool name are marked, and neither is offered. A call runs as the user whose turn it is, through the ability\'s own permission check. Alpaca Bot\'s own abilities are never offered.', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeAbilities']],
            'toolkits.mcp_servers' => ['type' => 'array', 'default' => [], 'section' => 'toolkits', 'label' => __('MCP servers', 'alpaca-bot'), 'description' => sprintf(
                /* translators: %1$s: the rule a header name has to meet (Schema::mcpHeaderNameRule()) */
                __('Remote MCP servers, and the tools of each you approve for the model. The address must be https, with no user name or password in it: a credential goes in the header, never in the address. The address, its query string included, is stored in the clear and read back by this screen and by GET /settings, so it is no place for a secret. The header name has to be %1$s. The header value is sent as typed, with nothing put in front of it: for Authorization type the scheme and the key (Bearer …), and for a header such as X-API-Key the bare key. The address is checked when it is saved from this screen or over the REST API, and again each time Alpaca Bot builds a connection to it: on Discover tools, and on a chat turn that lists its tools. A private or other special-purpose address is refused, even when it is the site\'s own host. Moving a server to another host or port clears its header value, so it is never sent to an address it was not set for; type it again. A connection to an MCP server never goes through a proxy, neither one set for WordPress nor one set in the server\'s environment, so a site whose outbound traffic has to use a proxy cannot reach one. The header value is kept in an option of its own that WordPress does not load on every page, and this screen only shows whether one is set.', 'alpaca-bot'),
                self::mcpHeaderNameRule(),
            )],
            'access.chat' => self::access('chat', __('Chat', 'alpaca-bot'), __('Who may open the chat screen and use the chat REST routes. A tool needs its own row as well as this one.', 'alpaca-bot')),
            'access.tool.web_fetch' => self::access('tool.web_fetch', __('Tool: fetch a web page', 'alpaca-bot'), __('Who may run a turn that can fetch a page, and who may use [alpacabot_agent], which runs the same tool on the shortcode\'s behalf. It governs the next fetch, not the last one: a shortcode answer already cached on a post stands until its cache expires. The tool makes this server send an outbound request and hands the reply back; the Tools help tab says what that grants.', 'alpaca-bot')),
            'access.tool.summarize' => self::access('tool.summarize', __('Tool: summarize', 'alpaca-bot'), __('Who may run a turn that can summarize text through the model.', 'alpaca-bot')),
            'access.tool.draft_post' => self::access('tool.draft_post', __('Tool: draft a post', 'alpaca-bot'), __('Who may run a turn that can write a draft. The tool also asks the post type\'s own capability of the same user, so this row can only narrow that.', 'alpaca-bot')),
            'access.tool.abilities' => self::access('tool.abilities', __('Tool: the site\'s abilities', 'alpaca-bot'), __('Who may run a turn that can call the abilities allowlisted under Tools. An ability runs with its own permission callback as well.', 'alpaca-bot')),
            'access.settings.read' => self::access('settings.read', __('Read settings over REST', 'alpaca-bot'), __('Who may read GET /settings and GET /settings/schema. The provider API key is never included; revealing it always needs an administrator.', 'alpaca-bot')),
            'access.settings.write' => self::access('settings.write', __('Write settings over REST', 'alpaca-bot'), __('Who may send PUT /settings. provider.base_url is a settable field, so this row decides who can point every turn at another server. So is an MCP server\'s URL: moving one to another host drops its header value rather than send it there, and this row decides who can do that. The Settings screen itself is always administrators.', 'alpaca-bot')),
            'access.shortcode' => self::access('shortcode', __('Shortcodes', 'alpaca-bot'), __('Who triggers a generation by viewing a page carrying [alpacabot], or the deprecated [alpacabot_agent], which generates through the same rules. A visitor never does, whatever this says.', 'alpaca-bot')),
            // Every MCP server's row in one map, server id => capability, and not a key per
            // server: sanitize() rebuilds the option from this list and drops whatever is not in
            // it, so a per-server key could never be saved. Access::stored('mcp.<id>') reads it,
            // so the row names are the same as every other row's. Empty until a server is
            // configured, and a server with no entry reads as manage_options (Access), which is
            // to say a server nobody has ruled on is administrators-only. An entry whose server
            // `toolkits.mcp_servers` no longer lists is dropped on the next update_option() of the
            // option (Mcp\ServerSettings::beforeSave()), which this pure function cannot do for it.
            'access.mcp' => ['type' => 'array', 'default' => [], 'section' => 'access', 'label' => __('Per-server access', 'alpaca-bot'), 'sanitize' => [self::class, 'sanitizeAccessMcp']],
        ];
    }

    /** @return array<string, mixed> key => default */
    public static function defaults(): array
    {
        return array_map(static fn (array $f): mixed => $f['default'], self::fields());
    }

    /**
     * Full, validated settings array: every schema key, in schema order, nothing else. A key the
     * input names is taken from the input; one it leaves out keeps what `$current` holds; only
     * when `$current` has nothing for it either does the default apply. Kept or new, every value
     * goes through its field's coercion, so a stored value outside the schema is corrected on the
     * way back rather than carried.
     *
     * Absent-keeps-stored is what makes a write partial at the top level (Store::replace(), the
     * REST PUT), and it is the guarantee under the settings page: PHP's max_input_vars drops the
     * tail of a large form post with no notice to userland, and a post can only lose what it
     * failed to carry, never a field it did not mention. Clearing is always explicit ('' for a
     * string, the secret included; 0 for a checkbox, which its hidden input posts).
     *
     * `$current` is also where a SECRETS field keeps its value from: a secret sent as MASK, or as
     * anything that is not a string, resolves to `$current`'s value (secret()). There is no
     * default for it on purpose. `[]` would turn an echoed mask into a cleared key, and reading
     * the option here would hide a database read inside a pure function; every caller knows what
     * it is writing over (Store has its memo, a `register_setting()` sanitize callback has
     * get_option()), so it says so. Core calls that callback with the option name as the second
     * argument, so it must be a closure that passes the stored array, not
     * `[Schema::class, 'sanitize']` itself: that fails with a TypeError rather than storing a mask.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current the stored settings this write replaces
     * @return array<string, mixed>
     */
    public static function sanitize(#[\SensitiveParameter] array $input, array $current): array
    {
        $out = [];
        foreach (self::fields() as $key => $f) {
            $raw = array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? $f['default']);
            if (in_array($key, self::SECRETS, true)) {
                $raw = self::secret($raw, $current[$key] ?? '');
            }
            if ($key === 'toolkits.mcp_servers') {
                // The one sanitizer handed something other than its field: the stored list, whose
                // ids sanitizeMcpServers() never gives a row that brings no id of its own, unless
                // the row is that stored server (its docblock says when).
                $out[$key] = self::sanitizeMcpServers($raw, $current[$key] ?? []);
                continue;
            }
            $out[$key] = isset($f['sanitize']) ? ($f['sanitize'])($raw, $f) : self::coerce($raw, $f);
        }
        return $out;
    }

    /**
     * The three-valued rule for a secret on the way in: '' clears it, MASK keeps what is stored,
     * any other string is the new value. A value that is not a string is read as "keep" too. It
     * cannot be the new key, and it is not the one spelling of "clear", so the only safe reading
     * is the one that loses nothing: a typed client's `null`, an untouched password control a
     * form serialised as `null`, a stray array, all leave the stored key as it was. The reply to
     * a write shows the mask when a key is stored, so a client that meant "clear" sees it did not.
     */
    private static function secret(mixed $raw, mixed $stored): string
    {
        if (is_string($raw) && $raw !== self::MASK) {
            return $raw;
        }
        return is_string($stored) ? $stored : '';
    }

    /** @param Field $f */
    private static function coerce(mixed $raw, array $f): mixed
    {
        switch ($f['type']) {
            case 'boolean':
                return in_array($raw, [true, 1, '1', 'true', 'on', 'yes'], true);
            case 'integer':
                $v = is_numeric($raw) ? (int) $raw : (int) $f['default'];
                return max((int) ($f['min'] ?? PHP_INT_MIN), min((int) ($f['max'] ?? PHP_INT_MAX), $v));
            case 'number':
                $v = is_numeric($raw) ? (float) $raw : (float) $f['default'];
                return max((float) ($f['min'] ?? -INF), min((float) ($f['max'] ?? INF), $v));
            case 'select':
                return is_scalar($raw) && array_key_exists((string) $raw, $f['options'] ?? []) ? (string) $raw : $f['default'];
            case 'array':
                return is_array($raw) ? $raw : $f['default'];
            case 'checkbox-list':
                // The checked subset of the options, in option order, so the stored list is
                // canonical whatever order a client sent. Unknown ids are dropped, and the ''
                // sentinel the page posts ahead of the boxes (Fields::render()) is just one more
                // unknown id: a form with every box unchecked stores []. Anything that is not
                // a list stores [] too, not the default: `array` falls back to its default, but
                // this field's default switches tools on, and a malformed write (a PUT of a
                // bare string) must fail closed rather than switch things on.
                if (!is_array($raw)) {
                    return [];
                }
                return array_values(array_filter(array_keys($f['options'] ?? []), static fn(string $id): bool => in_array($id, $raw, true)));
            case 'string':
            default:
                // One line ending. A textarea posts CRLF, a JSON client LF, and a hidden
                // carry-over of either is normalised again by the browser on its way back;
                // stored as LF, a multi-line value reads the same whichever path wrote it, and
                // saving an unrelated tab cannot rewrite it.
                return is_scalar($raw) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $raw)) : $f['default'];
        }
    }

    /**
     * esc_url_raw() drops disallowed schemes (javascript:, data:, ...) so nothing
     * unsafe reaches the chat UI (assistant_avatar) or server-side HTTP (base_url).
     *
     * @param array<string, mixed> $f
     */
    public static function sanitizeUrl(mixed $raw, array $f): string
    {
        $v = is_scalar($raw) ? trim((string) $raw) : '';
        $v = $v === '' ? '' : rtrim(esc_url_raw($v), '/');
        return $v === '' ? (string) $f['default'] : $v;
    }

    /**
     * One Settings › Access row: a select over Access::CAPABILITIES, starting at that row's
     * Access::defaults() value. `select` is what validates it — coerce() keeps only a value that is
     * a key of `options`, so a PUT or a hand-edited option naming anything else stores the default.
     *
     * @return Field
     */
    private static function access(string $row, string $label, string $description): array
    {
        return ['type' => 'select', 'default' => Access::defaults()[$row], 'section' => 'access', 'label' => $label, 'description' => $description, 'options' => self::capabilityLabels()];
    }

    /**
     * Access::CAPABILITIES with the words an operator picks from. The roles named are the stock
     * ones that hold each capability; a site with custom roles gets whoever holds it there, which
     * is why the row stores the capability and not a role.
     *
     * @return array<string, string>
     */
    private static function capabilityLabels(): array
    {
        return [
            'manage_options' => __('Administrators', 'alpaca-bot'),
            'edit_others_posts' => __('Editors and up', 'alpaca-bot'),
            'publish_posts' => __('Authors and up', 'alpaca-bot'),
            'edit_posts' => __('Contributors and up', 'alpaca-bot'),
            'read' => __('Any logged-in user', 'alpaca-bot'),
        ];
    }

    /**
     * The abilities allowlist: names of the form core checks an ability name against
     * (`/^[a-z0-9-]+\/[a-z0-9-]+$/`, WP 7.1 class-wp-abilities-registry.php:86), each once, in the
     * order sent, never one AbilitiesToolkit::excluded() names (AbilitiesToolkit says why). The
     * pattern here carries `D`, so a name with a trailing newline, which core's lets through, is
     * refused. Whether a name is *registered* is not asked: that would be a read of core's
     * registry inside a pure function, and a name whose plugin is deactivated is better kept. The
     * toolkit skips it, and the Tools tab shows it ticked and marked as not in the site's list,
     * so it can be seen and cleared. The '' sentinel the page posts ahead of the boxes is dropped like any
     * other non-name, so a form with every box clear stores []. Anything that is not an array
     * stores [] too; an array's keys are ignored.
     *
     * @return list<string>
     */
    public static function sanitizeAbilities(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name) {
            if (is_string($name)
                && preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#D', $name) === 1
                && !AbilitiesToolkit::excluded($name)
                && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * server id => one of Access::CAPABILITIES; anything else is dropped rather than defaulted.
     *
     * Dropped, not corrected: this map has no per-server default to fall back to, and an entry
     * this cannot read would otherwise be stored as a capability nobody chose. An id with no entry
     * reads as `manage_options` (Access::stored()), so dropping fails closed.
     *
     * What it can check is the value and the shape of the id (isMcpId(), the rule
     * sanitizeMcpServers() gives every server), not whether a server by that id is listed: that
     * takes the rest of the option, which this function is not handed. Mcp\ServerSettings::beforeSave()
     * drops an entry whose server is not listed, on every update_option() of the option.
     *
     * @return array<string, string>
     */
    public static function sanitizeAccessMcp(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id => $capability) {
            if (self::isMcpId($id) && is_string($capability) && in_array($capability, Access::CAPABILITIES, true)) {
                /** @var string $id */
                $out[$id] = $capability;
            }
        }
        return $out;
    }

    /**
     * `toolkits.mcp_servers` as it may be shown: each row's `header_value` is MASK when it holds a
     * non-empty string and '' otherwise, so a row that holds the value itself still shows none:
     * one written round Mcp\ServerSettings (add_option() on its own, a hand edit, a write while
     * the plugin was inactive), or one handed to a Store built with its settings in hand. Every
     * other field, and anything that is not a list of rows, is left as it is.
     */
    public static function maskedServers(mixed $rows): mixed
    {
        if (!is_array($rows)) {
            return $rows;
        }
        foreach ($rows as $i => $row) {
            if (is_array($row) && array_key_exists('header_value', $row)) {
                $rows[$i]['header_value'] = is_string($row['header_value']) && $row['header_value'] !== '' ? self::MASK : '';
            }
        }
        return $rows;
    }

    /**
     * The string ids of a `toolkits.mcp_servers` list as it is held, in list order: what
     * Mcp\ServerSettings reads a stored server by, and so what sanitize() reserves.
     *
     * @return list<string>
     */
    public static function serverIds(mixed $rows): array
    {
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }
        return $ids;
    }

    /** isMcpPrefix()'s rule in words, for every screen and reply that refuses a prefix. */
    public static function mcpPrefixRule(): string
    {
        /* translators: the rule an MCP server's tool-name prefix has to meet; ability is a literal prefix and stays in English */
        return __('1 to 16 lowercase letters and digits, starting with a letter, with single underscores allowed between them, and not "ability"', 'alpaca-bot');
    }

    /** The header name rule in words (mcpRowFault()'s `header`), for the field and every screen and reply that refuses a name. */
    public static function mcpHeaderNameRule(): string
    {
        /* translators: the rule an MCP server's header name has to meet; A-Z and a-z are the letters it may use */
        return __('1 to 64 letters (A-Z, a-z), digits and hyphens, with a letter among them', 'alpaca-bot');
    }

    /** Whether `$prefix` is one a server may name its tools under: MCP_TOOL_PREFIX, and not RESERVED_PREFIX. */
    public static function isMcpPrefix(mixed $prefix): bool
    {
        return is_string($prefix) && preg_match(self::MCP_TOOL_PREFIX, $prefix) === 1 && $prefix !== self::RESERVED_PREFIX;
    }

    /** Whether `$id` is a server id as MCP_ID defines one: the one rule for a server's row, its Access entry and its Access select. */
    public static function isMcpId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::MCP_ID, $id) === 1;
    }

    /**
     * The `toolkits.mcp_servers` list, each row read into
     * `{id, url, header_name, header_value, prefix, timeout, max_bytes, approved}` and a row that
     * cannot be read as a server dropped. What each field has to be, and why the rule is here:
     *
     * - `url` is https with a host, after esc_url_raw(). A row without one is dropped, which is
     *   what the form's blank "add a server" row is; so is a row whose `remove` box was ticked.
     *   So is a URL with a user name or a password in it, an empty one included: the URL is
     *   stored, and answered by `GET /settings`, as it is written, while the header value is kept
     *   apart and read back masked, so a credential goes there.
     *   Whether the *address* is public is not asked here: that is a DNS lookup, and this is a
     *   pure function. Mcp\ServerSettings asks it when the settings page, the REST route or
     *   `wp alpaca-bot settings` saves a URL that is new or changed. Mcp\Egress::client() asks it again whenever a client is
     *   built, and the ClientFactory the plugin constructs builds every client through it.
     * - `prefix` matches MCP_TOOL_PREFIX, is not `ability` (AbilitiesToolkit names its tools
     *   `ability__…`), and is not already taken by an earlier row; a row failing any of that is
     *   dropped, since its tools would have no name of their own.
     * - `id` names the server in its Access entry (`access.mcp[<id>]`, the row `mcp.<id>`, the
     *   filter `alpaca_bot/capability/mcp/<id>`) and its header value (Mcp\Secrets), so it has to
     *   survive an edit of the prefix or the URL, and it is never taken from another row. Every
     *   row that brings an id matching MCP_ID keeps it, first come first served, before any row is
     *   given one. A row whose id is missing, malformed or already kept is then the stored server
     *   (in `$stored`, which sanitize() passes) whose URL and prefix it has, when the post names
     *   that server's id in no row at all, a row whose `remove` is ticked included: a client
     *   that writes its servers without ids sends the same body every time, and its servers keep
     *   their ids. Any other such row gets its prefix, then `<prefix>_2`, `_3`… until one is
     *   neither kept nor a stored server's id. Mcp\ServerSettings reads a row whose id the stored
     *   list holds as that stored server, so a row that brings no id and is not one must never be
     *   given one of those ids, even one this same write removes. Keeping ids before making any
     *   is what stops a new row listed first from taking an existing server's id. Either way a
     *   new row cannot take another server's Access entry or secret.
     * - `header_name` is `[A-Za-z0-9-]{1,64}` with a letter in it, or '' (a missing or null name
     *   reads as ''). A row with any other name is dropped, and droppedMcpRows() names it
     *   `header`, so a save refuses it rather than keep the server with no header. A name with no
     *   letter (an int, or a string of digits and hyphens: `123`, `-1`, `0`) is the case the rule
     *   exists for: PHP makes a whole number written plainly an int array key, and php-agents'
     *   header map would then send the value in the name's place (Mcp\PhpAgentsClient::over()
     *   refuses such a name too, for a row written round this). A name outside the alphabet
     *   (`X_Key`, `Bad Header`, `+1`), one over 64 characters, and one that is not a string are
     *   refused the same way (R28-11): read as '', the server was saved with no header and its
     *   401 blamed a credential that was never sent.
     * - `header_value` follows the secret rule (the class docblock): '' clears, MASK keeps, any
     *   other string is the new value with CR, LF and NUL removed, so no value can end the header
     *   early or cut it; anything that is not a string reads as MASK.
     * - `timeout` is 1-120 seconds and `max_bytes` 1 KiB-8 MiB, clamped; a value that is not a
     *   number is the default, 30 seconds and 1 MiB, as ServerConfig::fromSettings() has them.
     * - `approved` keeps `tool name => fingerprint` where the name is `[A-Za-z0-9_.-]{1,128}` and
     *   the fingerprint 64 lowercase hex characters (ToolDefinition::fingerprint()). A name PHP
     *   made into an int key is dropped, as fromSettings() drops it. A map that is absent means no
     *   approvals, which is what a form with every box clear posts.
     *
     * @param mixed $stored the stored `toolkits.mcp_servers`: ids a row that brings no id of its own is never given, unless it is that server
     * @return list<array{id: string, url: string, header_name: string, header_value: string, prefix: string, timeout: float, max_bytes: int, approved: array<string, string>}>
     */
    public static function sanitizeMcpServers(#[\SensitiveParameter] mixed $raw, mixed $stored = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        $asked = [];
        $prefixes = [];
        foreach ($raw as $row) {
            if (!is_array($row) || !empty($row['remove']) || self::mcpRowFault($row, $prefixes) !== null) {
                continue;
            }
            $url = self::mcpUrl($row['url'] ?? null);
            /** @var string $prefix mcpRowFault() passed it */
            $prefix = $row['prefix'];
            $prefixes[] = $prefix;
            $asked[] = $row['id'] ?? null;
            $timeout = is_numeric($row['timeout'] ?? null) ? (float) $row['timeout'] : 30.0;
            $maxBytes = is_numeric($row['max_bytes'] ?? null) ? (int) $row['max_bytes'] : 1048576;
            /** @var string $name mcpRowFault() passed it: '' or a name the rule admits */
            $name = $row['header_name'] ?? '';
            $value = $row['header_value'] ?? null;
            $rows[] = [
                'id' => '',
                'url' => $url,
                'header_name' => $name,
                'header_value' => is_string($value) && $value !== self::MASK ? str_replace(["\r", "\n", "\0"], '', $value) : self::MASK,
                'prefix' => $prefix,
                'timeout' => max(1.0, min(120.0, $timeout)),
                'max_bytes' => max(1024, min(8388608, $maxBytes)),
                'approved' => self::approvals($row['approved'] ?? null),
            ];
        }
        $ids = [];
        foreach ($asked as $i => $id) {
            if (self::isMcpId($id) && !in_array($id, $ids, true)) {
                /** @var string $id */
                $ids[$i] = $id;
            }
        }
        $reserved = self::serverIds($stored);
        $named = self::serverIds($raw);
        $same = [];
        foreach (is_array($stored) ? $stored : [] as $row) {
            if (is_array($row) && self::isMcpId($row['id'] ?? null) && !in_array($row['id'], $named, true)) {
                $same[self::mcpUrl($row['url'] ?? null) . ' ' . (is_string($row['prefix'] ?? null) ? $row['prefix'] : '')] = $row['id'];
            }
        }
        foreach ($rows as $i => $row) {
            $adopt = $same[$row['url'] . ' ' . $row['prefix']] ?? null;
            if (!isset($ids[$i]) && is_string($adopt) && !in_array($adopt, $ids, true)) {
                $ids[$i] = $adopt;
            }
        }
        foreach ($rows as $i => $row) {
            if (!isset($ids[$i])) {
                $id = $row['prefix'];
                $n = 2;
                while (in_array($id, $ids, true) || in_array($id, $reserved, true)) {
                    $id = $row['prefix'] . '_' . $n;
                    ++$n;
                }
                $ids[$i] = $id;
            }
            $rows[$i]['id'] = $ids[$i];
        }
        return $rows;
    }

    /**
     * Each row of `$raw` that sanitizeMcpServers() leaves out, by its key in `$raw`, with why:
     * `url` (not https with a host), `userinfo` (https with a host, and a user name or password
     * too), `header` (a header name other than '' that mcpHeaderNameRule() does not admit), `prefix` (not MCP_TOOL_PREFIX, or
     * `ability`) or `taken` (an earlier row that is kept has it). A row that is not an array, or
     * whose `remove` is ticked,
     * is not listed, since leaving it out is what it asks for. Both functions ask mcpRowFault(),
     * so the two cannot disagree about a row.
     *
     * @return array<array-key, 'url'|'userinfo'|'header'|'prefix'|'taken'>
     */
    public static function droppedMcpRows(#[\SensitiveParameter] mixed $raw): array
    {
        $dropped = [];
        $prefixes = [];
        foreach (is_array($raw) ? $raw : [] as $key => $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            $fault = self::mcpRowFault($row, $prefixes);
            if ($fault === null) {
                /** @var string $prefix */
                $prefix = $row['prefix'];
                $prefixes[] = $prefix;
            } else {
                $dropped[$key] = $fault;
            }
        }
        return $dropped;
    }

    /**
     * Why sanitizeMcpServers() cannot keep `$row`, given the prefixes of the rows it has kept
     * before it, or null when it can.
     *
     * @param array<array-key, mixed> $row
     * @param list<string>            $prefixes
     * @return 'url'|'userinfo'|'header'|'prefix'|'taken'|null
     */
    private static function mcpRowFault(#[\SensitiveParameter] array $row, array $prefixes): ?string
    {
        $prefix = $row['prefix'] ?? null;
        $url = self::mcpUrlFault(self::escapedUrl($row['url'] ?? null));
        $name = $row['header_name'] ?? '';
        return match (true) {
            $url !== null => $url,
            $name !== '' && !(is_string($name) && preg_match('/^(?=[0-9-]*[A-Za-z])[A-Za-z0-9-]{1,64}\z/', $name) === 1) => 'header',
            !self::isMcpPrefix($prefix) => 'prefix',
            in_array($prefix, $prefixes, true) => 'taken',
            default => null,
        };
    }

    /** An MCP server's URL as sanitizeMcpServers() keeps it, or '' when mcpUrlFault() finds one. */
    private static function mcpUrl(mixed $raw): string
    {
        $url = self::escapedUrl($raw);
        return self::mcpUrlFault($url) === null ? $url : '';
    }

    private static function escapedUrl(mixed $raw): string
    {
        return is_string($raw) ? esc_url_raw(trim($raw)) : '';
    }

    /**
     * What is wrong with `$url`, an escaped URL, as an MCP server's: `url` when it is not https
     * with a host, `userinfo` when it is but carries a user name or a password (an empty one, as
     * in `https://@host/`, included), null when nothing is.
     *
     * @return 'url'|'userinfo'|null
     */
    private static function mcpUrlFault(string $url): ?string
    {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        // The host trimmed of dots, as Mcp\Egress reads it: `https://./mcp` has none.
        $host = trim((string) wp_parse_url($url, PHP_URL_HOST), '.');
        if ($scheme !== 'https' || $host === '') {
            return 'url';
        }
        // A password comes with a user name, if an empty one (`https://:pass@host/`), so asking for
        // the user name finds both.
        return wp_parse_url($url, PHP_URL_USER) === null ? null : 'userinfo';
    }

    /**
     * `$url` with any user name and password taken out, for a refusal that names the URL it
     * refused: `https://user:pass@host/mcp` is `https://host/mcp`. What is taken is everything
     * between the first `//` and the last `@` in the string, wherever that `@` is. That is more
     * than wp_parse_url() calls userinfo: a password with an unencoded `/`, `?` or `#` in it is
     * no userinfo to it, and the URL is refused as malformed rather than for its credential, and
     * the refusal must not answer that password back either. An `@` in a path or a query is
     * taken with what comes before it, which costs only the host in a refusal's text. A URL
     * without `//` is returned as it is.
     */
    public static function withoutUserinfo(string $url): string
    {
        return (string) preg_replace('#^([^/?\#]*//).*@#s', '$1', $url);
    }

    /**
     * Whether `$name` is a key an `approved` map keeps: a string of `[A-Za-z0-9_.-]{1,128}`. An
     * int is not, and PHP makes an int of an array key that is a decimal integer in canonical
     * form (`'123'`, `'-1'`, not `'0123'`), so a caller asking about a tool's name asks about
     * the key the name becomes: `isToolName(array_key_first([$name => true]))`.
     */
    public static function isToolName(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,128}\z/', $name) === 1;
    }

    /**
     * A server row's `approved` map, as sanitizeMcpServers() keeps it.
     *
     * @return array<string, string>
     */
    private static function approvals(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $name => $fingerprint) {
            if (self::isToolName($name) && is_string($fingerprint) && preg_match('/^[0-9a-f]{64}\z/', $fingerprint) === 1) {
                $out[$name] = $fingerprint;
            }
        }
        return $out;
    }

    /**
     * model => {temperature?, num_ctx?, keep_alive?, system?, tools?}; anything else is dropped.
     *
     * Each override is coerced with the schema field it overrides, so the type and
     * min/max bounds have one source of truth: fields().
     *
     * `tools` is the exception, and has to be: what it overrides is the catalogue's capability
     * flag, which is not a setting and so has no field to borrow from. Bending an unrelated
     * field's coercion onto it would give it that field's default on a value it could not read,
     * and a default here is a decision about somebody's turn. So it is checked against its own
     * two literals and dropped otherwise, which lands it on the same rule as every other cell:
     * unreadable is no override, not a coerced one.
     *
     * @return array<string, array<string, float|int|string>>
     */
    public static function sanitizeOverrides(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $fields = self::fields();
        $allowed = ['temperature' => 'models.temperature', 'num_ctx' => 'models.num_ctx', 'keep_alive' => 'models.keep_alive', 'system' => 'chat.system_prompt'];
        $out = [];
        foreach ($raw as $model => $opts) {
            if (!is_string($model) || $model === '' || !is_array($opts)) {
                continue;
            }
            $clean = [];
            foreach ($allowed as $k => $field) {
                if (!array_key_exists($k, $opts) || !is_scalar($opts[$k])) {
                    continue;
                }
                // An unparseable number is dropped rather than coerced to the schema
                // default, which would silently shadow the admin's global value.
                if (in_array($fields[$field]['type'], ['number', 'integer'], true) && !is_numeric($opts[$k])) {
                    continue;
                }
                /** @var float|int|string $v */
                $v = self::coerce($opts[$k], $fields[$field]);
                // A blank cell in the settings table is "no override", not an empty value that
                // would shadow the global keep_alive or system prompt with nothing.
                if ($v === '') {
                    continue;
                }
                $clean[$k] = $v;
            }
            // Last, so a stored row reads in the order the settings table shows it.
            if (is_scalar($opts['tools'] ?? null) && in_array((string) $opts['tools'], [self::TOOLS_ON, self::TOOLS_OFF], true)) {
                $clean['tools'] = (string) $opts['tools'];
            }
            if ($clean !== []) {
                $out[$model] = $clean;
            }
        }
        return $out;
    }
}
