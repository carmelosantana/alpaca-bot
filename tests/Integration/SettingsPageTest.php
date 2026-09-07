<?php

declare(strict_types=1);

namespace AlpacaBot\Tests\Integration;

use AlpacaBot\Admin\Menu;
use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Plugin;
use AlpacaBot\Provider\Model;
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
        $_POST = [];
        $GLOBALS['wp_settings_errors'] = [];
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
     * `__return_true` is what a site reaches for when a menu will not appear, and it is the one
     * value that must not work: `(string) true` is '1', which core reads as the legacy `level_1`
     * check rather than as a capability. On stock roles `edit_posts` and `level_1` happen to
     * cover the same set, so the harm is not visible there; what the cast really does is throw
     * away whatever capability the site settled on and check a user level instead. Asserted
     * against a real WordPress, because the point is what core's capability map makes of '1' —
     * which is also why this test is the one place that asks core a deprecated question.
     *
     * @expectedDeprecated has_cap
     */
    public function test_menu_capability_filter_returning_true_does_not_open_the_chat_screen(): void
    {
        add_filter('alpaca_bot/admin/menu_capability', '__return_true');
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
        $this->assertSame('edit_posts', $entry[1], 'a filter that is not a capability name must leave the declared one in place');

        // A role with a legacy user level and none of the plugin's capability: the two checks
        // are not the same question, and the cast would have answered the wrong one. Plugins
        // that build roles from user levels still produce roles shaped like this.
        add_role('ab_leveller', 'Leveller', ['read' => true, 'level_0' => true, 'level_1' => true]);
        $leveller = self::factory()->user->create_and_get(['role' => 'ab_leveller']);
        $this->assertTrue($leveller->has_cap('1'), "'1' is the level_1 check, which this role passes");
        $this->assertFalse($leveller->has_cap('edit_posts'), 'and edit_posts, which it does not');
        remove_role('ab_leveller');
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
            // Posted by a textarea as CRLF; stored as LF, and carried through a hidden input unchanged.
            'chat.system_prompt' => "You are terse.\r\nAnswer in one line.",
            'chat.spellcheck' => false,
            'privacy.usage_retention_days' => 0,
        ]));
        $before = get_option('alpaca_bot_settings');
        $this->assertSame('sk-secret-integration', $before['provider.api_key']);
        $this->assertSame("You are terse.\nAnswer in one line.", $before['chat.system_prompt']);

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
     * PHP's max_input_vars (1000 by default) drops the tail of a long POST and tells userland
     * nothing; a Models tab with four inputs per catalog model gets there at a few hundred
     * models. Two layers cover it, and each is proved on its own here. The page ends the form
     * with a marker input, and the sanitize callback refuses a post of the option that arrives
     * without it: nothing changes and the admin is told why. And under that, the schema keeps
     * the stored value for every key a post does not name, so even a post the guard does not
     * see (the callback reached with no $_POST at all) can never clear the key or the URL: the
     * reviewer's case, reproduced against the old layout where the carry-over was the tail.
     */
    public function test_a_post_php_cut_short_is_refused_whole_and_the_schema_keeps_what_it_never_heard(): void
    {
        $store = Plugin::instance()->get(Store::class);
        $store->replace(['provider.api_key' => 'sk-REVIEW-SENTINEL-9f3a', 'provider.base_url' => 'https://openrouter.ai/api/v1', 'models.default' => 'qwen3-vl:2b']);
        $before = get_option('alpaca_bot_settings');

        $_GET['tab'] = 'models';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();
        // The carry-over precedes the visible tab, and the marker is the last input before submit.
        $this->assertLessThan(strpos($html, 'id="ab-models-default"'), strpos($html, 'name="alpaca_bot_settings[provider.api_key]"'));
        $this->assertLessThan(strpos($html, 'id="submit"'), strpos($html, 'name="' . SettingsPage::END_MARKER . '" value="1"'));
        $posted = self::postedFrom($html);
        $posted['models.default'] = 'changed-on-the-models-tab';

        // Layer one, the guard: the post arrived without its marker, so it was cut, and is refused.
        $_POST = ['alpaca_bot_settings' => $posted];
        update_option('alpaca_bot_settings', $posted);
        $this->assertSame($before, get_option('alpaca_bot_settings'));
        $errors = get_settings_errors('alpaca_bot_settings');
        $this->assertCount(1, $errors);
        $this->assertSame('truncated', $errors[0]['code']);
        $this->assertStringContainsString('max_input_vars', $errors[0]['message']);
        // With the marker the same post saves.
        $_POST[SettingsPage::END_MARKER] = '1';
        update_option('alpaca_bot_settings', $posted);
        $this->assertSame('changed-on-the-models-tab', get_option('alpaca_bot_settings')['models.default']);
        $this->assertSame('sk-REVIEW-SENTINEL-9f3a', get_option('alpaca_bot_settings')['provider.api_key']);

        // Layer two, the schema alone: no $_POST, the guard is inert, and the post is the old
        // layout's truncation, the Models fields with the whole carry-over dropped.
        $_POST = [];
        $cut = array_filter($posted, static fn(string $key): bool => Schema::fields()[$key]['section'] === 'models', ARRAY_FILTER_USE_KEY);
        $cut['models.default'] = 'changed-again';
        $this->assertArrayNotHasKey('provider.api_key', $cut);
        update_option('alpaca_bot_settings', $cut);
        $after = get_option('alpaca_bot_settings');
        $this->assertSame('changed-again', $after['models.default']);
        $this->assertSame('sk-REVIEW-SENTINEL-9f3a', $after['provider.api_key']);
        $this->assertSame('https://openrouter.ai/api/v1', $after['provider.base_url']);
        $this->assertSame(array_keys(Schema::fields()), array_keys($after));
    }

    /**
     * The overrides table over real core: a model id from the provider and an override value
     * from the option, both hostile, render escaped at every attribute and text node, and the
     * form posts the stored override back exactly as it was stored.
     */
    public function test_the_models_tab_escapes_model_ids_and_override_values_and_posts_them_back_intact(): void
    {
        $evilId = '"><script>alert(1)</script>';
        $evilValue = '"><img src=x onerror=alert(2)>';
        $this->fakeProvider();
        add_filter('alpaca_bot/models', static fn(): array => [Model::fromArray(['id' => $evilId, 'label' => 'x']), Model::fromArray(['id' => 'qwen3-vl:2b', 'label' => 'qwen'])]);
        $store = Plugin::instance()->get(Store::class);
        $store->replace(['models.num_ctx' => 4096, 'models.overrides' => ['qwen3-vl:2b' => ['num_ctx' => 2048, 'system' => $evilValue], 'gone-model' => ['temperature' => 0.2]]]);

        $_GET['tab'] = 'models';
        set_current_screen('alpaca-bot_page_alpaca-bot-settings');
        ob_start();
        Plugin::instance()->get(SettingsPage::class)->render();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<th scope="row">' . esc_html($evilId) . '</th>', $html);
        $this->assertStringContainsString('name="' . esc_attr('alpaca_bot_settings[models.overrides][' . $evilId . '][temperature]') . '"', $html);
        $this->assertStringContainsString('name="alpaca_bot_settings[models.overrides][qwen3-vl:2b][system]" value="' . esc_attr($evilValue) . '"', $html);
        $this->assertStringContainsString('<th scope="row">gone-model</th>', $html);
        $this->assertStringContainsString('<th>Context window (tokens)</th><th>Keep alive</th>', $html);
        $this->assertStringContainsString('placeholder="4096"', $html);
        // Three table rows (the settings fields above the table are <th scope="row"> too).
        $this->assertSame(1, preg_match('#<tbody>(.*)</tbody>#s', $html, $body));
        $this->assertSame(3, substr_count($body[1], '<tr><th scope="row">'));

        $_POST = ['alpaca_bot_settings' => [], SettingsPage::END_MARKER => '1'];
        update_option('alpaca_bot_settings', self::postedFrom($html));
        $this->assertSame(
            ['qwen3-vl:2b' => ['num_ctx' => 2048, 'system' => $evilValue], 'gone-model' => ['temperature' => 0.2]],
            get_option('alpaca_bot_settings')['models.overrides'],
        );
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
            // A browser decodes the name attribute as it does any other: a model id with markup in it comes back as itself.
            $path = array_map(static fn(string $segment): string => html_entity_decode($segment, ENT_QUOTES), explode('][', $name[1]));
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
