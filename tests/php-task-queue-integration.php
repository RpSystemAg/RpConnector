<?php
/**
 * Task queue integration test -- runs the real dispatch code against a real database.
 *
 * WHY THIS EXISTS
 * ---------------
 * PR STUDIO shipped a task queue that never dispatched a single task. The
 * Browser Agent connected, heartbeat normally, and every task sat at
 * status=QUEUED with attempt_count=0 until the turn deadline killed it. The
 * cause was a `// phpcs:ignore` suppression line written *inside* the SQL
 * string literal of PRSTUDIO_UC_Store::claim_next(), between `LIMIT 1` and
 * `FOR UPDATE`. MySQL has no `//` comment syntax -- it reads `//` as a division
 * operator and rejects the whole statement, so get_row() returned null forever
 * and claim_next() concluded there was no work to do.
 *
 * Every existing check passed while that shipped:
 *   - `php -l` passes, because the file is valid PHP. The bug is a valid string
 *     containing invalid SQL.
 *   - The PHP smoke tests stub $wpdb, so no SQL text is ever parsed or executed
 *     by anything -- a stub returns whatever the test wants regardless of
 *     whether the query is syntactically legal.
 *   - Reading the diff, the line is indistinguishable from the hundreds of
 *     legitimate suppression comments surrounding it.
 *
 * A stub cannot catch a malformed query. Only a database can. So this test
 * connects to a real MariaDB/MySQL, creates the real schema, and calls the
 * plugin's actual claim_next() -- the same code path the Browser Agent hits.
 *
 * Usage:
 *   PRSTUDIO_TEST_DB_HOST=127.0.0.1 PRSTUDIO_TEST_DB_PORT=3399 \
 *   PRSTUDIO_TEST_DB_USER=root PRSTUDIO_TEST_DB_PASS= \
 *   php tests/php-task-queue-integration.php
 *
 * Skips cleanly (exit 0) when no database is configured, so it never blocks a
 * machine without one -- but CI must provide one, or this class of bug ships again.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );
define( 'PRSTUDIO_UC_VERSION', '1.0.0' );

// The code under test does `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`
// to reach dbDelta(). Build that path under a scratch root and leave the file
// empty -- dbDelta is defined below and executes the CREATE TABLE statements for
// real, which is the entire point of this test.
$wp_root = sys_get_temp_dir() . '/prstudio-queue-test-wp/';
@mkdir( $wp_root . 'wp-admin/includes', 0777, true );
if ( ! file_exists( $wp_root . 'wp-admin/includes/upgrade.php' ) ) {
    file_put_contents( $wp_root . 'wp-admin/includes/upgrade.php', "<?php\n// test stub: dbDelta() is defined by the harness.\n" );
}
define( 'ABSPATH', $wp_root );

$host = getenv( 'PRSTUDIO_TEST_DB_HOST' ) ?: '';
$port = (int) ( getenv( 'PRSTUDIO_TEST_DB_PORT' ) ?: 3306 );
$user = getenv( 'PRSTUDIO_TEST_DB_USER' ) ?: 'root';
$pass = getenv( 'PRSTUDIO_TEST_DB_PASS' );
$pass = false === $pass ? '' : $pass;
$dbname = getenv( 'PRSTUDIO_TEST_DB_NAME' ) ?: 'prstudio_queue_test';

if ( '' === $host ) {
    echo "SKIP php-task-queue-integration: PRSTUDIO_TEST_DB_HOST not set\n";
    exit( 0 );
}
if ( ! class_exists( 'mysqli' ) ) {
    echo "SKIP php-task-queue-integration: mysqli extension unavailable\n";
    exit( 0 );
}

mysqli_report( MYSQLI_REPORT_OFF );
$conn = @new mysqli( $host, $user, $pass, '', $port );
if ( $conn->connect_errno ) {
    echo "SKIP php-task-queue-integration: cannot connect ({$conn->connect_error})\n";
    exit( 0 );
}
$conn->query( "DROP DATABASE IF EXISTS `{$dbname}`" );
$conn->query( "CREATE DATABASE `{$dbname}`" );
$conn->select_db( $dbname );

/* ------------------------------------------------------------------------- *
 * Minimal WordPress surface. $wpdb executes real SQL -- that is the point.
 * ------------------------------------------------------------------------- */

