<?php

declare(strict_types=1);

// Define WP constants
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('OPENSSL_RAW_DATA')) {
    define('OPENSSL_RAW_DATA', 1);
}

// Composer autoloader to load the src/ classes
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Manually require the base test case so we don't need composer autoload-dev
require_once __DIR__ . '/AbstractTestCase.php';

// --- WordPress Function Stubs ---
// Because we aren't using Brain\Monkey, we define them globally.
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        return 'test-salt-value-for-unit-testing-only';
    }
}

if (!function_exists('get_option')) {
    // We will use a global variable to fake options for tests if needed.
    $GLOBALS['wpoc_mock_options'] = [];
    function get_option(string $option, $default = false) {
        return $GLOBALS['wpoc_mock_options'][$option] ?? $default;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return strip_tags(trim($str));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string {
        return stripslashes($value);
    }
}

if (!function_exists('absint')) {
    function absint($maybeint): int {
        return abs((int) $maybeint);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0): string {
        return (string) json_encode($data, $options);
    }
}

if (!function_exists('wp_remote_request')) {
    $GLOBALS['wpoc_remote_request_callback'] = null;
    $GLOBALS['wpoc_remote_request_log'] = [];

    function wp_remote_request(string $url, array $args = []) {
        $GLOBALS['wpoc_remote_request_log'][] = [
            'url'  => $url,
            'args' => $args,
        ];

        if (is_callable($GLOBALS['wpoc_remote_request_callback'])) {
            return $GLOBALS['wpoc_remote_request_callback']($url, $args);
        }

        return [
            'response' => ['code' => 200],
            'body'     => '{}',
        ];
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private string $message;

        public function __construct(string $code = '', string $message = '') {
            $this->message = $message !== '' ? $message : $code;
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int {
        return (int) ($response['response']['code'] ?? 0);
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string {
        return (string) ($response['body'] ?? '');
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }
}

if (!function_exists('delete_transient')) {
    $GLOBALS['wpoc_deleted_transients'] = [];

    function delete_transient(string $key): bool {
        $GLOBALS['wpoc_deleted_transients'][] = $key;
        return true;
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string {
        return 'http://example.test/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('get_users')) {
    $GLOBALS['wpoc_mock_users'] = [];

    function get_users(array $args = []): array {
        return $GLOBALS['wpoc_mock_users'];
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private int $status;

        public function __construct($data = null, int $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $headers;
        private string $body;
        private array $jsonParams;
        private array $params;

        public function __construct(array $headers = [], string $body = '', array $jsonParams = [], array $params = []) {
            $this->headers    = $headers;
            $this->body       = $body;
            $this->jsonParams = $jsonParams;
            $this->params     = $params;
        }

        public function get_header(string $name): string {
            return (string) ($this->headers[$name] ?? '');
        }

        public function get_body(): string {
            return $this->body;
        }

        public function get_json_params(): array {
            return $this->jsonParams;
        }

        public function get_param(string $name) {
            return $this->params[$name] ?? null;
        }
    }
}
