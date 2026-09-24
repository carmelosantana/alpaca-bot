<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Chat\Message;
use AlpacaBot\Chat\UsageMeter;
use AlpacaBot\Chat\UserPrefs;
use AlpacaBot\Mcp\Drift;
use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\ModelCatalog;
use AlpacaBot\RateLimit;
use AlpacaBot\Rest\ChatController;
use AlpacaBot\Rest\StreamBudget;
use AlpacaBot\Settings\Migrate04;
use AlpacaBot\Settings\Store;
use AlpacaBot\Shortcodes\Chat;

/**
 * uninstall.php against a real database: one of each thing the plugin stores, written where the
 * plugin's own code writes it wherever that is cheap, is gone afterwards; and a neighbour of each
 * kind that the plugin does not own is still there. The neighbours are the half that matters. The
 * routine matches the dynamic names by pattern (an escaped, anchored LIKE, then an exact regex),
 * so each neighbour is picked to be caught by a pattern that is one step too wide: a name that
 * extends one of ours, a name one of ours extends, the same name in a different storage kind (a
 * site transient, not a transient), `-` where an unescaped LIKE `_` would match anything, and our
 * meta key on a post that is not ours.
 *
 * The routine is the file, required the way core's uninstall_plugin() includes it: with
 * WP_UNINSTALL_PLUGIN defined. The plugin is loaded in this suite (bootstrap.php), so this cannot
 * show the file needs none of its classes -- the wp-env run of `wp plugin uninstall` in the task
 * report is what shows that. What it can show is that everything is gone through the API as well
 * as in the table: every name is read once before the routine runs, so a delete that went round
 * WordPress's option and meta caches would still be answered from them.
 *
 * @group uninstall
 */
final class UninstallTest extends TestCase
{
    /** The 0.4.17 options (`v0.4.17:src/Define.php` fields, plus the version stamp), named here independently of uninstall.php. */
    private const LEGACY_OPTIONS = [
        'alpaca_bot_api_url', 'alpaca_bot_api_username', 'alpaca_bot_api_password', 'alpaca_bot_ollama_timeout',
        'alpaca_bot_default_model', 'alpaca_bot_user_can_change_model', 'alpaca_bot_chat_history_limit',
        'alpaca_bot_chat_response_log', 'alpaca_bot_title_generation_method', 'alpaca_bot_user_agent',
        'alpaca_bot_chat_history_save', 'alpaca_bot_spellcheck', 'alpaca_bot_default_avatar', 'alpaca_bot_default_system',
        'alpaca_bot_default_template', 'alpaca_bot_default_assistant_welcome_message',
        'alpaca_bot_default_assistant_prompt_placeholder', 'alpaca_bot_default_mirostat', 'alpaca_bot_default_mirostat_eta',
        'alpaca_bot_default_mirostat_tau', 'alpaca_bot_default_num_ctx', 'alpaca_bot_default_num_gqa',
        'alpaca_bot_default_num_gpu', 'alpaca_bot_default_num_thread', 'alpaca_bot_default_repeat_last_n',
        'alpaca_bot_default_repeat_penalty', 'alpaca_bot_default_temperature', 'alpaca_bot_default_seed',
        'alpaca_bot_default_stop', 'alpaca_bot_default_tfs_z', 'alpaca_bot_default_num_predict', 'alpaca_bot_default_top_k',
        'alpaca_bot_default_top_p', 'alpaca_bot_version',
    ];

    /** Options that extend one of ours, that one of ours extends, or that an unanchored or unescaped match would take. */
    private const NEIGHBOUR_OPTIONS = [
        'alpaca_bot_settings_backup', 'alpaca_bot_mcp_secrets_old', 'xalpaca_bot_settings', 'alpaca_bot',
        'alpaca_bot_stream_slot_backup', 'alpaca_bot_stream_slot_7_0_old', 'alpaca-bot-stream-slot-7-0',
        'alpaca_bot_cache_notahash', 'alpaca_bot_migrated_05', 'alpaca_bot_api_url_v2', 'other_plugin_settings',
    ];

