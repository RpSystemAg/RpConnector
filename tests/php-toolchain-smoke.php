<?php
declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
$fixture = sys_get_temp_dir() . '/prstudio-toolchain-' . bin2hex(random_bytes(6));
mkdir($fixture, 0755, true);
define('ABSPATH', $fixture . '/');
define('WP_CONTENT_DIR', $fixture);
define('PRSTUDIO_UC_DIR', dirname(__DIR__) . '/prstudio-unified-control/');

class WP_Error {
    public function __construct(private string $code, private string $message, private array $data = []) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function assert_true(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($dir);
}

// Fake npx MCP process: validates the stdio client without network/downloads.
$fake_npx = $fixture . '/npx';
$fake_script = <<<'PHP'
#!/usr/bin/env php
<?php
while (($line = fgets(STDIN)) !== false) {
    $msg = json_decode(trim($line), true);
    if (!is_array($msg)) continue;
    if (($msg['method'] ?? '') === 'initialize') {
        echo json_encode(['jsonrpc'=>'2.0','id'=>$msg['id'],'result'=>['protocolVersion'=>'2025-06-18','capabilities'=>new stdClass(),'serverInfo'=>['name'=>'fixture','version'=>'1']]]) . "\n";
        flush();
    } elseif (($msg['method'] ?? '') === 'tools/list') {
        echo json_encode(['jsonrpc'=>'2.0','id'=>$msg['id'],'result'=>['tools'=>[['name'=>'read_file','inputSchema'=>['type'=>'object']]]]]) . "\n";
        flush();
    } elseif (($msg['method'] ?? '') === 'tools/call') {
        echo json_encode(['jsonrpc'=>'2.0','id'=>$msg['id'],'result'=>['content'=>[['type'=>'text','text'=>'fixture-ok']]]]) . "\n";
        flush();
    }
}
PHP;
file_put_contents($fake_npx, $fake_script);
chmod($fake_npx, 0755);
if (DIRECTORY_SEPARATOR === '\\') {
    // binary_path() resolves PATHEXT-style launchers on Windows. Keep the
    // sidecar fake local and deterministic instead of falling through to the
    // machine's real npx/network.
    $quoteBatch = static fn(string $value): string => '"' . str_replace('"', '""', $value) . '"';
    $batch = "@echo off\r\n" . $quoteBatch(PHP_BINARY) . ' ' . $quoteBatch($fake_npx) . " %*\r\n";
    file_put_contents($fake_npx . '.cmd', $batch);
}
putenv('PATH=' . $fixture . PATH_SEPARATOR . (string)getenv('PATH'));

require_once PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-mcp-toolchain.php';
require_once PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-capability-registry.php';
$outsidePath = tempnam(sys_get_temp_dir(), 'prstudio-outside-');
if ($outsidePath === false) {
    fwrite(STDERR, "FAIL: unable to create outside-root fixture\n");
    rrmdir($fixture);
    exit(1);
}
try {
    $status = PRSTUDIO_UC_MCP_Toolchain::status();
    assert_true(($status['version'] ?? '') === '1.0.0', 'toolchain version');
    assert_true(($status['installation_changed'] ?? true) === false, 'installation unchanged');
    assert_true(($status['configuration_changed'] ?? true) === false, 'configuration unchanged');
    assert_true(count((array)($status['profiles'] ?? [])) >= 15, 'requested integration profiles advertised');
    assert_true(($status['profiles']['playwright']['kind'] ?? '') === 'native_supersedes_sidecar', 'Playwright routed to Browser Agent');
    assert_true(($status['profiles']['chrome_devtools']['kind'] ?? '') === 'native_supersedes_sidecar', 'Chrome DevTools routed to Browser Agent');

    $path = $fixture . '/fixture.txt';
    $write = PRSTUDIO_UC_MCP_Toolchain::filesystem_write(['action'=>'write','path'=>$path,'content'=>'alpha beta gamma']);
    assert_true(!is_wp_error($write) && !empty($write['verified']) && is_file($path), 'atomic filesystem write');
    $read = PRSTUDIO_UC_MCP_Toolchain::filesystem_inspect(['action'=>'read','path'=>$path]);
    assert_true(!is_wp_error($read) && ($read['content'] ?? '') === 'alpha beta gamma', 'filesystem read');
    $replace = PRSTUDIO_UC_MCP_Toolchain::filesystem_write(['action'=>'replace','path'=>$path,'search'=>'beta','replace'=>'BETA','expected_occurrences'=>1,'expected_sha256'=>$read['sha256']]);
    assert_true(!is_wp_error($replace) && !empty($replace['verified']), 'exact guarded replace');
    $search = PRSTUDIO_UC_MCP_Toolchain::filesystem_inspect(['action'=>'search','path'=>$fixture,'query'=>'BETA']);
    assert_true(!is_wp_error($search) && ($search['count'] ?? 0) === 1, 'bounded filesystem search');
    $fileSearch = PRSTUDIO_UC_MCP_Toolchain::filesystem_inspect(['action'=>'search','path'=>$path,'query'=>'BETA']);
    assert_true(!is_wp_error($fileSearch) && ($fileSearch['count'] ?? 0) === 1 && ($fileSearch['scanned_files'] ?? 0) === 1, 'bounded filesystem search accepts a file path');
    // Use a real path outside the allowed roots on every OS. `/etc/passwd`
    // becomes a missing path on Windows and tests the wrong failure branch.
    $outside = PRSTUDIO_UC_MCP_Toolchain::filesystem_inspect(['action'=>'stat','path'=>$outsidePath]);
    assert_true(is_wp_error($outside) && $outside->get_error_code() === 'toolchain_path_outside_root', 'filesystem traversal denied');

    $pdfMissing = PRSTUDIO_UC_MCP_Toolchain::pdf_read(['path'=>$path]);
    assert_true(is_wp_error($pdfMissing) && $pdfMissing->get_error_code() === 'toolchain_pdf_extension_invalid', 'PDF type guard');

    $listed = PRSTUDIO_UC_MCP_Toolchain::sidecar_tools(['profile'=>'filesystem','root'=>$fixture]);
    assert_true(!is_wp_error($listed) && ($listed['result']['tools'][0]['name'] ?? '') === 'read_file', 'MCP stdio initialize + tools/list');
    $called = PRSTUDIO_UC_MCP_Toolchain::sidecar_read(['profile'=>'filesystem','root'=>$fixture,'tool'=>'read_file','arguments'=>['path'=>$path]]);
    assert_true(!is_wp_error($called) && ($called['result']['content'][0]['text'] ?? '') === 'fixture-ok', 'MCP stdio tools/call');
    $blockedTool = PRSTUDIO_UC_MCP_Toolchain::sidecar_read(['profile'=>'filesystem','root'=>$fixture,'tool'=>'write_file','arguments'=>['path'=>$path]]);
    assert_true(is_wp_error($blockedTool) && $blockedTool->get_error_code() === 'toolchain_sidecar_write_denied', 'read-only sidecar lane blocks write tool');
    $searchCap = PRSTUDIO_UC_Capability_Registry::search('mermaid', ['include_legacy'=>false,'limit'=>10]);
    assert_true(in_array('toolchain.mermaid.render', array_column((array)($searchCap['items'] ?? []), 'id'), true), 'ChatGPT capability discovery finds Mermaid naturally');

    fwrite(STDOUT, "PHP MCP toolchain smoke: 17 assertions passed\n");
} finally {
    @unlink($outsidePath);
    rrmdir($fixture);
}
