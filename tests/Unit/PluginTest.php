<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use Brain\Monkey\Actions;

it('exposes a version and boots once', function (): void {
    Actions\expectAdded('plugins_loaded')->once();
    $a = Plugin::boot();
    $b = Plugin::boot();
    expect($a)->toBe($b)
        ->and($a->version())->toBe(Plugin::VERSION)
        ->and(Plugin::VERSION)->toMatch('/^1\.0\.0/');
});
