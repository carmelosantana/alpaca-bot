<?php

declare(strict_types=1);

/*
 * Pins the WP_REST_Request stand-in to core's semantics. These assertions hold against real
 * WordPress too (they are core's documented behaviour), so a test written against the stub
 * cannot pass here and fail inside the harness site's integration run for a reason the stub
 * invented. The trap being guarded: core's third constructor argument is the route's
 * attributes, so `new WP_REST_Request('POST', '/chat', ['message' => 'hi'])` carries no params.
 */

it('does not read parameters from the constructor: the third argument is the route attributes', function (): void {
    $request = new WP_REST_Request('POST', '/alpaca-bot/v1/chat', ['message' => 'hi']);
    expect($request->get_param('message'))->toBeNull()
        ->and($request->has_param('message'))->toBeFalse()
        ->and($request->get_params())->toBe([])
        ->and($request->get_json_params())->toBeNull()
        ->and($request->get_attributes())->toBe(['message' => 'hi']);
});

it('serves set_param() values through get_param(), has_param() and get_params()', function (): void {
    $request = new WP_REST_Request('GET', '/alpaca-bot/v1/conversations');
    $request->set_param('page', 2);
    $request->set_param('empty', null);
    expect($request->get_param('page'))->toBe(2)
        ->and($request->has_param('empty'))->toBeTrue()
        ->and($request->has_param('missing'))->toBeFalse()
        ->and($request->get_params())->toBe(['page' => 2, 'empty' => null]);
});

it('parses a JSON body only when the Content-Type says JSON, and lets it override set_param()', function (): void {
    $request = new WP_REST_Request('POST', '/alpaca-bot/v1/chat');
    $request->set_param('message', 'from-query');
    $request->set_body('{"message":"hi","model":"llama3.2"}');
    expect($request->get_json_params())->toBeNull()->and($request->get_param('message'))->toBe('from-query');

    $request->set_header('Content-Type', 'application/json; charset=utf-8');
    expect($request->get_json_params())->toBe(['message' => 'hi', 'model' => 'llama3.2'])
        ->and($request->get_param('message'))->toBe('hi')
        ->and($request->get_params())->toBe(['message' => 'hi', 'model' => 'llama3.2'])
        ->and($request->get_header('content-type'))->toBe('application/json; charset=utf-8');

    $request->set_body('not json');
    expect($request->get_json_params())->toBeNull();
});
