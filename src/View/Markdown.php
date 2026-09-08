<?php

declare(strict_types=1);

namespace AlpacaBot\View;

use AlpacaBot\Vendor\League\CommonMark\ConverterInterface;
use AlpacaBot\Vendor\League\CommonMark\Environment\Environment;
use AlpacaBot\Vendor\League\CommonMark\Extension\Autolink\AutolinkExtension;
use AlpacaBot\Vendor\League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use AlpacaBot\Vendor\League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use AlpacaBot\Vendor\League\CommonMark\Extension\Table\TableExtension;
use AlpacaBot\Vendor\League\CommonMark\MarkdownConverter;

/**
 * Model replies as HTML. The text comes from a model, which is to say from whoever wrote the
 * prompt, so it is treated as hostile twice over: the CommonMark environment strips raw HTML,
 * refuses javascript:/data: links and caps nesting (a deeply nested list is a stack-depth
 * attack on the parser), and the result still goes through wp_kses against a small
 * allowlist, so an extension bug cannot let anything new through. The allowlist keeps
 * `class` on code and pre because the highlighter keys off `language-*`.
 */
final class Markdown
{
    private ConverterInterface $converter;

    public function __construct(?ConverterInterface $converter = null)
    {
        if ($converter === null) {
            $env = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 50]);
            $env->addExtension(new CommonMarkCoreExtension());
            $env->addExtension(new TableExtension());
            $env->addExtension(new StrikethroughExtension());
            $env->addExtension(new AutolinkExtension());
            $converter = new MarkdownConverter($env);
        }
        $this->converter = $converter;
    }

    public function toHtml(string $markdown): string
    {
        $html = (string) $this->converter->convert($markdown);
        return wp_kses($html, self::allowedTags());
    }

    /**
     * The wp_kses allowlist, after the `alpaca_bot/render/allowed_tags` filter, which is the
     * seam for a site that wants (say) `<kbd>` or a `title` on links.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowedTags(): array
    {
        $attrs = ['class' => true];
        $tags = ['p' => [], 'br' => [], 'strong' => [], 'em' => [], 'del' => [], 'code' => $attrs, 'pre' => $attrs, 'blockquote' => [], 'ul' => [], 'ol' => ['start' => true], 'li' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'hr' => [], 'a' => ['href' => true, 'rel' => true, 'target' => true], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['align' => true], 'td' => ['align' => true], 'img' => ['src' => true, 'alt' => true]];
        return (array) apply_filters('alpaca_bot/render/allowed_tags', $tags);
    }
}
