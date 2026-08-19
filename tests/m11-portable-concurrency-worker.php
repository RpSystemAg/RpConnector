<?php
declare(strict_types=1);
define( 'PRSTUDIO_UC_TESTING', true );

/** Separate-process worker used by validate-m11-portable-concurrency.py. */
final class WP_Error {
	private string $code;
	private string $message;
	private $data;
	public function __construct( string $code = '', string $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_title( $value ) { return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( trim( (string) $value ) ) ), '-' ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function wp_generate_uuid4() { return substr( bin2hex( random_bytes( 16 ) ), 0, 8 ) . '-' . substr( bin2hex( random_bytes( 2 ) ), 0, 4 ) . '-4' . substr( bin2hex( random_bytes( 2 ) ), 1, 3 ) . '-a' . substr( bin2hex( random_bytes( 2 ) ), 1, 3 ) . '-' . substr( bin2hex( random_bytes( 6 ) ), 0, 12 ); }
function wp_salt( $scheme = 'auth' ) { return 'm11-portable-concurrency-' . (string) $scheme; }
function absint( $value ) { return abs( (int) $value ); }
function esc_url_raw( $value ) { return trim( (string) $value ); }

$self_test = '' === (string) ( $argv[2] ?? '' ) && '' === (string) ( $argv[3] ?? '' );
$self_test_paths = array();
$store = (string) ( $argv[2] ?? '' );
$barrier = (string) ( $argv[3] ?? '' );
if ( $self_test ) {
	$store = tempnam( sys_get_temp_dir(), 'prstudio-m11-options-' );
	$barrier = tempnam( sys_get_temp_dir(), 'prstudio-m11-barrier-' );
	if ( false === $store || false === $barrier ) {
		fwrite( STDERR, "worker self-test temporary files unavailable\n" );
		exit( 74 );
	}
	$self_test_paths = array( $store, $barrier );
	if ( false === file_put_contents( $store, "{}\n", LOCK_EX ) || false === file_put_contents( $barrier, "go\n", LOCK_EX ) ) {
		foreach ( $self_test_paths as $path ) { @unlink( $path ); }
		fwrite( STDERR, "worker self-test temporary state unavailable\n" );
		exit( 74 );
	}
} elseif ( '' === $store || '' === $barrier ) {
	fwrite( STDERR, "worker requires store and barrier paths\n" );
	exit( 64 );
}
$GLOBALS['m11_option_store'] = $store;

function m11_store_mutate( callable $callback ) {
	$handle = fopen( $GLOBALS['m11_option_store'], 'c+' );
	if ( ! is_resource( $handle ) || ! flock( $handle, LOCK_EX ) ) { throw new RuntimeException( 'option_store_lock_failed' ); }
	try {
		rewind( $handle );
		$raw = stream_get_contents( $handle );
		$data = json_decode( $raw ?: '{}', true );
		if ( ! is_array( $data ) ) { $data = array(); }
		$result = $callback( $data );
		ftruncate( $handle, 0 );
		rewind( $handle );
		fwrite( $handle, (string) json_encode( $data, JSON_UNESCAPED_SLASHES ) );
		fflush( $handle );
		return $result;
	} finally {
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
}
function get_option( $key, $default = false ) {
	$handle = fopen( $GLOBALS['m11_option_store'], 'c+' );
	if ( ! is_resource( $handle ) || ! flock( $handle, LOCK_SH ) ) { return $default; }
	try {
		rewind( $handle );
		$data = json_decode( stream_get_contents( $handle ) ?: '{}', true );
		return is_array( $data ) && array_key_exists( $key, $data ) ? $data[ $key ] : $default;
	} finally {
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
}
function update_option( $key, $value, $autoload = null ) { return m11_store_mutate( static function( array &$data ) use ( $key, $value ) { $data[ $key ] = $value; return true; } ); }
function add_option( $key, $value = '', $deprecated = '', $autoload = null ) { return m11_store_mutate( static function( array &$data ) use ( $key, $value ) { if ( array_key_exists( $key, $data ) ) { return false; } $data[ $key ] = $value; return true; } ); }
function delete_option( $key ) { return m11_store_mutate( static function( array &$data ) use ( $key ) { if ( ! array_key_exists( $key, $data ) ) { return false; } unset( $data[ $key ] ); return true; } ); }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-execution-lanes.php';
require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-editorial-autonomy.php';

function m11_emit( $value ): void {
	if ( is_wp_error( $value ) ) {
		echo json_encode( array( 'ok' => false, 'error' => $value->get_error_code(), 'message' => $value->get_error_message(), 'data' => $value->get_error_data() ), JSON_UNESCAPED_SLASHES );
		exit( 0 );
	}
	echo json_encode( $value, JSON_UNESCAPED_SLASHES );
}
function m11_wait_barrier( string $path ): void {
	$deadline = microtime( true ) + 10.0;
	while ( ! file_exists( $path ) && microtime( true ) < $deadline ) { usleep( 1000 ); }
	if ( ! file_exists( $path ) ) { throw new RuntimeException( 'barrier_timeout' ); }
}
function m11_require_ok( $value, string $stage ): array {
	if ( is_wp_error( $value ) ) {
		throw new RuntimeException( $stage . ':' . (string) $value->get_error_code() );
	}
	if ( ! is_array( $value ) || empty( $value['ok'] ) ) {
		throw new RuntimeException( $stage . ':invalid_result' );
	}
	return $value;
}

try {
	if ( $self_test ) {
		m11_wait_barrier( $barrier );
		$context = array( 'client_id' => 'portable-oauth-client' );
		$first = m11_require_ok( PRSTUDIO_UC_Execution_Lanes::open( array( 'label' => 'bare worker A', 'chat_key' => 'bare-worker-a' ), $context ), 'open_a' );
		$second = m11_require_ok( PRSTUDIO_UC_Execution_Lanes::open( array( 'label' => 'bare worker B', 'chat_key' => 'bare-worker-b' ), $context ), 'open_b' );
		$first_handle = (string) ( $first['lane_handle'] ?? '' );
		$second_handle = (string) ( $second['lane_handle'] ?? '' );
		if ( '' === $first_handle || '' === $second_handle || hash_equals( $first_handle, $second_handle ) ) {
			throw new RuntimeException( 'lane_identity_invalid' );
		}

		$status = m11_require_ok( PRSTUDIO_UC_Execution_Lanes::status( array(), $context ), 'status' );
		if ( 2 !== (int) ( $status['count'] ?? -1 ) ) {
			throw new RuntimeException( 'lane_state_not_persisted' );
		}

		$resource = 'wp:post:bare-worker-self-test';
		$lease_a = m11_require_ok( PRSTUDIO_UC_Execution_Lanes::acquire( array( 'lane_handle' => $first_handle, 'resource' => $resource, 'ttl_seconds' => 120 ), $context ), 'acquire_a' );
		$contended = PRSTUDIO_UC_Execution_Lanes::acquire( array( 'lane_handle' => $second_handle, 'resource' => $resource, 'ttl_seconds' => 120 ), $context );
		if ( ! is_wp_error( $contended ) || 'resource_busy_other_context' !== (string) $contended->get_error_code() ) {
			throw new RuntimeException( 'contention_not_enforced' );
		}
		m11_require_ok( PRSTUDIO_UC_Execution_Lanes::release( array( 'lane_handle' => $first_handle, 'resource' => $resource ), $context ), 'release_a' );
		$lease_b = m11_require_ok( PRSTUDIO_UC_Execution_Lanes::acquire( array( 'lane_handle' => $second_handle, 'resource' => $resource, 'ttl_seconds' => 120 ), $context ), 'acquire_b_after_release' );
		m11_require_ok( PRSTUDIO_UC_Execution_Lanes::release( array( 'lane_handle' => $second_handle, 'resource' => $resource ), $context ), 'release_b' );
		m11_require_ok( PRSTUDIO_UC_Execution_Lanes::close( array( 'lane_handle' => $first_handle ), $context ), 'close_a' );
		m11_require_ok( PRSTUDIO_UC_Execution_Lanes::close( array( 'lane_handle' => $second_handle ), $context ), 'close_b' );
		$final_status = m11_require_ok( PRSTUDIO_UC_Execution_Lanes::status( array(), $context ), 'final_status' );
		if ( 0 !== (int) ( $final_status['count'] ?? -1 ) ) {
			throw new RuntimeException( 'lane_cleanup_incomplete' );
		}

		$store_state = json_decode( (string) file_get_contents( $store ), true );
		if ( ! is_array( $store_state ) ) {
			throw new RuntimeException( 'option_store_invalid_json' );
		}
		foreach ( $self_test_paths as $path ) {
			if ( ! @unlink( $path ) ) { throw new RuntimeException( 'temporary_cleanup_failed' ); }
		}
		$self_test_paths = array();
		echo json_encode( array(
			'ok' => true,
			'mode' => 'direct-real-execution-lanes',
			'opened' => 2,
			'contention_error' => (string) $contended->get_error_code(),
			'first_lease_ok' => ! empty( $lease_a['ok'] ),
			'second_lease_after_release_ok' => ! empty( $lease_b['ok'] ),
			'final_count' => 0,
			'cleanup' => true,
		), JSON_UNESCAPED_SLASHES );
		exit( 0 );
	}

	$mode = (string) ( $argv[1] ?? '' );
	m11_wait_barrier( $barrier );
	if ( 'open' === $mode ) {
		m11_emit( PRSTUDIO_UC_Execution_Lanes::open( array( 'label' => (string) ( $argv[4] ?? '' ), 'chat_key' => (string) ( $argv[4] ?? '' ) ), array( 'client_id' => 'portable-oauth-client' ) ) );
	} elseif ( 'status' === $mode ) {
		m11_emit( PRSTUDIO_UC_Execution_Lanes::status( array(), array( 'client_id' => 'portable-oauth-client' ) ) );
	} elseif ( 'acquire' === $mode ) {
		m11_emit( PRSTUDIO_UC_Execution_Lanes::acquire( array( 'lane_handle' => (string) ( $argv[4] ?? '' ), 'resource' => (string) ( $argv[5] ?? '' ), 'ttl_seconds' => 120 ), array( 'client_id' => 'portable-oauth-client' ) ) );
	} elseif ( 'editorial_upsert' === $mode ) {
		$index = (string) ( $argv[4] ?? '0' );
		m11_emit( PRSTUDIO_UC_Editorial_Autonomy::campaign_manager( array( 'operation'=>'upsert', 'campaign_id'=>'portable-' . $index, 'primary_keyword'=>'kw-' . $index, 'primary_url'=>'/portable-' . $index . '/' ) ) );
	} elseif ( 'editorial_list' === $mode ) {
		m11_emit( PRSTUDIO_UC_Editorial_Autonomy::campaign_manager( array( 'operation' => 'list' ) ) );
	} else {
		throw new InvalidArgumentException( 'unknown_worker_mode' );
	}
} catch ( Throwable $error ) {
	foreach ( $self_test_paths as $path ) { @unlink( $path ); }
	fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" );
	exit( 70 );
}
