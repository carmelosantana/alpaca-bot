<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * A monthly token cap has been reached. `$scope` is 'user' or 'site'; the message is safe to
 * show to the person who sent the request: the user-scope message quotes their own figures,
 * the site-scope message quotes none, because the site's spend is not the requester's to see.
 *
 * `$stopped` says when: false for a turn refused before it started (CapPolicy::assertAllowed()),
 * true for a tool turn stopped between two of its provider calls (CapPolicy::assertMayContinue(),
 * Kanboard #4701), whose message says the reply stopped rather than that the cap is reached.
 * `$conversationId` is the conversation a stopped turn's partial reply was stored on, which
 * Pipeline sets on the way out; 0 until then, and for a refusal, which stores nothing.
 */
final class CapExceeded extends \RuntimeException
{
    public int $conversationId = 0;

    public function __construct(public string $scope, public int $limit, public int $used, public bool $stopped = false)
    {
        parent::__construct(match (true) {
            /* translators: 1: tokens used this month, 2: the monthly cap */
            $scope === 'user' && $stopped => sprintf(__('This reply stopped because your monthly token cap was reached (%1$d of %2$d tokens).', 'alpaca-bot'), $used, $limit),
            /* translators: 1: tokens used this month, 2: the monthly cap */
            $scope === 'user' => sprintf(__('Your monthly token cap has been reached (%1$d of %2$d tokens).', 'alpaca-bot'), $used, $limit),
            $stopped => __('This reply stopped because the site\'s monthly token cap was reached.', 'alpaca-bot'),
            default => __('The site\'s monthly token cap has been reached.', 'alpaca-bot'),
        });
    }
}
