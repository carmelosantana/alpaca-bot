<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\HelpTabs;

/**
 * The help tabs over real core: set_current_screen() builds the WP_Screen and fires
 * `current_screen` itself, so this holds only if Plugin hooked HelpTabs there and the screen ids
 * it gates on are the ones core assigns to the plugin's two pages.
 *
 * @group admin
 */
final class HelpTabsTest extends TestCase
{
    public function tear_down(): void
    {
        set_current_screen('front');
        parent::tear_down();
    }

    public function test_the_chat_screen_and_the_settings_page_get_three_tabs_and_the_dashboard_none(): void
    {
        $this->asAdmin();
        foreach (HelpTabs::SCREENS as $id) {
            set_current_screen($id);
            $tabs = get_current_screen()->get_help_tabs();
            $this->assertSame(['alpaca-bot-chat', 'alpaca-bot-shortcodes', 'alpaca-bot-support'], array_keys($tabs), $id);
            $this->assertStringContainsString('[alpacabot]', $tabs['alpaca-bot-shortcodes']['content']);
        }
        set_current_screen('dashboard');
        $this->assertSame([], get_current_screen()->get_help_tabs());
    }
}
