<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * What an attached image is: a base64 data URL whose mime is on the image allowlist, and
 * nothing else. The contract is written once, here, so the two places that enforce it cannot
 * drift apart: Pipeline::images() refuses anything else at intake, and View\Chat\MessageBubble
 * renders nothing else. Before this class the render check was the only one, and a
 * `data:text/html,...` was stored, replayed to the provider on every later turn and never shown
 * to the user. Stateless and final: it is a vocabulary, not a service.
 *
 * `esc_url()` cannot stand in for it: `data:` is not in wp_allowed_protocols, so it strips the
 * URL to nothing. A data URL that passes here is safe to attribute-escape into a src.
 *
 * @since 0.5.0
 */
final class ImageData
{
    /**
     * A base64 image data URL and nothing else: the mime is an allowlist (SVG is an image mime
     * that can carry script, and is not on it), the payload is base64's alphabet with at most
     * two `=` of padding, and `\z` (not `$`, which admits a final newline) holds it to the very
     * end. Two is base64's own ceiling (one byte short of a multiple of three pads with two, two
     * short with one), and the bound is load-bearing: decodedBytes() subtracts each `=`, so the
     * unbounded `=*` this pattern once ended in let a payload padded with thousands of them
     * count for less than it carried, down to 0, and slip under the intake total cap.
     */
    public const PATTERN = '#^data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/]+={0,2}\z#';

    public static function isValid(string $url): bool
    {
        return preg_match(self::PATTERN, $url) === 1;
    }

    /**
     * The decoded size of the payload, in bytes, from the base64 length alone: four characters
     * carry three bytes and each `=` of padding stands for one byte fewer. Not base64_decode():
     * decoding an 8 MB payload to measure it doubles peak memory for nothing. Decoded bytes are
     * the currency Admin\Assets::maxImageBytes() and image.ts's `Blob.size` check speak, so a
     * total summed from here compares against the cap without conversion. 0 for anything that
     * is not a valid image data URL.
     *
     * The padding is clamped to two even though PATTERN already refuses more: the subtraction
     * is only correct for the padding base64 writes, and if the pattern is ever loosened again
     * this stays a count of bytes rather than a figure a crafted payload can drive to 0.
     */
    public static function decodedBytes(string $url): int
    {
        if (!self::isValid($url)) {
            return 0;
        }
        $payload = substr($url, (int) strpos($url, ',') + 1);
        $padding = min(2, strlen($payload) - strlen(rtrim($payload, '=')));
        return max(0, intdiv(strlen($payload), 4) * 3 - $padding);
    }
}
