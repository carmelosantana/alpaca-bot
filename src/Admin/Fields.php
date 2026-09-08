<?php

declare(strict_types=1);

namespace AlpacaBot\Admin;

use AlpacaBot\Plugin;
use AlpacaBot\Settings\Schema;

/**
 * Core-styled form controls for one Schema field, and the hidden inputs that carry a field the
 * page is not showing. Everything posts under `alpaca_bot_settings[<dotted.key>]`, so a single
 * `register_setting()` sanitize callback receives the whole option as one array.
 *
 * A secret (Schema::SECRETS) is never written into the page: the control and the hidden carry
 * over both show Schema::MASK when a value is stored and '' when not. Schema::sanitize() reads
 * the mask back as "keep what is stored", so a form saved without touching the key keeps it,
 * and a form saved with the field emptied clears it. The rule is applied here, at the one
 * place a value turns into markup, so no caller can print the key by forgetting to mask it.
 *
 * Every attribute goes through esc_attr(), every text node through esc_html() or
 * esc_textarea(): field labels and descriptions are translatable strings, but a stored value
 * came from a form and may be anything.
 *
 * @phpstan-import-type Field from Schema
 */
final class Fields
{
    /** Multi-line strings get a textarea; every other string is a single line. */
    private const TEXTAREAS = ['chat.system_prompt', 'chat.welcome'];

    public static function name(string $key): string
    {
        return Plugin::OPTION . '[' . $key . ']';
    }

    /** `ab-provider-base-url`: what `label_for` points at. */
    public static function id(string $key): string
    {
        return 'ab-' . str_replace('.', '-', $key);
    }

