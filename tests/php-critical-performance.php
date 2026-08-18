<?php
declare(strict_types=1);

define( 'PRSTUDIO_UC_TESTING', true );
$performance_root = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/prstudio-performance-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $performance_root . '/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'PRSTUDIO_UC_DIR', dirname( __DIR__ ) . '/prstudio-unified-control/' );
define( 'DAY_IN_SECONDS', 86400 );
@mkdir( WP_CONTENT_DIR, 0750, true );

function performance_remove_tree( string $path ): void {
	$normalized = str_replace( '\\', '/', $path );
	$temp = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/prstudio-performance-';
	if ( ! str_starts_with( $normalized, $temp ) || ! is_dir( $path ) ) { return; }
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) { $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); }
	@rmdir( $path );
}
register_shutdown_function( static fn() => performance_remove_tree( ABSPATH ) );

final class WP_Error {
	public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( $value ): string { return trim( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ), '-_' ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ): string { return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : ''; }
function wp_mkdir_p( $path ): bool { return is_dir( $path ) || mkdir( $path, 0750, true ); }
function home_url( $path = '/' ): string { return 'https://performance.example' . ( str_starts_with( (string) $path, '/' ) ? $path : '/' . $path ); }
function get_bloginfo( $field ): string { return array( 'name'=>'Performance Fixture', 'description'=>'Fixture', 'language'=>'it-IT', 'version'=>'6.8' )[ $field ] ?? ''; }
function wp_timezone_string(): string { return 'Europe/Rome'; }
function get_permalink( $id = 0 ): string { return 'https://performance.example/item/' . (int) $id; }
function get_posts( $args = array() ): array { return range( 1, max( 1, (int) ( $args['numberposts'] ?? 250 ) ) ); }
function get_post_types( $args = array(), $output = 'names' ): array { return array( 'post', 'page' ); }
function get_the_title( $id ): string { return 'Content ' . (int) $id; }
function get_post_type( $id ): string { return 0 === (int) $id % 2 ? 'page' : 'post'; }
function get_post_status( $id ): string { return 'publish'; }
function get_post_modified_time( $format, $gmt, $id ): string { return '2026-08-11T00:00:00Z'; }

final class Performance_Theme {
	public function get_stylesheet(): string { return 'performance-theme'; }
	public function get( $field ): string { return 'Name' === $field ? 'Performance Theme' : ( 'Version' === $field ? '1.0.0' : '' ); }
}
function wp_get_theme(): Performance_Theme { return new Performance_Theme(); }

final class Performance_Product {
	public function __construct( private int $id ) {}
	public function get_id(): int { return $this->id; }
	public function get_name(): string { return 'Product ' . $this->id; }
	public function get_sku(): string { return 'SKU-' . $this->id; }
	public function get_status(): string { return 'publish'; }
	public function get_price(): string { return '99.00'; }
	public function get_stock_status(): string { return 'instock'; }
	public function get_catalog_visibility(): string { return 'visible'; }
	public function get_date_modified() { return null; }
}
function wc_get_products( $args = array() ): array {
	$limit = max( 1, (int) ( $args['limit'] ?? 250 ) );
	$items = array();
	for ( $i = 1; $i <= $limit; $i++ ) { $items[] = new Performance_Product( 100000 + $i ); }
	return $items;
}

require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-agency-state.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-operational-twin.php';

// Public capability search deliberately filters non-callable executors. Build
// inert fixture classes from the shipped indexes so the benchmark measures the
// real scoring path without executing any capability or mutating WordPress.
$executor_classes = array();
foreach ( array( 'capabilities/capability-search-index.json', 'capabilities/agency-capabilities.json' ) as $relative ) {
	$document = json_decode( (string) file_get_contents( PRSTUDIO_UC_DIR . $relative ), true );
	$rows = (array) ( $document['items'] ?? $document['capabilities'] ?? array() );
	foreach ( $rows as $row ) {
		$executor = is_array( $row ) ? (string) ( $row['executor'] ?? '' ) : '';
		if ( str_contains( $executor, '::' ) ) { $executor_classes[] = explode( '::', $executor, 2 )[0]; }
	}
}
foreach ( array_unique( $executor_classes ) as $class ) {
	if ( class_exists( $class ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $class ) ) { continue; }
	eval( 'class ' . $class . ' { public static function __callStatic($name,$arguments){ return array(); } }' );
}
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-capability-registry.php';

function performance_ms( callable $callback ): array {
	$started = hrtime( true );
	$result = $callback();
	return array( $result, ( hrtime( true ) - $started ) / 1_000_000 );
}
function performance_percentile( array $values, float $percentile ): float {
	sort( $values, SORT_NUMERIC );
	$index = max( 0, min( count( $values ) - 1, (int) ceil( count( $values ) * $percentile ) - 1 ) );
	return (float) $values[ $index ];
}

list( $cold_result, $cold_ms ) = performance_ms( static fn() => PRSTUDIO_UC_Capability_Registry::search( 'product seo', array( 'limit'=>12 ) ) );
$queries = array( 'product seo', 'browser screenshot', 'orders customers', 'theme assets', 'search console indexing' );
$warm_ms = array();
$warm_results = 0;
for ( $i = 0; $i < 200; $i++ ) {
	list( $result, $elapsed ) = performance_ms( static fn() => PRSTUDIO_UC_Capability_Registry::search( $queries[ $i % count( $queries ) ], array( 'limit'=>12 ) ) );
	$warm_ms[] = $elapsed;
	$warm_results += (int) ( $result['count'] ?? 0 );
}

list( $twin_result, $twin_sync_ms ) = performance_ms( static fn() => PRSTUDIO_UC_Operational_Twin::sync( array( 'scope'=>array('site','content','commerce'), 'limit'=>250 ) ) );
$twin_query_ms = array();
for ( $i = 0; $i < 100; $i++ ) {
	list( $result, $elapsed ) = performance_ms( static fn() => PRSTUDIO_UC_Operational_Twin::query( 'Product', array( 'type'=>'product', 'limit'=>20 ) ) );
	$twin_query_ms[] = $elapsed;
}

if ( (int) ( $cold_result['count'] ?? 0 ) < 1 || $warm_results < 200 || (int) ( $twin_result['observed'] ?? 0 ) < 500 ) {
	fwrite( STDERR, "critical performance fixture did not exercise the complete paths\n" );
	exit( 2 );
}

$payload = array(
	'schema_version'=>'1.0.0',
	'capability_search'=>array(
		'total_capabilities'=>(int) ( $cold_result['total_capabilities'] ?? 0 ),
		'cold_ms'=>round( $cold_ms, 6 ),
		'warm_queries'=>count( $warm_ms ),
		'warm_median_ms'=>round( performance_percentile( $warm_ms, 0.50 ), 6 ),
		'warm_p95_ms'=>round( performance_percentile( $warm_ms, 0.95 ), 6 ),
	),
	'operational_twin'=>array(
		'observed'=>(int) ( $twin_result['observed'] ?? 0 ),
		'relations_observed'=>(int) ( $twin_result['relations_observed'] ?? 0 ),
		'sync_ms'=>round( $twin_sync_ms, 6 ),
		'query_samples'=>count( $twin_query_ms ),
		'query_median_ms'=>round( performance_percentile( $twin_query_ms, 0.50 ), 6 ),
		'query_p95_ms'=>round( performance_percentile( $twin_query_ms, 0.95 ), 6 ),
	),
	'peak_memory_bytes'=>memory_get_peak_usage( true ),
	'production_proven'=>false,
);
fwrite( STDOUT, json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n" );