final class Test_WPDB {
    public string $prefix = 'wp_';
    public string $charset = 'utf8mb4';
    public string $collate = 'utf8mb4_unicode_ci';
    public string $last_error = '';
    /** @var array<int,array{sql:string,error:string}> */
    public array $errors = array();
    public int $queries_run = 0;
    private mysqli $conn;

    public function __construct( mysqli $conn ) { $this->conn = $conn; }

    public function get_charset_collate(): string {
        return "DEFAULT CHARACTER SET {$this->charset} COLLATE {$this->collate}";
    }

    /** Mirrors $wpdb->prepare closely enough for these queries. */
    public function prepare( string $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
        $out = '';
        $i = 0;
        $len = strlen( $query );
        $arg_index = 0;
        while ( $i < $len ) {
            $ch = $query[ $i ];
            if ( '%' === $ch && $i + 1 < $len ) {
                $next = $query[ $i + 1 ];
                if ( '%' === $next ) { $out .= '%'; $i += 2; continue; }
                if ( in_array( $next, array( 's', 'd', 'f' ), true ) ) {
                    $value = $args[ $arg_index ] ?? null;
                    $arg_index++;
                    if ( 'd' === $next ) { $out .= (string) (int) $value; }
                    elseif ( 'f' === $next ) { $out .= (string) (float) $value; }
                    else { $out .= "'" . $this->conn->real_escape_string( (string) $value ) . "'"; }
                    $i += 2;
                    continue;
                }
            }
            $out .= $ch;
            $i++;
        }
        return $out;
    }

    private function run( string $sql ) {
        $this->queries_run++;
        $res = $this->conn->query( $sql );
        if ( false === $res ) {
            $this->last_error = $this->conn->error;
            $this->errors[] = array( 'sql' => $sql, 'error' => $this->conn->error );
            return false;
        }
        $this->last_error = '';
        return $res;
    }

    public function query( string $sql ) {
        $res = $this->run( $sql );
        if ( false === $res ) { return false; }
        return is_bool( $res ) ? $this->conn->affected_rows : $res->num_rows;
    }

    public function get_row( string $sql, string $output = 'OBJECT' ) {
        $res = $this->run( $sql );
        if ( false === $res || is_bool( $res ) ) { return null; }
        $row = $res->fetch_assoc();
        return is_array( $row ) ? $row : null;
    }

    public function get_var( string $sql ) {
        $res = $this->run( $sql );
        if ( false === $res || is_bool( $res ) ) { return null; }
        $row = $res->fetch_row();
        return $row ? $row[0] : null;
    }

    public function get_results( string $sql, string $output = 'OBJECT' ) {
        $res = $this->run( $sql );
        if ( false === $res || is_bool( $res ) ) { return array(); }
        $rows = array();
        while ( $r = $res->fetch_assoc() ) { $rows[] = $r; }
        return $rows;
    }

    public function insert( string $table, array $data, $format = null ) {
        $cols = array_keys( $data );
        $vals = array();
        foreach ( $data as $v ) {
            $vals[] = null === $v ? 'NULL' : "'" . $this->conn->real_escape_string( (string) $v ) . "'";
        }
        $sql = "INSERT INTO `{$table}` (`" . implode( '`,`', $cols ) . '`) VALUES (' . implode( ',', $vals ) . ')';
        return false === $this->run( $sql ) ? false : 1;
    }

