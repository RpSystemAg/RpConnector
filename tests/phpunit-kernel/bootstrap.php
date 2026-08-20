<?php
declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', dirname(__DIR__, 2) . '/');
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);

if (!function_exists('sanitize_key')) {
    function sanitize_key($value): string {
        $value = strtolower((string)$value);
        return preg_replace('/[^a-z0-9_\-]/', '', $value) ?: '';
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($value): string {
        $value = strtolower(trim((string)$value));
        return trim(preg_replace('/[^a-z0-9]+/', '-', $value) ?: '', '-');
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($value): string { return strip_tags((string)$value); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string { return 'https://suite.italia.test' . ('/' === $path ? '/' : $path); }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string { return 'https://suite.italia.test/wp-json/' . ltrim($path, '/'); }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'https://suite.italia.test/wp-admin/' . ltrim($path, '/'); }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string { return rtrim($value, '/'); }
}
if (!function_exists('post_type_exists')) {
    function post_type_exists(string $type): bool { return in_array($type, array('post', 'page'), true); }
}

if (!class_exists('WP_Error')) {
    final class WP_Error {
        private string $code;
        private string $message;
        private $data;
        public function __construct(string $code = '', string $message = '', $data = null) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    echo "PHPUNIT KERNEL BOOTSTRAP: PASS\n";
}
