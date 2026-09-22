<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

/**
 * An address a request may not go to, with a message fit to show the person who asked: it names
 * the host and, when a lookup was made, what the host answered with. Thrown by AddressPin.
 *
 * web_fetch is the only caller today and answers with a sentence of its own instead, because the
 * URL was the model's choice and telling the user which private address a name resolved to
 * reports on the site's network rather than on the fetch. A caller whose address came from a
 * person -- an admin typing a server URL into a settings field -- has the opposite need, and
 * this message is written for that one.
 *
 * @since 0.6.0
 */
final class AddressRefused extends \RuntimeException {}
