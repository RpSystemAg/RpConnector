<?php
/**
 * Real WordPress + MariaDB concurrency test for OAuth shared state.
 *
 * Direct execution is mandatory: RP_WP_PATH must point at an installed
 * WordPress containing the exact candidate plugin. Child modes are internal
 * workers used to create real cross-process contention.
 */
declare(strict_types=1);

$wpPath = rtrim((string)(getenv('RP_WP_PATH') ?: ''), '/');
if ('' === $wpPath || !is_file($wpPath . '/wp-load.php')) {
    fwrite(STDERR, "FAIL php-oauth-concurrency-integration: RP_WP_PATH must point to real WordPress\n");
    exit(1);
}
require_once $wpPath . '/wp-load.php';
if (!class_exists('PRSTUDIO_UC_MCP_Auth_V5')) {
    fwrite(STDERR, "FAIL php-oauth-concurrency-integration: candidate OAuth runtime missing\n");
    exit(1);
}

function oauth_private(string $name, array $args = []) {
    static $reflection = null;
    if (!$reflection) { $reflection = new ReflectionClass('PRSTUDIO_UC_MCP_Auth_V5'); }
    $method = $reflection->getMethod($name);
    $method->setAccessible(true);
    return $method->invokeArgs(null, $args);
}

function oauth_result($value): array {
    if (is_wp_error($value)) {
        return ['kind' => 'wp_error', 'code' => $value->get_error_code(), 'message' => $value->get_error_message()];
    }
    if (is_array($value)) {
        return ['kind' => 'array', 'access_token' => (string)($value['access_token'] ?? ''), 'refresh_token' => (string)($value['refresh_token'] ?? ''), 'client_id' => (string)($value['client_id'] ?? '')];
    }
    return ['kind' => 'scalar', 'value' => $value];
}

function child_emit($value, int $exit = 0): never {
    echo 'RP_OAUTH_CHILD=' . wp_json_encode(oauth_result($value), JSON_UNESCAPED_SLASHES) . "\n";
    exit($exit);
}

function child_payload(string $raw): array {
    $decoded = base64_decode($raw, true);
    $value = false === $decoded ? null : json_decode($decoded, true);
    if (!is_array($value)) { throw new RuntimeException('invalid child payload'); }
    return $value;
}

