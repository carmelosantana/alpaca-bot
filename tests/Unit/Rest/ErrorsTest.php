<?php

declare(strict_types=1);

use AlpacaBot\Chat\CapExceeded;
use AlpacaBot\Rest\Errors;
use Brain\Monkey\Functions;

/*
 * Every error shape a route can answer with, pinned: the code string a client switches on, the
 * HTTP status core reads from data.status, and the extra data keys a client is told to expect.
 * Nothing here is behaviour; it is the wire contract, which is why each value is spelled out.
 */

it('forbidden is core\'s rest_forbidden: 403 for a logged-in user, 401 for a visitor, with the given message', function (): void {
    Functions\when('is_user_logged_in')->justReturn(true);
    $user = Errors::forbidden();
    expect($user)->toBeInstanceOf(WP_Error::class)
        ->and($user->get_error_code())->toBe('rest_forbidden')
        ->and($user->get_error_message())->toBe('You are not allowed to do that.')
        ->and($user->get_error_data())->toBe(['status' => 403]);

    Functions\when('is_user_logged_in')->justReturn(false);
    $visitor = Errors::forbidden('Editors only.');
    expect($visitor->get_error_code())->toBe('rest_forbidden')
        ->and($visitor->get_error_message())->toBe('Editors only.')
        ->and($visitor->get_error_data())->toBe(['status' => 401]);
});

it('tooMany is alpaca_bot_rate_limited, 429, carrying retry_after in the data', function (): void {
    $e = Errors::tooMany(17);
    expect($e->get_error_code())->toBe('alpaca_bot_rate_limited')
        ->and($e->get_error_message())->toBe('Too many requests. Try again shortly.')
        ->and($e->get_error_data())->toBe(['status' => 429, 'retry_after' => 17]);
});

it('capExceeded is alpaca_bot_cap_exceeded, 402, with the scope, limit and used figures and the exception\'s own message', function (): void {
    $e = Errors::capExceeded(new CapExceeded('user', 1_000, 1_200));
    expect($e->get_error_code())->toBe('alpaca_bot_cap_exceeded')
        ->and($e->get_error_message())->toBe('Your monthly token cap has been reached (1200 of 1000 tokens).')
        ->and($e->get_error_data())->toBe(['status' => 402, 'scope' => 'user', 'limit' => 1_000, 'used' => 1_200]);

    // The site-scope message quotes no figures (the site's spend is not the requester's to see); the data still carries them.
    $site = Errors::capExceeded(new CapExceeded('site', 50_000, 50_001));
    expect($site->get_error_message())->toBe('The site\'s monthly token cap has been reached.')
        ->and($site->get_error_data())->toBe(['status' => 402, 'scope' => 'site', 'limit' => 50_000, 'used' => 50_001]);
});

it('notFound is alpaca_bot_not_found, 404, naming the kind of thing', function (): void {
    $e = Errors::notFound('Conversation');
    expect($e->get_error_code())->toBe('alpaca_bot_not_found')
        ->and($e->get_error_message())->toBe('Conversation not found.')
        ->and($e->get_error_data())->toBe(['status' => 404]);
});

it('provider is alpaca_bot_provider_error, 502, with the throwable\'s message', function (): void {
    $e = Errors::provider(new RuntimeException('connection refused'));
    expect($e->get_error_code())->toBe('alpaca_bot_provider_error')
        ->and($e->get_error_message())->toBe('connection refused')
        ->and($e->get_error_data())->toBe(['status' => 502]);
});
