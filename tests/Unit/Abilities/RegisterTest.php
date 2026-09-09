<?php

declare(strict_types=1);

use AlpacaBot\Abilities\Register;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// The Register runs over pipelineWith() (tests/Pest.php): the real pipeline with WordPress
// stubbed and the provider handed in, so the chat and summarize abilities are seen to reach it,
// or seen not to. user_can() is the capability map, stubbed per test to the one user the
// harness acts as; a test about a refusal gives that user nothing.

/** The capability map: user 3 holds exactly `$caps`; anyone else holds nothing. */
function abilitiesCaps(array $caps): void
{
    Functions\when('user_can')->alias(static fn(int $user, string $cap): bool => $user === 3 && in_array($cap, $caps, true));
}

function abilitiesFakeReply(string $reply = 'fake reply', ?array &$call = null): object
{
    return pipelineWith(pipelineProvider([
        new Response($reply, ProviderFinishReason::Stop),
        new Response('', ProviderFinishReason::Stop, usage: new Usage(3, 2, 5)),
    ], $call));
}

it('defines the three abilities, in order, with the shapes a client is told to expect', function (): void {
    $defs = abilitiesRegister(pipelineWith(null))->definitions();
    expect(array_keys($defs))->toBe(['alpaca-bot/chat', 'alpaca-bot/summarize', 'alpaca-bot/draft-post']);
    foreach ($defs as $id => $def) {
        expect($def['label'])->toBeString()->not->toBe('')
            ->and($def['description'])->toBeString()->not->toBe('')
            ->and($def['category'])->toBe('alpaca-bot')
            ->and($def['execute_callback'])->toBeInstanceOf(Closure::class)
            ->and($def['permission_callback'])->toBeInstanceOf(Closure::class)
            ->and($def['input_schema']['type'])->toBe('object')
            // Nothing a client sends besides the named keys reaches a callback: an unknown key is a 400, not ignored.
            ->and($def['input_schema']['additionalProperties'])->toBeFalse()
            ->and($def['output_schema']['type'])->toBe('object')
            ->and($def['meta'])->toBe(['show_in_rest' => true, 'public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]], $id);
    }
    $chat = $defs['alpaca-bot/chat']['input_schema'];
    expect(array_keys($chat['properties']))->toBe(['message', 'conversation_id', 'model'])
        ->and($chat['required'])->toBe(['message'])
        ->and($chat['properties']['message'])->toMatchArray(['type' => 'string', 'minLength' => 1])
        ->and($chat['properties']['conversation_id'])->toMatchArray(['type' => 'integer', 'minimum' => 0])
        ->and($chat['properties']['model']['type'])->toBe('string')
        ->and(array_keys($defs['alpaca-bot/chat']['output_schema']['properties']))->toBe(['conversation_id', 'reply', 'receipt'])
        ->and($defs['alpaca-bot/chat']['output_schema']['required'])->toBe(['conversation_id', 'reply', 'receipt']);
    $summarize = $defs['alpaca-bot/summarize']['input_schema'];
    expect(array_keys($summarize['properties']))->toBe(['text', 'length'])
        ->and($summarize['required'])->toBe(['text'])
        ->and($summarize['properties']['text'])->toMatchArray(['type' => 'string', 'minLength' => 1])
        ->and($summarize['properties']['length'])->toMatchArray(['type' => 'string', 'enum' => ['short', 'medium', 'long']])
        ->and(array_keys($defs['alpaca-bot/summarize']['output_schema']['properties']))->toBe(['summary']);
    $draft = $defs['alpaca-bot/draft-post']['input_schema'];
    expect(array_keys($draft['properties']))->toBe(['title', 'content', 'post_type'])
        ->and($draft['required'])->toBe(['title', 'content'])
        ->and($draft['properties']['post_type'])->toMatchArray(['type' => 'string', 'enum' => ['post', 'page']])
        ->and(array_keys($defs['alpaca-bot/draft-post']['output_schema']['properties']))->toBe(['id', 'edit_url'])
        ->and($defs['alpaca-bot/draft-post']['output_schema']['required'])->toBe(['id', 'edit_url']);
});

it('registers nothing, and asks no filter, where the Abilities API is absent', function (): void {
    $asked = [];
    $register = abilitiesRegister(pipelineWith(null), exists: static function (string $fn) use (&$asked): bool {
        $asked[] = $fn;
        return false;
    });
    Functions\expect('wp_register_ability')->never();
    Functions\expect('wp_register_ability_category')->never();
    Filters\expectApplied('alpaca_bot/abilities')->never();
    $register->register();
    $register->registerCategory();
    expect($asked)->toBe(['wp_register_ability', 'wp_register_ability_category']);
});

it('registers the category and the three abilities, each with its own definition, where the API is present', function (): void {
    $register = abilitiesRegister(pipelineWith(null), exists: static fn(string $fn): bool => true);
    $registered = [];
    Functions\expect('wp_register_ability')->times(3)->andReturnUsing(static function (string $id, array $args) use (&$registered): ?object {
        $registered[$id] = $args;
        return null;
    });
    Filters\expectApplied('alpaca_bot/abilities')->once()->with(Mockery::type('array'))->andReturnFirstArg();
    $register->register();
    expect(array_keys($registered))->toBe(['alpaca-bot/chat', 'alpaca-bot/summarize', 'alpaca-bot/draft-post'])
        ->and($registered['alpaca-bot/chat']['category'])->toBe('alpaca-bot')
        ->and($registered['alpaca-bot/draft-post']['input_schema']['required'])->toBe(['title', 'content']);

    Functions\expect('wp_register_ability_category')->once()->with('alpaca-bot', Mockery::on(
        static fn(mixed $args): bool => is_array($args) && ($args['label'] ?? '') === 'Alpaca Bot' && is_string($args['description'] ?? null) && $args['description'] !== ''
    ))->andReturn(null);
    $register->registerCategory();
});

it('lets alpaca_bot/abilities drop or add a definition, and drops what is not one', function (): void {
    $register = abilitiesRegister(pipelineWith(null), exists: static fn(string $fn): bool => true);
    Filters\expectApplied('alpaca_bot/abilities')->once()->andReturnUsing(static function (array $defs): array {
        unset($defs['alpaca-bot/chat']);
        $defs['acme/thing'] = $defs['alpaca-bot/summarize'];
        $defs['acme/broken'] = 'not a definition';
        $defs[7] = $defs['alpaca-bot/summarize'];
        return $defs;
    });
    $ids = [];
    Functions\expect('wp_register_ability')->times(3)->andReturnUsing(static function (string $id) use (&$ids): ?object {
        $ids[] = $id;
        return null;
    });
    $register->register();
    expect($ids)->toBe(['alpaca-bot/summarize', 'alpaca-bot/draft-post', 'acme/thing']);
});

it('chat: allowed for a user who can edit_posts, refused for one who cannot, and for nobody', function (): void {
    $h = pipelineWith(null);
    $permission = abilitiesRegister($h)->definitions()['alpaca-bot/chat']['permission_callback'];
    abilitiesCaps(['edit_posts']);
    expect($permission(['message' => 'hi']))->toBeTrue();
    abilitiesCaps(['read']);
    expect($permission(['message' => 'hi']))->toBeFalse();
    abilitiesCaps(['edit_posts']);
    expect((abilitiesRegister($h, userId: 0)->definitions()['alpaca-bot/chat']['permission_callback'])(['message' => 'hi']))->toBeFalse();
});

it('chat: runs one stored turn as the acting user, continuing the conversation and with the model asked for, and answers the reply, the conversation id and the receipt', function (): void {
    $h = abilitiesFakeReply('Hello there.', $call);
    $execute = abilitiesRegister($h)->definitions()['alpaca-bot/chat']['execute_callback'];
    $out = $execute(['message' => 'hi', 'conversation_id' => 42, 'model' => 'llama3.2']);
    expect($out)->toBe(['conversation_id' => 42, 'reply' => 'Hello there.', 'receipt' => $out['receipt']])
        ->and($out['receipt']['user_id'])->toBe(3)
        ->and($out['receipt']['conversation_id'])->toBe(42)
        ->and($out['receipt']['total_tokens'])->toBe(5)
        ->and($h->model)->toBe('llama3.2')
        ->and($call['messages'][count($call['messages']) - 1]->content())->toBe('hi')
        // Stored, not ephemeral: the transcript is written to conversation 42 and the receipt names it.
        ->and(array_map(static fn(array $w): mixed => $w[1], $h->writes))->toContain(ConversationStore::META_MESSAGES);
});

it('chat: what the pipeline refuses becomes the same WP_Error the REST route answers, and nothing runs', function (): void {
    $h = pipelineWith(null);
    $execute = abilitiesRegister($h)->definitions()['alpaca-bot/chat']['execute_callback'];
    // Conversation 42 belongs to user 3; user 9 may not continue it.
    $out = (abilitiesRegister($h, userId: 9)->definitions()['alpaca-bot/chat']['execute_callback'])(['message' => 'hi', 'conversation_id' => 42]);
    expect($out)->toBeInstanceOf(WP_Error::class)
        ->and($out->get_error_code())->toBe('alpaca_bot_bad_request')
        ->and($out->get_error_data()['status'])->toBe(400);
    // A whitespace message is the pipeline's refusal too, in its words.
    $out = $execute(['message' => '   ']);
    expect($out)->toBeInstanceOf(WP_Error::class)
        ->and($out->get_error_code())->toBe('alpaca_bot_bad_request')
        ->and($out->get_error_message())->toBe('The message is empty.')
        ->and($h->writes)->toBe([]);
});

it('summarize: needs edit_posts and the summarize toolkit switched on, and says which is missing', function (): void {
    $h = pipelineWith(null);
    abilitiesCaps(['edit_posts']);
    expect((abilitiesRegister($h)->definitions()['alpaca-bot/summarize']['permission_callback'])(['text' => 'x']))->toBeTrue();
    // The toolkit switched off: refused with a reason, since the fix is the administrator's, not the caller's role.
    $off = (abilitiesRegister($h, enabled: ['web_fetch', 'draft_post'])->definitions()['alpaca-bot/summarize']['permission_callback'])(['text' => 'x']);
    expect($off)->toBeInstanceOf(WP_Error::class)
        ->and($off->get_error_code())->toBe('alpaca_bot_toolkit_disabled')
        ->and($off->get_error_data()['status'])->toBe(403);
    abilitiesCaps(['read']);
    expect((abilitiesRegister($h)->definitions()['alpaca-bot/summarize']['permission_callback'])(['text' => 'x']))->toBeFalse();
});

it('summarize: runs the summarize toolkit itself, as one ephemeral turn billed to the acting user, and answers the summary', function (): void {
    $h = abilitiesFakeReply('A summary.', $call);
    $out = (abilitiesRegister($h)->definitions()['alpaca-bot/summarize']['execute_callback'])(['text' => 'Long text here.', 'length' => 'short']);
    expect($out)->toBe(['summary' => 'A summary.'])
        // The toolkit's own prompt: the length words it maps, not the site's chat prompt.
        ->and($call['messages'][0]->content())->toContain('one or two sentences')
        ->and($call['messages'][1]->content())->toBe('Long text here.')
        // No conversation kept: the receipt is the only write, and it is user 3's, conversation 0.
        ->and(array_map(static fn(array $w): array => [$w[0], $w[1]], $h->writes))->toBe([['wp_insert_post', 'chat_log']])
        ->and($h->writes[0][2]['post_author'])->toBe(3)
        ->and($h->writes[0][2]['meta_input']['conversation_id'])->toBe(0);
});

it('summarize: a turn refused by the pipeline keeps its code, and a toolkit switched off between the check and the run still refuses', function (): void {
    // Cap spent (user 3 has used 50 of 10 this month): the 402 the REST route answers, not a flattened tool error.
    $h = pipelineWith(null, ['governance.user_monthly_tokens' => 10]);
    $h->transients['alpaca_bot_usage_3_2024-08'] = ['tokens' => 50, 'requests' => 1];
    $out = (abilitiesRegister($h)->definitions()['alpaca-bot/summarize']['execute_callback'])(['text' => 'x']);
    expect($out)->toBeInstanceOf(WP_Error::class)
        ->and($out->get_error_code())->toBe('alpaca_bot_cap_exceeded')
        ->and($out->get_error_data()['status'])->toBe(402);
    $h = pipelineWith(null);
    $out = (abilitiesRegister($h, enabled: [])->definitions()['alpaca-bot/summarize']['execute_callback'])(['text' => 'x']);
    expect($out)->toBeInstanceOf(WP_Error::class)
        ->and($out->get_error_code())->toBe('alpaca_bot_toolkit_disabled')
        ->and($h->writes)->toBe([]);
});

it('draft-post: a post needs edit_posts, a page needs edit_pages, and the draft_post toolkit must be on', function (): void {
    $h = pipelineWith(null);
    $permission = abilitiesRegister($h)->definitions()['alpaca-bot/draft-post']['permission_callback'];
    abilitiesCaps(['edit_posts']);
    expect($permission(['title' => 't', 'content' => 'c']))->toBeTrue()
        ->and($permission(['title' => 't', 'content' => 'c', 'post_type' => 'post']))->toBeTrue()
        ->and($permission(['title' => 't', 'content' => 'c', 'post_type' => 'page']))->toBeFalse();
    abilitiesCaps(['edit_posts', 'edit_pages']);
    expect($permission(['title' => 't', 'content' => 'c', 'post_type' => 'page']))->toBeTrue();
    $off = (abilitiesRegister($h, enabled: ['summarize'])->definitions()['alpaca-bot/draft-post']['permission_callback'])(['title' => 't', 'content' => 'c']);
    expect($off)->toBeInstanceOf(WP_Error::class)->and($off->get_error_code())->toBe('alpaca_bot_toolkit_disabled');
});

it('draft-post: runs the draft_post toolkit itself and answers the id and the edit link; its refusal is a WP_Error', function (): void {
    $h = pipelineWith(null);
    abilitiesCaps(['edit_posts']);
    Functions\when('wp_kses_post')->returnArg();
    Functions\when('get_edit_post_link')->alias(static fn(int $id): string => "/wp-admin/post.php?post={$id}&action=edit");
    Functions\when('is_wp_error')->alias(static fn(mixed $v): bool => $v instanceof WP_Error);
    $inserted = null;
    Functions\when('wp_insert_post')->alias(static function (array $post) use (&$inserted): int {
        $inserted = $post;
        return 77;
    });
    $execute = abilitiesRegister($h)->definitions()['alpaca-bot/draft-post']['execute_callback'];
    expect($execute(['title' => 'From MCP', 'content' => '<p>Body</p>']))->toBe(['id' => 77, 'edit_url' => '/wp-admin/post.php?post=77&action=edit'])
        ->and($inserted)->toMatchArray(['post_type' => 'post', 'post_status' => 'draft', 'post_author' => 3, 'post_title' => 'From MCP']);
    // The toolkit's own refusal (a page for a user without edit_pages) comes back as an error, and nothing was inserted.
    $inserted = null;
    $out = $execute(['title' => 'p', 'content' => 'c', 'post_type' => 'page']);
    expect($out)->toBeInstanceOf(WP_Error::class)
        ->and($out->get_error_code())->toBe('alpaca_bot_tool_error')
        ->and($out->get_error_data()['status'])->toBe(400)
        ->and($inserted)->toBeNull();
});
