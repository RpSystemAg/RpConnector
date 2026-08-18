<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');

final class PRStudio_WPDB_Device_Alias_Stub {
    public string $prefix = 'wp_';
    public array $lastPrepareArgs = [];
    public function prepare(string $query, ...$args): string { $this->lastPrepareArgs = $args; return $query; }
    public function get_row(string $query, $output = null) {
        $values = array_map('strval', $this->lastPrepareArgs);
        if (in_array('device-uuid-22', $values, true) || in_array('22', $values, true)) return ['device_uuid' => 'device-uuid-22'];
        return null;
    }
}
$GLOBALS['wpdb'] = new PRStudio_WPDB_Device_Alias_Stub();

require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-store.php';

function check(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL {$message}\n"); exit(1); }
    fwrite(STDOUT, "PASS {$message}\n");
}

check(PRSTUDIO_UC_Store::resolve_device_uuid('device-uuid-22', true) === 'device-uuid-22', 'canonical device UUID resolves unchanged');
check(PRSTUDIO_UC_Store::resolve_device_uuid('22', true) === 'device-uuid-22', 'legacy numeric database id resolves to canonical UUID');
check(PRSTUDIO_UC_Store::resolve_device_uuid('999', true) === null, 'unknown numeric alias returns a technical failure');
$public = PRSTUDIO_UC_Store::public_device(['id'=>22,'device_uuid'=>'device-uuid-22','token_hash'=>'secret-hash','name'=>'Chrome','online'=>true]);
check(!array_key_exists('id', $public) && !array_key_exists('token_hash', $public), 'public device output strips internal database id and token hash');
check(($public['device_uuid'] ?? '') === 'device-uuid-22', 'public device output preserves canonical UUID');
$bridge = file_get_contents(dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-bridge.php');
check(str_contains($bridge, 'PRSTUDIO_UC_Store::resolve_device_uuid'), 'Bridge canonicalizes device aliases before creating browser tasks');
check(str_contains($bridge, 'PRSTUDIO_UC_Store::public_devices'), 'Bridge redacts internal device rows in status/error responses');
fwrite(STDOUT, "OK device alias smoke\n");
