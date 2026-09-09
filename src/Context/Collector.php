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
 * the request, and a source that throws is skipped for the same reason: less context is a
 * worse answer, no answer is a broken chat.
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
        /**
         * Filters the context sources asked for one chat turn, before any of them runs. Add a
         * ContextSourceInterface to feed the model something of the site's own (a product record,
         * a knowledge-base hit), or remove one of the plugin's to keep it out. Anything that is not
         * a source is dropped, and a source that throws is skipped: less context is a worse answer,
         * a broken chat is no answer.
         *
         * @since 0.5.0
         * @param ContextSourceInterface[] $sources the registered sources, in registration order
         * @param int                      $userId  the user whose turn it is
         * @param array<string, mixed>     $request the caller's `context` option: the REST `context` object as sent (the admin screen passes the post being edited), empty from the shortcodes and the abilities
         * @var mixed $sources what the filter returned, checked before it is trusted
         */
        $sources = apply_filters('alpaca_bot/context/sources', $this->sources, $userId, $request);
        $out = [];
        foreach (is_array($sources) ? $sources : [] as $source) {
            if (!$source instanceof ContextSourceInterface) {
                continue;
            }
            try {
                $contexts = $source->collect($userId, $request);
            } catch (\Throwable) {
                continue;
            }
            foreach ($contexts as $context) {
                $out[] = $context;
            }
        }
        /**
         * Filters the Context values collected for the turn, after every source has run and before
         * they are appended to the system prompt. Edit, reorder, drop or add here; anything that is
         * not a Context is dropped. This is where a site trims what a source gathered, budgets the
         * block as a whole (the collector sets no ceiling of its own), or blanks it for a user who
         * must not see it.
         *
         * @since 0.5.0
         * @param Context[]            $contexts what the sources collected, in source order
         * @param int                  $userId   the user whose turn it is
         * @param array<string, mixed> $request  the caller's `context` option, as `alpaca_bot/context/sources` saw it
         * @var mixed $contexts what the filter returned, checked before it is trusted
         */
        $contexts = apply_filters('alpaca_bot/context', $out, $userId, $request);
        return array_values(array_filter(
            is_array($contexts) ? $contexts : [],
            static fn(mixed $c): bool => $c instanceof Context,
        ));
    }

    /**
     * The collected contexts as one block for the system prompt, or '' when there are none.
     *
     * There is no ceiling on the block: each source bounds its own text (CurrentScreenSource
     * at 4,000 characters) but nothing bounds the sum, so a filter that adds sources grows the
     * prompt by that much. The consumer folding this into a prompt owns any global budget.
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
