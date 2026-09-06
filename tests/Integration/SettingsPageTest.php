<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\Menu;
use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;

/**
 * The Settings API page over real core: register_setting()'s sanitize path as options.php
 * drives it (update_option() -> sanitize_option() -> our callback), the real menu globals, and
 * the page markup itself. wp-admin/includes/admin.php is loaded by wp-phpunit's bootstrap
 * (its xmlrpc test case requires it), so add_menu_page() and the settings template helpers
 * exist in this process.
 *
 * @group admin
 */
final class SettingsPageTest extends TestCase
{
    /**
     * The registration is run directly rather than through do_action('admin_init'): core hooks
     * send_frame_options_header() there, and header() under PHPUnit's CLI, with output already
     * started, is a fatal. That the plugin hooks admin_init is asserted on its own below.
     */
    public function set_up(): void
    {
        parent::set_up();
        $this->asAdmin();
        Plugin::instance()->get(SettingsPage::class)->register();
    }

    public function test_the_page_registers_on_admin_init_and_the_menu_on_admin_menu(): void
    {
        $this->assertNotFalse(has_action('admin_init', [Plugin::instance()->get(SettingsPage::class), 'register']));
        $this->assertNotFalse(has_action('admin_menu'));
    }

    public function tear_down(): void
    {
        unset($_GET['tab']);
        parent::tear_down();
    }

    public function test_setting_is_registered_and_sanitized_on_save(): void
    {
        $registered = get_registered_settings();
        $this->assertArrayHasKey('alpaca_bot_settings', $registered);
        $this->assertSame('alpaca_bot', $registered['alpaca_bot_settings']['group']);
        update_option('alpaca_bot_settings', ['models.num_ctx' => '99999999999', 'provider.kind' => 'nope']);
        $saved = get_option('alpaca_bot_settings');
        $this->assertSame(1048576, $saved['models.num_ctx']);
        $this->assertSame('ollama', $saved['provider.kind']);
        // A save is the whole array: every schema key, in schema order, nothing else.
        $this->assertSame(array_keys(Schema::fields()), array_keys($saved));
    }

    public function test_a_fresh_install_reads_the_defaults_from_the_registered_setting(): void
    {
        delete_option('alpaca_bot_settings');
        $this->assertSame(Schema::defaults(), get_option('alpaca_bot_settings'));
    }

    // What the page posts for an untouched password field is the mask, and what it posts for
    // a cleared one is ''. Both must reach the option the way Schema::sanitize() means them.
    public function test_saving_the_mask_keeps_the_stored_key_and_an_empty_string_clears_it(): void
    {
        update_option('alpaca_bot_settings', array_merge(Schema::defaults(), ['provider.api_key' => 'sk-integration']));
        $this->assertSame('sk-integration', get_option('alpaca_bot_settings')['provider.api_key']);
        update_option('alpaca_bot_settings', ['provider.api_key' => Schema::MASK, 'models.temperature' => '1.1'] + Schema::defaults());
        $saved = get_option('alpaca_bot_settings');
        $this->assertSame('sk-integration', $saved['provider.api_key']);
        $this->assertSame(1.1, $saved['models.temperature']);
        update_option('alpaca_bot_settings', ['provider.api_key' => ''] + Schema::defaults());
        $this->assertSame('', get_option('alpaca_bot_settings')['provider.api_key']);
    }

    public function test_menu_pages_are_registered(): void
    {
        set_current_screen('dashboard');
        do_action('admin_menu');
        global $menu, $submenu;
        $this->assertContains(Menu::SLUG, array_column($menu, 2));
        $slugs = array_column($submenu['alpaca-bot'] ?? [], 2);
        $this->assertSame(['alpaca-bot', 'alpaca-bot-settings'], $slugs);
        $settings = $submenu['alpaca-bot'][array_search('alpaca-bot-settings', $slugs, true)];
        $this->assertSame('manage_options', $settings[1]);
        $this->assertSame('Settings', $settings[0]);
    }

