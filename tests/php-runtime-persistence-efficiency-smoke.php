<?php
/**
 * Runtime persistence efficiency smoke.
 *
 * Proves that high-frequency runtime paths do not manufacture duplicate
 * catalogue rows, rewrite invariant guard files, update an unchanged upload
 * session, or strand a failed atomic-write staging file.
 */

declare( strict_types = 1 );

$sandbox = sys_get_temp_dir() . '/prstudio-runtime-persistence-' . bin2hex( random_bytes( 5 ) );
define( 'ABSPATH', str_replace( '\\', '/', $sandbox ) . '/site/' );
define( 'WP_CONTENT_DIR', str_replace( '\\', '/', $sandbox ) . '/wp-content' );
mkdir( ABSPATH, 0750, true );
mkdir( WP_CONTENT_DIR, 0750, true );

final class WP_Error {
	private string $code;
	private string $message;
	private array $data;
	public function __construct( string $code, string $message = '', array $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; }
	public function get_error_code(): string { return $this->code; }
}
final class WP_REST_Request {
	public function __construct( private array $body ) {}
	public function get_json_params(): array { return $this->body; }
}
final class WP_REST_Response {
	public array $headers = array();
	public function __construct( public array $data, public int $status = 200 ) {}
	public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
}
final class WPAIB_Auth { public static function settings(): array { return array( 'max_file_bytes'=>8388608 ); } }
final class WPAIB_Audit { public static function log( ...$unused ): void {} }
final class PRSTUDIO_Report { public static function record_change( ...$unused ): void {} }

$GLOBALS['prstudio_test_transients'] = array();
$GLOBALS['prstudio_test_transient_writes'] = 0;
$GLOBALS['prstudio_test_uuid'] = 0;