    public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
        $sets = array();
        foreach ( $data as $k => $v ) {
            $sets[] = "`{$k}`=" . ( null === $v ? 'NULL' : "'" . $this->conn->real_escape_string( (string) $v ) . "'" );
        }
        $conds = array();
        foreach ( $where as $k => $v ) {
            $conds[] = "`{$k}`=" . ( null === $v ? 'NULL' : "'" . $this->conn->real_escape_string( (string) $v ) . "'" );
        }
        $sql = "UPDATE `{$table}` SET " . implode( ',', $sets ) . ' WHERE ' . implode( ' AND ', $conds );
        $res = $this->run( $sql );
        return false === $res ? false : $this->conn->affected_rows;
    }

    public function delete( string $table, array $where, $where_format = null ) {
        $conds = array();
        foreach ( $where as $k => $v ) { $conds[] = "`{$k}`='" . $this->conn->real_escape_string( (string) $v ) . "'"; }
        $res = $this->run( "DELETE FROM `{$table}` WHERE " . implode( ' AND ', $conds ) );
        return false === $res ? false : $this->conn->affected_rows;
    }
}

$GLOBALS['wpdb'] = new Test_WPDB( $conn );

// $wpdb result-format constants.
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// WordPress function surface used by the code under test.
function dbDelta( $sql ) { global $wpdb; foreach ( (array) $sql as $stmt ) { $wpdb->query( $stmt ); } return array(); }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function add_option( $k, $v, $x = '', $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function esc_sql( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_generate_uuid4() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ),
        random_int( 0, 0x0fff ) | 0x4000, random_int( 0, 0x3fff ) | 0x8000,
        random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
    );
}
function apply_filters( $tag, $value, ...$rest ) { return $value; }
function do_action( $tag, ...$args ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0777, true ); }
function current_time( $type = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_cache_flush() { return true; }
function maybe_serialize( $d ) { return is_array( $d ) || is_object( $d ) ? serialize( $d ) : $d; }
function maybe_unserialize( $d ) { return is_string( $d ) && @unserialize( $d ) !== false ? unserialize( $d ) : $d; }

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public array $errors = array();
        public array $error_data = array();
        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' !== $code ) { $this->errors[ $code ][] = $message; $this->error_data[ $code ] = $data; }
        }
        public function get_error_code() { return array_key_first( $this->errors ) ?? ''; }
        public function get_error_message( $code = '' ) {
            $code = $code ?: $this->get_error_code();
            return $this->errors[ $code ][0] ?? '';
        }
        public function get_error_data( $code = '' ) {
            $code = $code ?: $this->get_error_code();
            return $this->error_data[ $code ] ?? null;
        }
    }
}

$plugin = dirname( __DIR__ ) . '/prstudio-unified-control';
require_once $plugin . '/includes/class-prstudio-uc-state-machine.php';
require_once $plugin . '/includes/class-prstudio-uc-store.php';

/* ------------------------------------------------------------------------- *
 * Assertions
 * ------------------------------------------------------------------------- */

$failures = array();
$passes = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
    global $failures, $passes;
    if ( $ok ) { $passes++; echo "PASS  {$label}\n"; return; }
    $failures[] = $label . ( '' !== $detail ? ' -- ' . $detail : '' );
    echo "FAIL  {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
}

global $wpdb;
echo "=== PR STUDIO task queue against real " . $wpdb->get_var( 'SELECT VERSION()' ) . " ===\n\n";

// 1. Schema installs with no SQL errors.
PRSTUDIO_UC_Store::install();
$tasks_table = PRSTUDIO_UC_Store::tasks_table();
$exists = (string) $wpdb->get_var( "SHOW TABLES LIKE '{$tasks_table}'" );
check( 'schema installs and tasks table exists', $exists === $tasks_table, "got '{$exists}'" );
check( 'schema install produced no SQL errors', 0 === count( $wpdb->errors ),
    count( $wpdb->errors ) . ' error(s); first: ' . ( $wpdb->errors[0]['error'] ?? '' ) );

// 2. Queue a task exactly as the control plane does.
$device = 'device-integration-test';
$task_uuid = wp_generate_uuid4();
$wpdb->insert( $tasks_table, array(
    'task_uuid' => $task_uuid,
    'device_uuid' => null,
    'action' => 'browser_open',
    'arguments' => wp_json_encode( array( 'url' => 'https://search.google.com/search-console' ) ),
    'status' => PRSTUDIO_UC_State_Machine::QUEUED,
    'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
    'updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
    'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
) );
$queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_table} WHERE status='" . PRSTUDIO_UC_State_Machine::QUEUED . "'" );
check( 'a task can be queued', 1 === $queued, "queued={$queued}" );

