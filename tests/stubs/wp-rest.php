<?php

/**
 * Runtime stand-ins for the WordPress REST classes the unit suite touches. wordpress-stubs
 * is PHPStan-only (declared in bootstrapFiles, never autoloaded) and Brain Monkey stubs functions,
 * not classes, so without these `new WP_Error()` inside Rest\Errors is a fatal in the unit run.
 * tests/Pest.php requires this file only when the real classes are absent; inside a WordPress
 * process (the integration suite) core's own classes win and this file is never loaded.
 *
 * Only the members the plugin calls are modelled, with core's signatures and semantics
 * (wp-includes/class-wp-error.php, class-wp-http-response.php, rest-api/class-wp-rest-request.php,
 * rest-api/class-wp-rest-response.php, rest-api/class-wp-rest-server.php), so a test that passes
 * here does not depend on a method core lacks or a behaviour core does not have. The one
 * departure is WP_REST_Server::send_header(), which records instead of calling header(): under
 * the CLI SAPI header() is a silent no-op, and what StreamController::serve() sent is the point
 * of its tests. tests/Unit/Rest/WpRestStubTest.php pins the request semantics that are easiest
 * to get wrong.
 */

declare(strict_types=1);

if (!class_exists('WP_Error', false)) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '', public mixed $data = null) {}

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(string $code = ''): string
        {
            return $this->message;
        }

        public function get_error_data(string $code = ''): mixed
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Request', false)) {
    /**
     * Core's constructor takes ($method, $route, $attributes): the third argument is the route's
     * registration options, not parameters. Parameters arrive through set_param() (what core does
     * for URL, query and form values) or a JSON body, and get_json_params() is only the parsed
     * body, null when there was none, as in core. A test that hands params to the constructor
     * therefore sees them ignored here exactly as WordPress would ignore them.
     *
     * Where a JSON body and set_param() both carry a key, the body wins in get_param() and
     * get_params(), core's merge order for a JSON request. Not modelled: core's per-source param
     * types (URL/GET/POST/FILES/JSON/defaults), which only matter to code that asks for one source.
     */
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params = [];

        /** @var array<string, string> */
        private array $headers = [];

        private string $body = '';

        /** @param array<string, mixed> $attributes */
        public function __construct(private string $method = '', private string $route = '', private array $attributes = []) {}

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        /** @return array<string, mixed> */
        public function get_attributes(): array
        {
            return $this->attributes;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }

        public function set_body(string $data): void
        {
            $this->body = $data;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function has_param(string $key): bool
        {
            return array_key_exists($key, $this->get_params());
        }

        public function get_param(string $key): mixed
        {
            return $this->get_params()[$key] ?? null;
        }

        /** @return array<string, mixed> */
        public function get_params(): array
        {
            return array_replace($this->params, $this->get_json_params() ?? []);
        }

        /**
         * Core parses the body only when the Content-Type says JSON (application/json or a
         * +json type) and it decodes to an array; anything else leaves the JSON params null.
         *
         * @return array<string, mixed>|null
         */
        public function get_json_params(): ?array
        {
            $type = strtolower((string) $this->get_header('content-type'));
            if ($this->body === '' || !preg_match('#^application/([^;\s]+\+)?json#', $type)) {
                return null;
            }
            $decoded = json_decode($this->body, true);
            return is_array($decoded) ? $decoded : null;
        }
    }
}

if (!class_exists('WP_HTTP_Response', false)) {
    /** The base core's WP_REST_Response extends; what the rest_pre_serve_request filter types its result as. */
    class WP_HTTP_Response
    {
        /** @param array<string, string> $headers */
        public function __construct(public mixed $data = null, public int $status = 200, public array $headers = []) {}

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        public function header(string $key, string $value, bool $replace = true): void
        {
            $this->headers[$key] = $value;
        }

        /** @return array<string, string> */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_REST_Response', false)) {
    class WP_REST_Response extends WP_HTTP_Response
    {
    }
}

if (!class_exists('WP_REST_Server', false)) {
    /** send_header() records [name, value] pairs in order into `$sent` rather than calling header() (see the file docblock). */
    class WP_REST_Server
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function send_header(string $key, string $value): void
        {
            $this->sent[] = [$key, $value];
        }
    }
}
