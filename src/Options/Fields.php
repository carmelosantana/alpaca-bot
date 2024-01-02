<?php

declare(strict_types=1);

namespace CarmeloSantana\PluginTemplate\Options;

use Carbon_Fields\Container;
use Carbon_Fields\Field;

class Fields
{
    public function __construct()
    {
        add_action('carbon_fields_register_fields', [$this, 'metas']);
        add_action('carbon_fields_register_fields', [$this, 'options']);
    }

    public function metas(): void
    {
    }

    public function options(): void
    {
    }
}
