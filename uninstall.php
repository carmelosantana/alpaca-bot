<?php
/**
 * Alpaca Bot's uninstall routine: WordPress includes this file when the plugin is deleted from
 * the Plugins screen, or on `wp plugin uninstall`. The plugin is inactive then and was not loaded
 * for the request, so nothing here uses its autoloader or a class of its own: every name is
 * written out below, and the list is the inventory. tests/Unit/UninstallInventoryTest.php fails
 * when a quoted `alpaca_bot_*` or `ab_*` string in src/ is neither named here nor listed there as
 * not stored, or when src/ gains a post type, a cron event, a role change or a network-wide
 * option; tests/Integration/UninstallTest.php runs this file over one of each row and a
 * neighbour of each that is not ours.
 *
 * What it removes, on each site:
 * - options: the settings row, the MCP header values (`alpaca_bot_mcp_secrets`), the four
 *   Settings\Migrate04 flags, the stream slot rows Rest\StreamBudget writes with $wpdb, and the
 *   options 0.4.17 wrote (one per settings field, its version stamp, and its shortcode cache);
 * - transients: the model catalog, and every dynamic one (rate limit windows, usage month
 *   totals, shortcode answers, stream tickets, MCP drift markers, 0.4.17's model list and
 *   shortcode cache);
 * - posts: every `chat_history` (conversations) and `chat_log` (usage receipts) post in any
 *   status, and all of their post meta, which is where the transcript, the receipt's numbers and
 *   Migrate04's attempts count live;
 * - post meta 0.4.17's shortcode cache left on the page that showed it (the page stays);
 * - the `alpaca_bot/usage/cleanup` cron event.
 * And once, because the users table is shared by a network: the three Chat\UserPrefs user meta
 * keys and 0.4.17's `alpaca_bot_user_settings`.
 *
 * What it leaves: drafts the create-draft tool wrote. They are ordinary posts of the site's own
 * types, owned by the user who asked for them, and nothing marks them as the plugin's.
 *
 * Fixed names are deleted by name. Dynamic names are found with a LIKE on the escaped prefix,
 * anchored at the start, and each row it returns is deleted only if the whole name matches the
 * exact shape the plugin writes; so a neighbour that shares a prefix is left alone. Deletes go
 * through delete_option() and delete_metadata(), so WordPress's caches drop the rows with the
 * table. Posts are the exception: they are deleted with two SQL statements per batch of 500
 * rather than wp_delete_post(), which runs several queries and a hook chain for each row, and a
 * site can hold years of receipts. So no `delete_post` hooks fire for them, and each one's post
 * and meta cache entries are dropped by hand. Neither type supports comments, revisions or a
 * taxonomy, so there are no other rows to find.
 *
 * A persistent object cache: a transient then lives in the cache, not in the options table, and
 * a cache cannot be listed by prefix. The two with a fixed name are deleted through
 * delete_transient(), which reaches the cache. The dynamic ones cannot be found there and are
 * left to expire: every one is written with an expiry, the longest an MCP drift marker's week, a
 * shortcode answer's the `cache` seconds its shortcode asked for.
 *
 * Multisite: every site's rows are removed, not only the current site's, because the plugin's
 * files are gone for all of them whether it was network-activated or activated per site. Each
 * site costs a switch_to_blog() and a few queries, so a very large network pays for that in one
 * request; `wp plugin uninstall` runs it under PHP's command line, which has no time limit by
 * default.
 *
 * `chat_history` and `chat_log` are not prefixed (0.4 named them, and existing sites hold rows
 * under them). Every post of those types is removed, so a site where another plugin also used
 * one of those names would lose that plugin's posts of it too.
 *
 * @package AlpacaBot
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

(static function (): void {
    global $wpdb;

    $options = [
        'alpaca_bot_settings',
        'alpaca_bot_mcp_secrets',
        'alpaca_bot_migrated_04',
        'alpaca_bot_migrated_04_conversations',
        'alpaca_bot_migrated_retention',
        'alpaca_bot_migrated_flag_autoload',
        // 0.4.17: an option per settings field (v0.4.17:src/Define.php), and its version stamp.
        'alpaca_bot_api_url',
        'alpaca_bot_api_username',
        'alpaca_bot_api_password',
        'alpaca_bot_ollama_timeout',
        'alpaca_bot_default_model',
        'alpaca_bot_user_can_change_model',
        'alpaca_bot_chat_history_limit',
        'alpaca_bot_chat_response_log',
        'alpaca_bot_title_generation_method',
        'alpaca_bot_user_agent',
        'alpaca_bot_chat_history_save',
        'alpaca_bot_spellcheck',
        'alpaca_bot_default_avatar',
        'alpaca_bot_default_system',
        'alpaca_bot_default_template',
        'alpaca_bot_default_assistant_welcome_message',
        'alpaca_bot_default_assistant_prompt_placeholder',
        'alpaca_bot_default_mirostat',
        'alpaca_bot_default_mirostat_eta',
        'alpaca_bot_default_mirostat_tau',
        'alpaca_bot_default_num_ctx',
        'alpaca_bot_default_num_gqa',
        'alpaca_bot_default_num_gpu',
        'alpaca_bot_default_num_thread',
        'alpaca_bot_default_repeat_last_n',
        'alpaca_bot_default_repeat_penalty',
        'alpaca_bot_default_temperature',
        'alpaca_bot_default_seed',
        'alpaca_bot_default_stop',
        'alpaca_bot_default_tfs_z',
        'alpaca_bot_default_num_predict',
        'alpaca_bot_default_top_k',
        'alpaca_bot_default_top_p',
        'alpaca_bot_version',
    ];
    // Provider\ModelCatalog's, and 0.4.17's model list.
    $transients = ['alpaca_bot_models', 'alpaca_bot_ollama_models'];
    // Prefix => the whole name, for option names the plugin builds at run time.
    $option_patterns = [
        // Rest\StreamBudget: <person>_<slot>, the person a user id or RateLimit::subject()'s ip_<hash>.
        'alpaca_bot_stream_slot_' => '/^alpaca_bot_stream_slot_(?:\d+|ip_[A-Za-z0-9]+)_\d+\z/',
        // 0.4.17's shortcode cache kept as an option.
        'alpaca_bot_cache_' => '/^alpaca_bot_cache_[0-9a-f]{32}\z/',
    ];
    $transient_patterns = [
        // RateLimit: <bucket>_<person>_<YmdHi>.
        'alpaca_bot_rl_' => '/^alpaca_bot_rl_[a-z]+_(?:\d+|ip_[A-Za-z0-9]+)_\d{12}\z/',
        // Chat\UsageMeter: <user id or site>_<Y-m>.
        'alpaca_bot_usage_' => '/^alpaca_bot_usage_(?:\d+|site)_\d{4}-\d{2}\z/',
        // Shortcodes\Chat: an md5.
        'alpaca_bot_shortcode_' => '/^alpaca_bot_shortcode_[0-9a-f]{32}\z/',
        // Rest\ChatController's stream ticket: wp_generate_password(32, false).
        'alpaca_bot_stream_' => '/^alpaca_bot_stream_[A-Za-z0-9]{32}\z/',
        // Mcp\Drift: an MCP server id, as Settings\Schema's MCP_ID allows it.
        'alpaca_bot_mcp_drift_' => '/^alpaca_bot_mcp_drift_[a-z][a-z0-9_]{0,23}\z/',
        // 0.4.17's shortcode cache kept as a transient.
        'alpaca_bot_cache_' => '/^alpaca_bot_cache_[0-9a-f]{32}\z/',
    ];
    // 0.4.17's shortcode cache kept as post meta on the page that showed it.
    $post_meta_patterns = ['alpaca_bot_cache_' => '/^alpaca_bot_cache_[0-9a-f]{32}\z/'];
    $post_types = ['chat_history', 'chat_log'];
    $cron_hooks = ['alpaca_bot/usage/cleanup'];
    $user_meta = ['alpaca_bot_default_model', 'alpaca_bot_drawer_open', 'alpaca_bot_drawer_conversation', 'alpaca_bot_user_settings'];

    /**
     * The distinct values of `$column` that start with `$prefix`: the LIKE is on the escaped
     * prefix and anchored at the start, and the caller then checks each value whole.
     *
     * @return list<string>
     */
    $starting = static function (string $table, string $column, string $prefix) use ($wpdb): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No API lists options or meta keys by prefix. The table and column are $wpdb's table name and this file's own literal; the prefix is escaped with esc_like() and prepared.
        $found = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT `{$column}` FROM `{$table}` WHERE `{$column}` LIKE %s", $wpdb->esc_like($prefix) . '%'));
        return array_values(array_filter($found, 'is_string'));
    };

    $site = static function () use ($wpdb, $starting, $options, $transients, $option_patterns, $transient_patterns, $post_meta_patterns, $post_types, $cron_hooks): void {
        foreach ($options as $name) {
            delete_option($name);
        }
        foreach ($option_patterns as $prefix => $pattern) {
            foreach ($starting($wpdb->options, 'option_name', $prefix) as $name) {
                if (preg_match($pattern, $name) === 1) {
                    delete_option($name);
                }
            }
        }
        foreach ($transients as $name) {
            delete_transient($name);
        }
        // Value and timeout rows are matched on their own, so a timeout whose value is already
        // gone goes too: delete_transient() removes the timeout only when removing the value
        // succeeded.
        foreach ($transient_patterns as $prefix => $pattern) {
            foreach (['_transient_', '_transient_timeout_'] as $kind) {
                foreach ($starting($wpdb->options, 'option_name', $kind . $prefix) as $name) {
                    if (preg_match($pattern, substr($name, strlen($kind))) === 1) {
                        delete_option($name);
                    }
                }
            }
        }
        foreach ($post_meta_patterns as $prefix => $pattern) {
            foreach ($starting($wpdb->postmeta, 'meta_key', $prefix) as $key) {
                if (preg_match($pattern, $key) === 1) {
                    delete_metadata('post', 0, $key, '', true);
                }
            }
        }
        foreach ($post_types as $type) {
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The file docblock says why posts are deleted in SQL. The type is prepared; the interpolation is $wpdb's table name.
                $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM `{$wpdb->posts}` WHERE post_type = %s ORDER BY ID LIMIT 500", $type)));
                if ($ids === []) {
                    break;
                }
                $in = implode(',', $ids);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of integers cast above; the rest is $wpdb's table name. The caches are dropped below.
                $wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN ({$in})");
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
                $deleted = $wpdb->query("DELETE FROM `{$wpdb->posts}` WHERE ID IN ({$in})");
                foreach ($ids as $id) {
                    wp_cache_delete($id, 'posts');
                    wp_cache_delete($id, 'post_meta');
                }
                // A batch that deleted nothing would come back as the same batch: stop instead.
                $more = is_int($deleted) && $deleted > 0 && count($ids) === 500;
            } while ($more);
        }
        wp_cache_set_posts_last_changed();
        foreach ($cron_hooks as $hook) {
            wp_unschedule_hook($hook);
        }
    };

    if (is_multisite()) {
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $id) {
            switch_to_blog((int) $id);
            $site();
            restore_current_blog();
        }
    } else {
        $site();
    }

    foreach ($user_meta as $key) {
        delete_metadata('user', 0, $key, '', true);
    }
})();
