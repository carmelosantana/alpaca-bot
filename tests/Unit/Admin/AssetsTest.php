<?php

declare(strict_types=1);

use AlpacaBot\Admin\Assets;
use AlpacaBot\Plugin;
use Brain\Monkey\Functions;

it('enqueues nothing on any screen but the chat screen', function (): void {
    foreach (['index.php', 'alpaca-bot_page_alpaca-bot-settings', 'post.php', ''] as $hook) {
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_media')->never();
        (new Assets())->enqueue($hook);
    }
});

it('enqueues htmx, the chat bundle after it, the stylesheet and the media picker on the chat screen, with the REST root and nonce localised', function (): void {
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('wp_convert_hr_to_bytes')->justReturn(8 * 1024 * 1024);
    Functions\stubTranslationFunctions();
    Functions\expect('wp_enqueue_media')->once();
    Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-htmx', '/plugins/alpaca-bot/assets/js/htmx.min.js', [], Assets::HTMX_VERSION, true);
    Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-chat', '/plugins/alpaca-bot/assets/js/chat.js', ['alpaca-bot-htmx', 'heartbeat'], Mockery::type('string'), true);
    Functions\expect('wp_enqueue_style')->once()->with('alpaca-bot', '/plugins/alpaca-bot/assets/css/alpaca-bot.css', [], Mockery::type('string'));
    $localised = null;
    Functions\expect('wp_localize_script')->once()->with('alpaca-bot-chat', 'alpacaBot', Mockery::on(static function (array $data) use (&$localised): bool {
        $localised = $data;
        return true;
    }));
    (new Assets())->enqueue(Assets::HOOK);
    expect(Assets::HOOK)->toBe('toplevel_page_alpaca-bot')
        ->and(Assets::HTMX_VERSION)->toMatch('/^2\.0\.\d+$/')
        ->and($localised['rest'])->toBe('/wp-json/alpaca-bot/v1')
        ->and($localised['nonce'])->toBe('n')
        ->and($localised['maxImageBytes'])->toBe(6242304)
        ->and($localised['offline'])->toBeString()->not->toBe('')
        ->and($localised['i18n'])->toBeArray()->not->toBeEmpty();
    foreach ($localised['i18n'] as $key => $text) {
        expect($key)->toBeString()->and($text)->toBeString()->not->toBe('');
    }
});

