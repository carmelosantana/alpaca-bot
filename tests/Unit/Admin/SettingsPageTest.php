<?php

declare(strict_types=1);

use AlpacaBot\Admin\SettingsPage;
use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;
use Brain\Monkey\Functions;

// Core calls the sanitize callback with the option *name* as its second argument, so the
// callback must be a closure that supplies the stored array itself: that is what lets a secret
// posted back as the mask keep the stored key instead of becoming the literal mask.
it('registers the option under the alpaca_bot group with a sanitize callback that keeps the stored key on the mask', function (): void {
    Functions\when('add_settings_section')->justReturn(null);
    Functions\when('add_settings_field')->justReturn(null);
    $opts = null;
    Functions\expect('register_setting')->once()->withArgs(function (string $group, string $option, array $o) use (&$opts): bool {
        $opts = $o;
        return $group === 'alpaca_bot' && $option === Plugin::OPTION;
    });
    Functions\when('get_option')->justReturn(['provider.api_key' => 'sk-stored', 'models.temperature' => 1.2, 'models.default' => 'kept']);
    settingsPage()->register();
    expect($opts['type'])->toBe('array')->and($opts['default'])->toBe(Schema::defaults());
    // What core does on options.php: the callback, the posted array, the option name.
    $out = ($opts['sanitize_callback'])(['provider.api_key' => Schema::MASK, 'models.temperature' => '0.3'], Plugin::OPTION);
    expect($out['provider.api_key'])->toBe('sk-stored')->and($out['models.temperature'])->toBe(0.3)
        // Only what was posted survives: the page posts every field, and a caller that does not is corrected by the schema.
        ->and($out['models.default'])->toBe('');
    expect(($opts['sanitize_callback'])('', Plugin::OPTION)['models.num_ctx'])->toBe(Schema::defaults()['models.num_ctx'])
        ->and(($opts['sanitize_callback'])(['provider.api_key' => ''], Plugin::OPTION)['provider.api_key'])->toBe('');
});

it('adds a section per schema section on its own page and a field per schema field on that page', function (): void {
    Functions\when('register_setting')->justReturn(null);
    $sections = [];
    $fields = [];
    Functions\expect('add_settings_section')->times(count(Schema::sections()))->withArgs(function (string $id, string $title, callable $cb, string $page) use (&$sections): bool {
        $sections[$page] = $id;
        return true;
    });
    Functions\expect('add_settings_field')->times(count(Schema::fields()))->withArgs(function (string $id, string $title, callable $cb, string $page, string $section, array $args) use (&$fields): bool {
        $fields[$id] = [$page, $section, $args];
        return true;
    });
    settingsPage()->register();
    expect($sections)->toHaveKey(SettingsPage::SLUG . '-privacy')
        ->and($sections[SettingsPage::SLUG . '-privacy'])->toBe('alpaca_bot_privacy')
        ->and($fields['alpaca_bot_models.temperature'][0])->toBe(SettingsPage::SLUG . '-models')
        ->and($fields['alpaca_bot_models.temperature'][1])->toBe('alpaca_bot_models')
        ->and($fields['alpaca_bot_models.temperature'][2]['label_for'])->toBe('ab-models-temperature')
        // The overrides table has no single control to label.
        ->and($fields['alpaca_bot_models.overrides'][2])->not->toHaveKey('label_for');
});

it('renders the active tab and carries every other tab as hidden inputs with the secret masked', function (): void {
    Functions\when('settings_errors')->justReturn(null);
    Functions\when('settings_fields')->alias(function (string $group): void { echo '<input type="hidden" name="option_page" value="' . $group . '">'; });
    Functions\when('do_settings_sections')->alias(function (string $page): void { echo "<!-- sections:{$page} -->"; });
    Functions\when('submit_button')->alias(function (): void { echo '<input type="submit">'; });
    Functions\when('admin_url')->alias(fn(string $p = '') => 'http://x/wp-admin/' . $p);
    Functions\when('add_query_arg')->alias(fn(array $args, string $url) => $url . '?' . http_build_query($args));
    Functions\when('sanitize_key')->alias(fn(string $k) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)));
    $_GET['tab'] = 'chat';
    ob_start();
    settingsPage(['provider.api_key' => 'sk-real-key', 'provider.base_url' => 'http://ollama:11434/v1', 'models.overrides' => ['m' => ['num_ctx' => 4096]]])->render();
    $html = (string) ob_get_clean();
    unset($_GET['tab']);
    expect($html)->toContain('<!-- sections:' . SettingsPage::SLUG . '-chat -->')
        ->toContain('name="option_page" value="alpaca_bot"')
        ->toContain('action="options.php"')
        ->toContain('<input type="hidden" name="alpaca_bot_settings[provider.base_url]" value="http://ollama:11434/v1">')
        ->toContain('<input type="hidden" name="alpaca_bot_settings[provider.api_key]" value="' . Schema::MASK . '">')
        ->toContain('name="alpaca_bot_settings[models.overrides][m][num_ctx]" value="4096"')
        ->not->toContain('sk-real-key')
        ->not->toContain('name="alpaca_bot_settings[chat.welcome]"')
        ->toContain('nav-tab-active">Chat<');
    expect(preg_match_all('/class="nav-tab( nav-tab-active)?"/', $html))->toBe(count(Schema::sections()));
});

it('falls back to the provider tab for an unknown or missing tab', function (): void {
    Functions\when('settings_errors')->justReturn(null);
    Functions\when('settings_fields')->justReturn(null);
    Functions\when('submit_button')->justReturn(null);
    Functions\when('admin_url')->justReturn('http://x/wp-admin/admin.php');
    Functions\when('add_query_arg')->justReturn('http://x/');
    Functions\when('sanitize_key')->returnArg();
    $pages = [];
    Functions\when('do_settings_sections')->alias(function (string $page) use (&$pages): void { $pages[] = $page; });
    $_GET['tab'] = 'nope"><script>';
    ob_start();
    settingsPage()->render();
    ob_end_clean();
    unset($_GET['tab']);
    ob_start();
    settingsPage()->render();
    $html = (string) ob_get_clean();
    expect($pages)->toBe([SettingsPage::SLUG . '-provider', SettingsPage::SLUG . '-provider'])
        ->and($html)->not->toContain('name="alpaca_bot_settings[provider.base_url]"');
});
