<?php
/**
 * Execute one generated durable-job sequence inside a real WordPress process.
 *
 * Input: PR_STATEFUL_SCENARIO points to JSON with {actions:[...],max_attempts:n}.
 * Output: one JSON object. Any invariant violation exits non-zero.
 */
declare(strict_types=1);

$scenarioPath = (string) getenv('PR_STATEFUL_SCENARIO');
if ($scenarioPath === '' || !is_file($scenarioPath)) {
    fwrite(STDERR, "PR_STATEFUL_SCENARIO missing\n");
    exit(2);
}
$scenario = json_decode((string) file_get_contents($scenarioPath), true);
if (!is_array($scenario) || !is_array($scenario['actions'] ?? null)) {
    fwrite(STDERR, "invalid scenario JSON\n");
    exit(2);
}
if (!class_exists('PRSTUDIO_UC_Store')) {
    fwrite(STDERR, "PRSTUDIO_UC_Store unavailable\n");
    exit(2);
}

$maxAttempts = max(1, min(8, (int) ($scenario['max_attempts'] ?? 3)));
$planHash = hash('sha256', 'hypothesis-stateful-' . wp_generate_uuid4());
$created = PRSTUDIO_UC_Store::create_job(
    'hypothesis generated sequence',
    'agency',
    [],
    ['steps' => [], 'hash' => $planHash],
    '',
    $planHash,
    ['max_attempts' => $maxAttempts]
);
$jobId = (string) ($created['job_uuid'] ?? '');
if ($jobId === '') {
    fwrite(STDERR, "job creation failed\n");
    exit(1);
}

$currentLease = '';
$claimedSnapshot = null;
$healthyClaims = 0;
$recoveries = 0;
$events = [];

$fail = static function (string $message) use ($jobId, &$events): void {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'job_uuid' => $jobId,
        'message' => $message,
        'events' => $events,
    ], JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
};

foreach ($scenario['actions'] as $index => $actionRaw) {
    $action = (string) $actionRaw;
    $before = PRSTUDIO_UC_Store::get_job($jobId);
    if (!is_array($before)) {
        $fail("job disappeared at step {$index}");
    }
    $beforeAttempts = (int) ($before['attempts'] ?? -1);
    $beforeStatus = (string) ($before['status'] ?? '');

    if ($action === 'claim') {
        if ($currentLease !== '') {
            // A second worker must never steal a live lease.
            $other = PRSTUDIO_UC_Store::claim_job($jobId, 'hypothesis-other-' . $index);
            if ($other !== null) {
                $fail("live lease was double-claimed at step {$index}");
            }
        } elseif ($beforeStatus === 'READY') {
            $claimedSnapshot = PRSTUDIO_UC_Store::claim_job($jobId, 'hypothesis-' . $index);
            if (!is_array($claimedSnapshot)) {
                $fail("READY job was not claimable at step {$index}; attempts={$beforeAttempts}");
            }
            $currentLease = (string) ($claimedSnapshot['lease_token'] ?? '');
            if ($currentLease === '') {
                $fail("claim returned no lease token at step {$index}");
            }
            if ((int) ($claimedSnapshot['attempts'] ?? -1) !== $beforeAttempts) {
                $fail("healthy claim consumed failure budget at step {$index}");
            }
            $healthyClaims++;
        }
    } elseif ($action === 'double_claim') {
        if ($currentLease !== '') {
            $other = PRSTUDIO_UC_Store::claim_job($jobId, 'hypothesis-double-' . $index);
            if ($other !== null) {
                $fail("second worker acquired live lease at step {$index}");
            }
        }
    } elseif ($action === 'yield') {
        if ($currentLease !== '' && is_array($claimedSnapshot)) {
            $released = PRSTUDIO_UC_Store::release_leased_job(
                $jobId,
                $currentLease,
                (array) ($claimedSnapshot['checkpoint'] ?? []),
                (int) ($claimedSnapshot['step_index'] ?? 0),
                (int) ($claimedSnapshot['progress'] ?? 0),
                0
            );
            if (!$released) {
                $fail("healthy yield failed at step {$index}");
            }
            $after = PRSTUDIO_UC_Store::get_job($jobId);
            if ((int) ($after['attempts'] ?? -1) !== $beforeAttempts) {
                $fail("healthy yield changed failure budget at step {$index}");
            }
            if ((string) ($after['status'] ?? '') !== 'READY') {
                $fail("healthy yield did not return READY at step {$index}");
            }
            $currentLease = '';
            $claimedSnapshot = null;
        }
    } elseif ($action === 'stale_recover') {
        if ($currentLease !== '') {
            global $wpdb;
            $table = PRSTUDIO_UC_Store::jobs_table();
            $updated = $wpdb->update(
                $table,
                ['lease_expires_gmt' => gmdate('Y-m-d H:i:s', time() - 180)],
                ['job_uuid' => $jobId]
            );
            if ($updated === false) {
                $fail("could not expire lease at step {$index}");
            }
            PRSTUDIO_UC_Store::recover_stale_jobs(60);
            $after = PRSTUDIO_UC_Store::get_job($jobId);
            if (!is_array($after)) {
                $fail("job missing after recovery at step {$index}");
            }
            $afterAttempts = (int) ($after['attempts'] ?? -1);
            if ($afterAttempts !== $beforeAttempts + 1) {
                $fail("stale recovery did not consume exactly one failure attempt at step {$index}");
            }
            $recoveries++;
            $currentLease = '';
            $claimedSnapshot = null;
        }
    } elseif ($action === 'observe') {
        // Observation itself must be side-effect free.
        $again = PRSTUDIO_UC_Store::get_job($jobId);
        if (!is_array($again) || (int) ($again['attempts'] ?? -1) !== $beforeAttempts) {
            $fail("observation mutated retry state at step {$index}");
        }
    } else {
        $fail("unknown generated action {$action}");
    }

    $afterStep = PRSTUDIO_UC_Store::get_job($jobId);
    if (!is_array($afterStep)) {
        $fail("job disappeared after step {$index}");
    }
    $status = (string) ($afterStep['status'] ?? '');
    $attempts = (int) ($afterStep['attempts'] ?? -1);
    if ($status === 'READY' && $attempts >= (int) ($afterStep['max_attempts'] ?? $maxAttempts)) {
        $fail("non-terminal READY job is stranded at retry ceiling at step {$index}");
    }
    $events[] = [
        'step' => $index,
        'action' => $action,
        'status' => $status,
        'attempts' => $attempts,
    ];
}

$final = PRSTUDIO_UC_Store::get_job($jobId);
echo json_encode([
    'ok' => true,
    'job_uuid' => $jobId,
    'healthy_claims' => $healthyClaims,
    'recoveries' => $recoveries,
    'final_status' => (string) ($final['status'] ?? ''),
    'final_attempts' => (int) ($final['attempts'] ?? -1),
    'events' => $events,
], JSON_UNESCAPED_SLASHES) . "\n";
