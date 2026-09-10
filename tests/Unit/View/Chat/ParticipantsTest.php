<?php

declare(strict_types=1);

use AlpacaBot\Settings\Store;
use AlpacaBot\View\Chat\Participants;
use Brain\Monkey\Functions;

/*
 * Who a transcript shows. Small, and until now the only file in src/ with no test reference at
 * all: SchemaTest and Migrate04Test cover `chat.assistant_avatar` being sanitized and stored,
 * and nothing covered it being read, so the custom-avatar setting could stop working with a
 * green suite.
 *
 * The three callers (View\Chat\Shell::render(), Rest\ViewController's bubble and list
 * fragments) hand what this returns straight to MessageBubble and MessageList as the `src` of an
 * <img> and the flag that puts the default's disc class on it, so what is asserted here is the
 * value each of those images gets.
 */

beforeEach(function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 7, 'display_name' => 'Carmelo']);
    Functions\when('get_avatar_url')->justReturn('https://gravatar.test/7');
    Functions\when('plugins_url')->alias(static fn(string $path, string $file): string => 'https://site.test/wp-content/plugins/alpaca-bot/' . $path);
});

it('shows the assistant with the avatar the site configured', function (): void {
    $who = Participants::current(new Store(['chat.assistant_avatar' => 'https://site.test/bot.png']));

    expect($who->assistantAvatar)->toBe('https://site.test/bot.png')
        ->and($who->userName)->toBe('Carmelo')
        ->and($who->userAvatar)->toBe('https://gravatar.test/7');
});

it('falls back to the alpaca logo only when no assistant avatar is set', function (): void {
    // The setting's schema default is '' (Schema::fields()), and sanitizeUrl() writes '' back for
    // anything it rejects, so '' is the one value that means "not set" and the only one this may
    // replace. A site that set an avatar and a site that did not must not look the same.
    $logo = 'https://site.test/wp-content/plugins/alpaca-bot/assets/img/alpaca-bot.svg';

    expect(Participants::current(new Store())->assistantAvatar)->toBe($logo)
        ->and(Participants::current(new Store(['chat.assistant_avatar' => '']))->assistantAvatar)->toBe($logo)
        ->and(Participants::current(new Store(['chat.assistant_avatar' => 'https://site.test/bot.png']))->assistantAvatar)->not->toBe($logo);
});

it('marks the fallback as the default, and a configured avatar as not', function (): void {
    // The logo is black line art on a transparent ground, and its ears clip in a round crop, so
    // the stylesheet seats it on a padded off-white disc. A site's own avatar is a photo that
    // must keep filling its circle edge to edge, so the disc has to be scoped to the default,
    // and the components cannot tell the two URLs apart: this flag is what they scope it with.
    expect(Participants::current(new Store())->assistantAvatarIsDefault)->toBeTrue()
        ->and(Participants::current(new Store(['chat.assistant_avatar' => '']))->assistantAvatarIsDefault)->toBeTrue()
        ->and(Participants::current(new Store(['chat.assistant_avatar' => 'https://site.test/bot.png']))->assistantAvatarIsDefault)->toBeFalse();
});

it('carries an empty user avatar rather than the false core answers when there is none', function (): void {
    // get_avatar_url() is documented "string|false ... false on failure" (php-stubs/wordpress-stubs
    // 7.1, wordpress-stubs.php:142604), and a plugin is what usually produces the false: core's
    // own url is built unconditionally and then passed through `get_avatar_url`
    // (link-template.php:4571 in 6.8.3), with `pre_get_avatar_data` (:4477) able to short-circuit
    // it — both filters a privacy or disable-gravatar plugin returns false from.
    //
    // Participants.php declares strict_types, so without the guard that false does not become ''
    // on the way into the constructor: it is a TypeError. Measured — dropping the guard turns this
    // test into a TypeError, not a wrong value — which makes every screen that resolves
    // participants (the shell and both view fragments) a 500 on such a site. Named here so the
    // guard is a decision rather than something a later reader reads as redundant.
    Functions\when('get_avatar_url')->justReturn(false);

    $who = Participants::current(new Store());

    expect($who->userAvatar)->toBe('')
        ->and($who->userName)->toBe('Carmelo');
});
