<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

/**
 * A tool name the plugin did not choose, made to fit: at most MAX characters of `[A-Za-z0-9_-]`,
 * the first a letter or an underscore.
 *
 * AbilitiesToolkit names its tools after an ability's `namespace/name`, which another plugin
 * chose: core checks it against `/^[a-z0-9-]+\/[a-z0-9-]+$/` and sets no length (WP 7.1
 * class-wp-abilities-registry.php:86).
 *
 * A name that already fits comes back unchanged, so the common case reads as it was written.
 * Anything else is changed *and marked*: characters outside the set become `_`, a first
 * character that is neither a letter nor `_` gets an `_` in front, and the result is cut to 55
 * characters followed by `_` and eight hex characters of the sha256 of the name as it came. The
 * mark is what keeps `repo.search` apart from `repo_search`, which the replacement alone would
 * merge, and it is a hash of the input rather than a counter, so the same name gets the same
 * answer on every turn.
 *
 * fit() is not one-to-one, and cannot be: its answers are at most 64 characters long and its
 * inputs are not. The mark is a hash anyone can compute, so a party that chooses names can choose
 * one whose fitted form is another name's (ToolNameTest has such a pair). A caller that offers
 * tools named through here is the one that has to refuse two tools under one name, as
 * AbilitiesToolkit::collisions() does.
 *
 * @since 0.6.0
 */
final class ToolName
{
    /** The most characters fit() answers with. */
    public const MAX = 64;

    public static function fit(string $raw): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $raw);
        if (preg_match('/^[A-Za-z_]/', $name) !== 1) {
            $name = '_' . $name;
        }
        if ($name === $raw && strlen($name) <= self::MAX) {
            return $name;
        }
        return substr($name, 0, self::MAX - 9) . '_' . substr(hash('sha256', $raw), 0, 8);
    }
}
