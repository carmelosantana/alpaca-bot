<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\DraftPostToolkit;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use Brain\Monkey\Functions;

// The acting user is a closure resolved when the tool runs, never at construction:
// Plugin::register() builds the toolkits on plugins_loaded, before the current user exists, so
// an id taken then would be 0 for every turn. The tests hand in a closure that also counts its
// calls, so a toolkit that resolved early would show up as zero calls. The capability is asked
// about that same id (user_can), never about whoever is logged in (current_user_can): the
// shortcode and Abilities surfaces later in this phase can act for a user who is not the
// current one, and the check and the authorship must not be able to disagree.

/** The tool over a user id the closure answers, counting how often it was asked. */
function draftPostTool(int $userId, int &$asked): Tool
{
    $tool = (new DraftPostToolkit(static function () use ($userId, &$asked): int {
        $asked++;
        return $userId;
    }))->tools()[0];
    expect($tool)->toBeInstanceOf(Tool::class);
    return $tool;
}

beforeEach(function (): void {
    Functions\when('sanitize_text_field')->alias(static fn(string $s): string => trim(strip_tags($s)));
    Functions\when('wp_kses_post')->alias(static fn(string $s): string => str_replace('<script>alert(1)</script>', '', $s));
    Functions\when('is_wp_error')->alias(static fn(mixed $v): bool => $v instanceof WP_Error);
});

it('creates a draft post for the acting user, resolved at call time, and answers its id, status and edit link', function (): void {
    Functions\expect('user_can')->once()->with(3, 'edit_posts')->andReturn(true);
    Functions\expect('current_user_can')->never();
    Functions\expect('wp_insert_post')->once()->withArgs(static fn(array $p, bool $wpError): bool => $p === [
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_author' => 3,
        'post_title' => 'Title',
        'post_content' => '<p>Body</p>',
    ] && $wpError === true)->andReturn(55);
    Functions\expect('get_edit_post_link')->once()->with(55, 'raw')->andReturn('https://x/wp-admin/post.php?post=55&action=edit');
    $asked = 0;
    $tool = draftPostTool(3, $asked);
    expect($asked)->toBe(0)->and($tool->name())->toBe('draft_post');
    $res = $tool->execute(['title' => ' <b>Title</b> ', 'content' => '<p>Body</p><script>alert(1)</script>']);
    expect($res->status)->toBe(ToolResultStatus::Success)
        ->and($asked)->toBe(1)
        ->and(json_decode($res->content, true))->toBe(['id' => 55, 'status' => 'draft', 'post_type' => 'post', 'edit_url' => 'https://x/wp-admin/post.php?post=55&action=edit']);
});

it('makes a page when asked, under the page capability', function (): void {
    Functions\expect('user_can')->once()->with(3, 'edit_pages')->andReturn(true);
    Functions\expect('wp_insert_post')->once()->withArgs(static fn(array $p): bool => $p['post_type'] === 'page' && $p['post_status'] === 'draft')->andReturn(56);
    Functions\when('get_edit_post_link')->justReturn('https://x/56');
    $asked = 0;
    $res = draftPostTool(3, $asked)->execute(['title' => 'T', 'content' => 'x', 'post_type' => 'page']);
    expect(json_decode($res->content, true)['post_type'])->toBe('page');
});

it('refuses a user without the capability, and inserts nothing', function (): void {
    Functions\expect('user_can')->once()->with(3, 'edit_posts')->andReturn(false);
    Functions\expect('wp_insert_post')->never();
    $asked = 0;
    $res = draftPostTool(3, $asked)->execute(['title' => 'T', 'content' => 'x']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('cannot');
});

// The status is not a parameter: a caller (a model) that passes one is handed the same draft,
// and the tool's schema never offers the option. Both halves are pinned: the schema and the insert.
it('never publishes: a post_status the model sends is ignored, and the schema does not offer one', function (): void {
    Functions\expect('user_can')->once()->andReturn(true);
    Functions\expect('wp_insert_post')->once()->withArgs(static fn(array $p): bool => $p['post_status'] === 'draft')->andReturn(57);
    Functions\when('get_edit_post_link')->justReturn('https://x/57');
    $asked = 0;
    $tool = draftPostTool(3, $asked);
    expect(array_keys($tool->toFunctionSchema()['function']['parameters']['properties']))->toBe(['title', 'content', 'post_type'])
        ->and($tool->toFunctionSchema()['function']['parameters']['required'])->toBe(['title', 'content']);
    expect(json_decode($tool->execute(['title' => 'T', 'content' => 'x', 'post_status' => 'publish'])->content, true)['status'])->toBe('draft');
});

it('refuses when nobody is logged in, without asking the capability map', function (): void {
    Functions\expect('user_can')->never();
    Functions\expect('wp_insert_post')->never();
    $asked = 0;
    expect(draftPostTool(0, $asked)->execute(['title' => 'T', 'content' => 'x'])->status)->toBe(ToolResultStatus::Error);
});

it('passes an insert failure back as an error, and is refused by the tool for a post_type outside post and page or a missing title', function (): void {
    Functions\expect('user_can')->once()->andReturn(true);
    Functions\expect('wp_insert_post')->once()->andReturn(new WP_Error('empty_content', 'Content, title, and excerpt are empty.'));
    $asked = 0;
    $tool = draftPostTool(3, $asked);
    $res = $tool->execute(['title' => 'T', 'content' => 'x']);
    expect($res->status)->toBe(ToolResultStatus::Error)
        ->and($res->content)->toContain('empty');
    expect($tool->execute(['title' => 'T', 'content' => 'x', 'post_type' => 'attachment'])->status)->toBe(ToolResultStatus::Error)
        ->and($tool->execute(['content' => 'x'])->status)->toBe(ToolResultStatus::Error);
});

it('carries guidelines that say the result is a draft', function (): void {
    $kit = new DraftPostToolkit(static fn(): int => 3);
    expect($kit->tools())->toHaveCount(1)
        ->and($kit->guidelines())->toContain('draft_post')->toContain('draft');
});
