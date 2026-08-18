<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Operations extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'operations'; }
    public function label(): string { return 'Sistema, manutenzione, cache, cron e log'; }
    public function routes(): array { return array( '/system-manage', '/maintenance-manage', '/cache-manage', '/cron-manage', '/logs-manage' ); }
    public function keywords(): array { return array( 'sistema', 'manutenzione', 'cache', 'cron', 'log', 'diagnostica', 'salute', 'health', 'rewrite', 'pulizia' ); }
}
