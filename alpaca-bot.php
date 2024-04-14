<?php
/*
Plugin Name: Alpaca Bot
Plugin URI: https://github.com/carmelosantana/alpaca-bot
Description: A privately hosted WordPress AI chatbot.
Version: 0.4.15
Author: Carmelo Santana
Author URI: https://carmelosantana.com/
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.4
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Defines
define('ALPACA_BOT', 'alpaca-bot');
define('ALPACA_BOT_TITLE', 'Alpaca Bot');
define('ALPACA_BOT_DIR_URL', plugin_dir_url(__FILE__));
define('ALPACA_BOT_DIR_PATH', plugin_dir_path(__FILE__));

// Composer
if (!file_exists($composer = plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    // display error message when activating plugin
    trigger_error(
        sprintf(
            /* translators: %s: plugin name */
            esc_html__('Error locating %s autoloader. Please run <code>composer install</code>.', 'alpaca-bot'),
            esc_html__('Alpaca Bot', 'alpaca-bot')
        ),
        E_USER_ERROR
    );
}
require $composer;

add_action('plugins_loaded', function () {
    new \AlpacaBot\AlpacaBot();
}, 9);
