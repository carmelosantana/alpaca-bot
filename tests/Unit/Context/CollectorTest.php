<?php

declare(strict_types=1);

use AlpacaBot\Context\Collector;
use AlpacaBot\Context\Context;
use AlpacaBot\Context\ContextSourceInterface;
use Brain\Monkey\Filters;

// collectorSource() lives in tests/Pest.php: a throwaway source with a fixed id and payload.

it('collects from every source and applies the alpaca_bot/context filter', function (): void {
    $a = collectorSource('a', [new Context('a:1', 'A', 'alpha')]);
    $b = collectorSource('b', []);
    Filters\expectApplied('alpaca_bot/context/sources')->once()->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/context')->once()->andReturnFirstArg();
    $c = new Collector([$a, $b]);
    $out = $c->collect(1, []);
    expect($out)->toHaveCount(1)->and($out[0]->text)->toBe('alpha');
    expect(Collector::asSystemBlock($out))->toBe("Context:\n## A\nalpha");
    expect(Collector::asSystemBlock([]))->toBe('');
});

it('hands both filters the user id and the request, in source order', function (): void {
    $request = ['screen' => 'post', 'post_id' => '12'];
    $a = collectorSource('a', [new Context('a:1', 'A', 'alpha'), new Context('a:2', 'A2', 'alpha two')]);
    $b = collectorSource('b', [new Context('b:1', 'B', 'beta')]);
    Filters\expectApplied('alpaca_bot/context/sources')->once()->with([$a, $b], 7, $request)->andReturnFirstArg();
    Filters\expectApplied('alpaca_bot/context')->once()->with(Mockery::type('array'), 7, $request)->andReturnFirstArg();
    $out = (new Collector([$a, $b]))->collect(7, $request);
    expect(array_map(static fn(Context $c): string => $c->id, $out))->toBe(['a:1', 'a:2', 'b:1']);
});

it('lets alpaca_bot/context/sources add a source and remove one', function (): void {
    $shipped = collectorSource('shipped', [new Context('shipped:1', 'Shipped', 'from the shipped source')]);
    $plugin = collectorSource('plugin', [new Context('plugin:1', 'Plugin', 'from a third-party source')]);
    Filters\expectApplied('alpaca_bot/context/sources')->once()->andReturnUsing(
        static function (array $sources) use ($plugin): array {
            $kept = array_filter($sources, static fn(ContextSourceInterface $s): bool => $s->id() !== 'shipped');
            return [...$kept, $plugin];
        }
    );
    $out = (new Collector([$shipped]))->collect(1, []);
    expect($out)->toHaveCount(1)->and($out[0]->id)->toBe('plugin:1');
});

it('lets alpaca_bot/context rewrite the collected list', function (): void {
    $a = collectorSource('a', [new Context('a:1', 'A', 'alpha')]);
    Filters\expectApplied('alpaca_bot/context')->once()->andReturnUsing(
        static fn(array $contexts): array => [...$contexts, new Context('site:1', 'Site', 'added by a filter')]
    );
    $out = (new Collector([$a]))->collect(1, []);
    expect($out)->toHaveCount(2)->and($out[1]->label)->toBe('Site');
});

it('drops anything a filter returns that is not a source or a context', function (): void {
    $a = collectorSource('a', [new Context('a:1', 'A', 'alpha')]);
    Filters\expectApplied('alpaca_bot/context/sources')->once()->andReturnUsing(
        static fn(array $sources): array => [...$sources, 'not a source', null]
    );
    Filters\expectApplied('alpaca_bot/context')->once()->andReturnUsing(
        static fn(array $contexts): array => [...$contexts, ['id' => 'x'], 'text']
    );
    $out = (new Collector([$a]))->collect(1, []);
    expect($out)->toHaveCount(1)->and($out[0])->toBeInstanceOf(Context::class)->and($out[0]->id)->toBe('a:1');
});

it('skips a source that throws and still collects from the others', function (): void {
    $a = collectorSource('a', [new Context('a:1', 'A', 'alpha')]);
    $bad = new class implements ContextSourceInterface {
        public function id(): string
        {
            return 'bad';
        }

        public function collect(int $userId, array $request): array
        {
            throw new RuntimeException('database gone away');
        }
    };
    $b = collectorSource('b', [new Context('b:1', 'B', 'beta')]);
    $out = (new Collector([$a, $bad, $b]))->collect(1, []);
    expect(array_map(static fn(Context $c): string => $c->id, $out))->toBe(['a:1', 'b:1']);
});

it('skips a source that raises an Error, not only an Exception', function (): void {
    $bad = new class implements ContextSourceInterface {
        public function id(): string
        {
            return 'bad';
        }

        public function collect(int $userId, array $request): array
        {
            return [strlen([])]; // a TypeError, on purpose
        }
    };
    $b = collectorSource('b', [new Context('b:1', 'B', 'beta')]);
    $out = (new Collector([$bad, $b]))->collect(1, []);
    expect($out)->toHaveCount(1)->and($out[0]->id)->toBe('b:1');
});

it('add() appends a source after the constructor ones', function (): void {
    $a = collectorSource('a', [new Context('a:1', 'A', 'alpha')]);
    $b = collectorSource('b', [new Context('b:1', 'B', 'beta')]);
    $c = new Collector([$a]);
    $c->add($b);
    $out = $c->collect(1, []);
    expect(array_map(static fn(Context $x): string => $x->id, $out))->toBe(['a:1', 'b:1']);
});

it('renders several contexts as one block with a blank line between them', function (): void {
    $block = Collector::asSystemBlock([
        new Context('a:1', 'A', "alpha\nline two"),
        new Context('b:1', 'B', 'beta'),
    ]);
    expect($block)->toBe("Context:\n## A\nalpha\nline two\n\n## B\nbeta");
});

it('Context::toArray exposes id, label, text and meta', function (): void {
    $c = new Context('a:1', 'A', 'alpha', ['post_id' => 12]);
    expect($c->toArray())->toBe(['id' => 'a:1', 'label' => 'A', 'text' => 'alpha', 'meta' => ['post_id' => 12]]);
    expect((new Context('b:1', 'B', 'beta'))->toArray()['meta'])->toBe([]);
});
