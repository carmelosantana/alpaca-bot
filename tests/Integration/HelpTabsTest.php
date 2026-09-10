<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\HelpTabs;
use AlpacaBot\Admin\Menu;
use AlpacaBot\Admin\SettingsPage;

/**
 * The help tabs over real core: set_current_screen() builds the WP_Screen and fires
 * `current_screen` itself, so this holds only if Plugin hooked HelpTabs there and the screen ids
 * it gates on are the ones core assigns to the plugin's two pages.
 *
 * The screen ids come from core, over a menu registered here, never read back out of HelpTabs.
 * Feeding HelpTabs' own list into set_current_screen() asserts the plugin's answer against
 * itself and passes whatever it says — which is what this file did until 0.5.0, while the
 * settings page was losing all four of its tabs on every locale that translates "Alpaca Bot".
 *
 * @group admin
 */
final class HelpTabsTest extends TestCase
{
    /** @var array<string, mixed>|null core's menu globals as they were before registerMenu() replaced them */
    private ?array $menuGlobals = null;

    public function tear_down(): void
    {
        set_current_screen('front');
        // The admin menu lives in process globals, which core's per-test rollback does not touch:
        // left as registerMenu() made them, the next test that reads $submenu sees this file's
        // registration on top of its own (SettingsPageTest counts the pages).
        if ($this->menuGlobals !== null) {
            foreach ($this->menuGlobals as $name => $value) {
                $GLOBALS[$name] = $value;
            }
            $this->menuGlobals = null;
        }
        parent::tear_down();
    }

    /**
     * Registers the plugin's admin menu into core's globals and returns the two screen ids core
     * assigned it, from core's own get_plugin_page_hookname() over that registration. That is
     * the function HelpTabs asks too, so the translated test below also asserts the settings id
     * as a literal string: the two cannot then be wrong together.
     *
     * `add_menu_page()` writes `sanitize_title($menu_title)` to `$admin_page_hooks[$slug]`
     * (wp-admin/includes/plugin.php:1397) and that value is the prefix of every *submenu*
     * screen id under it (`get_plugin_page_hookname()`, :2152-2158). The top-level page's own
     * slug is a key of that array, which sends it down the `toplevel` branch instead, so its id
     * never sees the title.
     *
     * @return array{string, string} [the chat screen, the settings screen]
     */
    private function registerMenu(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $this->menuGlobals = [];
        foreach (['admin_page_hooks', 'menu', 'submenu', '_registered_pages', '_parent_pages'] as $name) {
            $this->menuGlobals[$name] = $GLOBALS[$name] ?? null;
            $GLOBALS[$name] = [];
        }
        $renderer = static function (): void {};
        (new Menu(new SettingsPage(
            \AlpacaBot\Plugin::instance()->get(\AlpacaBot\Settings\Store::class),
            \AlpacaBot\Plugin::instance()->get(\AlpacaBot\Provider\ModelCatalog::class),
        ), $renderer))->register();

        return [
            get_plugin_page_hookname(Menu::SLUG, ''),
            get_plugin_page_hookname(SettingsPage::SLUG, Menu::SLUG),
        ];
    }

    public function test_the_chat_screen_and_the_settings_page_get_four_tabs_and_the_dashboard_none(): void
    {
        $this->asAdmin();
        foreach ($this->registerMenu() as $id) {
            set_current_screen($id);
            $tabs = get_current_screen()->get_help_tabs();
            $this->assertSame(['alpaca-bot-chat', 'alpaca-bot-shortcodes', 'alpaca-bot-tools', 'alpaca-bot-support'], array_keys($tabs), $id);
            $this->assertStringContainsString('[alpacabot]', $tabs['alpaca-bot-shortcodes']['content']);
            $this->assertStringContainsString('egress policy', $tabs['alpaca-bot-tools']['content']);
        }
        set_current_screen('dashboard');
        $this->assertSame([], get_current_screen()->get_help_tabs());
    }

    /**
     * The locale case. Translating the menu title moves the settings page's screen id, and a
     * hard-coded `alpaca-bot_page_alpaca-bot-settings` lost every tab there. The filter is
     * core's own per-textdomain translation hook, so the menu is registered through the same
     * `__()` calls a real locale would go through.
     */
    public function test_the_settings_page_keeps_its_help_tabs_when_the_menu_title_is_translated(): void
    {
        $this->asAdmin();
        $translate = static fn(string $translation, string $text): string => $text === 'Alpaca Bot' ? 'Robot Alpaca' : $translation;
        add_filter('gettext_alpaca-bot', $translate, 10, 2);
        [$chat, $settings] = $this->registerMenu();

        // Core moved it, and the plugin followed: the two agree without either being a constant.
        $this->assertSame('robot-alpaca_page_alpaca-bot-settings', $settings);
        $this->assertNotSame('alpaca-bot_page_' . SettingsPage::SLUG, $settings);
        $this->assertSame('toplevel_page_alpaca-bot', $chat, 'the top-level page id does not read the title');
        $this->assertSame([$chat, $settings], HelpTabs::screens());

        set_current_screen($settings);
        $this->assertSame(
            ['alpaca-bot-chat', 'alpaca-bot-shortcodes', 'alpaca-bot-tools', 'alpaca-bot-support'],
            array_keys(get_current_screen()->get_help_tabs()),
            $settings,
        );
    }
}
