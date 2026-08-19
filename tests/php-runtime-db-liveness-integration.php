<?php
/**
 * Real-database liveness/recovery test for the durable job kernel.
 *
 * This intentionally tests semantics that mocks cannot prove:
 * - healthy claim/yield cycles do not consume failure budget;
 * - a running lease fences a second worker;
 * - stale browser leases really recover through executable SQL;
 * - stale job leases consume a failure/recovery budget, not a scheduling budget;
 * - retry exhaustion terminates instead of stranding READY work;
 * - a lost dead-letter CAS cannot leave a false receipt behind.
 */
declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('YEAR_IN_SECONDS', 31536000);
define('MINUTE_IN_SECONDS', 60);
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');

$wpRoot = sys_get_temp_dir() . '/prstudio-runtime-db-test-wp/';
@mkdir($wpRoot . 'wp-admin/includes', 0777, true);
if (!file_exists($wpRoot . 'wp-admin/includes/upgrade.php')) {
    file_put_contents($wpRoot . 'wp-admin/includes/upgrade.php', "<?php\n");
}
define('ABSPATH', $wpRoot);

$host = getenv('PRSTUDIO_TEST_DB_HOST') ?: '';
$port = (int)(getenv('PRSTUDIO_TEST_DB_PORT') ?: 3306);
$user = getenv('PRSTUDIO_TEST_DB_USER') ?: 'root';
$passEnv = getenv('PRSTUDIO_TEST_DB_PASS');
$pass = false === $passEnv ? '' : $passEnv;
$dbname = getenv('PRSTUDIO_TEST_DB_NAME') ?: 'prstudio_runtime_liveness';

if ('' === $host) {
    fwrite(STDERR, "FAIL php-runtime-db-liveness-integration: PRSTUDIO_TEST_DB_HOST not set for mandatory MariaDB runtime\n");
    exit(1);
}
if (!class_exists('mysqli')) {
    fwrite(STDERR, "FAIL php-runtime-db-liveness-integration: mysqli unavailable for mandatory MariaDB runtime\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "FAIL cannot connect to database: {$conn->connect_error}\n");
    exit(1);
}
$conn->query("DROP DATABASE IF EXISTS `{$dbname}`");
if (!$conn->query("CREATE DATABASE `{$dbname}`")) {
    fwrite(STDERR, "FAIL cannot create test database: {$conn->error}\n");
    exit(1);
}
$conn->select_db($dbname);

final class Runtime_Test_WPDB {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $errors = [];
    public int $queries_run = 0;
    private mysqli $conn;

    public function __construct(mysqli $conn) { $this->conn = $conn; }
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }

    public function prepare(string $query, ...$args): string {
        if (1 === count($args) && is_array($args[0])) { $args = $args[0]; }
        $out = '';
        $arg = 0;
        $len = strlen($query);
        for ($i = 0; $i < $len; $i++) {
            $ch = $query[$i];
            if ('%' !== $ch || $i + 1 >= $len) { $out .= $ch; continue; }
            $kind = $query[$i + 1];
            if ('%' === $kind) { $out .= '%'; $i++; continue; }
            if (!in_array($kind, ['s', 'd', 'f'], true)) { $out .= $ch; continue; }
            if (!array_key_exists($arg, $args)) {
                throw new RuntimeException('wpdb::prepare missing argument for placeholder in: ' . $query);
            }
            $value = $args[$arg++];
            if ('d' === $kind) { $out .= (string)(int)$value; }
            elseif ('f' === $kind) { $out .= (string)(float)$value; }
            else { $out .= "'" . $this->conn->real_escape_string((string)$value) . "'"; }
            $i++;
        }
        if ($arg !== count($args)) {
            throw new RuntimeException('wpdb::prepare received extra arguments: placeholders=' . $arg . ' args=' . count($args));
        }
        return $out;
    }

    private function run(string $sql) {
        $this->queries_run++;
        $result = $this->conn->query($sql);
        if (false === $result) {
            $this->last_error = $this->conn->error;
            $this->errors[] = ['sql' => $sql, 'error' => $this->conn->error];
            return false;
        }
        $this->last_error = '';
        return $result;
    }

    public function query(string $sql) {
        $result = $this->run($sql);
        if (false === $result) { return false; }
        return is_bool($result) ? $this->conn->affected_rows : $result->num_rows;
    }

    public function get_row(string $sql, string $output = 'OBJECT') {
        $result = $this->run($sql);
        if (false === $result || is_bool($result)) { return null; }
        $row = $result->fetch_assoc();
        if (!is_array($row)) { return null; }
        return 'ARRAY_A' === $output ? $row : (object)$row;
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $result = $this->run($sql);
        if (false === $result || is_bool($result)) { return []; }
        $rows = [];
        while ($row = $result->fetch_assoc()) { $rows[] = 'ARRAY_A' === $output ? $row : (object)$row; }
        return $rows;
    }

    public function get_var(string $sql) {
        $result = $this->run($sql);
        if (false === $result || is_bool($result)) { return null; }
        $row = $result->fetch_row();
        return $row ? $row[0] : null;
    }

    private function sqlValue($value): string {
        if (null === $value) { return 'NULL'; }
        if (is_int($value) || is_float($value)) { return (string)$value; }
        return "'" . $this->conn->real_escape_string((string)$value) . "'";
    }

    public function insert(string $table, array $data, $format = null) {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_map(fn($v) => $this->sqlValue($v), array_values($data))) . ')';
        return false === $this->run($sql) ? false : 1;
    }

    public function replace(string $table, array $data, $format = null) {
        $cols = array_keys($data);
        $sql = 'REPLACE INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_map(fn($v) => $this->sqlValue($v), array_values($data))) . ')';
        return false === $this->run($sql) ? false : 1;
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null) {
        $sets = [];
        foreach ($data as $k => $v) { $sets[] = "`{$k}`=" . $this->sqlValue($v); }
        $conds = [];
        foreach ($where as $k => $v) {
            $conds[] = null === $v ? "`{$k}` IS NULL" : "`{$k}`=" . $this->sqlValue($v);
        }
        $result = $this->run("UPDATE `{$table}` SET " . implode(',', $sets) . ' WHERE ' . implode(' AND ', $conds));
        return false === $result ? false : $this->conn->affected_rows;
    }

    public function delete(string $table, array $where, $whereFormat = null) {
        $conds = [];
        foreach ($where as $k => $v) { $conds[] = "`{$k}`=" . $this->sqlValue($v); }
        $result = $this->run("DELETE FROM `{$table}` WHERE " . implode(' AND ', $conds));
        return false === $result ? false : $this->conn->affected_rows;
    }
}