    /**
     * Transients next to ours. A server id starts with a letter; not `_Srv` for the drift one,
     * because option_name's collation is case-insensitive and that is our `srv` row.
     */
    private const NEIGHBOUR_TRANSIENTS = [
        'alpaca_bot_models_backup', 'alpaca_bot_rl_backup', 'alpaca_bot_stream_notatoken', 'alpaca_bot_usage_site_2026',
        'alpaca_bot_mcp_drift_9srv', 'other_plugin_models',
    ];

    private function uninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'alpaca-bot/alpaca-bot.php');
        }
        require dirname(__DIR__, 2) . '/uninstall.php';
    }

    private function optionRow(string $name): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name)) > 0;
    }

    private function postRow(int $id): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id)) > 0;
    }

    private function postMetaRows(int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $id));
    }

    private function userMetaRow(int $user, string $key): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $user, $key)) > 0;
    }

    /** Every option row the test wrote or the plugin wrote for it, so the gone/kept assertions read the rows, not a guess. */
    private function assertOptionGone(string $name): void
    {
        $this->assertFalse($this->optionRow($name), "option row $name is still there");
        $this->assertSame('gone', get_option($name, 'gone'), "get_option($name) still answers from the cache");
    }

    private function assertTransientGone(string $name): void
    {
        $this->assertFalse($this->optionRow('_transient_' . $name), "transient $name is still there");
        $this->assertFalse($this->optionRow('_transient_timeout_' . $name), "transient timeout $name is still there");
        $this->assertFalse(get_transient($name), "get_transient($name) still answers from the cache");
    }

    /** A 0.4 cache name with one character too many. */
    private function hashPlusOne(): string
    {
        return 'alpaca_bot_cache_' . md5('x') . '0';
    }

    /** @return array<string, int> what was written, by name, for the assertions */
    private function plantEverything(int $user): array
    {
        $store = Plugin::instance()->get(Store::class);
        $store->set('privacy.save_history', true);
        $plugin = Plugin::instance();

        // Options: the settings row exists from TestCase::set_up(); the rest are written here.
        Secrets::put(['srv' => 'Bearer token']);
        foreach ([Migrate04::FLAG, Migrate04::FLAG_CONVERSATIONS, Migrate04::FLAG_RETENTION, Migrate04::FLAG_AUTOLOAD] as $flag) {
            update_option($flag, '1', true);
        }
        $budget = new StreamBudget($store);
        $this->assertNotNull($budget->claim($user)['slot']);
        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->assertNotNull($budget->claim(0)['slot']);
        (new RateLimit())->hit(0);
        if ($address === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $address;
        }

        // Transients: through the code that writes them.
        (new RateLimit())->hit($user);
        Drift::set('srv', ['tool_a']);
        set_transient(ModelCatalog::TRANSIENT, [['id' => 'fake-model']], 300);
        set_transient(ChatController::STREAM_TRANSIENT . wp_generate_password(32, false), ['user_id' => $user], 120);
        set_transient(Chat::TRANSIENT_PREFIX . md5('shortcode'), 'cached answer', 60);

        // Posts: a conversation with its transcript, a receipt (and the month totals it caches).
        $conversations = $plugin->get(ConversationStore::class);
        $c = $conversations->create($user, 'A conversation');
        $this->assertGreaterThan(0, $c->id);
        $c->append(new Message('user', 'hello', 'fake-model'));
        $conversations->save($c);
        $receipt = $plugin->get(UsageMeter::class)->record($user, 'fake-model', 10, 20, 30, $c->id);
        $this->assertGreaterThan(0, $receipt);
        $plugin->get(UsageMeter::class)->monthSummary($user);
        $plugin->get(UsageMeter::class)->monthSummary(null);
        // A 0.4 conversation the migration gave up on: `publish`, 0.4's meta, the attempts count.
        $stuck = self::factory()->post->create(['post_type' => ConversationStore::POST_TYPE, 'post_status' => 'publish', 'post_author' => $user]);
        update_post_meta($stuck, 'messages', [['message' => ['role' => $user, 'content' => 'hi']]]);
        update_post_meta($stuck, 'chat_mode_generate', true);
        update_post_meta($stuck, Migrate04::META_ATTEMPTS, 3);
        // A trashed receipt is still a row.
        $trashed = self::factory()->post->create(['post_type' => UsageMeter::POST_TYPE, 'post_status' => 'trash']);
        update_post_meta($trashed, 'total_tokens', 5);

        // User meta.
        $prefs = $plugin->get(UserPrefs::class);
        $prefs->setDefaultModel($user, 'fake-model');
        $prefs->setDrawerOpen($user, true);
        $prefs->setDrawerConversation($user, $c->id);

        // Cron.
        $plugin->get(UsageMeter::class)->scheduleCleanup();

        // 0.4.17's leftovers: its options, its model-list transient, its per-user settings, and
        // its shortcode cache, which it kept as an option, a transient or post meta on the page.
        foreach (self::LEGACY_OPTIONS as $name) {
            update_option($name, 'legacy');
        }
        set_transient('alpaca_bot_ollama_models', ['llama'], 3600);
        update_user_meta($user, 'alpaca_bot_user_settings', ['model' => 'llama']);
        $hash = md5('0.4 shortcode');
        update_option('alpaca_bot_cache_' . $hash, 'cached');
        set_transient('alpaca_bot_cache_' . md5('0.4 transient'), 'cached', 3600);
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, 'alpaca_bot_cache_' . $hash, 'cached');

        return ['conversation' => $c->id, 'receipt' => $receipt, 'stuck' => $stuck, 'trashed' => $trashed, 'page' => $page];
    }

    /** @return array<string, int> */
    private function plantNeighbours(int $user, int $other): array
    {
        foreach ([...self::NEIGHBOUR_OPTIONS, $this->hashPlusOne()] as $name) {
            update_option($name, 'not ours');
        }
        foreach (self::NEIGHBOUR_TRANSIENTS as $name) {
            set_transient($name, 'not ours', 3600);
        }
        // One of our names as a *site* transient, which the plugin never writes.
        set_site_transient(ModelCatalog::TRANSIENT, 'not ours', 3600);

        // A post of a neighbouring type, and a plain post carrying one of our post meta keys.
        $archive = self::factory()->post->create(['post_type' => 'chat_history_backup']);
        $post = self::factory()->post->create(['post_type' => 'post', 'post_author' => $user]);
        update_post_meta($post, ConversationStore::META_MESSAGES, 'not ours');
        update_post_meta($post, Migrate04::META_ATTEMPTS, 1);
        update_post_meta($post, 'alpaca_bot_cache_notahash', 'not ours');
        update_post_meta($post, 'total_tokens', 9);

        // User meta sharing our prefix, and another user's own key.
        update_user_meta($user, 'alpaca_bot_drawer_open_backup', '1');
        update_user_meta($user, 'alpaca_bot_default_model_x', 'not ours');
        update_user_meta($other, 'other_plugin_pref', 'kept');

        // Cron events that are not ours.
        wp_schedule_event(time() + 3600, 'daily', UsageMeter::CLEANUP_HOOK . '_backup');
        wp_schedule_event(time() + 3600, 'daily', 'other_plugin_cleanup');

        return ['archive' => $archive, 'post' => $post];
    }

    public function test_it_removes_every_artefact_the_plugin_stores_and_nothing_next_to_them(): void
    {
        $user = self::factory()->user->create(['role' => 'editor']);
        $other = self::factory()->user->create();
        $ours = $this->plantEverything($user);
        $theirs = $this->plantNeighbours($user, $other);

        global $wpdb;
        $slots = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(StreamBudget::OPTION) . '%'));
        $this->assertCount(4, $slots, 'two stream slots and the two neighbours');
        $transients = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_alpaca_bot_') . '%'));
        // Prime every cache the API would answer from, so a delete behind its back shows.
        foreach ([...$slots, ...$transients, Plugin::OPTION, Secrets::OPTION, ...self::LEGACY_OPTIONS] as $name) {
            get_option($name);
        }
        foreach (['conversation', 'receipt', 'stuck', 'trashed'] as $which) {
            $this->assertNotNull(get_post($ours[$which]));
            get_post_meta($ours[$which]);
        }
        get_user_meta($user);
        $this->assertNotFalse(wp_next_scheduled(UsageMeter::CLEANUP_HOOK));
        $this->assertTrue($this->userMetaRow($user, UserPrefs::META_DRAWER_OPEN));

        $this->uninstall();

        // Ours: options.
        foreach ([Plugin::OPTION, Secrets::OPTION, Migrate04::FLAG, Migrate04::FLAG_CONVERSATIONS, Migrate04::FLAG_RETENTION, Migrate04::FLAG_AUTOLOAD, ...self::LEGACY_OPTIONS] as $name) {
            $this->assertOptionGone($name);
        }
        foreach ($slots as $name) {
            if (!str_ends_with($name, 'backup') && !str_ends_with($name, 'old')) {
                $this->assertOptionGone($name);
            }
        }
        $this->assertOptionGone('alpaca_bot_cache_' . md5('0.4 shortcode'));
        // Ours: every transient the plugin wrote, found by listing the rows rather than by name.
        foreach ($transients as $row) {
            $name = (string) preg_replace('/^_transient_(timeout_)?/', '', $row);
            if (in_array($name, self::NEIGHBOUR_TRANSIENTS, true)) {
                continue;
            }
            $this->assertTransientGone($name);
        }
        $this->assertGreaterThanOrEqual(10, count($transients), 'rate limit x2, drift, models, stream, shortcode, usage x2, 0.4 models and cache, each with a timeout');
        // Ours: posts and every row of their meta.
        foreach (['conversation', 'receipt', 'stuck', 'trashed'] as $which) {
            $this->assertFalse($this->postRow($ours[$which]), "$which post is still there");
            $this->assertSame(0, $this->postMetaRows($ours[$which]), "$which post meta is still there");
            $this->assertNull(get_post($ours[$which]), "get_post() still answers $which from the cache");
        }
        $this->assertSame('', get_post_meta($ours['page'], 'alpaca_bot_cache_' . md5('0.4 shortcode'), true), '0.4.17\'s shortcode cache is still on the page');
        $this->assertTrue($this->postRow($ours['page']), 'the page carrying the 0.4 cache is not ours');
        // Ours: user meta.
        foreach ([UserPrefs::META_DEFAULT_MODEL, UserPrefs::META_DRAWER_OPEN, UserPrefs::META_DRAWER_CONVERSATION, 'alpaca_bot_user_settings'] as $key) {
            $this->assertFalse($this->userMetaRow($user, $key), "user meta $key is still there");
            $this->assertSame('', get_user_meta($user, $key, true), "get_user_meta($key) still answers from the cache");
        }
        // Ours: the cron event.
        $this->assertFalse(wp_next_scheduled(UsageMeter::CLEANUP_HOOK), 'the usage cleanup event is still scheduled');

        // Theirs: every neighbour.
        // Collected, not asserted one at a time, so a failure names every neighbour that went.
        $lost = array_values(array_filter([...self::NEIGHBOUR_OPTIONS, $this->hashPlusOne()], fn(string $n): bool => !$this->optionRow($n)));
        $this->assertSame([], $lost, 'options that are not the plugin\'s were deleted');
        $lost = array_values(array_filter(self::NEIGHBOUR_TRANSIENTS, fn(string $n): bool => !$this->optionRow('_transient_' . $n)));
        $this->assertSame([], $lost, 'transients that are not the plugin\'s were deleted');
        // Read through the API: a site transient is an options row on a single site and a sitemeta row on a network.
        $this->assertSame('not ours', get_site_transient(ModelCatalog::TRANSIENT), 'the site transient of the same name is not ours');
        $this->assertTrue($this->postRow($theirs['archive']), 'a post of a neighbouring type was deleted');
        $this->assertTrue($this->postRow($theirs['post']), 'a plain post was deleted');
        foreach ([ConversationStore::META_MESSAGES, Migrate04::META_ATTEMPTS, 'alpaca_bot_cache_notahash', 'total_tokens'] as $key) {
            $this->assertNotSame('', get_post_meta($theirs['post'], $key, true), "post meta $key on a post that is not ours was deleted");
        }
        $this->assertTrue($this->userMetaRow($user, 'alpaca_bot_drawer_open_backup'), 'user meta alpaca_bot_drawer_open_backup was deleted');
        $this->assertTrue($this->userMetaRow($user, 'alpaca_bot_default_model_x'), 'user meta alpaca_bot_default_model_x was deleted');
        $this->assertTrue($this->userMetaRow($other, 'other_plugin_pref'), 'user meta other_plugin_pref was deleted');
        $this->assertNotFalse(wp_next_scheduled(UsageMeter::CLEANUP_HOOK . '_backup'), 'a cron event next to ours was unscheduled');
        $this->assertNotFalse(wp_next_scheduled('other_plugin_cleanup'), 'another plugin\'s cron event was unscheduled');
    }

    /** Run twice, and on a site that never stored anything: nothing to remove is not an error. */
    public function test_it_is_safe_to_run_on_a_site_with_nothing_to_remove(): void
    {
        delete_option(Plugin::OPTION);
        $this->uninstall();
        $this->uninstall();
        $this->assertFalse($this->optionRow(Plugin::OPTION));
    }

    /**
     * On a network every site's rows go, not only the current site's: the plugin's files are
     * gone for all of them. Runs only when the suite is run as multisite (WP_MULTISITE=1).
     */
    public function test_on_a_network_it_removes_every_sites_rows(): void
    {
        if (!is_multisite()) {
            $this->markTestSkipped('run the suite with WP_MULTISITE=1');
        }
        $user = self::factory()->user->create();
        $blog = self::factory()->blog->create();
        switch_to_blog($blog);
        update_option(Plugin::OPTION, ['x' => 1]);
        update_option('alpaca_bot_settings_backup', 'not ours');
        set_transient(ModelCatalog::TRANSIENT, ['m'], 300);
        $post = self::factory()->post->create(['post_type' => ConversationStore::POST_TYPE]);
        $plain = self::factory()->post->create(['post_type' => 'post']);
        wp_schedule_event(time() + 3600, 'daily', UsageMeter::CLEANUP_HOOK);
        restore_current_blog();
        update_user_meta($user, UserPrefs::META_DRAWER_OPEN, '1');

        $this->uninstall();

        $this->assertSame(get_main_site_id(), get_current_blog_id(), 'the routine leaves the main site current');
        switch_to_blog($blog);
        $this->assertFalse($this->optionRow(Plugin::OPTION));
        $this->assertFalse($this->optionRow('_transient_' . ModelCatalog::TRANSIENT));
        $this->assertFalse($this->postRow($post));
        $this->assertFalse(wp_next_scheduled(UsageMeter::CLEANUP_HOOK));
        $this->assertTrue($this->optionRow('alpaca_bot_settings_backup'));
        $this->assertTrue($this->postRow($plain));
        restore_current_blog();
        $this->assertFalse($this->userMetaRow($user, UserPrefs::META_DRAWER_OPEN));
    }
}
