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
// A list is one checkbox per option and, ahead of them, a hidden '' under the same `[]` name.
// A checkbox posts only when checked, so with nothing checked the field would be absent from
// the POST and Schema::sanitize() would keep what is stored: the admin could never switch the
// last tool off. The sentinel is the boolean's hidden 0 for a list; Schema::coerce() drops it
// as one more unknown id.
it('renders a checkbox-list as one box per option, checked from the stored list, behind a hidden empty sentinel', function (): void {
    $f = ['type' => 'checkbox-list', 'label' => 'Tools', 'default' => ['a', 'b'], 'options' => ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'description' => 'd'];
    $html = Fields::render('toolkits.enabled', $f, ['a', 'c']);
    expect($html)->toMatch('/^<input type="hidden" name="alpaca_bot_settings\[toolkits\.enabled\]\[\]" value="">/')
        ->toContain('<input type="checkbox" id="ab-toolkits-enabled-a" name="alpaca_bot_settings[toolkits.enabled][]" value="a" checked="checked"> A')
        ->toContain('<input type="checkbox" id="ab-toolkits-enabled-b" name="alpaca_bot_settings[toolkits.enabled][]" value="b"> B')
        ->toContain('value="c" checked="checked"> C')
        ->toContain('<p class="description">d</p>');
    // A stored value that is not a list checks nothing rather than guessing.
    expect(Fields::render('toolkits.enabled', $f, 'a'))->not->toContain('checked');
});

// The carry-over for a list is one hidden input per item under the `[]` name, the way the
// browser would post the checked boxes, so a save from another tab keeps the list as it is.
// An empty list posts nothing, and absent keeps the stored [], which is the same thing.
it('carries a list as one hidden input per item, and nothing for an empty list', function (): void {
    expect(Fields::hidden('toolkits.enabled', ['a', 'b']))->toBe('<input type="hidden" name="alpaca_bot_settings[toolkits.enabled][]" value="a"><input type="hidden" name="alpaca_bot_settings[toolkits.enabled][]" value="b">');
    expect(Fields::hidden('toolkits.enabled', []))->toBe('');
});

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

/**
 * `provider.base_url` rendered with OLLAMA_API_URL set to `$constant`, in a fresh PHP process:
 * the constant is process-wide and the suite never defines it in its own (freshProcess() in
 * Pest.php says why).
 *
 * @param string|null $constant null leaves the constant undefined
 */
function fieldsBaseUrlInFreshProcess(?string $constant): string
{
    $script = <<<'PHP_SCRIPT'
    <?php
    [, $root, $defined, $constant] = $argv;
    require $root . '/vendor/autoload.php';
    function __(string $text, string $domain = 'default'): string { return $text; }
    function esc_html__(string $text, string $domain = 'default'): string { return $text; }
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
    function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
    if ($defined === '1') { define('OLLAMA_API_URL', $constant); }
    echo AlpacaBot\Admin\Fields::render(
        'provider.base_url',
        ['type' => 'string', 'label' => 'Base URL', 'default' => '', 'description' => 'OpenAI-compatible endpoint. For Ollama this ends in /v1.'],
        'http://stored.example:11434/v1',
    );
    PHP_SCRIPT;

    return freshProcess($script, [dirname(__DIR__, 3), $constant === null ? '0' : '1', (string) $constant]);
}

// An admin can point the site at a new gateway, save, and read the new value back in the field
// while every request keeps going to the constant's host: without this note the only symptom is
// that nothing changed. The constant's value is printed because the reader is an administrator,
// who can read wp-config.php anyway.
it('warns under the base URL field when OLLAMA_API_URL overrides it, naming the constant and its value', function (): void {
    $overridden = fieldsBaseUrlInFreshProcess('http://gateway.example:11434/v1');
    expect($overridden)->toContain('value="http://stored.example:11434/v1"')
        ->toContain('<code>OLLAMA_API_URL</code>')
        ->toContain('<code>http://gateway.example:11434/v1</code>')
        ->toContain('This field is saved but ignored')
        // The field's own description is still there; the warning is an extra paragraph.
        ->toContain('For Ollama this ends in /v1.');
});

// Factory::baseUrl() ignores an empty constant, so the warning must too, or a site that defines
// it blank is told its setting does nothing when in fact the setting is what is used.
it('says nothing when OLLAMA_API_URL is undefined or empty, and never on another field', function (): void {
    expect(fieldsBaseUrlInFreshProcess(null))->not->toContain('OLLAMA_API_URL');
    expect(fieldsBaseUrlInFreshProcess(''))->not->toContain('OLLAMA_API_URL');
    expect(fieldsBaseUrlInFreshProcess('   '))->not->toContain('OLLAMA_API_URL');
    expect(Fields::render('provider.timeout', ['type' => 'integer', 'label' => 'T', 'default' => 60], 60))->not->toContain('OLLAMA_API_URL');
});