$GLOBALS['wpdb'] = new Runtime_Test_WPDB($conn);
$GLOBALS['__runtime_options'] = [];
function dbDelta($sql) { global $wpdb; foreach ((array)$sql as $statement) { $wpdb->query($statement); } return []; }
function get_option($key, $default = false) { return $GLOBALS['__runtime_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null): bool { $GLOBALS['__runtime_options'][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = null): bool { if (array_key_exists($key, $GLOBALS['__runtime_options'])) return false; $GLOBALS['__runtime_options'][$key] = $value; return true; }
function delete_option($key): bool { unset($GLOBALS['__runtime_options'][$key]); return true; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function sanitize_text_field($value): string { return trim(strip_tags((string)$value)); }
function sanitize_key($value): string { return trim((string)preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)), '-_'); }
function wp_generate_uuid4(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff), random_int(0, 0xffff));
}

$plugin = dirname(__DIR__) . '/prstudio-unified-control/includes';
require_once $plugin . '/class-prstudio-uc-state-machine.php';
require_once $plugin . '/class-prstudio-uc-store.php';

$fails = [];
$passes = 0;
function check_runtime(bool $ok, string $label, string $detail = ''): void {
    global $fails, $passes;
    if ($ok) { $passes++; echo "PASS {$label}\n"; return; }
    $fails[] = $label . ($detail ? " -- {$detail}" : '');
    echo "FAIL {$label}" . ($detail ? " -- {$detail}" : '') . "\n";
}

