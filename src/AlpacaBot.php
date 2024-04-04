<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Api\Ollama;
use CarmeloSantana\AlpacaBot\Define;
use CarmeloSantana\AlpacaBot\Utils\Options;

const VERSION = '0.4.12';

class AlpacaBot
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueStyles']);
        add_action('admin_init', [$this, 'adminInit']);
        add_action('admin_menu', [$this, 'adminAddMenu']);
        add_action('admin_notices', [$this, 'adminNotices']);
        add_action('init', [$this, 'init']);

        // Setup options
        $this->options();

        // Log
        if (Options::get('chat_response_log')) {
            new Log\Post();
        }

        // Load with plugin
        new Agents();
        new Api\Htmx();
        new Chat\Post();
    }

    public function adminAddMenu()
    {
        add_menu_page(
            ALPACA_BOT_TITLE,
            ALPACA_BOT_TITLE,
            apply_filters(Options::appendPrefix('menu-capability'), 'edit_posts'),
            ALPACA_BOT,
            [$this, 'chatScreen'],
            ALPACA_BOT_DIR_URL . 'assets/img/icon-80.png',
            4
        );

        // Add submenu page to replace the default menu page
        add_submenu_page(
            ALPACA_BOT,
            'Chat',
            'Chat',
            apply_filters(Options::appendPrefix('menu-capability'), 'edit_posts'),
            ALPACA_BOT,
            [$this, 'chatScreen'],
            0
        );
    }

    public function adminCheckScreen()
    {
        // check page for alpaca-bot
        if (strpos($_SERVER['REQUEST_URI'], 'admin.php?page=' . ALPACA_BOT) === false) {
            return false;
        }

        return true;
    }

    public function adminEnqueueScripts()
    {
        if (!$this->adminCheckScreen()) {
            return;
        }
        wp_enqueue_script('htmx', ALPACA_BOT_DIR_URL . 'assets/js/htmx.min.js', [], '1.9.10', true);
        wp_enqueue_script('htmx-multi-swap', ALPACA_BOT_DIR_URL . 'assets/js/multi-swap.js', [], '1', true);
        wp_enqueue_script('prism', ALPACA_BOT_DIR_URL . 'assets/js/prism.min.js', [], '1.29.0', true);
        wp_enqueue_script(ALPACA_BOT, ALPACA_BOT_DIR_URL . 'assets/js/alpaca-bot.js', [], VERSION, true);
    }

    public function adminEnqueueStyles()
    {
        wp_enqueue_style('hint', ALPACA_BOT_DIR_URL . 'assets/css/hint.min.css', [], VERSION);
        wp_enqueue_style('materialsymbolsoutlined', ALPACA_BOT_DIR_URL . 'assets/css/materialsymbolsoutlined.css', [], VERSION);
        wp_enqueue_style('prism', ALPACA_BOT_DIR_URL . 'assets/css/prism-coy.min.css', [], '1.29.0');
        wp_enqueue_style(ALPACA_BOT, ALPACA_BOT_DIR_URL . 'assets/css/alpaca-bot.css', [], VERSION);
    }

    public function adminInit()
    {
        new Help();
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

    public function chatScreen()
    {
        (new Chat\Screen())->render();
    }

    public function init()
    {
        // Set default options
        Options::setDefaultOptions();

        // Build cache
        $this->buildCache();
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
        $options->setParentSlug(ALPACA_BOT);
        $options->setPrefix(ALPACA_BOT);
        $options->addPageWrapClass(ALPACA_BOT);
        $options->addPageWrapClass(Options::appendPrefix('options', '-'));

        // Register and create options page
        $options->register();
    }
}
