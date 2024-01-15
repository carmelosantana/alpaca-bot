<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress;

class Options
{
    public static function get(string $key, $default = null)
    {
        $options = get_option('ollama_press_options');
        return $options[$key] ?? $default;
    }
}
