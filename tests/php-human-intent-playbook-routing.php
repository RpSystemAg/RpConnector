<?php
/**
 * LAW 13: ordinary human playbook requests must route to the executable agency
 * path without requiring the caller to know the internal `mission` routing key.
 */
declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);

final class WP_Error {
    public string $code;
    public string $message;
    /** @var mixed */
    public $data;
    /** @param mixed $data */
    public function __construct(string $code = '', string $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-do.php';

$params = [
    'playbook' => 'browser_deep_audit',
    'objective' => 'Audit the live site through the registered browser playbook.',
    'occurrence_key' => 'law13-human-playbook-routing',
];

$cases = [
    'Please run the browser_deep_audit playbook' => 'run_playbook',
    'Execute the browser_deep_audit playbook' => 'execute_playbook',
    'Esegui il playbook browser_deep_audit' => 'esegui_playbook',
    'Avvia il playbook browser_deep_audit' => 'avvia_playbook',
];

$failures = [];
$passes = 0;
foreach ($cases as $intent => $expectedMatch) {
    $resolved = PRSTUDIO_UC_Do::resolve([
        'intent' => $intent,
        'params' => $params,
    ]);
    if ($resolved instanceof WP_Error) {
        $failures[] = "{$intent}: returned WP_Error {$resolved->code}";
        continue;
    }
    if ('agency_submit' !== ($resolved['tool'] ?? '')) {
        $failures[] = "{$intent}: routed to " . (string)($resolved['tool'] ?? '<missing>') . ' instead of agency_submit';
        continue;
    }
    if ($params !== ($resolved['arguments'] ?? null)) {
        $failures[] = "{$intent}: playbook parameters were not preserved byte-for-byte";
        continue;
    }
    if ($expectedMatch !== ($resolved['routing']['matched'] ?? '')) {
        $failures[] = "{$intent}: matched " . (string)($resolved['routing']['matched'] ?? '<missing>') . " instead of {$expectedMatch}";
        continue;
    }
    if ('fallback' === ($resolved['routing']['confidence'] ?? '')) {
        $failures[] = "{$intent}: fell back to capability search instead of direct human-intent routing";
        continue;
    }
    ++$passes;
    fwrite(STDOUT, "PASS {$intent} -> agency_submit ({$expectedMatch})\n");
}

$unknown = PRSTUDIO_UC_Do::resolve([
    'intent' => 'quantum banana frobnicate',
]);
if ($unknown instanceof WP_Error || 'prstudio_capability_search' !== ($unknown['tool'] ?? '')) {
    $failures[] = 'unknown intent no longer falls back safely to capability search';
} else {
    ++$passes;
    fwrite(STDOUT, "PASS unknown intent keeps capability-search fallback\n");
}

fwrite(STDOUT, sprintf("SUMMARY %d passed, %d failed\n", $passes, count($failures)));
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}
exit(0);
