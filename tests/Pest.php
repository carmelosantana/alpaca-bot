<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use Brain\Monkey;
use Brain\Monkey\Functions;

uses()->beforeEach(function (): void {
    Monkey\setUp();
    // Schema labels run through __(); pass the strings through untouched.
    Functions\stubTranslationFunctions();
    // Plugin is a process-wide singleton and Pest runs the suite in one process:
    // reset it so every test's boot() starts from a cold state.
    $instance = new ReflectionProperty(Plugin::class, 'instance');
    $instance->setValue(null, null);
})->afterEach(function (): void {
    // Mockery expectations are the only assertions in some tests; count them
    // before tearDown() closes the container so those tests are not "risky".
    $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
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
