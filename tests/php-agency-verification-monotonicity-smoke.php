<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

function sanitize_key($v): string { return strtolower((string)preg_replace('/[^a-z0-9_\-]/i', '', (string)$v)); }
function sanitize_text_field($v): string { return trim(strip_tags((string)$v)); }
function is_wp_error($v): bool { return $v instanceof WP_Error; }

final class WP_Error {
    public function __construct(private string $code='', private string $message='', private array $data=[]) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}

final class PRSTUDIO_UC_Store {
    public static array $job = [];
    public static array $completed_verification = [];

    public static function claim_job(string $job_uuid, string $worker): ?array {
        return self::$job;
    }
    public static function claim_next_job(string $worker): ?array { return self::$job; }
    public static function checkpoint_leased_job(string $job_uuid, string $lease, int $step_index, array $checkpoint, int $progress=0): ?array {
        self::$job['checkpoint'] = $checkpoint;
        self::$job['step_index'] = $step_index + 1;
        self::$job['progress'] = $progress;
        return self::$job;
    }
    public static function complete_leased_job(string $job_uuid, string $lease, array $result, array $verification): ?array {
        self::$completed_verification = $verification;
        self::$job['status'] = 'COMPLETED';
        self::$job['result'] = $result;
        self::$job['verification'] = $verification;
        return self::$job;
    }
    public static function retry_leased_job(string $job_uuid, string $lease, array $error): ?array { return null; }
    public static function dead_letter_job(string $job_uuid, array $error, string $failure_class='exhausted', string $lease=''): bool { return false; }
    public static function get_job(string $job_uuid): ?array { return self::$job; }
    public static function release_leased_job(string $job_uuid, string $lease, array $checkpoint, int $step_index, int $progress, int $delay=0): ?array { return self::$job; }
    public static function wait_leased_job(string $job_uuid, string $lease, string $state, array $checkpoint): ?array { return self::$job; }
}

PRSTUDIO_UC_Store::$job = [
    'job_uuid' => 'job-1',
    'lease_token' => 'lease-1',
    'status' => 'RUNNING',
    'step_index' => 0,
    'progress' => 0,
    'plan' => [
        'hash' => hash('sha256', 'plan'),
        'steps' => [[
            'id' => 'browser-observe',
            'handler' => 'browser.action',
            'requires_browser' => true,
            'arguments' => ['action' => 'playwright_observation_bundle', 'arguments' => []],
        ]],
    ],
    'checkpoint' => [
        'playbook' => 'browser_deep_audit',
        'next_step' => 0,
        'results' => [],
        'browser_task_id' => 'task-1',
        'browser_step_index' => 0,
        'browser_result' => [
            'result' => ['executed' => true, 'degraded' => true],
            'verification' => [
                'ok' => false,
                'reason' => 'browser_result_missing_verified_evidence',
                'evidence_hash' => hash('sha256', 'weak-evidence'),
            ],
        ],
    ],
];

require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-agency-runtime.php';

$result = PRSTUDIO_UC_Agency_Runtime::run_one('job-1', 'verification-test', 1.0);
$verification = PRSTUDIO_UC_Store::$completed_verification;

if (($result['completed'] ?? false) !== true) {
    fwrite(STDERR, "FAIL test harness did not reach terminal completion\n");
    exit(1);
}

if (($verification['ok'] ?? null) !== false) {
    fwrite(STDERR, "FAIL verification monotonicity: an unverified child became a verified mission\n");
    exit(1);
}

if (empty($verification['degraded']) || empty($verification['unverified_steps'])) {
    fwrite(STDERR, "FAIL final mission verification does not preserve degraded/unverified step evidence\n");
    exit(1);
}

echo "PASS unverified browser evidence cannot be promoted to verified mission evidence\n";
