<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "FAIL WordPress core is not bootstrapped\n");
    exit(1);
}

function prstudio_real_fail(string $message): void {
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}
function prstudio_real_pass(string $message): void {
    fwrite(STDOUT, "PASS {$message}\n");
}

$active = (array)get_option('active_plugins', []);
if (!in_array('prstudio-unified-control/prstudio-unified-control.php', $active, true)) {
    prstudio_real_fail('PR STUDIO plugin is not active in WordPress');
}
prstudio_real_pass('plugin is active in the real WordPress plugin registry');

if (!defined('PRSTUDIO_UC_VERSION') || PRSTUDIO_UC_VERSION !== '1.0.0') {
    prstudio_real_fail('plugin bootstrap/version constant did not load');
}
if (!class_exists('PRSTUDIO_UC_Autoload')) {
    prstudio_real_fail('class-map autoloader did not load');
}
$autoload = PRSTUDIO_UC_Autoload::verify();
if (empty($autoload['ok']) || !empty($autoload['missing'])) {
    prstudio_real_fail('autoload verification failed: ' . wp_json_encode($autoload));
}
prstudio_real_pass('all mapped plugin classes resolve to readable files');

if (!class_exists('PRSTUDIO_UC_MCP_V5')) {
    prstudio_real_fail('MCP runtime class cannot be autoloaded inside WordPress');
}
$tools = PRSTUDIO_UC_MCP_V5::tools();
if (!is_array($tools) || count($tools) !== 120) {
    prstudio_real_fail('MCP runtime did not expose exactly 120 tools inside WordPress');
}
$names = array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $tools);
if (count(array_unique($names)) !== 120 || in_array('', $names, true)) {
    prstudio_real_fail('MCP runtime tool names are missing or duplicated');
}
foreach (['browser_live_attach','browser_live_signal','browser_live_stop','browser_live_status'] as $removed) {
    if (in_array($removed, $names, true)) {
        prstudio_real_fail('removed Browser LIVE tool is still registered: ' . $removed);
    }
}
prstudio_real_pass('MCP runtime exposes 120 unique current tools inside WordPress');

$server = rest_get_server();
if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}
$routes = $server->get_routes();
foreach (['/prstudio-unified/v1/mcp','/prstudio-unified/v1/oauth/register','/prstudio-unified/v1/oauth/token'] as $route) {
    if (!isset($routes[$route])) {
        prstudio_real_fail('REST route not registered: ' . $route);
    }
}
prstudio_real_pass('MCP and OAuth REST routes are registered by WordPress');

$request = new WP_REST_Request('GET', '/prstudio-unified/v1/mcp');
$response = rest_do_request($request);
$status = (int)$response->get_status();
$data = $response->get_data();
if ($status !== 401 || !is_array($data) || empty($data['error'])) {
    prstudio_real_fail('unauthenticated MCP route did not execute its real auth boundary: status=' . $status . ' body=' . wp_json_encode($data));
}
prstudio_real_pass('real MCP REST dispatch reaches the OAuth boundary without fatal errors');

fwrite(STDOUT, "OK real WordPress activation/MCP probe\n");
