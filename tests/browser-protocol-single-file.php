<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);

function sanitize_text_field($value) {
    return is_string($value) ? trim($value) : $value;
}

final class PRSTUDIO_UC_Contract {
    public static function extension_compatibility(array $capabilities): array {
        $ok = ($capabilities['legacy_ok'] ?? false) === true;
        return array(
            'compatible' => $ok,
            'executor_protocol' => '',
            'legacy_stub' => true,
        );
    }
}

require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-browser-protocol.php';

function bp_assert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$tests = array();
$tests['canonical 3.0.0 stays executable'] = static function (): void {
    $caps = array('executorProtocolVersion'=>'3.0.0','runtimeOperationCount'=>61,'wordpressCapabilityCatalog'=>false);
    $r = PRSTUDIO_UC_Browser_Protocol::compatibility($caps);
    bp_assert(($r['compatible'] ?? null) === true, 'canonical peer must be compatible');
    bp_assert(PRSTUDIO_UC_Browser_Protocol::negotiated_protocol($caps) === '3.0.0', 'canonical protocol negotiation changed');
};
$tests['4.0.0 bridge and snake aliases stay executable'] = static function (): void {
    $caps = array('executor_protocol_version'=>'4.0.0','runtime_operation_count'=>61,'wordpress_capability_catalog'=>false);
    $r = PRSTUDIO_UC_Browser_Protocol::compatibility($caps);
    bp_assert(($r['compatible'] ?? null) === true, '4.0.0 bridge must remain compatible');
    bp_assert(PRSTUDIO_UC_Browser_Protocol::negotiated_protocol($caps) === '4.0.0', 'bridge negotiation changed');
};
$tests['missing optional catalog remains compatible'] = static function (): void {
    $r = PRSTUDIO_UC_Browser_Protocol::compatibility(array('executorProtocolVersion'=>'3.0.0','runtimeOperationCount'=>61));
    bp_assert(($r['compatible'] ?? null) === true, 'optional catalog field became mandatory');
};
$tests['exact numeric runtime forms remain compatible'] = static function (): void {
    foreach (array(61, 61.0, '61', ' 61 ') as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(array('executorProtocolVersion'=>'3.0.0','runtimeOperationCount'=>$value,'wordpressCapabilityCatalog'=>false));
        bp_assert(($r['compatible'] ?? null) === true, 'exact runtime form rejected');
        bp_assert(($r['runtime_operation_count'] ?? null) === 61, 'runtime normalized output differs');
    }
};
$tests['runtime boundaries block zero negative fractional and malformed containers'] = static function (): void {
    foreach (array(0, -1, 0.5, 61.5, array('x'), array(), new stdClass(), null, true, false, '61x', '') as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(array('executorProtocolVersion'=>'3.0.0','runtimeOperationCount'=>$value,'wordpressCapabilityCatalog'=>false));
        bp_assert(($r['compatible'] ?? null) === false, 'invalid runtime widened compatibility');
        bp_assert(is_int($r['runtime_operation_count'] ?? null), 'runtime diagnostic must stay integer');
    }
};
$tests['catalog false forms preserve autonomy'] = static function (): void {
    foreach (array(false, 0, '0', 'false', ' FALSE ') as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(array('executorProtocolVersion'=>'3.0.0','runtimeOperationCount'=>61,'wordpressCapabilityCatalog'=>$value));
        bp_assert(($r['compatible'] ?? null) === true, 'false catalog form narrowed autonomy');
        bp_assert(($r['wordpress_capability_catalog'] ?? null) === false, 'false catalog normalization differs');
    }
};
$tests['catalog true forms block incompatible catalog exposure'] = static function (): void {
    foreach (array(true, 1, '1', 'true', ' TRUE ') as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(array('executorProtocolVersion'=>'3.0.0','runtimeOperationCount'=>61,'wordpressCapabilityCatalog'=>$value));
        bp_assert(($r['compatible'] ?? null) === false, 'true catalog exposure must block compatibility');
        bp_assert(($r['wordpress_capability_catalog'] ?? null) === true, 'true catalog normalization differs');
    }
};
$tests['malformed explicit catalog returns a technical failure without throwing'] = static function (): void {
    foreach (array('yes', 2, -1, array(), array('false'), new stdClass()) as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(array('executorProtocolVersion'=>'3.0.0','runtimeOperationCount'=>61,'wordpressCapabilityCatalog'=>$value));
        bp_assert(($r['compatible'] ?? null) === false, 'malformed catalog widened compatibility');
        bp_assert(is_bool($r['wordpress_capability_catalog'] ?? null), 'catalog diagnostic must stay boolean');
    }
};
$tests['hostile executor values fall to legacy without raw conversion error'] = static function (): void {
    foreach (array(new stdClass(), array('3.0.0'), 3, true, null) as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(array('executorProtocolVersion'=>$value));
        bp_assert(($r['compatible'] ?? null) === false, 'hostile executor became compatible');
        bp_assert(($r['mode'] ?? '') === 'legacy_contract_2', 'invalid executor must use legacy decision path');
    }
};
$tests['legacy valid contract remains executable'] = static function (): void {
    $caps = array('legacy_ok'=>true);
    $r = PRSTUDIO_UC_Browser_Protocol::compatibility($caps);
    bp_assert(($r['compatible'] ?? null) === true, 'legacy compatibility path was removed');
    bp_assert(($r['pairing_compatible'] ?? null) === true, 'legacy pairing compatibility changed');
    bp_assert(PRSTUDIO_UC_Browser_Protocol::negotiated_protocol($caps) === '3.0.0', 'legacy negotiated default changed');
};

$pass = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL: {$name} :: " . get_class($e) . ' :: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
echo "PASS: browser protocol targeted {$pass}/" . count($tests) . "\n";