it('ships the real image cap, the raw size whose base64 body fits under post_max_size, with a message that names both figures', function (): void {
    // image.ts's constant is a guess against a default 8M post_max_size; behind a tighter
    // limit the guard fell into the opaque failure it exists to close. The figure ships from
    // PHP, and the message it shows carries placeholders for the size and the cap.
    //
    // The image is not an upload: it travels base64-encoded inside the JSON body of POST /chat,
    // so post_max_size is the only PHP limit it meets, and the cap is the raw size whose encoded
    // form (4/3 of it) plus the rest of the body stays under it. wp_max_upload_size() has no say:
    // the picker chooses from the media library, so any image it offers already passed that.
    Functions\when('wp_convert_hr_to_bytes')->alias(static fn(string $v): int => (int) $v * match (strtoupper(substr(trim($v), -1))) { 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, default => 1 });
    $allowance = Assets::IMAGE_BODY_ALLOWANCE;
    expect($allowance)->toBe(64 * 1024)
        ->and(Assets::maxImageBytes('8M'))->toBe(intdiv((8 * 1024 * 1024 - $allowance) * 3, 4))->toBe(6242304)
        ->and(Assets::maxImageBytes('4M'))->toBe(intdiv((4 * 1024 * 1024 - $allowance) * 3, 4))->toBe(3096576)
        // post_max_size 0 is PHP for "no limit": no cap, and image.ts reads 0 as "no figure".
        ->and(Assets::maxImageBytes('0'))->toBe(0)
        // A post_max_size the allowance alone exhausts is a site nothing posts to; the figure is 0 rather than negative.
        ->and(Assets::maxImageBytes('64K'))->toBe(0);
    // The arithmetic is only right if an image at the cap actually posts: its base64 form
    // (4 chars per 3 bytes, rounded up to a whole quantum) plus the allowance must not exceed
    // post_max_size, which PHP enforces as CONTENT_LENGTH > post_max_size.
    foreach (['8M' => 8 * 1024 * 1024, '4M' => 4 * 1024 * 1024] as $ini => $postMax) {
        $cap = Assets::maxImageBytes($ini);
        expect(4 * intdiv($cap + 2, 3) + $allowance)->toBeLessThanOrEqual($postMax)
            // and the cap is not needlessly tight: one more quantum of raw bytes would not fit.
            ->and(4 * intdiv($cap + 3 + 2, 3) + $allowance)->toBeGreaterThan($postMax);
    }

    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\stubTranslationFunctions();
    Functions\when('wp_enqueue_media')->justReturn();
    Functions\when('wp_enqueue_script')->justReturn(true);
    Functions\when('wp_enqueue_style')->justReturn(true);
    $localised = null;
    Functions\when('wp_localize_script')->alias(static function (string $h, string $n, array $data) use (&$localised): bool {
        $localised = $data;
        return true;
    });
    (new Assets())->enqueue(Assets::HOOK);
    expect($localised['maxImageBytes'])->toBeInt()->toBe(Assets::maxImageBytes((string) ini_get('post_max_size')))
        ->and($localised['i18n']['imageTooLarge'])->toContain('{size}')->toContain('{max}')
        // The total's message beside the single image's: image.ts's ImagesTooLarge carries the same two figures.
        ->and($localised['i18n']['imagesTooLarge'])->toContain('{size}')->toContain('{max}')->not->toBe($localised['i18n']['imageTooLarge']);
});

it('versions the build outputs by the plugin version outside WP_DEBUG, and under it by mtime only for a file that is there', function (): void {
    // The unit process has no WP_DEBUG: the enqueue path takes the plugin version for both files.
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('wp_convert_hr_to_bytes')->justReturn(8 * 1024 * 1024);
    Functions\stubTranslationFunctions();
    Functions\when('wp_enqueue_media')->justReturn();
    Functions\when('wp_localize_script')->justReturn(true);
    $versions = [];
    Functions\when('wp_enqueue_script')->alias(static function (string $handle, string $src, array $deps, string|bool $ver) use (&$versions): void {
        $versions[$handle] = $ver;
    });
    Functions\when('wp_enqueue_style')->alias(static function (string $handle, string $src, array $deps, string|bool $ver) use (&$versions): void {
        $versions[$handle] = $ver;
    });
    (new Assets())->enqueue(Assets::HOOK);
    expect($versions)->toBe(['alpaca-bot-htmx' => Assets::HTMX_VERSION, 'alpaca-bot-chat' => Plugin::VERSION, 'alpaca-bot' => Plugin::VERSION]);

    // Under WP_DEBUG (asked for explicitly), a checkout without `pnpm build` run has no bundle
    // to stamp: the is_readable() guard answers the plugin version, and filemtime() never
    // warns. A file that is there is stamped with its mtime.
    set_error_handler(static function (int $no, string $msg): never {
        throw new ErrorException($msg, $no);
    });
    try {
        expect(Assets::version('assets/js/does-not-exist-' . getmypid() . '.js', true))->toBe(Plugin::VERSION)
            ->and(Assets::version('alpaca-bot.php', true))->toBe((string) filemtime(dirname(ALPACA_BOT_FILE) . '/alpaca-bot.php'))
            ->and(Assets::version('alpaca-bot.php', false))->toBe(Plugin::VERSION);
    } finally {
        restore_error_handler();
    }
});

