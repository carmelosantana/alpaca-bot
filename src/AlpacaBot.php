<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

class AlpacaBot
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
            AB_TITLE,
            AB_TITLE,
            'edit_posts',
            AB_SLUG,
            [__NAMESPACE__ . '\Chat\Screen', 'outputHTML'],
            AB_DIR_URL . 'assets/img/icon-80.png',
            4
        );

        // Add submenu page to replace the default menu page
        add_submenu_page(
            AB_SLUG,
            'Chat',
            'Chat',
            'edit_posts',
            AB_SLUG,
            [__NAMESPACE__ . '\Chat\Screen', 'outputHTML'],
            0
        );
    }

    public function adminCheckScreen()
    {
        // check page for alpaca-bot
        if (strpos($_SERVER['REQUEST_URI'], 'admin.php?page=' . AB_SLUG) === false) {
            return false;
        }

        return true;
    }

    public function adminEnqueueScripts()
    {
        if (!$this->adminCheckScreen()) {
            return;
        }

        wp_enqueue_script('htmx', AB_DIR_URL . 'assets/js/htmx.min.js', [], '1.9.10');
        wp_enqueue_script('htmx-multi-swap', AB_DIR_URL . 'assets/js/multi-swap.js', [], '1');
        wp_enqueue_script(AB_SLUG, AB_DIR_URL . 'assets/js/alpaca-bot.js', [], AB_VERSION, true);
    }

    public function adminEnqueueStyles()
    {
        wp_enqueue_style(AB_SLUG, AB_DIR_URL . 'assets/css/alpaca-bot.css', [], AB_VERSION);
        wp_enqueue_style('hint', AB_DIR_URL . 'assets/css/hint.min.css', [], AB_VERSION);
        wp_enqueue_style('materialsymbolsoutlined', AB_DIR_URL . 'assets/css/Material-Symbols-Outlined.css', [], AB_VERSION);
    }

    public function adminNotices()
    {
        $notices = [
            'permalinks' => [
                'message' => 'Alpaca Bot requires pretty permalinks to be enabled. Please enable them in <a href="' . admin_url('options-permalink.php') . '">Settings > Permalinks</a>.',
                'condition' => get_option('permalink_structure') === false or get_option('permalink_structure') === ''
            ],
            'api_url' => [
                'message' => 'Alpaca Bot requires an API URL to be set. Please set it in <a href="' . admin_url('admin.php?page=' . AB_SLUG . '-options') . '">Settings > Alpaca Bot</a>.',
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
