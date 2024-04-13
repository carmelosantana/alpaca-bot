<?php

declare(strict_types=1);

namespace AlpacaBot\Utils;

use AlpacaBot\Define;
use AlpacaBot\Utils\Settings;

class Options extends Settings
{
    private string $prefix = ALPACA_BOT;

    public static function appendPrefix(string $key = '', string $separator = '_')
    {
        return str_replace('-', $separator, ALPACA_BOT . (!empty($key) ? $separator . $key : ''));
    }

    public static function get(string $key, $default = false)
    {
        $value = get_option(self::appendPrefix($key));

        $value = self::validateValue($value);

        return $value ? $value : $default;
    }

    public static function getPlaceholder(string $key)
    {
        $value = self::get($key);

        return $value ? $value : Define::fields()[$key]['placeholder'] ?? $value;
    }

    public static function setDefaultOptions()
    {
        if (get_option(self::appendPrefix('version')) === \AlpacaBot\VERSION) {
            return;
        }

        foreach (Define::fields() as $key => $option) {
            if (!get_option(self::appendPrefix($key) and self::validateValue($option['default']))) {
                add_option(self::appendPrefix($key), $option['default'], '', ($option['autoload'] ?? 'no'));
            }
        }

        update_option(self::appendPrefix('version'), \AlpacaBot\VERSION);
    }

    public static function validateValue($value, $default = false)
    {
        if (is_string($value) and in_array(strtolower($value), ['true', 'false'])) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif (is_string($value) and empty($value)) {
            return $default;
        }

        return $value;
    }
}
