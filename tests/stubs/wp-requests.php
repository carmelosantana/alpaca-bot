<?php

/**
 * Runtime stand-in for the one Requests class the plugin throws: WebFetchToolkit refuses a
 * redirect to a special-purpose address the way core's WP_Http::validate_redirects() does, by
 * throwing WpOrg\Requests\Exception from the `requests.before_redirect` hook, which WP_Http
 * catches and turns into a WP_Error. Loaded by tests/Pest.php only when core's class is absent,
 * for the same reason as wp-rest.php: wordpress-stubs is PHPStan-only and Brain Monkey stubs
 * functions, not classes. Core's constructor signature (wp-includes/Requests/src/Exception.php).
 */

declare(strict_types=1);

namespace WpOrg\Requests;

if (!class_exists(Exception::class, false)) {
    class Exception extends \Exception
    {
        public function __construct(string $message, private string $type, private mixed $data = null, int $code = 0)
        {
            parent::__construct($message, $code);
        }

        public function getType(): string
        {
            return $this->type;
        }

        public function getData(): mixed
        {
            return $this->data;
        }
    }
}
