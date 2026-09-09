<?php

declare(strict_types=1);

use AlpacaBot\Abilities\Register;
use AlpacaBot\Chat\ConversationStore;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Response;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Provider\Usage;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
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
    abilitiesCaps(['edit_posts']);
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
    // Both users may chat; what refuses them is the pipeline.
    Functions\when('user_can')->justReturn(true);
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
    abilitiesCaps(['edit_posts']);
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
    abilitiesCaps(['edit_posts']);
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

it('draft-post: runs the draft_post toolkit itself and answers the id and the edit link; a page the user may not edit is refused before it', function (): void {
    $h = pipelineWith(null);
    abilitiesCaps(['edit_posts']);
    Functions\when('is_user_logged_in')->justReturn(true);
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
    // A page for a user without edit_pages: the execute callback's own capability check, from
    // the same map the toolkit enforces, refuses before the toolkit runs, and nothing was inserted.
    $inserted = null;
    $out = $execute(['title' => 'p', 'content' => 'c', 'post_type' => 'page']);
    expect($out)->toBeInstanceOf(WP_Error::class)
        ->and($out->get_error_code())->toBe('rest_forbidden')
        ->and($out->get_error_data()['status'])->toBe(403)
        ->and($inserted)->toBeNull();
});

/** The rate limiter's counter, kept in the harness's transients so a hit is seen by the next. */
function abilitiesCountHits(object $h): void
{
    Functions\when('set_transient')->alias(static function (string $key, mixed $value) use ($h): bool {
        $h->transients[$key] = $value;
        return true;
    });
}

it('chat and summarize: limited with POST /chat, in its chat bucket, thirty a minute per user; the thirty-first is 429 with retry_after and nothing runs', function (): void {
    $h = abilitiesFakeReply('Hello.');
    abilitiesCaps(['edit_posts']);
    abilitiesCountHits($h);
    // The same key RateLimit writes for the REST route: twenty-nine hits already this minute, from whichever surface.
    $key = 'alpaca_bot_rl_chat_3_' . gmdate('YmdHi', 1_725_000_000);
    $h->transients[$key] = 29;
    Filters\expectApplied('alpaca_bot/rate_limit')->times(2)->with(30, 3, 'chat')->andReturnFirstArg();
    $defs = abilitiesRegister($h)->definitions();
    $out = ($defs['alpaca-bot/chat']['execute_callback'])(['message' => 'hi']);
    expect($out)->toBeArray()->and($out['reply'])->toBe('Hello.')->and($h->transients[$key])->toBe(30);
    $writes = count($h->writes);
    $out = ($defs['alpaca-bot/summarize']['execute_callback'])(['text' => 'x']);
    expect($out)->toBeInstanceOf(WP_Error::class)
        ->and($out->get_error_code())->toBe('alpaca_bot_rate_limited')
        ->and($out->get_error_data()['status'])->toBe(429)
        ->and($out->get_error_data()['retry_after'])->toBeGreaterThan(0)
        // Counted even when refused, as the REST route counts it: a client past the limit does not refill its bucket.
        ->and($h->transients[$key])->toBe(31)
        ->and(count($h->writes))->toBe($writes);
});

it('the alpaca_bot/rate_limit filter moves the abilities\' limit as it moves the REST routes\': at one a minute the second turn is refused', function (): void {
    $h = abilitiesFakeReply('Hello.');
    abilitiesCaps(['edit_posts']);
    abilitiesCountHits($h);
    Filters\expectApplied('alpaca_bot/rate_limit')->times(2)->with(30, 3, 'chat')->andReturn(1);
    $execute = abilitiesRegister($h)->definitions()['alpaca-bot/chat']['execute_callback'];
    expect($execute(['message' => 'hi']))->toBeArray();
    $out = $execute(['message' => 'again']);
    expect($out)->toBeInstanceOf(WP_Error::class)->and($out->get_error_code())->toBe('alpaca_bot_rate_limited');
});

