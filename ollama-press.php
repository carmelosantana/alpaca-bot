<?php
/*
Plugin Name: Ollama Press
Plugin URI: https://github.com/carmelosantana/ollama-press
Description: 🚀 Boost your website with instant AI-powered content creation and coding assistance! 💥
Version: 0.1.0-alpha.2
Author: Carmelo Santana
Author URI: https://carmelosantana.com/
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

// Defines
define('OP', 'ollama-press');
define('OP_TITLE', 'Ollama Press');
define('OP_PLUGIN_FILE', __FILE__);
define('OP_DIR_PATH', plugin_dir_path(__FILE__));
define('OP_DIR_URL', plugin_dir_url(__FILE__));
define('OP_VERSION', '0.1.0-alpha.1');

// Composer
if (!file_exists($composer = plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    // display error message when activating plugin
    trigger_error(
        sprintf(
            /* translators: %s: plugin name */
            __('Error locating %s autoloader. Please run <code>composer install</code>.', OP),
            OP_TITLE
        ),
        E_USER_ERROR
    );
}
require $composer;

new \CarmeloSantana\OllamaPress\OllamaPress();
