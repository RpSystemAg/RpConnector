<?php
/**
 * No claimed write without a statement that reached the server.
 *
 * WHY THIS EXISTS
 * ---------------
 * Both defects that stopped this product were the same shape: a mutation path
 * that reported success while sending nothing to MySQL.
 *
 *   claim_next()          a `// phpcs:ignore` line sat inside the SQL string
 *                         between LIMIT 1 and FOR UPDATE. MySQL has no `//`
 *                         comment, so the statement was rejected, get_row()
 *                         returned null, and the queue concluded there was no
 *                         work. Every task stayed QUEUED with attempt_count 0.
 *
 *   recover_stale_tasks() six placeholders, five arguments. wpdb::prepare()
 *                         reported misuse and returned '', $wpdb->query('')
 *                         returned false, $affected became 0, and the caller
 *                         read that as "nothing needed recovering". A task
 *                         whose device died held its lease forever.
 *
 * Neither is visible from the outside. Both functions return a number, both
 * numbers are plausible, and zero is the honest answer when there is genuinely
 * nothing to do. Static analysis cannot separate them: the first was valid SQL
 * and the second was an empty string.
 *
 * What separates them is the server. A statement that never arrives leaves no
 * trace in the general query log, and a statement that arrives but matches
 * nothing changes no rows. So this test asks the database directly, for each
 * mutation under test:
 *
 *   1. did a mutating statement actually reach the server?
 *   2. did it change the number of rows it should have changed?
 *   3. is the observable state different afterwards, read back independently?
 *
 * All three, because each can lie alone. (1) alone passes if the statement
 * arrives and matches nothing. (3) alone passes if some other code path made
 * the change. Together they are hard to fake.
 *
 * HOW
 * ---
 * MariaDB's general log is switched to TABLE output, so the statements can be
 * read back with SQL instead of needing filesystem access inside the runner.
 * That requires SUPER, which the CI service container's root user has.
 *
 * Usage:
 *   PRSTUDIO_TEST_DB_HOST=127.0.0.1 PRSTUDIO_TEST_DB_PORT=3306 \
 *   PRSTUDIO_TEST_DB_USER=root PRSTUDIO_TEST_DB_PASS= \
 *   PRSTUDIO_TEST_DB_NAME=prstudio_writeobs \
 *   php tests/php-write-observability-integration.php
 *
 * Skips cleanly (exit 0) with no database configured, so it never blocks a
 * machine without one. CI must provide one.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );
// PRSTUDIO_UC_Store::install() does `require_once ABSPATH . 'wp-admin/includes/
// upgrade.php'`, which real WordPress always provides. Pointing ABSPATH at
// tests/ meant that require_once emitted a warning and carried on -- harmless
// looking, and invisible until tests/strict-php-errors.php started promoting
// diagnostics to failures. It found this on its first real run, in the suite
// written alongside it, which is a fair demonstration of why it exists.
//
// php-task-queue-integration.php already solved this the same way; this file
// simply had not copied it.
$wp_root = sys_get_temp_dir() . '/prstudio-writeobs-root/';
@mkdir( $wp_root . 'wp-admin/includes', 0777, true );
if ( ! file_exists( $wp_root . 'wp-admin/includes/upgrade.php' ) ) {
	file_put_contents( $wp_root . 'wp-admin/includes/upgrade.php', '<?php' . PHP_EOL . '// test stub: dbDelta() is defined by this harness.' . PHP_EOL );
}
define( 'ABSPATH', $wp_root );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$host = getenv( 'PRSTUDIO_TEST_DB_HOST' );
if ( ! is_string( $host ) || '' === $host ) {
	fwrite( STDOUT, "SKIP no PRSTUDIO_TEST_DB_HOST configured\n" );
	exit( 0 );
}
$port = (int) ( getenv( 'PRSTUDIO_TEST_DB_PORT' ) ?: '3306' );
$user = (string) ( getenv( 'PRSTUDIO_TEST_DB_USER' ) ?: 'root' );
$pass = (string) ( getenv( 'PRSTUDIO_TEST_DB_PASS' ) ?: '' );
$name = (string) ( getenv( 'PRSTUDIO_TEST_DB_NAME' ) ?: 'prstudio_writeobs' );

mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$conn = new mysqli( $host, $user, $pass, '', $port );
$conn->query( 'CREATE DATABASE IF NOT EXISTS `' . $conn->real_escape_string( $name ) . '`' );
$conn->select_db( $name );
$conn->set_charset( 'utf8mb4' );

/* ------------------------------------------------------------------ */
/* Minimal WordPress surface                                           */
/* ------------------------------------------------------------------ */

