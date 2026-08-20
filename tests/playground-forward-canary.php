<?php
/** WordPress Playground forward-compatibility oracle. */
declare(strict_types=1);

$configuredRoot = rtrim((string) getenv('RP_WP_PATH'), "/\\");
$root = $configuredRoot !== '' ? $configuredRoot . '/' : '/wordpress/';
if (!is_file($root . 'wp-load.php')) {
    fwrite(STDERR, "FAIL Playground WordPress root missing\n");
    exit(1);
}
require_once $root . 'wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'prstudio-unified-control/prstudio-unified-control.php';
$result = activate_plugin($plugin);
if (is_wp_error($result)) {
    fwrite(STDERR, "FAIL plugin activation: " . $result->get_error_message() . "\n");
    exit(1);
}
if (!is_plugin_active($plugin)) {
    fwrite(STDERR, "FAIL plugin is not active after activation call\n");
    exit(1);
}
if (!class_exists('PRSTUDIO_UC_Store')) {
    fwrite(STDERR, "FAIL PRSTUDIO_UC_Store did not load\n");
    exit(1);
}
if (!PRSTUDIO_UC_Store::maybe_upgrade()) {
    fwrite(STDERR, "FAIL store/schema migration did not complete\n");
    exit(1);
}

// Trigger the normal REST registration lifecycle and inspect WordPress' own
// route registry rather than looking for source strings.
do_action('rest_api_init');
$routes = rest_get_server()->get_routes();
$required = [
    '/prstudio-unified/v1/mcp',
    '/prstudio-unified/v1/pair',
];
foreach ($required as $route) {
    if (!array_key_exists($route, $routes)) {
        fwrite(STDERR, "FAIL missing registered REST route {$route}\n");
        exit(1);
    }
}

$version = get_bloginfo('version');
$php = PHP_VERSION;
$errors = [];
foreach (error_get_last() ? [error_get_last()] : [] as $error) {
    if (is_array($error) && in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $errors[] = $error;
    }
}
if ($errors) {
    fwrite(STDERR, "FAIL fatal error recorded: " . wp_json_encode($errors) . "\n");
    exit(1);
}

echo wp_json_encode([
    'ok' => true,
    'wordpress_version' => $version,
    'php_version' => $php,
    'plugin_active' => true,
    'schema_upgrade' => true,
    'required_routes' => $required,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
