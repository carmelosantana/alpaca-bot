<?php

declare(strict_types=1);

namespace AlpacaBot\Context;

/**
 * Gathers Context from every registered source for one chat turn.
 *
 * Two filters make the seam public: `alpaca_bot/context/sources` (ContextSourceInterface[],
 * userId, request) lets a plugin add or remove sources before they run, and
 * `alpaca_bot/context` (Context[], userId, request) lets it edit what was collected. Anything
 * a filter returns that is not a source or a Context is dropped rather than allowed to fatal
 * the request.
 */
final class Collector
{
    /** @param ContextSourceInterface[] $sources */
    public function __construct(private array $sources = []) {}

    public function add(ContextSourceInterface $s): void
    {
        $this->sources[] = $s;
    }

    /**
     * @param array<string, mixed> $request
     * @return Context[]
     */
    public function collect(int $userId, array $request): array
    {
        $sources = apply_filters('alpaca_bot/context/sources', $this->sources, $userId, $request);
        $out = [];
        foreach (is_array($sources) ? $sources : [] as $source) {
            if (!$source instanceof ContextSourceInterface) {
                continue;
            }
            foreach ($source->collect($userId, $request) as $context) {
                $out[] = $context;
            }
        }
        $contexts = apply_filters('alpaca_bot/context', $out, $userId, $request);
        return array_values(array_filter(
            is_array($contexts) ? $contexts : [],
            static fn(mixed $c): bool => $c instanceof Context,
        ));
    }

    /**
     * The collected contexts as one block for the system prompt, or '' when there are none.
     *
     * @param Context[] $contexts
     */
    public static function asSystemBlock(array $contexts): string
    {
        if ($contexts === []) {
            return '';
        }
        return "Context:\n" . implode("\n\n", array_map(
            static fn(Context $c): string => "## {$c->label}\n{$c->text}",
            $contexts,
        ));
    }
}