it('the execute callbacks refuse a caller without the capability on their own, so an adapter that skips check_permissions gets the same answer and nothing runs', function (): void {
    $h = pipelineWith(null);
    abilitiesCaps(['read']);
    Functions\when('is_user_logged_in')->justReturn(true);
    $defs = abilitiesRegister($h)->definitions();
    foreach (['alpaca-bot/chat' => ['message' => 'hi'], 'alpaca-bot/summarize' => ['text' => 'x'], 'alpaca-bot/draft-post' => ['title' => 't', 'content' => 'c']] as $id => $input) {
        $out = ($defs[$id]['execute_callback'])($input);
        expect($out)->toBeInstanceOf(WP_Error::class, $id)
            ->and($out->get_error_code())->toBe('rest_forbidden', $id)
            ->and($out->get_error_data()['status'])->toBe(403, $id);
    }
    // A page for a user who may edit posts but not pages: the post type's own capability, asked again here, before the toolkit is.
    abilitiesCaps(['edit_posts']);
    $out = ($defs['alpaca-bot/draft-post']['execute_callback'])(['title' => 't', 'content' => 'c', 'post_type' => 'page']);
    expect($out)->toBeInstanceOf(WP_Error::class)
        ->and($out->get_error_code())->toBe('rest_forbidden')
        ->and($h->writes)->toBe([]);
});

it('nobody: an acting user of 0 is refused by the guard itself, whatever user_can() would say about id 0, on every permission and execute callback', function (): void {
    $h = pipelineWith(null);
    // A capability map that says yes to anyone, id 0 included: only the guard can refuse here.
    Functions\when('user_can')->justReturn(true);
    Functions\when('is_user_logged_in')->justReturn(false);
    $defs = abilitiesRegister($h, userId: 0)->definitions();
    foreach (['alpaca-bot/chat' => ['message' => 'hi'], 'alpaca-bot/summarize' => ['text' => 'x'], 'alpaca-bot/draft-post' => ['title' => 't', 'content' => 'c']] as $id => $input) {
        expect(($defs[$id]['permission_callback'])($input))->toBeFalse($id);
        $out = ($defs[$id]['execute_callback'])($input);
        expect($out)->toBeInstanceOf(WP_Error::class, $id)
            ->and($out->get_error_code())->toBe('rest_forbidden', $id)
            ->and($out->get_error_data()['status'])->toBe(401, $id);
    }
    expect($h->writes)->toBe([]);
});

it('summarize and draft-post run whatever alpaca_bot/toolkits put under the id, so a site that swaps a toolkit swaps it for the model and the ability alike', function (): void {
    $h = pipelineWith(null);
    abilitiesCaps(['edit_posts']);
    abilitiesCountHits($h);
    $swapped = new class implements ToolkitInterface {
        /** @var list<array{0: string, 1: array<string, mixed>}> */
        public array $calls = [];

        public function tools(): array
        {
            return [
                new Tool('summarize', 'A summary from elsewhere.', [new StringParameter('text', 'The text.')], function (array $args): string {
                    $this->calls[] = ['summarize', $args];
                    return 'Swapped summary.';
                }),
                new Tool('draft_post', 'A draft from elsewhere.', [new StringParameter('title', 'The title.'), new StringParameter('content', 'The body.')], function (array $args): string {
                    $this->calls[] = ['draft_post', $args];
                    return (string) json_encode(['id' => 5, 'edit_url' => '/edit/5']);
                }),
            ];
        }

        public function guidelines(): string
        {
            return '';
        }
    };
    Filters\expectApplied('alpaca_bot/toolkits')->with(Mockery::type('array'), 3)->andReturn(['summarize' => $swapped, 'draft_post' => $swapped]);
    $defs = abilitiesRegister($h)->definitions();
    expect(($defs['alpaca-bot/summarize']['execute_callback'])(['text' => 'Long.', 'length' => 'short']))->toBe(['summary' => 'Swapped summary.'])
        ->and(($defs['alpaca-bot/draft-post']['execute_callback'])(['title' => 't', 'content' => 'c']))->toBe(['id' => 5, 'edit_url' => '/edit/5'])
        ->and($swapped->calls)->toBe([['summarize', ['text' => 'Long.', 'length' => 'short']], ['draft_post', ['title' => 't', 'content' => 'c']]])
        // The plugin's own toolkits never ran: no turn, no insert.
        ->and($h->writes)->toBe([]);
});
