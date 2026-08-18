<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Experience_UI extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'experience_ui'; }
    public function label(): string { return 'Template, stili, menu e widget'; }
    public function routes(): array { return array( '/templates-manage', '/styles-manage', '/menus-manage', '/widgets-manage' ); }
    public function keywords(): array { return array( 'template', 'stile', 'css', 'menu', 'widget', 'layout', 'header', 'footer', 'block', 'gutenberg' ); }
}
