<?php

declare(strict_types=1);

namespace AlpacaBot;

use AlpacaBot\Toolkit\WebFetchToolkit;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\HttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\NativeHttpClient;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Symfony HTTP client the plugin's own callers are handed: the provider Provider\Factory
 * builds, and the transport under Mcp\Egress's pin. It is the one place that answers "can
 * Symfony's Curl client run here".
 *
 * Symfony's HttpClient::create() picks its Curl client wherever ext-curl is loaded
 * (vendor-prefixed HttpClient.php:52-55), and a shared host that lists curl_init and curl_exec
 * in disable_functions still has it loaded, so there the first request dies with an Error from
 * the missing curl_init() (Kanboard #4690). So two things are asked first, and the Curl client is
 * a candidate only when both answer yes:
 *
 * - web_fetch's question, WebFetchToolkit::curlCarries(false): Requests' Transport\Curl::test()
 *   with no SSL requirement, which is function_exists('curl_init') &&
 *   function_exists('curl_exec') (Curl.php:605), false for a disabled function. Where that says
 *   no, web_fetch refuses as well (it asks the same thing, and for an https page the stricter
 *   question, with SSL), so on the ordinary shared host the two HTTP paths agree.
 * - whether every function Symfony's Curl client calls exists (CURL_FUNCTIONS). It runs on the
 *   multi and share handles and never calls curl_exec, so a host that disables curl_multi_exec,
 *   say, and leaves curl_init and curl_exec alone passes web_fetch's question and would still
 *   die with an Error on the Curl client. There these callers go Native while web_fetch, whose
 *   question is Requests' and stays so, still runs.
 *
 * The agreement runs one way only, and only on that question: wherever web_fetch refuses because
 * curl_init or curl_exec cannot be called, these callers go Native. web_fetch also refuses an
 * https page where libcurl was built without SSL, which is not asked here, so there these callers
 * can still get the Curl client. And they can go Native where web_fetch runs, on the host just
 * described and on Windows with none of `curl.cainfo`, `openssl.cafile` or `openssl.capath` set,
 * where create() itself answers Native or Amp (HttpClient.php:52-66; Egress replaces Amp with
 * Native).
 *
 * Where both say yes the choice is create()'s, unchanged, with the options handed in. Otherwise
 * the client is Symfony's Native client with those same options and create()'s host connection
 * limit of 6 (HttpClient.php:31, :66), never Amp's, which create() can pick when the unprefixed
 * amphp/http-client is loaded (:33-49, :60-61): Egress cannot pin Amp (its docblock says why),
 * and the question here is only whether cURL can be called.
 *
 * @since 0.6.1
 */
final class HttpTransport
{
    /**
     * Every cURL function Symfony's Curl client calls, whether building the client, sending a
     * request or reading its response: each curl_* call in vendor-prefixed symfony/http-client
     * (HttpTransportTest holds this list to that package). curl_share_init_persistent() is called
     * only on PHP 8.5 and later (CurlClientState.php:81-90), where it exists, so it is asked about
     * only there.
     *
     * @var list<string>
     */
    public const CURL_FUNCTIONS = [
        'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_getinfo', 'curl_error', 'curl_strerror', 'curl_pause', 'curl_version',
        'curl_multi_init', 'curl_multi_exec', 'curl_multi_add_handle', 'curl_multi_remove_handle', 'curl_multi_select', 'curl_multi_setopt', 'curl_multi_info_read', 'curl_multi_strerror',
        'curl_share_init', 'curl_share_setopt',
    ];

    /**
     * @param array<string, mixed>        $options Symfony's default request options, as create() takes them
     * @param null|\Closure(bool): bool   $curl    web_fetch's question, whether cURL can carry the request, asked for http (no SSL requirement); WebFetchToolkit::curlCarries() by default, a test hands in its own
     * @param null|\Closure(string): bool $exists  whether a function can be called; function_exists() by default, a test hands in its own to stand in for disable_functions
     */
    public static function create(array $options = [], ?\Closure $curl = null, ?\Closure $exists = null): HttpClientInterface
    {
        return ($curl ?? WebFetchToolkit::curlCarries(...))(false) && self::symfonyCurlRuns($exists ?? function_exists(...))
            ? HttpClient::create($options)
            : new NativeHttpClient($options, 6);
    }

    /** @param \Closure(string): bool $exists */
    private static function symfonyCurlRuns(\Closure $exists): bool
    {
        $needed = PHP_VERSION_ID >= 80500 ? [...self::CURL_FUNCTIONS, 'curl_share_init_persistent'] : self::CURL_FUNCTIONS;
        foreach ($needed as $function) {
            if (!$exists($function)) {
                return false;
            }
        }
        return true;
    }
}
