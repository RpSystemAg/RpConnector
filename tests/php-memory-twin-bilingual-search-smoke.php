<?php

declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('PRSTUDIO_UC_VERSION', '1.0.0');
$testRoot = sys_get_temp_dir() . '/prstudio-memory-twin-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $testRoot);
@mkdir($testRoot, 0750, true);

function sanitize_key(string $value): string { return strtolower(trim((string)preg_replace('/[^a-z0-9_\-]/i', '', $value))); }
function sanitize_text_field(string $value): string { return trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value)); }
function esc_url_raw(string $value): string { return $value; }
function home_url(string $path = '/'): string { return 'https://example.test' . ('/' === substr($path, 0, 1) ? $path : '/' . $path); }
function wp_parse_url(string $url) { return parse_url($url); }
function get_current_blog_id(): int { return 1; }
function wp_mkdir_p(string $dir): bool { return is_dir($dir) || @mkdir($dir, 0750, true); }
function remove_accents(string $value): string { return $value; }
function is_wp_error($value): bool { return false; }

final class PRSTUDIO_UC_Idempotency {
    public static function canonical_json($value): string { if (is_array($value)) { ksort($value); } return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
}
final class PRSTUDIO_UC_Agency_State {
    private static array $states = [];
    public static function read(string $name, array $defaults = []): array { return isset(self::$states[$name]) ? array_replace_recursive($defaults, self::$states[$name]) : $defaults; }
    public static function mutate(string $name, array $defaults, callable $callback) { $state = self::read($name, $defaults); $result = $callback($state); self::$states[$name] = $state; return $result; }
}

$inc = dirname(__DIR__) . '/prstudio-unified-control/includes/';
require_once $inc . 'class-prstudio-uc-action-lexicon.php';
require_once $inc . 'class-prstudio-uc-memory.php';
require_once $inc . 'class-prstudio-uc-operational-twin.php';

function ok(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function cleanup(string $root): void {
    if (!is_dir($root)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
    @rmdir($root);
}
function memoryIds(string $query): array {
    $result = PRSTUDIO_UC_Memory::search($query, 'resource', 50);
    return array_map(static fn(array $row): string => (string)($row['id'] ?? ''), (array)($result['items'] ?? []));
}
function twinIds(string $query): array {
    $result = PRSTUDIO_UC_Operational_Twin::query($query, ['type' => 'browser_action', 'limit' => 200]);
    return array_map(static fn(array $row): string => (string)($row['external_id'] ?? ''), (array)($result['items'] ?? []));
}

try {
    $memoryFixtures = [
        ['browser-snapshot', 'snapshot page'],
        ['browser-fill', 'fill field'],
        ['browser-reload', 'reload page'],
        ['browser-back', 'back previous page'],
        ['browser-select', 'select option'],
        ['browser-screenshot', 'screenshot page'],
        ['browser_snapshot_v1', 'technical identifier only'],
    ];
    foreach ($memoryFixtures as [$id, $action]) {
        $remembered = PRSTUDIO_UC_Memory::remember('resource', $id, ['id' => $id], ['action' => $action, 'status' => 'verified']);
        ok(!empty($remembered['ok']), 'memory fixture persisted ' . $id);
    }

    $pairs = [
        ['istantanea', 'snapshot'], ['compila', 'fill'], ['ricarica', 'reload'],
        ['indietro', 'back'], ['seleziona', 'select'], ['schermata', 'screenshot'],
        ['pagina', 'page'],
    ];
    foreach ($pairs as [$italian, $english]) {
        $it = memoryIds($italian); $en = memoryIds($english);
        ok($it === $en && [] !== $it, sprintf('memory full order agrees IT/EN: "%s" / "%s"', $italian, $english));
    }
    ok(['browser_snapshot_v1'] === memoryIds('browser_snapshot_v1'), 'memory technical identifier stays exact');
    ok([] === memoryIds('xyzzy quux'), 'memory nonsense query stays empty');

    $entities = [
        ['type' => 'browser_action', 'external_id' => 'playwright_locator_snapshot', 'label' => 'Snapshot page', 'attributes' => ['kind' => 'snapshot']],
        ['type' => 'browser_action', 'external_id' => 'playwright_fill', 'label' => 'Fill field', 'attributes' => ['kind' => 'fill']],
        ['type' => 'browser_action', 'external_id' => 'playwright_reload', 'label' => 'Reload page', 'attributes' => ['kind' => 'reload']],
        ['type' => 'browser_action', 'external_id' => 'playwright_go_back', 'label' => 'Back previous page', 'attributes' => ['kind' => 'back']],
        ['type' => 'browser_action', 'external_id' => 'playwright_select_option', 'label' => 'Select option', 'attributes' => ['kind' => 'select']],
        ['type' => 'browser_action', 'external_id' => 'playwright_screenshot_page', 'label' => 'Screenshot page', 'attributes' => ['kind' => 'screenshot']],
    ];
    $ingested = PRSTUDIO_UC_Operational_Twin::ingest($entities, [], PRSTUDIO_UC_Operational_Twin::provenance('memory', 'test-fixture', 1.0));
    ok(!empty($ingested['ok']) && 6 === (int)($ingested['accepted'] ?? 0), 'operational twin fixtures ingested');

    foreach ($pairs as [$italian, $english]) {
        $it = twinIds($italian); $en = twinIds($english);
        ok($it === $en && [] !== $it, sprintf('twin full order agrees IT/EN: "%s" / "%s"', $italian, $english));
    }
    ok(['playwright_locator_snapshot'] === twinIds('playwright_locator_snapshot'), 'twin technical identifier stays exact');
    ok([] === twinIds('xyzzy quux'), 'twin nonsense query stays empty');

    fwrite(STDOUT, "PHP memory/twin bilingual search smoke: all assertions passed\n");
} finally {
    cleanup($testRoot);
}
