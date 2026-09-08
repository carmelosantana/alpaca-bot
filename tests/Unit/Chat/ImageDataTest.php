<?php

declare(strict_types=1);

use AlpacaBot\Chat\ImageData;

// The one contract for an attached image, shared by the intake check (Pipeline::images()) and
// the render check (MessageBubble): a base64 data URL whose mime is on the image allowlist.

it('accepts a base64 PNG, JPEG, GIF or WebP data URL', function (): void {
    foreach (['png', 'jpeg', 'jpg', 'gif', 'webp'] as $mime) {
        expect(ImageData::isValid("data:image/{$mime};base64,iVBORw0KGgo="))->toBeTrue($mime);
    }
    // Padding is optional at the end and nothing but the alphabet is allowed before it.
    expect(ImageData::isValid('data:image/png;base64,AAAA'))->toBeTrue()
        ->and(ImageData::isValid('data:image/png;base64,AA=='))->toBeTrue()
        ->and(ImageData::isValid('data:image/png;base64,A+/='))->toBeTrue();
});

it('rejects anything that is not a base64 image data URL', function (): void {
    expect(ImageData::isValid('data:text/html,x'))->toBeFalse()
        ->and(ImageData::isValid('data:text/html;base64,PHNjcmlwdD4='))->toBeFalse()
        // SVG is an image mime but it can carry script; it is not on the allowlist.
        ->and(ImageData::isValid('data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='))->toBeFalse()
        // A URL-encoded (non-base64) data URL is a data URL but not the one the provider is sent.
        ->and(ImageData::isValid('data:image/png,%89PNG'))->toBeFalse()
        ->and(ImageData::isValid('data:image/png;base64,'))->toBeFalse()
        ->and(ImageData::isValid('data:image/png;base64,abc" onerror="x'))->toBeFalse()
        ->and(ImageData::isValid('https://example.com/x.png'))->toBeFalse()
        ->and(ImageData::isValid('javascript:alert(1)'))->toBeFalse()
        ->and(ImageData::isValid(''))->toBeFalse();
});

// `$` alone matches before a final "\n" (PCRE without the D modifier), so a payload with a
// trailing newline would pass and then land in a src attribute; the pattern ends in \z instead.
it('rejects a trailing newline: \z holds the pattern to the very end where $ would not', function (): void {
    expect(ImageData::isValid("data:image/png;base64,QUJD\n"))->toBeFalse()
        ->and(preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+=*$#', "data:image/png;base64,QUJD\n"))->toBe(1)
        ->and(ImageData::isValid("data:image/png;base64,QUJD\r\n"))->toBeFalse()
        ->and(ImageData::isValid("data:image/png;base64,QUJD "))->toBeFalse();
});

it('measures the decoded payload from the base64 length, for 0, 1 and 2 characters of padding', function (): void {
    // Four base64 characters carry three bytes; each '=' stands for one byte fewer.
    expect(ImageData::decodedBytes('data:image/png;base64,AAAA'))->toBe(3)
        ->and(ImageData::decodedBytes('data:image/png;base64,AAA='))->toBe(2)
        ->and(ImageData::decodedBytes('data:image/png;base64,AA=='))->toBe(1)
        // The PNG magic image.ts's own test encodes: four bytes -> "iVBORw==".
        ->and(ImageData::decodedBytes('data:image/png;base64,iVBORw=='))->toBe(4)
        ->and(ImageData::decodedBytes('data:image/jpeg;base64,' . str_repeat('A', 4 * 1024 * 1024)))->toBe(3 * 1024 * 1024)
        ->and(ImageData::decodedBytes('data:image/jpeg;base64,' . str_repeat('A', 4 * 1024 * 1024 - 2) . '=='))->toBe(3 * 1024 * 1024 - 2);
});

it('measures an invalid URL as 0 bytes', function (): void {
    expect(ImageData::decodedBytes(''))->toBe(0)
        ->and(ImageData::decodedBytes('data:text/html;base64,PHNjcmlwdD4='))->toBe(0)
        ->and(ImageData::decodedBytes('data:image/png,%89PNG'))->toBe(0)
        ->and(ImageData::decodedBytes("data:image/png;base64,QUJD\n"))->toBe(0);
});
