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
        add_action('admin_notices', [$this, 'adminNotices']);

        // Load chat log post type early
        new Api\Htmx();
        new Chat\Post();
    }

    public function addAdminMenu()
    {
        add_menu_page(
            OP_TITLE,
            OP_TITLE,
            'manage_options',
            OP_SLUG,
            [__NAMESPACE__ . '\Chat\Screen', 'outputHTML'],
            OP_DIR_URL . 'assets/img/icon-80.png',
            4
        );

        // Add submenu page to replace the default menu page
        add_submenu_page(
            OP_SLUG,
            'Chat',
            'Chat',
            'manage_options',
            OP_SLUG,
            [__NAMESPACE__ . '\Chat\Screen', 'outputHTML'],
            0
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

    public function adminNotices()
    {
        $notices = [
            'permalinks' => [
                'message' => 'Ollama Press requires pretty permalinks to be enabled. Please enable them in <a href="' . admin_url('options-permalink.php') . '">Settings > Permalinks</a>.',
                'condition' => get_option('permalink_structure') === false or get_option('permalink_structure') === ''
            ],
            'api_url' => [
                'message' => 'Ollama Press requires the <code>OLLAMA_API_URL</code> constant to be defined. Please define it in wp-config.php.',
                'condition' => !defined('OLLAMA_API_URL')
            ],
        ];

        foreach ($notices as $notice) {
            if ($notice['condition']) {
                echo '<div class="notice notice-error is-dismissible"><p>' . $notice['message'] . '</p></div>';
            }
        }
    }
}
