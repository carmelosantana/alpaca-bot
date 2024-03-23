<?php
/*
Plugin Name: Alpaca Bot
Plugin URI: https://github.com/carmelosantana/alpaca-bot
Description: A privately hosted WordPress AI chatbot.
Version: 0.4.11
Author: Carmelo Santana
Author URI: https://carmelosantana.com/
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.4
*/

// Defines
define('AB_SLUG', 'alpaca-bot');
define('AB_TITLE', 'Alpaca Bot');
define('AB_DIR_URL', plugin_dir_url(__FILE__));
define('AB_DIR_PATH', plugin_dir_path(__FILE__));

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
    new \CarmeloSantana\AlpacaBot\AlpacaBot();
}, 9);
