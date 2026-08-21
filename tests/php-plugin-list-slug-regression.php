<?php
/** Regression for plugins-manage.get accidentally matching the first plugin on an empty slug. */
declare( strict_types = 1 );
define( 'PRSTUDIO_UC_TESTING', true );
define( 'ABSPATH', __DIR__ . '/fixtures/' );

function get_plugins(): array {
    return array(
        'compressx/compressx.php' => array( 'Name' => 'CompressX', 'Version' => '1.0.0', 'Author' => 'A' ),
        'target-plugin/target-plugin.php' => array( 'Name' => 'Target Plugin', 'Version' => '2.0.0', 'Author' => 'B' ),
        'hello.php' => array( 'Name' => 'Hello', 'Version' => '3.0.0', 'Author' => 'C' ),
    );
}
function get_option( string $name, $default = false ) { return 'active_plugins' === $name ? array() : $default; }
function is_multisite(): bool { return false; }
function get_site_option( string $name, $default = false ) { return $default; }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-wpaib-site.php';

$list = WPAIB_Site::plugins();
$items = $list['items'] ?? array();
if ( 3 !== count( $items ) ) { fwrite( STDERR, "BAD plugin list fixture count\n" ); exit( 1 ); }

$slugs = array_column( $items, 'slug', 'plugin' );
$expected = array(
    'compressx/compressx.php' => 'compressx',
    'target-plugin/target-plugin.php' => 'target-plugin',
    'hello.php' => 'hello',
);
if ( $expected !== $slugs ) {
    fwrite( STDERR, 'BAD plugin slugs: ' . json_encode( $slugs ) . "\n" );
    exit( 1 );
}
foreach ( $items as $item ) {
    if ( '' === (string) ( $item['slug'] ?? '' ) ) {
        fwrite( STDERR, "BAD plugin list emitted an empty slug\n" );
        exit( 1 );
    }
}

fwrite( STDOUT, "PASS plugin list emits stable non-empty slugs; empty-slug fallback cannot select CompressX\n" );
exit( 0 );