    /** @param Field $f */
    public static function render(string $key, array $f, mixed $value): string
    {
        $value = self::display($key, $value);
        $name = esc_attr(self::name($key));
        $id = esc_attr(self::id($key));
        $desc = isset($f['description']) ? '<p class="description">' . esc_html((string) $f['description']) . '</p>' : '';
        $desc .= self::override($key);
        switch ($f['type']) {
            case 'boolean':
                // The hidden 0 before the box is what an unchecked box posts; without it the
                // sanitize callback would never hear "off" and the field would keep its default.
                return sprintf(
                    '<input type="hidden" name="%1$s" value="0"><label><input type="checkbox" id="%2$s" name="%1$s" value="1"%3$s> %4$s</label>%5$s',
                    $name,
                    $id,
                    checked((bool) $value, true, false),
                    esc_html((string) $f['label']),
                    $desc,
                );
            case 'select':
                $opts = '';
                foreach ($f['options'] ?? [] as $v => $label) {
                    $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr((string) $v), selected((string) (is_scalar($value) ? $value : ''), (string) $v, false), esc_html((string) $label));
                }
                return sprintf('<select id="%s" name="%s">%s</select>%s', $id, $name, $opts, $desc);
            case 'integer':
            case 'number':
                $bounds = '';
                foreach (['min', 'max'] as $bound) {
                    if (isset($f[$bound])) {
                        $bounds .= sprintf(' %s="%s"', $bound, esc_attr((string) $f[$bound]));
                    }
                }
                return sprintf(
                    '<input type="number" class="small-text" id="%s" name="%s" value="%s" step="%s"%s>%s',
                    $id,
                    $name,
                    esc_attr(self::scalar($value)),
                    $f['type'] === 'integer' ? '1' : '0.1',
                    $bounds,
                    $desc,
                );
            case 'array':
                // A map has no single control; SettingsPage renders `models.overrides` as a table.
                return '';
            case 'checkbox-list':
                // One box per option under the same `[]` name, and ahead of them a hidden ''
                // under that name: a checkbox posts only when checked, so with every box clear
                // the field would be absent from the POST, Schema::sanitize() would keep what
                // is stored, and the last tool could never be switched off. The sentinel is the
                // boolean's hidden 0 for a list; Schema::coerce() drops it as an unknown id.
                // The stored value is checked against as a list; anything else checks nothing.
                $chosen = is_array($value) ? $value : [];
                $boxes = '';
                foreach ($f['options'] ?? [] as $v => $label) {
                    $boxes .= sprintf(
                        '<label><input type="checkbox" id="%s" name="%s[]" value="%s"%s> %s</label><br>',
                        esc_attr(self::id($key) . '-' . (string) $v),
                        $name,
                        esc_attr((string) $v),
                        checked(in_array((string) $v, array_map('strval', array_filter($chosen, 'is_scalar')), true), true, false),
                        esc_html((string) $label),
                    );
                }
                return sprintf(
                    '<input type="hidden" name="%s[]" value=""><fieldset><legend class="screen-reader-text"><span>%s</span></legend>%s</fieldset>%s',
                    $name,
                    esc_html((string) $f['label']),
                    $boxes,
                    $desc,
                );
            default:
                if (in_array($key, self::TEXTAREAS, true)) {
                    return sprintf('<textarea id="%s" name="%s" rows="5" class="large-text code">%s</textarea>%s', $id, $name, esc_textarea(self::scalar($value)), $desc);
                }
                $secret = in_array($key, Schema::SECRETS, true);
                return sprintf(
                    '<input type="%s" class="regular-text" id="%s" name="%s" value="%s"%s>%s',
                    $secret ? 'password' : 'text',
                    $id,
                    $name,
                    esc_attr(self::scalar($value)),
                    $secret ? ' autocomplete="new-password"' : '',
                    $desc,
                );
        }
    }

    /**
     * The hidden inputs that post a field the page is not showing, so a save from one tab
     * carries every other tab's values unchanged: the sanitize callback rebuilds the whole
     * option from what is posted, and a field it does not hear about goes back to its default
     * (the old first-save bug). Booleans post as 0/1, the way the visible checkbox does; a map
     * (`models.overrides`) posts one input per leaf, `[key][model][field]`, and anything that is
     * not a scalar at that depth is dropped rather than printed as "Array"; a list
     * (`toolkits.enabled`) posts one input per item under `[key][]`, the way the checked boxes
     * would, and an empty list posts nothing, which keeps the stored [] just the same.
     */
    public static function hidden(string $key, mixed $value): string
    {
        $value = self::display($key, $value);
        if (is_array($value)) {
            $out = '';
            foreach ($value as $k => $v) {
                if (is_scalar($v) && array_is_list($value)) {
                    $out .= self::hiddenInput(self::name($key) . '[]', self::scalar($v));
                    continue;
                }
                if (!is_array($v)) {
                    continue;
                }
                foreach ($v as $kk => $vv) {
                    if (!is_scalar($vv)) {
                        continue;
                    }
                    $out .= self::hiddenInput(Plugin::OPTION . '[' . $key . '][' . $k . '][' . $kk . ']', self::scalar($vv));
                }
            }
            return $out;
        }
        return self::hiddenInput(self::name($key), self::scalar($value));
    }

    /**
     * The warning under a field whose stored value something outside the settings overrides, or
     * '' when nothing does. Only `provider.base_url` has one: Provider\Factory::baseUrl() prefers
     * a non-empty `OLLAMA_API_URL` constant, so on a site that defines it (wp-config.php,
     * usually) the field still saves and still reads back, and every request goes somewhere
     * else. Without this the only symptom of pointing the site at a new gateway is that nothing
     * changes.
     *
     * The constant's value is printed because this is the admin screen and the reader is an
     * administrator who can read wp-config.php anyway; that is also why it stays here and not in
     * Schema, whose descriptions the REST schema route serves as data.
     */
    private static function override(string $key): string
    {
        if ($key !== 'provider.base_url' || !defined('OLLAMA_API_URL') || trim((string) constant('OLLAMA_API_URL')) === '') {
            return '';
        }
        return '<p class="description"><strong>' . sprintf(
            /* translators: 1: PHP constant name, 2: the URL the constant is set to */
            esc_html__('Overridden: the %1$s constant is set to %2$s, and the provider uses that. This field is saved but ignored until the constant is removed.', 'alpaca-bot'),
            '<code>OLLAMA_API_URL</code>',
            '<code>' . esc_html(trim((string) constant('OLLAMA_API_URL'))) . '</code>',
        ) . '</strong></p>';
    }

    /** What the page shows for a value: the mask for a stored secret, the value for anything else. */
    private static function display(string $key, mixed $value): mixed
    {
        if (in_array($key, Schema::SECRETS, true)) {
            return is_string($value) && $value !== '' ? Schema::MASK : '';
        }
        return $value;
    }

    private static function hiddenInput(string $name, string $value): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s">', esc_attr($name), esc_attr($value));
    }

    /** A value as a form posts it: booleans as 0/1, numbers and strings as they print, anything else as ''. */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return is_scalar($value) ? (string) $value : '';
    }
}
