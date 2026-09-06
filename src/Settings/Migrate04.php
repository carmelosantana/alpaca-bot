<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

/**
 * One-time move from 0.4: the scattered `alpaca_bot_*` options into the single settings option,
 * and the `chat_history` rows into the shape ConversationStore expects.
 *
 * Each step has its own flag. The options move is one request and flags itself at once; the
 * conversation pass handles BATCH rows per admin request and flags itself when a batch comes
 * back short, so a large history is spread over several requests instead of timing out one
 * and restarting from the top. Legacy options are left in place (P3 removes them).
 */
final class Migrate04
{
    public const FLAG = 'alpaca_bot_migrated_04';
    public const FLAG_CONVERSATIONS = 'alpaca_bot_migrated_04_conversations';

    private const LEGACY_PREFIX = 'alpaca_bot_';

    /** Rows per admin request: one wp_update_post() and its hook chain each. */
    private const BATCH = 100;

    /**
     * Legacy option (without prefix) => new dotted key.
     *
     * Not carried over: `api_password`. 0.4 sent it as HTTP Basic alongside an
     * `api_username` that 1.0 has no field for, while `provider.api_key` goes out
     * as a Bearer token. Migrating it would hand every upgrading admin a credential
     * guaranteed to fail auth, silently; an empty field they must re-fill is
     * strictly better than a populated one that cannot work.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'api_url' => 'provider.base_url',
        'ollama_timeout' => 'provider.timeout',
        'default_model' => 'models.default',
        'default_temperature' => 'models.temperature',
        'default_num_ctx' => 'models.num_ctx',
        'default_system' => 'chat.system_prompt',
        'default_assistant_welcome_message' => 'chat.welcome',
        'default_assistant_prompt_placeholder' => 'chat.placeholder',
        'user_can_change_model' => 'chat.user_can_change_model',
        // Semantic change: in 0.4 this was the number of *messages sent to the model*
        // (0 = all); in 1.0 it is the number of *conversations shown in history*.
        // The number is carried anyway: it yields a merely arbitrary list length,
        // visible in the UI and one setting away from fixed, whereas dropping it
        // would discard user intent for no safety gain.
        'chat_history_limit' => 'chat.history_limit',
        'spellcheck' => 'chat.spellcheck',
        'default_avatar' => 'chat.assistant_avatar',
        'chat_history_save' => 'privacy.save_history',
        'chat_response_log' => 'privacy.usage_log',
        'user_agent' => 'toolkits.user_agent',
    ];

    public function __construct(private Store $store) {}

    /** True while either step still has work: run() does what is pending and no more. */
    public function needed(): bool
    {
        return $this->optionsPending() || $this->conversationsPending();
    }

    /**
     * Runs whichever steps are still pending; the conversation step does one batch.
     *
     * @return array<string, mixed> the settings after the run
     */
    public function run(): array
    {
        if ($this->optionsPending()) {
            $this->migrateOptions();
            update_option(self::FLAG, '1', false);
        }
        if ($this->conversationsPending()) {
            $this->migrateConversations();
        }
        return $this->store->all();
    }

    /**
     * True when 0.4 options exist and the move has not run yet.
     *
     * On a site that never had 0.4 the flag is written here, so the detection
     * (three non-autoloaded option reads) happens once instead of on every admin request.
     */
    private function optionsPending(): bool
    {
        if (get_option(self::FLAG, false) !== false) {
            return false;
        }
        $legacy = get_option(self::LEGACY_PREFIX . 'api_url', null) !== null
            || get_option(self::LEGACY_PREFIX . 'default_model', null) !== null;
        if (!$legacy) {
            update_option(self::FLAG, '1', false);
        }
        return $legacy;
    }

    /**
     * Keyed on its own flag, not on the legacy options: a 0.4 site running Ollama on its
     * defaults never had to save the settings tab, yet it has conversations.
     */
    private function conversationsPending(): bool
    {
        return get_option(self::FLAG_CONVERSATIONS, false) === false;
    }

    private function migrateOptions(): void
    {
        $next = $this->store->all();
        $fields = Schema::fields();
        foreach (self::MAP as $legacy => $key) {
            $value = get_option(self::LEGACY_PREFIX . $legacy, null);
            if ($value === null || $value === false) {
                continue;
            }
            // 0.4's Options::get() went through empty() and `$value ? $value : $default`,
            // so a stored '', '0' or 0 all meant "unset, use the default". For its
            // boolean radios the stored '' *was* the "No" value (they only ever wrote
            // '1' or ''), so only non-boolean keys fall back to the 1.0 default here.
            if ($fields[$key]['type'] !== 'boolean' && in_array($value, ['', '0', 0], true)) {
                continue;
            }
            if ($key === 'provider.base_url') {
                $value = rtrim(is_scalar($value) ? (string) $value : '', '/');
                $value = str_ends_with($value, '/v1') ? $value : $value . '/v1';
            }
            $next[$key] = $value;
        }
        $this->store->replace($next);
    }

    /**
     * One batch of 0.4 conversation rows.
     *
     * 0.4 stored every conversation as a `publish` post and left post_author to
     * wp_insert_post()'s default — the current user, so 0 for a request that had none.
     * ConversationStore checks the owner strictly, so every legacy row becomes private, and one
     * with no owner takes it from the first message: 0.4 wrote the user's id as that message's
     * role. A row that already has an owner keeps it.
     *
     * Only `publish` rows are queried, so a row this batch flips leaves the next batch's result
     * set: the rows are their own cursor, and the pass is complete when a batch comes back short.
     */
    private function migrateConversations(): void
    {
        $posts = get_posts([
            'post_type' => 'chat_history',
            'post_status' => 'publish',
            'numberposts' => self::BATCH,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($posts as $post) {
            $pid = (int) $post->ID;
            $update = ['ID' => $pid, 'post_status' => 'private'];
            if ((int) $post->post_author < 1) {
                $legacy = get_post_meta($pid, 'messages', true);
                $role = is_array($legacy) ? ($legacy[0]['message']['role'] ?? null) : null;
                if (is_int($role) && $role > 0) {
                    $update['post_author'] = $role;
                }
            }
            wp_update_post($update);
        }
        if (count($posts) < self::BATCH) {
            update_option(self::FLAG_CONVERSATIONS, '1', false);
        }
    }
}
