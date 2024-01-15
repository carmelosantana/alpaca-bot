<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress;

class OllamaPress
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueStyles']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function addAdminMenu()
    {
        add_menu_page(
            OP_TITLE,
            OP_TITLE,
            'manage_options',
            OP_SLUG,
            [$this, 'chat'],
            OP_DIR_URL . 'assets/img/icon-80.png',
            4
        );
    }

    public function adminEnqueueScripts()
    {
        wp_enqueue_script('htmx', OP_DIR_URL . 'assets/js/htmx.min.js', [], '1.9.10');
        wp_enqueue_script('htmx-multi-swap', OP_DIR_URL . 'assets/js/multi-swap.js', [], '1');
        wp_enqueue_script(OP_SLUG, OP_DIR_URL . 'assets/js/ollama-press.js', [], OP_VERSION, true);
    }

    public function adminEnqueueStyles()
    {
        wp_enqueue_style(OP_SLUG, OP_DIR_URL . 'assets/css/ollama-press.css', [], OP_VERSION);
        wp_enqueue_style('materialsymbolsrounded', OP_DIR_URL . 'assets/css/Material-Symbols-Outlined.css', [], OP_VERSION);
    }

    public function chat()
    {
        new Chat\Screen();
    }

    public function init()
    {
        new Api\Htmx();
        new Chat\Post();
        new Editor\Screen();
    }
}