final class WP_Error {
	public string $code;
	public string $message;
	/** @param mixed $data */
	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code(): string {
		return $this->code;
	}
}

final class Obs_WPDB {
	public string $prefix = 'wp_';
	public string $charset = 'utf8mb4';
	public string $collate = 'utf8mb4_unicode_ci';
	public string $last_error = '';
	public int $rows_affected = 0;
	private mysqli $conn;

	public function __construct( mysqli $conn ) {
		$this->conn = $conn;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$placeholders = preg_match_all( "/%(?:\d+\\\$)?(?:'.)?[-+]?\d*(?:\.\d+)?[sdfFi]/", str_replace( '%%', '', $query ) );
		if ( $placeholders !== count( $args ) ) {
			// This is what WordPress does: report misuse, return ''. Reproduced
			// faithfully, because a shim that silently interpolates anyway is
			// how the recover_stale_tasks() defect stayed invisible in tests.
			_doing_it_wrong(
				'wpdb::prepare',
				sprintf( 'The query does not contain the correct number of placeholders (%d) for the number of arguments passed (%d).', $placeholders, count( $args ) ),
				'4.8.3'
			);
			return '';
		}
		$out = '';
		$i   = 0;
		$len = strlen( $query );
		for ( $p = 0; $p < $len; $p++ ) {
			if ( '%' === $query[ $p ] && $p + 1 < $len ) {
				$next = $query[ $p + 1 ];
				if ( '%' === $next ) {
					$out .= '%';
					++$p;
					continue;
				}
				if ( false !== strpos( 'sdfFi', $next ) ) {
					$value = $args[ $i++ ] ?? null;
					$out  .= ( 's' === $next )
						? "'" . $this->conn->real_escape_string( (string) $value ) . "'"
						: (string) (int) $value;
					++$p;
					continue;
				}
			}
			$out .= $query[ $p ];
		}
		return $out;
	}

	/** @return int|false */
	public function query( string $sql ) {
		if ( '' === trim( $sql ) ) {
			$this->rows_affected = 0;
			return false;
		}
		try {
			$res = $this->conn->query( $sql );
		} catch ( mysqli_sql_exception $e ) {
			$this->last_error     = $e->getMessage();
			$this->rows_affected  = 0;
			return false;
		}
		$this->rows_affected = $this->conn->affected_rows;
		if ( $res instanceof mysqli_result ) {
			$res->free();
		}
		return $this->rows_affected;
	}

	/** @return array<string,mixed>|null */
	public function get_row( string $sql, string $output = 'OBJECT' ): ?array {
		try {
			$res = $this->conn->query( $sql );
		} catch ( mysqli_sql_exception $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
		if ( ! $res instanceof mysqli_result ) {
			return null;
		}
		$row = $res->fetch_assoc();
		$res->free();
		return is_array( $row ) ? $row : null;
	}

	/** @return mixed */
	public function get_var( string $sql ) {
		$row = $this->get_row( $sql );
		return is_array( $row ) ? reset( $row ) : null;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $where
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where, $fmt = null, $wfmt = null ) {
		$sets = array();
		foreach ( $data as $col => $val ) {
			$sets[] = '`' . $col . '` = ' . ( null === $val ? 'NULL' : "'" . $this->conn->real_escape_string( (string) $val ) . "'" );
		}
		$conds = array();
		foreach ( $where as $col => $val ) {
			$conds[] = '`' . $col . '` = ' . ( null === $val ? 'NULL' : "'" . $this->conn->real_escape_string( (string) $val ) . "'" );
		}
		return $this->query( 'UPDATE ' . $table . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conds ) );
	}

