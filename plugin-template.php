<?php
/*
Plugin Name: Plugin Template
Plugin URI: https://github.com/carmelosantana/plugin-template-composer
Description: Plugin template with composer support.
Version: 0.1.0
Author: Carmelo Santana
Author URI: https://carmelosantana.com/
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

// Defines
define('PLUGIN_TEMPLATE', 'plugin-template');
define('PLUGIN_TEMPLATE_TITLE', 'Plugin Template');
define('PLUGIN_TEMPLATE_FILE_PATH', __FILE__);
define('PLUGIN_TEMPLATE_DIR_PATH', plugin_dir_path(__FILE__));
define('PLUGIN_TEMPLATE_DIR_URL', plugin_dir_url(__FILE__));

// Composer
if (!file_exists($composer = plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    trigger_errorw(
        sprintf(
            /* translators: %s: plugin name */
            __('Error locating %s autoloader. Please run <code>composer install</code>.', PLUGIN_TEMPLATE),
            PLUGIN_TEMPLATE_TITLE
        ),
        E_USER_ERROR
    );
}
require $composer;

new \CarmeloSantana\PluginTemplate\PluginTemplate();
