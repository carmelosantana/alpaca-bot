<?php

/**
 * Runtime stand-ins for the three WordPress REST classes the unit suite touches. wordpress-stubs
 * is PHPStan-only (declared in bootstrapFiles, never autoloaded) and Brain Monkey stubs functions,
 * not classes, so without these `new WP_Error()` inside Rest\Errors is a fatal in the unit run.
 * tests/Pest.php requires this file only when the real classes are absent; inside a WordPress
 * process (the integration suite) core's own classes win and this file is never loaded.
 *
 * Only the members the plugin calls are modelled, with core's signatures (wp-includes/class-wp-error.php,
 * rest-api/class-wp-rest-request.php, rest-api/class-wp-rest-response.php), so a test that
 * passes here does not depend on a method core lacks.
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
    class WP_REST_Request
    {
        /** @param array<string, mixed> $params */
        public function __construct(public string $method = 'GET', public string $route = '', private array $params = []) {}

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        /** @return array<string, mixed> */
        public function get_params(): array
        {
            return $this->params;
        }

        /** @return array<string, mixed> */
        public function get_json_params(): array
        {
            return $this->params;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response', false)) {
    class WP_REST_Response
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
