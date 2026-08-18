<?php
declare(strict_types=1);
define( 'PRSTUDIO_UC_TESTING', true );
define( 'PRSTUDIO_UC_PATH', dirname( __DIR__ ) . '/prstudio-unified-control/' );

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
function sanitize_key( $value ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }

require PRSTUDIO_UC_PATH . 'includes/class-prstudio-uc-engineering-workbench.php';

function m11_fail( string $message ): void { fwrite( STDERR, "FAIL {$message}\n" ); exit( 1 ); }
function m11_pass( string $message ): void { fwrite( STDOUT, "PASS {$message}\n" ); }

try {
	$missing = PRSTUDIO_UC_Engineering_Workbench::repo_map( array( 'path' => '__m11_path_that_does_not_exist__' ) );
} catch ( Throwable $error ) {
	m11_fail( 'repo_map threw ' . get_class( $error ) . ' instead of returning WP_Error' );
}
if ( ! is_wp_error( $missing ) || ! in_array( $missing->get_error_code(), array( 'engineering_path_not_found', 'engineering_path_invalid' ), true ) ) {
	m11_fail( 'repo_map missing path did not return a typed path error' );
}
m11_pass( 'repo_map missing path returns typed WP_Error without TypeError' );

$outside = PRSTUDIO_UC_Engineering_Workbench::repo_map( array( 'path' => '../' ) );
if ( ! is_wp_error( $outside ) || 'engineering_path_invalid' !== $outside->get_error_code() ) {
	m11_fail( 'repo_map traversal did not return a technical failure with engineering_path_invalid' );
}
m11_pass( 'repo_map path traversal returns a technical failure with a typed error' );

$lint = PRSTUDIO_UC_Engineering_Workbench::validate( array( 'profile' => 'php_lint', 'path' => 'prstudio-unified-control.php' ) );
if ( is_wp_error( $lint ) || empty( $lint['ok'] ) || 1 !== (int) ( $lint['results']['php_lint']['checked'] ?? 0 ) || 0 !== (int) ( $lint['results']['php_lint']['failures'] ?? -1 ) ) {
	m11_fail( 'php_lint did not accept one valid PHP file: ' . json_encode( $lint, JSON_UNESCAPED_SLASHES ) );
}
m11_pass( 'php_lint preserves the real zero exit code on this platform' );

$version = PRSTUDIO_UC_Engineering_Workbench::terminal( array( 'operation' => 'php_version' ) );
if ( empty( $version['ok'] ) || 'cli' !== (string) ( $version['sapi'] ?? '' ) || preg_match( '/php-fpm|fpm-fcgi/i', (string) ( $version['php_binary'] ?? '' ) . ' ' . (string) ( $version['stdout'] ?? '' ) ) ) {
	m11_fail( 'engineering workbench did not prove a CLI SAPI binary: ' . json_encode( $version, JSON_UNESCAPED_SLASHES ) );
}
m11_pass( 'engineering workbench resolves and proves PHP CLI instead of FPM/CGI' );
fwrite( STDOUT, "OK milestone-11 engineering runtime smoke\n" );
