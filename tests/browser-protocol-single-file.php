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
function bp_caps(array $patch = array()): array {
    return array_merge(array(
        'executorProtocolVersion' => '3.0.0',
        'runtimeOperationCount' => 61,
        'wordpressCapabilityCatalog' => false,
        'capabilityHash' => PRSTUDIO_UC_Browser_Protocol::REQUIRED_CAPABILITY_CONTRACT_SHA256,
        'gscDimensionSessionVersion' => PRSTUDIO_UC_Browser_Protocol::REQUIRED_GSC_DIMENSION_SESSION_VERSION,
        'suiteVersion' => PRSTUDIO_UC_Browser_Protocol::REQUIRED_AGENT_PRODUCT_VERSION,
        'agentBuild' => 'prstudio-browser-1.0.0+git.0123456789ab',
        'buildTimestamp' => '2026-08-22T09:00:00Z',
    ), $patch);
}

$tests = array();
$tests['canonical 3.0.0 stays executable with bound identity'] = static function (): void {
    $caps = bp_caps();
    $r = PRSTUDIO_UC_Browser_Protocol::compatibility($caps);
    bp_assert(($r['compatible'] ?? null) === true, 'canonical peer must be compatible');
    bp_assert(($r['capability_hash_match'] ?? false) === true, 'capability hash must match');
    bp_assert(($r['gsc_dimension_session_match'] ?? false) === true, 'GSC v4 session must match');
    bp_assert(($r['build_identity']['ok'] ?? false) === true, 'build identity must be bound');
    bp_assert(PRSTUDIO_UC_Browser_Protocol::negotiated_protocol($caps) === '3.0.0', 'canonical protocol negotiation changed');
};
$tests['4.0.0 bridge and snake aliases stay executable when contracts match'] = static function (): void {
    $caps = bp_caps(array(
        'executorProtocolVersion' => null,
        'runtimeOperationCount' => null,
        'wordpressCapabilityCatalog' => null,
        'capabilityHash' => null,
        'gscDimensionSessionVersion' => null,
        'executor_protocol_version'=>'4.0.0',
        'runtime_operation_count'=>61,
        'wordpress_capability_catalog'=>false,
        'capability_hash'=>PRSTUDIO_UC_Browser_Protocol::REQUIRED_CAPABILITY_CONTRACT_SHA256,
        'gsc_dimension_session_version'=>PRSTUDIO_UC_Browser_Protocol::REQUIRED_GSC_DIMENSION_SESSION_VERSION,
    ));
    unset($caps['executorProtocolVersion'], $caps['runtimeOperationCount'], $caps['wordpressCapabilityCatalog'], $caps['capabilityHash'], $caps['gscDimensionSessionVersion']);
    $r = PRSTUDIO_UC_Browser_Protocol::compatibility($caps);
    bp_assert(($r['compatible'] ?? null) === true, '4.0.0 bridge must remain compatible');
    bp_assert(PRSTUDIO_UC_Browser_Protocol::negotiated_protocol($caps) === '4.0.0', 'bridge negotiation changed');
};
$tests['missing optional catalog remains compatible'] = static function (): void {
    $caps = bp_caps(); unset($caps['wordpressCapabilityCatalog']);
    $r = PRSTUDIO_UC_Browser_Protocol::compatibility($caps);
    bp_assert(($r['compatible'] ?? null) === true, 'optional catalog field became mandatory');
};
$tests['exact numeric runtime forms remain compatible'] = static function (): void {
    foreach (array(61, 61.0, '61', ' 61 ') as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(bp_caps(array('runtimeOperationCount'=>$value)));
        bp_assert(($r['compatible'] ?? null) === true, 'exact runtime form rejected');
        bp_assert(($r['runtime_operation_count'] ?? null) === 61, 'runtime normalized output differs');
    }
};
$tests['runtime boundaries block zero negative fractional and malformed containers'] = static function (): void {
    foreach (array(0, -1, 0.5, 61.5, array('x'), array(), new stdClass(), null, true, false, '61x', '') as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(bp_caps(array('runtimeOperationCount'=>$value)));
        bp_assert(($r['compatible'] ?? null) === false, 'invalid runtime widened compatibility');
        bp_assert(is_int($r['runtime_operation_count'] ?? null), 'runtime diagnostic must stay integer');
    }
};
$tests['catalog false forms preserve autonomy'] = static function (): void {
    foreach (array(false, 0, '0', 'false', ' FALSE ') as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(bp_caps(array('wordpressCapabilityCatalog'=>$value)));
        bp_assert(($r['compatible'] ?? null) === true, 'false catalog form narrowed autonomy');
        bp_assert(($r['wordpress_capability_catalog'] ?? null) === false, 'false catalog normalization differs');
    }
};
$tests['catalog true forms block incompatible catalog exposure'] = static function (): void {
    foreach (array(true, 1, '1', 'true', ' TRUE ') as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(bp_caps(array('wordpressCapabilityCatalog'=>$value)));
        bp_assert(($r['compatible'] ?? null) === false, 'true catalog exposure must block compatibility');
        bp_assert(($r['wordpress_capability_catalog'] ?? null) === true, 'true catalog normalization differs');
    }
};
$tests['malformed explicit catalog returns a technical failure without throwing'] = static function (): void {
    foreach (array('yes', 2, -1, array(), array('false'), new stdClass()) as $value) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(bp_caps(array('wordpressCapabilityCatalog'=>$value)));
        bp_assert(($r['compatible'] ?? null) === false, 'malformed catalog widened compatibility');
        bp_assert(is_bool($r['wordpress_capability_catalog'] ?? null), 'catalog diagnostic must stay boolean');
    }
};
$tests['capability contract mismatch blocks pairing before dispatch'] = static function (): void {
    foreach (array('', str_repeat('0', 64), '1358fb18', 'UNVERIFIED') as $hash) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(bp_caps(array('capabilityHash'=>$hash)));
        bp_assert(($r['compatible'] ?? null) === false, 'wrong capability contract reached stable pairing');
        bp_assert(($r['capability_hash_match'] ?? null) === false, 'hash diagnostic must expose mismatch');
        bp_assert(($r['repair_required'] ?? null) === true, 'mismatch must require repair');
    }
};
$tests['gsc_dimension_session_v4 is mandatory for stable pairing'] = static function (): void {
    foreach (array('', '1.0.0', '3.0.0', '5.0.0', 'gsc_dimension_session_v3') as $version) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(bp_caps(array('gscDimensionSessionVersion'=>$version)));
        bp_assert(($r['compatible'] ?? null) === false, 'old GSC collector session reached Agent');
        bp_assert(($r['gsc_dimension_session_match'] ?? null) === false, 'GSC mismatch diagnostic missing');
    }
};
$tests['unbound and unstamped Browser builds are rejected'] = static function (): void {
    foreach (array(
        array('agentBuild'=>'prstudio-browser-1.0.0+unbound'),
        array('agentBuild'=>'prstudio-browser-1.0.0+unverified'),
        array('agentBuild'=>'prstudio-browser-0.9.9+git.0123456789ab'),
        array('buildTimestamp'=>'UNSTAMPED'),
        array('buildTimestamp'=>''),
        array('suiteVersion'=>'0.9.9'),
    ) as $patch) {
        $r = PRSTUDIO_UC_Browser_Protocol::compatibility(bp_caps($patch));
        bp_assert(($r['compatible'] ?? null) === false, 'unbound/stale build reached pairing');
        bp_assert(($r['build_identity']['ok'] ?? null) === false, 'build identity mismatch not reported');
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