it('pins htmx to the exact version package.json installs, so a bump busts the browser cache', function (): void {
    // "~2.0.10" would let pnpm install 2.0.11 while the enqueued version string stayed put.
    $package = json_decode((string) file_get_contents(dirname(ALPACA_BOT_FILE) . '/package.json'), true);
    expect($package['devDependencies']['htmx.org'])->toBe(Assets::HTMX_VERSION);
});

it('answers the heartbeat with a fresh REST nonce only when the chat screen asked for one', function (): void {
    // chat.ts sends alpaca_bot_nonce: 1 on heartbeat-send (Kanboard #302); any other heartbeat
    // (another screen, a poll without the flag) is left as it was.
    Functions\when('wp_create_nonce')->alias(fn(string $action) => 'nonce-for-' . $action);
    $assets = new Assets();
    expect($assets->heartbeat(['server_time' => 1], ['alpaca_bot_nonce' => 1]))->toBe(['server_time' => 1, 'alpaca_bot_nonce' => 'nonce-for-wp_rest'])
        ->and($assets->heartbeat(['server_time' => 1], ['alpaca_bot_nonce' => '1']))->toBe(['server_time' => 1, 'alpaca_bot_nonce' => 'nonce-for-wp_rest'])
        ->and($assets->heartbeat(['server_time' => 1], []))->toBe(['server_time' => 1])
        ->and($assets->heartbeat(['server_time' => 1], ['alpaca_bot_nonce' => 0]))->toBe(['server_time' => 1])
        ->and($assets->heartbeat(['server_time' => 1], ['wp-refresh-post-nonces' => ['post_id' => 1]]))->toBe(['server_time' => 1]);
});

it('enqueues the same bundle for a front-end shortcode render, the media picker only for a user who can upload, and no admin hook', function (): void {
    // A [alpacabot] shell on a page: the handles are the admin screen's, so a page that carries
    // the shell and (somehow) the admin bundle loads each file once. wp_enqueue_media() is the
    // whole media library (Backbone, the views, the modal templates in wp_footer); a viewer who
    // cannot upload_files gets an empty modal from it, so it is loaded for those who can.
    Functions\when('plugins_url')->alias(fn(string $p) => '/plugins/alpaca-bot/' . $p);
    Functions\when('rest_url')->alias(fn(string $p) => '/wp-json/' . $p);
    Functions\when('wp_create_nonce')->justReturn('n');
    Functions\when('wp_convert_hr_to_bytes')->justReturn(8 * 1024 * 1024);
    Functions\stubTranslationFunctions();
    foreach ([true, false] as $canUpload) {
        Functions\when('current_user_can')->alias(static fn(string $cap): bool => $cap === 'upload_files' && $canUpload);
        Functions\expect('wp_enqueue_media')->times($canUpload ? 1 : 0);
        Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-htmx', '/plugins/alpaca-bot/assets/js/htmx.min.js', [], Assets::HTMX_VERSION, true);
        Functions\expect('wp_enqueue_script')->once()->with('alpaca-bot-chat', '/plugins/alpaca-bot/assets/js/chat.js', ['alpaca-bot-htmx', 'heartbeat'], Mockery::type('string'), true);
        Functions\expect('wp_enqueue_style')->once()->with('alpaca-bot', '/plugins/alpaca-bot/assets/css/alpaca-bot.css', [], Mockery::type('string'));
        $localised = null;
        Functions\expect('wp_localize_script')->once()->with('alpaca-bot-chat', 'alpacaBot', Mockery::on(static function (array $data) use (&$localised): bool {
            $localised = $data;
            return true;
        }));
        (new Assets())->enqueueFront();
        // The bundle signs every request with the nonce and reads the REST root, on the front end as in wp-admin.
        expect($localised['rest'])->toBe('/wp-json/alpaca-bot/v1')->and($localised['nonce'])->toBe('n')->and($localised['maxImageBytes'])->toBe(6242304);
    }
});
