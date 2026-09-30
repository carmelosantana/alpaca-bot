<?php
/**
 * Alpaca Bot's uninstall routine: WordPress includes this file when the plugin is deleted from
 * the Plugins screen, or on `wp plugin uninstall`. The plugin is inactive then, and was not loaded
 * for the request unless `wp plugin uninstall --deactivate` loaded it before deactivating it, so
 * nothing here uses its autoloader or a class of its own: every name is written out below, and
 * the list is the inventory. tests/Unit/UninstallInventoryTest.php reads every call in src/ to
 * a writer on its lists: the WordPress functions that store options, transients, meta, cron
 * events, post types, terms and the rest its docblock names; `$wpdb` writes; and the table,
 * upload and `$wp_filesystem` calls. It fails when one stores under a name this file does not
 * name, under a name built at run time that its map does not tie to an entry here, or in a table
 * or file. A writer that is not on its lists is not seen, and nor are the `meta_input` and
 * `tax_input` keys of wp_insert_post() and wp_update_post(), which it reads only for `post_type`.
 * tests/Integration/UninstallTest.php runs this file over one of each row and a neighbour of each
 * that is not ours.
 *
 * What it removes, on each site:
 * - options: the settings row, the provider API key (`alpaca_bot_provider_key`), the MCP header
 *   values (`alpaca_bot_mcp_secrets`), the four
 *   Settings\Migrate04 flags, the stream slot rows Rest\StreamBudget writes with $wpdb, and the
 *   options 0.4 wrote (one per settings field 0.4.17 had and five older ones, its version
 *   stamp, and its shortcode cache);
 * - transients: the model catalog, and every dynamic one (rate limit windows, usage month
 *   totals, shortcode answers, stream tickets, MCP drift markers, MCP sessions, 0.4.17's model
 *   list and shortcode cache);
 * - posts: every `chat_history` (conversations) and `chat_log` (usage receipts) post in any
 *   status, and all of their post meta, which is where the transcript, the receipt's numbers and
 *   Migrate04's attempts count live; unless another loaded plugin has registered the type (the
 *   last paragraph);
 * - post meta: 0.4.17's shortcode cache, left on the post that showed it (the post stays);
 * - the `alpaca_bot/usage/cleanup` cron event.
 * And once, because a network's sites share one user meta table: the three Chat\UserPrefs user
 * meta keys and 0.4.17's `alpaca_bot_user_settings`.
 *
 * What it leaves: drafts the create-draft tool wrote. They are ordinary posts of the site's own
 * types, owned by the user who asked for them, and nothing marks them as the plugin's. And the
 * names releases before 0.4.16 wrote without this plugin's prefix: those releases were published
 * on GitHub alone, and `chat` and `log` (post types), `ollama_models` (a transient), `ollama_*`
 * (0.1 to 0.2's options and user meta) and `custom_*` (the v0.4.0 tag's options, a prefix bug
 * fixed the same day in d856ff7) are names another plugin may own. The prefixed names every
 * tag from v0.1.0 to v0.4.17 wrote are removed, the older ones cited in the list below; untagged
 * commits on main were looked at only where a tag showed a gap (d856ff7).
 *
 * Fixed names are deleted by name. Dynamic names are found with a LIKE on the escaped prefix,
 * anchored at the start, and each row it returns is deleted only if the whole name matches the
 * exact shape the plugin writes; so a neighbour that shares a prefix is left alone. Deletes go
 * through delete_option() and delete_metadata(), so WordPress's caches drop the rows with the
 * table. Posts are the exception: they are deleted in SQL, in batches of 500, rather than
 * with wp_delete_post(), which runs several queries and a hook chain for each row, and a
 * site can hold years of receipts. So no `delete_post` hooks fire for them, and each one's post,
 * meta, comment and term caches are dropped by hand. The plugin writes no terms or comments on
 * them, but other code can, and what it hangs off them by post id goes with them: their term
 * relationships in a taxonomy registered for post types alone (those terms are recounted), and
 * their comments with the comments' meta. Left: a relationship in a taxonomy registered for
 * anything else (`link_category`, a user taxonomy) or registered by nothing loaded, because
 * term_relationships does not say what kind of object its id names; and a post whose
 * post_parent names one of them.
 *
 * A persistent object cache: a transient then lives in the cache, not in the options table, and
 * a cache cannot be listed by prefix. The two with a fixed name are deleted through
 * delete_transient(), which reaches the cache. The dynamic ones cannot be found there and are
 * left to expire. Every one is written with an expiry: a week for an MCP drift marker, the
 * `cache` seconds its shortcode asked for on a shortcode answer (0.4.17's as well as this
 * version's), and at most an hour for the rest.
 *
 * Multisite: every site's rows are removed, not only the current site's, because the plugin's
 * files are gone for all of them whether it was network-activated or activated per site. Each
 * site costs a switch_to_blog() and a query or two for every name and pattern below, more where
 * there are rows to delete, so a very large network pays for that in one request. `wp plugin
 * uninstall` runs it under PHP's command line, which has no time limit by default.
 *
 * `chat_history` and `chat_log` are not prefixed (0.4 named them, and existing sites hold rows
 * under them), and nothing on a post says which plugin wrote it, so the decision is per type:
 * - registered for this request with the plugin's mark (`alpaca_bot_owned`, Plugin::POST_TYPE_MARK;
 *   `wp plugin uninstall --deactivate` loaded the plugin first): the plugin registered the name
 *   and nothing else had, so the posts are its own, removed;
 * - registered without the mark: another plugin loaded for this request uses the name, whether
 *   it registered before the plugin (which then registered over it unmarked) or after, and every
 *   post of that type is left, the plugin's own included;
 * - registered by nothing (the Plugins screen and a plain `wp plugin uninstall`, where the
 *   plugin is not loaded): removed. So an *inactive* plugin's posts under the same name are
 *   deleted too; nothing can tell them apart.
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
        'alpaca_bot_provider_key',
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
        // Written only before 0.4.16, when releases were published on GitHub alone, through the
        // Settings API (register_setting() over the same prefix): v0.3.0:src/Options.php:191,212,
        // 222,227, and main again from d856ff7 (setPrefix) until 7b8a000 dropped the token; and
        // v0.4.9:src/Define.php:118. The first is an API credential.
        'alpaca_bot_api_token',
        'alpaca_bot_save_chat_history',
        'alpaca_bot_default_system_message',
        'alpaca_bot_default_message_placeholder',
        'alpaca_bot_log_chat_response',
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
        // Mcp\TransientSessions: an md5 of php-agents' session key.
        'alpaca_bot_mcp_session_' => '/^alpaca_bot_mcp_session_[0-9a-f]{32}\z/',
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
        // Taxonomy => term_taxonomy_id => true, for the terms that lost a post, recounted once below.
        $recount = [];
        foreach ($post_types as $type) {
            // Registered now, by anything but this plugin (whose registrations carry the mark),
            // means another loaded plugin uses the name and the posts may be its own.
            $object = get_post_type_object($type);
            if ($object !== null && !(property_exists($object, 'alpaca_bot_owned') && $object->alpaca_bot_owned === true)) {
                continue;
            }
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The file docblock says why posts are deleted in SQL. The type is prepared; the interpolation is $wpdb's table name.
                $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM `{$wpdb->posts}` WHERE post_type = %s ORDER BY ID LIMIT 500", $type)));
                if ($ids === []) {
                    break;
                }
                $in = implode(',', $ids);
                // Term relationships. object_id is only a number, and a taxonomy decides what kind
                // of object it names, so a row goes only when its taxonomy is registered for post
                // types alone: `link_category` names links, a user taxonomy names users, and a
                // taxonomy nothing loaded registers cannot say.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of integers cast above; the rest is $wpdb's table names.
                $rows = $wpdb->get_results("SELECT tr.object_id, tr.term_taxonomy_id, tt.taxonomy FROM `{$wpdb->term_relationships}` tr INNER JOIN `{$wpdb->term_taxonomy}` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id IN ({$in})");
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $taxonomy = get_taxonomy((string) $row->taxonomy);
                    $types = $taxonomy === false ? [] : (array) $taxonomy->object_type;
                    $forPosts = $types !== [] && array_filter($types, static fn(string $t): bool => !in_array($t, $post_types, true) && !post_type_exists($t)) === [];
                    if (!$forPosts) {
                        continue;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above; the term count and cache are put right below.
                    $wpdb->delete($wpdb->term_relationships, ['object_id' => (int) $row->object_id, 'term_taxonomy_id' => (int) $row->term_taxonomy_id], ['%d', '%d']);
                    wp_cache_delete((int) $row->object_id, $taxonomy->name . '_relationships');
                    $recount[$taxonomy->name][(int) $row->term_taxonomy_id] = true;
                }
                // Comments on them, and those comments' meta.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
                $comments = array_map('intval', $wpdb->get_col("SELECT comment_ID FROM `{$wpdb->comments}` WHERE comment_post_ID IN ({$in})"));
                if ($comments !== []) {
                    $cin = implode(',', $comments);
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cin is a list of integers cast above; the rest is $wpdb's table name.
                    $wpdb->query("DELETE FROM `{$wpdb->commentmeta}` WHERE comment_id IN ({$cin})");
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
                    $wpdb->query("DELETE FROM `{$wpdb->comments}` WHERE comment_ID IN ({$cin})");
                    clean_comment_cache($comments);
                }
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
        foreach ($recount as $taxonomy => $tt) {
            wp_update_term_count_now(array_keys($tt), $taxonomy);
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
