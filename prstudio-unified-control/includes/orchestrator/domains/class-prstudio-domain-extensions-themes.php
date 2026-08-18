<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Extensions_Themes extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'extensions_themes'; }
    public function label(): string { return 'Plugin e temi'; }
    public function routes(): array { return array( '/plugins-manage', '/themes-manage' ); }
    public function keywords(): array { return array( 'plugin', 'tema', 'theme', 'estensione', 'attiva plugin', 'disattiva plugin', 'aggiorna plugin' ); }
}
