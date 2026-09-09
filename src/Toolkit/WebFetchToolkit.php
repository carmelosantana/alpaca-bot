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
 * The model chooses the URL, so the fetch is held to two checks in turn, and a URL has to pass
 * both. Core's first: wp_http_validate_url(), which refuses anything but http(s), a URL with
 * credentials, a port outside 80/443/8080, and a host that is or resolves to what the running
 * core version knows to be local; then wp_safe_remote_get(), which applies the same check to
 * every redirect hop. What core knows depends on its version: 6.9, the plugin's floor, refuses
 * loopback, RFC 1918 and 0/8 and passes the link-local address where a cloud instance's
 * metadata service answers with the instance's credentials; 7.1 refuses the IPv4 registry; no
 * version reads an AAAA record. So the plugin's own check runs second, on the URL core returned
 * and on every redirect target (through the `requests.before_redirect` action core fires for
 * its own redirect validation): SpecialPurposeAddress, both families, over every address a
 * name resolves to. Two of core's rules are kept on purpose, and hostIsPublic() says what each
 * costs: the site's own host is exempt, and `http_request_host_is_external` opts a host in for
 * this tool as it does for any plugin; `http_allowed_safe_ports` is core's and stays core's.
 * Core's check is asked here as well as inside the request so that the refusal is the tool's
 * own message, not a WP_Error about a blocked URL, and so that nothing is built for a URL that
 * was never going anywhere.
 *
 * What neither check covers, on any core version: a name whose answer changes between the
 * check and the connection. wp_http_validate_url() resolves the host, this class resolves it
 * again, and the transport resolves it once more when it connects; each is a separate lookup,
 * and a name under an attacker's control with a short TTL can answer the checks with a public
 * address and the connection with 127.0.0.1 or the metadata address (DNS rebinding). What that
 * buys the attacker is a GET from the web server's host to whatever that host can reach, with
 * the response text handed to the model: an internal dashboard, or on a cloud instance the
 * metadata service and, under IMDSv1, the instance's credentials, which is everything the
 * address table exists to refuse. Closing it means resolving once and connecting to that
 * address (a transport that pins the resolved IP, CURLOPT_RESOLVE through `http_api_curl`, and
 * an answer for the fsockopen transport), which is a compatibility risk across transports and
 * a piece of work of its own, so it is not done here. An operator who needs it closed closes
 * it where it holds for every plugin at once: an egress policy at the network, so the web
 * server's host cannot open a connection to the metadata address or the private ranges
 * whatever name it resolved.
 *
 * Three limits that are not negotiable from the model's side: the `toolkits.user_agent`
 * setting on every request, so a site owner can name the bot to the servers it visits (a
 * blank setting falls back to the schema's default rather than sending an empty header);
 * five seconds, so a slow host cannot hold a chat turn for the provider timeout on top of its
 * own; and 1 MiB on the wire, which the HTTP API enforces as it reads. What comes back is
 * read in the charset it declares (utf8() says how that is decided), reduced to text in time
 * linear in its size (text() says how, and why not wp_strip_all_tags()), and cut at MAX_CHARS
 * characters (not bytes) with a marker. A response whose Content-Type is not a text type is
 * refused outright: 1 MiB of a PDF or an image stripped of "tags" is noise the model would
 * have to pay for in context, and it would tell the user nothing. A page PCRE gives up on is
 * refused too, naming the reason, never returned as the part that survived.
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

    /**
     * @param null|\Closure(string): list<string> $resolver every address a host name answers with, A and AAAA, or [] when it does not resolve or a lookup failed; the default is resolve() over the system resolver, a test hands in its own
     */
    public function __construct(private Store $store, private ?\Closure $resolver = null) {}

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
        if (!is_string($safe) || !$this->hostIsPublic($safe)) {
            return ToolResult::error(__('That URL is not allowed: only public http(s) addresses can be fetched, never a private, local, or malformed one.', 'alpaca-bot'));
        }
        $userAgent = trim((string) $this->store->get('toolkits.user_agent'));
        if ($userAgent === '') {
            $userAgent = (string) Schema::defaults()['toolkits.user_agent'];
        }
        // Every redirect hop through the same check. Core registers its own validate_redirects()
        // on the Requests hook and re-fires it as this action, after its own callback has run.
        $guard = function (string $location): void {
            if (!$this->hostIsPublic($location)) {
                throw new \WpOrg\Requests\Exception(__('The page redirected to an address that is not allowed.', 'alpaca-bot'), 'alpaca_bot.redirect_refused');
            }
        };
        add_action('requests-requests.before_redirect', $guard);
        try {
            $response = wp_safe_remote_get($safe, [
                'user-agent' => $userAgent,
                'timeout' => self::TIMEOUT,
                'redirection' => 3,
                'limit_response_size' => self::MAX_BYTES,
                // wp_safe_remote_get() sets this itself; spelled out so the intent is in one place.
                'reject_unsafe_urls' => true,
            ]);
        } finally {
            remove_action('requests-requests.before_redirect', $guard);
        }
        if (is_wp_error($response)) {
            /* translators: %s: the HTTP API's error message */
            return ToolResult::error(sprintf(__('The page could not be fetched: %s', 'alpaca-bot'), $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 400 || $code < 200) {
            /* translators: 1: HTTP status code, 2: the URL */
            return ToolResult::error(sprintf(__('HTTP %1$d from %2$s', 'alpaca-bot'), $code, $safe));
        }
        $header = (string) wp_remote_retrieve_header($response, 'content-type');
        $type = strtolower(trim(explode(';', $header)[0]));
        if ($type !== '' && !str_starts_with($type, 'text/') && !in_array($type, self::TEXT_TYPES, true)) {
            /* translators: %s: the response's content type, e.g. application/pdf */
            return ToolResult::error(sprintf(__('That URL is not a text page (it answered %s), so there is nothing to read from it.', 'alpaca-bot'), $type));
        }
        try {
            $text = self::text(self::utf8((string) wp_remote_retrieve_body($response), $header));
        } catch (\UnexpectedValueException $e) {
            /* translators: %s: why the page's bytes could not be read, e.g. a charset name or a PCRE error */
            return ToolResult::error(sprintf(__('The page could not be read as text: %s', 'alpaca-bot'), $e->getMessage()));
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . '…';
        }
        return ToolResult::success($text);
    }

    /**
     * Whether the URL's host is somewhere a model-chosen fetch may go: an address in none of
     * SpecialPurposeAddress::RANGES, or a name every one of whose A and AAAA answers is. A name
     * that does not resolve is refused (the fetch would fail anyway, and core below 7.1 lets it
     * through), and so is one whose lookup failed (resolve() says why that is not the same
     * thing). The site's own host is exempt, as it is in core: a local site resolves to a
     * private address and can still read its own pages. The cost of the exemption is core's
     * too: whatever else listens on that host on 80, 443 or 8080 is reachable. A special-purpose
     * address a site has opted in through core's `http_request_host_is_external` filter is
     * allowed here as well, so that opt-in means the same thing for this tool as for any plugin.
     */
    private function hostIsPublic(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '.'));
        if ($host === '') {
            return false;
        }
        if ($host === strtolower((string) parse_url(home_url(), PHP_URL_HOST))) {
            return true;
        }
        $literal = trim($host, '[]');
        $addresses = SpecialPurposeAddress::isAddress($literal) ? [$literal] : ($this->resolver ?? self::resolve(...))($host);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (!SpecialPurposeAddress::isAddress($address)) {
                return false;
            }
            if (SpecialPurposeAddress::match($address) !== null && !apply_filters('http_request_host_is_external', false, $host, $url)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Every address a name answers with, both families, through the system resolver: A through
     * gethostbynamel(), AAAA through dns_get_record(). The connection will use whichever the
     * transport prefers, so both have to be looked at. Cost: two lookups on top of the one
     * wp_http_validate_url() already made, normally answered from the resolver's cache since it
     * just made the first; a resolver that times out costs its timeout. dns_get_record() warns
     * as well as returning false on a failed query, and a warning here is a refused fetch, not
     * an error worth logging, hence the suppression.
     *
     * A lookup that failed is not a lookup that answered "none". Either function returns false
     * when the query could not be made (a timeout, a nameserver that drops the question), and
     * that is answered with an empty list, which hostIsPublic() refuses as it refuses a name that
     * does not resolve. Read as "no records" instead, a false from the AAAA half let a name whose
     * nameserver answers A with a public address and drops AAAA queries past the table on the A
     * alone, and the transport's own lookup, which does read AAAA, then chose the address on a
     * dual-stack host; this class is the one AAAA check in the path (the class docblock), so it
     * is the one that has to hold. What the refusal costs: a legitimate host behind a resolver
     * that fails one of the two queries cannot be fetched until the resolver answers, where it
     * was fetched before on whichever half had answered. The A half costs nothing beyond that,
     * since wp_http_validate_url() already refused a host gethostbyname() could not answer.
     *
     * @param null|\Closure(string): (list<string>|false) $a    the A lookup; gethostbynamel() by default
     * @param null|\Closure(string): (array<mixed>|false) $aaaa the AAAA lookup; dns_get_record($host, DNS_AAAA) by default
     * @return list<string> every address, or [] when the name has none or either lookup failed
     * @internal Public only so the tests can hand in the two lookups; not part of the plugin's API.
     */
    public static function resolve(string $host, ?\Closure $a = null, ?\Closure $aaaa = null): array
    {
        $a ??= static fn(string $host): array|false => gethostbynamel($host);
        $aaaa ??= static fn(string $host): array|false => @dns_get_record($host, DNS_AAAA);
        $v4 = $a($host);
        $v6 = $aaaa($host);
        if ($v4 === false || $v6 === false) {
            return [];
        }
        $addresses = array_values(array_filter($v4, 'is_string'));
        foreach ($v6 as $record) {
            if (is_array($record) && is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }
        return $addresses;
    }

    /**
     * The page's bytes as UTF-8, which is what the regexes in text() and the character cut in
     * fetch() require. The charset is the Content-Type header's parameter, else a `<meta>`
     * charset in the first 4 KiB (a browser prescans 1 KiB and revises later; 4 KiB covers the
     * head of nearly every page in one look), else UTF-8 if the bytes are UTF-8, else
     * windows-1252, which is what the HTML standard says an undeclared page is. A page whose
     * bytes contradict a declared UTF-8 keeps every line, with each bad byte replaced by
     * mbstring's substitute character, since dropping the line was the failure this replaces.
     * A charset this PHP cannot convert is a refusal that names it; guessing would hand the
     * model text in the wrong alphabet as if it were the page.
     *
     * @throws \UnexpectedValueException with a message fit for the tool's error
     */
    private static function utf8(string $body, string $contentType): string
    {
        $charset = self::charset($contentType, $body) ?? (mb_check_encoding($body, 'UTF-8') ? 'utf-8' : 'windows-1252');
        if ($charset === 'utf-8' || $charset === 'utf8') {
            return mb_check_encoding($body, 'UTF-8') ? $body : mb_scrub($body, 'UTF-8');
        }
        try {
            $converted = mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (\ValueError) {
            $converted = false;
        }
        if (!is_string($converted)) {
            /* translators: %s: the charset the page declared, e.g. x-mac-roman */
            throw new \UnexpectedValueException(sprintf(__('its charset, %s, is not one this server can convert', 'alpaca-bot'), $charset));
        }
        return $converted;
    }

    /**
     * The charset a page declares, lower-cased, or null: the header's `charset` parameter
     * first, then a `<meta charset>` or `<meta http-equiv>` in the first 4 KiB. The ISO-8859-1
     * family of labels is read as windows-1252, as browsers read it (WHATWG Encoding): pages
     * labelled Latin-1 use the 0x80-0x9F range for the windows-1252 punctuation, which
     * mbstring's ISO-8859-1 would turn into control characters.
     */
    private static function charset(string $contentType, string $body): ?string
    {
        $found = self::pcre(preg_match('/;\s*charset\s*=\s*["\']?\s*([a-z0-9._:-]+)/i', $contentType, $m)) === 1
            || self::pcre(preg_match('/<meta\s[^>]*charset\s*=\s*["\']?\s*([a-z0-9._:-]+)/i', substr($body, 0, 4096), $m)) === 1;
        if (!$found) {
            return null;
        }
        $charset = strtolower($m[1]);
        return in_array($charset, ['iso-8859-1', 'iso8859-1', 'iso_8859-1', 'latin1', 'l1', 'us-ascii', 'ascii', 'cp1252', 'cp-1252', 'x-cp1252'], true) ? 'windows-1252' : $charset;
    }

    /**
     * The readable text of a page. Script, style, noscript and template elements go whole
     * (their content is code, not prose; an unclosed one runs to the end of the page, as a
     * browser reads it), then a block-level close becomes a paragraph break before the tags
     * go, so headings, paragraphs, list items and table rows keep their separation instead of
     * running into one line; runs of blank lines collapse to one paragraph break.
     *
     * Every step is linear in the page: the elements are found by one split on their open and
     * close tags and a walk over the pieces, and the tags go through strip_tags(), a state
     * machine. wp_strip_all_tags() is not used on purpose: its own `<(script|style)…>.*?</\1>`
     * carries the quadratic scan this replaced (15 s at 432 KB of `<script>x` here, with the
     * 1 MiB cap as the bound), and it casts the null PCRE answers when it gives up. Entities are
     * decoded after the tags go, not before: a page that shows markup as text (a code sample
     * on a docs page is `&lt;div&gt;` in the source) would otherwise have that text stripped as
     * markup. So the result can spell `<script>`; it is text, never markup, and whoever renders
     * it escapes it as text, as with any tool result.
     *
     * @throws \UnexpectedValueException when PCRE gave up on the page (see pcre())
     */
    private static function text(string $html): string
    {
        $html = self::withoutContentless($html);
        $html = self::pcre(preg_replace('#</(p|div|h[1-6]|li|tr|blockquote|pre|section|article|header|footer|title)\s*>|<br\s*/?>#i', "\n\n", $html));
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $lines[] = trim(self::pcre(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)));
        }
        return trim(self::pcre(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines))));
    }

    /**
     * The page without its script, style, noscript and template elements: one split on the
     * open and close tags of those four, and a walk that drops every piece from an open tag to
     * the matching close (a nested opener of another kind inside is content of the outer one,
     * as in HTML) or to the end of the page when it never closes.
     */
    private static function withoutContentless(string $html): string
    {
        $parts = self::pcre(preg_split('#(<(?:script|style|noscript|template)\b[^>]*>|</(?:script|style|noscript|template)\s*>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE));
        $out = '';
        $inside = null;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                if ($inside === null) {
                    $out .= $part;
                }
                continue;
            }
            $closing = $part[1] === '/';
            $name = strtolower(rtrim(ltrim($part, '</'), "> \t\r\n"));
            $name = (string) strtok($name, " \t\r\n/>");
            if ($closing) {
                if ($inside === $name) {
                    $inside = null;
                }
            } elseif ($inside === null) {
                $inside = $name;
            }
        }
        return $out;
    }

    /**
     * A preg_* answer, or an exception when PCRE gave up (a match or backtrack limit, a JIT
     * stack limit, bad UTF-8 under `/u`). preg_replace() answers null then, preg_match() false,
     * and preg_split() the pieces it had matched so far with no other sign, so the error code
     * is read after every call: a `(string)` cast over the null, or a walk over the partial
     * split, is a page silently emptied or truncated and reported as read.
     *
     * @template T
     * @param T|null|false $result
     * @return T
     */
    private static function pcre(mixed $result): mixed
    {
        if ($result === null || $result === false || preg_last_error() !== PREG_NO_ERROR) {
            throw new \UnexpectedValueException(preg_last_error_msg());
        }
        return $result;
    }
}
