<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress;

use CarmeloSantana\OllamaPress\Editor;

class OllamaPress
{
    public function __construct()
    {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
    }

    public function addAdminMenu()
    {
        add_menu_page(
            'Ollama Press',
            'Ollama Press',
            'manage_options',
            'ollama-press',
            [$this, 'renderAdminPage'],
            'dashicons-admin-generic'
        );
    }

    public function init()
    {
        new Editor\Screen();
    }

    public function renderAdminPage()
    {
        echo '<div class="wrap"><h1>Ollama Press</h1></div>';
    }
}
