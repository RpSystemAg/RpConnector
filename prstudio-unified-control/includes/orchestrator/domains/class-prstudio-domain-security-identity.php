<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Security_Identity extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'security_identity'; }
    public function label(): string { return 'Sicurezza, utenti e impostazioni'; }
    public function routes(): array { return array( '/security-manage', '/users-manage', '/settings-manage' ); }
    public function keywords(): array { return array( 'sicurezza', 'utente', 'ruolo', 'permesso', 'password', 'firewall', 'setting', 'impostazione', 'privacy' ); }
}
