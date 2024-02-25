<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Utils;

use CarmeloSantana\AlpacaBot\Define;
use CarmeloSantana\AlpacaBot\Utils\Settings;

class Options extends Settings
{
    private string $prefix = AB_SLUG;

    public static function appendPrefix(string $key = '', string $separator = '_')
    {
        return str_replace('-', $separator, AB_SLUG . (!empty($key) ? $separator . $key : ''));
    }

    public static function get(string $key, $default = false)
    {
        $value = get_option(self::appendPrefix($key));

        $value = self::validateValue($value);

        return $value ? $value : $default;
    }


    public static function getDefault(string $key)
    {
        $value = self::get($key);

        return $value ? $value : Define::fields()[$key]['default'] ?? $value;
    }

    public static function getPlaceholder(string $key)
    {
        $value = self::get($key);

        return $value ? $value : Define::fields()[$key]['placeholder'] ?? $value;
    }

    public static function validateValue($value)
    {
        if (is_string($value) and in_array(strtolower($value), ['true', 'false'])) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif (is_string($value) and empty($value)) {
            return false;
        }

        return $value;
    }
}