	/** @param array<string,mixed> $data */
	public function insert( string $table, array $data, $fmt = null ) {
		$cols = array();
		$vals = array();
		foreach ( $data as $col => $val ) {
			$cols[] = '`' . $col . '`';
			$vals[] = null === $val ? 'NULL' : "'" . $this->conn->real_escape_string( (string) $val ) . "'";
		}
		return $this->query( 'INSERT INTO ' . $table . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')' );
	}
}

$GLOBALS['wpdb']   = new Obs_WPDB( $conn );
$GLOBALS['__opts'] = array();

// Guarded because tests/strict-php-errors.php declares the same function and
// is loaded ahead of this file through auto_prepend_file. Declaring it
// unconditionally was a straight fatal, which is exactly what the first CI run
// of this suite reported -- my own bug, and the kind that only a real run
// finds. The definition stays for the standalone case: running this file
// directly, without the prepend, must still turn a prepare() misuse into a
// clear failure rather than an undefined-function fatal that reads like a
// different problem entirely.
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version = '' ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- test harness stands in for WordPress core.
		fwrite( STDERR, "FAIL WordPress API misuse -- _doing_it_wrong({$function_name}): {$message}\n" );
		fwrite( STDERR, "  prepare() returns '' after this, so the statement is never sent.\n" );
		exit( 1 );
	}
}
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
function wp_generate_uuid4(): string {
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		wp_rand_int(), wp_rand_int(), wp_rand_int(),
		wp_rand_int() & 0x0fff | 0x4000,
		wp_rand_int() & 0x3fff | 0x8000,
		wp_rand_int(), wp_rand_int(), wp_rand_int()
	);
}
function wp_rand_int(): int { return random_int( 0, 0xffff ); }

$plugin = dirname( __DIR__ ) . '/prstudio-unified-control';
require_once $plugin . '/includes/class-prstudio-uc-state-machine.php';
require_once $plugin . '/includes/class-prstudio-uc-store.php';

/* ------------------------------------------------------------------ */
/* The observability harness                                           */
/* ------------------------------------------------------------------ */

$passes = 0;
$fails  = array();

function check( string $label, bool $ok, string $detail = '' ): void {
	global $passes, $fails;
	if ( $ok ) {
		++$passes;
		fwrite( STDOUT, "PASS {$label}\n" );
		return;
	}
	$fails[] = $label . ( '' !== $detail ? " -- {$detail}" : '' );
	fwrite( STDERR, "FAIL {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n" );
}

/** Statements the server actually received since the marker, mutations only. */
function mutations_since( mysqli $conn, string $marker ): array {
	$sql = "SELECT argument FROM mysql.general_log
	         WHERE command_type = 'Query'
	           AND event_time >= (SELECT MIN(event_time) FROM mysql.general_log
	                              WHERE argument LIKE '%" . $conn->real_escape_string( $marker ) . "%')
	           AND (argument LIKE 'UPDATE%' OR argument LIKE 'INSERT%' OR argument LIKE 'DELETE%'
	                OR argument LIKE 'REPLACE%')";
	$out = array();
	$res = $conn->query( $sql );
	if ( $res instanceof mysqli_result ) {
		while ( $row = $res->fetch_assoc() ) {
			$arg = (string) ( $row['argument'] ?? '' );
			if ( false === stripos( $arg, 'general_log' ) ) {
				$out[] = $arg;
			}
		}
		$res->free();
	}
	return $out;
}

function status_of( mysqli $conn, string $table, string $uuid ): string {
	$res = $conn->query( "SELECT status FROM {$table} WHERE task_uuid='" . $conn->real_escape_string( $uuid ) . "'" );
	if ( ! $res instanceof mysqli_result ) {
		return '';
	}
	$row = $res->fetch_assoc();
	$res->free();
	return is_array( $row ) ? (string) ( $row['status'] ?? '' ) : '';
}

