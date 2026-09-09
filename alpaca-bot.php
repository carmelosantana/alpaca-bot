<?php
/*
Plugin Name: Alpaca Bot
Plugin URI: https://github.com/carmelosantana/alpaca-bot
Description: A privately hosted WordPress AI chatbot. Chat with your own models, ground answers in your site, and keep control of cost.
Version: 0.5.0-dev
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
        echo '<div class="notice notice-error"><p>' . esc_html__('Alpaca Bot 0.5 requires PHP 8.4 or newer. The plugin is inactive.', 'alpaca-bot') . '</p></div>';
    });
    return;
}

define('ALPACA_BOT_FILE', __FILE__);
define('ALPACA_BOT_DIR', plugin_dir_path(__FILE__));
define('ALPACA_BOT_URL', plugin_dir_url(__FILE__));

// The plugin's own classes: a PSR-4 loader for AlpacaBot\ over src/, registered here rather
// than by requiring vendor/autoload.php. Composer's autoloader is dev/test only (tests/Pest.php
// loads it): at runtime it would register every third-party namespace unprefixed (php-agents,
// commonmark, symfony, psr) next to the strauss-prefixed copies and eagerly require the vendor
// polyfill bootstraps and function files, on every request, for every other plugin on the site
// to collide with. That is the conflict vendor-prefixed/ exists to prevent.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'AlpacaBot\\') || str_starts_with($class, 'AlpacaBot\\Vendor\\')) {
        return;
    }
    $file = ALPACA_BOT_DIR . 'src/' . strtr(substr($class, strlen('AlpacaBot\\')), '\\', '/') . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Third-party code, prefixed under AlpacaBot\Vendor\ by strauss (composer install builds it on
// a dev checkout; the release zip ships it). This is the only autoloader file the runtime loads.
$alpaca_bot_prefixed = ALPACA_BOT_DIR . 'vendor-prefixed/autoload.php';
if (!is_readable($alpaca_bot_prefixed)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Alpaca Bot: vendor-prefixed/ is missing. Run composer install (dev checkout) or reinstall the release zip.', 'alpaca-bot') . '</p></div>';
    });
    return;
}
require_once $alpaca_bot_prefixed;

\AlpacaBot\Plugin::boot();
register_deactivation_hook(__FILE__, [\AlpacaBot\Plugin::class, 'deactivate']);
