<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\Drawer;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Store;

/**
 * The drawer's budget (spec §4): until it is opened, it adds no database query to the screen it
 * is on. Counted with core's own get_num_queries(), the counter the performance baseline's probe
 * recorded (docs/reviews/2026-09-09-performance-baseline.md, "Method") and the one
 * UsageSummaryQueriesTest already makes this kind of claim with, around the drawer's two
 * callbacks, called directly in the order core fires their hooks (`admin_enqueue_scripts`, then
 * `admin_footer`) for a logged-in administrator on an ordinary screen.
 *
 * The reads before the count are not part of the measurement. They are what wp-admin has already
 * done by the time these hooks fire, done here because this suite's request is not an admin
 * request: loading the current user reads its capabilities with get_user_meta()
 * (WP_User::get_caps_data()), and that read, on a cold cache, primes the whole of the user's meta
 * in one query (get_metadata_raw(), update_meta_cache()); get_user_meta($uid) below is the same
 * prime, redone after the test's own update_user_meta() dropped the cache. The Store read is the
 * settings option, which wp-admin has loaded with the other autoloaded options. And wp_scripts()
 * and wp_styles() build core's two registries, which admin-header.php has done with its own
 * enqueues before it fires `admin_enqueue_scripts`: the first wp_enqueue_script() of a request
 * builds them, and building them reads options (permalink_structure, the nonce salts) that this
 * suite's request has not read yet.
 *
 * It also asserts the hooks did their work, so a drawer that printed nothing cannot pass on zero.
 *
 * @group performance
 */
final class DrawerQueriesTest extends TestCase
{
    public function test_the_drawer_adds_no_query_to_an_admin_screen_before_it_is_opened(): void
    {
        $uid = $this->asAdmin();
        update_user_meta($uid, 'alpaca_bot_drawer_conversation', '5');
        set_current_screen('dashboard');
        $drawer = Plugin::instance()->get(Drawer::class);
        get_user_meta($uid);
        Plugin::instance()->get(Store::class)->get('access.chat');
        wp_scripts();
        wp_styles();

        $before = get_num_queries();
        $drawer->enqueue();
        ob_start();
        $drawer->footer();
        $html = (string) ob_get_clean();
        $queries = get_num_queries() - $before;

        $this->assertStringContainsString('id="ab-drawer-launcher"', $html);
        $this->assertStringContainsString('data-conversation="5"', $html);
        $this->assertTrue(wp_script_is(Drawer::HANDLE, 'enqueued'));
        $this->assertTrue(wp_style_is(Drawer::HANDLE, 'enqueued'));
        $this->assertFalse(wp_script_is('alpaca-bot-chat', 'enqueued'));
        $this->assertFalse(wp_script_is('alpaca-bot-htmx', 'enqueued'));
        $this->assertFalse(wp_style_is('alpaca-bot', 'enqueued'));
        $this->assertFalse(wp_script_is('media-views', 'enqueued'));
        $this->assertSame(0, $queries, 'the drawer ran a query before it was opened');
    }
}