function marker( mysqli $conn ): string {
	$m = 'obsmarker_' . bin2hex( random_bytes( 6 ) );
	$conn->query( "SELECT '" . $m . "'" );
	return $m;
}

/* ------------------------------------------------------------------ */

try {
	$conn->query( "SET GLOBAL log_output = 'TABLE'" );
	$conn->query( 'SET GLOBAL general_log = ON' );
} catch ( mysqli_sql_exception $e ) {
	fwrite( STDOUT, "SKIP cannot enable the general log (needs SUPER): " . $e->getMessage() . "\n" );
	exit( 0 );
}
$conn->query( 'TRUNCATE TABLE mysql.general_log' );

PRSTUDIO_UC_Store::install();
$tasks = PRSTUDIO_UC_Store::tasks_table();
$conn->query( 'DELETE FROM ' . $tasks );

/* --- Scenario 1: recover_stale_tasks() with a stale lease present ---- */

$uuid = wp_generate_uuid4();
$past = gmdate( 'Y-m-d H:i:s', time() - 7200 );
$conn->query(
	'INSERT INTO ' . $tasks . " (task_uuid, device_uuid, status, payload, lease_token, lease_expires_gmt, created_gmt, updated_gmt, expires_gmt)
	 VALUES ('{$uuid}', 'dev-obs', 'leased', '{}', 'tok', '{$past}', '{$past}', '{$past}', '" . gmdate( 'Y-m-d H:i:s', time() + 86400 ) . "')"
);

$before = status_of( $conn, $tasks, $uuid );
$m      = marker( $conn );
$n      = PRSTUDIO_UC_Store::recover_stale_tasks();
$muts   = mutations_since( $conn, $m );
$after  = status_of( $conn, $tasks, $uuid );

check(
	'recovery: a mutating statement actually reached the server',
	count( $muts ) > 0,
	'the general log recorded no INSERT/UPDATE/DELETE for a call that claims to recover work'
);
check(
	'recovery: the claimed count matches a real row change',
	$n >= 1,
	"recover_stale_tasks() returned {$n} with a stale lease present"
);
check(
	'recovery: the state changed, read back independently of the writer',
	'leased' === $before && 'queued' === $after,
	"status went '{$before}' -> '{$after}', expected 'leased' -> 'queued'"
);

/* --- Scenario 2: honest zero. No stale lease, so no mutation. -------- */

$conn->query( 'UPDATE ' . $tasks . " SET lease_expires_gmt = '" . gmdate( 'Y-m-d H:i:s', time() + 3600 ) . "', status='leased' WHERE task_uuid='{$uuid}'" );
$m2    = marker( $conn );
$n2    = PRSTUDIO_UC_Store::recover_stale_tasks();
$muts2 = mutations_since( $conn, $m2 );

check(
	'honest zero: still sends its statements even when nothing matches',
	count( $muts2 ) > 0,
	'a correct no-op must still reach the server -- silence here is indistinguishable from the defect'
);
check(
	'honest zero: reports 0 when there is genuinely nothing to recover',
	0 === $n2,
	"expected 0, got {$n2}"
);

/* --- Scenario 3: a statement that fails to build must not look like zero -- */

// Not exercised in-process: the failure mode is that wpdb::prepare() returns ''
// and the caller reads 0, and reproducing that here would mean calling
// _doing_it_wrong(), which exits. That path is covered two other ways --
// tests/validate-prepare-arity.py counts placeholders against arguments for
// every prepare() in the plugin, and tests/strict-php-errors.php turns the
// runtime signal into a failure for every suite. What is asserted here instead
// is the property those two protect: a call that reports 0 must have reached
// the server, which is scenario 2 above.

$conn->query( 'SET GLOBAL general_log = OFF' );
$conn->query( 'DELETE FROM ' . $tasks );

fwrite( STDOUT, "
SUMMARY {$passes} passed, " . count( $fails ) . " failed
" );
foreach ( $fails as $f ) {
	fwrite( STDERR, "  - {$f}
" );
}
exit( count( $fails ) > 0 ? 1 : 0 );
