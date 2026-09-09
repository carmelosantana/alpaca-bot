<?php

declare(strict_types=1);

/**
 * bin/hooks-doc.php, run as the CLI script it is (a fresh PHP process per call), over a
 * throwaway tree: `<root>/src/<name>.php` files written by the test, so each case pins one
 * behaviour of the scanner without depending on what the plugin's real hooks look like today.
 * The last two cases run it over the real repo, which is the reference the file in docs/ is.
 *
 * @return array{code: int, stdout: string, stderr: string}
 */
function hooksDoc(string $root, ?string $out = '-'): array
{
    $args = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/hooks-doc.php', '--root=' . $root];
    if ($out !== null) {
        $args[] = '--out=' . $out;
    }
    $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * A throwaway repo root holding `src/<name> => <php source>`; removed after the test.
 *
 * @param array<string, string> $files
 */
function hooksDocTree(array $files): string
{
    $root = sys_get_temp_dir() . '/alpaca-hooks-doc-' . bin2hex(random_bytes(6));
    mkdir($root . '/src/Deep', 0o777, true);
    foreach ($files as $name => $source) {
        file_put_contents($root . '/src/' . $name, $source);
    }
    $GLOBALS['hooksDocRoots'][] = $root;
    return $root;
}

/** One `apply_filters` site with the docblock the reference expects, as a fixture file. */
function hooksDocDocumented(): string
{
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Fixture;

    final class Documented
    {
        public function run(string $text, int $userId): string
        {
            /**
             * Filters the text before it is sent, so a site can rewrite or refuse it.
             * A listener that returns '' refuses the turn.
             *
             * @since 0.5.0
             * @param string $text   the user's message, trimmed
             * @param int    $userId who sent it
             */
            return (string) apply_filters('alpaca_bot/fixture/before', $text, $userId);
        }
    }
    PHP;
}

afterEach(function (): void {
    foreach ($GLOBALS['hooksDocRoots'] ?? [] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
    $GLOBALS['hooksDocRoots'] = [];
});

it('renders one table row per documented hook call, with the params and the repo-relative location', function (): void {
    $root = hooksDocTree(['Documented.php' => hooksDocDocumented()]);

    $run = hooksDoc($root);

    expect($run['code'])->toBe(0, $run['stderr'])
        ->and($run['stderr'])->toBe('')
        ->and($run['stdout'])->toContain(
            '| `alpaca_bot/fixture/before` | filter | `string $text` — the user\'s message, trimmed<br>`int $userId` — who sent it | `src/Documented.php:19` | Filters the text before it is sent, so a site can rewrite or refuse it. A listener that returns \'\' refuses the turn. |',
        );
});

it('reads a do_action whose hook name is on its own line as an action', function (): void {
    $root = hooksDocTree(['Multi.php' => <<<'PHP'
    <?php
    /**
     * Fires when a turn fails.
     *
     * @since 0.5.0
     * @param \Throwable $e why
     */
    do_action(
        'alpaca_bot/fixture/failed',
        new \RuntimeException('x'),
    );
    PHP]);

    $run = hooksDoc($root);

    expect($run['code'])->toBe(0, $run['stderr'])
        ->and($run['stdout'])->toContain('| `alpaca_bot/fixture/failed` | action | `\Throwable $e` — why | `src/Multi.php:8` | Fires when a turn fails. |');
});

it('reads a Capability::filtered() site as a filter, with an interpolated segment shown as {name}', function (): void {
    $root = hooksDocTree(['Guarded.php' => <<<'PHP'
    <?php
    use AlpacaBot\Capability;
    /**
     * Filters the capability a route checks.
     *
     * @since 0.5.0
     * @param string           $capability the route's default
     * @param \WP_REST_Request $request    the request
     */
    $cap = Capability::filtered("alpaca_bot/fixture/capability/{$route}", $capability, $request);
    /**
     * Filters the menu capability.
     *
     * @since 0.5.0
     * @param string $capability `edit_posts`
     */
    $menu = Capability::filtered('alpaca_bot/fixture/menu_capability', 'edit_posts');
    PHP]);

    $run = hooksDoc($root);

    expect($run['code'])->toBe(0, $run['stderr'])
        ->and($run['stdout'])->toContain('| `alpaca_bot/fixture/capability/{route}` | filter | `string $capability` — the route\'s default<br>`\WP_REST_Request $request` — the request | `src/Guarded.php:10` |')
        ->and($run['stdout'])->toContain('| `alpaca_bot/fixture/menu_capability` | filter | `string $capability` — `edit_posts` | `src/Guarded.php:17` |');
});

it('fails, naming the site, when a hook call has no docblock, and writes nothing', function (): void {
    $root = hooksDocTree(['Bare.php' => <<<'PHP'
    <?php
    $x = apply_filters('alpaca_bot/fixture/bare', 1);
    PHP]);

    $run = hooksDoc($root, $root . '/docs/hooks.md');

    expect($run['code'])->toBe(1)
        ->and($run['stderr'])->toContain('src/Bare.php:2')->toContain('alpaca_bot/fixture/bare')->toContain('no docblock')
        ->and(file_exists($root . '/docs/hooks.md'))->toBeFalse();
});

it('fails when the docblock documents a different number of params than the call passes', function (): void {
    $root = hooksDocTree(['Short.php' => <<<'PHP'
    <?php
    /**
     * Filters something with two arguments but documents one.
     *
     * @since 0.5.0
     * @param int $a first
     */
    $x = apply_filters('alpaca_bot/fixture/short', $a, $b);
    PHP]);

    $run = hooksDoc($root);

    expect($run['code'])->toBe(1)
        ->and($run['stderr'])->toContain('src/Short.php:8')->toContain('alpaca_bot/fixture/short')->toContain('2 argument')->toContain('1 @param');
});

it('fails when the docblock has no @since, which every hook of the 0.5 line carries', function (): void {
    $root = hooksDocTree(['NoSince.php' => <<<'PHP'
    <?php
    /**
     * Filters something, undated.
     *
     * @param int $a first
     */
    $x = apply_filters('alpaca_bot/fixture/undated', $a);
    PHP]);

    $run = hooksDoc($root);

    expect($run['code'])->toBe(1)
        ->and($run['stderr'])->toContain('src/NoSince.php:7')->toContain('alpaca_bot/fixture/undated')->toContain('no @since');
});

it('fails when a hook name is not a string literal, so an indirection cannot drop a hook silently', function (): void {
    $root = hooksDocTree(['Indirect.php' => <<<'PHP'
    <?php
    /**
     * Applies whatever hook it was handed.
     *
     * @since 0.5.0
     * @param mixed $value the value
     */
    $x = apply_filters($hook, $value);
    PHP]);

    $run = hooksDoc($root);

    expect($run['code'])->toBe(1)
        ->and($run['stderr'])->toContain('src/Indirect.php:8')->toContain('not a string literal');
});

it('ignores hooks outside the alpaca_bot namespace and the wrapper body in src/Capability.php', function (): void {
    $root = hooksDocTree([
        'Other.php' => <<<'PHP'
        <?php
        $external = apply_filters('http_request_host_is_external', false, $host, $url);
        PHP,
        'Capability.php' => <<<'PHP'
        <?php
        /** @var mixed $filtered */
        $filtered = apply_filters($hook, $default, ...$args);
        PHP,
    ]);

    $run = hooksDoc($root);

    expect($run['code'])->toBe(0, $run['stderr'])
        ->and($run['stdout'])->not->toContain('http_request_host_is_external')
        ->and(substr_count($run['stdout'], "\n| `alpaca_bot/"))->toBe(0);
});

it('orders rows by hook name, not by where the filesystem lists the files', function (): void {
    $docblock = "/**\n * Filters %s.\n *\n * @since 0.5.0\n * @param int \$v value\n */\n";
    $root = hooksDocTree([
        'Zeta.php' => "<?php\n{$docblock}\$a = apply_filters('alpaca_bot/fixture/aaa', \$v);\n",
        'Deep/Alpha.php' => "<?php\n{$docblock}\$z = apply_filters('alpaca_bot/fixture/zzz', \$v);\n",
        'Mid.php' => "<?php\n{$docblock}\$m = apply_filters('alpaca_bot/fixture/mmm', \$v);\n",
    ]);

    $run = hooksDoc($root);

    expect($run['code'])->toBe(0, $run['stderr']);
    preg_match_all('~^\| `(alpaca_bot/[^`]+)`~m', $run['stdout'], $m);
    expect($m[1])->toBe(['alpaca_bot/fixture/aaa', 'alpaca_bot/fixture/mmm', 'alpaca_bot/fixture/zzz'])
        ->and($run['stdout'])->not->toContain($root);
});

it('documents exactly the twenty hooks the plugin ships, the two built through Capability::filtered() included', function (): void {
    $run = hooksDoc(dirname(__DIR__, 2));

    expect($run['code'])->toBe(0, $run['stderr']);
    preg_match_all('~^\| `(alpaca_bot/[^`]+)`~m', $run['stdout'], $m);
    $hooks = array_values(array_unique($m[1]));
    sort($hooks);
    expect($hooks)->toBe([
        'alpaca_bot/abilities',
        'alpaca_bot/admin/menu_capability',
        'alpaca_bot/cap/allowed',
        'alpaca_bot/capability/{route}',
        'alpaca_bot/chat/completed',
        'alpaca_bot/chat/failed',
        'alpaca_bot/chat/started',
        'alpaca_bot/context',
        'alpaca_bot/context/sources',
        'alpaca_bot/message/after_receive',
        'alpaca_bot/message/before_send',
        'alpaca_bot/models',
        'alpaca_bot/provider',
        'alpaca_bot/rate_limit',
        'alpaca_bot/render/allowed_tags',
        'alpaca_bot/rest/controllers',
        'alpaca_bot/shortcode/allow_guests',
        'alpaca_bot/system_prompt',
        'alpaca_bot/toolkits',
        'alpaca_bot/usage/recorded',
    ])->and($run['stdout'])->not->toContain(dirname(__DIR__, 2))->not->toContain('1.0');
});

it('is deterministic, and docs/hooks.md is what it produces', function (): void {
    $first = hooksDoc(dirname(__DIR__, 2));
    $second = hooksDoc(dirname(__DIR__, 2));

    expect($first['code'])->toBe(0, $first['stderr'])
        ->and($second['stdout'])->toBe($first['stdout'])
        ->and(file_get_contents(dirname(__DIR__, 2) . '/docs/hooks.md'))->toBe($first['stdout'], 'docs/hooks.md is stale: run composer docs:hooks');
});
