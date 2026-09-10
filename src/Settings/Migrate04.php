<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Plugin;

/**
 * One-time moves, each under its own flag: from 0.4, the scattered `alpaca_bot_*` options into
 * the single settings option and the `chat_history` rows into the shape ConversationStore
 * expects; and for a setting that arrived after sites were already running 0.5, the value an
 * upgraded site gets when it differs from a fresh install's default (migrateRetention()).
 *
 * The options move and the retention step are one request each and flag themselves at once;
 * the conversation pass handles BATCH rows per request and flags itself when a batch comes
 * back short, so a large history is spread over several requests instead of timing out one
 * and restarting from the top. Legacy options are left in place (P3 removes them).
 *
 * "Per request" is literal, and not "per admin request": Plugin::register() hooks the run on
 * `init` priority 20 (Plugin.php, which says why — WP-CLI fires init and never admin_init), so
 * every request the site serves runs it while any flag is unset, an anonymous front-end page
 * view included. BATCH is therefore a bound on what a visitor's page view can be made to pay
 * for, not only on what an administrator waits through.
 *
 * The same hook is why all three flags are written autoloaded: needed() re-reads them on
 * every request forever, and a non-autoloaded option costs one uncached SELECT per request
 * each. That was measured rather than assumed: the three reads were three of the 31 queries the
 * admin chat screen ran, and being on `init` they ran on every other request too
 * (docs/reviews/2026-09-09-performance-baseline.md).
 * Autoloaded they cost nothing beyond three short rows in the alloptions read WordPress
 * already does.
 *
 * Writing them autoloaded only fixes rows written from here on, because none of the three
 * *Pending() checks looks at a flag again once it is set, so nothing ever rewrites one. A site
 * that completed the migration under a pre-release 0.5 would keep three non-autoloaded rows
 * forever, so a fourth flag (FLAG_AUTOLOAD) carries a one-time wp_set_options_autoload() over
 * the other three; it is itself autoloaded, and once it is set needed() is four alloptions
 * reads and no query at all. 0.4.17 never wrote any of these option names (`git grep
 * migrated_04 v0.4.17` is empty), so a genuine 0.4 site creates all four rows fresh under the
 * new value and the repair is a no-op there.
 */
final class Migrate04
{
    public const FLAG = 'alpaca_bot_migrated_04';
    public const FLAG_CONVERSATIONS = 'alpaca_bot_migrated_04_conversations';
    public const FLAG_RETENTION = 'alpaca_bot_migrated_retention';
    public const FLAG_AUTOLOAD = 'alpaca_bot_migrated_flag_autoload';

    private const LEGACY_PREFIX = 'alpaca_bot_';

    /** Rows per request: one wp_update_post() and its hook chain each. */
    private const BATCH = 100;

    /**
     * Legacy option (without prefix) => new dotted key.
     *
     * Not carried over: `api_password`. 0.4 sent it as HTTP Basic alongside an
     * `api_username` that 0.5 has no field for, while `provider.api_key` goes out
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
        // 0.4's "Limit chat history" was the number of messages sent to the model, which
        // is chat.context_messages, not chat.history_limit (conversations listed in the
        // history UI; that one starts at its 0.5 default). A stored 0 or '' is skipped
        // below like any other non-boolean, which matches what 0.4 actually did with it:
        // its Options::get() read 0 as unset and fell back to the placeholder.
        'chat_history_limit' => 'chat.context_messages',
        'spellcheck' => 'chat.spellcheck',
        'default_avatar' => 'chat.assistant_avatar',
        'chat_history_save' => 'privacy.save_history',
        'chat_response_log' => 'privacy.usage_log',
        'user_agent' => 'toolkits.user_agent',
    ];

    public function __construct(private Store $store) {}

    /** True while any step still has work: run() does what is pending and no more. */
    public function needed(): bool
    {
        return $this->optionsPending() || $this->retentionPending() || $this->conversationsPending() || $this->autoloadPending();
    }

    /**
     * Runs whichever steps are still pending; the conversation step does one batch. Retention
     * goes before the options move: on a 0.4 site the move creates the settings row and would
     * fill the retention default in, and the retention step has to see that there was no row.
     *
     * @return array<string, mixed> the settings after the run
     */
    public function run(): array
    {
        if ($this->retentionPending()) {
            $this->migrateRetention();
            update_option(self::FLAG_RETENTION, '1', true);
        }
        if ($this->optionsPending()) {
            $this->migrateOptions();
            update_option(self::FLAG, '1', true);
        }
        if ($this->conversationsPending()) {
            $this->migrateConversations();
        }
        if ($this->autoloadPending()) {
            $this->repairFlagAutoload();
        }
        return $this->store->all();
    }

