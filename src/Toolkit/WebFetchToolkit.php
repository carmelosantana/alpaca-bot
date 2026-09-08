<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Settings\Schema;
use AlpacaBot\Settings\Store;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\Tool;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * `web_fetch(url)`: one public web page as readable text, for a URL the user named.
 *
 * The model chooses the URL, so the fetch is held to what the HTTP API calls safe:
 * wp_http_validate_url() first, which refuses anything but http(s), a URL with credentials, a
 * port outside 80/443/8080, and a host that is or resolves to loopback, a private range, link
 * local (the cloud metadata address) and the rest of the special-purpose space; then
 * wp_safe_remote_get(), which applies the same check to every redirect hop. Both are core's,
 * and both honour core's `http_request_host_is_external` and `http_allowed_safe_ports` filters,
 * so a site that must reach an internal host opts in the way it would for any plugin. The
 * guard is asked here as well as inside the request so that the refusal is the tool's own
 * message, not a WP_Error about a blocked URL, and so that nothing is built for a URL that was
 * never going anywhere.
 *
 * Three limits that are not negotiable from the model's side: the `toolkits.user_agent`
 * setting on every request, so a site owner can name the bot to the servers it visits (a
 * blank setting falls back to the schema's default rather than sending an empty header);
 * five seconds, so a slow host cannot hold a chat turn for the provider timeout on top of its
 * own; and 1 MiB on the wire, which the HTTP API enforces as it reads. What comes back is
 * reduced to text: script, style and noscript blocks go whole, block-level closes become
 * paragraph breaks, tags are stripped, entities decoded, and the result is cut at MAX_CHARS
 * characters (not bytes) with a marker. A response whose Content-Type is not a text type is
 * refused outright: 1 MiB of a PDF or an image stripped of "tags" is noise the model would
 * have to pay for in context, and it would tell the user nothing.
 *
 * Nothing here executes what it fetched, and nothing the model sends reaches the shell or
 * eval(): the page is text in, text out (wordpress.org guideline 8).
 *
 * The tool's description and guidelines are English on purpose: they are read by the model,
 * not the user, and a description that changed with the site's locale would change what the
 * model does with the tool from one site to the next. The errors are translated, since a later
 * screen shows them to the person who asked.
 *
 * @since 0.5.0
 */
final class WebFetchToolkit implements ToolkitInterface
{
    /** Characters of page text handed back, not bytes: a page in a multibyte script is cut where a reader would be, not mid-character. */
    public const MAX_CHARS = 8000;

    /** Bytes the HTTP API reads before it stops: 1 MiB. */
    public const MAX_BYTES = 1048576;

    /** Seconds one fetch may take, connection and body together. */
    public const TIMEOUT = 5;

    /** Content types past `text/*` that are text: an API answer or a feed a user pasted the URL of. */
    private const TEXT_TYPES = ['application/json', 'application/xml', 'application/xhtml+xml', 'application/rss+xml', 'application/atom+xml', 'application/ld+json'];

    public function __construct(private Store $store) {}

    public function tools(): array
    {
        return [new Tool(
            'web_fetch',
            'Fetch a public web page by URL and return its readable text, HTML removed, cut at ' . self::MAX_CHARS . ' characters. Use it for a URL the user gave or clearly asked you to look up.',
            [new StringParameter('url', 'The absolute http(s) URL to fetch.')],
            fn(array $args): ToolResult => $this->fetch((string) $args['url']),
        )];
    }

    public function guidelines(): string
    {
        return 'Use web_fetch only for URLs the user provided or clearly asked you to look up; never guess a URL. The text is cut at ' . self::MAX_CHARS . ' characters, so say so if a page looks incomplete. Quote sparingly and name the URL when you rely on it.';
    }

    private function fetch(string $url): ToolResult
    {
        $safe = wp_http_validate_url(trim($url));
        if (!is_string($safe)) {
            return ToolResult::error(__('That URL is not allowed: only public http(s) addresses can be fetched, never a private, local, or malformed one.', 'alpaca-bot'));
        }
        $userAgent = trim((string) $this->store->get('toolkits.user_agent'));
        if ($userAgent === '') {
            $userAgent = (string) Schema::defaults()['toolkits.user_agent'];
        }
        $response = wp_safe_remote_get($safe, [
            'user-agent' => $userAgent,
            'timeout' => self::TIMEOUT,
            'redirection' => 3,
            'limit_response_size' => self::MAX_BYTES,
            // wp_safe_remote_get() sets this itself; spelled out so the intent is in one place.
            'reject_unsafe_urls' => true,
        ]);
        if (is_wp_error($response)) {
            /* translators: %s: the HTTP API's error message */
            return ToolResult::error(sprintf(__('The page could not be fetched: %s', 'alpaca-bot'), $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 400 || $code < 200) {
            /* translators: 1: HTTP status code, 2: the URL */
            return ToolResult::error(sprintf(__('HTTP %1$d from %2$s', 'alpaca-bot'), $code, $safe));
        }
        $type = strtolower(trim((string) explode(';', (string) wp_remote_retrieve_header($response, 'content-type'))[0]));
        if ($type !== '' && !str_starts_with($type, 'text/') && !in_array($type, self::TEXT_TYPES, true)) {
            /* translators: %s: the response's content type, e.g. application/pdf */
            return ToolResult::error(sprintf(__('That URL is not a text page (it answered %s), so there is nothing to read from it.', 'alpaca-bot'), $type));
        }
        $text = self::text((string) wp_remote_retrieve_body($response));
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . '…';
        }
        return ToolResult::success($text);
    }

    /**
     * The readable text of a page. A block-level close becomes a paragraph break before the
     * tags go, so headings, paragraphs, list items and table rows keep their separation
     * instead of running into one line; runs of blank lines collapse to one paragraph break.
     */
    private static function text(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('#</(p|div|h[1-6]|li|tr|blockquote|pre|section|article|header|footer|title)\s*>|<br\s*/?>#i', "\n\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($html, false), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(static fn(string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)), explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));
        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }
}
