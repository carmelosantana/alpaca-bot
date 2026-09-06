<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use Brain\Monkey\Actions;

it('exposes a version and boots once', function (): void {
    // Mockery only honours a matcher at the top level of an argument, so the
    // [instance, 'register'] callback is pinned with on() rather than type().
    $registerCallback = Mockery::on(
        static fn (mixed $cb): bool => is_array($cb)
            && ($cb[0] ?? null) instanceof Plugin
            && ($cb[1] ?? null) === 'register'
    );
    Actions\expectAdded('plugins_loaded')->once()->with($registerCallback, 9);
    $a = Plugin::boot();
    $b = Plugin::boot();
    expect($a)->toBe($b)
        ->and($a->version())->toBe(Plugin::VERSION)
        ->and(Plugin::VERSION)->toMatch('/^1\.0\.0/');
});
