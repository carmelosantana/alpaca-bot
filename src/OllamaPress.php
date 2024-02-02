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

        // Load with plugin
        (new Options())->addActions();
        new Api\Htmx();
        new Chat\Post();
    }

    public function addAdminMenu()
    {
        add_menu_page(
            OP_TITLE,
            OP_TITLE,
            'edit_posts',
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
            'edit_posts',
            OP_SLUG,
            [__NAMESPACE__ . '\Chat\Screen', 'outputHTML'],
            0
        );
    }

    public function adminCheckScreen()
    {
        // check page for ollama-press
        if (strpos($_SERVER['REQUEST_URI'], 'admin.php?page=' . OP_SLUG) === false) {
            return false;
        }

        return true;
    }

    public function adminEnqueueScripts()
    {
        if (!$this->adminCheckScreen()) {
            return;
        }

        wp_enqueue_script('htmx', OP_DIR_URL . 'assets/js/htmx.min.js', [], '1.9.10');
        wp_enqueue_script('htmx-multi-swap', OP_DIR_URL . 'assets/js/multi-swap.js', [], '1');
        wp_enqueue_script(OP_SLUG, OP_DIR_URL . 'assets/js/ollama-press.js', [], OP_VERSION, true);
    }

    public function adminEnqueueStyles()
    {
        wp_enqueue_style(OP_SLUG, OP_DIR_URL . 'assets/css/ollama-press.css', [], OP_VERSION);
        wp_enqueue_style('hint', OP_DIR_URL . 'assets/css/hint.min.css', [], OP_VERSION);
        wp_enqueue_style('materialsymbolsoutlined', OP_DIR_URL . 'assets/css/Material-Symbols-Outlined.css', [], OP_VERSION);
    }

    public function adminNotices()
    {
        $notices = [
            'permalinks' => [
                'message' => 'Ollama Press requires pretty permalinks to be enabled. Please enable them in <a href="' . admin_url('options-permalink.php') . '">Settings > Permalinks</a>.',
                'condition' => get_option('permalink_structure') === false or get_option('permalink_structure') === ''
            ],
            'api_url' => [
                'message' => 'Ollama Press requires an API URL to be set. Please set it in <a href="' . admin_url('admin.php?page=' . OP_SLUG . '-options') . '">Settings > Ollama Press</a>.',
                'condition' => Options::get('api_url') === false
            ],
        ];

        foreach ($notices as $notice) {
            if ($notice['condition']) {
                echo '<div class="notice notice-error is-dismissible"><p>' . $notice['message'] . '</p></div>';
            }
        }
    }
}