try {
    PRSTUDIO_UC_Store::install();
    check_runtime(0 === count($GLOBALS['wpdb']->errors), 'schema installs without SQL errors');

    // Healthy scheduling/resume must not spend the failure budget.
    $planHash = hash('sha256', 'healthy-yield-plan');
    $job = PRSTUDIO_UC_Store::create_job('healthy yield liveness', 'agency', [], ['steps' => [], 'hash' => $planHash], '', $planHash, ['max_attempts' => 3]);
    $jobId = (string)($job['job_uuid'] ?? '');
    check_runtime('' !== $jobId, 'healthy liveness job created');
    $healthyCycles = 0;
    for ($i = 0; $i < 12 && '' !== $jobId; $i++) {
        $claimed = PRSTUDIO_UC_Store::claim_job($jobId, 'healthy-worker-' . $i);
        if (!$claimed) {
            check_runtime(false, 'healthy job remains claimable across > max_attempts scheduling cycles', "claim failed at cycle {$i}");
            break;
        }
        check_runtime(0 === (int)($claimed['attempts'] ?? -1), 'healthy claim does not consume failure attempts', 'attempts=' . (int)($claimed['attempts'] ?? -1));
        $lease = (string)($claimed['lease_token'] ?? '');
        $released = PRSTUDIO_UC_Store::release_leased_job($jobId, $lease, (array)($claimed['checkpoint'] ?? []), (int)($claimed['step_index'] ?? 0), (int)($claimed['progress'] ?? 0), 0);
        if (!$released) {
            check_runtime(false, 'healthy leased job can yield back to READY', "cycle={$i}");
            break;
        }
        $healthyCycles++;
    }
    check_runtime($healthyCycles >= 10, 'healthy job survives at least ten yield/resume cycles', "cycles={$healthyCycles}");
    $stranded = (int)$GLOBALS['wpdb']->get_var(
        'SELECT COUNT(*) FROM ' . PRSTUDIO_UC_Store::jobs_table() . " WHERE status='READY' AND attempts >= max_attempts"
    );
    check_runtime(0 === $stranded, 'no non-terminal READY job is stranded by retry accounting', "stranded={$stranded}");

    // A live job lease fences another worker and a valid heartbeat keeps ownership.
    $fence = PRSTUDIO_UC_Store::create_job('lease fence', 'agency', [], ['steps' => [], 'hash' => hash('sha256', 'fence')], '', hash('sha256', 'fence'), ['max_attempts' => 5]);
    $fenceId = (string)$fence['job_uuid'];
    $first = PRSTUDIO_UC_Store::claim_job($fenceId, 'worker-a');
    check_runtime(is_array($first), 'first worker acquires job lease');
    if (is_array($first)) {
        $firstLease = (string)($first['lease_token'] ?? '');
        check_runtime(PRSTUDIO_UC_Store::heartbeat_job($fenceId, $firstLease), 'live worker heartbeat extends its lease');
        check_runtime(!PRSTUDIO_UC_Store::heartbeat_job($fenceId, 'wrong-lease-token'), 'wrong lease token cannot heartbeat job');
    }
    $second = PRSTUDIO_UC_Store::claim_job($fenceId, 'worker-b');
    check_runtime(null === $second, 'second worker cannot acquire live job lease');

    // Stale job lease is a failure/recovery event, not a healthy scheduling event.
    if (is_array($first)) {
        $GLOBALS['wpdb']->update(PRSTUDIO_UC_Store::jobs_table(), ['lease_expires_gmt' => gmdate('Y-m-d H:i:s', time() - 120)], ['job_uuid' => $fenceId]);
        $beforeRecovery = PRSTUDIO_UC_Store::get_job($fenceId);
        $beforeFailures = (int)($beforeRecovery['attempts'] ?? -1);
        $recovered = PRSTUDIO_UC_Store::recover_stale_jobs(60);
        $afterRecovery = PRSTUDIO_UC_Store::get_job($fenceId);
        check_runtime($recovered >= 1, 'stale job lease is recovered');
        check_runtime('READY' === (string)($afterRecovery['status'] ?? ''), 'stale job returns to READY');
        check_runtime((int)($afterRecovery['attempts'] ?? -1) === $beforeFailures + 1, 'stale lease consumes exactly one failure attempt', 'before=' . $beforeFailures . ' after=' . (int)($afterRecovery['attempts'] ?? -1));
    }

    // Explicit execution failures consume the same failure budget and terminate at the ceiling.
    $retryHash = hash('sha256', 'retry-budget-plan');
    $retryJob = PRSTUDIO_UC_Store::create_job('retry budget', 'agency', [], ['steps' => [], 'hash' => $retryHash], '', $retryHash, ['max_attempts' => 2, 'backoff_seconds' => 5]);
    $retryId = (string)($retryJob['job_uuid'] ?? '');
    $retryClaimOne = '' !== $retryId ? PRSTUDIO_UC_Store::claim_job($retryId, 'retry-worker-1') : null;
    check_runtime(is_array($retryClaimOne), 'retry job first claim succeeds');
    if (is_array($retryClaimOne)) {
        check_runtime(0 === (int)($retryClaimOne['attempts'] ?? -1), 'first retry claim does not pre-spend failure budget', 'attempts=' . (int)($retryClaimOne['attempts'] ?? -1));
        $retryOne = PRSTUDIO_UC_Store::retry_leased_job($retryId, (string)$retryClaimOne['lease_token'], ['code' => 'synthetic_retryable', 'class' => 'synthetic_retryable']);
        check_runtime(is_array($retryOne) && 'READY' === (string)($retryOne['status'] ?? ''), 'first retryable failure returns job to READY');
        check_runtime(1 === (int)($retryOne['attempts'] ?? -1), 'first retryable failure consumes exactly one attempt', 'attempts=' . (int)($retryOne['attempts'] ?? -1));
        $GLOBALS['wpdb']->update(PRSTUDIO_UC_Store::jobs_table(), ['available_gmt' => gmdate('Y-m-d H:i:s', time() - 1)], ['job_uuid' => $retryId]);
        $retryClaimTwo = PRSTUDIO_UC_Store::claim_job($retryId, 'retry-worker-2');
        check_runtime(is_array($retryClaimTwo), 'retry job second claim succeeds before exhaustion');
        if (is_array($retryClaimTwo)) {
            check_runtime(1 === (int)($retryClaimTwo['attempts'] ?? -1), 'second claim preserves prior failure count', 'attempts=' . (int)($retryClaimTwo['attempts'] ?? -1));
            $retryTwo = PRSTUDIO_UC_Store::retry_leased_job($retryId, (string)$retryClaimTwo['lease_token'], ['code' => 'synthetic_retryable', 'class' => 'synthetic_retryable']);
            check_runtime(is_array($retryTwo) && 'DEAD_LETTER' === (string)($retryTwo['status'] ?? ''), 'retry exhaustion terminates in DEAD_LETTER');
            check_runtime(2 === (int)($retryTwo['attempts'] ?? -1), 'dead-lettered retry records final failure attempt', 'attempts=' . (int)($retryTwo['attempts'] ?? -1));
            $deadRows = (int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM " . PRSTUDIO_UC_Store::dead_letters_table() . " WHERE job_uuid='" . $retryId . "'");
            check_runtime(1 === $deadRows, 'retry exhaustion writes one dead-letter record', "rows={$deadRows}");
        }
    }
    $strandedAfterRetry = (int)$GLOBALS['wpdb']->get_var(
        'SELECT COUNT(*) FROM ' . PRSTUDIO_UC_Store::jobs_table() . " WHERE status='READY' AND attempts >= max_attempts"
    );
    check_runtime(0 === $strandedAfterRetry, 'retry exhaustion leaves no READY job at failure ceiling', "stranded={$strandedAfterRetry}");

    // The general dead-letter path must never create a receipt before winning
    // the fenced state transition. A wrong lease deterministically reproduces
    // the CAS-loss condition that used to leave a false dead-letter row behind.
    $receiptHash = hash('sha256', 'dead-letter-receipt-race');
    $receiptJob = PRSTUDIO_UC_Store::create_job('dead-letter receipt race', 'agency', [], ['steps' => [], 'hash' => $receiptHash], '', $receiptHash, ['max_attempts' => 3]);
    $receiptId = (string)($receiptJob['job_uuid'] ?? '');
    $receiptClaim = '' !== $receiptId ? PRSTUDIO_UC_Store::claim_job($receiptId, 'receipt-worker') : null;
    check_runtime(is_array($receiptClaim), 'dead-letter receipt race job is leased');
    if (is_array($receiptClaim)) {
        $receiptLease = (string)($receiptClaim['lease_token'] ?? '');
        $receiptError = ['code' => 'synthetic_terminal', 'class' => 'synthetic_terminal', 'message' => 'deterministic dead-letter fencing test'];
        $wrongDeadLetter = PRSTUDIO_UC_Store::dead_letter_job($receiptId, $receiptError, 'synthetic_terminal', 'wrong-lease-token');
        $afterWrongDeadLetter = PRSTUDIO_UC_Store::get_job($receiptId);
        $falseReceipts = (int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM " . PRSTUDIO_UC_Store::dead_letters_table() . " WHERE job_uuid='" . $receiptId . "'");
        check_runtime(false === $wrongDeadLetter, 'dead-letter transition rejects a lost lease');
        check_runtime('RUNNING' === (string)($afterWrongDeadLetter['status'] ?? ''), 'lost dead-letter CAS leaves job RUNNING', 'status=' . (string)($afterWrongDeadLetter['status'] ?? ''));
        check_runtime(hash_equals($receiptLease, (string)($afterWrongDeadLetter['lease_token'] ?? '')), 'lost dead-letter CAS preserves winning lease');
        check_runtime(0 === $falseReceipts, 'lost dead-letter CAS creates no false receipt', "rows={$falseReceipts}");

        $wonDeadLetter = PRSTUDIO_UC_Store::dead_letter_job($receiptId, $receiptError, 'synthetic_terminal', $receiptLease);
        $afterWonDeadLetter = PRSTUDIO_UC_Store::get_job($receiptId);
        $wonReceipts = (int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM " . PRSTUDIO_UC_Store::dead_letters_table() . " WHERE job_uuid='" . $receiptId . "'");
        check_runtime(true === $wonDeadLetter, 'valid dead-letter lease commits transition and receipt');
        check_runtime('DEAD_LETTER' === (string)($afterWonDeadLetter['status'] ?? ''), 'valid dead-letter transition reaches DEAD_LETTER');
        check_runtime(empty($afterWonDeadLetter['lease_token']) && empty($afterWonDeadLetter['lease_expires_gmt']), 'dead-letter transition clears lease ownership');
        check_runtime(1 === $wonReceipts, 'valid dead-letter transition writes exactly one receipt', "rows={$wonReceipts}");

        $staleReplay = PRSTUDIO_UC_Store::dead_letter_job($receiptId, $receiptError, 'synthetic_terminal', $receiptLease);
        $replayReceipts = (int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM " . PRSTUDIO_UC_Store::dead_letters_table() . " WHERE job_uuid='" . $receiptId . "'");
        check_runtime(false === $staleReplay, 'stale lease cannot replay a terminal dead-letter transition');
        check_runtime(1 === $replayReceipts, 'stale dead-letter replay cannot duplicate receipt', "rows={$replayReceipts}");
    }

    // Browser stale-lease recovery must execute against the real engine.
    $task = PRSTUDIO_UC_Store::create_task('playwright_observation_bundle', ['url' => 'https://localhost.invalid/'], null, '', hash('sha256', 'browser-recovery'), '');
    $taskId = (string)($task['task_uuid'] ?? '');
    $claimedTask = PRSTUDIO_UC_Store::claim_next('device-real-db');
    check_runtime(is_array($claimedTask) && (string)($claimedTask['task_uuid'] ?? '') === $taskId, 'browser task is claimed before recovery test');
    if (is_array($claimedTask)) {
        $running = PRSTUDIO_UC_Store::mark_running($taskId, (string)$claimedTask['lease_token']);
        check_runtime(is_array($running), 'browser task enters RUNNING');
        $GLOBALS['wpdb']->update(PRSTUDIO_UC_Store::tasks_table(), ['lease_expires_gmt' => gmdate('Y-m-d H:i:s', time() - 120)], ['task_uuid' => $taskId]);
        try {
            $count = PRSTUDIO_UC_Store::recover_stale_tasks();
            $after = PRSTUDIO_UC_Store::get_task($taskId);
            check_runtime($count >= 1, 'real SQL stale browser recovery affects a row');
            check_runtime(PRSTUDIO_UC_State_Machine::QUEUED === (string)($after['status'] ?? ''), 'stale browser task is requeued');
            check_runtime(empty($after['lease_token']) && empty($after['lease_expires_gmt']), 'stale browser lease is cleared');
            check_runtime((int)($after['recovery_count'] ?? 0) >= 1, 'browser recovery counter advances');
        } catch (Throwable $e) {
            check_runtime(false, 'real SQL stale browser recovery executes', $e->getMessage());
        }
    }

} catch (Throwable $e) {
    check_runtime(false, 'runtime DB test completed without uncaught exception', get_class($e) . ': ' . $e->getMessage());
}

printf("SUMMARY %d passed, %d failed, SQL=%d\n", $passes, count($fails), $GLOBALS['wpdb']->queries_run);
$conn->query("DROP DATABASE IF EXISTS `{$dbname}`");
if ($fails) {
    foreach ($fails as $fail) { fwrite(STDERR, " - {$fail}\n"); }
    exit(1);
}
exit(0);
