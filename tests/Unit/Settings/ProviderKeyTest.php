<?php

declare(strict_types=1);

use AlpacaBot\Plugin;
use AlpacaBot\Settings\ProviderKey;
use AlpacaBot\Settings\Schema;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// The lift runs on pre_update_option_alpaca_bot_settings, which core applies to every
// update_option() of the option before the add_option() branch a site's first save takes. What
// core does with it (the autoload column, alloptions, the migration on init) is asserted against a
// real WordPress in tests/Integration/ProviderKeyTest.php.

/** get_option(), update_option() and delete_option() over `$stored`, with the autoload each write was handed kept as `__autoload__<name>`. */
function providerKeyIn(array &$stored): void
{
    Functions\when('get_option')->alias(static function (string $name, mixed $default = false) use (&$stored): mixed {
        return $stored[$name] ?? $default;
    });
    Functions\when('update_option')->alias(static function (string $name, mixed $value, mixed $autoload = null) use (&$stored): bool {
        $stored[$name] = $value;
        $stored['__autoload__' . $name] = $autoload;
        $stored['__writes__'] = ($stored['__writes__'] ?? 0) + 1;
        return true;
    });
    Functions\when('delete_option')->alias(static function (string $name) use (&$stored): bool {
        unset($stored[$name]);
        $stored['__writes__'] = ($stored['__writes__'] ?? 0) + 1;
        return true;
    });
}

it('hooks the lift onto every write of the settings option, with the old value', function (): void {
    $key = new ProviderKey();
    Filters\expectAdded('pre_update_option_' . Plugin::OPTION)->once()->with([$key, 'beforeSave'], 10, 2);
    $key->register();
});

it('moves a new key into its own option, autoload off, and leaves the mask in the row', function (): void {
    $stored = [];
    providerKeyIn($stored);
    $value = (new ProviderKey())->beforeSave(['provider.api_key' => 'sk-FAKE-new', 'models.num_ctx' => 1024], []);
    expect($value)->toBe(['provider.api_key' => Schema::MASK, 'models.num_ctx' => 1024])
        ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-new')
        ->and($stored['__autoload__' . ProviderKey::OPTION])->toBeFalse();
});

it('keeps the held key for the mask or a non-string, deletes it for an empty or absent key, and replaces it for another string', function (): void {
    $stored = [ProviderKey::OPTION => 'sk-FAKE-held'];
    providerKeyIn($stored);
    $lift = new ProviderKey();
    foreach ([Schema::MASK, null, ['x']] as $keep) {
        expect($lift->beforeSave(['provider.api_key' => $keep], ['provider.api_key' => Schema::MASK])['provider.api_key'])->toBe(Schema::MASK)
            ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-held');
    }
    // Nothing was written for a kept key: the option changes only when the key does.
    expect($stored['__writes__'] ?? 0)->toBe(0);
    expect($lift->beforeSave(['provider.api_key' => 'sk-FAKE-other'], [])['provider.api_key'])->toBe(Schema::MASK)
        ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-other');
    expect($lift->beforeSave(['provider.api_key' => ''], [])['provider.api_key'])->toBe('')
        ->and($stored)->not->toHaveKey(ProviderKey::OPTION);
    $stored[ProviderKey::OPTION] = 'sk-FAKE-held';
    // A row without the key says there is none (Store merges the '' default over it).
    expect($lift->beforeSave(['models.num_ctx' => 1024], [])['provider.api_key'])->toBe('')
        ->and($stored)->not->toHaveKey(ProviderKey::OPTION);
});

it('writes the mask only when a key is held, so the mask over nothing is nothing', function (): void {
    $stored = [];
    providerKeyIn($stored);
    expect((new ProviderKey())->beforeSave(['provider.api_key' => Schema::MASK], ['provider.api_key' => ''])['provider.api_key'])->toBe('')
        ->and($stored)->not->toHaveKey(ProviderKey::OPTION);
});

