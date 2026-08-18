<?php
/** Pass 4 security primitive regression checks. */
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../prstudio-unified-control/includes/class-prstudio-uc-database-backend.php';

$checks = 0;
$pass = 0;
function check17( bool $ok, string $name ): void {
    global $checks, $pass;
    $checks++;
    if ( $ok ) { $pass++; echo "ok {$checks} - {$name}\n"; }
    else { echo "not ok {$checks} - {$name}\n"; }
}

$method = new ReflectionMethod( 'PRSTUDIO_UC_Database_Backend', 'serialized_safe_replace' );
$invoke = static fn( string $search, string $replace, string $value ): string => $method->invoke( null, $search, $replace, $value );

$input = serialize( array( 'url' => 'https://old.example/a', 'nested' => array( 'old.example' ) ) );
$output = $invoke( 'old.example', 'new.example', $input );
$decoded = unserialize( $output, array( 'allowed_classes' => false ) );
check17( is_array( $decoded ) && 'https://new.example/a' === $decoded['url'] && 'new.example' === $decoded['nested'][0], 'serialized arrays remain length-correct and replaceable' );
check17( serialize( false ) === $invoke( 'old', 'new', serialize( false ) ), 'serialized false remains valid' );
check17( 'plain new.example' === $invoke( 'old.example', 'new.example', 'plain old.example' ), 'plain strings still use deterministic replacement' );

class PRSTUDIO_Unserialize_Probe {
    public static int $wakeups = 0;
    public string $value = 'old.example';
    public function __wakeup(): void { self::$wakeups++; }
}
$object_payload = serialize( new PRSTUDIO_Unserialize_Probe() );
$object_output = $invoke( 'old.example', 'new.example', $object_payload );
check17( 0 === PRSTUDIO_Unserialize_Probe::$wakeups, 'generic DB replacement does not execute object __wakeup' );
check17( $object_payload === $object_output, 'object-bearing serialized payload is preserved byte-for-byte' );

$nested_object_payload = serialize( array( 'safe' => 'old.example', 'object' => new PRSTUDIO_Unserialize_Probe() ) );
$nested_object_output = $invoke( 'old.example', 'new.example', $nested_object_payload );
check17( 0 === PRSTUDIO_Unserialize_Probe::$wakeups, 'nested object does not execute magic unserialization' );
check17( $nested_object_payload === $nested_object_output, 'nested object payload is preserved instead of corrupting class identity' );

$root = dirname( __DIR__ );
$editorial = file_get_contents( $root . '/prstudio-unified-control/includes/class-prstudio-uc-editorial-autonomy.php' );
$executor = file_get_contents( $root . '/prstudio-unified-control/includes/class-prstudio-uc-complete-action-executor.php' );
check17( false !== strpos( $editorial, 'wp_safe_remote_get( $url' ) && false === strpos( $editorial, 'wp_remote_get( $url' ), 'watcher arbitrary URL uses wp_safe_remote_get' );
check17( false !== strpos( $executor, "wp_safe_remote_head(\$link['href']" ) && false === strpos( $executor, "wp_remote_head(\$link['href']" ), 'external-link audit uses wp_safe_remote_head' );

echo "1..{$checks}\n";
if ( $pass !== $checks ) { fwrite( STDERR, "FAIL {$pass}/{$checks}\n" ); exit(1); }
fwrite( STDERR, "PASS {$pass}/{$checks}\n" );
