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

it('provider is alpaca_bot_provider_error, 502, with a fixed message: the throwable\'s own text is not for the caller', function (): void {
    // What the provider throws quotes the endpoint (Symfony: 'Could not resolve host: x for
    // "http://x:11434/v1/chat/completions"'), and anyone who may chat can make it throw, so the
    // message never carries it. The code and status are the wire contract and do not move.
    $boom = new RuntimeException('Provider error: Could not resolve host: ollama-gateway.internal for "http://ollama-gateway.internal:11434/v1/chat/completions".');
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
    $e = Errors::provider($boom);
    expect($e->get_error_code())->toBe('alpaca_bot_provider_error')
        ->and($e->get_error_message())->toBe('The model provider could not complete the request.')
        ->and($e->get_error_data())->toBe(['status' => 502]);
});

it('provider hands the throwable\'s text to an administrator as data.detail, and only there', function (): void {
    $boom = new RuntimeException('Provider error: HTTP/1.1 401 Unauthorized returned for "http://127.0.0.1:11434/v1/chat/completions".');
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);
    $e = Errors::provider($boom);
    expect($e->get_error_code())->toBe('alpaca_bot_provider_error')
        ->and($e->get_error_message())->toBe('The model provider could not complete the request.')
        ->and($e->get_error_data())->toBe(['status' => 502, 'detail' => $boom->getMessage()]);
});

it('badRequest is alpaca_bot_bad_request, 400, with the given message', function (): void {
    // The pipeline's InvalidArgumentException messages are already written for the person who
    // sent the request (an image that is not a data URL, a model the catalog does not list, a
    // conversation that is not theirs), so a route passes them through as the 400's message.
    $e = Errors::badRequest('Images must be data URLs.');
    expect($e->get_error_code())->toBe('alpaca_bot_bad_request')
        ->and($e->get_error_message())->toBe('Images must be data URLs.')
        ->and($e->get_error_data())->toBe(['status' => 400]);
});

// The buffered route returns this and the stream route writes it into an `error` frame, so the
// two cannot answer a failed turn differently. Kept as one function because "these two catch
// blocks must match" is prose a build cannot check, and a fourth exception class lands in one
// place.
it('fromPipeline maps what a turn throws: CapExceeded to 402, InvalidArgumentException to 400, anything else to the 502', function (): void {
    Functions\when('current_user_can')->justReturn(false);
    expect(Errors::fromPipeline(new CapExceeded('user', 10, 11))->get_error_data()['status'])->toBe(402);
    expect(Errors::fromPipeline(new InvalidArgumentException('Model "x" is not available.'))->get_error_data()['status'])->toBe(400);
    expect(Errors::fromPipeline(new InvalidArgumentException('Model "x" is not available.'))->get_error_message())->toBe('Model "x" is not available.');
    expect(Errors::fromPipeline(new RuntimeException('Provider error: boom'))->get_error_data()['status'])->toBe(502);
    // An Error is not an Exception; the pipeline's outer catch is on Throwable, and so is this.
    expect(Errors::fromPipeline(new TypeError('nope'))->get_error_code())->toBe('alpaca_bot_provider_error');
    // A subclass of InvalidArgumentException is still the caller's mistake.
    expect(Errors::fromPipeline(new class ('too long') extends InvalidArgumentException {})->get_error_data()['status'])->toBe(400);
});