// A raw update_option() of the mask over a row that still carries its plaintext key,
// before the migration has run: the key it keeps is that plaintext, lifted, not nothing.
it('keeps a plaintext key the old row still carries when the mask is written over it', function (): void {
    $stored = [];
    providerKeyIn($stored);
    $value = (new ProviderKey())->beforeSave(['provider.api_key' => Schema::MASK], ['provider.api_key' => 'sk-FAKE-plain']);
    expect($value['provider.api_key'])->toBe(Schema::MASK)
        ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-plain');
});

it('hands back a value that is not an array as it came, and writes nothing', function (): void {
    $stored = [ProviderKey::OPTION => 'sk-FAKE-held'];
    providerKeyIn($stored);
    expect((new ProviderKey())->beforeSave('nope', []))->toBe('nope')
        ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-held')
        ->and($stored['__writes__'] ?? 0)->toBe(0);
});

it('resolves the mask to the held key, and a plaintext key a row still carries to itself', function (): void {
    $stored = [ProviderKey::OPTION => 'sk-FAKE-held'];
    providerKeyIn($stored);
    expect(ProviderKey::resolve(Schema::MASK))->toBe('sk-FAKE-held')
        ->and(ProviderKey::resolve('sk-FAKE-plain'))->toBe('sk-FAKE-plain')
        ->and(ProviderKey::resolve(''))->toBe('')
        ->and(ProviderKey::resolve(null))->toBe('')
        ->and(ProviderKey::resolve(['x']))->toBe('');
    unset($stored[ProviderKey::OPTION]);
    expect(ProviderKey::resolve(Schema::MASK))->toBe('');
});

// #4699: what a screen shows follows what is sent. The option is read only for MASK, so a row
// with no key, or one still carrying its key in plaintext, costs no read.
it('shows the mask only when a key resolves, and reads the option only for the mask', function (): void {
    $reads = 0;
    Functions\when('get_option')->alias(static function (string $name, mixed $default = false) use (&$reads): mixed {
        ++$reads;
        return $name === ProviderKey::OPTION ? 'sk-FAKE-held' : $default;
    });
    expect(ProviderKey::shown(''))->toBe('')
        ->and(ProviderKey::shown(null))->toBe('')
        ->and(ProviderKey::shown('sk-FAKE-plain'))->toBe(Schema::MASK)
        ->and($reads)->toBe(0)
        ->and(ProviderKey::shown(Schema::MASK))->toBe(Schema::MASK)
        ->and($reads)->toBe(1);
    Functions\when('get_option')->justReturn('');
    expect(ProviderKey::shown(Schema::MASK))->toBe('');
});

// #4699 over #4539: a move clears a key only when there was one to clear. The option is read only
// on a move over a row that says MASK.
it('names a key cleared by a move only when the row\'s key resolves to one', function (): void {
    $reads = 0;
    $held = 'sk-FAKE-held';
    Functions\when('get_option')->alias(static function (string $name, mixed $default = false) use (&$reads, &$held): mixed {
        ++$reads;
        return $name === ProviderKey::OPTION ? $held : $default;
    });
    $move = ['provider.base_url' => 'https://steal.example.net/v1'];
    $stay = ['provider.base_url' => 'https://openrouter.ai/v2'];
    $row = static fn(string $key): array => ['provider.api_key' => $key, 'provider.base_url' => 'https://openrouter.ai/api/v1'];
    expect(ProviderKey::clearedByMove($stay, $row(Schema::MASK)))->toBeFalse()
        ->and(ProviderKey::clearedByMove($move, $row('sk-FAKE-plain')))->toBeTrue()
        ->and(ProviderKey::clearedByMove($move, $row('')))->toBeFalse()
        ->and($reads)->toBe(0)
        ->and(ProviderKey::clearedByMove($move, $row(Schema::MASK)))->toBeTrue()
        ->and($reads)->toBe(1);
    $held = '';
    expect(ProviderKey::clearedByMove($move, $row(Schema::MASK)))->toBeFalse()
        // Schema's own still says the move clears the MASK, and sanitize() stores '' for it.
        ->and(Schema::providerKeyClearedByMove($move, $row(Schema::MASK)))->toBeTrue();
});