    public function test_menu_capability_is_filterable(): void
    {
        add_filter('alpaca_bot/admin/menu_capability', static fn(): string => 'read');
        set_current_screen('dashboard');
        do_action('admin_menu');
        global $menu;
        $entry = null;
        foreach ($menu as $item) {
            if (($item[2] ?? null) === Menu::SLUG) {
                $entry = $item;
            }
        }
        $this->assertNotNull($entry);
        $this->assertSame('read', $entry[1]);
    }

    /**
     * The whole old first-save bug, in-process: render the Chat tab, post back exactly what
     * the form carries plus one change, and every other tab's value survives, the key included
     * and never printed. The posted array is rebuilt from the hidden inputs the way a browser
     * would build it, so the test fails if the carry-over ever stops covering a field.
     */
    public function test_saving_one_tab_keeps_every_other_tab_and_never_prints_the_key(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->replace(array_merge(Schema::defaults(), [
            'provider.api_key' => 'sk-secret-integration',
            'provider.base_url' => 'http://ollama.internal:11434/v1',
            'models.default' => 'qwen3-vl:2b',
            'models.temperature' => 0.3,
            'models.overrides' => ['qwen3-vl:2b' => ['num_ctx' => 4096, 'system' => 'Be brief & kind']],
            'chat.spellcheck' => false,
            'privacy.usage_retention_days' => 0,
        ]));
        $before = get_option('alpaca_bot_settings');
        $this->assertSame('sk-secret-integration', $before['provider.api_key']);

        $_GET['tab'] = 'chat';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('sk-secret-integration', $html);
        $this->assertStringContainsString('<textarea id="ab-chat-welcome" name="alpaca_bot_settings[chat.welcome]"', $html);
        $this->assertMatchesRegularExpression('/name=[\'"]option_page[\'"] value=[\'"]alpaca_bot[\'"]/', $html);
        $this->assertStringContainsString('name="_wpnonce"', $html);
        $this->assertSame(count(Schema::sections()), preg_match_all('/class="nav-tab( nav-tab-active)?"/', $html));

        $posted = self::postedFrom($html);
        $this->assertSame(Schema::MASK, $posted['provider.api_key']);
        $this->assertSame('How can I help?', $posted['chat.welcome']);
        $posted['chat.welcome'] = 'Hello from the Chat tab';
        $posted['chat.spellcheck'] = '1';

        update_option('alpaca_bot_settings', $posted);
        $after = get_option('alpaca_bot_settings');
        $this->assertSame('Hello from the Chat tab', $after['chat.welcome']);
        $this->assertTrue($after['chat.spellcheck']);
        unset($before['chat.welcome'], $after['chat.welcome'], $before['chat.spellcheck'], $after['chat.spellcheck']);
        $this->assertSame($before, $after);
    }

    /**
     * The form fields as a browser would post them: visible controls and hidden carry-overs
     * alike, `alpaca_bot_settings[a.b]` and `alpaca_bot_settings[a.b][m][f]` names unpacked.
     *
     * @return array<string, mixed>
     */
    private static function postedFrom(string $html): array
    {
        $posted = [];
        preg_match_all('/<(input|textarea|select)\b([^>]*)>(.*?<\/\1>)?/s', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            if (!preg_match('/name="alpaca_bot_settings\[([^"]+)\]"/', $tag[2], $name)) {
                continue;
            }
            $path = explode('][', $name[1]);
            if ($tag[1] === 'textarea') {
                $value = substr($tag[3] ?? '', 0, -strlen('</textarea>'));
            } elseif ($tag[1] === 'select') {
                preg_match('/<option value="([^"]*)" selected="selected"/', $tag[3] ?? '', $sel);
                $value = $sel[1] ?? '';
            } else {
                preg_match('/value="([^"]*)"/', $tag[2], $val);
                $value = $val[1] ?? '';
                // A checkbox posts only when checked; its hidden 0 in front was already taken.
                if (str_contains($tag[2], 'type="checkbox"') && !str_contains($tag[2], 'checked')) {
                    continue;
                }
            }
            $value = html_entity_decode((string) $value, ENT_QUOTES);
            if (count($path) === 3) {
                $posted[$path[0]][$path[1]][$path[2]] = $value;
            } else {
                $posted[$path[0]] = $value;
            }
        }
        return $posted;
    }
}
