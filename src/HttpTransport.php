<?php

declare(strict_types=1);

namespace AlpacaBot;

use AlpacaBot\Toolkit\WebFetchToolkit;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\HttpClient;
use AlpacaBot\Vendor\Symfony\Component\HttpClient\NativeHttpClient;
use AlpacaBot\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Symfony HTTP client the plugin's own callers are handed: the provider Provider\Factory
 * builds, and the transport under Mcp\Egress's pin.
 *
 * Symfony's HttpClient::create() picks its Curl client wherever ext-curl is loaded
 * (vendor-prefixed HttpClient.php:52-55), and a shared host that lists curl_init and curl_exec
 * in disable_functions still has it loaded, so there the first request dies with an Error from
 * the missing curl_init() (Kanboard #4690). So cURL is asked about first, and asked the way
 * web_fetch asks it: WebFetchToolkit::curlCarries(false), which is Requests'
 * Transport\Curl::test() with no SSL requirement, and that is function_exists('curl_init') &&
 * function_exists('curl_exec') (Curl.php:605), false for a disabled function. One answer for
 * the whole plugin: wherever these callers go Native, web_fetch refuses as well (it asks the same
 * question, and for an https page the stricter one, with SSL).
 *
 * Where cURL is usable the choice is create()'s, unchanged, with the options handed in. Where it
 * is not, the client is Symfony's Native client with those same options and create()'s host
 * connection limit of 6 (HttpClient.php:31, :66), never Amp's, which create() can pick when the
 * unprefixed amphp/http-client is loaded (:33-49, :60-61): Egress cannot pin Amp (its docblock
 * says why), and the question here is only whether cURL can be called.
 *
 * @since 0.6.1
 */
final class HttpTransport
{
    /**
     * @param array<string, mixed>     $options Symfony's default request options, as create() takes them
     * @param null|\Closure(bool): bool $curl    whether cURL can carry the request, asked for http (no SSL requirement); WebFetchToolkit::curlCarries() by default, a test hands in its own
     */
    public static function create(array $options = [], ?\Closure $curl = null): HttpClientInterface
    {
        return ($curl ?? WebFetchToolkit::curlCarries(...))(false)
            ? HttpClient::create($options)
            : new NativeHttpClient($options, 6);
    }
}