it('reads a held value that is not a string, or is the mask, as no key, so resolve() never answers the mask', function (): void {
    $stored = [ProviderKey::OPTION => ['x']];
    providerKeyIn($stored);
    expect(ProviderKey::held())->toBe('');
    $stored[ProviderKey::OPTION] = Schema::MASK;
    expect(ProviderKey::held())->toBe('')
        ->and(ProviderKey::resolve(Schema::MASK))->toBe('');
});

// The row is rewritten only once the option holds the key, so a failed write of either leaves the
// plaintext where resolve() still reads it, and the next run moves it again.
it('leaves the row pending when the key option cannot be written, rather than lose the key', function (): void {
    $stored = [Plugin::OPTION => ['provider.api_key' => 'sk-FAKE-plain']];
    providerKeyIn($stored);
    Functions\when('update_option')->alias(static function (string $name, mixed $value, mixed $autoload = null) use (&$stored): bool {
        if ($name === ProviderKey::OPTION) {
            return false;
        }
        $stored[$name] = $value;
        return true;
    });
    ProviderKey::migrate();
    expect($stored[Plugin::OPTION]['provider.api_key'])->toBe('sk-FAKE-plain')
        ->and($stored)->not->toHaveKey(ProviderKey::OPTION);
});

it('leaves the row pending when its write fails, and moves it on the next run', function (): void {
    $stored = [Plugin::OPTION => ['provider.api_key' => 'sk-FAKE-plain']];
    providerKeyIn($stored);
    $fail = true;
    Functions\when('update_option')->alias(static function (string $name, mixed $value, mixed $autoload = null) use (&$stored, &$fail): bool {
        if ($name === Plugin::OPTION && $fail) {
            return false;
        }
        $stored[$name] = $value;
        return true;
    });
    ProviderKey::migrate();
    expect($stored[ProviderKey::OPTION])->toBe('sk-FAKE-plain')
        ->and(ProviderKey::pending($stored[Plugin::OPTION]))->toBeTrue()
        ->and(ProviderKey::resolve($stored[Plugin::OPTION]['provider.api_key']))->toBe('sk-FAKE-plain');
    $fail = false;
    ProviderKey::migrate();
    expect($stored[Plugin::OPTION]['provider.api_key'])->toBe(Schema::MASK)
        ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-plain');
});

it('calls a row pending only when it carries a plaintext key', function (): void {
    expect(ProviderKey::pending(['provider.api_key' => 'sk-FAKE-plain']))->toBeTrue()
        ->and(ProviderKey::pending(['provider.api_key' => Schema::MASK]))->toBeFalse()
        ->and(ProviderKey::pending(['provider.api_key' => '']))->toBeFalse()
        ->and(ProviderKey::pending(['provider.api_key' => 7]))->toBeFalse()
        ->and(ProviderKey::pending([]))->toBeFalse()
        ->and(ProviderKey::pending(false))->toBeFalse();
});

it('migrates a plaintext key out of the row once, and a second run writes nothing', function (): void {
    $stored = [Plugin::OPTION => ['provider.api_key' => 'sk-FAKE-plain', 'models.num_ctx' => 1024]];
    providerKeyIn($stored);
    ProviderKey::migrate();
    expect($stored[Plugin::OPTION])->toBe(['provider.api_key' => Schema::MASK, 'models.num_ctx' => 1024])
        ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-plain')
        ->and($stored['__autoload__' . ProviderKey::OPTION])->toBeFalse();
    $writes = $stored['__writes__'];
    ProviderKey::migrate();
    expect($stored['__writes__'])->toBe($writes)
        ->and($stored[Plugin::OPTION]['provider.api_key'])->toBe(Schema::MASK)
        ->and($stored[ProviderKey::OPTION])->toBe('sk-FAKE-plain');
});

it('migrates nothing when there is no row or no key', function (): void {
    $stored = [];
    providerKeyIn($stored);
    ProviderKey::migrate();
    $stored[Plugin::OPTION] = ['provider.api_key' => ''];
    ProviderKey::migrate();
    expect($stored['__writes__'] ?? 0)->toBe(0);
});
