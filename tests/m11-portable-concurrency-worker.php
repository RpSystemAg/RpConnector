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

$store = (string) ( $argv[2] ?? '' );
$barrier = (string) ( $argv[3] ?? '' );
if ( '' === $store || '' === $barrier ) {
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

try {
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
	fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" );
	exit( 70 );
}
