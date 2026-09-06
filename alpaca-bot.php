<?php
/*
Plugin Name: Alpaca Bot
Plugin URI: https://github.com/carmelosantana/alpaca-bot
Description: A privately hosted WordPress AI chatbot. Chat with your own models, ground answers in your site, and keep control of cost.
Version: 1.0.0-dev
Author: Carmelo Santana
Author URI: https://carmelosantana.com/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: alpaca-bot
Requires at least: 6.9
Requires PHP: 8.4
*/

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (PHP_VERSION_ID < 80400) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Alpaca Bot 1.0 requires PHP 8.4 or newer. The plugin is inactive.', 'alpaca-bot') . '</p></div>';
    });
    return;
}

define('ALPACA_BOT_FILE', __FILE__);
define('ALPACA_BOT_DIR', plugin_dir_path(__FILE__));
define('ALPACA_BOT_URL', plugin_dir_url(__FILE__));

$autoload = ALPACA_BOT_DIR . 'vendor-prefixed/autoload.php';
$psr4 = ALPACA_BOT_DIR . 'vendor/autoload.php';
if (!is_readable($autoload) || !is_readable($psr4)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Alpaca Bot: run composer install (dev checkout) or reinstall the release zip.', 'alpaca-bot') . '</p></div>';
    });
    return;
}
require_once $psr4;
require_once $autoload;

\AlpacaBot\Plugin::boot();
