<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use Brain\Monkey;

uses()->beforeEach(function (): void {
    Monkey\setUp();
    // Plugin is a process-wide singleton and Pest runs the suite in one process:
    // reset it so every test's boot() starts from a cold state.
    $instance = new ReflectionProperty(Plugin::class, 'instance');
    $instance->setValue(null, null);
})->afterEach(function (): void {
    Monkey\tearDown();
    Mockery::close();
})->in('Unit');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('ALPACA_BOT_FILE')) {
    define('ALPACA_BOT_FILE', dirname(__DIR__) . '/alpaca-bot.php');
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor-prefixed/autoload.php';
