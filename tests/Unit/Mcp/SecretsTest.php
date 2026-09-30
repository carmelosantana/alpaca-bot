<?php

declare(strict_types=1);

use AlpacaBot\Mcp\Secrets;
use AlpacaBot\Settings\Schema;
use Brain\Monkey\Functions;

it('reads only string values under string ids, and nothing from an option that is not a map', function (): void {
    Functions\when('get_option')->justReturn(['trk' => 'Bearer t', 'gh' => ['x'], 7 => 'Bearer n']);
    expect(Secrets::all())->toBe(['trk' => 'Bearer t'])
        ->and(Secrets::get('trk'))->toBe('Bearer t')
        ->and(Secrets::get('gh'))->toBe('');
    Functions\when('get_option')->justReturn('nope');
    expect(Secrets::all())->toBe([]);
});

it('writes the map with autoload off, and deletes the option rather than store an empty one', function (): void {
    Functions\expect('update_option')->once()->with(Secrets::OPTION, ['trk' => 'Bearer t'], false)->andReturn(true);
    Functions\expect('delete_option')->once()->with(Secrets::OPTION)->andReturn(true);
    Secrets::put(['trk' => 'Bearer t']);
    Secrets::put([]);
});

// resolve() is how a row's header value is read back by anything that has to send it: the mask
// is swapped for the kept value, '' stays '', and a value the row still carries itself is that
// value (the Store's memo holds the posted row until the request ends).
it('resolves a row\'s mask to the kept value by the row\'s id, and nothing else', function (): void {
    Functions\when('get_option')->justReturn(['trk' => 'Bearer t']);
    expect(Secrets::resolve(['id' => 'trk', 'header_value' => Schema::MASK]))->toBe('Bearer t')
        ->and(Secrets::resolve(['id' => 'gh', 'header_value' => Schema::MASK]))->toBe('')
        ->and(Secrets::resolve(['id' => 'trk', 'header_value' => '']))->toBe('')
        ->and(Secrets::resolve(['id' => 'trk', 'header_value' => 'Bearer new']))->toBe('Bearer new')
        ->and(Secrets::resolve(['id' => 7, 'header_value' => Schema::MASK]))->toBe('')
        ->and(Secrets::resolve(['id' => 'trk', 'header_value' => ['x']]))->toBe('')
        ->and(Secrets::resolve(['id' => 'trk']))->toBe('');
});