    private function retentionPending(): bool
    {
        return get_option(self::FLAG_RETENTION, false) === false;
    }

    private function autoloadPending(): bool
    {
        return get_option(self::FLAG_AUTOLOAD, false) === false;
    }

    /**
     * Puts the three completion flags on autoload once, for a site that completed the migration
     * under a pre-release 0.5 and so holds them non-autoloaded (class docblock).
     *
     * Last in run() so it sees whichever flags this request wrote, and keyed on a flag of its own
     * so it happens exactly once: `wp_set_options_autoload()` is a SELECT plus at most one UPDATE
     * and one cache flush, which is fine once and is not fine per request. It ignores names with
     * no row, so a first request that has not finished the conversation pass leaves
     * FLAG_CONVERSATIONS to run()'s own autoloaded write later. Its own flag is written after the
     * call, not before, so a request that dies mid-repair runs it again rather than skipping it.
     */
    private function repairFlagAutoload(): void
    {
        wp_set_options_autoload([self::FLAG, self::FLAG_RETENTION, self::FLAG_CONVERSATIONS], true);
        update_option(self::FLAG_AUTOLOAD, '1', true);
    }

    /**
     * `privacy.usage_retention_days` arrived after sites were recording receipts, and its
     * default (90) would have the daily cleanup delete an upgraded site's whole usage history a
     * day after the upgrade, unasked. A site with anything the field could act on when it first
     * appears gets 0 written explicitly, so nothing is deleted until an admin chooses a window:
     * a settings row written before the field existed (a site upgrading within 0.5), or a
     * receipt row and no settings row (a 0.4 site; its options are moved right after this). A
     * row that already carries the field was saved by an admin who saw it, and stands. A fresh
     * install has neither row and keeps the default. The receipts query runs only on a site
     * without a settings row, once, under the flag.
     *
     * The option is read with an explicit default, and core honours a caller's own default over
     * a registered one (filter_default_option() returns $default_value when $passed_default).
     * On this hook nothing has registered one yet — the only register_setting() call is
     * SettingsPage::register() on `admin_init`, which is later than the `init` priority 20 this
     * migration runs on (Plugin::register()) — so the explicit default is defensive, not
     * load-bearing today. It is here for a caller that runs the migration somewhere later, where
     * `Schema::defaults()` would otherwise stand in for a missing row and make a fresh install
     * look like a row that already carries the field.
     */
    private function migrateRetention(): void
    {
        $row = get_option(Plugin::OPTION, null);
        if (is_array($row) && $row !== []) {
            if (!array_key_exists('privacy.usage_retention_days', $row)) {
                $this->store->set('privacy.usage_retention_days', 0);
            }
            return;
        }
        $receipts = get_posts([
            'post_type' => UsageMeter::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        if ($receipts !== []) {
            $this->store->set('privacy.usage_retention_days', 0);
        }
    }

    /**
     * True when 0.4 options exist and the move has not run yet.
     *
     * On a site that never had 0.4 the flag is written here, so the two legacy reads that detect
     * a 0.4 site happen once instead of on every request the site serves — which, hooked on
     * `init`, is what this would otherwise be. On such a site neither row exists at all, and a
     * missing option is one uncached SELECT per request without a persistent object cache:
     * core records the miss in its `notoptions` cache (wp-includes/option.php), which does not
     * outlive the request. On a real 0.4 site both rows do exist and are autoloaded —
     * `v0.4.17:src/Define.php` registers `api_url` and `default_model` `'autoload' => 'yes'`,
     * and `v0.4.17:src/Utils/Options.php:43` passes that to add_option() — so there they cost no
     * query of their own, this branch is not taken, and run() writes the flag instead. The flag
     * read above costs no query either: every write of it, here and in run(), is autoloaded
     * (class docblock).
     */
    private function optionsPending(): bool
    {
        if (get_option(self::FLAG, false) !== false) {
            return false;
        }
        $legacy = get_option(self::LEGACY_PREFIX . 'api_url', null) !== null
            || get_option(self::LEGACY_PREFIX . 'default_model', null) !== null;
        if (!$legacy) {
            update_option(self::FLAG, '1', true);
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
            // '1' or ''), so only non-boolean keys fall back to the 0.5 default here.
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
            // wp_update_post() unslashes the fields it is given. These are an id, a status and
            // sometimes an author id, none of which can carry a backslash, so this changes
            // nothing today; it is here so the rule holds at every write, whatever is added.
            wp_update_post(wp_slash($update));
        }
        if (count($posts) < self::BATCH) {
            update_option(self::FLAG_CONVERSATIONS, '1', true);
        }
    }
}
