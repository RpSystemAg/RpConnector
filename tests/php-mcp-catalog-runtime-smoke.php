<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';

function fail(string $message): void { fwrite(STDERR, "FAIL {$message}\n"); exit(1); }
function pass(string $message): void { fwrite(STDOUT, "PASS {$message}\n"); }

try {
    $tools = PRSTUDIO_UC_MCP_V5::tools();
} catch (Throwable $e) {
    fail('MCP tools() must execute without Throwable: ' . get_class($e) . ': ' . $e->getMessage());
}
if (!is_array($tools)) fail('tools() must return array');
// PR31 intentionally removed the four Browser LIVE/WebRTC tools from the
// 124-tool catalogue. The public runtime therefore has exactly 120 tools.
// Keep this exact invariant coupled to the uniqueness and removal checks below.
if (count($tools) !== 120) fail('expected 120 tools, got ' . count($tools));
$names = [];
foreach ($tools as $index => $tool) {
    if (!is_array($tool)) fail("tool {$index} is not array");
    foreach (['name','description','inputSchema','annotations'] as $key) {
        if (!array_key_exists($key, $tool)) fail("tool {$index} missing {$key}");
    }
    $name = (string)$tool['name'];
    if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) fail("invalid tool name {$name}");
    if (isset($names[$name])) fail("duplicate tool {$name}");
    $names[$name] = true;
    if (($tool['inputSchema']['type'] ?? null) !== 'object') fail("{$name} inputSchema must be object");
    if (isset($tool['outputSchema']) && ($tool['outputSchema']['type'] ?? null) !== 'object') fail("{$name} outputSchema must be object when advertised");
    foreach (['readOnlyHint','destructiveHint','idempotentHint','openWorldHint'] as $hint) {
        if (!array_key_exists($hint, $tool['annotations'])) fail("{$name} missing annotation {$hint}");
        if (!is_bool($tool['annotations'][$hint])) fail("{$name} annotation {$hint} must be boolean");
    }
}
foreach (['browser_live_attach','browser_live_signal','browser_live_stop','browser_live_status'] as $removed) {
    if (isset($names[$removed])) fail("removed Browser LIVE tool still advertised: {$removed}");
}
$json = json_encode(['jsonrpc'=>'2.0','id'=>1,'result'=>['tools'=>$tools]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
if ($json === false) fail('catalog JSON encoding failed: ' . json_last_error_msg());
if (strlen($json) > 1024*1024) fail('catalog exceeds 1 MiB response budget: ' . strlen($json));
pass('MCP tools() executes and returns ' . count($tools) . ' unique tools');
pass('Removed Browser LIVE tools remain absent from the runtime catalogue');
pass('Every tool has valid object schemas and boolean annotations');
pass('Catalog JSON encodes successfully (' . strlen($json) . ' bytes)');