if (($argv[1] ?? '') === '--child') {
    try {
        $mode = (string)($argv[2] ?? '');
        $payload = child_payload((string)($argv[3] ?? ''));
        if ('refresh' === $mode) {
            child_emit(oauth_private('rotate_refresh_token_atomic', [(string)$payload['token'], (string)$payload['client_id'], (string)$payload['resource']]));
        }
        if ('code' === $mode) {
            $request = new WP_REST_Request('POST', '/');
            foreach ((array)$payload['params'] as $key => $value) { $request->set_param((string)$key, $value); }
            child_emit(oauth_private('exchange_code', [$request, (string)$payload['client_id']]));
        }
        if ('rate' === $mode) {
            child_emit(oauth_private('atomic_rate_limit', [(string)$payload['key'], (int)$payload['limit'], 120]));
        }
        if ('register' === $mode) {
            $_SERVER['REMOTE_ADDR'] = (string)$payload['ip'];
            child_emit(PRSTUDIO_UC_MCP_Auth_V5::register_client((array)$payload['registration']));
        }
        if ('issue' === $mode) {
            child_emit(oauth_private('issue_tokens', [(string)$payload['client_id'], (string)$payload['scope'], (string)$payload['resource']]));
        }
        if ('verify' === $mode) {
            child_emit(PRSTUDIO_UC_MCP_Auth_V5::verify_access_token((string)$payload['token'], false));
        }
        if ('delete' === $mode) {
            oauth_private('delete_token_record', [(string)$payload['id']]);
            child_emit(true);
        }
        if ('revoke' === $mode) {
            child_emit(PRSTUDIO_UC_MCP_Auth_V5::revoke_all());
        }
        throw new RuntimeException('unknown child mode ' . $mode);
    } catch (Throwable $error) {
        echo 'RP_OAUTH_CHILD=' . wp_json_encode(['kind' => 'throw', 'message' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";
        exit(2);
    }
}

$fails = [];
$passes = 0;
function oauth_check(bool $ok, string $label, string $detail = ''): void {
    global $fails, $passes;
    if ($ok) { $passes++; echo "PASS {$label}\n"; return; }
    $fails[] = $label . ('' !== $detail ? " -- {$detail}" : '');
    echo "FAIL {$label}" . ('' !== $detail ? " -- {$detail}" : '') . "\n";
}

function oauth_cache_reset(): void {
    wp_cache_flush();
    foreach (['prstudio_mcp_v5_clients', 'prstudio_mcp_v5_tokens', 'prstudio_mcp_v5_generation'] as $name) {
        wp_cache_delete($name, 'options');
    }
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}

function oauth_reset_state(): void {
    global $wpdb;
    delete_option('prstudio_mcp_v5_clients');
    delete_option('prstudio_mcp_v5_tokens');
    delete_option('prstudio_mcp_v5_generation');
    update_option('prstudio_mcp_v5_generation', 1, false);
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_prstudio_mcp_v5_%' OR option_name LIKE '_transient_timeout_prstudio_mcp_v5_%'");
    oauth_cache_reset();
}

function oauth_children(string $mode, array $payloads): array {
    $children = [];
    foreach ($payloads as $index => $payload) {
        $encoded = base64_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, '--child', $mode, $encoded],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            array_merge($_ENV, ['RP_WP_PATH' => (string)getenv('RP_WP_PATH')])
        );
        if (!is_resource($process)) { throw new RuntimeException("cannot start {$mode} child {$index}"); }
        fclose($pipes[0]);
        $children[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'index' => $index];
    }
    $results = [];
    foreach ($children as $child) {
        $stdout = stream_get_contents($child['stdout']);
        $stderr = stream_get_contents($child['stderr']);
        fclose($child['stdout']); fclose($child['stderr']);
        $exit = proc_close($child['process']);
        if (!preg_match_all('/^RP_OAUTH_CHILD=(.+)$/m', (string)$stdout, $matches) || empty($matches[1])) {
            $results[] = ['kind' => 'transport_error', 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
            continue;
        }
        $decoded = json_decode((string)end($matches[1]), true);
        if (!is_array($decoded)) { $decoded = ['kind' => 'decode_error']; }
        $decoded['exit'] = $exit;
        $decoded['stderr'] = trim((string)$stderr);
        $results[] = $decoded;
    }
    return $results;
}

function oauth_count_kind(array $results, string $kind, string $code = ''): int {
    return count(array_filter($results, static function (array $row) use ($kind, $code): bool {
        return ($row['kind'] ?? '') === $kind && ('' === $code || ($row['code'] ?? '') === $code) && 0 === (int)($row['exit'] ?? -1);
    }));
}

function oauth_lock_name(string $scope): string {
    global $wpdb;
    $blogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1;
    $namespace = (defined('DB_NAME') ? (string)DB_NAME : '') . '|' . (string)$wpdb->prefix . '|' . $blogId . '|' . $scope;
    return 'prstudio_mcp_v5_' . substr(hash('sha256', $namespace), 0, 40);
}

function oauth_lock_is_free(string $scope): bool {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', oauth_lock_name($scope)));
    return '1' === (string)$value;
}

function oauth_db_killer(): mysqli {
    if (!class_exists('mysqli')) { throw new RuntimeException('mysqli is required for connection-loss injection'); }
    $dbHost = (string)DB_HOST;
    $host = $dbHost;
    $port = 3306;
    if (preg_match('/^(.+):(\d+)$/', $dbHost, $match)) { $host = $match[1]; $port = (int)$match[2]; }
    $mysqli = @new mysqli($host, (string)DB_USER, (string)DB_PASSWORD, (string)DB_NAME, $port);
    if ($mysqli->connect_errno) { throw new RuntimeException('killer DB connection failed: ' . $mysqli->connect_error); }
    return $mysqli;
}

try {
    oauth_reset_state();
    $resource = PRSTUDIO_UC_MCP_Auth_V5::mcp_url();

    // Normal and exceptional lock paths must release ownership.
    $sameScope = oauth_private('with_db_lock', ['runtime-nested', static function () {
        return oauth_private('with_db_lock', ['runtime-nested', static fn() => 'nested-ok', 0]);
    }, 1]);
    oauth_check('nested-ok' === $sameScope, 'same-scope nested lock remains executable');
    oauth_check(oauth_lock_is_free('runtime-nested'), 'same-scope nested lock is released');

    $crossScope = oauth_private('with_db_lock', ['runtime-outer', static function () {
        return oauth_private('with_db_lock', ['runtime-inner', static fn() => true, 0]);
    }, 1]);
    oauth_check(is_wp_error($crossScope) && 'oauth_state_lock_order' === $crossScope->get_error_code(), 'cross-scope nested lock is rejected');
    oauth_check(oauth_lock_is_free('runtime-outer'), 'outer lock released after nested-order rejection');

    $threw = false;
    try {
        oauth_private('with_db_lock', ['runtime-throw', static function () { throw new RuntimeException('expected-lock-test'); }, 1]);
    } catch (RuntimeException $error) { $threw = 'expected-lock-test' === $error->getMessage(); }
    oauth_check($threw, 'callback exception propagates');
    oauth_check(oauth_lock_is_free('runtime-throw'), 'lock released after callback exception');

    // Kill the exact advisory-lock DB session. wpdb may reconnect, but success
    // must not be reported after the original connection-scoped lock vanished.
    $lost = oauth_private('with_db_lock', ['runtime-connection-loss', static function () {
        global $wpdb;
        $connectionId = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
        $killer = oauth_db_killer();
        if (!$killer->query('KILL CONNECTION ' . $connectionId)) { throw new RuntimeException('cannot kill OAuth lock connection: ' . $killer->error); }
        $killer->close();
        usleep(100000);
        update_option('prstudio_oauth_connection_loss_probe', wp_generate_uuid4(), false);
        return true;
    }, 1]);
    oauth_check(is_wp_error($lost) && 'oauth_state_lock_lost' === $lost->get_error_code(), 'connection loss is detected and returned as failure', is_wp_error($lost) ? $lost->get_error_code() : gettype($lost));
    oauth_check(oauth_lock_is_free('runtime-connection-loss'), 'killed connection cannot leave advisory lock held');

    // Concurrent token creation must retain every successful issuance.
    oauth_reset_state();
    $issuePayloads = [];
    for ($i = 0; $i < 16; $i++) { $issuePayloads[] = ['client_id' => 'issue-' . $i, 'scope' => 'prstudio.read offline_access', 'resource' => $resource]; }
    $issued = oauth_children('issue', $issuePayloads);
    oauth_check(16 === oauth_count_kind($issued, 'array'), '16 concurrent token issuances all succeed');
    oauth_cache_reset();
    $issuedRegistry = get_option('prstudio_mcp_v5_tokens', []);
    oauth_check(is_array($issuedRegistry) && 16 === count($issuedRegistry), 'concurrent token issuance has zero lost registry updates', 'persisted=' . (is_array($issuedRegistry) ? count($issuedRegistry) : -1));

    // Concurrent verification/touch of one token must preserve the record.
    oauth_reset_state();
    $single = oauth_private('issue_tokens', ['touch-client', 'prstudio.read offline_access', $resource]);
    if (!is_array($single) || empty($single['access_token'])) { throw new RuntimeException('cannot create touch token'); }
    $verifyPayloads = array_fill(0, 16, ['token' => (string)$single['access_token']]);
    $verified = oauth_children('verify', $verifyPayloads);
    oauth_check(16 === oauth_count_kind($verified, 'array'), '16 concurrent access-token verifications succeed');
    oauth_cache_reset();
    $touchRegistry = get_option('prstudio_mcp_v5_tokens', []);
    $touchRecord = is_array($touchRegistry) ? reset($touchRegistry) : false;
    oauth_check(is_array($touchRecord) && (int)($touchRecord['last_used'] ?? 0) > 0, 'concurrent last_used touch persists without deleting token');

    // Concurrent delete is idempotent and cannot resurrect the token.
    $tokenId = is_array($touchRecord) ? (string)($touchRecord['id'] ?? '') : '';
    $deletePayloads = array_fill(0, 8, ['id' => $tokenId]);
    $deleted = oauth_children('delete', $deletePayloads);
    oauth_check(8 === oauth_count_kind($deleted, 'scalar'), 'concurrent token deletion workers complete');
    oauth_cache_reset();
    $afterDelete = get_option('prstudio_mcp_v5_tokens', []);
    oauth_check(is_array($afterDelete) && !isset($afterDelete[$tokenId]), 'deleted token is not resurrected by concurrent deletes');

    // Refresh rotation must have exactly one winner for one old refresh token.
    oauth_reset_state();
    $seed = oauth_private('issue_tokens', ['refresh-client', 'prstudio.read prstudio.write offline_access', $resource]);
    if (!is_array($seed) || empty($seed['refresh_token'])) { throw new RuntimeException('cannot create refresh seed'); }
    $refreshPayloads = array_fill(0, 12, ['token' => (string)$seed['refresh_token'], 'client_id' => 'refresh-client', 'resource' => $resource]);
    $refresh = oauth_children('refresh', $refreshPayloads);
    $refreshWins = oauth_count_kind($refresh, 'array');
    $refreshRejects = oauth_count_kind($refresh, 'wp_error', 'invalid_grant');
    oauth_check(1 === $refreshWins && 11 === $refreshRejects, 'refresh rotation has exactly one winner', "wins={$refreshWins} invalid_grant={$refreshRejects}");
    oauth_cache_reset();
    $refreshRegistry = get_option('prstudio_mcp_v5_tokens', []);
    oauth_check(is_array($refreshRegistry) && 1 === count($refreshRegistry), 'refresh rotation persists only replacement token');

    // One authorization code must have exactly one successful exchange.
    oauth_reset_state();
    $codeId = bin2hex(random_bytes(8));
    $verifier = str_repeat('V', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $code = 'prstudio_ac_' . $codeId . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $codeHash = oauth_private('secret_hash', [$code]);
    $redirect = 'http://127.0.0.1/oauth-concurrency-callback';
    set_transient('prstudio_mcp_v5_code_' . $codeId, [
        'hash' => $codeHash, 'client_id' => 'code-client', 'redirect_uri' => $redirect,
        'scope' => 'prstudio.read offline_access', 'code_challenge' => $challenge,
        'resource' => $resource, 'generation' => 1, 'created_at' => time(),
    ], 300);
    $params = ['code' => $code, 'redirect_uri' => $redirect, 'resource' => $resource, 'code_verifier' => $verifier];
    $codePayloads = array_fill(0, 12, ['params' => $params, 'client_id' => 'code-client']);
    $codes = oauth_children('code', $codePayloads);
    $codeWins = oauth_count_kind($codes, 'array');
    $codeRejects = oauth_count_kind($codes, 'wp_error', 'invalid_grant');
    oauth_check(1 === $codeWins && 11 === $codeRejects, 'authorization code is single-use across processes', "wins={$codeWins} invalid_grant={$codeRejects}");
    oauth_cache_reset();
    $codeRegistry = get_option('prstudio_mcp_v5_tokens', []);
    oauth_check(is_array($codeRegistry) && 1 === count($codeRegistry), 'single-use code creates exactly one token record');

    // Atomic rate limit must admit exactly the configured number under contention.
    global $wpdb;
    $rateKey = 'prstudio_mcp_v5_runtime_rl_' . bin2hex(random_bytes(6));
    delete_transient($rateKey);
    $ratePayloads = array_fill(0, 32, ['key' => $rateKey, 'limit' => 12]);
    $rates = oauth_children('rate', $ratePayloads);
    $rateAllowed = count(array_filter($rates, static fn(array $r): bool => ($r['kind'] ?? '') === 'scalar' && true === ($r['value'] ?? null) && 0 === (int)($r['exit'] ?? -1)));
    $rateDenied = count(array_filter($rates, static fn(array $r): bool => ($r['kind'] ?? '') === 'scalar' && false === ($r['value'] ?? null) && 0 === (int)($r['exit'] ?? -1)));
    oauth_check(12 === $rateAllowed && 20 === $rateDenied, 'atomic rate limit cannot overrun', "allowed={$rateAllowed} denied={$rateDenied}");
    oauth_cache_reset();
    oauth_check(12 === (int)get_transient($rateKey), 'rate-limit persisted counter equals admitted requests');

    // Fill below MAX_CLIENTS, then hit the capacity concurrently. A capacity
    // bound may reject new work but must never evict an already accepted client.
    oauth_reset_state();
    $acceptedIds = [];
    for ($i = 0; $i < 90; $i++) {
        $_SERVER['REMOTE_ADDR'] = '10.70.' . intdiv($i, 250) . '.' . (($i % 250) + 1);
        $record = PRSTUDIO_UC_MCP_Auth_V5::register_client([
            'client_name' => 'prefill-' . $i,
            'redirect_uris' => ['http://127.0.0.1/prefill/' . $i],
            'scope' => 'prstudio.read',
        ]);
        if (!is_array($record) || empty($record['client_id'])) { throw new RuntimeException('DCR prefill failed at ' . $i); }
        $acceptedIds[] = (string)$record['client_id'];
    }
    $capacityPayloads = [];
    for ($i = 0; $i < 20; $i++) {
        $capacityPayloads[] = [
            'ip' => '10.71.0.' . ($i + 1),
            'registration' => ['client_name' => 'capacity-' . $i, 'redirect_uris' => ['http://127.0.0.1/capacity/' . $i], 'scope' => 'prstudio.read'],
        ];
    }
    $capacity = oauth_children('register', $capacityPayloads);
    $capacityAccepted = array_values(array_filter($capacity, static fn(array $r): bool => ($r['kind'] ?? '') === 'array' && 0 === (int)($r['exit'] ?? -1)));
    $capacityRejected = oauth_count_kind($capacity, 'wp_error', 'client_registry_full');
    foreach ($capacityAccepted as $row) { if (!empty($row['client_id'])) { $acceptedIds[] = (string)$row['client_id']; } }
    oauth_check(10 === count($capacityAccepted) && 10 === $capacityRejected, 'DCR capacity has exactly ten winners and ten explicit rejections', 'accepted=' . count($capacityAccepted) . " rejected={$capacityRejected}");
    oauth_cache_reset();
    $clients = get_option('prstudio_mcp_v5_clients', []);
    oauth_check(is_array($clients) && 100 === count($clients), 'DCR registry stops exactly at MAX_CLIENTS', 'persisted=' . (is_array($clients) ? count($clients) : -1));
    $missingAccepted = 0;
    foreach ($acceptedIds as $id) { if (!is_array($clients) || !isset($clients[$id])) { $missingAccepted++; } }
    oauth_check(0 === $missingAccepted, 'DCR capacity never evicts an accepted client', "missing={$missingAccepted}");

    // Global revoke must invalidate existing access material and leave no token rows.
    oauth_reset_state();
    $revocable = oauth_private('issue_tokens', ['revoke-client', 'prstudio.read offline_access', $resource]);
    if (!is_array($revocable) || empty($revocable['access_token'])) { throw new RuntimeException('cannot create revocation seed'); }
    $revoke = PRSTUDIO_UC_MCP_Auth_V5::revoke_all();
    oauth_check(true === $revoke, 'global revoke reports success only after persistence');
    oauth_cache_reset();
    $revokedRegistry = get_option('prstudio_mcp_v5_tokens', []);
    oauth_check(is_array($revokedRegistry) && 0 === count($revokedRegistry), 'global revoke clears token registry');
    $afterRevoke = PRSTUDIO_UC_MCP_Auth_V5::verify_access_token((string)$revocable['access_token'], false);
    oauth_check(is_wp_error($afterRevoke) && 'invalid_token' === $afterRevoke->get_error_code(), 'revoked access token is rejected');

} catch (Throwable $error) {
    oauth_check(false, 'runtime test completed without uncaught exception', $error->getMessage());
}

oauth_reset_state();
delete_option('prstudio_oauth_connection_loss_probe');

echo 'OAUTH RUNTIME CONCURRENCY: passes=' . $passes . ' failures=' . count($fails) . "\n";
foreach ($fails as $failure) { echo 'ERROR ' . $failure . "\n"; }
exit($fails ? 1 : 0);