function ok( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
	fwrite( STDOUT, "PASS {$message}\n" );
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function add_action( ...$unused ): void {}
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function wp_normalize_path( string $value ): string { return str_replace( '\\', '/', $value ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0750, true ); }
function wp_generate_uuid4(): string { $GLOBALS['prstudio_test_uuid']++; return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['prstudio_test_uuid'] ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }
function get_transient( string $key ) { return $GLOBALS['prstudio_test_transients'][ $key ] ?? false; }
function set_transient( string $key, $value, int $ttl ): bool {
	unset( $ttl );
	$GLOBALS['prstudio_test_transient_writes']++;
	$GLOBALS['prstudio_test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( string $key ): bool { unset( $GLOBALS['prstudio_test_transients'][ $key ] ); return true; }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-log-orchestrator.php';
require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-wpaib-media-upload-extension.php';
require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-wpaib-files.php';

// Logger: guard files are installation invariants, not per-event outputs.
PRSTUDIO_UC_Log_Orchestrator::log( 'runtime', 'first', array( 'n'=>1 ), 'info', __FILE__ );
$log_root = PRSTUDIO_UC_Log_Orchestrator::root();
$guard_mtime = 946684800;
foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $guard ) {
	ok( is_file( $log_root . '/' . $guard ), 'logger created ' . $guard );
	touch( $log_root . '/' . $guard, $guard_mtime );
}
for ( $i = 2; $i <= 5; $i++ ) {
	PRSTUDIO_UC_Log_Orchestrator::log( 'runtime', 'event_' . $i, array( 'n'=>$i ), 'info', __FILE__ );
}
// Reset request-local caches to exercise the on-disk de-duplication path used
// by the next independent WordPress request.
foreach ( array( 'root_ready'=>false, 'indexed_sources'=>array() ) as $property => $value ) {
	$reflection = new ReflectionProperty( PRSTUDIO_UC_Log_Orchestrator::class, $property );
	$reflection->setAccessible( true );
	$reflection->setValue( null, $value );
}
PRSTUDIO_UC_Log_Orchestrator::log( 'runtime', 'event_6', array( 'n'=>6 ), 'info', __FILE__ );
clearstatcache();
foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $guard ) {
	ok( $guard_mtime === filemtime( $log_root . '/' . $guard ), 'logger does not rewrite invariant ' . $guard );
}
$generation = trim( (string) file_get_contents( $log_root . '/CURRENT' ) );
$generation_dir = $log_root . '/' . $generation;
$source_id = hash( 'sha256', wp_normalize_path( __FILE__ ) );
$index_lines = file( $generation_dir . '/files/index.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array();
ok( 1 === count( $index_lines ), 'six cross-request events from one source produce one catalogue row' );
$source_lines = file( $generation_dir . '/files/' . $source_id . '.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array();
ok( 6 === count( $source_lines ), 'source event stream remains complete' );
$aggregate_lines = file( $generation_dir . '/orchestrator.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array();
ok( 6 === count( $aggregate_lines ), 'aggregate event stream remains complete' );
$rotation = PRSTUDIO_UC_Log_Orchestrator::rotate_generation( 'persistence_test', 'work_test' );
ok( ! empty( $rotation['rotated'] ), 'logger generation rotation succeeds after request-local caching' );
ok( (string) $rotation['generation'] === trim( (string) file_get_contents( $log_root . '/CURRENT' ) ), 'rotation updates the durable generation pointer' );
ok( ! is_dir( $generation_dir ), 'rotation removes the superseded generation' );
ok( is_file( $log_root . '/' . $rotation['generation'] . '/orchestrator.ndjson' ), 'rotation event is written into the new generation' );

// Upload status: polling unchanged bytes is read-only and must not churn wp_options.
$upload = tempnam( $sandbox, 'upload-' );
file_put_contents( $upload, 'abc' );
$session_id = str_repeat( 'a', 48 );
$session_key = 'wpaib_mupload_session_' . $session_id;
$GLOBALS['prstudio_test_transients'][ $session_key ] = array(
	'session_id'=>$session_id, 'request_id'=>'request-12345678', 'filename'=>'image.png',
	'file_size'=>4, 'file_sha256'=>str_repeat( 'b', 64 ), 'mime_type'=>'image/png',
	'parent_id'=>0, 'metadata'=>array(), 'temp_path'=>$upload, 'received'=>3,
	'created_at'=>time(), 'updated_at'=>time(), 'expires_at'=>time()+3600,
);
$response = WPAIB_Media_Upload_Extension::status( new WP_REST_Request( array( 'session_id'=>$session_id ) ) );
ok( $response instanceof WP_REST_Response, 'unchanged upload status returns a response' );
ok( 0 === $GLOBALS['prstudio_test_transient_writes'], 'unchanged upload status performs no transient write' );
file_put_contents( $upload, 'd', FILE_APPEND );
$response = WPAIB_Media_Upload_Extension::status( new WP_REST_Request( array( 'session_id'=>$session_id ) ) );
ok( 1 === $GLOBALS['prstudio_test_transient_writes'], 'changed upload status persists exactly once' );
ok( 4 === (int) $response->data['data']['session']['received_bytes'], 'changed upload status reports observed bytes' );

// Atomic write failure: the staging path created by wp_tempnam is unlinked.
final class PRSTUDIO_Fail_Write_Stream {
	public $context;
	public static bool $unlinked = false;
	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool { unset( $path, $mode, $options, $opened_path ); return true; }
	public function stream_write( string $data ): int { unset( $data ); return 0; }
	public function stream_stat(): array { return array(); }
	public function url_stat( string $path, int $flags ): array { unset( $path, $flags ); return array( 'mode'=>0100600, 'size'=>0 ); }
	public function unlink( string $path ): bool { unset( $path ); self::$unlinked = true; return true; }
}
stream_wrapper_register( 'prstudiofail', PRSTUDIO_Fail_Write_Stream::class );
function wp_tempnam( string $filename, string $dir = '' ): string { unset( $filename, $dir ); return 'prstudiofail://staging-file'; }
set_error_handler( static fn(): bool => true );
$failed = WPAIB_Files::write_raw( 'wp-content/failure-target.txt', 'payload', null );
restore_error_handler();
ok( is_wp_error( $failed ) && 'wpaib_write_failed' === $failed->get_error_code(), 'failed staging write returns the technical error' );
ok( PRSTUDIO_Fail_Write_Stream::$unlinked, 'failed staging write removes its temporary file' );
stream_wrapper_unregister( 'prstudiofail' );

@unlink( $upload );
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $sandbox, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $iterator as $item ) { $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); }
@rmdir( $sandbox );

fwrite( STDOUT, "PASS runtime persistence efficiency smoke complete\n" );
exit( 0 );
