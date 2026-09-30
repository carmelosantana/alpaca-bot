<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * A tool turn that failed in this plugin's own words: its run ended on an Error finish nobody
 * announced and no successful tool result accounts for (Pipeline::failure() says how that is
 * read). Pipeline::send() raises its message as it is. The `Provider error:` prefix says the
 * provider threw, and nothing announced that it did (Kanboard #4327).
 *
 * A RuntimeException, the class every failure of a turn has been: it is what failure() is typed
 * to return, it is what an `alpaca_bot/chat/failed` listener is handed, and it is chained behind
 * the RuntimeException send() raises — which is the one Errors::fromPipeline() maps, a 502 with
 * the message in `data.detail` for administrators. This class is a marker for send()'s own
 * catch, not a new shape for callers.
 */
final class RunFailure extends \RuntimeException {}
