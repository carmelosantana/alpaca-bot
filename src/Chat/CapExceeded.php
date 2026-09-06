<?php

declare(strict_types=1);

namespace AlpacaBot\Chat;

/**
 * A monthly token cap has been reached. `$scope` is 'user' or 'site'; the message is safe to
 * show to the person who sent the request.
 */
final class CapExceeded extends \RuntimeException
{
    public function __construct(public string $scope, public int $limit, public int $used)
    {
        parent::__construct(sprintf(
            $scope === 'user'
                /* translators: 1: tokens used this month, 2: the monthly cap */
                ? __('Your monthly token cap has been reached (%1$d of %2$d tokens).', 'alpaca-bot')
                /* translators: 1: tokens used this month, 2: the monthly cap */
                : __('The site\'s monthly token cap has been reached (%1$d of %2$d tokens).', 'alpaca-bot'),
            $used,
            $limit,
        ));
    }
}
