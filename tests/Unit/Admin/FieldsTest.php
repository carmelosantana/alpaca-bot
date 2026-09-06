<?php

declare(strict_types=1);

use AlpacaBot\Admin\Fields;
use AlpacaBot\Settings\Schema;
use Brain\Monkey\Functions;

// esc_attr()/esc_html()/esc_textarea() pass through (Pest.php stubs them); checked() and
// selected() are core template helpers Brain Monkey does not stub.
beforeEach(function (): void {
    Functions\when('checked')->alias(fn($a, $b = true, $echo = true) => $a == $b ? ' checked="checked"' : '');
    Functions\when('selected')->alias(fn($a, $b = true, $echo = true) => $a == $b ? ' selected="selected"' : '');
});

it('renders each type with the option array name', function (): void {
    expect(Fields::render('provider.base_url', ['type' => 'string', 'label' => 'Base URL', 'default' => ''], 'http://x'))->toContain('name="alpaca_bot_settings[provider.base_url]"')->toContain('value="http://x"')->toContain('class="regular-text"');
    expect(Fields::render('chat.spellcheck', ['type' => 'boolean', 'label' => 'S', 'default' => true], true))->toContain('type="checkbox"')->toContain('checked="checked"');
    expect(Fields::render('provider.kind', ['type' => 'select', 'label' => 'P', 'default' => 'ollama', 'options' => ['ollama' => 'Ollama', 'wp-ai' => 'WP']], 'wp-ai'))->toContain('<option value="wp-ai" selected="selected">');
    expect(Fields::render('models.temperature', ['type' => 'number', 'label' => 'T', 'default' => 0.7, 'min' => 0, 'max' => 2], 0.7))->toContain('type="number"')->toContain('step="0.1"')->toContain('max="2"');
    expect(Fields::render('chat.system_prompt', ['type' => 'string', 'label' => 'S', 'default' => '', 'description' => 'd'], 'x'))->toContain('<textarea')->toContain('class="description"');
});

it('renders an integer with step 1 and leaves min/max out when the field has none', function (): void {
    $html = Fields::render('provider.timeout', ['type' => 'integer', 'label' => 'T', 'default' => 60, 'min' => 5, 'max' => 600], 60);
    expect($html)->toContain('step="1"')->toContain('min="5"')->toContain('max="600"');
    $bare = Fields::render('x.y', ['type' => 'integer', 'label' => 'T', 'default' => 1], 1);
    expect($bare)->not->toContain('min=')->not->toContain('max=');
});

// An unchecked checkbox is absent from the POST; the hidden 0 in front of it is what makes
// "off" reach the sanitize callback at all instead of the field silently keeping its default.
it('posts 0 for an unchecked checkbox through a hidden input placed before it', function (): void {
    $html = Fields::render('chat.spellcheck', ['type' => 'boolean', 'label' => 'S', 'default' => true], false);
    expect($html)->toMatch('/<input type="hidden" name="alpaca_bot_settings\[chat\.spellcheck\]" value="0">.*<input type="checkbox"/s')
        ->and($html)->not->toContain('checked="checked"');
});

// The stored key must never reach the page HTML: the password control shows the mask, which
// Schema::sanitize() reads as "keep what is stored" when it comes back untouched.
it('renders the mask, never the stored value, for a secret and nothing when none is stored', function (): void {
    $f = Schema::fields()['provider.api_key'];
    $html = Fields::render('provider.api_key', $f, 'sk-real-key');
    expect($html)->toContain('type="password"')->toContain('value="' . Schema::MASK . '"')->not->toContain('sk-real-key')->toContain('autocomplete="new-password"');
    expect(Fields::render('provider.api_key', $f, ''))->toContain('value=""');
});

it('runs every attribute and text node through the escapers', function (): void {
    Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    Functions\when('esc_textarea')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    $evil = '"><script>alert(1)</script>';
    expect(Fields::render('models.default', ['type' => 'string', 'label' => $evil, 'default' => '', 'description' => $evil], $evil))->not->toContain('<script>');
    expect(Fields::render('chat.welcome', ['type' => 'string', 'label' => 'W', 'default' => ''], '</textarea>' . $evil))->not->toContain('</textarea><');
    expect(Fields::render('provider.kind', ['type' => 'select', 'label' => 'P', 'default' => 'a', 'options' => [$evil => $evil]], 'a'))->not->toContain('<script>');
    expect(Fields::hidden('models.default', $evil))->not->toContain('<script>');
});

// Every field on a tab that is not shown still has to be posted, or the sanitize callback
// (which rebuilds the whole option) would reset it to its default: the old first-save bug.
it('carries a field as a hidden input: booleans as 0/1, arrays nested, secrets masked, non-scalars dropped', function (): void {
    expect(Fields::hidden('models.temperature', 0.7))->toBe('<input type="hidden" name="alpaca_bot_settings[models.temperature]" value="0.7">');
    expect(Fields::hidden('chat.spellcheck', true))->toContain('value="1"');
    expect(Fields::hidden('chat.spellcheck', false))->toContain('value="0"');
    expect(Fields::hidden('provider.api_key', 'sk-real-key'))->toContain('value="' . Schema::MASK . '"')->not->toContain('sk-real-key');
    expect(Fields::hidden('provider.api_key', ''))->toContain('value=""');
    $over = Fields::hidden('models.overrides', ['llama3' => ['temperature' => 0.2, 'system' => 'be brief', 'junk' => ['x']], 'bad' => 'scalar']);
    expect($over)->toContain('name="alpaca_bot_settings[models.overrides][llama3][temperature]" value="0.2"')
        ->toContain('name="alpaca_bot_settings[models.overrides][llama3][system]" value="be brief"')
        ->not->toContain('[junk]')
        ->not->toContain('[bad]');
    expect(Fields::hidden('models.overrides', []))->toBe('');
});
