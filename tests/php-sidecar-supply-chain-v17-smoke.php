<?php
define( 'PRSTUDIO_UC_TESTING', true );
define( 'ABSPATH', dirname(__DIR__) . '/' );
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code; private string $message; private $data;
        public function __construct( $code='', $message='', $data=null ) { $this->code=(string)$code; $this->message=(string)$message; $this->data=$data; }
        public function get_error_code(){ return $this->code; }
        public function get_error_message(){ return $this->message; }
        public function get_error_data(){ return $this->data; }
    }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
require_once __DIR__ . '/../prstudio-unified-control/includes/class-prstudio-uc-mcp-toolchain.php';
$checks=0;$pass=0;
function ck( bool $ok, string $name ): void { global $checks,$pass; $checks++; if($ok){$pass++;echo "ok {$checks} - {$name}\n";}else echo "not ok {$checks} - {$name}\n"; }
$profiles=(new ReflectionMethod('PRSTUDIO_UC_MCP_Toolchain','sidecar_profiles'))->invoke(null);
ck( '@sylphx/pdf-reader-mcp@4.1.2' === ($profiles['pdf']['package']??''), 'PDF sidecar is pinned to current verified 4.1.2' );
ck( 'disabled_upstream_security' === ($profiles['postgres']['kind']??''), 'Postgres 0.3.0 is typed technical failure while upstream security findings remain unresolved' );
ck( str_contains((string)($profiles['postgres']['security_note']??''),'unresolved'), 'Postgres hold exposes a truthful security reason' );
$command=(new ReflectionMethod('PRSTUDIO_UC_MCP_Toolchain','sidecar_command'))->invoke(null,'postgres',array());
ck( is_wp_error($command) && 'toolchain_sidecar_upstream_security_hold' === $command->get_error_code(), 'disabled Postgres profile cannot spawn through sidecar command' );
$source=file_get_contents(__DIR__.'/../prstudio-unified-control/includes/class-prstudio-uc-mcp-toolchain.php');
ck( false !== strpos($source,"'postgres'=>'disabled until a patched upstream release"), 'toolchain status routing reports the hold instead of claiming readiness' );
echo "1..{$checks}\n"; if($pass!==$checks){fwrite(STDERR,"FAIL {$pass}/{$checks}\n");exit(1);} fwrite(STDERR,"PASS {$pass}/{$checks}\n");
