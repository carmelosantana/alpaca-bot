<?php

declare(strict_types=1);

use AlpacaBot\Toolkit\FirstWins;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/** A toolkit offering one tool per name in `$names`, each answering `$tag:<name>`, that counts how often tools() is asked. */
function firstWinsKit(string $tag, array $names, string $guidelines = 'Use them.'): ToolkitInterface
{
    return new class ($tag, $names, $guidelines) implements ToolkitInterface {
        public int $asked = 0;

        public function __construct(private string $tag, private array $names, private string $guidelines) {}

        public function tools(): array
        {
            ++$this->asked;
            return array_map(fn(string $n): Tool => new Tool($n, 'd', [], fn(array $a): ToolResult => ToolResult::success($this->tag . ':' . $n)), $this->names);
        }

        public function guidelines(): string
        {
            return $this->guidelines;
        }
    };
}

it('leaves out every tool whose name an earlier toolkit offers, keeps the rest in order, and asks each toolkit once', function (): void {
    $builtIn = firstWinsKit('built-in', ['web_fetch', 'ability__core__get-site-info']);
    $remote = firstWinsKit('remote', ['ability__core__get-site-info', 'trk__search']);
    $later = firstWinsKit('later', ['trk__search', 'web_fetch'], 'Later.');
    [$a, $b, $c] = FirstWins::over(['web_fetch' => $builtIn, 'mcp.trk' => $remote, 'site' => $later]);
    $names = static fn(ToolkitInterface $k): array => array_map(static fn($t): string => $t->name(), $k->tools());
    expect($names($a))->toBe(['web_fetch', 'ability__core__get-site-info'])
        ->and($names($b))->toBe(['trk__search'])
        ->and($names($c))->toBe([])
        ->and($b->tools()[0]->execute([])->content)->toBe('remote:trk__search')
        ->and($b->guidelines())->toBe('Use them.')
        ->and($c->guidelines())->toBe('')
        ->and([$builtIn->asked, $remote->asked, $later->asked])->toBe([1, 1, 1]);
});

it('leaves two tools of one toolkit under one name to that toolkit', function (): void {
    [$only] = FirstWins::over([firstWinsKit('one', ['x', 'x'])]);
    expect(array_map(static fn($t): string => $t->name(), $only->tools()))->toBe(['x', 'x']);
});
