<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Api\Ollama;
use CarmeloSantana\AlpacaBot\Define;
use CarmeloSantana\AlpacaBot\Utils\Options;

const VERSION = '0.4.11';

class AlpacaBot
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueStyles']);
        add_action('admin_init', [$this, 'buildCache']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_notices', [$this, 'adminNotices']);

        // Setup options
        $this->options();

        // Log
        if (Options::get('log_chat_response')) {
            new Log\Post();
        }

        // Load with plugin
        new Agents();
        new Help();
        new Api\Htmx();
        new Chat\Post();
    }

    public function addAdminMenu()
    {
        add_menu_page(
            AB_TITLE,
            AB_TITLE,
            apply_filters(Options::appendPrefix('menu-capability'), 'edit_posts'),
            AB_SLUG,
            [$this, 'chatScreen'],
            AB_DIR_URL . 'assets/img/icon-80.png',
            4
        );

        // Add submenu page to replace the default menu page
        add_submenu_page(
            AB_SLUG,
            'Chat',
            'Chat',
            apply_filters(Options::appendPrefix('menu-capability'), 'edit_posts'),
            AB_SLUG,
            [$this, 'chatScreen'],
            0
        );
    }

    public function chatScreen()
    {
        (new Chat\Screen())->render();
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

        wp_enqueue_script('htmx', AB_DIR_URL . 'assets/js/htmx.min.js', [], '1.9.10', true);
        wp_enqueue_script('htmx-multi-swap', AB_DIR_URL . 'assets/js/multi-swap.js', [], '1', true);
        wp_enqueue_script(AB_SLUG, AB_DIR_URL . 'assets/js/alpaca-bot.js', [], VERSION, true);
    }

    public function adminEnqueueStyles()
    {
        wp_enqueue_style(AB_SLUG, AB_DIR_URL . 'assets/css/alpaca-bot.css', [], VERSION);
        wp_enqueue_style('hint', AB_DIR_URL . 'assets/css/hint.min.css', [], VERSION);
        wp_enqueue_style('materialsymbolsoutlined', AB_DIR_URL . 'assets/css/Material-Symbols-Outlined.css', [], VERSION);
    }

    public function adminNotices()
    {
        $notices = [
            [
                'message' => 'Alpaca Bot requires pretty permalinks to be enabled. Please enable them in <a href="' . admin_url('options-permalink.php') . '">Settings > Permalinks</a>.',
                'condition' => get_option('permalink_structure') === false or get_option('permalink_structure') === ''
            ],
            [
                'message' => 'Alpaca Bot requires an Ollama API URL to be set. Please set it in <a href="' . admin_url('admin.php?page=' . Options::appendPrefix('settings', '-')) . '">Settings > Alpaca Bot</a>.',
                'condition' => Options::get('api_url') === false
            ]
        ];

        $default = [
            'message' => '',
            'condition' => false,
        ];

        foreach ($notices as $notice) {
            $notice = wp_parse_args($notice, $default);
            if ($notice['condition']) {
                echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses($notice['message'], Options::getAllowedTags()) . '</p></div>';
            }
        }
    }

    public function buildCache()
    {
        // Build model cache
        if (Options::get('api_url') and $this->adminCheckScreen()) {
            (new Ollama())->getModels();
        }
    }

    public function options()
    {
        // Options
        $options = new Options;

        // Set fields
        $options->setFields(Define::fields());
        $options->setSections(Define::sections());

        // Setup menu and page
        $options->setMenuSlug(Options::appendPrefix('settings', '-'));
        $options->setMenuTitle('Settings');
        $options->setPageTitle('Settings');
        $options->setParentSlug(AB_SLUG);
        $options->setPrefix(AB_SLUG);
        $options->addPageWrapClass(AB_SLUG);
        $options->addPageWrapClass(Options::appendPrefix('options', '-'));

        // Register and create options page
        $options->register();
    }
}