// 3. THE REGRESSION. This is the call the Browser Agent makes every poll.
$errors_before = count( $wpdb->errors );
$claimed = PRSTUDIO_UC_Store::claim_next( $device );
$new_errors = array_slice( $wpdb->errors, $errors_before );

check( 'claim_next() executes without a SQL error', 0 === count( $new_errors ),
    count( $new_errors ) . ' SQL error(s); first: ' . ( $new_errors[0]['error'] ?? '' ) );
check( 'claim_next() returns the queued task', is_array( $claimed ),
    'returned ' . ( null === $claimed ? 'null -- the queue is not being consumed' : gettype( $claimed ) ) );

if ( is_array( $claimed ) ) {
    check( 'claimed task is the one queued', ( $claimed['task_uuid'] ?? '' ) === $task_uuid );
    check( 'claimed task carries a lease token', '' !== (string) ( $claimed['lease_token'] ?? '' ) );
    check( 'attempt_count advanced to 1 (was 0 in the field)',
        1 === (int) ( $claimed['attempt_count'] ?? 0 ),
        'attempt_count=' . (int) ( $claimed['attempt_count'] ?? 0 ) );
    check( 'task moved out of QUEUED', PRSTUDIO_UC_State_Machine::QUEUED !== (string) ( $claimed['status'] ?? '' ),
        'status=' . (string) ( $claimed['status'] ?? '' ) );
    check( 'task is bound to the claiming device', ( $claimed['device_uuid'] ?? '' ) === $device );
}

// 4. The row in the database reflects the lease (not just the return value).
$row = $wpdb->get_row( "SELECT status, attempt_count, device_uuid, lease_token FROM {$tasks_table} WHERE task_uuid='" . $task_uuid . "'", ARRAY_A );
check( 'database row shows the lease persisted',
    is_array( $row ) && 1 === (int) $row['attempt_count'] && '' !== (string) $row['lease_token'],
    'row=' . wp_json_encode( $row ) );

// 5. A second poll must not hand out the same task twice.
$second = PRSTUDIO_UC_Store::claim_next( 'device-other' );
check( 'a leased task is not handed to a second device', null === $second,
    'second claim returned ' . ( is_array( $second ) ? (string) ( $second['task_uuid'] ?? '?' ) : 'null' ) );

// 6. An expired task must never be claimable (the WHERE clause guard).
$stale_uuid = wp_generate_uuid4();
$wpdb->insert( $tasks_table, array(
    'task_uuid' => $stale_uuid, 'device_uuid' => null, 'action' => 'browser_open',
    'arguments' => '{}', 'status' => PRSTUDIO_UC_State_Machine::QUEUED,
    'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    'updated_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
) );
$stale = PRSTUDIO_UC_Store::claim_next( 'device-third' );
check( 'an expired queued task is not claimed', null === $stale || ( $stale['task_uuid'] ?? '' ) !== $stale_uuid );

echo "\ntotal SQL statements executed: {$wpdb->queries_run}\n";
if ( $wpdb->errors ) {
    echo "\n=== ALL SQL ERRORS OBSERVED ===\n";
    foreach ( $wpdb->errors as $n => $e ) {
        echo '  [' . ( $n + 1 ) . '] ' . $e['error'] . "\n      " . substr( preg_replace( '/\s+/', ' ', $e['sql'] ), 0, 220 ) . "\n";
    }
}

echo "\n=== {$passes} passed, " . count( $failures ) . " failed ===\n";
$conn->query( "DROP DATABASE IF EXISTS `{$dbname}`" );
if ( $failures ) {
    echo "\nFAILURES:\n";
    foreach ( $failures as $f ) { echo "  - {$f}\n"; }
    exit( 1 );
}
echo "PASS the task queue dispatches against a real database\n";
exit( 0 );
