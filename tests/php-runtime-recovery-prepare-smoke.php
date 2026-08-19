<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

final class Strict_Prepare_WPDB {
    public string $prefix = 'wp_';
    public array $queries = [];

    public function prepare(string $query, ...$args): string {
        $masked = str_replace('%%', '', $query);
        preg_match_all('/%(?:s|d|f)/', $masked, $matches);
        $expected = count($matches[0]);
        $actual = count($args);
        if ($expected !== $actual) {
            throw new RuntimeException("wpdb::prepare arity mismatch: {$expected} placeholders, {$actual} arguments\n{$query}");
        }
        return $query;
    }

    public function query(string $query): int {
        $this->queries[] = $query;
        return 1;
    }
}

$GLOBALS['wpdb'] = new Strict_Prepare_WPDB();

require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-state-machine.php';
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-store.php';

try {
    $affected = PRSTUDIO_UC_Store::recover_stale_tasks();
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL stale-task recovery SQL is not structurally executable: {$e->getMessage()}\n");
    exit(1);
}

if ($affected !== 1 || count($GLOBALS['wpdb']->queries) !== 2) {
    fwrite(STDERR, "FAIL stale-task recovery did not execute both bounded recovery statements\n");
    exit(1);
}

echo "PASS stale-task recovery SQL has exact prepare arity and executes both statements\n";
